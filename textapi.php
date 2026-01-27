<?php
/**
 * Text Print API for Epson TM-T88VII
 * 
 * Accepts plain text (newline-separated) and creates a print job.
 * 
 * Parameters (POST or GET):
 *   apikey     - Required. API key (min 16 chars, must exist as apikeys/{key}.txt)
 *   printer    - Required. Printer ID
 *   text       - Required. Plain text to print (newline-separated lines)
 *   opendrawer - Optional. Set to "true" to open cash drawer
 *   cut        - Optional. Set to "false" to skip paper cut (default: true)
 * 
 * Response: JSON with success/error status
 */

// Fast failure - set JSON header immediately
header('Content-Type: application/json; charset=UTF-8');

// Include queue management and API authentication
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/apiauth.php';

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
 * Escape text for XML
 */
function escapeXml(string $text): string
{
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Build the print job XML from text lines
 */
function buildPrintXml(string $jobId, string $text, bool $openDrawer, bool $cut): string
{
    $commands = '<text lang="en"/>';
    
    // Normalize line endings and split into lines
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = explode("\n", $text);
    
    // Print each line
    foreach ($lines as $line) {
        // Use &#10; for newline in ePOS-Print XML
        $commands .= '<text>' . escapeXml($line) . '&#10;</text>';
    }
    
    // Feed and cut paper (unless disabled)
    if ($cut) {
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
      <timeout>10000</timeout>
      <printjobid>' . escapeXml($jobId) . '</printjobid>
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
$text = $_REQUEST['text'] ?? '';
$openDrawer = filter_var($_REQUEST['opendrawer'] ?? false, FILTER_VALIDATE_BOOLEAN);
$cut = filter_var($_REQUEST['cut'] ?? true, FILTER_VALIDATE_BOOLEAN);

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
if (empty($text) && !$openDrawer) {
    respond(false, 'Nothing to do: no text and opendrawer is false');
}

// Generate job ID
$jobId = 'TXT_' . time() . '_' . mt_rand(1000, 9999);

// Build XML
$xml = buildPrintXml($jobId, $text, $openDrawer, $cut);

// Queue the job
$queueResult = queueJob($printerId, $xml, $jobId);

if (!$queueResult['success']) {
    respond(false, $queueResult['error'] ?? 'Failed to create print job');
}

// Count lines for response
$lineCount = empty($text) ? 0 : substr_count(str_replace(["\r\n", "\r"], "\n", $text), "\n") + 1;

// Success response
$response = [
    'job_id' => $jobId,
    'printer' => $printerId,
    'line_count' => $lineCount,
    'open_drawer' => $openDrawer,
    'cut' => $cut,
    'queue_position' => $queueResult['queue_position'],
    'queue_depth' => $queueResult['queue_depth']
];

if ($queueResult['discarded']) {
    $response['queue_overflow'] = true;
    $response['discarded_job'] = $queueResult['discarded_job_id'];
}

respond(true, 'Print job queued', $response);
