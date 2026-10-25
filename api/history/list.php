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
    
    $query = "SELECT `monitoring_id` AS `id`, `type`, `day_name`, `log_time`, `value`, `status`, `note`, `is_deleted`, DATE(`created_at`) AS `log_date`, `created_at` FROM monitoring_logs WHERE is_deleted = 0";
    $params = [];
    
    if (!empty($search)) {
        $query .= " AND (day_name LIKE ? OR note LIKE ? OR type LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    if (!empty($type)) {
        $query .= " AND type = ?";
        $params[] = $type;
    }
    if (!empty($day)) {
        $query .= " AND day_name = ?";
        $params[] = $day;
    }
    if (!empty($month)) {
        $query .= " AND MONTH(created_at) = ?";
        $params[] = $month;
    }
    if (!empty($status)) {
        $query .= " AND status = ?";
        $params[] = $status;
    }
    
    $query .= " ORDER BY created_at DESC, monitoring_id DESC LIMIT 500";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    successResponse($logs);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
