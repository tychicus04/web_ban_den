<?php
/**
 * CSRF Protection Helper
 * Provides functions to generate and validate CSRF tokens
 */

/**
 * Generate a CSRF token and store it in session
 * @return string The generated CSRF token
 */
function generateCSRFToken()
{
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Generate new token if it doesn't exist or is older than 1 hour
    if (empty($_SESSION['csrf_token']) ||
        empty($_SESSION['csrf_token_time']) ||
        (time() - $_SESSION['csrf_token_time']) > 3600) {

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token against the session token
 * @param string $token The token to validate
 * @return bool True if valid, false otherwise
 */
function validateCSRFToken($token)
{
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Check if token exists in session
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }

    // Check if token has expired (1 hour)
    if (empty($_SESSION['csrf_token_time']) ||
        (time() - $_SESSION['csrf_token_time']) > 3600) {
        return false;
    }

    // Use hash_equals to prevent timing attacks
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate a hidden input field with CSRF token
 * @return string HTML input field with CSRF token
 */
function csrfTokenField()
{
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Generate meta tag with CSRF token (for AJAX requests)
 * @return string HTML meta tag with CSRF token
 */
function csrfTokenMeta()
{
    $token = generateCSRFToken();
    return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verify CSRF token from POST request
 * Dies with error message if validation fails
 */
function verifyCsrfToken()
{
    $token = $_POST['csrf_token'] ?? '';

    if (!validateCSRFToken($token)) {
        http_response_code(403);
        die('CSRF token validation failed. Please refresh the page and try again.');
    }
}

/**
 * Get CSRF token for AJAX requests (as JSON)
 * @return string JSON encoded token
 */
function getCsrfTokenJson()
{
    header('Content-Type: application/json');
    echo json_encode([
        'csrf_token' => generateCSRFToken()
    ]);
}
?>
