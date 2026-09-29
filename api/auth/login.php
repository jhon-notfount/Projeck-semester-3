<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        errorResponse('Username and password are required', 400);
    }
    
    $stmt = $pdo->prepare("SELECT `id_pengguna` AS `id`, `nama_pengguna` AS `username`, `kata_sandi` AS `password`, `email`, `peran` AS `role`, `dibuat_pada` AS `created_at`, `diperbarui_pada` AS `updated_at` FROM users WHERE nama_pengguna = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        
        unset($user['password']);
        successResponse($user, 'Login successful');
    } else {
        errorResponse('Invalid credentials', 401);
    }
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
