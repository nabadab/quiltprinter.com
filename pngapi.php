<?php
/**
 * PNG Print API for Epson TM-T88VII
 * 
 * Accepts a PNG image and converts it to an ePOS-Print job.
 * 
 * Parameters (POST or GET):
 *   apikey   - Required. API key (min 16 chars, must exist as apikeys/{key}.txt)
 *   printer  - Required. Printer ID
 *   png      - Optional. Base64-encoded PNG image
 *   opendrawer - Optional. Set to "true" to open cash drawer
 * 
 * Response: JSON with success/error status
 */

// Fast failure - set JSON header immediately
header('Content-Type: application/json; charset=UTF-8');

// Include queue management and API authentication
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/apiauth.php';

// Configuration
define('LOGS_DIR', __DIR__ . '/logs/');
define('MAX_IMAGE_WIDTH', 576);  // TM-T88VII max print width in dots (80mm paper)
define('BRIGHTNESS_THRESHOLD', 127);  // 0-255, pixels darker than this become black
define('DEBUG_MODE', true);  // Set to true to enable debug logging

// Ensure log directory exists
if (DEBUG_MODE && !is_dir(LOGS_DIR)) {
    mkdir(LOGS_DIR, 0755, true);
}

/**
 * Debug logging function
 */
function debugLog(string $message, $data = null): void
{
    if (!DEBUG_MODE) return;
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message";
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $logEntry .= ": " . json_encode($data, JSON_PRETTY_PRINT);
        } else {
            $logEntry .= ": $data";
        }
    }
    $logEntry .= "\n";
    
    file_put_contents(LOGS_DIR . 'pngapi_debug.log', $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Send JSON response and exit
 */
function respond(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Convert PNG to Epson raster format
 * 
 * @param string $pngData Raw PNG binary data
 * @return array ['raster' => base64 string, 'width' => int, 'height' => int] or ['error' => string]
 */
function pngToRaster(string $pngData): array
{
    // Create image from PNG data
    $image = @imagecreatefromstring($pngData);
    if ($image === false) {
        debugLog('Failed to create image from PNG data');
        return ['error' => 'Invalid PNG image data'];
    }
    
    $width = imagesx($image);
    $height = imagesy($image);
    $isTrueColor = imageistruecolor($image);
    
    debugLog('Image loaded', [
        'width' => $width,
        'height' => $height,
        'is_truecolor' => $isTrueColor
    ]);
    
    // Validate dimensions
    if ($width <= 0 || $height <= 0) {
        imagedestroy($image);
        return ['error' => 'Invalid image dimensions'];
    }
    
    // CRITICAL: Convert palette-based images to true color
    // For palette images, imagecolorat() returns palette index, not RGBA
    // We need true color to get actual RGB values
    if (!$isTrueColor) {
        debugLog('Converting palette image to true color');
        $trueColorImage = imagecreatetruecolor($width, $height);
        
        // Handle transparency - fill with white first
        $white = imagecolorallocate($trueColorImage, 255, 255, 255);
        imagefill($trueColorImage, 0, 0, $white);
        
        // Copy the image (this converts palette to true color)
        imagecopy($trueColorImage, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);
        $image = $trueColorImage;
    }
    
    // Scale down if too wide (maintain aspect ratio)
    if ($width > MAX_IMAGE_WIDTH) {
        $newWidth = MAX_IMAGE_WIDTH;
        $newHeight = (int)round($height * (MAX_IMAGE_WIDTH / $width));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        
        // Fill with white for transparency handling
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);
        $image = $resized;
        $width = $newWidth;
        $height = $newHeight;
        
        debugLog('Image resized', ['new_width' => $width, 'new_height' => $height]);
    }
    
    // Pad width to multiple of 8 for byte alignment
    $paddedWidth = (int)ceil($width / 8) * 8;
    $bytesPerRow = $paddedWidth / 8;
    
    debugLog('Raster conversion starting', [
        'padded_width' => $paddedWidth,
        'bytes_per_row' => $bytesPerRow,
        'threshold' => BRIGHTNESS_THRESHOLD
    ]);
    
    // Sample a few pixels for debugging
    if (DEBUG_MODE) {
        $samplePixels = [];
        $samplePoints = [[0, 0], [$width/2, $height/2], [$width-1, $height-1]];
        foreach ($samplePoints as $point) {
            $px = min((int)$point[0], $width - 1);
            $py = min((int)$point[1], $height - 1);
            $rgb = imagecolorat($image, $px, $py);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $gray = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
            $samplePixels[] = [
                'pos' => "($px, $py)",
                'rgb' => "R=$r G=$g B=$b",
                'gray' => $gray,
                'isBlack' => $gray < BRIGHTNESS_THRESHOLD
            ];
        }
        debugLog('Sample pixels', $samplePixels);
    }
    
    // Convert to monochrome raster
    $rasterData = '';
    $blackPixelCount = 0;
    $whitePixelCount = 0;
    
    for ($y = 0; $y < $height; $y++) {
        $byte = 0;
        $bitPosition = 7;  // Start from MSB
        
        for ($x = 0; $x < $paddedWidth; $x++) {
            if ($x < $width) {
                // Get pixel color (now guaranteed to be true color format)
                $rgb = imagecolorat($image, $x, $y);
                
                // Extract RGB components
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                // Convert to grayscale using luminosity method
                $gray = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
                
                // Apply threshold: darker than threshold = black (1)
                $isBlack = ($gray < BRIGHTNESS_THRESHOLD);
            } else {
                // Padding pixels are white (0)
                $isBlack = false;
            }
            
            if ($isBlack) {
                $blackPixelCount++;
            } else {
                $whitePixelCount++;
            }
            
            // Set bit if black
            if ($isBlack) {
                $byte |= (1 << $bitPosition);
            }
            
            $bitPosition--;
            
            // Write byte when complete
            if ($bitPosition < 0) {
                $rasterData .= chr($byte);
                $byte = 0;
                $bitPosition = 7;
            }
        }
    }
    
    imagedestroy($image);
    
    debugLog('Raster conversion complete', [
        'black_pixels' => $blackPixelCount,
        'white_pixels' => $whitePixelCount,
        'black_percentage' => round(100 * $blackPixelCount / ($blackPixelCount + $whitePixelCount), 2) . '%',
        'raster_bytes' => strlen($rasterData)
    ]);
    
    return [
        'raster' => base64_encode($rasterData),
        'width' => $paddedWidth,
        'height' => $height,
        'debug' => DEBUG_MODE ? [
            'black_pixels' => $blackPixelCount,
            'white_pixels' => $whitePixelCount,
            'black_percentage' => round(100 * $blackPixelCount / ($blackPixelCount + $whitePixelCount), 2)
        ] : null
    ];
}

/**
 * Build the print job XML
 */
function buildPrintXml(string $jobId, ?array $imageData, bool $openDrawer): string
{
    $commands = '<text lang="en"/>';
    
    // Add image if provided
    if ($imageData !== null) {
        $commands .= sprintf(
            '<image width="%d" height="%d" color="color_1" mode="mono" align="center">%s</image>',
            $imageData['width'],
            $imageData['height'],
            $imageData['raster']
        );
        $commands .= '<feed line="2"/>';
        $commands .= '<cut type="feed"/>';
    }
    
    // Add drawer pulse if requested
    if ($openDrawer) {
        $commands .= '<pulse drawer="drawer_1" time="pulse_100"/>';
    }
    
    return '<?xml version="1.0" encoding="utf-8"?>
<PrintRequestInfo Version="2.00">
  <ePOSPrint>
    <Parameter>
      <devid>local_printer</devid>
      <timeout>60000</timeout>
      <printjobid>' . htmlspecialchars($jobId, ENT_XML1) . '</printjobid>
    </Parameter>
    <PrintData>
      <epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">
        ' . $commands . '
      </epos-print>
    </PrintData>
  </ePOSPrint>
</PrintRequestInfo>';
}

// =============================================================================
// MAIN EXECUTION
// =============================================================================

// Get parameters (support both GET and POST)
$apiKey = $_REQUEST['apikey'] ?? '';
$printerId = $_REQUEST['printer'] ?? '';
$pngBase64 = $_REQUEST['png'] ?? '';
$openDrawer = filter_var($_REQUEST['opendrawer'] ?? false, FILTER_VALIDATE_BOOLEAN);

// Validate API key (fast fail)
if (empty($apiKey)) {
    respond(false, 'Missing API key');
}

if (!validateApiKey($apiKey)) {
    respond(false, 'Invalid API key');
}

// Validate printer ID
if (empty($printerId)) {
    respond(false, 'Missing printer ID');
}

$printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $printerId);
if (empty($printerId)) {
    respond(false, 'Invalid printer ID');
}

// Check if we have something to do
if (empty($pngBase64) && !$openDrawer) {
    respond(false, 'Nothing to do: no image and opendrawer is false');
}

// Process image if provided
$imageData = null;
if (!empty($pngBase64)) {
    debugLog('Processing PNG input', ['length' => strlen($pngBase64)]);
    
    // Strip data URL prefix if present (e.g., "data:image/png;base64,")
    if (strpos($pngBase64, 'data:') === 0) {
        $commaPos = strpos($pngBase64, ',');
        if ($commaPos !== false) {
            $pngBase64 = substr($pngBase64, $commaPos + 1);
            debugLog('Stripped data URL prefix');
        }
    }
    
    // Decode base64
    $pngBinary = base64_decode($pngBase64, true);
    if ($pngBinary === false) {
        debugLog('Base64 decode failed');
        respond(false, 'Invalid base64 encoding for PNG');
    }
    
    debugLog('Base64 decoded', ['binary_length' => strlen($pngBinary)]);
    
    // Verify it's actually a PNG (check magic bytes)
    if (strlen($pngBinary) < 8 || substr($pngBinary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        debugLog('PNG magic bytes check failed', [
            'first_8_bytes_hex' => bin2hex(substr($pngBinary, 0, 8))
        ]);
        respond(false, 'Data is not a valid PNG image');
    }
    
    // Convert to raster
    $imageData = pngToRaster($pngBinary);
    if (isset($imageData['error'])) {
        respond(false, $imageData['error']);
    }
}

// Generate job ID
$jobId = 'PNG_' . time() . '_' . mt_rand(1000, 9999);

// Build XML
$xml = buildPrintXml($jobId, $imageData, $openDrawer);

// Queue the job
$queueResult = queueJob($printerId, $xml, $jobId);

if (!$queueResult['success']) {
    respond(false, $queueResult['error'] ?? 'Failed to create print job');
}

// Success response
$response = [
    'job_id' => $jobId,
    'printer' => $printerId,
    'has_image' => ($imageData !== null),
    'open_drawer' => $openDrawer,
    'queue_position' => $queueResult['queue_position'],
    'queue_depth' => $queueResult['queue_depth']
];

if ($queueResult['discarded']) {
    $response['queue_overflow'] = true;
    $response['discarded_job'] = $queueResult['discarded_job_id'];
}

if ($imageData !== null) {
    $response['image_width'] = $imageData['width'];
    $response['image_height'] = $imageData['height'];
    if (DEBUG_MODE && isset($imageData['debug'])) {
        $response['debug'] = $imageData['debug'];
    }
}

respond(true, 'Print job queued', $response);
