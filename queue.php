<?php
/**
 * Print Queue Management for Epson TM-T88VII (MySQL Version)
 * 
 * Handles queueing of print jobs with a max depth of 10 per printer.
 * When queue is full, oldest job is discarded.
 */

require_once __DIR__ . '/dbconnection.php';

define('QUEUE_MAX_DEPTH', 10);

/**
 * Add a job to the printer queue
 * 
 * If queue is at max depth, oldest job is discarded.
 * 
 * @param string $printerId Sanitized printer ID
 * @param string $jobContent The XML content of the print job
 * @param string|null $jobId Optional job ID
 * @return array ['success' => bool, 'queue_position' => int, 'queue_depth' => int, 'discarded' => bool, ...]
 */
function queueJob(string $printerId, string $jobContent, ?string $jobId = null): array
{
    try {
        $pdo = getDbConnection();
        
        // Generate job ID if not provided
        if (empty($jobId)) {
            $jobId = 'JOB_' . time() . '_' . mt_rand(1000, 9999);
        }
        
        // Start transaction for atomicity
        $pdo->beginTransaction();
        
        // Count current pending jobs for this printer
        $pendingCount = (int)dbFetchValue(
            "SELECT COUNT(*) FROM `print_queue` WHERE `printer_id` = ? AND `status` = 'pending'",
            [$printerId]
        );
        
        $discarded = false;
        $discardedJobId = null;
        
        // If at or over max, remove oldest job(s)
        while ($pendingCount >= QUEUE_MAX_DEPTH) {
            // Find and delete oldest pending job
            $oldest = dbFetchOne(
                "SELECT `id`, `job_id` FROM `print_queue` 
                 WHERE `printer_id` = ? AND `status` = 'pending' 
                 ORDER BY `created_at` ASC LIMIT 1",
                [$printerId]
            );
            
            if ($oldest) {
                dbExecute("DELETE FROM `print_queue` WHERE `id` = ?", [$oldest['id']]);
                $discarded = true;
                $discardedJobId = $oldest['job_id'];
                $pendingCount--;
            } else {
                break;
            }
        }
        
        // Insert the new job
        $insertId = dbInsert(
            "INSERT INTO `print_queue` (`printer_id`, `job_id`, `content`, `status`) VALUES (?, ?, ?, 'pending')",
            [$printerId, $jobId, $jobContent]
        );
        
        // Get new queue depth
        $queueDepth = (int)dbFetchValue(
            "SELECT COUNT(*) FROM `print_queue` WHERE `printer_id` = ? AND `status` = 'pending'",
            [$printerId]
        );
        
        // Get position of this job in queue (how many jobs are ahead of it + itself)
        $queuePosition = (int)dbFetchValue(
            "SELECT COUNT(*) FROM `print_queue` 
             WHERE `printer_id` = ? AND `status` = 'pending' AND `id` <= ?",
            [$printerId, $insertId]
        );
        
        $pdo->commit();
        
        return [
            'success' => true,
            'job_id' => $jobId,
            'queue_position' => $queuePosition,
            'queue_depth' => $queueDepth,
            'discarded' => $discarded,
            'discarded_job_id' => $discardedJobId
        ];
        
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ];
    }
}

/**
 * Get the next job from the queue (oldest/first pending)
 * 
 * Marks the job as 'processing' to prevent duplicate fetches.
 * 
 * @param string $printerId Sanitized printer ID
 * @return array|null ['id' => int, 'job_id' => string, 'content' => string] or null if no jobs
 */
function getNextJob(string $printerId): ?array
{
    try {
        $pdo = getDbConnection();
        
        // Use transaction with row locking to prevent race conditions
        $pdo->beginTransaction();
        
        // Find oldest pending job and lock the row
        $job = dbFetchOne(
            "SELECT `id`, `job_id`, `content` FROM `print_queue` 
             WHERE `printer_id` = ? AND `status` = 'pending' 
             ORDER BY `created_at` ASC 
             LIMIT 1 
             FOR UPDATE",
            [$printerId]
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
 * Mark a job as complete
 * 
 * @param int $jobDbId Database ID of the job
 * @param bool $success Whether the job completed successfully
 * @param string|null $errorMessage Error message if failed
 * @return bool Success
 */
function completeJob(int $jobDbId, bool $success = true, ?string $errorMessage = null): bool
{
    try {
        $status = $success ? 'completed' : 'failed';
        
        $affected = dbExecute(
            "UPDATE `print_queue` SET `status` = ?, `processed_at` = NOW(3), `error_message` = ? WHERE `id` = ?",
            [$status, $errorMessage, $jobDbId]
        );
        
        return $affected > 0;
        
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Delete a job from the queue
 * 
 * @param int $jobDbId Database ID of the job
 * @return bool Success
 */
function deleteJob(int $jobDbId): bool
{
    try {
        $affected = dbExecute("DELETE FROM `print_queue` WHERE `id` = ?", [$jobDbId]);
        return $affected > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get the count of pending jobs in queue
 * 
 * @param string $printerId Sanitized printer ID
 * @return int Number of pending jobs
 */
function getQueueCount(string $printerId): int
{
    try {
        return (int)dbFetchValue(
            "SELECT COUNT(*) FROM `print_queue` WHERE `printer_id` = ? AND `status` = 'pending'",
            [$printerId]
        );
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Get list of queued jobs for a printer
 * 
 * @param string $printerId Sanitized printer ID
 * @param string $status Filter by status (null for all)
 * @return array List of jobs
 */
function getQueuedJobs(string $printerId, ?string $status = 'pending'): array
{
    try {
        if ($status) {
            return dbFetchAll(
                "SELECT `id`, `job_id`, `status`, `created_at`, `processed_at` 
                 FROM `print_queue` 
                 WHERE `printer_id` = ? AND `status` = ?
                 ORDER BY `created_at` ASC",
                [$printerId, $status]
            );
        } else {
            return dbFetchAll(
                "SELECT `id`, `job_id`, `status`, `created_at`, `processed_at` 
                 FROM `print_queue` 
                 WHERE `printer_id` = ?
                 ORDER BY `created_at` ASC",
                [$printerId]
            );
        }
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Clear all pending jobs from a printer's queue
 * 
 * @param string $printerId Sanitized printer ID
 * @return int Number of jobs cleared
 */
function clearQueue(string $printerId): int
{
    try {
        return dbExecute(
            "DELETE FROM `print_queue` WHERE `printer_id` = ? AND `status` = 'pending'",
            [$printerId]
        );
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Get queue status for a printer
 * 
 * @param string $printerId Sanitized printer ID
 * @return array Queue status information
 */
function getQueueStatus(string $printerId): array
{
    try {
        $pending = (int)dbFetchValue(
            "SELECT COUNT(*) FROM `print_queue` WHERE `printer_id` = ? AND `status` = 'pending'",
            [$printerId]
        );
        
        $processing = (int)dbFetchValue(
            "SELECT COUNT(*) FROM `print_queue` WHERE `printer_id` = ? AND `status` = 'processing'",
            [$printerId]
        );
        
        $jobs = dbFetchAll(
            "SELECT `id`, `job_id`, `status`, `created_at`, `processed_at`
             FROM `print_queue` 
             WHERE `printer_id` = ? AND `status` IN ('pending', 'processing')
             ORDER BY `created_at` ASC",
            [$printerId]
        );
        
        return [
            'printer_id' => $printerId,
            'pending_count' => $pending,
            'processing_count' => $processing,
            'max_depth' => QUEUE_MAX_DEPTH,
            'jobs' => $jobs
        ];
        
    } catch (PDOException $e) {
        return [
            'printer_id' => $printerId,
            'pending_count' => 0,
            'processing_count' => 0,
            'max_depth' => QUEUE_MAX_DEPTH,
            'jobs' => [],
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Log a print result from the printer
 * 
 * @param string $printerId Printer ID
 * @param string|null $jobId Job ID if available
 * @param bool $success Whether print succeeded
 * @param string|null $code Error/status code
 * @param int|null $statusFlags Status flags from printer
 * @param string|null $version Response version
 * @param string|null $rawResponse Raw XML response
 * @return bool Success
 */
function logPrintResult(
    string $printerId,
    ?string $jobId,
    bool $success,
    ?string $code = null,
    ?int $statusFlags = null,
    ?string $version = null,
    ?string $rawResponse = null
): bool {
    try {
        dbInsert(
            "INSERT INTO `print_results` 
             (`printer_id`, `job_id`, `success`, `code`, `status_flags`, `response_version`, `raw_response`)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$printerId, $jobId, $success ? 1 : 0, $code, $statusFlags, $version, $rawResponse]
        );
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Cleanup old completed/failed jobs
 * 
 * @param int $daysOld Delete jobs older than this many days
 * @return int Number of jobs deleted
 */
function cleanupOldJobs(int $daysOld = 7): int
{
    try {
        return dbExecute(
            "DELETE FROM `print_queue` 
             WHERE `status` IN ('completed', 'failed') 
             AND `processed_at` < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$daysOld]
        );
    } catch (PDOException $e) {
        return 0;
    }
}
