<?php
/**
 * Hydrotech - ESP Device Configuration
 *
 * API key untuk autentikasi perangkat ESP (mis. ESP32) pada endpoint
 * api/sensor/ingest.php. Key dikirim perangkat melalui header X-API-Key,
 * field body api_key, atau query ?api_key=.
 *
 * Urutan sumber key (yang pertama ditemukan dipakai):
 *   1. Environment variable HYDROTECH_ESP_KEY
 *   2. File config/esp_key.txt (tidak ikut repo — sudah di .gitignore)
 *   3. Nilai default di bawah (hanya untuk pengembangan lokal)
 *
 * Untuk produksi: set HYDROTECH_ESP_KEY atau buat config/esp_key.txt
 * berisi key, sehingga kredensial tidak tersimpan di source code.
 */

$espKeyFile = __DIR__ . '/esp_key.txt';
$espKeyFromFile = is_readable($espKeyFile) ? trim((string)file_get_contents($espKeyFile)) : '';
$espKeyFromFile = preg_match('/^[A-Za-z0-9]{32,}$/', $espKeyFromFile) ? $espKeyFromFile : '';

$ESP_API_KEY = getenv('HYDROTECH_ESP_KEY') !== false && getenv('HYDROTECH_ESP_KEY') !== ''
    ? getenv('HYDROTECH_ESP_KEY')
    : ($espKeyFromFile !== ''
        ? $espKeyFromFile
        : 'ed09c9af42aa1be4ee34c7da134b2c647ab10fd917ecc9c9');

/**
 * Ambil API key perangkat yang sedang aktif.
 * @return string
 */
function getEspApiKey() {
    global $ESP_API_KEY;
    return $ESP_API_KEY;
}

/**
 * Verifikasi API key perangkat (header X-API-Key, body api_key, atau ?api_key=).
 * Menghentikan request dengan 401 bila tidak cocok.
 * @param array $input Body request (opsional)
 * @return void
 */
function requireEspKey(array $input = []) {
    $provided = $_SERVER['HTTP_X_API_KEY']
        ?? $input['api_key']
        ?? $_GET['api_key']
        ?? null;
    if (!is_string($provided) || $provided === ''
        || !hash_equals(getEspApiKey(), $provided)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'API key perangkat tidak valid.']);
        exit;
    }
}
