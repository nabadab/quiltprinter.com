<?php
/**
 * PrintJob - Helper class for creating Epson TM-T88VII print jobs
 * 
 * Usage:
 *   require_once 'PrintJob.php';
 *   
 *   $job = new PrintJob('PRINTER_ID');
 *   $job->text('Hello World')
 *       ->feed(1)
 *       ->textCentered('Centered Text')
 *       ->textBold('Bold Text')
 *       ->textLarge('Large Text')
 *       ->barcode('123456789')
 *       ->cut()
 *       ->openDrawer()
 *       ->send();
 */

// Include queue management
require_once __DIR__ . '/queue.php';

class PrintJob
{
    // Image processing constants
    private const MAX_IMAGE_WIDTH = 576;  // TM-T88VII max print width in dots (80mm paper)
    private const BRIGHTNESS_THRESHOLD = 127;  // 0-255, pixels darker than this become black
    
    private string $printerId;
    private array $commands = [];
    private string $jobId;
    private ?array $lastQueueResult = null;
    
    /**
     * Create a new print job
     * 
     * @param string $printerId The printer ID configured in Server Direct Print
     */
    public function __construct(string $printerId)
    {
        $this->printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $printerId);
        $this->jobId = 'JOB_' . time() . '_' . mt_rand(1000, 9999);
        
        // Initialize with language setting
        $this->commands[] = '<text lang="en"/>';
    }
    
    /**
     * Add plain text
     */
    public function text(string $text): self
    {
        $this->commands[] = '<text>' . $this->escape($text) . '&#10;</text>';
        return $this;
    }
    
    /**
     * Add text without newline
     */
    public function textInline(string $text): self
    {
        $this->commands[] = '<text>' . $this->escape($text) . '</text>';
        return $this;
    }
    
    /**
     * Add centered text
     */
    public function textCentered(string $text): self
    {
        $this->commands[] = '<text align="center"/>';
        $this->commands[] = '<text>' . $this->escape($text) . '&#10;</text>';
        $this->commands[] = '<text align="left"/>';
        return $this;
    }
    
    /**
     * Add bold/emphasized text
     */
    public function textBold(string $text): self
    {
        $this->commands[] = '<text em="true"/>';
        $this->commands[] = '<text>' . $this->escape($text) . '&#10;</text>';
        $this->commands[] = '<text em="false"/>';
        return $this;
    }
    
    /**
     * Add large text (double width and height)
     */
    public function textLarge(string $text): self
    {
        $this->commands[] = '<text width="2" height="2"/>';
        $this->commands[] = '<text>' . $this->escape($text) . '&#10;</text>';
        $this->commands[] = '<text width="1" height="1"/>';
        return $this;
    }
    
    /**
     * Add double-width text
     */
    public function textWide(string $text): self
    {
        $this->commands[] = '<text dw="true"/>';
        $this->commands[] = '<text>' . $this->escape($text) . '&#10;</text>';
        $this->commands[] = '<text dw="false"/>';
        return $this;
    }
    
    /**
     * Add double-height text
     */
    public function textTall(string $text): self
    {
        $this->commands[] = '<text dh="true"/>';
        $this->commands[] = '<text>' . $this->escape($text) . '&#10;</text>';
        $this->commands[] = '<text dh="false"/>';
        return $this;
    }
    
    /**
     * Add underlined text
     */
    public function textUnderline(string $text): self
    {
        $this->commands[] = '<text ul="true"/>';
        $this->commands[] = '<text>' . $this->escape($text) . '&#10;</text>';
        $this->commands[] = '<text ul="false"/>';
        return $this;
    }
    
    /**
     * Add inverted (white on black) text
     */
    public function textInverted(string $text): self
    {
        $this->commands[] = '<text reverse="true"/>';
        $this->commands[] = '<text> ' . $this->escape($text) . ' </text>';
        $this->commands[] = '<text reverse="false"/>';
        $this->commands[] = '<text>&#10;</text>';
        return $this;
    }
    
    /**
     * Set text alignment for following text
     * 
     * @param string $align 'left', 'center', or 'right'
     */
    public function align(string $align): self
    {
        $valid = ['left', 'center', 'right'];
        if (in_array($align, $valid)) {
            $this->commands[] = '<text align="' . $align . '"/>';
        }
        return $this;
    }
    
    /**
     * Feed paper by lines
     */
    public function feed(int $lines = 1): self
    {
        $this->commands[] = '<feed line="' . max(1, $lines) . '"/>';
        return $this;
    }
    
    /**
     * Feed paper by dots (units)
     */
    public function feedDots(int $dots): self
    {
        $this->commands[] = '<feed unit="' . max(1, $dots) . '"/>';
        return $this;
    }
    
    /**
     * Add a horizontal line (using dashes)
     */
    public function line(int $width = 32): self
    {
        $this->commands[] = '<text>' . str_repeat('-', $width) . '&#10;</text>';
        return $this;
    }
    
    /**
     * Add a double horizontal line (using equals)
     */
    public function doubleLine(int $width = 32): self
    {
        $this->commands[] = '<text>' . str_repeat('=', $width) . '&#10;</text>';
        return $this;
    }
    
    /**
     * Add a barcode
     * 
     * @param string $data Barcode data
     * @param string $type Barcode type: 'code39', 'code93', 'code128', 'ean13', 'ean8', 'upca', 'upce', 'itf', 'codabar'
     * @param string $hri Human readable interpretation position: 'none', 'above', 'below', 'both'
     */
    public function barcode(string $data, string $type = 'code128', string $hri = 'below'): self
    {
        $this->commands[] = '<barcode type="' . $type . '" hri="' . $hri . '" width="2" height="60">' . $this->escape($data) . '</barcode>';
        $this->commands[] = '<feed line="1"/>';
        return $this;
    }
    
    /**
     * Add a QR code
     * 
     * @param string $data QR code data
     * @param int $size Module size (1-16)
     * @param string $level Error correction level: 'level_l', 'level_m', 'level_q', 'level_h'
     */
    public function qrCode(string $data, int $size = 4, string $level = 'level_m'): self
    {
        $this->commands[] = '<symbol type="qrcode_model_2" level="' . $level . '" width="' . $size . '">' . $this->escape($data) . '</symbol>';
        $this->commands[] = '<feed line="1"/>';
        return $this;
    }
    
    /**
     * Add a stored logo from printer NV memory
     * 
     * @param string $key Logo key code (as configured in printer)
     */
    public function logo(string $key = 'key1'): self
    {
        $this->commands[] = '<logo key="' . $this->escape($key) . '"/>';
        return $this;
    }
    
    /**
     * Add an image to the print job
     * 
     * Accepts either:
     * - A file path to an image (PNG, JPEG, GIF, etc.)
     * - Base64-encoded image data (with or without data URL prefix)
     * - Raw binary image data
     * 
     * Fails silently if image cannot be processed (no image added, no error).
     * 
     * @param string $imageData File path, base64 string, or binary data
     * @param string $align Image alignment: 'left', 'center', 'right'
     * @return self
     */
    public function image(string $imageData, string $align = 'center'): self
    {
        $rasterData = $this->imageToRaster($imageData);
        
        if ($rasterData === null) {
            return $this;  // Fail silently
        }
        
        $alignAttr = in_array($align, ['left', 'center', 'right']) ? $align : 'center';
        
        $this->commands[] = sprintf(
            '<image width="%d" height="%d" color="color_1" mode="mono" align="%s">%s</image>',
            $rasterData['width'],
            $rasterData['height'],
            $alignAttr,
            $rasterData['raster']
        );
        $this->commands[] = '<feed line="1"/>';
        
        return $this;
    }
    
    /**
     * Add an image from a file path
     * 
     * Fails silently if file cannot be read or processed (no image added, no error).
     * 
     * @param string $filePath Path to image file
     * @param string $align Image alignment: 'left', 'center', 'right'
     * @return self
     */
    public function imageFromFile(string $filePath, string $align = 'center'): self
    {
        if (!file_exists($filePath)) {
            return $this;  // Fail silently
        }
        
        $imageData = @file_get_contents($filePath);
        if ($imageData === false) {
            return $this;  // Fail silently
        }
        
        return $this->image($imageData, $align);
    }
    
    /**
     * Add an image from base64-encoded data
     * 
     * Fails silently if data cannot be decoded or processed (no image added, no error).
     * 
     * @param string $base64Data Base64 string (with or without data URL prefix)
     * @param string $align Image alignment: 'left', 'center', 'right'
     * @return self
     */
    public function imageFromBase64(string $base64Data, string $align = 'center'): self
    {
        // Strip data URL prefix if present
        if (strpos($base64Data, 'data:') === 0) {
            $commaPos = strpos($base64Data, ',');
            if ($commaPos !== false) {
                $base64Data = substr($base64Data, $commaPos + 1);
            }
        }
        
        $binaryData = @base64_decode($base64Data, true);
        if ($binaryData === false) {
            return $this;  // Fail silently
        }
        
        return $this->image($binaryData, $align);
    }
    
    /**
     * Convert image data to Epson raster format
     * 
     * @param string $imageData File path, base64 string, or binary data
     * @return array|null ['raster' => base64 string, 'width' => int, 'height' => int] or null on failure
     */
    private function imageToRaster(string $imageData): ?array
    {
        // Detect input type and get binary data
        $binaryData = $this->getImageBinaryData($imageData);
        
        if ($binaryData === null) {
            return null;
        }
        
        // Create image from binary data
        $image = @imagecreatefromstring($binaryData);
        if ($image === false) {
            return null;
        }
        
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Validate dimensions
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);
            return null;
        }
        
        // Convert palette images to true color (critical for correct color reading)
        if (!imageistruecolor($image)) {
            $trueColorImage = @imagecreatetruecolor($width, $height);
            if ($trueColorImage === false) {
                imagedestroy($image);
                return null;
            }
            $white = imagecolorallocate($trueColorImage, 255, 255, 255);
            imagefill($trueColorImage, 0, 0, $white);
            imagecopy($trueColorImage, $image, 0, 0, 0, 0, $width, $height);
            imagedestroy($image);
            $image = $trueColorImage;
        }
        
        // Scale down if too wide (maintain aspect ratio)
        if ($width > self::MAX_IMAGE_WIDTH) {
            $newWidth = self::MAX_IMAGE_WIDTH;
            $newHeight = (int)round($height * (self::MAX_IMAGE_WIDTH / $width));
            $resized = @imagecreatetruecolor($newWidth, $newHeight);
            if ($resized === false) {
                imagedestroy($image);
                return null;
            }
            
            $white = imagecolorallocate($resized, 255, 255, 255);
            imagefill($resized, 0, 0, $white);
            
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
            $width = $newWidth;
            $height = $newHeight;
        }
        
        // Pad width to multiple of 8 for byte alignment
        $paddedWidth = (int)ceil($width / 8) * 8;
        
        // Convert to monochrome raster
        $rasterData = '';
        
        for ($y = 0; $y < $height; $y++) {
            $byte = 0;
            $bitPosition = 7;  // Start from MSB
            
            for ($x = 0; $x < $paddedWidth; $x++) {
                if ($x < $width) {
                    $rgb = imagecolorat($image, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    
                    // Convert to grayscale using luminosity method
                    $gray = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
                    
                    // Apply threshold: darker than threshold = black (1)
                    $isBlack = ($gray < self::BRIGHTNESS_THRESHOLD);
                } else {
                    // Padding pixels are white (0)
                    $isBlack = false;
                }
                
                if ($isBlack) {
                    $byte |= (1 << $bitPosition);
                }
                
                $bitPosition--;
                
                if ($bitPosition < 0) {
                    $rasterData .= chr($byte);
                    $byte = 0;
                    $bitPosition = 7;
                }
            }
        }
        
        imagedestroy($image);
        
        return [
            'raster' => base64_encode($rasterData),
            'width' => $paddedWidth,
            'height' => $height
        ];
    }
    
    /**
     * Detect image data type and return binary data
     * 
     * @param string $imageData File path, base64 string, or binary data
     * @return string|null Binary image data or null on failure
     */
    private function getImageBinaryData(string $imageData): ?string
    {
        // Check if it's a file path
        if (file_exists($imageData)) {
            $data = @file_get_contents($imageData);
            if ($data === false) {
                return null;
            }
            return $data;
        }
        
        // Check if it's a data URL (data:image/png;base64,...)
        if (strpos($imageData, 'data:') === 0) {
            $commaPos = strpos($imageData, ',');
            if ($commaPos !== false) {
                $base64Data = substr($imageData, $commaPos + 1);
                $decoded = @base64_decode($base64Data, true);
                if ($decoded !== false) {
                    return $decoded;
                }
            }
            return null;
        }
        
        // Check if it looks like base64 (alphanumeric + /+ with optional = padding)
        // Base64 strings are typically longer and don't contain binary characters
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $imageData) && strlen($imageData) > 100) {
            $decoded = @base64_decode($imageData, true);
            if ($decoded !== false && strlen($decoded) > 0) {
                // Verify it looks like image data (check for common magic bytes)
                $magic = substr($decoded, 0, 8);
                if (
                    substr($magic, 0, 8) === "\x89PNG\r\n\x1a\n" ||  // PNG
                    substr($magic, 0, 2) === "\xff\xd8" ||            // JPEG
                    substr($magic, 0, 6) === "GIF87a" ||              // GIF87
                    substr($magic, 0, 6) === "GIF89a" ||              // GIF89
                    substr($magic, 0, 4) === "RIFF"                   // WEBP
                ) {
                    return $decoded;
                }
            }
        }
        
        // Check if it's raw binary data (check for image magic bytes)
        $magic = substr($imageData, 0, 8);
        if (
            substr($magic, 0, 8) === "\x89PNG\r\n\x1a\n" ||  // PNG
            substr($magic, 0, 2) === "\xff\xd8" ||            // JPEG
            substr($magic, 0, 6) === "GIF87a" ||              // GIF87
            substr($magic, 0, 6) === "GIF89a" ||              // GIF89
            substr($magic, 0, 4) === "RIFF"                   // WEBP
        ) {
            return $imageData;
        }
        
        return null;
    }
    
    /**
     * Cut paper (partial cut with feed - default)
     */
    public function cut(): self
    {
        $this->commands[] = '<cut type="feed"/>';
        return $this;
    }
    
    /**
     * Full cut paper
     */
    public function cutFull(): self
    {
        $this->commands[] = '<cut type="feed_fullcut"/>';
        return $this;
    }
    
    /**
     * Open cash drawer
     * 
     * @param string $drawer 'drawer_1' or 'drawer_2' (pin 2 or pin 5)
     * @param string $duration Pulse duration: 'pulse_100' to 'pulse_500'
     */
    public function openDrawer(string $drawer = 'drawer_1', string $duration = 'pulse_100'): self
    {
        $this->commands[] = '<pulse drawer="' . $drawer . '" time="' . $duration . '"/>';
        return $this;
    }
    
    /**
     * Play buzzer/sound (if supported)
     * 
     * @param int $pattern Sound pattern (1-10)
     * @param int $repeat Repeat count (1-255)
     */
    public function sound(int $pattern = 1, int $repeat = 1): self
    {
        $this->commands[] = '<sound pattern="pattern_' . min(10, max(1, $pattern)) . '" repeat="' . min(255, max(1, $repeat)) . '"/>';
        return $this;
    }
    
    /**
     * Add raw ePOS-Print XML commands
     */
    public function raw(string $xml): self
    {
        $this->commands[] = $xml;
        return $this;
    }
    
    /**
     * Get the job ID
     */
    public function getJobId(): string
    {
        return $this->jobId;
    }
    
    /**
     * Set a custom job ID
     */
    public function setJobId(string $jobId): self
    {
        $this->jobId = preg_replace('/[^A-Za-z0-9_-]/', '', $jobId);
        return $this;
    }
    
    /**
     * Build the complete XML document
     */
    public function toXml(): string
    {
        $content = implode("\n        ", $this->commands);
        
        return '<?xml version="1.0" encoding="utf-8"?>
<PrintRequestInfo Version="2.00">
  <ePOSPrint>
    <Parameter>
      <devid>local_printer</devid>
      <timeout>10000</timeout>
      <printjobid>' . $this->escape($this->jobId) . '</printjobid>
    </Parameter>
    <PrintData>
      <epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">
        ' . $content . '
      </epos-print>
    </PrintData>
  </ePOSPrint>
</PrintRequestInfo>';
    }
    
    /**
     * Send the print job (queue for printer to pick up)
     * 
     * @return bool True on success, false on failure
     */
    public function send(): bool
    {
        $this->lastQueueResult = queueJob($this->printerId, $this->toXml(), $this->jobId);
        return $this->lastQueueResult['success'];
    }
    
    /**
     * Get the result of the last send() operation
     * 
     * @return array|null Queue result or null if send() not called
     */
    public function getQueueResult(): ?array
    {
        return $this->lastQueueResult;
    }
    
    /**
     * Get queue position after sending
     * 
     * @return int|null Queue position or null if not sent
     */
    public function getQueuePosition(): ?int
    {
        return $this->lastQueueResult['queue_position'] ?? null;
    }
    
    /**
     * Check if an older job was discarded due to queue overflow
     * 
     * @return bool
     */
    public function wasJobDiscarded(): bool
    {
        return $this->lastQueueResult['discarded'] ?? false;
    }
    
    /**
     * Escape text for XML
     */
    private function escape(string $text): string
    {
        // Convert newlines to XML entity
        $text = str_replace(["\r\n", "\r", "\n"], '&#10;', $text);
        // Convert tabs to XML entity
        $text = str_replace("\t", '&#9;', $text);
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Static helper to quickly send a simple text print
     */
    public static function quickPrint(string $printerId, string $text, bool $openDrawer = false): bool
    {
        $job = new self($printerId);
        $job->text($text)->cut();
        if ($openDrawer) {
            $job->openDrawer();
        }
        return $job->send();
    }
}
