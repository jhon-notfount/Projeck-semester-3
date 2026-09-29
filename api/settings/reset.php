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
    if (!in_array($type, ['ppm', 'ph', 'dithane'])) {
        errorResponse('Invalid type', 400);
    }
    
    $defaultConfig = '';
    if ($type === 'ppm') {
        $defaultConfig = json_encode(["min" => "800", "max" => "1000"]);
    } elseif ($type === 'ph') {
        $defaultConfig = json_encode(["min" => "5,5", "max" => "6,5"]);
    } elseif ($type === 'dithane') {
        $defaultConfig = json_encode(["interval" => "48", "duration" => "10", "start" => "08.00"]);
    }
    
    if ($type === 'dithane') {
        $data = json_decode($defaultConfig, true);
        if (!is_array($data)) throw new InvalidArgumentException('Konfigurasi jadwal harus berupa objek.');
        writeDithane($pdo, $data, (int)$_SESSION['user_id']);
        successResponse(null, 'Jadwal Dithane tersimpan');
    }
    $stmt = $pdo->prepare("UPDATE settings SET konfigurasi_json = ?, diubah_oleh_id = ?, diperbarui_pada = CURRENT_TIMESTAMP WHERE jenis = ?");
    $stmt->execute([$defaultConfig, $_SESSION['user_id'], $type]);
    
    successResponse(null, 'Setting reset to default successfully');
} catch (InvalidArgumentException $e) {
    errorResponse($e->getMessage(), 400);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
