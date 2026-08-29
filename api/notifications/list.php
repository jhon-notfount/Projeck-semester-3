<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 20");
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $unreadStmt = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0");
    $unreadCount = $unreadStmt->fetchColumn();
    $all_read = $unreadCount == 0;
    
    successResponse(['notifications' => $notifications, 'all_read' => $all_read]);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
