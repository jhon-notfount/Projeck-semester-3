<?php
/**
 * Hydrotech - Ubah Password (halaman profile)
 *
 * Identitas akun diambil dari SESI LOGIN. Password saat ini diverifikasi,
 * password baru diperiksa kebijakannya, berbeda dari lama, dan cocok dengan
 * konfirmasi. Update hash + riwayat berjalan dalam satu transaksi, lalu
 * sesi lain yang lebih tua ditolak oleh requireAuth() secara otomatis.
 *
 * POST: current_password, new_password, confirm_password, csrf_token
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/account.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }
requireCsrfToken();

const PWD_CHANGE_COUNTER = 'pwd_change_failures';
if (accountAttemptsBlocked(PWD_CHANGE_COUNTER)) {
    errorResponse('Terlalu banyak percobaan. Coba lagi dalam 1 menit.', 429);
}

/* Pencatat kegagalan (tanpa password); kegagalan log tidak mengganggu respons. */
function logFailedPassword(PDO $pdo, int $userId, string $username): void {
    try {
        writeAccountChangeLog($pdo, $userId, 'password_change', 'profile', $username, null, 'failed');
    } catch (Throwable $logError) {
        error_log('Ubah password: gagal mencatat log kegagalan: ' . $logError->getMessage());
    }
}

try {
    $pdo = getDBConnection();

    /* Identitas dari sesi login, bukan dari frontend. */
    $stmt = $pdo->prepare(
        "SELECT `user_id` AS `id`, `username`, `password` FROM users WHERE user_id = ? LIMIT 1"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { errorResponse('Sesi tidak valid. Silakan login kembali.', 401); }

    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    $fieldError = null;
    if ($currentPassword === '' || !password_verify($currentPassword, $user['password'])) {
        // Hanya kegagalan kredensial yang dihitung ke rate limit.
        accountRecordFailure(PWD_CHANGE_COUNTER);
        logFailedPassword($pdo, (int)$user['id'], $user['username']);
        $fieldError = ['field' => 'current_password', 'message' => 'Password saat ini salah.'];
    }

    if ($fieldError === null && ($policyError = validateNewPassword($newPassword)) !== null) {
        $fieldError = ['field' => 'new_password', 'message' => $policyError];
    }

    if ($fieldError === null && $newPassword === $currentPassword) {
        $fieldError = ['field' => 'new_password', 'message' => 'Password baru harus berbeda dari password lama.'];
    }

    if ($fieldError === null && $newPassword !== $confirmPassword) {
        $fieldError = ['field' => 'confirm_password', 'message' => 'Konfirmasi password baru tidak cocok.'];
    }

    if ($fieldError !== null) {
        errorResponse($fieldError['message'], 422, ['field' => $fieldError['field']]);
    }

    /* TRANSAKSI: hash baru + penanda waktu + riwayat tersimpan atomik. */
    $pdo->beginTransaction();
    try {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $pdo->prepare(
            "UPDATE users SET password = ?, password_changed_at = CURRENT_TIMESTAMP WHERE user_id = ?"
        );
        $update->execute([$newHash, $user['id']]);

        writeAccountChangeLog($pdo, (int)$user['id'], 'password_change', 'profile', $user['username'], null, 'success');

        $pdo->commit();
    } catch (Throwable $txError) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $txError;
    }

    /* SESSION SAFETY: regenerasi ID sesi (anti fixation) dan simpan epoch
       agar sesi INI tetap sah; sesi lain otomatis ditolak requireAuth(). */
    session_regenerate_id(true);
    $_SESSION['pwd_epoch'] = time();
    accountClearFailures(PWD_CHANGE_COUNTER);

    successResponse(['username' => $user['username']], 'Password berhasil diperbarui.');
} catch (Throwable $e) {
    error_log('Ubah password: ' . $e->getMessage());
    errorResponse('Server error: password tidak diubah.', 500);
}
