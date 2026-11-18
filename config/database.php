<?php
/**
 * Hydrotech - Database Configuration
 *
 * Provides PDO connection to the hydrotech database.
 * Uses singleton pattern to avoid multiple connections.
 */

$DB_HOST = getenv('HYDROTECH_DB_HOST') !== false ? getenv('HYDROTECH_DB_HOST') : 'localhost';
$DB_USER = getenv('HYDROTECH_DB_USER') !== false ? getenv('HYDROTECH_DB_USER') : 'Jhon';
$DB_PASS = getenv('HYDROTECH_DB_PASS') !== false ? getenv('HYDROTECH_DB_PASS') : '#Jhon45';
$DB_NAME = getenv('HYDROTECH_DB_NAME') !== false ? getenv('HYDROTECH_DB_NAME') : 'hydrotech';
$DB_CHARSET = 'utf8mb4';

/*
 * ZONA WAKTU: semua waktu aplikasi memakai Asia/Jakarta (WIB, UTC+7).
 * MySQL menyimpan TIMESTAMP sebagai UTC lalu merender string sesuai zona
 * sesi (+07:00); tanpa setelan ini PHP default memakai UTC sehingga
 * strtotime() menggeser waktu 7 jam.
 */
date_default_timezone_set('Asia/Jakarta');
$DB_TIMEZONE_OFFSET = '+07:00';

/**
 * Get PDO database connection (singleton)
 *
 * Urutan kredensial: env HYDROTECH_DB_* -> default (user 'Jhon', produksi
 * Fedora) -> fallback 'root' tanpa password (default Laragon di Windows).
 * Inilah kenapa proyek jalan tanpa konfigurasi di kedua sistem operasi.
 *
 * @return PDO
 */
function getDBConnection() {
    static $pdo = null;

    if ($pdo === null) {
        global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_CHARSET, $DB_TIMEZONE_OFFSET;

        $attempts = [
            [$DB_USER, $DB_PASS],
            ['root', ''],                              // Laragon (Windows) default
        ];

        $lastError = null;
        foreach ($attempts as [$tryUser, $tryPass]) {
            try {
                $pdo = dbConnect($tryUser, $tryPass);
                break;
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'Access denied') === false) throw $e;
                $lastError = $e;
            }
        }
        if ($pdo === null) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . ($lastError ? $lastError->getMessage() : 'kredensial tidak dikenal')
            ]);
            exit;
        }
    }

    return $pdo;
}

/** Buat koneksi PDO tunggal (dipakai getDBConnection). */
function dbConnect(string $user, string $pass): PDO {
    global $DB_HOST, $DB_NAME, $DB_CHARSET, $DB_TIMEZONE_OFFSET;

    try {
        $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $charsetError) {
        if (stripos($charsetError->getMessage(), 'Unknown character set') === false) {
            throw $charsetError;
        }
        $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME}", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('SET NAMES utf8');
    }
    // Kunci zona waktu sesi MySQL agar string TIMESTAMP selalu WIB,
    // konsisten dengan date_default_timezone_set di config ini.
    $pdo->exec("SET time_zone = '{$DB_TIMEZONE_OFFSET}'");
    return $pdo;
}
