<?php
/**
 * Hydrotech - Pump Status Endpoint (status RELAY sebenarnya)
 *
 * Sumber: runtime/pump_status.json — ditulis worker MQTT setiap kali
 * perangkat menyiarkan hydrotech/pump/status (telemetri asli perangkat).
 * Tombol dashboard membaca ini agar ON/OFF selalu sesuai kondisi relay.
 *
 * GET (login): data = { pumps: { ab: {state, mode, remaining_s, updated_at}, ... },
 *                       device_online: bool }
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') errorResponse('Method not allowed', 405);

const STATUS_FILE = __DIR__ . '/../../runtime/pump_status.json';
const DEVICE_STALE_SECONDS = 15;

try {
    $all = is_readable(STATUS_FILE)
        ? (json_decode((string)file_get_contents(STATUS_FILE), true) ?: [])
        : [];

    $pumps = [];
    $newest = 0;
    foreach (['ab', 'ph', 'dithane', 'circ'] as $pump) {
        $info = $all[$pump] ?? [];
        $ts = strtotime((string)($info['updated_at'] ?? '')) ?: 0;
        $newest = max($newest, $ts);
        $pumps[$pump] = [
            'state'        => ($info['state'] ?? 'off') === 'on' ? 'on' : 'off',
            'mode'         => ($info['mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto',
            'remaining_s'  => (int)($info['remaining_s'] ?? 0),
            'updated_at'   => $info['updated_at'] ?? null,
        ];
    }

    /* PRIORITAS: slot berikutnya versi PERANGKAT (next_due dari telemetri,
       selalu persis dgn jadwal yang dijalankan ESP32).                  */
    $nextSpray = null;
    $deviceNext = (int)($all['device']['next_due'] ?? 0);
    if ($deviceNext > 0 && (time() - strtotime((string)($all['device']['updated_at'] ?? ''))) <= 3600) {
        $nextSpray = $deviceNext;
    }

    /* Fallback (telemetri belum tersedia): dihitung dari database —
       dua kasus (sama dgn firmware):
       a) jadwal BARU disimpan setelah penyemprotan terakhir
          (dithane_schedules.updated_at > selesai terakhir):
          target = JAM MULAI yang baru diatur (hitungan mundur sesuai
          ekspektasi operator saat mengubah jadwal);
       b) jadwal tidak berubah: target = selesai terakhir + interval.    */
    $pdo = getDBConnection();
    $nextSpray = null;
    try {
        $row = $pdo->query(
            "SELECT DATE_ADD(l.created_at, INTERVAL COALESCE(d.duration_seconds,0) SECOND) AS last_done,
                    d.interval_hours, d.start_time, d.updated_at AS sched_updated
               FROM monitoring_logs l
               LEFT JOIN dithane_schedules d ON d.schedule_id = 1
              WHERE l.type = 'dithane' AND l.is_deleted = 0
              ORDER BY l.monitoring_id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $sched = $pdo->query(
            "SELECT interval_hours, start_time, updated_at FROM dithane_schedules WHERE schedule_id = 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($sched && (int)$sched['interval_hours'] > 0) {
            $startHM = substr((string)$sched['start_time'], 0, 5);
            $schedUpdated = strtotime((string)$sched['updated_at']);
            $lastDone = $row && $row['last_done'] ? strtotime((string)$row['last_done']) : 0;
            if ($lastDone > 0 && $schedUpdated > $lastDone) {
                /* (a) jadwal baru: slot berikutnya = jam mulai yang baru. */
                $ts = strtotime(date('Y-m-d ') . $startHM);
                if ($ts <= time() - 300) $ts += 86400;          // slot lampau -> besok
            } else {
                /* (b) berantai dari penyemprotan selesai terakhir.       */
                $ts = ($lastDone > 0 ? $lastDone : time()) + ((int)$sched['interval_hours'] * 3600);
                while ($ts <= time()) $ts += ((int)$sched['interval_hours'] * 3600);
            }
            $nextSpray = $ts;
        }
    } catch (Throwable $ignore) { /* jadwal belum ada */ }

    successResponse([
        'pumps'            => $pumps,
        'device_online'    => $newest > 0 && (time() - $newest) <= DEVICE_STALE_SECONDS,
        'next_spray_epoch' => $nextSpray,
    ], 'Status pompa');
} catch (Throwable $e) {
    error_log('Pump status: ' . $e->getMessage());
    errorResponse('Gagal membaca status pompa.', 500);
}
