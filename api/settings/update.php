<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/dithane.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $type = $_POST['type'] ?? '';
    $config = $_POST['config'] ?? '';
    
    if (!in_array($type, ['ppm', 'ph', 'dithane'])) {
        errorResponse('Invalid type', 400);
    }
    
    $configJson = is_array($config) ? json_encode($config) : $config;
    
    if (json_decode($configJson) === null && json_last_error() !== JSON_ERROR_NONE) {
         errorResponse('Invalid config format', 400);
    }

    if ($type === 'dithane') {
        $data = json_decode($configJson, true);
        if (!is_array($data)) throw new InvalidArgumentException('Konfigurasi jadwal harus berupa objek.');
        writeDithane($pdo, $data, (int)$_SESSION['user_id']);
        successResponse(null, 'Jadwal Dithane tersimpan');
    }
    $stmt = $pdo->prepare("UPDATE settings SET konfigurasi_json = ?, diubah_oleh_id = ?, diperbarui_pada = CURRENT_TIMESTAMP WHERE jenis = ?");
    $stmt->execute([$configJson, $_SESSION['user_id'], $type]);
    
    successResponse(null, 'Setting updated successfully');
} catch (InvalidArgumentException $e) {
    errorResponse($e->getMessage(), 400);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
