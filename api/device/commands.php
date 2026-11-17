<?php
/**
 * Hydrotech - Device Command Poll Endpoint (api/device/commands.php)
 *
 * Jalur kontrol pompa manual dari dashboard ke perangkat ESP32, memakai
 * autentikasi API key yang sama dengan api/sensor/ingest.php (X-API-Key).
 *
 * POST { "pumps": { "ab": "on|off", "ph": "...", "dithane": "...", "circ": "..." },
 *        "ack": [command_id, ...] }
 *   - "pumps" : status pompa terkini -> di-upsert ke pump_states (sumber status
 *               tombol dashboard; status mencerminkan kondisi pompa sebenarnya).
 *   - "ack"   : command_id yang sudah dieksekusi/ditolak pada siklus sebelumnya.
 *
 * Respons membawa seluruh perintah pending (urut command_id) sekaligus
 * menandainya delivered. Perintah dieksekusi ESP32 berdasarkan command_id,
 * sehingga perintah duplikat tidak pernah dibuat dua kali.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/esp.php';
require_once __DIR__ . '/../../includes/response.php';

requireEspKey([]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

try {
    $raw = file_get_contents('php://input');
    $input = [];
    if (str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        try { $input = json_decode($raw ?: '[]', true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { errorResponse('JSON tidak valid.', 400); }
    } else if ($raw) {
        parse_str($raw, $input);
    }
    if (!is_array($input)) $input = [];

    $pdo = getDBConnection();
    $pdo->beginTransaction();

    /* 1) ACK: tandai hasil eksekusi perintah siklus sebelumnya.            */
    $ackDone = $pdo->prepare(
        "UPDATE device_commands SET status = 'done', completed_at = CURRENT_TIMESTAMP
          WHERE command_id = ? AND status = 'delivered'"
    );
    foreach ((($input['ack'] ?? []) ) as $commandId) {
        if (filter_var($commandId, FILTER_VALIDATE_INT) !== false) $ackDone->execute([(int)$commandId]);
    }

    /* 2) Status pompa: upsert pump_states (tanpa menyentuh baris lain).    */
    $upsert = $pdo->prepare(
        "INSERT INTO pump_states (pump, state, mode, remaining_s)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE state = VALUES(state), mode = VALUES(mode), remaining_s = VALUES(remaining_s)"
    );
    $reported = 0;
    foreach (($input['pumps'] ?? []) as $pump => $info) {
        if (!in_array($pump, ['ab', 'ph', 'dithane', 'circ'], true)) continue;
        $info = is_array($info) ? $info : ['state' => $info];
        $state = ($info['state'] ?? null) === 'on' ? 'on' : 'off';
        $mode = ($info['mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
        $remaining = filter_var($info['remaining_s'] ?? null, FILTER_VALIDATE_INT);
        $remaining = $remaining === false ? null : max(0, min(3600, $remaining));
        if ($mode === 'auto') $remaining = null;
        $upsert->execute([$pump, $state, $mode, $remaining]);
        $reported++;
    }

    /* 3) Ambil seluruh perintah pending, tandai delivered sekali saja.     */
    $rows = $pdo->query(
        "SELECT command_id, pump, action, duration_s FROM device_commands
          WHERE status = 'pending' ORDER BY command_id ASC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
    $mark = $pdo->prepare(
        "UPDATE device_commands SET status = 'delivered', delivered_at = CURRENT_TIMESTAMP
          WHERE status = 'pending' AND command_id = ?"
    );
    $commands = [];
    foreach ($rows as $row) {
        $mark->execute([(int)$row['command_id']]);
        $commands[] = [
            'command_id' => (int)$row['command_id'],
            'pump' => $row['pump'],
            'action' => $row['action'],
            'duration_s' => (int)$row['duration_s'],
        ];
    }
    $pdo->commit();

    successResponse(['commands' => $commands, 'reported' => $reported], 'Perintah diproses');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Device commands: ' . $e->getMessage());
    errorResponse('Gagal memproses perintah perangkat.', 500);
}
