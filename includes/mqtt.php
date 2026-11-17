<?php
/**
 * Hydrotech - Helper koneksi MQTT untuk proses PHP.
 * Sumber kredensial: config/mqtt_auth.local.php (di-gitignore).
 */

function mqttConfig(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $authFile = __DIR__ . '/../config/mqtt_auth.local.php';
        if (!is_readable($authFile)) {
            throw new RuntimeException('config/mqtt_auth.local.php tidak ditemukan.');
        }
        require $authFile;
        $cfg = [
            'host' => $MQTT_HOST,
            'port' => $MQTT_PORT,
            'user' => $MQTT_USER_WORKER,
            'pass' => $MQTT_PASS_WORKER,
            'user_esp' => $MQTT_USER_ESP,
            'pass_esp' => $MQTT_PASS_ESP,
        ];
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
