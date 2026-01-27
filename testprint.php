<?php
/**
 * Test Print Generator for Epson TM-T88VII
 * 
 * Usage:
 *   testprint.php?id=PRINTERID              - Create a test print job
 *   testprint.php?id=PRINTERID&opendrawer=true  - Create test print and open drawer
 *   testprint.php?id=PRINTERID&text=Hello   - Custom text to print
 */

// Include queue management
require_once __DIR__ . '/queue.php';

// Get parameters
$printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['id'] ?? '');
$openDrawer = filter_var($_GET['opendrawer'] ?? false, FILTER_VALIDATE_BOOLEAN);
$customText = $_GET['text'] ?? '';

// Validate printer ID
if (empty($printerId)) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(400);
    echo '<!DOCTYPE html>
<html>
<head><title>Test Print - Error</title></head>
<body>
<h1>Error: Missing Printer ID</h1>
<p>Usage: <code>testprint.php?id=PRINTERID</code></p>
<p>Options:</p>
<ul>
<li><code>&amp;opendrawer=true</code> - Open the cash drawer</li>
<li><code>&amp;text=YourText</code> - Custom text to print</li>
</ul>
</body>
</html>';
    exit;
}

// Build the print XML
$timestamp = date('Y-m-d H:i:s');
$jobId = 'TEST_' . time() . '_' . mt_rand(1000, 9999);

// Escape text for XML
$escapeXml = function($text) {
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
};

// Build print content
$printContent = '';

// Header
$printContent .= '<text align="center"/>';
$printContent .= '<text width="2" height="2"/>';
$printContent .= '<text em="true">TEST PRINT&#10;</text>';
$printContent .= '<text width="1" height="1"/>';
$printContent .= '<text em="false"/>';
$printContent .= '<feed line="1"/>';

// Divider
$printContent .= '<text>================================&#10;</text>';

// Info
$printContent .= '<text align="left"/>';
$printContent .= '<text>Printer ID: ' . $escapeXml($printerId) . '&#10;</text>';
$printContent .= '<text>Timestamp:  ' . $escapeXml($timestamp) . '&#10;</text>';
$printContent .= '<text>Job ID:     ' . $escapeXml($jobId) . '&#10;</text>';

// Custom text if provided
if (!empty($customText)) {
    $printContent .= '<feed line="1"/>';
    $printContent .= '<text>--------------------------------&#10;</text>';
    $printContent .= '<text>' . $escapeXml($customText) . '&#10;</text>';
}

// Drawer status
$printContent .= '<feed line="1"/>';
$printContent .= '<text>Drawer: ' . ($openDrawer ? 'WILL OPEN' : 'No action') . '&#10;</text>';

// Footer
$printContent .= '<text>================================&#10;</text>';
$printContent .= '<feed line="1"/>';
$printContent .= '<text align="center"/>';
$printContent .= '<text>Server Direct Print Test&#10;</text>';
$printContent .= '<text>quiltprinter.com&#10;</text>';
$printContent .= '<feed line="2"/>';

// Cut paper
$printContent .= '<cut type="feed"/>';

// Open drawer if requested (after cut)
if ($openDrawer) {
    $printContent .= '<pulse drawer="drawer_1" time="pulse_100"/>';
}

// Build complete XML document
$xml = '<?xml version="1.0" encoding="utf-8"?>
<PrintRequestInfo Version="2.00">
  <ePOSPrint>
    <Parameter>
      <devid>local_printer</devid>
      <timeout>10000</timeout>
      <printjobid>' . $escapeXml($jobId) . '</printjobid>
    </Parameter>
    <PrintData>
      <epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">
        <text lang="en"/>
        ' . $printContent . '
      </epos-print>
    </PrintData>
  </ePOSPrint>
</PrintRequestInfo>';

// Queue the job
$queueResult = queueJob($printerId, $xml, $jobId);

// Output result
header('Content-Type: text/html; charset=UTF-8');

if ($queueResult['success']) {
    echo '<!DOCTYPE html>
<html>
<head>
<title>Test Print - Success</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 8px; }
.warning { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 10px; border-radius: 8px; margin-top: 15px; }
.info { background: #cce5ff; border: 1px solid #b8daff; color: #004085; padding: 10px; border-radius: 8px; margin-top: 15px; }
code { background: #f8f9fa; padding: 2px 6px; border-radius: 4px; }
pre { background: #f8f9fa; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
</style>
</head>
<body>
<div class="success">
<h2>Print Job Queued!</h2>
<p><strong>Printer ID:</strong> <code>' . htmlspecialchars($printerId) . '</code></p>
<p><strong>Job ID:</strong> <code>' . htmlspecialchars($jobId) . '</code></p>
<p><strong>Open Drawer:</strong> ' . ($openDrawer ? 'Yes' : 'No') . '</p>
<p><strong>Timestamp:</strong> ' . htmlspecialchars($timestamp) . '</p>
<p><strong>Queue Position:</strong> ' . $queueResult['queue_position'] . ' of ' . $queueResult['queue_depth'] . '</p>
</div>';

    if ($queueResult['discarded']) {
        echo '<div class="warning">
<strong>Queue Overflow:</strong> An older job (<code>' . htmlspecialchars($queueResult['discarded_job_id'] ?? 'unknown') . '</code>) was discarded to make room (max queue depth is ' . QUEUE_MAX_DEPTH . ').
</div>';
    }

    echo '<div class="info">
<strong>Queue Status:</strong> ' . $queueResult['queue_depth'] . ' job(s) waiting for printer <code>' . htmlspecialchars($printerId) . '</code>
</div>';

    echo '
<h3>What happens next?</h3>
<p>The printer will pick up jobs from the queue in order (oldest first) on each poll.</p>

<h3>Actions</h3>
<p>
<a href="testprint.php?id=' . urlencode($printerId) . '">Send another test print</a> | 
<a href="testprint.php?id=' . urlencode($printerId) . '&amp;opendrawer=true">Test print + open drawer</a>
</p>

<h3>Generated XML</h3>
<details>
<summary>Click to view</summary>
<pre>' . htmlspecialchars($xml) . '</pre>
</details>
</body>
</html>';
} else {
    http_response_code(500);
    echo '<!DOCTYPE html>
<html>
<head><title>Test Print - Error</title></head>
<body>
<h1>Error: Failed to create print job</h1>
<p>' . htmlspecialchars($queueResult['error'] ?? 'Unknown error') . '</p>
</body>
</html>';
}
