<?php
/**
 * Print Server Endpoint
 * 
 * Supports both Epson ePOS and Star CloudPRNT protocols.
 * This endpoint is polled by printers for print jobs.
 */

// Include queue management (which includes dbconnection.php)
require_once __DIR__ . '/queue.php';

// Debug logging
$rawBody = file_get_contents('php://input');
$jsonBody = json_decode($rawBody, true);

$allHeaders = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $allHeaders[substr($key, 5)] = $value;
    }
}

$debugData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'pid' => $_GET['pid'] ?? null,
    'raw_body' => $rawBody,
    'json_body' => $jsonBody,
    'all_headers' => $allHeaders,
    'request' => $_REQUEST,
];
//file_put_contents('debug.txt', json_encode($debugData, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

// Check if this is a Star CloudPRNT request (has pid query parameter)
$starPrinterId = isset($_GET['pid']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['pid']) : null;

if ($starPrinterId) {
    // ========================================
    // STAR CLOUDPRNT PROTOCOL
    // ========================================
    handleStarCloudPRNT($starPrinterId, $jsonBody);
    exit;
}

/**
 * Handle Star CloudPRNT protocol requests
 * 
 * Protocol flow:
 * 1. POST: Printer polls for jobs. Server responds with JSON indicating job availability.
 * 2. GET: Printer fetches job content. Server returns raw print data.
 * 3. DELETE: Printer confirms job completion. Server marks job as complete.
 */
function handleStarCloudPRNT(string $printerId, ?array $jsonBody): void
{
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'POST':
            // Printer is polling for print jobs
            header('Content-Type: application/json; charset=UTF-8');
            
            // Check if printer is currently printing (don't send new jobs)
            $printingInProgress = $jsonBody['printingInProgress'] ?? false;
            
            if ($printingInProgress) {
                // Printer is busy, don't offer new jobs
                echo json_encode(['jobReady' => false]);
                return;
            }
            
            // Check queue for pending jobs
            $job = peekNextJob($printerId);
            
            if ($job !== null) {
                // Determine media types based on job content
                $content = $job['content'];
                $mediaTypes = ['text/plain'];  // Default (also used for drawer-only)
                
                if (strpos($content, '[STAR:PNG]') === 0 || strpos($content, '[STAR:PNG:DRAWER]') === 0) {
                    $mediaTypes = ['image/png'];
                } elseif (strpos($content, '[STAR:MARKUP]') === 0 || strpos($content, '[STAR:MARKUP:DRAWER]') === 0) {
                    // Star Document Markup gets converted to plain text
                    // (Native markup requires CPUtil which we don't have)
                    $mediaTypes = ['text/plain'];
                }
                // drawer-only uses text/plain (default) to ensure headers are processed
                
                // Job available - tell printer to fetch it
                echo json_encode([
                    'jobReady' => true,
                    'mediaTypes' => $mediaTypes,
                    'jobToken' => (string)$job['id']
                ]);
            } else {
                // No jobs in queue
                echo json_encode(['jobReady' => false]);
            }
            break;
            
        case 'GET':
            // Printer is fetching the print job content
            $jobToken = $_GET['token'] ?? null;
            
            // Get the job (either by token or next in queue)
            if ($jobToken) {
                $job = getJobById((int)$jobToken, $printerId);
            } else {
                $job = getNextJob($printerId);
            }
            
            if ($job !== null) {
                $content = $job['content'];
                
                // Check for drawer-only job (no printing, just open drawer)
                if ($content === '[STAR:DRAWER_ONLY]') {
                    // Send empty response with drawer command headers
                    // Content-Length: 0 means nothing to print
                    // X-Star-Cut: none prevents any cut/feed
                    // X-Star-CashDrawer: start triggers drawer immediately
                    header('Content-Type: text/plain');
                    header('Content-Length: 0');
                    header('X-Star-Cut: none');
                    header('X-Star-CashDrawer: start');
                    // No echo - truly empty body
                }
                // Check if this is a PNG job (check DRAWER variant first since it's longer)
                elseif (strpos($content, '[STAR:PNG:DRAWER]') === 0) {
                    // PNG job with drawer
                    $pngResult = extractStarPngJob($content);
                    
                    header('Content-Type: image/png');
                    header('X-Star-Cut: partial; feed=true');
                    header('X-Star-CashDrawer: end');
                    
                    echo $pngResult['data'];
                }
                elseif (strpos($content, '[STAR:PNG]') === 0) {
                    // PNG job without drawer
                    $pngResult = extractStarPngJob($content);
                    
                    header('Content-Type: image/png');
                    header('X-Star-Cut: partial; feed=true');
                    
                    echo $pngResult['data'];
                }
                // Check if this is a Star Document Markup job
                // Note: Star Document Markup requires CPUtil to convert to printer commands
                // Since we don't have CPUtil, we convert markup to plain text
                elseif (strpos($content, '[STAR:MARKUP:DRAWER]') === 0) {
                    // Markup job with drawer
                    $markupContent = substr($content, strlen("[STAR:MARKUP:DRAWER]\n"));
                    $plainText = convertMarkupToPlainText($markupContent);
                    
                    header('Content-Type: text/plain; charset=UTF-8');
                    header('X-Star-Cut: partial; feed=true');
                    header('X-Star-CashDrawer: end');
                    
                    echo $plainText;
                }
                elseif (strpos($content, '[STAR:MARKUP]') === 0) {
                    // Markup job without drawer
                    $markupContent = substr($content, strlen("[STAR:MARKUP]\n"));
                    $plainText = convertMarkupToPlainText($markupContent);
                    
                    header('Content-Type: text/plain; charset=UTF-8');
                    header('X-Star-Cut: partial; feed=true');
                    
                    echo $plainText;
                } else {
                    // Plain text job
                    header('Content-Type: text/plain; charset=UTF-8');
                    header('X-Star-Cut: partial; feed=true');
                    
                    // Extract plain text and check for special options
                    $result = extractPlainTextFromJob($content);
                    
                    // Handle cash drawer if requested
                    if ($result['openDrawer']) {
                        header('X-Star-CashDrawer: end');
                    }
                    
                    echo $result['text'];
                }
            } else {
                // No job found
                http_response_code(404);
            }
            break;
            
        case 'DELETE':
            // Printer is confirming job completion
            $jobToken = $_GET['token'] ?? null;
            $code = $_GET['code'] ?? '';
            
            // Check if print was successful (code starts with "200")
            $success = strpos($code, '200') === 0;
            
            if ($jobToken) {
                completeJob((int)$jobToken, $success);
            }
            
            // Log the result
            logPrintResult($printerId, $jobToken, $success, $code, null, 'CloudPRNT', $code);
            
            http_response_code(200);
            break;
            
        default:
            http_response_code(405);
            break;
    }
}

/**
 * Peek at the next job without marking it as in-progress
 * Includes 'processing' jobs in case printer restarts mid-print
 */
function peekNextJob(string $printerId): ?array
{
    try {
        // First check for any processing jobs (printer might have restarted)
        $job = dbFetchOne(
            "SELECT `id`, `content` FROM `print_queue` 
             WHERE `printer_id` = ? AND `status` = 'processing' 
             ORDER BY `created_at` ASC 
             LIMIT 1",
            [$printerId]
        );
        
        if ($job) {
            return $job;
        }
        
        // Otherwise get next pending job
        return dbFetchOne(
            "SELECT `id`, `content` FROM `print_queue` 
             WHERE `printer_id` = ? AND `status` = 'pending' 
             ORDER BY `created_at` ASC 
             LIMIT 1",
            [$printerId]
        );
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Get a specific job by ID and mark as processing
 */
function getJobById(int $jobId, string $printerId): ?array
{
    try {
        $pdo = getDbConnection();
        $pdo->beginTransaction();
        
        // Get the job and lock the row
        $job = dbFetchOne(
            "SELECT `id`, `job_id`, `content` FROM `print_queue` 
             WHERE `id` = ? AND `printer_id` = ? AND `status` IN ('pending', 'processing')
             LIMIT 1 FOR UPDATE",
            [$jobId, $printerId]
        );
        
        if (!$job) {
            $pdo->commit();
            return null;
        }
        
        // Mark as processing
        dbExecute(
            "UPDATE `print_queue` SET `status` = 'processing', `processed_at` = NOW(3) WHERE `id` = ?",
            [$job['id']]
        );
        
        $pdo->commit();
        
        return [
            'id' => (int)$job['id'],
            'job_id' => $job['job_id'],
            'content' => $job['content']
        ];
        
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return null;
    }
}

/**
 * Extract plain text content from job format
 * Handles: Star text format (with markers), Epson ePOS XML, or plain text
 * 
 * @return array ['text' => string, 'openDrawer' => bool]
 */
function extractPlainTextFromJob(string $content): array
{
    $openDrawer = false;
    
    // Check for Star drawer marker at the start
    if (strpos($content, "[STAR:DRAWER]\n") === 0) {
        $openDrawer = true;
        $content = substr($content, strlen("[STAR:DRAWER]\n"));
    }
    
    // Try to parse as XML (Epson ePOS format)
    $xml = @simplexml_load_string($content);
    
    if ($xml === false) {
        // Not XML, return as-is (plain text for Star printers)
        return [
            'text' => $content,
            'openDrawer' => $openDrawer
        ];
    }
    
    $text = '';
    
    // Look for text elements in the XML
    // Epson ePOS uses <text> elements
    foreach ($xml->xpath('//text') as $textNode) {
        $text .= (string)$textNode . "\n";
    }
    
    // Check for pulse/drawer commands in XML
    $pulseElements = $xml->xpath('//pulse');
    if (!empty($pulseElements)) {
        $openDrawer = true;
    }
    
    // If no text elements found, try to get any text content
    if (empty($text)) {
        $text = strip_tags($content);
    }
    
    return [
        'text' => trim($text) . "\n",
        'openDrawer' => $openDrawer
    ];
}

/**
 * Extract PNG data from Star PNG job format
 * Format: [STAR:PNG] or [STAR:PNG:DRAWER] on first line, then base64 PNG data
 * 
 * @return array ['data' => binary PNG, 'openDrawer' => bool]
 */
function extractStarPngJob(string $content): array
{
    $openDrawer = false;
    
    // Check for PNG with drawer marker
    if (strpos($content, "[STAR:PNG:DRAWER]\n") === 0) {
        $openDrawer = true;
        $base64Data = substr($content, strlen("[STAR:PNG:DRAWER]\n"));
    } elseif (strpos($content, "[STAR:PNG]\n") === 0) {
        $base64Data = substr($content, strlen("[STAR:PNG]\n"));
    } else {
        // Unknown format, return empty
        return [
            'data' => '',
            'openDrawer' => false
        ];
    }
    
    // Decode base64 to get raw PNG binary
    $pngData = base64_decode($base64Data, true);
    if ($pngData === false) {
        $pngData = '';
    }
    
    return [
        'data' => $pngData,
        'openDrawer' => $openDrawer
    ];
}

/**
 * Convert Star Document Markup to plain text
 * 
 * Since Star Document Markup requires CPUtil for native processing,
 * we strip the markup tags and convert to plain text format.
 * 
 * @param string $markup Star Document Markup content
 * @return string Plain text content
 */
function convertMarkupToPlainText(string $markup): string
{
    // Remove cut commands (we handle cut via header)
    $text = preg_replace('/\[cut:[^\]]*\]/', '', $markup);
    
    // Remove formatting commands that don't translate to plain text
    $text = preg_replace('/\[magnify[^\]]*\]/', '', $text);
    $text = preg_replace('/\[bold:\s*(on|off)\]/', '', $text);
    $text = preg_replace('/\[underline:\s*(on|off)\]/', '', $text);
    $text = preg_replace('/\[invert:\s*(on|off)\]/', '', $text);
    $text = preg_replace('/\[align:\s*(left|center|centre|right)\]/', '', $text);
    $text = preg_replace('/\[font:[^\]]*\]/', '', $text);
    
    // Handle barcode - convert to text representation
    $text = preg_replace_callback('/\[barcode:[^\]]*data\s+([^;\]]+)[^\]]*\]/', function($m) {
        return '[BARCODE: ' . trim($m[1]) . ']';
    }, $text);
    
    // Handle QR code - convert to text representation
    $text = preg_replace_callback('/\[qrcode:[^\]]*data\s+([^;\]]+)[^\]]*\]/', function($m) {
        return '[QR: ' . trim($m[1]) . ']';
    }, $text);
    
    // Handle PDF417 - convert to text representation
    $text = preg_replace_callback('/\[pdf417:[^\]]*data\s+([^;\]]+)[^\]]*\]/', function($m) {
        return '[PDF417: ' . trim($m[1]) . ']';
    }, $text);
    
    // Remove image tags (can't render in plain text)
    $text = preg_replace('/\[image:[^\]]*\]/', '[IMAGE]', $text);
    
    // Remove any remaining markup tags
    $text = preg_replace('/\[[a-z]+:[^\]]*\]/i', '', $text);
    $text = preg_replace('/\[[a-z]+\]/i', '', $text);
    
    // Clean up multiple blank lines
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    
    // Trim whitespace
    $text = trim($text);
    
    return $text . "\n";
}

// ========================================
// EPSON ePOS PROTOCOL (existing code)
// ========================================

// Set XML content type for Epson
header('Content-Type: text/xml; charset=UTF-8');

// Get connection type
$connectionType = $_REQUEST['ConnectionType'] ?? '';
if ($connectionType === 'GetRequest') {
    // Printer is polling for print jobs
    
    // Get printer ID - sanitize to prevent injection
    $printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $_REQUEST['ID'] ?? '');
    if (empty($printerId)) {
        $printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $_REQUEST['Name'] ?? '');
    }
    
    if (empty($printerId)) {
        exit;
    }
    
    // Get next job from queue
    $job = getNextJob($printerId);
    
    if ($job !== null) {
        // Output the job content
        echo $job['content'];
        
        // Mark job as complete (it's been sent to printer)
        completeJob($job['id'], true);
    }
    // If no job in queue, output nothing (empty response = no job)
    
} elseif ($connectionType === 'SetResponse') {
    // Printer is sending print result
    
    $printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $_REQUEST['ID'] ?? '');
    if (empty($printerId)) {
        $printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $_REQUEST['Name'] ?? '');
    }
    $responseXml = $_REQUEST['ResponseFile'] ?? '';
    
    if (!empty($printerId) && !empty($responseXml)) {
        // Parse and log the response
        try {
            $xml = @simplexml_load_string($responseXml);
            if ($xml) {
                $version = (string)($xml['Version'] ?? '1.00');
                
                // Handle different response versions
                if ($version === '1.00') {
                    foreach ($xml->response as $response) {
                        $success = ((string)$response['success']) === 'true';
                        $code = (string)$response['code'];
                        $status = isset($response['status']) ? (int)$response['status'] : null;
                        
                        logPrintResult($printerId, null, $success, $code, $status, $version, $responseXml);
                    }
                } elseif ($version >= '2.00') {
                    foreach ($xml->ePOSPrint as $eposprint) {
                        $jobId = (string)($eposprint->Parameter->printjobid ?? '');
                        $response = $eposprint->PrintResponse->response ?? null;
                        
                        if ($response) {
                            $success = ((string)$response['success']) === 'true';
                            $code = (string)$response['code'];
                            $status = isset($response['status']) ? (int)$response['status'] : null;
                            
                            logPrintResult($printerId, $jobId, $success, $code, $status, $version, $responseXml);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Silently ignore parsing errors to keep response fast
        }
    }
}
