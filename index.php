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
file_put_contents('debug.txt', json_encode($debugData, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

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
                // Job available - tell printer to fetch it
                echo json_encode([
                    'jobReady' => true,
                    'mediaTypes' => ['text/plain'],
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
                // Return the job content as plain text
                header('Content-Type: text/plain; charset=UTF-8');
                header('X-Star-Cut: partial; feed=true');
                
                // Extract plain text and check for special options
                $result = extractPlainTextFromJob($job['content']);
                
                // Handle cash drawer if requested
                if ($result['openDrawer']) {
                    header('X-Star-CashDrawer: end');
                }
                
                echo $result['text'];
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
 * Handles: Star JSON format, Epson ePOS XML, or plain text
 * 
 * @return array ['text' => string, 'openDrawer' => bool]
 */
function extractPlainTextFromJob(string $content): array
{
    $openDrawer = false;
    
    // First, try to parse as JSON (Star format with metadata)
    $json = @json_decode($content, true);
    if ($json !== null && isset($json['type']) && $json['type'] === 'star') {
        return [
            'text' => trim($json['text'] ?? '') . "\n",
            'openDrawer' => $json['openDrawer'] ?? false
        ];
    }
    
    // Try to parse as XML (Epson ePOS format)
    $xml = @simplexml_load_string($content);
    
    if ($xml === false) {
        // Not XML or JSON, return as-is (plain text)
        return [
            'text' => $content,
            'openDrawer' => false
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
