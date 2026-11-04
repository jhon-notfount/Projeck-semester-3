<?php
/**
 * Hydrotech - ESP Ingest Endpoint
 *
 * Endpoint khusus perangkat mikrokontroler (ESP32/ESP866) tanpa login session.
 * Autentikasi memakai API key (config/esp.php): header X-API-Key, field body
 * api_key, atau query ?api_key=.
 *
 * POST  : simpan pengukuran { "ph_value": 6.5, "ppm_value": 900 } (keduanya opsional,
 *         minimal satu).
 * GET   : ambil konfigurasi aktif (ambang pH/PPM + jadwal Dithane) untuk perangkat.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/esp.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/sensors.php';
require_once __DIR__ . '/../../includes/dithane.php';

function espReadInput(): array {
    if (str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        try { $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new InvalidArgumentException('JSON tidak valid.'); }
        return is_array($input) ? $input : [];
    }
    return $_POST;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        requireEspKey($_GET);
        $pdo = getDBConnection();
        $settings = $pdo->query("SELECT type, config_json FROM settings WHERE type IN ('ph','ppm')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $config = [];
        foreach (['ph', 'ppm'] as $type) {
            $config[$type] = json_decode($settings[$type] ?? 'null', true);
        }
        $schedule = readDithane($pdo);
        if ($schedule) {
            $config['dithane'] = $schedule['config'];
            /* Anchor interval "sejak penyemprotan SELESAI terakhir": catatan
               aktivitas terakhir (pompa mulai menyala) + durasi terjadwal.
               Dipakai firmware agar interval tetap akurat pasca-restart/NVS
               hilang. Waktu catatan memakai waktu server (WIB).           */
            $lastDone = $pdo->query(
                "SELECT DATE_ADD(l.created_at, INTERVAL COALESCE(d.duration_seconds, 0) SECOND)
                   FROM monitoring_logs l
                   LEFT JOIN dithane_schedules d ON d.schedule_id = 1
                  WHERE l.type = 'dithane' AND l.is_deleted = 0
                  ORDER BY l.monitoring_id DESC LIMIT 1"
            )->fetchColumn();
            if ($lastDone) $config['dithane']['last_completed_at'] = substr((string)$lastDone, 0, 19);
        }
        successResponse(['config' => $config], 'Konfigurasi perangkat');
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = espReadInput();
        requireEspKey($input);
        $readings = saveSensorMeasurements(getDBConnection(), $input);
        successResponse([
            'readings' => $readings,
            'reading_ids' => array_column($readings, 'id'),
        ], 'Data sensor tersimpan');
    } else {
        errorResponse('Method not allowed', 405);
    }
} catch (InvalidArgumentException $e) {
    errorResponse($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('ESP ingest: ' . $e->getMessage());
    errorResponse('Data sensor gagal diproses.', 500);
}
