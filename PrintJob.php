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

class PrintJob
{
    private string $printerId;
    private string $jobsDir;
    private array $commands = [];
    private string $jobId;
    
    /**
     * Create a new print job
     * 
     * @param string $printerId The printer ID configured in Server Direct Print
     * @param string|null $jobsDir Optional custom jobs directory path
     */
    public function __construct(string $printerId, ?string $jobsDir = null)
    {
        $this->printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $printerId);
        $this->jobsDir = $jobsDir ?? __DIR__ . '/jobs/';
        $this->jobId = 'JOB_' . time() . '_' . mt_rand(1000, 9999);
        
        // Ensure jobs directory exists
        if (!is_dir($this->jobsDir)) {
            mkdir($this->jobsDir, 0755, true);
        }
        
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
     * Send the print job (write to file for printer to pick up)
     * 
     * @return bool True on success, false on failure
     */
    public function send(): bool
    {
        $jobFile = $this->jobsDir . $this->printerId . '.txt';
        $result = file_put_contents($jobFile, $this->toXml(), LOCK_EX);
        return $result !== false;
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
