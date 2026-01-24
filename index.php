<?php
/**
 * Epson TM-T88VII Server Direct Print Endpoint
 * 
 * This endpoint is polled by the printer once per second.
 * Optimized for speed - minimal processing, direct file operations.
 */

// Set XML content type immediately
header('Content-Type: text/xml; charset=UTF-8');

// Directory where print jobs are stored
define('JOBS_DIR', __DIR__ . '/jobs/');

// Ensure jobs directory exists
if (!is_dir(JOBS_DIR)) {
    mkdir(JOBS_DIR, 0755, true);
}

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
        exit;
    }
    
    $jobFile = JOBS_DIR . $printerId . '.txt';
    $pendingFile = JOBS_DIR . $printerId . '_pending_' . time() . '.txt';
    
    // Check if job file exists
    if (file_exists($jobFile)) {
        // Read and output the job
        $content = file_get_contents($jobFile);
        
        // Immediately rename to prevent duplicate printing
        // Use atomic rename for race condition safety
        if (rename($jobFile, $pendingFile)) {
            echo $content;
        }
    }
    // If no job file, output nothing (empty response = no job)
    
} elseif ($connectionType === 'SetResponse') {
    // Printer is sending print result
    // Log the response for debugging if needed
    
    $printerId = preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['ID'] ?? '');
    $responseXml = $_POST['ResponseFile'] ?? '';
    
    if (!empty($printerId) && !empty($responseXml)) {
        // Parse and log the response
        $logFile = JOBS_DIR . 'print_results.log';
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
