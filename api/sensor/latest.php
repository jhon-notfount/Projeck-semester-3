<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/sensors.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') errorResponse('Method not allowed', 405);
try {
    $pdo = getDBConnection();
    $where = [];
    $params = [];
    if (isset($_GET['sensor_id'])) {
        $id = filter_var($_GET['sensor_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) errorResponse('ID sensor tidak valid.', 400);
        $where[] = 'r.sensor_id = ?';
        $params[] = $id;
    }
    if (isset($_GET['type'])) {
        if (!in_array($_GET['type'], ['ph','ppm'], true)) errorResponse('Jenis sensor tidak valid.', 400);
        $where[] = 's.type = ?';
        $params[] = $_GET['type'];
    }
    $query = 'SELECT r.*, s.name AS sensor_name, s.type, s.unit, s.status AS sensor_status FROM sensor_readings r JOIN sensors s ON s.id = r.sensor_id';
    if ($where) $query .= ' WHERE ' . implode(' AND ', $where);
    $query .= ' ORDER BY r.recorded_at DESC, r.id DESC LIMIT 10';
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    successResponse($stmt->fetchAll());
} catch (Throwable $e) {
    errorResponse('Tidak dapat membaca hasil sensor.', 500);
}
