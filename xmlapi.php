<?php
/**
 * XML Print API for Epson TM-T88VII
 * 
 * Accepts complete, pre-formatted ePOS-Print XML and queues it directly.
 * The client is responsible for all formatting (cuts, drawer commands, etc.)
 * 
 * Parameters (POST or GET):
 *   apikey  - Required. API key (min 16 chars, must exist as apikeys/{key}.txt)
 *   printer - Required. Printer ID
 *   xml     - Required. Complete PrintRequestInfo XML document
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

// =============================================================================
// MAIN EXECUTION
// =============================================================================

// Get parameters (support both GET and POST)
$apiKey = $_REQUEST['apikey'] ?? '';
$printerId = $_REQUEST['printer'] ?? '';
$xml = $_REQUEST['xml'] ?? '';

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

// Validate XML is provided
if (empty($xml)) {
    respond(false, 'Missing XML payload');
}

// Basic XML validation - check it starts with XML declaration or root element
$xmlTrimmed = ltrim($xml);
if (strpos($xmlTrimmed, '<?xml') !== 0 && strpos($xmlTrimmed, '<PrintRequestInfo') !== 0) {
    respond(false, 'Invalid XML: must start with <?xml or <PrintRequestInfo');
}

// Try to parse the XML to validate it's well-formed
libxml_use_internal_errors(true);
$parsed = @simplexml_load_string($xml);
if ($parsed === false) {
    $errors = libxml_get_errors();
    $errorMsg = !empty($errors) ? $errors[0]->message : 'Unknown XML parse error';
    libxml_clear_errors();
    respond(false, 'Invalid XML: ' . trim($errorMsg));
}

// Generate job ID (extract from XML if present, otherwise generate)
$jobId = null;
if (isset($parsed->ePOSPrint->Parameter->printjobid)) {
    $jobId = (string)$parsed->ePOSPrint->Parameter->printjobid;
}
if (empty($jobId)) {
    $jobId = 'XML_' . time() . '_' . mt_rand(1000, 9999);
}

// Queue the job
$queueResult = queueJob($printerId, $xml, $jobId);

if (!$queueResult['success']) {
    respond(false, $queueResult['error'] ?? 'Failed to create print job');
}

// Success response
$response = [
    'job_id' => $jobId,
    'printer' => $printerId,
    'xml_size' => strlen($xml),
    'queue_position' => $queueResult['queue_position'],
    'queue_depth' => $queueResult['queue_depth']
];

if ($queueResult['discarded']) {
    $response['queue_overflow'] = true;
    $response['discarded_job'] = $queueResult['discarded_job_id'];
}

respond(true, 'Print job queued', $response);
