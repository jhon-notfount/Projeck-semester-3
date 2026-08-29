<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $stmtSettings = $pdo->query("SELECT type, config_json FROM settings");
    $settings = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $parsedSettings = [];
    foreach ($settings as $type => $config) {
        $parsedSettings[$type] = json_decode($config, true);
    }
    
    $stmtReading = $pdo->query("SELECT * FROM sensor_readings ORDER BY recorded_at DESC LIMIT 1");
    $latestReading = $stmtReading->fetch(PDO::FETCH_ASSOC);
    
    $stmtUnread = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0");
    $unreadNotifications = (int)$stmtUnread->fetchColumn();
    
    $result = [
        'settings' => $parsedSettings,
        'latest_reading' => $latestReading,
        'unread_notifications' => $unreadNotifications
    ];
    
    successResponse($result);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
