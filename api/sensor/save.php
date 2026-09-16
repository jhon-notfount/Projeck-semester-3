<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/sensors.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);
try {
    $readings = saveSensorMeasurements(getDBConnection(), $_POST);
    successResponse(['readings' => $readings, 'reading_ids' => array_column($readings, 'id')], 'Data sensor tersimpan');
} catch (InvalidArgumentException $e) {
    errorResponse($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('Sensor save: ' . $e->getMessage());
    errorResponse('Data sensor gagal disimpan.', 500);
}
