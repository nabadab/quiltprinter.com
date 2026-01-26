<?php
/**
 * Epson TM-T88VII Server Direct Print Endpoint
 * 
 * This endpoint is polled by the printer once per second.
 * Optimized for speed - minimal processing, direct file operations.
 * Uses queue system for managing multiple print jobs per printer.
 */

// Set XML content type immediately
file_put_contents('debug.txt', json_encode($_REQUEST));

header('Content-Type: text/xml; charset=UTF-8');

// Include queue management
require_once __DIR__ . '/queue.php';

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;
}

// Get connection type
$connectionType = $_POST['ConnectionType'] ?? '';

if ($connectionType === 'GetRequest') {
    // Printer is polling for print jobs
    
    // Get printer ID - sanitize to prevent directory traversal
    $printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['ID'] ?? '');
    if (empty($printerId)) {
        $printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['Name'] ?? '');
    }
    
    if (empty($printerId)) {
        exit;
    }
    
    // Get next job from queue
    $job = getNextJob($printerId);
    
    if ($job !== null) {
        // Mark job as complete (moves to _pending directory)
        if (completeJob($job['file'], true)) {
            echo $job['content'];
        }
    }
    // If no job in queue, output nothing (empty response = no job)
    
} elseif ($connectionType === 'SetResponse') {
    // Printer is sending print result
    // Log the response for debugging if needed
    
    $printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['ID'] ?? '');
    if (empty($printerId)) {
        $printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['Name'] ?? '');
    }
    $responseXml = $_POST['ResponseFile'] ?? '';
    
    if (!empty($printerId) && !empty($responseXml)) {
        // Parse and log the response
        $logFile = QUEUE_BASE_DIR . 'print_results.log';
        $timestamp = date('Y-m-d H:i:s');
        
        try {
            $xml = @simplexml_load_string($responseXml);
            if ($xml) {
                $version = (string)($xml['Version'] ?? '1.00');
                $logEntry = "[$timestamp] Printer: $printerId, Version: $version\n";
                
                // Handle different response versions
                if ($version === '1.00') {
                    foreach ($xml->response as $response) {
                        $success = (string)$response['success'];
                        $code = (string)$response['code'];
                        $logEntry .= "  Result: success=$success, code=$code\n";
                    }
                } elseif ($version >= '2.00') {
                    foreach ($xml->ePOSPrint as $eposprint) {
                        $devid = (string)($eposprint->Parameter->devid ?? '');
                        $jobid = (string)($eposprint->Parameter->printjobid ?? '');
                        $response = $eposprint->PrintResponse->response ?? null;
                        if ($response) {
                            $success = (string)$response['success'];
                            $code = (string)$response['code'];
                            $logEntry .= "  Device: $devid, Job: $jobid, success=$success, code=$code\n";
                        }
                    }
                }
                
                file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            }
        } catch (Exception $e) {
            // Silently ignore parsing errors to keep response fast
        }
    }
}
