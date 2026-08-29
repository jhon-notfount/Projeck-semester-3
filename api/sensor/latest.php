<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->query("SELECT * FROM sensor_readings ORDER BY recorded_at DESC LIMIT 10");
    $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    successResponse($readings);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
