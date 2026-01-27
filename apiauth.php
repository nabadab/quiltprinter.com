<?php
/**
 * API Authentication for Epson TM-T88VII Print Queue
 * 
 * Provides API key validation using MySQL database.
 */

require_once __DIR__ . '/dbconnection.php';

define('MIN_APIKEY_LENGTH', 16);

/**
 * Validate API key
 * 
 * Checks if the API key exists in the database and is active.
 * Updates last_used_at and request_count on successful validation.
 * 
 * @param string $key The API key to validate
 * @return bool True if valid, false otherwise
 */
function validateApiKey(string $key): bool
{
    // Quick length check first (fast fail)
    if (strlen($key) < MIN_APIKEY_LENGTH) {
        return false;
    }
    
    // Sanitize to prevent injection (only allow alphanumeric, dash, underscore)
    $safeKey = preg_replace('/[^A-Za-z0-9_-]/', '', $key);
    if ($safeKey !== $key) {
        return false;  // Key contained invalid characters
    }
    
    try {
        // Check if key exists and is active
        $result = dbFetchOne(
            "SELECT `id`, `is_active` FROM `api_keys` WHERE `api_key` = ? LIMIT 1",
            [$key]
        );
        
        if (!$result || !$result['is_active']) {
            return false;
        }
        
        // Update usage stats (non-blocking, fire and forget)
        try {
            dbExecute(
                "UPDATE `api_keys` SET `last_used_at` = NOW(), `request_count` = `request_count` + 1 WHERE `id` = ?",
                [$result['id']]
            );
        } catch (PDOException $e) {
            // Ignore update failures - don't block the request
        }
        
        return true;
        
    } catch (PDOException $e) {
        // On database error, fail closed (deny access)
        return false;
    }
}

/**
 * Create a new API key
 * 
 * @param string $name Optional name for the key
 * @return array ['success' => bool, 'api_key' => string] or ['success' => false, 'error' => string]
 */
function createApiKey(string $name = 'Unnamed Key'): array
{
    try {
        // Generate a secure random key
        $key = bin2hex(random_bytes(16));  // 32 character hex string
        
        dbInsert(
            "INSERT INTO `api_keys` (`api_key`, `name`) VALUES (?, ?)",
            [$key, $name]
        );
        
        return [
            'success' => true,
            'api_key' => $key,
            'name' => $name
        ];
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'error' => 'Failed to create API key: ' . $e->getMessage()
        ];
    }
}

/**
 * Deactivate an API key
 * 
 * @param string $key The API key to deactivate
 * @return bool True if deactivated, false if not found or error
 */
function deactivateApiKey(string $key): bool
{
    try {
        $affected = dbExecute(
            "UPDATE `api_keys` SET `is_active` = 0 WHERE `api_key` = ?",
            [$key]
        );
        return $affected > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * List all API keys (without exposing full keys)
 * 
 * @param bool $activeOnly Only return active keys
 * @return array List of API keys with partial key shown
 */
function listApiKeys(bool $activeOnly = true): array
{
    try {
        $sql = "SELECT `id`, `api_key`, `name`, `is_active`, `created_at`, `last_used_at`, `request_count` 
                FROM `api_keys`";
        
        if ($activeOnly) {
            $sql .= " WHERE `is_active` = 1";
        }
        
        $sql .= " ORDER BY `created_at` DESC";
        
        $keys = dbFetchAll($sql);
        
        // Mask the API keys for security
        foreach ($keys as &$key) {
            $fullKey = $key['api_key'];
            $key['api_key_masked'] = substr($fullKey, 0, 8) . '...' . substr($fullKey, -4);
            unset($key['api_key']);  // Remove full key from response
        }
        
        return $keys;
        
    } catch (PDOException $e) {
        return [];
    }
}
