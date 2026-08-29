<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->query("SELECT * FROM profile ORDER BY section, sort_order");
    $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [
        'owner' => [],
        'team' => []
    ];
    
    foreach ($profiles as $profile) {
        if ($profile['section'] === 'owner') {
            $result['owner'][] = $profile;
        } elseif ($profile['section'] === 'team') {
            $result['team'][] = $profile;
        }
    }
    
    successResponse($result);
} catch (Exception $e) {
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
