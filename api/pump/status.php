<?php
/**
 * Hydrotech - Pump Status Endpoint (api/pump/status.php)
 *
 * Status pompa terkini yang dilaporkan perangkat (tabel pump_states,
 * diperbarui setiap siklus poll api/device/commands.php). Dipakai tombol
 * ON/OFF dashboard agar tampilan mencerminkan kondisi pompa sebenarnya.
 *
 * GET (login): data = { pumps: { ab: {state, mode, remaining_s, age_s}, ... },
 *                       device_online: bool }
 *   device_online: perangkat dianggap terhubung bila laporan status pompa
 *   terakhir berumur <= DEVICE_POLL_STALE_SECONDS (poll normal 3 detik).
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') errorResponse('Method not allowed', 405);

/* Poll normal 3 s; 15 s tanpa laporan = perangkat tidak terhubung. */
const DEVICE_POLL_STALE_SECONDS = 15;

try {
    $pdo = getDBConnection();
    $rows = $pdo->query(
        "SELECT pump, state, mode, remaining_s,
                TIMESTAMPDIFF(SECOND, updated_at, CURRENT_TIMESTAMP) AS age_s
           FROM pump_states"
    )->fetchAll(PDO::FETCH_ASSOC);

    $pumps = [];
    $maxAge = null;
    foreach ($rows as $row) {
        $age = $row['age_s'] === null ? null : (int)$row['age_s'];
        $pumps[$row['pump']] = [
            'state' => $row['state'],
            'mode' => $row['mode'],
            'remaining_s' => $row['remaining_s'] === null ? null : (int)$row['remaining_s'],
            'age_s' => $age,
        ];
        if ($age !== null) $maxAge = $maxAge === null ? $age : min($maxAge, $age);
    }
    successResponse([
        'pumps' => $pumps,
        'device_online' => $maxAge !== null && $maxAge <= DEVICE_POLL_STALE_SECONDS,
    ], 'Status pompa');
} catch (Throwable $e) {
    error_log('Pump status: ' . $e->getMessage());
    errorResponse('Gagal membaca status pompa.', 500);
}
