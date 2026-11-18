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
        /* Push MQTT DIJALANKAN SEBELUM successResponse (yang exit) agar
           perubahan jadwal Dithane langsung sampai ke perangkat.          */
        mqttPushConfig($type, $configJson);
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

    /* MQTT: dorong konfigurasi baru ke ESP32 (hydrotech/cmd/esp32/*, QoS 1,
       TANPA retained agar perangkat yang baru hidup tidak memakai nilai lama).
       Kegagalan broker TIDAK menggagalkan penyimpanan: perangkat tetap
       meminta config berkala lewat config_request (fallback).             */
    mqttPushConfig($type, $configJson);

    successResponse(null, 'Setting updated successfully');
} catch (InvalidArgumentException $e) {
    errorResponse($e->getMessage(), 400);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}

/** Publish konfigurasi tersimpan ke topik cmd perangkat (dipakai semua tipe). */
function mqttPushConfig(string $type, string $configJson): void {
    try {
        require_once __DIR__ . '/../../vendor/hydrotech/MqttClient.php';
        require_once __DIR__ . '/../../includes/mqtt.php';
        $msgId = $type . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $payload = array_merge(
            ['msg_id' => $msgId],
            json_decode($configJson, true) ?: []
        );
        $client = mqttWorkerClient();
        if ($client->connect(3)) {
            $client->publish("hydrotech/cmd/esp32/{$type}", json_encode($payload), 1, false);
            $wait = microtime(true) + 1;
            while (microtime(true) < $wait) $client->loop(100);
            $client->disconnect();
        }
    } catch (Throwable $mqttError) {
        error_log('MQTT push config: ' . $mqttError->getMessage());
    }
}
