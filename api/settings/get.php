<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/dithane.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $type = $_GET['type'] ?? '';
    if (empty($type)) {
        errorResponse('Type is required', 400);
    }
    
    if ($type === 'dithane') {
        $schedule = readDithane($pdo);
        if (!$schedule) errorResponse('Jadwal belum tersedia.', 404);
        successResponse($schedule);
    }
    $stmt = $pdo->prepare("SELECT `id_pengaturan` AS `id`, `jenis` AS `type`, `konfigurasi_json` AS `config_json`, `diperbarui_pada` AS `updated_at`, `diubah_oleh_id` AS `updated_by` FROM settings WHERE jenis = ? LIMIT 1");
    $stmt->execute([$type]);
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($setting) {
        $result = [
            'type' => $setting['type'],
            'config' => json_decode($setting['config_json'], true),
            'updated_at' => $setting['updated_at']
        ];
        successResponse($result);
    } else {
        errorResponse('Setting not found', 404);
    }
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
