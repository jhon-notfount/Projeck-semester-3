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
    
    /* LEFT JOIN: reading_id/schedule_id boleh NULL (log manual/Dithane);
       pasangan pembacaan sensor ikut disertakan tanpa membuang baris log. */
    $query = "SELECT `l`.`monitoring_id` AS `id`, `l`.`type`, `l`.`day_name`, `l`.`log_time`, `l`.`value`, `l`.`status`, `l`.`note`, `l`.`reading_id`, `l`.`schedule_id`, `l`.`is_deleted`, `l`.`created_at`,
                     `r`.`ph_value`, `r`.`ppm_value`, `r`.`recorded_at` AS `reading_recorded_at`,
                     `d`.`interval_hours`, `d`.`duration_seconds`, `d`.`start_time`
              FROM monitoring_logs `l`
              LEFT JOIN sensor_readings `r` ON `r`.`reading_id` = `l`.`reading_id`
              LEFT JOIN dithane_schedules `d` ON `d`.`schedule_id` = `l`.`schedule_id`
              WHERE `l`.`type` = ? AND `l`.`is_deleted` = 0";
    $params = [$type];
    
    if (!empty($status)) {
        $query .= " AND status = ?";
        $params[] = $status;
    }
    
    $query .= " ORDER BY `l`.`created_at` DESC, `l`.`monitoring_id` DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    successResponse($logs);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
