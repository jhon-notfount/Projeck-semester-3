<?php
/**
 * Hydrotech - Account Management Helpers
 *
 * Helper bersama untuk fitur pengelolaan akun (reset password dari halaman
 * login serta ubah username/password dari halaman profile). Dipakai oleh:
 *   - api/auth/reset-password.php
 *   - api/account/change-username.php
 *   - api/account/change-password.php
 *
 * Prinsip keamanan:
 *   - Password hanya bergerak sebagai hash (password_hash/password_verify).
 *   - Seluruh query memakai prepared statements.
 *   - Riwayat dicatat ke account_change_logs (relasi FK ke users); password
 *     TIDAK PERNAH dicatat dalam bentuk apa pun.
 */

require_once __DIR__ . '/../config/database.php';

/* PASSWORD POLICY: minimal 8 karakter, maksimal 72 (batas bcrypt),
   wajib mengandung huruf dan angka. Mengembalikan pesan galat atau null. */
function validateNewPassword(?string $password): ?string {
    if ($password === null || strlen($password) < 8) {
        return 'Password baru minimal 8 karakter.';
    }
    if (strlen($password) > 72) {
        return 'Password baru maksimal 72 karakter.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password baru harus mengandung huruf dan angka.';
    }
    return null;
}

/* USERNAME POLICY: 3-50 karakter, huruf, angka, titik, garis bawah, tanda hubung. */
function validateNewUsername(?string $username): ?string {
    if ($username === null || strlen($username) < 3) {
        return 'Username baru minimal 3 karakter.';
    }
    if (strlen($username) > 50 || !preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
        return 'Username baru hanya boleh huruf, angka, titik, garis bawah, dan tanda hubung (maks 50 karakter).';
    }
    return null;
}

/* IP HELPERS: alamat pengguna untuk riwayat (mendukung X-Forwarded-For). */
function accountClientIp(): ?string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if (is_string($ip) && str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return $ip !== '' && $ip !== null ? substr($ip, 0, 45) : null;
}

/* RATE LIMIT: pembatas percobaan gagal per sesi (5 kali, jeda 60 detik),
   pola yang sama dengan api/auth/login.php. Mengembalikan true bila diblokir. */
function accountAttemptsBlocked(string $counterKey): bool {
    $failures = (int)($_SESSION[$counterKey] ?? 0);
    $lastFail = (int)($_SESSION[$counterKey . '_last'] ?? 0);
    return $failures >= 5 && (time() - $lastFail) < 60;
}

function accountRecordFailure(string $counterKey): void {
    $_SESSION[$counterKey] = (int)($_SESSION[$counterKey] ?? 0) + 1;
    $_SESSION[$counterKey . '_last'] = time();
}

function accountClearFailures(string $counterKey): void {
    unset($_SESSION[$counterKey], $_SESSION[$counterKey . '_last']);
}

/* ACCOUNT CHANGE LOG: penulis riwayat ke account_change_logs.
   Bisa dipanggil di dalam transaksi pemanggil (konsistensi atomik) atau
   berdiri sendiri untuk pencatatan kegagalan validasi. Tidak pernah
   menerima parameter password. */
function writeAccountChangeLog(
    PDO $pdo,
    ?int $userId,
    string $actionType,
    string $source,
    ?string $oldUsername,
    ?string $newUsername,
    string $status
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO account_change_logs
            (user_id, action_type, source, old_username, new_username, status, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $truncate = function_exists('mb_substr') ? 'mb_substr' : 'substr'; // tanpa ketergantungan ekstensi mbstring
    $stmt->execute([
        $userId,
        $actionType,
        $source,
        $oldUsername !== null ? $truncate($oldUsername, 0, 100) : null,
        $newUsername !== null ? $truncate($newUsername, 0, 100) : null,
        $status,
        accountClientIp(),
    ]);
}

/* USER LOOKUP: mencari akun berdasarkan username (prepared statement).
   Mengembalikan array assoc atau null. */
function findUserByUsername(PDO $pdo, string $username): ?array {
    $stmt = $pdo->prepare(
        "SELECT `user_id` AS `id`, `username`, `password`, `email`
         FROM users WHERE username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}
