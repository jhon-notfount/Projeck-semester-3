<?php
/**
 * Hydrotech - Device Activity Report Endpoint  (api/device/report.php)
 *
 * Endpoint laporan eksekusi pompa dari perangkat ESP (tanpa login session,
 * autentikasi sama seperti api/sensor/ingest.php: header X-API-Key, field
 * body api_key, atau query ?api_key=).
 *
 * POST { "pump": "dithane", "state": "started", "duration_s": 0,
 *        "device_seen": "YYYY-MM-DD HH:MM:SS" }   (semua kecuali pump/state opsional)
 *
 * Aturan pencatatan aktivitas (lihat includes/device_report.php):
 *  - "started"   : satu catatan monitoring_logs (Data Aktivitas Sistem,
 *                  status "Menyala") saat pompa mulai menyala + notifikasi web.
 *  - "completed" : notifikasi web saja, TANPA catatan kedua.
 *  - Dedup laporan ulang < 60 detik diterapkan server; firmware TIDAK
 *    me-retry POST laporan.
 *
 * Respons "starts" mengembalikan last_completed_at (waktu server) agar
 * firmware dapat memakainya sebagai anchor interval Dithane pasca-restart.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/esp.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/device_report.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        errorResponse('Method not allowed', 405);
    }
    requireEspKey([]);

    $input = [];
    $raw = file_get_contents('php://input');
    if (str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        try { $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { errorResponse('JSON tidak valid.', 400); }
    } else if ($raw) {
        parse_str($raw, $input);
    }
    if (!is_array($input)) $input = [];

    $pump     = is_string($input['pump'] ?? null) ? trim($input['pump']) : '';
    $state    = is_string($input['state'] ?? null) ? trim($input['state']) : '';
    $duration = filter_var($input['duration_s'] ?? 0, FILTER_VALIDATE_INT);
    $duration = $duration === false ? 0 : $duration;
    /* Waktu perangkat hanya pelengkap dedup, bukan keputusan pencatatan.  */
    $seen     = preg_replace('/[^0-9\-\s:]/', '',
                substr(is_string($input['device_seen'] ?? null) ? trim($input['device_seen']) : '', 0, 32));

    $result = recordDithaneActivity(getDBConnection(), $pump, $state, $duration, $seen);
    successResponse($result, 'Laporan perangkat diproses');
} catch (InvalidArgumentException $e) {
    errorResponse($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('Device report: ' . $e->getMessage());
    errorResponse('Gagal mencatat laporan perangkat.', 500);
}
