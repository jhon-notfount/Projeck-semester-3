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
    /* LEFT JOIN users: updated_by bisa NULL (data sebelum relasi); nama
       pengubah tampil bila ada.                                          */
    $stmt = $pdo->prepare("SELECT `s`.`type`, `s`.`config_json`, `s`.`updated_at`, `s`.`updated_by`, `u`.`username` AS `updated_by_name`
                           FROM settings `s`
                           LEFT JOIN users `u` ON `u`.`user_id` = `s`.`updated_by`
                           WHERE `s`.`type` = ? LIMIT 1");
    $stmt->execute([$type]);
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($setting) {
        $result = [
            'type' => $setting['type'],
            'config' => json_decode($setting['config_json'], true),
            'updated_at' => $setting['updated_at'],
            'updated_by' => $setting['updated_by'],
            'updated_by_name' => $setting['updated_by_name']
        ];
        successResponse($result);
    } else {
        errorResponse('Setting not found', 404);
    }
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
