<?php
/**
 * Print Queue Management for Epson TM-T88VII
 * 
 * Handles queueing of print jobs with a max depth of 10 per printer.
 * When queue is full, oldest job is discarded.
 */

define('QUEUE_BASE_DIR', __DIR__ . '/jobs/');
define('QUEUE_MAX_DEPTH', 10);

/**
 * Get the queue directory path for a printer
 * 
 * @param string $printerId Sanitized printer ID
 * @return string Full path to queue directory
 */
function getQueueDir(string $printerId): string
{
    return QUEUE_BASE_DIR . $printerId . '/';
}

/**
 * Ensure queue directory exists for a printer
 * 
 * @param string $printerId Sanitized printer ID
 * @return string Full path to queue directory
 */
function ensureQueueDir(string $printerId): string
{
    $queueDir = getQueueDir($printerId);
    if (!is_dir($queueDir)) {
        mkdir($queueDir, 0755, true);
    }
    return $queueDir;
}

/**
 * Get list of queued job files for a printer, sorted oldest first
 * 
 * @param string $printerId Sanitized printer ID
 * @return array List of full file paths, sorted by filename (oldest first)
 */
function getQueuedJobs(string $printerId): array
{
    $queueDir = getQueueDir($printerId);
    
    if (!is_dir($queueDir)) {
        return [];
    }
    
    $files = glob($queueDir . '*.txt');
    if ($files === false) {
        return [];
    }
    
    // Sort by filename (timestamp-based, so oldest first)
    sort($files, SORT_STRING);
    
    return $files;
}

/**
 * Get the count of jobs in queue
 * 
 * @param string $printerId Sanitized printer ID
 * @return int Number of jobs in queue
 */
function getQueueCount(string $printerId): int
{
    return count(getQueuedJobs($printerId));
}

/**
 * Add a job to the printer queue
 * 
 * If queue is at max depth, oldest job is discarded.
 * 
 * @param string $printerId Sanitized printer ID
 * @param string $jobContent The XML content of the print job
 * @param string|null $jobId Optional job ID for the filename
 * @return array ['success' => bool, 'file' => string, 'discarded' => bool, 'queue_position' => int]
 */
function queueJob(string $printerId, string $jobContent, ?string $jobId = null): array
{
    $queueDir = ensureQueueDir($printerId);
    $jobs = getQueuedJobs($printerId);
    $discarded = false;
    $discardedFile = null;
    
    // If queue is full, remove oldest job(s) to make room
    while (count($jobs) >= QUEUE_MAX_DEPTH) {
        $oldestJob = array_shift($jobs);
        if ($oldestJob && file_exists($oldestJob)) {
            @unlink($oldestJob);
            $discarded = true;
            $discardedFile = basename($oldestJob);
        }
    }
    
    // Generate unique filename with timestamp for sorting
    // Format: {timestamp_microseconds}_{random}.txt
    $timestamp = sprintf('%d%06d', time(), (int)(microtime(true) * 1000000) % 1000000);
    $random = mt_rand(1000, 9999);
    $filename = $timestamp . '_' . $random . '.txt';
    $filepath = $queueDir . $filename;
    
    // Write the job file
    $result = file_put_contents($filepath, $jobContent, LOCK_EX);
    
    if ($result === false) {
        return [
            'success' => false,
            'error' => 'Failed to write job file',
            'file' => null,
            'discarded' => $discarded,
            'queue_position' => -1
        ];
    }
    
    // Get new queue position (1-based)
    $newJobs = getQueuedJobs($printerId);
    $position = array_search($filepath, $newJobs);
    $queuePosition = ($position !== false) ? $position + 1 : count($newJobs);
    
    return [
        'success' => true,
        'file' => $filename,
        'discarded' => $discarded,
        'discarded_file' => $discardedFile,
        'queue_position' => $queuePosition,
        'queue_depth' => count($newJobs)
    ];
}

/**
 * Get the next job from the queue (oldest/first)
 * 
 * @param string $printerId Sanitized printer ID
 * @return array|null ['content' => string, 'file' => string] or null if no jobs
 */
function getNextJob(string $printerId): ?array
{
    $jobs = getQueuedJobs($printerId);
    
    if (empty($jobs)) {
        return null;
    }
    
    $nextJob = $jobs[0];  // Oldest job (first in sorted list)
    
    if (!file_exists($nextJob)) {
        return null;
    }
    
    $content = file_get_contents($nextJob);
    if ($content === false) {
        return null;
    }
    
    return [
        'content' => $content,
        'file' => $nextJob,
        'filename' => basename($nextJob)
    ];
}

/**
 * Mark a job as complete (remove from queue, optionally archive)
 * 
 * @param string $filepath Full path to the job file
 * @param bool $archive If true, move to pending instead of deleting
 * @return bool Success
 */
function completeJob(string $filepath, bool $archive = true): bool
{
    if (!file_exists($filepath)) {
        return false;
    }
    
    if ($archive) {
        // Move to pending directory
        $pendingDir = QUEUE_BASE_DIR . '_pending/';
        if (!is_dir($pendingDir)) {
            mkdir($pendingDir, 0755, true);
        }
        
        $pendingFile = $pendingDir . basename($filepath, '.txt') . '_' . time() . '.txt';
        return rename($filepath, $pendingFile);
    } else {
        return unlink($filepath);
    }
}

/**
 * Clear all jobs from a printer's queue
 * 
 * @param string $printerId Sanitized printer ID
 * @return int Number of jobs cleared
 */
function clearQueue(string $printerId): int
{
    $jobs = getQueuedJobs($printerId);
    $cleared = 0;
    
    foreach ($jobs as $job) {
        if (@unlink($job)) {
            $cleared++;
        }
    }
    
    return $cleared;
}

/**
 * Get queue status for a printer
 * 
 * @param string $printerId Sanitized printer ID
 * @return array Queue status information
 */
function getQueueStatus(string $printerId): array
{
    $jobs = getQueuedJobs($printerId);
    
    return [
        'printer_id' => $printerId,
        'queue_depth' => count($jobs),
        'max_depth' => QUEUE_MAX_DEPTH,
        'jobs' => array_map(function($path) {
            return [
                'filename' => basename($path),
                'size' => filesize($path),
                'created' => filectime($path)
            ];
        }, $jobs)
    ];
}
