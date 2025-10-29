<?php
/**
 * Response Helper Functions
 * Standardized JSON and HTTP response utilities
 *
 * This file provides consistent response formatting for API endpoints
 * and AJAX requests across the application.
 *
 * @author TK-MALL Development Team
 * @version 2.0.0
 */

/**
 * Send JSON response and exit
 *
 * @param mixed $data Data to send
 * @param int $statusCode HTTP status code (default: 200)
 */
function sendJson($data, $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send success JSON response
 *
 * @param mixed $data Response data (optional)
 * @param string $message Success message (optional)
 * @param int $statusCode HTTP status code (default: 200)
 */
function sendSuccess($data = null, $message = 'Success', $statusCode = 200)
{
    $response = [
        'success' => true,
        'message' => $message
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    sendJson($response, $statusCode);
}

/**
 * Send error JSON response
 *
 * @param string $message Error message
 * @param int $statusCode HTTP status code (default: 400)
 * @param array $errors Validation errors (optional)
 */
function sendError($message, $statusCode = 400, $errors = null)
{
    $response = [
        'success' => false,
        'error' => $message
    ];

    if ($errors !== null) {
        $response['errors'] = $errors;
    }

    sendJson($response, $statusCode);
}

/**
 * Send validation error response
 *
 * @param array $errors Validation errors
 * @param string $message Main error message (default: 'Validation failed')
 */
function sendValidationError($errors, $message = 'Validation failed')
{
    sendError($message, 422, $errors);
}

/**
 * Send unauthorized error response
 *
 * @param string $message Error message (default: 'Unauthorized')
 */
function sendUnauthorized($message = 'Unauthorized')
{
    sendError($message, 401);
}

/**
 * Send forbidden error response
 *
 * @param string $message Error message (default: 'Forbidden')
 */
function sendForbidden($message = 'Forbidden')
{
    sendError($message, 403);
}

/**
 * Send not found error response
 *
 * @param string $message Error message (default: 'Not found')
 */
function sendNotFound($message = 'Not found')
{
    sendError($message, 404);
}

/**
 * Send server error response
 *
 * @param string $message Error message (default: 'Internal server error')
 */
function sendServerError($message = 'Internal server error')
{
    sendError($message, 500);
}

/**
 * Send paginated response
 *
 * @param array $items Array of items
 * @param int $total Total number of items
 * @param int $page Current page
 * @param int $perPage Items per page
 * @param string $message Success message (optional)
 */
function sendPaginatedResponse($items, $total, $page, $perPage, $message = 'Success')
{
    $totalPages = ceil($total / $perPage);

    $response = [
        'success' => true,
        'message' => $message,
        'data' => $items,
        'pagination' => [
            'total' => (int)$total,
            'per_page' => (int)$perPage,
            'current_page' => (int)$page,
            'total_pages' => (int)$totalPages,
            'has_more' => $page < $totalPages
        ]
    ];

    sendJson($response);
}

/**
 * Send created response (HTTP 201)
 *
 * @param mixed $data Created resource data
 * @param string $message Success message (default: 'Created')
 */
function sendCreated($data = null, $message = 'Created')
{
    sendSuccess($data, $message, 201);
}

/**
 * Send no content response (HTTP 204)
 */
function sendNoContent()
{
    http_response_code(204);
    exit;
}

/**
 * Validate required fields in request
 *
 * @param array $data Request data ($_POST or $_GET)
 * @param array $required Array of required field names
 * @return array|bool Array of errors if validation fails, true if all valid
 */
function validateRequired($data, $required)
{
    $errors = [];

    foreach ($required as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $errors[$field] = "Trường {$field} là bắt buộc";
        }
    }

    return empty($errors) ? true : $errors;
}

/**
 * Validate email field
 *
 * @param string $email Email to validate
 * @param string $fieldName Field name for error message
 * @return string|bool Error message if invalid, true if valid
 */
function validateEmailField($email, $fieldName = 'email')
{
    if (empty($email)) {
        return "Trường {$fieldName} là bắt buộc";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Email không hợp lệ";
    }

    return true;
}

/**
 * Validate phone field (Vietnamese format)
 *
 * @param string $phone Phone number to validate
 * @param string $fieldName Field name for error message
 * @return string|bool Error message if invalid, true if valid
 */
function validatePhoneField($phone, $fieldName = 'phone')
{
    if (empty($phone)) {
        return "Trường {$fieldName} là bắt buộc";
    }

    if (!preg_match('/^0[0-9]{9}$/', $phone)) {
        return "Số điện thoại không hợp lệ (phải có 10 số và bắt đầu bằng 0)";
    }

    return true;
}

/**
 * Validate password strength
 *
 * @param string $password Password to validate
 * @param int $minLength Minimum length (default: 6)
 * @return string|bool Error message if invalid, true if valid
 */
function validatePasswordField($password, $minLength = 6)
{
    if (empty($password)) {
        return "Mật khẩu là bắt buộc";
    }

    if (strlen($password) < $minLength) {
        return "Mật khẩu phải có ít nhất {$minLength} ký tự";
    }

    return true;
}

/**
 * Validate integer field
 *
 * @param mixed $value Value to validate
 * @param string $fieldName Field name for error message
 * @param int $min Minimum value (optional)
 * @param int $max Maximum value (optional)
 * @return string|bool Error message if invalid, true if valid
 */
function validateIntField($value, $fieldName, $min = null, $max = null)
{
    if ($value === '' || $value === null) {
        return "Trường {$fieldName} là bắt buộc";
    }

    if (!is_numeric($value) || (int)$value != $value) {
        return "Trường {$fieldName} phải là số nguyên";
    }

    $intValue = (int)$value;

    if ($min !== null && $intValue < $min) {
        return "Trường {$fieldName} phải lớn hơn hoặc bằng {$min}";
    }

    if ($max !== null && $intValue > $max) {
        return "Trường {$fieldName} phải nhỏ hơn hoặc bằng {$max}";
    }

    return true;
}

/**
 * Validate float field
 *
 * @param mixed $value Value to validate
 * @param string $fieldName Field name for error message
 * @param float $min Minimum value (optional)
 * @param float $max Maximum value (optional)
 * @return string|bool Error message if invalid, true if valid
 */
function validateFloatField($value, $fieldName, $min = null, $max = null)
{
    if ($value === '' || $value === null) {
        return "Trường {$fieldName} là bắt buộc";
    }

    if (!is_numeric($value)) {
        return "Trường {$fieldName} phải là số";
    }

    $floatValue = (float)$value;

    if ($min !== null && $floatValue < $min) {
        return "Trường {$fieldName} phải lớn hơn hoặc bằng {$min}";
    }

    if ($max !== null && $floatValue > $max) {
        return "Trường {$fieldName} phải nhỏ hơn hoặc bằng {$max}";
    }

    return true;
}

/**
 * Check if request is POST method
 * Sends error response if not POST
 *
 * @param bool $sendError Whether to send error response (default: true)
 * @return bool True if POST, false otherwise
 */
function requirePostMethod($sendError = true)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($sendError) {
            sendError('Method not allowed', 405);
        }
        return false;
    }
    return true;
}

/**
 * Check if request is AJAX
 * Sends error response if not AJAX
 *
 * @param bool $sendError Whether to send error response (default: true)
 * @return bool True if AJAX, false otherwise
 */
function requireAjax($sendError = true)
{
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if (!$isAjax && $sendError) {
        sendError('AJAX request required', 400);
    }

    return $isAjax;
}

/**
 * Get JSON input from request body
 *
 * @param bool $assoc Return as associative array (default: true)
 * @return mixed Decoded JSON data
 */
function getJsonInput($assoc = true)
{
    $input = file_get_contents('php://input');
    return json_decode($input, $assoc);
}

/**
 * Clean and prepare data for database insertion
 *
 * @param array $data Input data
 * @param array $allowed Allowed fields
 * @return array Cleaned data
 */
function cleanData($data, $allowed)
{
    $cleaned = [];

    foreach ($allowed as $field) {
        if (isset($data[$field])) {
            $cleaned[$field] = is_string($data[$field])
                ? trim($data[$field])
                : $data[$field];
        }
    }

    return $cleaned;
}
?>
