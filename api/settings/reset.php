<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $type = $_POST['type'] ?? '';
    if (!in_array($type, ['ppm', 'ph', 'dithane'])) {
        errorResponse('Invalid type', 400);
    }
    
    $defaultConfig = '';
    if ($type === 'ppm') {
        $defaultConfig = json_encode(["min" => "800", "max" => "1000"]);
    } elseif ($type === 'ph') {
        $defaultConfig = json_encode(["min" => "5,5", "max" => "6,5"]);
    } elseif ($type === 'dithane') {
        $defaultConfig = json_encode(["interval" => "48", "duration" => "10", "start" => "08.00"]);
    }
    
    $stmt = $pdo->prepare("UPDATE settings SET config_json = ?, updated_at = CURRENT_TIMESTAMP WHERE type = ?");
    $stmt->execute([$defaultConfig, $type]);
    
    successResponse(null, 'Setting reset to default successfully');
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
