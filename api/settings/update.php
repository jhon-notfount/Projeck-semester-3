<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/dithane.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }
requireCsrfToken();

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
        writeDithane($pdo, $data, $_SESSION['user_id']);
        successResponse(null, 'Jadwal Dithane tersimpan');
    }

    // Validasi ambang ppm/ph SEBELUM menyimpan: config invalid tidak boleh
    // masuk database karena akan merusak proses ingest sensor.
    require_once __DIR__ . '/../../includes/sensors.php';
    $data = json_decode($configJson, true);
    if (!is_array($data)) throw new InvalidArgumentException('Konfigurasi harus berupa objek JSON dengan kunci min dan max.');
    validateSettingConfig($type, $data);

    $stmt = $pdo->prepare("UPDATE settings SET config_json = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE type = ?");
    $stmt->execute([$configJson, $_SESSION['user_id'], $type]);
    
    successResponse(null, 'Setting updated successfully');
} catch (InvalidArgumentException $e) {
    errorResponse($e->getMessage(), 400);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
