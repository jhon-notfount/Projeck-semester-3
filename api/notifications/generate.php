<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $stmtReading = $pdo->query("SELECT * FROM sensor_readings ORDER BY recorded_at DESC LIMIT 1");
    $reading = $stmtReading->fetch(PDO::FETCH_ASSOC);
    
    if (!$reading) {
        successResponse([], 'No readings available');
        exit;
    }
    
    $stmtSettings = $pdo->query("SELECT type, config_json FROM settings WHERE type IN ('ppm', 'ph')");
    $settings = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $ppmConfig = isset($settings['ppm']) ? json_decode($settings['ppm'], true) : ['min' => 800, 'max' => 1000];
    $phConfig = isset($settings['ph']) ? json_decode($settings['ph'], true) : ['min' => "5.5", 'max' => "6.5"];
    
    $ppmMin = (float)$ppmConfig['min'];
    $ppmMax = (float)$ppmConfig['max'];
    
    $phMin = (float)str_replace(',', '.', $phConfig['min']);
    $phMax = (float)str_replace(',', '.', $phConfig['max']);
    
    $phValue = (float)$reading['ph_value'];
    $ppmValue = (float)$reading['ppm_value'];
    
    $generated = [];
    
    $insertNotification = function($label, $message, $tone) use ($pdo, &$generated) {
        $stmt = $pdo->prepare("INSERT INTO notifications (label, message, tone, href, is_read) VALUES (?, ?, ?, '#', 0)");
        $stmt->execute([$label, $message, $tone]);
        $generated[] = ['label' => $label, 'message' => $message, 'tone' => $tone];
    };
    
    $isNormal = true;
    
    if ($phValue < $phMin || $phValue > $phMax) {
        $insertNotification('PH Alert', "pH level ({$phValue}) is out of range ({$phMin}-{$phMax})", 'warning');
        $isNormal = false;
    }
    
    if ($ppmValue < $ppmMin || $ppmValue > $ppmMax) {
        $insertNotification('PPM Alert', "PPM level ({$ppmValue}) is out of range ({$ppmMin}-{$ppmMax})", 'warning');
        $isNormal = false;
    }
    
    if ($isNormal) {
        $insertNotification('System Normal', "Sensor readings are within normal ranges", 'success');
    }
    
    successResponse($generated, 'Notifications generated');
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
