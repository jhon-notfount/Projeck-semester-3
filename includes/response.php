<?php
/**
 * Hydrotech - JSON Response Helper
 * 
 * Standardized JSON response functions for all API endpoints.
 */

/**
 * Send JSON response with status code and exit
 * 
 * @param mixed $data Response data
 * @param int $statusCode HTTP status code
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send success response
 * 
 * @param mixed $data Optional data payload
 * @param string $message Success message
 */
function successResponse($data = null, $message = 'Success') {
    $response = ['success' => true, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    jsonResponse($response);
}

/**
 * Send error response
 * 
 * @param string $message Error message
 * @param int $statusCode HTTP status code
 */
function errorResponse($message = 'Error', $statusCode = 400) {
    jsonResponse(['success' => false, 'message' => $message], $statusCode);
}

/**
 * Validate required POST fields
 * 
 * @param array $fields List of required field names
 * @return array Validated data
 */
function validateRequired($fields) {
    $data = [];
    $missing = [];
    foreach ($fields as $field) {
        $value = $_POST[$field] ?? null;
        if ($value === null || $value === '') {
            $missing[] = $field;
        } else {
            $data[$field] = trim($value);
        }
    }
    if (!empty($missing)) {
        errorResponse('Missing required fields: ' . implode(', ', $missing));
    }
    return $data;
}

/**
 * Sanitize string input
 * 
 * @param string $input Raw input
 * @return string Sanitized input
 */
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

