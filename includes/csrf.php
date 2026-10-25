<?php
/**
 * Hydrotech - CSRF Protection
 *
 * Token CSRF per sesi untuk seluruh endpoint POST berbasis login browser.
 * Endpoint perangkat (autentikasi API key, api/sensor/ingest.php) tidak
 * memakai mekanisme ini karena tidak berjalan atas cookie browser.
 *
 * Token dikirim dari frontend melalui field body `csrf_token` atau header
 * `X-CSRF-Token`, dan diambil dari <meta name="csrf-token"> pada halaman.
 */

function csrfToken(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfMetaTag(): string {
    return '<meta name="csrf-token" content="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrfToken(?string $token): bool {
    return is_string($token) && $token !== ''
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrfToken(): void {
    $token = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? null;
    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'CSRF token tidak valid. Muat ulang halaman lalu coba lagi.']);
        exit;
    }
}
