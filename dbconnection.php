<?php
/**
 * Database Connection for Epson TM-T88VII Print Queue
 * 
 * This file loads database configuration from config_live.php and provides
 * a PDO connection for use throughout the application.
 * 
 * ============================================================================
 * REQUIRED: Create config_live.php with the following variables:
 * ============================================================================
 * 
 * <?php
 * // Database connection parameters
 * $db_host = 'localhost';           // Database host (e.g., 'localhost', '127.0.0.1', 'db.example.com')
 * $db_name = 'your_database_name';  // Database name
 * $db_user = 'your_username';       // Database username
 * $db_pass = 'your_password';       // Database password
 * $db_port = 3306;                  // Database port (optional, defaults to 3306)
 * $db_charset = 'utf8mb4';          // Character set (optional, defaults to utf8mb4)
 * 
 * ============================================================================
 */

// Load configuration
$configFile = __DIR__ . '/config_live.php';
if (!file_exists($configFile)) {
    throw new RuntimeException(
        "Configuration file not found: config_live.php\n" .
        "Please create this file with your database credentials.\n" .
        "See dbconnection.php for required variables."
    );
}

require_once $configFile;

// Validate required variables
if (!isset($db_host) || !isset($db_name) || !isset($db_user) || !isset($db_pass)) {
    throw new RuntimeException(
        "Missing required database configuration variables.\n" .
        "Required: \$db_host, \$db_name, \$db_user, \$db_pass"
    );
}

// Set defaults for optional variables
$db_port = $db_port ?? 3306;
$db_charset = $db_charset ?? 'utf8mb4';

/**
 * Get a PDO database connection
 * 
 * Uses a singleton pattern to reuse connections within a single request.
 * 
 * @return PDO
 * @throws PDOException If connection fails
 */
function getDbConnection(): PDO
{
    static $pdo = null;
    
    if ($pdo === null) {
        global $db_host, $db_name, $db_user, $db_pass, $db_port, $db_charset;
        
        $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset={$db_charset}";
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$db_charset}",
            // Enable persistent connections for better performance under load
            PDO::ATTR_PERSISTENT => true,
        ];
        
        $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    }
    
    return $pdo;
}

/**
 * Execute a query and return the statement
 * 
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @return PDOStatement
 */
function dbQuery(string $sql, array $params = []): PDOStatement
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Execute a query and return all rows
 * 
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @return array
 */
function dbFetchAll(string $sql, array $params = []): array
{
    return dbQuery($sql, $params)->fetchAll();
}

/**
 * Execute a query and return a single row
 * 
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @return array|null
 */
function dbFetchOne(string $sql, array $params = []): ?array
{
    $result = dbQuery($sql, $params)->fetch();
    return $result ?: null;
}

/**
 * Execute a query and return a single value
 * 
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters to bind
 * @return mixed
 */
function dbFetchValue(string $sql, array $params = [])
{
    return dbQuery($sql, $params)->fetchColumn();
}

/**
 * Execute an INSERT and return the last insert ID
 * 
 * @param string $sql SQL INSERT query
 * @param array $params Parameters to bind
 * @return int|string Last insert ID
 */
function dbInsert(string $sql, array $params = [])
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $pdo->lastInsertId();
}

/**
 * Execute an UPDATE/DELETE and return affected row count
 * 
 * @param string $sql SQL query
 * @param array $params Parameters to bind
 * @return int Number of affected rows
 */
function dbExecute(string $sql, array $params = []): int
{
    return dbQuery($sql, $params)->rowCount();
}