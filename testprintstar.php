<?php
/**
 * Test Print Generator for Star CloudPRNT Printers
 * 
 * Usage:
 *   testprintstar.php?pid=PRINTERID              - Create a test print job
 *   testprintstar.php?pid=PRINTERID&text=Hello   - Custom text to print
 *   testprintstar.php?pid=PRINTERID&opendrawer=true  - Open cash drawer
 */

// Include queue management
require_once __DIR__ . '/queue.php';

// Get parameters
$printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['pid'] ?? '');
$openDrawer = filter_var($_GET['opendrawer'] ?? false, FILTER_VALIDATE_BOOLEAN);
$customText = $_GET['text'] ?? '';

// Validate printer ID
if (empty($printerId)) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(400);
    echo '<!DOCTYPE html>
<html>
<head><title>Star Test Print - Error</title></head>
<body>
<h1>Error: Missing Printer ID</h1>
<p>Usage: <code>testprintstar.php?pid=PRINTERID</code></p>
<p>Options:</p>
<ul>
<li><code>&amp;text=YourText</code> - Custom text to print</li>
<li><code>&amp;opendrawer=true</code> - Open cash drawer</li>
</ul>
</body>
</html>';
    exit;
}

// Build the print content (plain text for Star CloudPRNT)
$timestamp = date('Y-m-d H:i:s');
$jobId = 'STAR_TEST_' . time() . '_' . mt_rand(1000, 9999);

// Build plain text receipt
// Star printers typically use 42 or 48 character width depending on model
$lineWidth = 42;

$lines = [];

// Header (centered)
$lines[] = str_pad('*** TEST PRINT ***', $lineWidth, ' ', STR_PAD_BOTH);
$lines[] = '';
$lines[] = str_repeat('=', $lineWidth);

// Info
$lines[] = 'Printer ID: ' . $printerId;
$lines[] = 'Timestamp:  ' . $timestamp;
$lines[] = 'Job ID:     ' . $jobId;

// Custom text if provided
if (!empty($customText)) {
    $lines[] = '';
    $lines[] = str_repeat('-', $lineWidth);
    $lines[] = 'Custom Message:';
    // Word wrap the custom text
    $wrapped = wordwrap($customText, $lineWidth, "\n", true);
    foreach (explode("\n", $wrapped) as $line) {
        $lines[] = $line;
    }
}

// Drawer status
$lines[] = '';
$lines[] = 'Cash Drawer: ' . ($openDrawer ? 'WILL OPEN' : 'No action');

// Footer
$lines[] = str_repeat('=', $lineWidth);
$lines[] = '';
$lines[] = str_pad('Star CloudPRNT Test', $lineWidth, ' ', STR_PAD_BOTH);
$lines[] = str_pad('quiltprinter.com', $lineWidth, ' ', STR_PAD_BOTH);
$lines[] = '';
$lines[] = '';

// Join lines with newlines
$printContent = implode("\n", $lines);

// Store metadata in a JSON wrapper so we can extract it during GET
// The actual content sent to printer will be extracted by extractPlainTextFromJob
// But for Star, we'll store it as plain text directly
$jobContent = $printContent;

// If opening drawer, we'll note it in the job metadata
// The X-Star-CashDrawer header is set during GET response
if ($openDrawer) {
    // Store as JSON with metadata for drawer control
    $jobContent = json_encode([
        'type' => 'star',
        'text' => $printContent,
        'openDrawer' => true
    ]);
}

// Queue the job
$queueResult = queueJob($printerId, $jobContent, $jobId);

// Output result
header('Content-Type: text/html; charset=UTF-8');

if ($queueResult['success']) {
    echo '<!DOCTYPE html>
<html>
<head>
<title>Star Test Print - Success</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 8px; }
.warning { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 10px; border-radius: 8px; margin-top: 15px; }
.info { background: #cce5ff; border: 1px solid #b8daff; color: #004085; padding: 10px; border-radius: 8px; margin-top: 15px; }
code { background: #f8f9fa; padding: 2px 6px; border-radius: 4px; }
pre { background: #f8f9fa; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; white-space: pre-wrap; }
.star-badge { display: inline-block; background: #6f42c1; color: white; padding: 3px 8px; border-radius: 4px; font-size: 12px; margin-left: 10px; }
</style>
</head>
<body>
<div class="success">
<h2>Print Job Queued! <span class="star-badge">Star CloudPRNT</span></h2>
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
<p>The Star printer polls the server at: <code>https://epson.quiltprinter.com?pid=' . htmlspecialchars($printerId) . '</code></p>
<p>When polled, the server responds with <code>jobReady: true</code>, and the printer fetches the job via GET request.</p>

<h3>Actions</h3>
<p>
<a href="testprintstar.php?pid=' . urlencode($printerId) . '">Send another test print</a> | 
<a href="testprintstar.php?pid=' . urlencode($printerId) . '&amp;opendrawer=true">Test print + open drawer</a>
</p>

<h3>Print Content Preview</h3>
<pre>' . htmlspecialchars($printContent) . '</pre>
</body>
</html>';
} else {
    http_response_code(500);
    echo '<!DOCTYPE html>
<html>
<head><title>Star Test Print - Error</title></head>
<body>
<h1>Error: Failed to create print job</h1>
<p>' . htmlspecialchars($queueResult['error'] ?? 'Unknown error') . '</p>
</body>
</html>';
}
