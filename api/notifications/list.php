<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->query("SELECT `id_notifikasi` AS `id`, `label`, `pesan` AS `message`, `tingkat_pesan` AS `tone`, `tautan` AS `href`, `sudah_dibaca` AS `is_read`, `dibuat_pada` AS `created_at`, `id_pembacaan_ph` AS `ph_reading_id`, `id_pembacaan_ppm` AS `ppm_reading_id` FROM notifications ORDER BY dibuat_pada DESC LIMIT 20");
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $unreadStmt = $pdo->query("SELECT COUNT(*) FROM notifications WHERE sudah_dibaca = 0");
    $unreadCount = $unreadStmt->fetchColumn();
    $all_read = $unreadCount == 0;
    
    successResponse(['notifications' => $notifications, 'all_read' => $all_read]);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
