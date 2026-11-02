<?php
/**
 * Hydrotech - Device Credentials Endpoint
 *
 * GET api/device/credentials.php  (hanya sesi login admin)
 *
 * Menyediakan data yang diperlukan untuk menghubungkan perangkat ESP32:
 *   - api_base_url : alamat lengkap POST/GET /api/sensor/ingest.php,
 *     dihitung server-side dari IP LAN server (bukan dari HTTP_HOST yang
 *     bisa berupa "localhost" bila admin membuka dashboard di laptop ini).
 *   - api_key      : nilai aktif dari config/esp.php (env → esp_key.txt →
 *     default). Karena sensitif, endpoint ini WAJIB sesi admin — tidak
 *     ada akses tanpa login.
 *
 * Respons:
 *   { success, message, data: { api_base_url, api_key, hostname, port,
 *                               scheme, note } }
 *   note = null atau String peringatan kecil (contoh: host loopback).
 *
 * Tanpa perubahan database; hanya membaca konfigurasi yang sudah ada.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/esp.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

/**
 * Ambil host dari HTTP_HOST tanpa bagian port.
 * @return string Contoh: "192.168.1.10" atau "localhost" (boleh kosong).
 */
function credHttpHost(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    return preg_replace('/:\d+$/', '', $host);
}

/** @return bool true bila host adalah loopback/localhost. */
function credIsLoopback(string $host): bool {
    $lower = strtolower($host);
    return $host === '' || $lower === 'localhost'
        || $lower === '127.0.0.1' || $lower === '[::1]' || $lower === '::1';
}

/**
 * Deteksi IP LAN server (untuk URL yang bisa dipakai ESP32 di jaringan).
 * Urutan: HTTP_HOST non-loopback (admin membuka via IP LAN) →
 *         gethostbyname(gethostname()) → SERVER_ADDR → kosongk/reset.
 * @return array{0:string host, 1:bool loopback}
 */
function credDetectHost(): array {
    $httpHost = credHttpHost();
    if (!credIsLoopback($httpHost)) {
        return [$httpHost, false];        // admin memang membuka via LAN
    }

    $hostName = gethostname();
    $lan = ($hostName !== false) ? @gethostbyname($hostName) : '';
    if ($lan !== $hostName
        && filter_var($lan, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        && str_starts_with($lan, '127.') === false) {
        return [$lan, false];
    }

    $serverAddr = (string)($_SERVER['SERVER_ADDR'] ?? '');
    if ($serverAddr !== '' && str_starts_with($serverAddr, '127.') === false
        && str_starts_with($serverAddr, '::') === false
        && filter_var($serverAddr, FILTER_VALIDATE_IP)) {
        return [$serverAddr, false];
    }

    return [$httpHost !== '' ? $httpHost : '127.0.0.1', true];
}

try {
    // Skema yang melayani dashboard ini sendiri.
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $scheme  = $isHttps ? 'https' : 'http';

    // Host: LAN bila terdeteksi; bila cuman loopback, tetap tampilkan
    // tetapi sertakan peringatan.
    [$host, $loopback] = credDetectHost();

    // Port: dari HTTP_HOST bila tertulis eksplisit; else SERVER_PORT;
    // hanya ditampilkan bila bukan port default (80/443).
    $port = $_SERVER['SERVER_PORT'] ?? '';
    if (preg_match('/:(\d+)$/', (string)($_SERVER['HTTP_HOST'] ?? ''), $pm)) {
        $port = $pm[1];
    }
    $defaultPort = $isHttps ? '443' : '80';
    $portSuffix = ($port !== '' && $port !== $defaultPort) ? ':' . $port : '';

    // Jalur aplikasi: SCRIPT_NAME = <aplikasi>/api/device/credentials.php
    // → dirname dua kali = root aplikasi ("" bila melayani dari root).
    $basePath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/device/credentials.php'))), '/');
    $apiBaseUrl = $scheme . '://' . $host . $portSuffix . $basePath . '/api/sensor/ingest.php';

    $note = null;
    if ($loopback) {
        $note = 'Server mendeteksi host loopback (localhost). URL ini TIDAK '
              . 'bisa dipakai ESP32 — buka dashboard via IP LAN laptop '
              . '(contoh http://<IP-LAPTOP>:' . ($port !== '' ? $port : '8000')
              . ') agar angka di bawah akurat.';
    }

    // Anti-cache: nilai key tidak boleh diam di memori cache perantara.
    header('Cache-Control: no-store');

    successResponse([
        'api_base_url' => $apiBaseUrl,
        'api_key'      => getEspApiKey(),
        'hostname'     => $host,
        'port'         => $port,
        'scheme'       => $scheme,
        'note'         => $note,
    ], 'Kredensial perangkat ESP32');
} catch (Throwable $e) {
    error_log('Device credentials: ' . $e->getMessage());
    errorResponse('Tidak dapat membaca kredensial perangkat.', 500);
}
