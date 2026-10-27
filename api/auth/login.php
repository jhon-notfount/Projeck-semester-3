<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }
requireCsrfToken();

try {
    $pdo = getDBConnection();

    /* RATE LIMIT: pembatasan percobaan gagal per sesi (5 kali, jeda 60 detik).
       Sederhana dan efektif untuk skala aplikasi ini; tidak mengunci akun permanen. */
    $failures = (int)($_SESSION['login_failures'] ?? 0);
    $lastFail = (int)($_SESSION['login_last_fail'] ?? 0);
    if ($failures >= 5 && (time() - $lastFail) < 60) {
        errorResponse('Terlalu banyak percobaan login. Coba lagi dalam 1 menit.', 429);
    }

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        errorResponse('Username and password are required', 400);
    }

    $stmt = $pdo->prepare("SELECT `user_id` AS `id`, `username`, `password`, `email`, `created_at`, `updated_at` FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        unset($_SESSION['login_failures'], $_SESSION['login_last_fail']);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        /* Sesi yang lahir setelah ganti password terakhir dianggap sah. */
        $_SESSION['pwd_epoch'] = time();

        unset($user['password']);
        successResponse($user, 'Login successful');
    }

    // Gagal: catat percobaan + jeda kecil memperlambat brute force.
    $_SESSION['login_failures'] = $failures + 1;
    $_SESSION['login_last_fail'] = time();
    usleep(300000);
    errorResponse('Invalid credentials', 401);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
