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
       untuk penerima tertentu. LEFT JOIN menghadirkan detail kejadian
       sumbernya; baris log sumber bisa NULL (notifikasi umum).          */
    $stmt = $pdo->prepare("SELECT `n`.`notification_id` AS `id`, `n`.`label`, `n`.`message`, `n`.`tone`, `n`.`href`, `n`.`monitoring_id`, `n`.`is_read`, `n`.`created_at`,
                                  `l`.`type` AS `event_type`, `l`.`status` AS `event_status`, `l`.`note` AS `event_note`, `l`.`day_name` AS `event_day`, `l`.`log_time` AS `event_time`, `l`.`created_at` AS `event_at`
                           FROM notifications `n`
                           LEFT JOIN monitoring_logs `l` ON `l`.`monitoring_id` = `n`.`monitoring_id`
                           WHERE `n`.`user_id` IS NULL OR `n`.`user_id` = ?
                           ORDER BY `n`.`created_at` DESC LIMIT 20");
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
