<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $id = $_POST['id'] ?? '';
    if (empty($id)) {
        errorResponse('ID is required', 400);
    }
    
    $stmt = $pdo->prepare("UPDATE monitoring_logs SET is_deleted = 1 WHERE id = ?");
    $stmt->execute([$id]);
    
    successResponse(null, 'Log deleted successfully');
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
