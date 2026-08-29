<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $phValue = $_POST['ph_value'] ?? null;
    $ppmValue = $_POST['ppm_value'] ?? null;
    
    if ($phValue === null || $ppmValue === null || !is_numeric($phValue) || !is_numeric($ppmValue)) {
        errorResponse('Valid ph_value and ppm_value are required', 400);
    }
    
    $stmtInsert = $pdo->prepare("INSERT INTO sensor_readings (ph_value, ppm_value) VALUES (?, ?)");
    $stmtInsert->execute([$phValue, $ppmValue]);
    $readingId = $pdo->lastInsertId();
    
    $stmtSettings = $pdo->query("SELECT type, config_json FROM settings WHERE type IN ('ppm', 'ph')");
    $settings = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $ppmConfig = isset($settings['ppm']) ? json_decode($settings['ppm'], true) : ['min' => 800, 'max' => 1000];
    $phConfig = isset($settings['ph']) ? json_decode($settings['ph'], true) : ['min' => "5.5", 'max' => "6.5"];
    
    $ppmMin = (float)$ppmConfig['min'];
    $ppmMax = (float)$ppmConfig['max'];
    
    $phMin = (float)str_replace(',', '.', $phConfig['min']);
    $phMax = (float)str_replace(',', '.', $phConfig['max']);
    
    $insertNotification = function($label, $message, $tone) use ($pdo) {
        $stmt = $pdo->prepare("INSERT INTO notifications (label, message, tone, href, is_read) VALUES (?, ?, ?, '#', 0)");
        $stmt->execute([$label, $message, $tone]);
    };
    
    if ((float)$phValue < $phMin || (float)$phValue > $phMax) {
        $insertNotification('PH Alert', "pH level ({$phValue}) is out of range ({$phMin}-{$phMax})", 'warning');
    }
    
    if ((float)$ppmValue < $ppmMin || (float)$ppmValue > $ppmMax) {
        $insertNotification('PPM Alert', "PPM level ({$ppmValue}) is out of range ({$ppmMin}-{$ppmMax})", 'warning');
    }
    
    successResponse(['reading_id' => $readingId], 'Sensor data saved');
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
