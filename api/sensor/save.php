<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/sensors.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);
try {
    $input = $_POST;
    if (str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        try { $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new InvalidArgumentException('JSON tidak valid.'); }
        if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Kirim objek JSON.');
    }
    $readings = saveSensorMeasurements(getDBConnection(), $input);
    successResponse(['readings' => $readings, 'reading_ids' => array_column($readings, 'id')], 'Data sensor tersimpan');
} catch (InvalidArgumentException $e) {
    errorResponse($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('Sensor save: ' . $e->getMessage());
    errorResponse('Data sensor gagal disimpan.', 500);
}
