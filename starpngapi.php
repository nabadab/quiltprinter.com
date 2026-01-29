<?php
/**
 * PNG Print API for Star CloudPRNT Printers
 * 
 * Accepts a PNG image and queues it for Star CloudPRNT printers.
 * Star printers natively support PNG, so no rasterization needed.
 * 
 * Parameters (POST or GET):
 *   apikey   - Required. API key (min 16 chars)
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
define('MAX_PNG_SIZE', 5 * 1024 * 1024);  // 5MB max
define('DEBUG_MODE', true);

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
 * Validate PNG data and get dimensions
 */
function validatePng(string $pngBinary): array
{
    // Check minimum size
    if (strlen($pngBinary) < 24) {
        return ['error' => 'PNG data too small'];
    }
    
    // Verify PNG magic bytes
    if (substr($pngBinary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        return ['error' => 'Data is not a valid PNG image'];
    }
    
    // Get dimensions from IHDR chunk
    // PNG structure: 8 byte signature, then chunks
    // First chunk should be IHDR with width/height
    $ihdrLength = unpack('N', substr($pngBinary, 8, 4))[1];
    $ihdrType = substr($pngBinary, 12, 4);
    
    if ($ihdrType !== 'IHDR' || $ihdrLength < 13) {
        return ['error' => 'Invalid PNG structure'];
    }
    
    // Width and height are first 8 bytes of IHDR data
    $width = unpack('N', substr($pngBinary, 16, 4))[1];
    $height = unpack('N', substr($pngBinary, 20, 4))[1];
    
    if ($width <= 0 || $height <= 0 || $width > 10000 || $height > 10000) {
        return ['error' => 'Invalid image dimensions'];
    }
    
    return [
        'width' => $width,
        'height' => $height,
        'size' => strlen($pngBinary)
    ];
}

/**
 * Build Star CloudPRNT job content
 * Format: [STAR:PNG] or [STAR:PNG:DRAWER] on first line, then base64 PNG data
 */
function buildStarPngJob(string $pngBinary, bool $openDrawer): string
{
    $header = $openDrawer ? '[STAR:PNG:DRAWER]' : '[STAR:PNG]';
    return $header . "\n" . base64_encode($pngBinary);
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
$imageInfo = null;
$pngBinary = null;

if (!empty($pngBase64)) {
    // Strip data URL prefix if present (e.g., "data:image/png;base64,")
    if (strpos($pngBase64, 'data:') === 0) {
        $commaPos = strpos($pngBase64, ',');
        if ($commaPos !== false) {
            $pngBase64 = substr($pngBase64, $commaPos + 1);
        }
    }
    
    // Decode base64
    $pngBinary = base64_decode($pngBase64, true);
    if ($pngBinary === false) {
        respond(false, 'Invalid base64 encoding for PNG');
    }
    
    // Check size limit
    if (strlen($pngBinary) > MAX_PNG_SIZE) {
        respond(false, 'PNG image too large (max ' . (MAX_PNG_SIZE / 1024 / 1024) . 'MB)');
    }
    
    // Validate PNG
    $imageInfo = validatePng($pngBinary);
    if (isset($imageInfo['error'])) {
        respond(false, $imageInfo['error']);
    }
}

// Generate job ID
$jobId = 'STAR_PNG_' . time() . '_' . mt_rand(1000, 9999);

// Build job content
if ($pngBinary !== null) {
    $jobContent = buildStarPngJob($pngBinary, $openDrawer);
} else {
    // Just drawer command, no image
    $jobContent = $openDrawer ? "[STAR:DRAWER]\n" : "";
}

// Queue the job
$queueResult = queueJob($printerId, $jobContent, $jobId);

if (!$queueResult['success']) {
    respond(false, $queueResult['error'] ?? 'Failed to create print job');
}

// Success response
$response = [
    'job_id' => $jobId,
    'printer' => $printerId,
    'format' => 'star_png',
    'has_image' => ($imageInfo !== null),
    'open_drawer' => $openDrawer,
    'queue_position' => $queueResult['queue_position'],
    'queue_depth' => $queueResult['queue_depth']
];

if ($queueResult['discarded']) {
    $response['queue_overflow'] = true;
    $response['discarded_job'] = $queueResult['discarded_job_id'];
}

if ($imageInfo !== null) {
    $response['image_width'] = $imageInfo['width'];
    $response['image_height'] = $imageInfo['height'];
    $response['image_size'] = $imageInfo['size'];
}

respond(true, 'Print job queued for Star CloudPRNT', $response);
