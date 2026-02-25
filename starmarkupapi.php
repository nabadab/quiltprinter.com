<?php
/**
 * Star Document Markup Print API for Star CloudPRNT Printers
 * 
 * Accepts Star Document Markup and queues it for Star CloudPRNT printers.
 * Much faster than PNG since the printer renders text/barcodes natively.
 * 
 * Parameters (POST or GET):
 *   apikey     - Required. API key (min 16 chars)
 *   printer    - Required. Printer ID
 *   markup     - Required. Star Document Markup content
 *   opendrawer - Optional. Set to "true" to open cash drawer
 * 
 * Response: JSON with success/error status
 * 
 * Example markup:
 *   [align: center]
 *   [magnify: width 2; height 2]
 *   RECEIPT
 *   [magnify]
 *   [align: left]
 *   ================================
 *   Coffee                    $4.50
 *   Muffin                    $3.25
 *   ================================
 *   [bold: on]
 *   TOTAL                     $7.75
 *   [bold: off]
 *   [barcode: type code128; data 12345678]
 *   [cut: feed; partial]
 */

// Fast failure - set JSON header immediately
header('Content-Type: application/json; charset=UTF-8');

// Include queue management and API authentication
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/apiauth.php';

// Configuration
define('MAX_MARKUP_SIZE', 1024 * 1024);  // 1MB max

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
 * Build Star CloudPRNT markup job content
 * Format: [STAR:MARKUP] or [STAR:MARKUP:DRAWER] on first line, then markup content
 */
function buildStarMarkupJob(string $markup, bool $openDrawer): string
{
    $header = $openDrawer ? '[STAR:MARKUP:DRAWER]' : '[STAR:MARKUP]';
    return $header . "\n" . $markup;
}

// =============================================================================
// MAIN EXECUTION
// =============================================================================

// Get parameters (support both GET and POST)
$apiKey = $_REQUEST['apikey'] ?? '';
$printerId = $_REQUEST['printer'] ?? '';
$markup = $_REQUEST['markup'] ?? '';
$openDrawer = filter_var($_REQUEST['opendrawer'] ?? false, FILTER_VALIDATE_BOOLEAN);

// Also check for raw POST body if markup not in REQUEST
if (empty($markup)) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    // If content type is text/plain or application/vnd.star.markup, use raw body
    if (strpos($contentType, 'text/plain') !== false || 
        strpos($contentType, 'application/vnd.star.markup') !== false) {
        $markup = file_get_contents('php://input');
    }
}

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

// Check if we have markup content
if (empty($markup)) {
    respond(false, 'Missing markup content');
}

// Check size limit
if (strlen($markup) > MAX_MARKUP_SIZE) {
    respond(false, 'Markup content too large (max ' . (MAX_MARKUP_SIZE / 1024) . 'KB)');
}

// Generate job ID
$jobId = 'STAR_MARKUP_' . time() . '_' . mt_rand(1000, 9999);

// Build job content
$jobContent = buildStarMarkupJob($markup, $openDrawer);

// Queue the job
$queueResult = queueJob($printerId, $jobContent, $jobId);

if (!$queueResult['success']) {
    respond(false, $queueResult['error'] ?? 'Failed to create print job');
}

// Success response
$response = [
    'job_id' => $jobId,
    'printer' => $printerId,
    'format' => 'star_markup',
    'open_drawer' => $openDrawer,
    'markup_size' => strlen($markup),
    'queue_position' => $queueResult['queue_position'],
    'queue_depth' => $queueResult['queue_depth']
];

if ($queueResult['discarded']) {
    $response['queue_overflow'] = true;
    $response['discarded_job'] = $queueResult['discarded_job_id'];
}

respond(true, 'Print job queued for Star CloudPRNT', $response);
