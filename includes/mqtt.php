<?php
/**
 * Hydrotech - Helper koneksi MQTT untuk proses PHP.
 *
 * Kredensial: config/mqtt_auth.local.php (di-gitignore, produksi Fedora).
 * Bila file TIDAK ada (instalasi baru / Windows dev): dipakai default yang
 * ikut repo — broker lokal anonymous (127.0.0.1, tanpa password), sehingga
 * proyek jalan tanpa konfigurasi apa pun. Lihat docs/INSTALL-WINDOWS.md.
 */

function mqttConfig(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $authFile = __DIR__ . '/../config/mqtt_auth.local.php';
        if (is_readable($authFile)) {
            require $authFile;
            $cfg = [
                'host' => $MQTT_HOST,
                'port' => $MQTT_PORT,
                'user' => $MQTT_USER_WORKER,
                'pass' => $MQTT_PASS_WORKER,
                'user_esp' => $MQTT_USER_ESP,
                'pass_esp' => $MQTT_PASS_ESP,
            ];
        } else {
            /* Default development (broker lokal, allow_anonymous true):
               kredensial apa pun diterima broker.                         */
            $cfg = [
                'host' => '127.0.0.1',
                'port' => 1883,
                'user' => 'esp32',
                'pass' => 'hydrotech-dev',
                'user_esp' => 'esp32',
                'pass_esp' => 'hydrotech-dev',
            ];
        }
    }
    return $cfg;
}

/** Klien MQTT worker PHP (publisher + subscriber). */
function mqttWorkerClient(): MqttClient
{
    $cfg = mqttConfig();
    return new MqttClient(
        $cfg['host'],
        $cfg['port'],
        'hydrotech-worker',
        $cfg['user'],
        $cfg['pass'],
        30
    );
}
