<?php
/**
 * Hydrotech - Device Status Endpoint
 *
 * Sumber status perangkat ESP32 adalah heartbeat aktual dari data sensor
 * yang dikirim perangkat ke api/sensor/ingest.php dan tersimpan di tabel
 * sensor_readings (kolom recorded_at memakai CURRENT_TIMESTAMP server).
 *
 * GET api/device/status.php  (butuh sesi login admin)
 *
 * Response:
 *   status                    : online | offline | never_connected
 *   last_seen_at              : waktu kirim data terakhir (WIB / waktu server)
 *   seconds_since_last_seen   : selisih detik sejak data terakhir
 *   threshold_seconds         : batas detik perangkat dianggap online
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

/* DEVICE HEARTBEAT THRESHOLD: perangkat dianggap online selama data terakhir
   berumur <= 300 detik (5 menit); melebihi itu berarti koneksi terputus. */
const DEVICE_ONLINE_THRESHOLD_SECONDS = 300;

try {
    $pdo = getDBConnection();

    /* Ambil waktu kirim data terakhir dan selisihnya dihitung di server (SQL)
       agar tidak terpengaruh perbedaan jam browser/website. */
    $stmt = $pdo->query(
        "SELECT MAX(recorded_at) AS last_seen_at,
                TIMESTAMPDIFF(SECOND, MAX(recorded_at), CURRENT_TIMESTAMP) AS seconds_since
         FROM sensor_readings"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $lastSeenAt = $row['last_seen_at'] ?? null;
    $secondsSince = $row['seconds_since'] ?? null;

    if ($lastSeenAt === null) {
        // Belum pernah ada data masuk dari perangkat.
        $status = 'never_connected';
        $secondsSince = null;
    } elseif ((int)$secondsSince <= DEVICE_ONLINE_THRESHOLD_SECONDS) {
        $status = 'online';
    } else {
        // Sebelumnya pernah terhubung, sekarang tidak ada data lagi.
        $status = 'offline';
    }

    successResponse([
        'status' => $status,
        'last_seen_at' => $lastSeenAt,
        'seconds_since_last_seen' => $secondsSince === null ? null : (int)$secondsSince,
        'threshold_seconds' => DEVICE_ONLINE_THRESHOLD_SECONDS,
    ], 'Status perangkat');
} catch (Exception $e) {
    error_log('Device status: ' . $e->getMessage());
    errorResponse('Server error: gagal membaca status perangkat.', 500);
}
