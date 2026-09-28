<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') errorResponse('Method not allowed', 405);
try {
    successResponse([['type'=>'ph', 'field'=>'ph_value', 'unit'=>null], ['type'=>'ppm', 'field'=>'ppm_value', 'unit'=>'ppm']]);
} catch (Throwable $e) {
    errorResponse('Tidak dapat membaca daftar sensor.', 500);
}
