#!/usr/bin/env php
<?php
/**
 * Hydrotech - MQTT Subscriber Worker (MQTT -> MySQL)
 *
 * Berjalan sebagai daemon (systemd unit: hydrotech-mqtt-subscriber).
 *
 *   hydrotech/sensor/ph   {"value":6.5,"valid":true,"ts":1700000000}   (QoS 0, 2 dtk)
 *   hydrotech/sensor/ppm  {"value":850,"valid":true,"ts":1700000000}   (QoS 0, 2 dtk)
 *   hydrotech/pump/status {"pump":"ab","state":"on","mode":"manual",   (QoS 1)
 *                          "remaining_s":27}
 *   hydrotech/device/status {"online":true,"ip":"..","rssi":-52}       (QoS 1, retained)
 *
 * Data sensor DIPASANGKAN per timestamp (satu pengukuran = satu baris
 * sensor_readings, model database tetap): pH dan PPM yang datang bersama
 * dalam satu siklus digabung; pasangan yang tak lengkap disimpan sendiri
 * setelah 4 detik. Penyimpanan memakai saveSensorMeasurements() sehingga
 * aturan monitoring_logs + notifikasi lama tetap berjalan utuh.
 *
 * Reconnect otomatis dengan backoff; broker mati tidak mematikan worker.
 */

require_once __DIR__ . '/../vendor/hydrotech/MqttClient.php';
require_once __DIR__ . '/../includes/mqtt.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/sensors.php';
require_once __DIR__ . '/../includes/dithane.php';
require_once __DIR__ . '/../includes/device_report.php';

set_time_limit(0);

const SENSOR_TOPICS = ['hydrotech/sensor/ph', 'hydrotech/sensor/ppm'];
const PAIR_WINDOW_S = 4;          // usia maksimum entri tunggal sebelum disimpan sendiri
const FLUSH_EVERY_S = 2;

/** @var array<float, array{ph: ?array, ppm: ?array, at: float}> $pairing */
$pairing = [];
$lastFlush = 0;

function logLine(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

/** Simpan satu pengukuran (ph dan/atau ppm) ke MySQL dengan aturan lama. */
function persistReading(array $entry): void
{
    $input = [];
    if ($entry['ph'] !== null && $entry['ph']['valid']) $input['ph_value'] = $entry['ph']['value'];
    if ($entry['ppm'] !== null && $entry['ppm']['valid']) $input['ppm_value'] = (int)round($entry['ppm']['value']);
    if (!$input) return;                                  // keduanya invalid: tidak disimpan
    saveSensorMeasurements(getDBConnection(), $input);
}

/** Simpan entri tunggal yang terlalu lama tak berpasangan. */
function flushStale(array &$pairing): void
{
    $now = microtime(true);
    foreach ($pairing as $ts => $entry) {
        if ($now - $entry['at'] >= PAIR_WINDOW_S) {
            persistReading($entry);
            unset($pairing[$ts]);
        }
    }
}

function upsertPumpState(array $data): void
{
    $pump = $data['pump'] ?? null;
    if (!in_array($pump, ['ab', 'ph', 'dithane', 'circ'], true)) return;
    $state = ($data['state'] ?? 'off') === 'on' ? 'on' : 'off';
    $mode = ($data['mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
    $remaining = filter_var($data['remaining_s'] ?? null, FILTER_VALIDATE_INT);
    $remaining = $remaining === false ? null : max(0, min(3600, $remaining));
    if ($mode === 'auto') $remaining = null;
    getDBConnection()
        ->prepare('INSERT INTO pump_states (pump, state, mode, remaining_s) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE state = VALUES(state), mode = VALUES(mode), remaining_s = VALUES(remaining_s)')
        ->execute([$pump, $state, $mode, $remaining]);
}

$cfg = mqttConfig();
$client = mqttWorkerClient();
$client->setOnMessage(function (string $topic, string $payload) use (&$pairing, &$client): void {
    $data = json_decode($payload, true);
    if (!is_array($data)) return;

    if (str_starts_with($topic, 'hydrotech/sensor/')) {
        $type = substr($topic, strrpos($topic, '/') + 1);
        if (!in_array($type, ['ph', 'ppm'], true)) return;
        if (!isset($data['value']) || !is_numeric($data['value'])) return;
        $msg = ['value' => (float)$data['value'], 'valid' => !empty($data['valid'])];
        $ts = filter_var($data['ts'] ?? null, FILTER_VALIDATE_FLOAT);
        $ts = ($ts === false || $ts === null) ? microtime(true) : (float)$ts;

        if (isset($pairing[$ts])) {                       // pasangan lengkap -> simpan
            $pairing[$ts][$type] = $msg;
            $entry = $pairing[$ts];
            unset($pairing[$ts]);
            try { persistReading($entry); }
            catch (Throwable $e) { logLine('Gagal simpan pengukuran: ' . $e->getMessage()); }
        } else {                                          // tunggu pasangannya
            $pairing[$ts] = ['ph' => null, 'ppm' => null, 'at' => microtime(true)];
            $pairing[$ts][$type] = $msg;
        }
        return;
    }

    if ($topic === 'hydrotech/pump/status') {
        upsertPumpState($data);
        return;
    }
    /* hydrotech/device/status: heartbeat/LWT cukup untuk log; status online
       dashboard tetap dari kesegaran sensor_readings (api/device/status.php).
       Payload {"type":"ack","msg_id":..} menandai perintah antrean selesai. */
    if ($topic === 'hydrotech/device/status' && ($data['type'] ?? null) === 'ack') {
        $msgId = (string)($data['msg_id'] ?? '');
        if (str_starts_with($msgId, 'httpcmd-')) {
            $commandId = substr($msgId, 8);
            if (ctype_digit($commandId)) {
                getDBConnection()
                    ->prepare("UPDATE device_commands
                                  SET status = 'done', completed_at = CURRENT_TIMESTAMP,
                                      result_note = ?
                                WHERE command_id = ? AND status = 'delivered'")
                    ->execute([(string)($data['note'] ?? ''), (int)$commandId]);
            }
        }
        return;
    }

    /* Laporan eksekusi pompa (pengganti HTTP api/device/report.php):
       menulis monitoring_logs + notifikasi lewat fungsi yang sama.        */
    if ($topic === 'hydrotech/device/status' && ($data['type'] ?? null) === 'pump_activity') {
        $pump = (string)($data['pump'] ?? '');
        $state = (string)($data['state'] ?? '');
        $duration = filter_var($data['duration_s'] ?? 0, FILTER_VALIDATE_INT);
        $duration = $duration === false ? 0 : $duration;
        try {
            if ($pump === 'dithane') {
                recordDithaneActivity(getDBConnection(), $pump, $state, $duration, 'MQTT');
            } elseif (in_array($pump, ['ab', 'ph'], true)) {
                recordManualPumpActivity(getDBConnection(), $pump, $state, $duration);
            }
        } catch (Throwable $e) {
            logLine('Laporan pompa gagal diproses: ' . $e->getMessage());
        }
        return;
    }

    /* Permintaan konfigurasi (pengganti HTTP GET config): worker membaca
       settings + dithane_schedules lalu mendorongnya ke cmd/esp32/*.      */
    if ($topic === 'hydrotech/device/status' && ($data['type'] ?? null) === 'config_request') {
        pushCurrentConfig($client);
        return;
    }
});

logLine('Worker mulai; broker ' . $cfg['host'] . ':' . $cfg['port']);

/**
 * JEMBATAN ANTREAN: perintah kontrol pompa dari dashboard (device_commands)
 * dipublikasikan ke hydrotech/cmd/esp32/pump (QoS 1, tanpa retained).
 * msg_id = "httpcmd-<command_id>" — ESP32 menolak eksekusi msg_id ganda,
 * sehingga pengiriman ulang perintah yang sama tidak pernah dijalankan ulang.
 */
function relayPendingCommands(MqttClient $client, array &$inFlight): void
{
    $pdo = getDBConnection();
    /* Perintah yang tidak pernah di-ack >60 s dikembalikan pending
       (aman: dedup msg_id mencegah eksekusi ganda di ESP32).           */
    $pdo->exec("UPDATE device_commands SET status = 'pending'
                 WHERE status = 'delivered' AND delivered_at < CURRENT_TIMESTAMP - INTERVAL 60 SECOND");
    $rows = $pdo->query(
        "SELECT command_id, pump, action, duration_s FROM device_commands
          WHERE status = 'pending' ORDER BY command_id ASC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
    $mark = $pdo->prepare("UPDATE device_commands SET status = 'delivered', delivered_at = CURRENT_TIMESTAMP WHERE command_id = ?");
    foreach ($rows as $row) {
        $msgId = 'httpcmd-' . $row['command_id'];
        if (isset($inFlight[$msgId]) && time() - $inFlight[$msgId] < 30) continue; // belum di-ack
        $payload = json_encode([
            'msg_id' => $msgId,
            'pump' => $row['pump'],
            'action' => $row['action'],
            'duration_s' => (int)$row['duration_s'],
            'issued_by' => 'dashboard',
        ]);
        if ($client->publish('hydrotech/cmd/esp32/pump', $payload, 1, false)) {
            $inFlight[$msgId] = time();
            $mark->execute([(int)$row['command_id']]);
        }
    }
    /* Bersihkan in-flight yang sudah lama. */
    foreach ($inFlight as $k => $t) if (time() - $t > 300) unset($inFlight[$k]);
}

$inFlight = [];
$lastRelay = 0;

/**
 * Dorong konfigurasi aktif (ambang pH/PPM + jadwal Dithane) ke ESP32.
 * Dipanggil saat perangkat meminta (config_request) — dipakai juga
 * sebagai sinkronisasi berkala & pasca-restart perangkat. msg_id unik
 * per dorongan; ESP menolak msg_id ganda.
 */
function pushCurrentConfig(MqttClient $client): void
{
    $pdo = getDBConnection();
    $settings = $pdo->query("SELECT type, config_json FROM settings WHERE type IN ('ph','ppm')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['ph', 'ppm'] as $type) {
        $config = json_decode($settings[$type] ?? 'null', true);
        if (!is_array($config) || !isset($config['min'], $config['max'])) continue;
        $msgId = 'cfg-' . $type . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $client->publish(
            "hydrotech/cmd/esp32/{$type}",
            json_encode(['msg_id' => $msgId, 'min' => $config['min'], 'max' => $config['max']]),
            1,
            false
        );
    }

    /* Jadwal Dithane + anchor interval (waktu selesai terakhir). */
    $row = $pdo->query(
        "SELECT ds.interval_hours, ds.duration_seconds, ds.start_time,
                (SELECT DATE_ADD(l.created_at, INTERVAL COALESCE(d.duration_seconds, 0) SECOND)
                   FROM monitoring_logs l
                   LEFT JOIN dithane_schedules d ON d.schedule_id = 1
                  WHERE l.type = 'dithane' AND l.is_deleted = 0
                  ORDER BY l.monitoring_id DESC LIMIT 1) AS last_done
           FROM dithane_schedules ds WHERE ds.schedule_id = 1"
    )->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $msgId = 'cfg-dithane-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $client->publish(
            'hydrotech/cmd/esp32/dithane',
            json_encode([
                'msg_id' => $msgId,
                'interval' => (string)$row['interval_hours'],
                'duration' => (string)$row['duration_seconds'],
                'start' => str_replace(':', '.', substr((string)$row['start_time'], 0, 5)),
                'last_completed_at' => $row['last_done'] ? substr((string)$row['last_done'], 0, 19) : '',
            ]),
            1,
            false
        );
    }
    logLine('Konfigurasi aktif didorong ke perangkat.');
}

$backoff = 2;
while (true) {
    if (!$client->connected()) {
        try {
            if ($client->connect(5)) {
                logLine('Terhubung ke broker.');
                $backoff = 2;
                foreach (SENSOR_TOPICS as $t) {
                    if (!$client->subscribe($t, 0)) logLine("SUBSCRIBE $t gagal");
                }
                $client->subscribe('hydrotech/pump/status', 1);
                $client->subscribe('hydrotech/device/status', 1);
            }
        } catch (Throwable $e) {
            logLine('Koneksi gagal: ' . $e->getMessage());
        }
        if (!$client->connected()) {
            logLine("Coba lagi dalam {$backoff} s");
            sleep($backoff);
            $backoff = min(60, $backoff * 2);
            continue;
        }
    }
    $client->loop(500);
    if (time() - $lastRelay >= 2) {                       // jembatan antrean perintah
        $lastRelay = time();
        try { relayPendingCommands($client, $inFlight); }
        catch (Throwable $e) { logLine('Relay perintah gagal: ' . $e->getMessage()); }
    }
    if (time() - $lastFlush >= FLUSH_EVERY_S) {
        $lastFlush = time();
        flushStale($pairing);
    }
}
