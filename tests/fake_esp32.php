#!/usr/bin/env php
<?php
/**
 * HydroTech - Simulator ESP32 (uji end-to-end tanpa perangkat)
 *
 * Meniru perilaku firmware MQTT: publish sensor tiap 2 detik, subscribe
 * perintah pompa + config, publish ack & status perangkat. Dipakai untuk
 * membuktikan jalur ESP<->Web tanpa perangkat fisik.
 *
 * Pemakaian: php tests/fake_esp32.php [durasi-detik]
 */

require_once __DIR__ . '/../vendor/hydrotech/MqttClient.php';
require_once __DIR__ . '/../includes/mqtt.php';

$durasi = (int)($argv[1] ?? 20);
$cfg = mqttConfig();

$client = new MqttClient($cfg['host'], $cfg['port'], 'fake-esp32', $cfg['user_esp'], $cfg['pass_esp'], 30);
if (!$client->connect(5)) { fwrite(STDERR, "Gagal konek ke broker\n"); exit(1); }
echo "[fake-esp] terhubung ke broker {$cfg['host']}:{$cfg['port']}\n";

$client->setOnMessage(function (string $topic, string $payload) use (&$client): void {
    $data = json_decode($payload, true);
    echo "[fake-esp] TERIMA $topic => {$payload}\n";
    if (str_starts_with($topic, 'hydrotech/cmd/esp32/')) {
        /* Ack persis seperti firmware: QoS 1, tanpa retained. */
        $ack = json_encode([
            'type' => 'ack', 'msg_id' => $data['msg_id'] ?? '', 'topic' => $topic,
            'ok' => true, 'note' => 'dieksekusi (simulator)', 'ts' => time(),
        ]);
        $client->publish('hydrotech/device/status', $ack, 1, false);
        echo "[fake-esp] ack terkirim\n";
    }
});

foreach (['ph', 'ppm', 'dithane', 'pump'] as $t) {
    $client->subscribe("hydrotech/cmd/esp32/{$t}", 1);
}
/* Minta konfigurasi awal — worker PHP harus menjawab. */
$client->publish('hydrotech/device/status', json_encode(['type' => 'config_request', 'ts' => time()]), 1, false);
echo "[fake-esp] config_request terkirim\n";

$ph = 6.4; $ppm = 880; $mulai = time();
for ($i = 0; time() - $mulai < $durasi; $i++) {
    $ts = time();
    $client->publish('hydrotech/sensor/ph', json_encode(['value' => round($ph + 0.01 * ($i % 8), 2), 'valid' => true, 'ts' => $ts]), 0, false);
    $client->publish('hydrotech/sensor/ppm', json_encode(['value' => $ppm + ($i % 6) * 3, 'valid' => true, 'ts' => $ts]), 0, false);
    echo "[fake-esp] sensor terkirim (siklus $i)\n";
    $client->publish('hydrotech/device/status', json_encode(['type' => 'status', 'online' => true, 'ip' => '192.168.1.99', 'rssi' => -55, 'ts' => $ts]), 1, true);
    $batas = microtime(true) + 2;
    while (microtime(true) < $batas) { $client->loop(200); }   // 2 detik, tetap proses pesan
}
$client->disconnect();
echo "[fake-esp] selesai\n";
