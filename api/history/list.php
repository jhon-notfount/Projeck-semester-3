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
    
    $query = "SELECT * FROM history_logs WHERE is_deleted = 0";
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
        $query .= " AND MONTH(log_date) = ?";
        $params[] = $month;
    }
    if (!empty($status)) {
        $query .= " AND status = ?";
        $params[] = $status;
    }
    
    $query .= " ORDER BY log_date DESC, log_time DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    successResponse($logs);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
