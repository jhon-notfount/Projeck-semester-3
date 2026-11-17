<?php
/**
 * Hydrotech - Manual Pump Control Endpoint (api/pump/control.php)
 *
 * Membuat perintah ON/OFF pompa manual dari dashboard. Perintah MASUK ANTREAN
 * (device_commands) dan diambil perangkat ESP32 pada poll berikutnya melalui
 * api/device/commands.php — dashboard tidak pernah mengontrol pompa langsung.
 *
 * POST (login + CSRF): pump = ab|ph|dithane, action = on|off, duration_s opsional.
 *   - action "on"  : override manual berdurasi; habis durasi, pompa kembali
 *                    ke otomatisasi lokal (timer di ESP32, bukan di server).
 *   - action "off" : paksa pompa OFF selama durasi, lalu otomatis jalan lagi.
 *
 * Batas durasi mengikuti keselamatan firmware:
 *   ab / ph  : 1..120 detik (satu siklus dosis, DOSE_COOLDOWN tetap berlaku)
 *   dithane  : 1..600 detik (MAX_SPRAY_SECONDS firmware)
 *
 * Dedup: perintah identik untuk pompa yang sama dalam 2 detik terakhir
 * diabaikan (pencegahan klik ganda / permintaan ganda).
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/sensors.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);
requireCsrfToken();

/* Batas durasi override manual per pompa (detik). */
const PUMP_MANUAL_LIMITS = ['ab' => 120, 'ph' => 120, 'dithane' => 600];
/* Durasi default bila dashboard tidak mengirim duration_s. */
const PUMP_DEFAULT_DURATION = ['on' => 30, 'off' => 300];

try {
    $pump = $_POST['pump'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!array_key_exists($pump, PUMP_MANUAL_LIMITS)) {
        errorResponse('Pompa tidak dikenal. Gunakan ab, ph, atau dithane.', 400);
    }
    if (!in_array($action, ['on', 'off'], true)) {
        errorResponse('Aksi tidak valid. Gunakan on atau off.', 400);
    }

    $duration = filter_var($_POST['duration_s'] ?? null, FILTER_VALIDATE_INT);
    if ($duration === false || $duration === null) {
        $duration = PUMP_DEFAULT_DURATION[$action];
    }
    $limit = PUMP_MANUAL_LIMITS[$pump];
    if ($duration < 1 || $duration > $limit) {
        errorResponse("Durasi override harus 1..{$limit} detik untuk pompa ini.", 400);
    }

    $pdo = getDBConnection();
    $lock = measurementLock($pdo);
    try {
        /* Dedup: perintah identik yang masih pending < 2 detik diabaikan.  */
        $dup = $pdo->prepare(
            "SELECT command_id FROM device_commands
              WHERE pump = ? AND action = ? AND status = 'pending'
                AND created_at >= CURRENT_TIMESTAMP - INTERVAL 2 SECOND
              LIMIT 1"
        );
        $dup->execute([$pump, $action]);
        if ($existing = $dup->fetchColumn()) {
            successResponse(['command_id' => (int)$existing, 'duplicate' => true], 'Perintah sudah ada di antrean.');
        }

        $insert = $pdo->prepare(
            "INSERT INTO device_commands (pump, action, duration_s, issued_by) VALUES (?, ?, ?, ?)"
        );
        $insert->execute([$pump, $action, $duration, $_SESSION['user_id'] ?? null]);
        $commandId = (int)$pdo->lastInsertId();
    } finally {
        measurementUnlock($pdo, $lock);
    }

    $labels = ['ab' => 'nutrisi AB-Mix (PPM)', 'ph' => 'pengatur pH', 'dithane' => 'penyemprot Dithane'];
    successResponse(
        ['command_id' => $commandId, 'duplicate' => false, 'pump' => $pump, 'action' => $action, 'duration_s' => $duration],
        "Perintah {$action} pompa {$labels[$pump]} ({$duration} s) dikirim ke antrean perangkat."
    );
} catch (Throwable $e) {
    error_log('Pump control: ' . $e->getMessage());
    errorResponse('Gagal membuat perintah kontrol pompa.', 500);
}
