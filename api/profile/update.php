<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../includes/response.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { errorResponse('Method not allowed', 405); }

try {
    $pdo = getDBConnection();
    
    $section = $_POST['section'] ?? '';
    if (!in_array($section, ['owner', 'team'])) {
        errorResponse('Invalid section', 400);
    }
    
    $dataJson = $_POST['data'] ?? '[]';
    $data = json_decode($dataJson, true);
    
    if (!is_array($data)) {
        errorResponse('Invalid data format', 400);
    }
    
    $pdo->beginTransaction();
    
    $stmtDelete = $pdo->prepare("DELETE FROM profile WHERE section = ?");
    $stmtDelete->execute([$section]);
    
    $stmtInsert = $pdo->prepare("INSERT INTO profile (section, label, value, sort_order) VALUES (?, ?, ?, ?)");
    foreach ($data as $index => $item) {
        $label = $item['label'] ?? '';
        $value = $item['value'] ?? '';
        $stmtInsert->execute([$section, $label, $value, $index]);
    }
    
    $pdo->commit();
    successResponse(null, 'Profile updated successfully');
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    errorResponse('Server error: ' . $e->getMessage(), 500);
}
