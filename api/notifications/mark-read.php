<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("UPDATE notifications SET sudah_dibaca = 1 WHERE sudah_dibaca = 0");
    $stmt->execute();
    
    successResponse(null, 'All notifications marked as read');
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
