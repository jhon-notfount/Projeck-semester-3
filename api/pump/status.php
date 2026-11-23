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

    successResponse([
        'pumps'         => $pumps,
        'device_online' => $newest > 0 && (time() - $newest) <= DEVICE_STALE_SECONDS,
    ], 'Status pompa');
} catch (Throwable $e) {
    error_log('Pump status: ' . $e->getMessage());
    errorResponse('Gagal membaca status pompa.', 500);
}
