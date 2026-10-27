<?php
/**
 * Hydrotech - Ubah Username (halaman profile)
 *
 * Identitas akun diambil dari SESI LOGIN (user_id tidak pernah diterima
 * dari frontend). Perubahan memverifikasi password saat ini, memeriksa
 * keunikan username baru, lalu menyimpan update + riwayat dalam transaksi.
 *
 * POST: new_username, current_password, csrf_token
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/account.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }
requireCsrfToken();

const USERNAME_COUNTER = 'username_failures';
if (accountAttemptsBlocked(USERNAME_COUNTER)) {
    errorResponse('Terlalu banyak percobaan. Coba lagi dalam 1 menit.', 429);
}

/* Pencatat kegagalan (tanpa password); kegagalan log tidak mengganggu respons. */
function logFailedUsername(PDO $pdo, int $userId, string $oldUsername, ?string $attempted): void {
    try {
        writeAccountChangeLog($pdo, $userId, 'username_change', 'profile', $oldUsername, $attempted !== '' ? $attempted : null, 'failed');
    } catch (Throwable $logError) {
        error_log('Ubah username: gagal mencatat log kegagalan: ' . $logError->getMessage());
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

    $newUsername = trim((string)($_POST['new_username'] ?? ''));
    $currentPassword = (string)($_POST['current_password'] ?? '');

    $fieldError = null;
    if ($currentPassword === '' || !password_verify($currentPassword, $user['password'])) {
        // Hanya kegagalan kredensial yang dihitung ke rate limit.
        accountRecordFailure(USERNAME_COUNTER);
        logFailedUsername($pdo, (int)$user['id'], $user['username'], $newUsername);
        $fieldError = ['field' => 'current_password', 'message' => 'Password saat ini salah.'];
    }

    if ($fieldError === null && ($policyError = validateNewUsername($newUsername)) !== null) {
        $fieldError = ['field' => 'new_username', 'message' => $policyError];
    }

    if ($fieldError === null && strcasecmp($newUsername, $user['username']) === 0) {
        $fieldError = ['field' => 'new_username', 'message' => 'Username baru sama dengan username saat ini.'];
    }

    if ($fieldError === null) {
        /* Keunikan: username UNIQUE; cek dulu agar pesan jelas. */
        $taken = findUserByUsername($pdo, $newUsername);
        if ($taken && (int)$taken['id'] !== (int)$user['id']) {
            $fieldError = ['field' => 'new_username', 'message' => 'Username baru sudah digunakan akun lain.'];
        }
    }

    if ($fieldError !== null) {
        errorResponse($fieldError['message'], 422, ['field' => $fieldError['field']]);
    }

    /* TRANSAKSI: username + riwayat tersimpan atomik. */
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare("UPDATE users SET username = ? WHERE user_id = ?");
        $update->execute([$newUsername, $user['id']]);

        writeAccountChangeLog($pdo, (int)$user['id'], 'username_change', 'profile', $user['username'], $newUsername, 'success');

        $pdo->commit();
    } catch (PDOException $txError) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        /* 23000 = pelanggaran UNIQUE/constraint bila balapan dengan request lain. */
        if (($txError->errorInfo[0] ?? '') === '23000') {
            errorResponse('Username baru sudah digunakan akun lain.', 422, ['field' => 'new_username']);
        }
        throw $txError;
    } catch (Throwable $txError) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $txError;
    }

    /* Sinkronkan sesi dan identitas di halaman agar header ikut berubah. */
    $_SESSION['username'] = $newUsername;
    accountClearFailures(USERNAME_COUNTER);

    successResponse(['username' => $newUsername], 'Username berhasil diperbarui.');
} catch (Throwable $e) {
    error_log('Ubah username: ' . $e->getMessage());
    errorResponse('Server error: username tidak diubah.', 500);
}
