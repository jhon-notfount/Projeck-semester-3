<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }

try {
    session_destroy();
    successResponse(null, 'Logged out successfully');
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
