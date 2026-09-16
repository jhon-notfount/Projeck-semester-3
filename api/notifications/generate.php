<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/sensors.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);
try {
    $pdo = getDBConnection();
    $pdo->beginTransaction();
    $limits = sensorLimits($pdo);
    $generated = [];
    foreach (latestSensorReadings($pdo) as $reading) {
        $generated[] = sensorWarning($pdo, $reading, $limits, true);
    }
    $pdo->commit();
    successResponse($generated, 'Notifikasi sensor diperbarui');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    errorResponse('Tidak dapat membuat notifikasi sensor.', 500);
}
