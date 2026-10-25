<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/sensors.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') errorResponse('Method not allowed', 405);
try {
    $pdo = getDBConnection();
    $type = $_GET['type'] ?? null;
    if ($type !== null && !in_array($type, ['ph','ppm'], true)) errorResponse('Jenis pengukuran tidak valid.', 400);
    $readings = latestSensorReadings($pdo, 10);
    if ($type !== null) {
        $readings = array_values(array_filter($readings, fn($r) => $r['type'] === $type));
    }
    successResponse($readings);
} catch (Throwable $e) {
    errorResponse('Tidak dapat membaca hasil sensor.', 500);
}
