<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $type = $_GET['type'] ?? '';
    $status = $_GET['status'] ?? '';
    
    if (empty($type)) {
        errorResponse('Type is required', 400);
    }
    
    $query = "SELECT `id_monitoring` AS `id`, `jenis` AS `type`, `nama_hari` AS `day_name`, `jam_catatan` AS `log_time`, `nilai` AS `value`, `status`, `keterangan` AS `note`, `dihapus` AS `is_deleted`, `dibuat_pada` AS `created_at`, `id_pembacaan_ph` AS `ph_reading_id`, `id_pembacaan_ppm` AS `ppm_reading_id` FROM monitoring_logs WHERE jenis = ? AND dihapus = 0";
    $params = [$type];
    
    if (!empty($status)) {
        $query .= " AND status = ?";
        $params[] = $status;
    }
    
    $query .= " ORDER BY dibuat_pada DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    successResponse($logs);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
