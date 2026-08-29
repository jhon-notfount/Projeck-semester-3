<?php
/**
 * Hydrotech - Authentication Middleware
 * 
 * Include at top of protected pages and API endpoints.
 * Handles session validation and redirects/responses for unauthenticated users.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Require authentication - redirect to login (pages) or return 401 (API)
 */
function requireAuth() {
    if (!isset($_SESSION['user_id'])) {
        if (isApiRequest()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
            exit;
        }
        // Page request - redirect to login
        $loginUrl = getLoginUrl();
        header("Location: $loginUrl");
        exit;
    }
}

/**
 * Check if current request is an API call
 */
function isApiRequest() {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return strpos($uri, '/api/') !== false
        || strpos($accept, 'application/json') !== false
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET';
}

/**
 * Get login URL relative to current script location
 */
function getLoginUrl() {
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $scriptDir = dirname($scriptPath);

    // If we're in /pages/ directory
    if (basename($scriptDir) === 'pages') {
        return 'login.php';
    }
    // If we're in /api/ subdirectories (api/auth/, api/settings/, etc.)
    $depth = substr_count(str_replace('\\', '/', $scriptDir), '/api/');
    if ($depth > 0 || strpos($scriptDir, '/api') !== false) {
        // Calculate relative path back to pages/
        $parts = explode('/', trim(str_replace('\\', '/', $scriptDir), '/'));
        $apiIndex = array_search('api', $parts);
        if ($apiIndex !== false) {
            $levelsUp = count($parts) - $apiIndex;
            return str_repeat('../', $levelsUp) . 'pages/login.php';
        }
    }
    // Root level
    return 'pages/login.php';
}

/**
 * Check if user is currently logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Get current user info from session
 * @return array|null
 */
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'email'    => $_SESSION['email'] ?? '',
        'role'     => $_SESSION['role'] ?? 'admin',
    ];
}

