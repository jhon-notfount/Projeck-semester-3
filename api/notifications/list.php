<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    $userId = $_SESSION['user_id'];

    /* user_id NULL = notifikasi sistem (broadcast); selain itu hanya
       untuk penerima tertentu.                                             */
    $stmt = $pdo->prepare("SELECT `notification_id` AS `id`, `label`, `message`, `tone`, `href`, `monitoring_id`, `is_read`, `created_at`
                           FROM notifications WHERE user_id IS NULL OR user_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND (user_id IS NULL OR user_id = ?)");
    $unreadStmt->execute([$userId]);
    $unreadCount = $unreadStmt->fetchColumn();
    $all_read = $unreadCount == 0;

    successResponse(['notifications' => $notifications, 'all_read' => $all_read]);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
