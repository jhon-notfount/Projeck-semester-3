<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }
requireCsrfToken();

try {
    $pdo = getDBConnection();
    $userId = $_SESSION['user_id'];

    /* Status baca hanya untuk pesan yang berhak dilihat sesi ini:
       miliknya sendiri ATAU notifikasi sistem tanpa penerima spesifik.   */
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND (user_id IS NULL OR user_id = ?)");
    $stmt->execute([$userId]);

    successResponse(null, 'All notifications marked as read');
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
