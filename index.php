<?php
/**
 * Epson TM-T88VII Server Direct Print Endpoint
 * 
 * This endpoint is polled by the printer once per second.
 * Optimized for speed - minimal processing, database-backed queue.
 */

// Set XML content type immediately
header('Content-Type: text/xml; charset=UTF-8');
file_put_contents('debug.txt', print_r($_REQUEST, true));

// Include queue management (which includes dbconnection.php)
require_once __DIR__ . '/queue.php';

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    //exit;
}

// Get connection type
$connectionType = $_REQUEST['ConnectionType'] ?? '';
file_put_contents('debug5.txt', $connectionType);
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
        file_put_contents('debug3.txt', $job['content']);
        
        // Mark job as complete (it's been sent to printer)
        completeJob($job['id'], true);
        file_put_contents('debug4.txt', "Job completed");
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
