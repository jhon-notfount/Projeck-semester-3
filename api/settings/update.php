<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $type = $_POST['type'] ?? '';
    $config = $_POST['config'] ?? '';
    
    if (!in_array($type, ['ppm', 'ph', 'dithane'])) {
        errorResponse('Invalid type', 400);
    }
    
    $configJson = is_array($config) ? json_encode($config) : $config;
    
    if (json_decode($configJson) === null && json_last_error() !== JSON_ERROR_NONE) {
         errorResponse('Invalid config format', 400);
    }

    $stmt = $pdo->prepare("UPDATE settings SET config_json = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE type = ?");
    $stmt->execute([$configJson, $_SESSION['user_id'], $type]);
    
    successResponse(null, 'Setting updated successfully');
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
