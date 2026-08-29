<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

try {
    $loggedIn = isLoggedIn();
    $user = $loggedIn ? getCurrentUser() : null;
    successResponse(['logged_in' => $loggedIn, 'user' => $user]);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
