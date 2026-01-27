-- ============================================================================
-- Epson TM-T88VII Print Queue Database Setup
-- ============================================================================
-- Run this SQL to create the required database tables.
-- 
-- Usage:
--   mysql -u root -p your_database < databasesetup.sql
--
-- Or import via phpMyAdmin, MySQL Workbench, etc.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Print Queue Table
-- Stores pending, processing, and completed print jobs
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `print_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `printer_id` VARCHAR(64) NOT NULL,
    `job_id` VARCHAR(128) NOT NULL,
    `content` MEDIUMTEXT NOT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `processed_at` DATETIME(3) NULL DEFAULT NULL,
    `error_message` VARCHAR(255) NULL DEFAULT NULL,
    
    PRIMARY KEY (`id`),
    
    -- Index for fetching next job: find oldest pending job for a printer
    INDEX `idx_printer_status_created` (`printer_id`, `status`, `created_at`),
    
    -- Index for counting pending jobs per printer
    INDEX `idx_printer_status` (`printer_id`, `status`),
    
    -- Index for cleanup of old completed jobs
    INDEX `idx_status_processed` (`status`, `processed_at`),
    
    -- Index for job lookup by job_id
    INDEX `idx_job_id` (`job_id`)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- API Keys Table
-- Stores valid API keys for authentication
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_keys` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `api_key` VARCHAR(128) NOT NULL,
    `name` VARCHAR(255) NOT NULL DEFAULT 'Unnamed Key',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME NULL DEFAULT NULL,
    `request_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    
    PRIMARY KEY (`id`),
    
    -- Unique index for fast API key lookups
    UNIQUE INDEX `idx_api_key` (`api_key`),
    
    -- Index for listing active keys
    INDEX `idx_active` (`is_active`)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- Print Results Log Table (Optional)
-- Stores print completion responses from printers
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `print_results` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `printer_id` VARCHAR(64) NOT NULL,
    `job_id` VARCHAR(128) NULL,
    `success` TINYINT(1) NOT NULL,
    `code` VARCHAR(64) NULL,
    `status_flags` INT UNSIGNED NULL,
    `response_version` VARCHAR(16) NULL,
    `raw_response` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    
    -- Index for finding results by printer
    INDEX `idx_printer_created` (`printer_id`, `created_at`),
    
    -- Index for finding results by job
    INDEX `idx_job_id` (`job_id`)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- Sample API Key (for testing - REMOVE IN PRODUCTION)
-- ----------------------------------------------------------------------------
-- INSERT INTO `api_keys` (`api_key`, `name`) VALUES 
--     ('test_api_key_12345678', 'Test Key - Delete in Production');


-- ----------------------------------------------------------------------------
-- Stored Procedure: Queue a job with overflow handling
-- Automatically removes oldest job if queue exceeds max depth
-- ----------------------------------------------------------------------------
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS `queue_print_job`(
    IN p_printer_id VARCHAR(64),
    IN p_job_id VARCHAR(128),
    IN p_content MEDIUMTEXT,
    IN p_max_queue_depth INT,
    OUT p_queue_position INT,
    OUT p_queue_depth INT,
    OUT p_discarded_job_id VARCHAR(128)
)
BEGIN
    DECLARE v_pending_count INT;
    DECLARE v_oldest_id BIGINT;
    DECLARE v_oldest_job_id VARCHAR(128);
    
    -- Initialize outputs
    SET p_discarded_job_id = NULL;
    
    -- Count current pending jobs for this printer
    SELECT COUNT(*) INTO v_pending_count 
    FROM `print_queue` 
    WHERE `printer_id` = p_printer_id AND `status` = 'pending';
    
    -- If at or over max, remove oldest job(s)
    WHILE v_pending_count >= p_max_queue_depth DO
        -- Find oldest pending job
        SELECT `id`, `job_id` INTO v_oldest_id, v_oldest_job_id
        FROM `print_queue`
        WHERE `printer_id` = p_printer_id AND `status` = 'pending'
        ORDER BY `created_at` ASC
        LIMIT 1;
        
        -- Delete it
        DELETE FROM `print_queue` WHERE `id` = v_oldest_id;
        
        -- Store the discarded job ID (last one if multiple)
        SET p_discarded_job_id = v_oldest_job_id;
        
        SET v_pending_count = v_pending_count - 1;
    END WHILE;
    
    -- Insert the new job
    INSERT INTO `print_queue` (`printer_id`, `job_id`, `content`, `status`)
    VALUES (p_printer_id, p_job_id, p_content, 'pending');
    
    -- Get new queue depth
    SELECT COUNT(*) INTO p_queue_depth
    FROM `print_queue`
    WHERE `printer_id` = p_printer_id AND `status` = 'pending';
    
    -- Get position of this job in queue
    SELECT COUNT(*) INTO p_queue_position
    FROM `print_queue`
    WHERE `printer_id` = p_printer_id 
      AND `status` = 'pending'
      AND `created_at` <= (
          SELECT `created_at` FROM `print_queue` WHERE `job_id` = p_job_id ORDER BY `id` DESC LIMIT 1
      );
      
END //

DELIMITER ;


-- ----------------------------------------------------------------------------
-- Event: Cleanup old completed jobs (runs daily)
-- Removes completed jobs older than 7 days
-- ----------------------------------------------------------------------------
-- Note: Requires event_scheduler to be enabled:
--   SET GLOBAL event_scheduler = ON;
-- 
-- CREATE EVENT IF NOT EXISTS `cleanup_old_jobs`
-- ON SCHEDULE EVERY 1 DAY
-- STARTS CURRENT_TIMESTAMP
-- DO
--     DELETE FROM `print_queue` 
--     WHERE `status` IN ('completed', 'failed') 
--       AND `processed_at` < DATE_SUB(NOW(), INTERVAL 7 DAY);
