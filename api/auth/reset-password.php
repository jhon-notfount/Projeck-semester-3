<?php
/**
 * Hydrotech - Reset Password (halaman login)
 *
 * Perubahan password berbasis verifikasi kredensial: pemohon wajib
 * mengisi username dan password lama yang cocok dengan database.
 * Tidak memerlukan sesi login (dijalankan dari halaman login), tetap
 * memakai CSRF per sesi browser dan rate limiting.
 *
 * POST: username, old_password, new_password, confirm_password, csrf_token
 *
 * Berhasil: hash password diperbarui (transaksi) + riwayat dicatat ke
 * account_change_logs. Sesi aktif lain otomatis ditolak oleh requireAuth()
 * melalui kolom users.password_changed_at.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/account.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }
requireCsrfToken();

/* RATE LIMIT: batasi percobaan reset per sesi browser (anti brute force). */
const PWD_RESET_COUNTER = 'pwd_reset_failures';
if (accountAttemptsBlocked(PWD_RESET_COUNTER)) {
    errorResponse('Terlalu banyak percobaan. Coba lagi dalam 1 menit.', 429);
}

/* Pencatat kegagalan kredensial (tanpa password apa pun); kegagalan log
   tidak boleh mengganggu respons utama. */
function logFailedAttempt(PDO $pdo, ?int $userId, ?string $attemptedUsername): void {
    try {
        writeAccountChangeLog($pdo, $userId, 'password_change', 'login', $attemptedUsername, null, 'failed');
    } catch (Throwable $logError) {
        error_log('Reset password: gagal mencatat log kegagalan: ' . $logError->getMessage());
    }
}

$username = trim((string)($_POST['username'] ?? ''));
$oldPassword = (string)($_POST['old_password'] ?? '');
$newPassword = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

/* VALIDASI INPUT: kegagalan dicatat (tanpa password) bila usernya dikenal. */
$fieldError = null;
$logUser = null;

try {
    $pdo = getDBConnection();

    if ($username === '' || $oldPassword === '') {
        $fieldError = ['field' => 'old_password', 'message' => 'Username dan password lama wajib diisi.'];
    }

    $user = $username === '' ? null : findUserByUsername($pdo, $username);
    if ($fieldError === null && !$user) {
        // Pesan seragam agar keberadaan akun tidak terungkap.
        accountRecordFailure(PWD_RESET_COUNTER);
        logFailedAttempt($pdo, null, $username);
        $fieldError = ['field' => 'old_password', 'message' => 'Username atau password lama tidak cocok.'];
    }

    if ($fieldError === null && !password_verify($oldPassword, $user['password'])) {
        accountRecordFailure(PWD_RESET_COUNTER);
        logFailedAttempt($pdo, (int)$user['id'], $username);
        $fieldError = ['field' => 'old_password', 'message' => 'Username atau password lama tidak cocok.'];
    }

    if ($fieldError === null && ($policyError = validateNewPassword($newPassword)) !== null) {
        $fieldError = ['field' => 'new_password', 'message' => $policyError];
    }

    if ($fieldError === null && $newPassword !== $confirmPassword) {
        $fieldError = ['field' => 'confirm_password', 'message' => 'Konfirmasi password baru tidak cocok.'];
    }

    if ($fieldError === null && password_verify($newPassword, $user['password'])) {
        $fieldError = ['field' => 'new_password', 'message' => 'Password baru harus berbeda dari password lama.'];
    }

    if ($fieldError !== null) {
        // Kegagalan non-kredensial (kebijakan password/konfirmasi) tidak masuk
        // hitungan rate limit; sudah melewati verifikasi kredensial.
        errorResponse($fieldError['message'], 422, ['field' => $fieldError['field']]);
    }

    /* TRANSAKSI: hash baru + riwayat sukses tersimpan atomik; bila salah satu
       gagal, password lama tetap utuh (tidak ada pembaruan sebagian). */
    $pdo->beginTransaction();
    try {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $pdo->prepare(
            "UPDATE users SET password = ?, password_changed_at = CURRENT_TIMESTAMP WHERE user_id = ?"
        );
        $update->execute([$newHash, $user['id']]);

        writeAccountChangeLog($pdo, (int)$user['id'], 'password_change', 'login', $user['username'], $user['username'], 'success');

        $pdo->commit();
    } catch (Throwable $txError) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $txError;
    }

    accountClearFailures(PWD_RESET_COUNTER);
    successResponse(['username' => $user['username']], 'Password berhasil direset. Silakan login dengan password baru.');
} catch (InvalidArgumentException $e) {
    errorResponse($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('Reset password: ' . $e->getMessage());
    errorResponse('Server error: password tidak diubah.', 500);
}
