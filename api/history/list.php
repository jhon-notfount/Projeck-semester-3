<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $search = $_GET['search'] ?? '';
    $type = $_GET['type'] ?? '';
    $day = $_GET['day'] ?? '';
    $month = $_GET['month'] ?? '';
    $status = $_GET['status'] ?? '';
    
    $query = "SELECT `id_riwayat` AS `id`, `nama_hari` AS `day_name`, `tanggal_catatan` AS `log_date`, `jam_catatan` AS `log_time`, `jenis` AS `type`, `status`, `keterangan` AS `note`, `dihapus` AS `is_deleted`, `dibuat_pada` AS `created_at` FROM history_logs WHERE dihapus = 0";
    $params = [];
    
    if (!empty($search)) {
        $query .= " AND (nama_hari LIKE ? OR keterangan LIKE ? OR jenis LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    if (!empty($type)) {
        $query .= " AND jenis = ?";
        $params[] = $type;
    }
    if (!empty($day)) {
        $query .= " AND nama_hari = ?";
        $params[] = $day;
    }
    if (!empty($month)) {
        $query .= " AND MONTH(tanggal_catatan) = ?";
        $params[] = $month;
    }
    if (!empty($status)) {
        $query .= " AND status = ?";
        $params[] = $status;
    }
    
    $query .= " ORDER BY tanggal_catatan DESC, jam_catatan DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    successResponse($logs);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
