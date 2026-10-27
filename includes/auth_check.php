<?php
/**
 * Hydrotech - Authentication Middleware
 * 
 * Include at top of protected pages and API endpoints.
 * Handles session validation and redirects/responses for unauthenticated users.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Hardening cookie sesi: HttpOnly (tak terbaca JS), SameSite=Lax (mitigasi CSRF),
    // Secure otomatis saat lewat HTTPS, strict mode menolak ID sesi yang tidak dikenal.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
    ]);
    session_start();
}

/* DATABASE: dimuat di scope file (bukan di dalam fungsi) agar variabel
   koneksi $DB_* terdaftar sebagai global. Halaman yang memanggil
   requireAuth() sebelum config/database.php tetap aman karena require_once
   bersifat idempoten. */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/csrf.php';

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

    /* SESSION INVALIDATION: setelah password diganti, semua sesi yang lahir
       sebelum perubahan ditolak (menanggapi kebutuhan keamanan akun). Sesi
       yang melakukan perubahan menyimpan pwd_epoch terbaru sehingga tetap
       sah. Pemeriksaan berupa lookup primary key yang murah; fail-open bila
       database bermasalah agar tidak merusak alur lain. */
    try {
        $pdoAuth = getDBConnection();
        $stmtPwd = $pdoAuth->prepare("SELECT password_changed_at FROM users WHERE user_id = ?");
        $stmtPwd->execute([$_SESSION['user_id']]);
        $pwdChangedAt = $stmtPwd->fetchColumn();
        if ($pwdChangedAt !== false && $pwdChangedAt !== null) {
            $changedEpoch = strtotime((string)$pwdChangedAt); // default TZ = Asia/Jakarta (database.php)
            $sessionEpoch = isset($_SESSION['pwd_epoch']) ? (int)$_SESSION['pwd_epoch'] : 0;
            if ($changedEpoch === false || $changedEpoch > $sessionEpoch + 2) {
                // Perubahan tercatat lebih baru daripada sesi ini -> sesi ditolak.
                session_unset();
                session_destroy();
                if (isApiRequest()) {
                    http_response_code(401);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => 'Sesi berakhir karena password diubah. Silakan login kembali.']);
                    exit;
                }
                header("Location: " . getLoginUrl());
                exit;
            }
            $_SESSION['pwd_epoch'] = max($sessionEpoch, $changedEpoch);
        }
    } catch (Throwable $e) {
        error_log('Session invalidation check skipped: ' . $e->getMessage());
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
    ];
}

