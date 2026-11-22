<?php
/**
 * Hydrotech - Manual Pump Control Endpoint (api/pump/control.php)
 *
 * Tombol pompa dashboard = pengirim perintah LANGSUNG via MQTT.
 * Tanpa tabel antrean: request ini publish ke hydrotech/cmd/esp32/pump
 * (QoS 1, TANPA retained) dan selesai. Dedup & validasi tetap jalan:
 *  - msg_id unik per klik; ESP32 menolak msg_id ganda (cache FNV-1a).
 *  - ESP32 menolak manual ON saat mode aman / interlock / sensor invalid.
 *  - Override manual berdurasi: habis durasi, pompa kembali otomatis
 *    (timer lokal ESP32 - broker putus pun aman).
 *
 * POST (login + CSRF): pump = ab|ph|dithane, action = on|off, duration_s opsional.
 * Batas durasi mengikuti keselamatan firmware:
 *   ab / ph  : 1..120 detik;   dithane : 1..600 detik (MAX_SPRAY_SECONDS).
 * Broker tidak tersedia -> perintah GAGAL dikirim (dilaporkan ke operator),
 * sesuai desain tanpa antrean.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../vendor/hydrotech/MqttClient.php';
require_once __DIR__ . '/../../includes/mqtt.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);
requireCsrfToken();

/* Batas durasi override manual per pompa (detik). */
const PUMP_MANUAL_LIMITS = ['ab' => 120, 'ph' => 120, 'dithane' => 600];
/* Durasi default bila dashboard tidak mengirim duration_s.
   OFF = paksa mati + tahan otomatisasi; dibatasi batas pompa. */
const PUMP_DEFAULT_DURATION = ['on' => 30, 'off' => 120];

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
    if ($duration > $limit) $duration = $limit;   // OFF 300 utk ab/ph -> dipatok 120
    if ($duration < 1) {
        errorResponse("Durasi override harus 1..{$limit} detik untuk pompa ini.", 400);
    }

    /* Kirim langsung ke broker (QoS 1, tanpa retained). */
    $msgId = 'pump-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $payload = json_encode([
        'msg_id' => $msgId,
        'pump' => $pump,
        'action' => $action,
        'duration_s' => $duration,
        'issued_by' => 'dashboard:' . ($_SESSION['username'] ?? '-'),
    ]);

    $client = mqttWorkerClient();
    if (!$client->connect(3)) {
        errorResponse('Broker MQTT tidak merespons — perintah tidak terkirim. Coba lagi.', 503);
    }
    if (!$client->publish('hydrotech/cmd/esp32/pump', $payload, 1, false)) {
        errorResponse('Koneksi broker terputus — perintah tidak terkirim. Coba lagi.', 503);
    }
    $wait = microtime(true) + 1;
    while (microtime(true) < $wait) $client->loop(100);
    $client->disconnect();

    $labels = ['ab' => 'nutrisi AB-Mix (PPM)', 'ph' => 'pengatur pH', 'dithane' => 'penyemprot Dithane'];
    successResponse(
        ['msg_id' => $msgId, 'pump' => $pump, 'action' => $action, 'duration_s' => $duration],
        "Perintah {$action} pompa {$labels[$pump]} ({$duration} s) terkirim."
    );
} catch (Throwable $e) {
    error_log('Pump control: ' . $e->getMessage());
    errorResponse('Gagal mengirim perintah kontrol pompa.', 500);
}
