<?php
/**
 * Hydrotech - Database Configuration
 * 
 * Provides PDO connection to the monitoring_sensor database.
 * Uses singleton pattern to avoid multiple connections.
 */

$DB_HOST    = 'localhost';
$DB_USER    = 'root';
$DB_PASS    = '';
$DB_NAME    = 'monitoring_sensor';
$DB_CHARSET = 'utf8mb4';

/**
 * Get PDO database connection (singleton)
 * @return PDO
 */
function getDBConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_CHARSET;
        
        $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
        
        try {
            $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    
    return $pdo;
}
