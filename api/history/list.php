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
    
    /* LEFT JOIN: baris log tetap tampil walau tanpa pembacaan sensor
       (manual/Dithane) atau tanpa jadwal pemicu; nilai pasangan NULL.   */
    $query = "SELECT `l`.`monitoring_id` AS `id`, `l`.`type`, `l`.`day_name`, `l`.`log_time`, `l`.`value`, `l`.`status`, `l`.`note`, `l`.`is_deleted`, DATE(`l`.`created_at`) AS `log_date`, `l`.`created_at`, `l`.`reading_id`, `l`.`schedule_id`,
                     `r`.`ph_value`, `r`.`ppm_value`, `r`.`recorded_at` AS `reading_recorded_at`,
                     `d`.`interval_hours`, `d`.`duration_seconds`, `d`.`start_time`
              FROM monitoring_logs `l`
              LEFT JOIN sensor_readings `r` ON `r`.`reading_id` = `l`.`reading_id`
              LEFT JOIN dithane_schedules `d` ON `d`.`schedule_id` = `l`.`schedule_id`
              WHERE `l`.`is_deleted` = 0";
    $params = [];
    
    if (!empty($search)) {
        $query .= " AND (`l`.`day_name` LIKE ? OR `l`.`note` LIKE ? OR `l`.`type` LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    if (!empty($type)) {
        $query .= " AND `l`.`type` = ?";
        $params[] = $type;
    }
    if (!empty($day)) {
        $query .= " AND `l`.`day_name` = ?";
        $params[] = $day;
    }
    if (!empty($month)) {
        $query .= " AND MONTH(`l`.`created_at`) = ?";
        $params[] = $month;
    }
    if (!empty($status)) {
        $query .= " AND `l`.`status` = ?";
        $params[] = $status;
    }

    $query .= " ORDER BY `l`.`created_at` DESC, `l`.`monitoring_id` DESC LIMIT 500";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    successResponse($logs);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
