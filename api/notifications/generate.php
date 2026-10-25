<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/sensors.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);
requireCsrfToken();
try {
    $pdo = getDBConnection();
    $lock = measurementLock($pdo);
    $pdo->beginTransaction();
    $limits = sensorLimits($pdo);
    $generated = [];
    foreach (latestSensorReadings($pdo) as $reading) {
        // dedup=true: pemanggilan berulang tidak menciptakan notifikasi ganda
        // untuk kejadian (pembacaan) yang sudah pernah diberi notifikasi.
        $generated[] = sensorWarning($pdo, $reading, $limits, true, true);
    }
    $pdo->commit();
    measurementUnlock($pdo, $lock);
    successResponse($generated, 'Notifikasi sensor diperbarui');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (isset($lock)) measurementUnlock($pdo, $lock);
    errorResponse('Tidak dapat membuat notifikasi sensor.', 500);
}
