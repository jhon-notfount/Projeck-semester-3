#!/usr/bin/env php
<?php
/**
 * Hydrotech - MQTT Publisher (alat kirim perintah/CLI)
 *
 * Pemakaian:
 *   php bin/mqtt_publisher.php <topic> <payload-json> [qos] [retain]
 *
 * Contoh (perintah ambang pH ke ESP32, QoS 1, TANPA retained):
 *   php bin/mqtt_publisher.php hydrotech/cmd/esp32/ph \
 *     '{"msg_id":"ph-20261117-1","min":5.5,"max":6.5}' 1 0
 *
 * QoS 1 dipakai untuk perintah agar sampai tepat sekali; retained 0 agar
 * ESP32 yang baru hidup tidak mengeksekusi perintah lama.
 */

require_once __DIR__ . '/../vendor/hydrotech/MqttClient.php';
require_once __DIR__ . '/../includes/mqtt.php';

$topic = $argv[1] ?? null;
$payload = $argv[2] ?? null;
$qos = (int)($argv[3] ?? 1);
$retain = (int)($argv[4] ?? 0) === 1;

if ($topic === null || $payload === null) {
    fwrite(STDERR, "Pemakaian: php mqtt_publisher.php <topic> <payload> [qos=1] [retain=0]\n");
    exit(1);
}

$client = mqttWorkerClient();
$deadline = microtime(true) + 8;
while (!$client->connected() && microtime(true) < $deadline) {
    try { $client->connect(3); }
    catch (Throwable $e) { fwrite(STDERR, 'Koneksi gagal: ' . $e->getMessage() . "\n"); exit(1); }
}
if (!$client->connected()) { fwrite(STDERR, "Broker tidak merespons.\n"); exit(1); }

$client->publish($topic, $payload, min(1, max(0, $qos)), $retain);
/* Beri waktu PUBACK masuk untuk QoS 1. */
$waitUntil = microtime(true) + 1;
while (microtime(true) < $waitUntil) $client->loop(100);
$client->disconnect();
echo "Terkirim: $topic\n";
