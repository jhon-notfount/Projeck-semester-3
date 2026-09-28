<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/sensors.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') errorResponse('Method not allowed', 405);
try {
    $pdo = getDBConnection();
    if (isset($_GET['sensor_id'])) errorResponse('Gunakan filter type=ph atau type=ppm.', 400);
    $type = $_GET['type'] ?? null;
    if ($type !== null && !in_array($type, ['ph','ppm'], true)) errorResponse('Jenis pengukuran tidak valid.', 400);
    $parts = [];
    foreach ($type === null ? ['ph','ppm'] : [$type] as $kind) {
        $parts[] = "(SELECT id, '$kind' AS type, {$kind}_value AS value, recorded_at FROM {$kind}_readings ORDER BY recorded_at DESC, id DESC LIMIT 10)";
    }
    $stmt = $pdo->query(implode(' UNION ALL ', $parts) . ' ORDER BY recorded_at DESC, id DESC, type LIMIT 10');
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) $row['value'] = $row['type'] === 'ppm' ? (int)$row['value'] : (float)$row['value'];
    unset($row);
    successResponse($rows);
} catch (Throwable $e) {
    errorResponse('Tidak dapat membaca hasil sensor.', 500);
}
