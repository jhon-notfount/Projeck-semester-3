<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/sensors.php';
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
    
    $latestReadings = latestSensorReadings($pdo);
    // Compatibility fields: newest available reading per parameter, with its own timestamp.
    $latestReading = null;
    foreach ($latestReadings as $reading) {
        if ($latestReading === null) $latestReading = [];
        $key = $reading['type'] . '_value';
        if (!array_key_exists($key, $latestReading)) {
            $latestReading[$key] = (float)$reading['value'];
            $latestReading[$reading['type'] . '_sensor_id'] = (int)$reading['sensor_id'];
            $latestReading[$reading['type'] . '_recorded_at'] = $reading['recorded_at'];
        }
    }
    
    $stmtUnread = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0");
    $unreadNotifications = (int)$stmtUnread->fetchColumn();
    
    $result = [
        'settings' => $parsedSettings,
        'latest_reading' => $latestReading,
        'latest_readings' => $latestReadings,
        'unread_notifications' => $unreadNotifications
    ];
    
    successResponse($result);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
