<?php
/**
 * Test Print Generator for Star CloudPRNT Printers
 * 
 * Usage:
 *   testprintstar.php?pid=PRINTERID              - Create a markup test print job (default)
 *   testprintstar.php?pid=PRINTERID&text=Hello   - Custom text to print
 *   testprintstar.php?pid=PRINTERID&opendrawer=true  - Open cash drawer
 *   testprintstar.php?pid=PRINTERID&testpng=true     - Print a small test PNG image
 *   testprintstar.php?pid=PRINTERID&plaintext=true   - Use plain text instead of markup
 */

// Include queue management
require_once __DIR__ . '/queue.php';

// Small test PNG image (30x30 gray square) - base64 encoded
define('TEST_PNG_BASE64', 'iVBORw0KGgoAAAANSUhEUgAAAB4AAAAeCAIAAAC0Ujn1AAABhWlDQ1BJQ0MgcHJvZmlsZQAAKJF9kb1Lw1AUxU9TRZGKgh1EKgSpTnZREcdSxSJYKG2FVh1MXvoFTRqSFBdHwbXg4Mdi1cHFWVcHV0EQ/ADxDxAnRRcp8b6k0CLGC4/347x7Du/dBwiNClPNriigapaRisfEbG5V7HmFD6MYRAhjEjP1RHoxA8/6uqduqrsIz/Lu+7P6lbzJAJ9IHGW6YRFvEM9uWjrnfeIgK0kK8TnxpEEXJH7kuuzyG+eiwwLPDBqZ1DxxkFgsdrDcwaxkqMQzxGFF1ShfyLqscN7irFZqrHVP/sJAXltJc51WCHEsIYEkRMiooYwKLERo10gxkaLzmId/xPEnySWTqwxGjgVUoUJy/OB/8Hu2ZmF6yk0KxIDuF9v+GAd6doFm3ba/j227eQL4n4Erre2vNoC5T9LrbS18BAxsAxfXbU3eAy53gOEnXTIkR/LTEgoF4P2MvikHDN0CfWvu3FrnOH0AMjSr5Rvg4BCYKFL2use7ezvn9m9Pa34/0LRyzEJfoQUAAAAJcEhZcwAALiMAAC4jAXilP3YAAAAHdElNRQfqAR0QHRuNNzI2AAAAGXRFWHRDb21tZW50AENyZWF0ZWQgd2l0aCBHSU1QV4EOFwAAADBJREFUSMdjYBgFo2D4AUZcEv///yfBFEYs5jDRztWjRo8aPWr0CDB6FIyCUUASAAC/igMaew3hywAAAABJRU5ErkJggg==');

// Get parameters
$printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['pid'] ?? '');
$openDrawer = filter_var($_GET['opendrawer'] ?? false, FILTER_VALIDATE_BOOLEAN);
$testPng = filter_var($_GET['testpng'] ?? false, FILTER_VALIDATE_BOOLEAN);
$plainText = filter_var($_GET['plaintext'] ?? false, FILTER_VALIDATE_BOOLEAN);
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
<li><code>&amp;testpng=true</code> - Print a small test PNG image</li>
<li><code>&amp;plaintext=true</code> - Use plain text instead of markup</li>
</ul>
</body>
</html>';
    exit;
}

// Build the print content
$timestamp = date('Y-m-d H:i:s');
$jobType = 'markup';  // Default
$printContent = '';   // For preview

if ($testPng) {
    // PNG test job
    $jobType = 'png';
    $jobId = 'STAR_PNG_TEST_' . time() . '_' . mt_rand(1000, 9999);
    
    // Build PNG job content: [STAR:PNG] or [STAR:PNG:DRAWER] + base64 data
    $header = $openDrawer ? '[STAR:PNG:DRAWER]' : '[STAR:PNG]';
    $jobContent = $header . "\n" . TEST_PNG_BASE64;
    
    $printContent = "(PNG Image - 30x30 pixels)";
    
} elseif ($plainText) {
    // Plain text job (legacy)
    $jobType = 'plaintext';
    $jobId = 'STAR_TEXT_' . time() . '_' . mt_rand(1000, 9999);
    
    $lineWidth = 48;
    $lines = [];

    $lines[] = str_pad('*** TEST PRINT ***', $lineWidth, ' ', STR_PAD_BOTH);
    $lines[] = '';
    $lines[] = str_repeat('=', $lineWidth);
    $lines[] = 'Printer ID: ' . $printerId;
    $lines[] = 'Timestamp:  ' . $timestamp;
    $lines[] = 'Job ID:     ' . $jobId;
    $lines[] = 'Format:     Plain Text';

    if (!empty($customText)) {
        $lines[] = '';
        $lines[] = str_repeat('-', $lineWidth);
        $lines[] = 'Custom Message:';
        $wrapped = wordwrap($customText, $lineWidth, "\n", true);
        foreach (explode("\n", $wrapped) as $line) {
            $lines[] = $line;
        }
    }

    $lines[] = '';
    $lines[] = 'Cash Drawer: ' . ($openDrawer ? 'WILL OPEN' : 'No action');
    $lines[] = str_repeat('=', $lineWidth);
    $lines[] = '';
    $lines[] = str_pad('Star CloudPRNT Test', $lineWidth, ' ', STR_PAD_BOTH);
    $lines[] = str_pad('quiltprinter.com', $lineWidth, ' ', STR_PAD_BOTH);
    $lines[] = '';

    $printContent = implode("\n", $lines);
    $jobContent = $openDrawer ? "[STAR:DRAWER]\n" . $printContent : $printContent;
    
} else {
    // Star Document Markup job (default - faster!)
    $jobType = 'markup';
    $jobId = 'STAR_MARKUP_TEST_' . time() . '_' . mt_rand(1000, 9999);
    
    // Build Star Document Markup
    $markup = [];
    
    // Header
    $markup[] = '[align: center]';
    $markup[] = '[magnify: width 2; height 2]';
    $markup[] = '*** TEST PRINT ***';
    $markup[] = '[magnify: width 1; height 1]';
    $markup[] = '';
    $markup[] = '[align: left]';
    $markup[] = '================================================';
    
    // Info
    $markup[] = '[bold: on]';
    $markup[] = 'Printer ID:[bold: off] ' . $printerId;
    $markup[] = '[bold: on]';
    $markup[] = 'Timestamp:[bold: off]  ' . $timestamp;
    $markup[] = '[bold: on]';
    $markup[] = 'Job ID:[bold: off]     ' . $jobId;
    $markup[] = '[bold: on]';
    $markup[] = 'Format:[bold: off]     Star Document Markup';
    
    // Custom text if provided
    if (!empty($customText)) {
        $markup[] = '';
        $markup[] = '------------------------------------------------';
        $markup[] = '[bold: on]Custom Message:[bold: off]';
        $markup[] = $customText;
    }
    
    // Drawer status
    $markup[] = '';
    $markup[] = '[bold: on]Cash Drawer:[bold: off] ' . ($openDrawer ? 'WILL OPEN' : 'No action');
    
    // Barcode demo
    $markup[] = '';
    $markup[] = '------------------------------------------------';
    $markup[] = '[align: center]';
    $markup[] = '[barcode: type code128; data ' . $jobId . '; height 40; hri below]';
    $markup[] = '';
    
    // QR code demo
    $markup[] = '[qrcode: data https://quiltprinter.com; cell 4]';
    $markup[] = '';
    
    // Footer
    $markup[] = '================================================';
    $markup[] = '[magnify: width 1; height 2]';
    $markup[] = 'Star CloudPRNT Test';
    $markup[] = '[magnify: width 1; height 1]';
    $markup[] = 'quiltprinter.com';
    $markup[] = '[align: left]';
    $markup[] = '';
    
    // Cut
    $markup[] = '[cut: feed; partial]';
    
    $printContent = implode("\n", $markup);
    
    // Build job content with header
    $header = $openDrawer ? '[STAR:MARKUP:DRAWER]' : '[STAR:MARKUP]';
    $jobContent = $header . "\n" . $printContent;
}

// Queue the job
$queueResult = queueJob($printerId, $jobContent, $jobId);

// Job type badges
$typeBadges = [
    'markup' => '<span class="star-badge" style="background:#17a2b8;">Markup</span>',
    'png' => '<span class="star-badge" style="background:#28a745;">PNG</span>',
    'plaintext' => '<span class="star-badge" style="background:#6c757d;">Plain Text</span>',
];
$typeLabels = [
    'markup' => 'Star Document Markup',
    'png' => 'PNG Image',
    'plaintext' => 'Plain Text',
];

// Output result
header('Content-Type: text/html; charset=UTF-8');

if ($queueResult['success']) {
    echo '<!DOCTYPE html>
<html>
<head>
<title>Star Test Print - Success</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 700px; margin: 50px auto; padding: 20px; }
.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 8px; }
.warning { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 10px; border-radius: 8px; margin-top: 15px; }
.info { background: #cce5ff; border: 1px solid #b8daff; color: #004085; padding: 10px; border-radius: 8px; margin-top: 15px; }
code { background: #f8f9fa; padding: 2px 6px; border-radius: 4px; }
pre { background: #f8f9fa; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 11px; white-space: pre-wrap; max-height: 400px; }
.star-badge { display: inline-block; background: #6f42c1; color: white; padding: 3px 8px; border-radius: 4px; font-size: 12px; margin-left: 5px; }
.actions { margin-top: 20px; }
.actions a { display: inline-block; margin: 5px 10px 5px 0; padding: 8px 16px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; font-size: 14px; }
.actions a:hover { background: #0056b3; }
.actions a.secondary { background: #6c757d; }
.actions a.success { background: #28a745; }
</style>
</head>
<body>
<div class="success">
<h2>Print Job Queued! <span class="star-badge">Star CloudPRNT</span> ' . $typeBadges[$jobType] . '</h2>
<p><strong>Printer ID:</strong> <code>' . htmlspecialchars($printerId) . '</code></p>
<p><strong>Job ID:</strong> <code>' . htmlspecialchars($jobId) . '</code></p>
<p><strong>Job Type:</strong> ' . $typeLabels[$jobType] . '</p>
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

<div class="actions">
<h3>Test Actions</h3>
<a href="testprintstar.php?pid=' . urlencode($printerId) . '">Markup Test</a>
<a href="testprintstar.php?pid=' . urlencode($printerId) . '&amp;opendrawer=true" class="success">Markup + Drawer</a>
<a href="testprintstar.php?pid=' . urlencode($printerId) . '&amp;testpng=true" class="secondary">PNG Test</a>
<a href="testprintstar.php?pid=' . urlencode($printerId) . '&amp;plaintext=true" class="secondary">Plain Text</a>
</div>

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
