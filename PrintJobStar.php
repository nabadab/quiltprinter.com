<?php
/**
 * PrintJobStar - Helper class for creating Star CloudPRNT print jobs
 * 
 * Uses Star Document Markup format for fast, native rendering.
 * Much faster than PNG since the printer renders text/barcodes natively.
 * 
 * Usage:
 *   require_once 'PrintJobStar.php';
 *   
 *   $job = new PrintJobStar('PRINTER_ID');
 *   $job->text('Hello World')
 *       ->feed(1)
 *       ->textCentered('Centered Text')
 *       ->textBold('Bold Text')
 *       ->textLarge('Large Text')
 *       ->barcode('123456789')
 *       ->qrCode('https://example.com')
 *       ->cut()
 *       ->openDrawer()
 *       ->send();
 */

// Include queue management
require_once __DIR__ . '/queue.php';

class PrintJobStar
{
    private string $printerId;
    private array $lines = [];
    private string $jobId;
    private bool $openDrawer = false;
    private ?array $lastQueueResult = null;
    
    // Current formatting state (to minimize redundant commands)
    private string $currentAlign = 'left';
    private int $currentWidth = 1;
    private int $currentHeight = 1;
    private bool $currentBold = false;
    private bool $currentUnderline = false;
    private bool $currentInvert = false;
    
    /**
     * Create a new Star print job
     * 
     * @param string $printerId The printer ID for CloudPRNT
     */
    public function __construct(string $printerId)
    {
        $this->printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $printerId);
        $this->jobId = 'STAR_JOB_' . time() . '_' . mt_rand(1000, 9999);
    }
    
    /**
     * Add plain text with newline
     */
    public function text(string $text): self
    {
        $this->lines[] = $text;
        return $this;
    }
    
    /**
     * Add text without newline (inline)
     */
    public function textInline(string $text): self
    {
        // Append to last line if exists, otherwise create new
        if (!empty($this->lines)) {
            $lastIndex = count($this->lines) - 1;
            if (is_string($this->lines[$lastIndex])) {
                $this->lines[$lastIndex] .= $text;
                return $this;
            }
        }
        $this->lines[] = ['inline' => $text];
        return $this;
    }
    
    /**
     * Add centered text
     */
    public function textCentered(string $text): self
    {
        $this->lines[] = '[align: center]';
        $this->lines[] = $text;
        $this->lines[] = '[align: left]';
        return $this;
    }
    
    /**
     * Add right-aligned text
     */
    public function textRight(string $text): self
    {
        $this->lines[] = '[align: right]';
        $this->lines[] = $text;
        $this->lines[] = '[align: left]';
        return $this;
    }
    
    /**
     * Add bold text
     */
    public function textBold(string $text): self
    {
        $this->lines[] = '[bold: on]';
        $this->lines[] = $text;
        $this->lines[] = '[bold: off]';
        return $this;
    }
    
    /**
     * Add large text (double width and height)
     */
    public function textLarge(string $text): self
    {
        $this->lines[] = '[magnify: width 2; height 2]';
        $this->lines[] = $text;
        $this->lines[] = '[magnify: width 1; height 1]';
        return $this;
    }
    
    /**
     * Add extra large text (3x width and height)
     */
    public function textXLarge(string $text): self
    {
        $this->lines[] = '[magnify: width 3; height 3]';
        $this->lines[] = $text;
        $this->lines[] = '[magnify: width 1; height 1]';
        return $this;
    }
    
    /**
     * Add double-width text
     */
    public function textWide(string $text): self
    {
        $this->lines[] = '[magnify: width 2; height 1]';
        $this->lines[] = $text;
        $this->lines[] = '[magnify: width 1; height 1]';
        return $this;
    }
    
    /**
     * Add double-height text
     */
    public function textTall(string $text): self
    {
        $this->lines[] = '[magnify: width 1; height 2]';
        $this->lines[] = $text;
        $this->lines[] = '[magnify: width 1; height 1]';
        return $this;
    }
    
    /**
     * Add underlined text
     */
    public function textUnderline(string $text): self
    {
        $this->lines[] = '[underline: on]';
        $this->lines[] = $text;
        $this->lines[] = '[underline: off]';
        return $this;
    }
    
    /**
     * Add inverted (white on black) text
     */
    public function textInverted(string $text): self
    {
        $this->lines[] = '[invert: on]';
        $this->lines[] = ' ' . $text . ' ';
        $this->lines[] = '[invert: off]';
        return $this;
    }
    
    /**
     * Add text with custom magnification
     * 
     * @param string $text Text to print
     * @param int $width Width magnification (1-6)
     * @param int $height Height magnification (1-6)
     */
    public function textMagnified(string $text, int $width = 1, int $height = 1): self
    {
        $width = max(1, min(6, $width));
        $height = max(1, min(6, $height));
        
        $this->lines[] = "[magnify: width $width; height $height]";
        $this->lines[] = $text;
        $this->lines[] = '[magnify: width 1; height 1]';
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
            $this->lines[] = "[align: $align]";
            $this->currentAlign = $align;
        }
        return $this;
    }
    
    /**
     * Turn bold on or off for following text
     */
    public function bold(bool $on = true): self
    {
        $this->lines[] = '[bold: ' . ($on ? 'on' : 'off') . ']';
        $this->currentBold = $on;
        return $this;
    }
    
    /**
     * Turn underline on or off for following text
     */
    public function underline(bool $on = true): self
    {
        $this->lines[] = '[underline: ' . ($on ? 'on' : 'off') . ']';
        $this->currentUnderline = $on;
        return $this;
    }
    
    /**
     * Set magnification for following text
     * 
     * @param int $width Width magnification (1-6)
     * @param int $height Height magnification (1-6)
     */
    public function magnify(int $width = 1, int $height = 1): self
    {
        $width = max(1, min(6, $width));
        $height = max(1, min(6, $height));
        $this->lines[] = "[magnify: width $width; height $height]";
        $this->currentWidth = $width;
        $this->currentHeight = $height;
        return $this;
    }
    
    /**
     * Reset all formatting to defaults
     */
    public function resetFormat(): self
    {
        $this->lines[] = '[magnify: width 1; height 1]';
        $this->lines[] = '[bold: off]';
        $this->lines[] = '[underline: off]';
        $this->lines[] = '[invert: off]';
        $this->lines[] = '[align: left]';
        $this->currentAlign = 'left';
        $this->currentWidth = 1;
        $this->currentHeight = 1;
        $this->currentBold = false;
        $this->currentUnderline = false;
        $this->currentInvert = false;
        return $this;
    }
    
    /**
     * Add blank lines (feed)
     */
    public function feed(int $lines = 1): self
    {
        for ($i = 0; $i < $lines; $i++) {
            $this->lines[] = '';
        }
        return $this;
    }
    
    /**
     * Add a horizontal line using dashes
     */
    public function line(int $width = 48): self
    {
        $this->lines[] = str_repeat('-', $width);
        return $this;
    }
    
    /**
     * Add a double horizontal line using equals
     */
    public function doubleLine(int $width = 48): self
    {
        $this->lines[] = str_repeat('=', $width);
        return $this;
    }
    
    /**
     * Add a barcode
     * 
     * @param string $data Barcode data
     * @param string $type Barcode type: 'code128', 'code39', 'jan13' (ean13), 'jan8' (ean8), 'upca', 'upce', 'itf', 'nw7' (codabar)
     * @param int $height Barcode height in dots (default 40)
     * @param string $hri Human readable position: 'top', 'bottom', 'both', 'none'
     */
    public function barcode(string $data, string $type = 'code128', int $height = 40, string $hri = 'bottom'): self
    {
        $hriMap = ['top' => 'above', 'bottom' => 'below', 'both' => 'both', 'none' => 'none'];
        $hriValue = $hriMap[$hri] ?? 'below';
        
        $this->lines[] = "[barcode: type $type; data $data; height $height; hri $hriValue]";
        return $this;
    }
    
    /**
     * Add a QR code
     * 
     * @param string $data QR code data (URL, text, etc.)
     * @param int $cellSize Cell/module size (1-8, default 4)
     * @param string $level Error correction level: 'l', 'm', 'q', 'h'
     */
    public function qrCode(string $data, int $cellSize = 4, string $level = 'm'): self
    {
        $cellSize = max(1, min(8, $cellSize));
        $this->lines[] = "[qrcode: data $data; cell $cellSize; level $level]";
        return $this;
    }
    
    /**
     * Add a PDF417 barcode
     * 
     * @param string $data PDF417 data
     * @param int $width Module width (2-8)
     * @param int $height Module height (2-8)
     */
    public function pdf417(string $data, int $width = 3, int $height = 3): self
    {
        $width = max(2, min(8, $width));
        $height = max(2, min(8, $height));
        $this->lines[] = "[pdf417: data $data; width $width; height $height]";
        return $this;
    }
    
    /**
     * Add an image from base64 data
     * 
     * Note: For best performance, use text and barcodes instead of images.
     * Images are slower to transmit and process.
     * 
     * @param string $base64Data Base64-encoded PNG/JPEG data (with or without data URL prefix)
     * @param string $align Image alignment: 'left', 'center', 'right'
     */
    public function imageBase64(string $base64Data, string $align = 'center'): self
    {
        // Strip data URL prefix if not present, add it
        if (strpos($base64Data, 'data:') !== 0) {
            // Detect image type from base64 header
            $decoded = base64_decode(substr($base64Data, 0, 16), true);
            if ($decoded !== false) {
                if (substr($decoded, 0, 8) === "\x89PNG\r\n\x1a\n") {
                    $base64Data = 'data:image/png;base64,' . $base64Data;
                } elseif (substr($decoded, 0, 2) === "\xff\xd8") {
                    $base64Data = 'data:image/jpeg;base64,' . $base64Data;
                } else {
                    $base64Data = 'data:image/png;base64,' . $base64Data;
                }
            }
        }
        
        if ($align !== 'left') {
            $this->lines[] = "[align: $align]";
        }
        $this->lines[] = "[image: url $base64Data]";
        if ($align !== 'left') {
            $this->lines[] = '[align: left]';
        }
        return $this;
    }
    
    /**
     * Add an image from a URL
     * 
     * @param string $url Image URL
     * @param string $align Image alignment: 'left', 'center', 'right'
     */
    public function imageUrl(string $url, string $align = 'center'): self
    {
        if ($align !== 'left') {
            $this->lines[] = "[align: $align]";
        }
        $this->lines[] = "[image: url $url]";
        if ($align !== 'left') {
            $this->lines[] = '[align: left]';
        }
        return $this;
    }
    
    /**
     * Cut paper (partial cut - default)
     */
    public function cut(): self
    {
        $this->lines[] = '[cut: feed; partial]';
        return $this;
    }
    
    /**
     * Full cut paper
     */
    public function cutFull(): self
    {
        $this->lines[] = '[cut: feed; full]';
        return $this;
    }
    
    /**
     * Cut without feed
     */
    public function cutNoFeed(): self
    {
        $this->lines[] = '[cut: partial]';
        return $this;
    }
    
    /**
     * Open cash drawer
     * Note: For Star CloudPRNT, drawer is controlled via HTTP header
     */
    public function openDrawer(): self
    {
        $this->openDrawer = true;
        return $this;
    }
    
    /**
     * Add raw Star Document Markup
     */
    public function raw(string $markup): self
    {
        $this->lines[] = $markup;
        return $this;
    }
    
    /**
     * Add a receipt-style row with left and right text
     * 
     * @param string $left Left-aligned text
     * @param string $right Right-aligned text
     * @param int $width Total line width
     * @param string $fill Fill character between left and right
     */
    public function row(string $left, string $right, int $width = 48, string $fill = ' '): self
    {
        $leftLen = mb_strlen($left);
        $rightLen = mb_strlen($right);
        $fillLen = $width - $leftLen - $rightLen;
        
        if ($fillLen < 1) {
            // Text too long, just concatenate
            $this->lines[] = $left . ' ' . $right;
        } else {
            $this->lines[] = $left . str_repeat($fill, $fillLen) . $right;
        }
        return $this;
    }
    
    /**
     * Add a receipt-style row with dots between items
     * 
     * @param string $left Left text (item name)
     * @param string $right Right text (price)
     * @param int $width Total line width
     */
    public function rowDotted(string $left, string $right, int $width = 48): self
    {
        return $this->row($left, $right, $width, '.');
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
     * Check if drawer will be opened
     */
    public function willOpenDrawer(): bool
    {
        return $this->openDrawer;
    }
    
    /**
     * Build the Star Document Markup content
     */
    public function toMarkup(): string
    {
        $output = '';
        
        foreach ($this->lines as $line) {
            if (is_array($line) && isset($line['inline'])) {
                // Inline text - no newline
                $output .= $line['inline'];
            } else {
                $output .= $line . "\n";
            }
        }
        
        return $output;
    }
    
    /**
     * Build the job content for queuing
     * Includes the STAR:MARKUP header for proper handling
     */
    public function toJobContent(): string
    {
        $header = $this->openDrawer ? '[STAR:MARKUP:DRAWER]' : '[STAR:MARKUP]';
        return $header . "\n" . $this->toMarkup();
    }
    
    /**
     * Send the print job (queue for printer to pick up)
     * 
     * @return bool True on success, false on failure
     */
    public function send(): bool
    {
        $this->lastQueueResult = queueJob($this->printerId, $this->toJobContent(), $this->jobId);
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
     */
    public function wasJobDiscarded(): bool
    {
        return $this->lastQueueResult['discarded'] ?? false;
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
    
    /**
     * Static helper to create a simple receipt
     * 
     * @param string $printerId Printer ID
     * @param string $title Receipt title
     * @param array $items Array of ['name' => string, 'price' => string]
     * @param string $total Total amount
     * @param bool $openDrawer Open cash drawer after printing
     */
    public static function quickReceipt(
        string $printerId,
        string $title,
        array $items,
        string $total,
        bool $openDrawer = false
    ): bool {
        $job = new self($printerId);
        
        $job->align('center')
            ->textLarge($title)
            ->align('left')
            ->feed(1)
            ->doubleLine();
        
        foreach ($items as $item) {
            $job->row($item['name'] ?? '', $item['price'] ?? '');
        }
        
        $job->doubleLine()
            ->bold(true)
            ->row('TOTAL', $total)
            ->bold(false)
            ->feed(2)
            ->cut();
        
        if ($openDrawer) {
            $job->openDrawer();
        }
        
        return $job->send();
    }
}
