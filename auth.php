<?php
/**
 * Authentication Helper
 * Centralized authentication and authorization functions
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';

/**
 * Check if user is logged in
 * @return bool True if logged in, false otherwise
 */
function isLoggedIn()
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current user ID
 * @return int|null User ID or null if not logged in
 */
function getCurrentUserId()
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user type
 * @return string|null User type (customer, seller, admin) or null
 */
function getCurrentUserType()
{
    return $_SESSION['user_type'] ?? null;
}

/**
 * Get current user name
 * @return string|null User name or null
 */
function getCurrentUserName()
{
    return $_SESSION['user_name'] ?? null;
}

/**
 * Require login - redirect to login page if not authenticated
 * @param string $userType Optional: require specific user type (customer, seller, admin)
 * @param string $redirectTo Where to redirect if not authenticated
 */
function requireLogin($userType = null, $redirectTo = 'login.php')
{
    if (!isLoggedIn()) {
        header('Location: ' . $redirectTo);
        exit;
    }

    // Check user type if specified
    if ($userType !== null && getCurrentUserType() !== $userType) {
        header('Location: ' . $redirectTo . '?error=unauthorized');
        exit;
    }

    // Check session timeout (1 hour by default)
    $sessionTimeout = 3600; // 1 hour
    $loginTime = $_SESSION['login_time'] ?? time();

    if ((time() - $loginTime) > $sessionTimeout) {
        logout();
        header('Location: ' . $redirectTo . '?message=session_expired');
        exit;
    }
}

/**
 * Require admin access
 */
function requireAdmin()
{
    requireLogin('admin', 'admin/login.php');
}

/**
 * Require seller access
 */
function requireSeller()
{
    requireLogin('seller', 'seller/login.php');
}

/**
 * Require customer access
 */
function requireCustomer()
{
    requireLogin('customer', 'login.php');
}

/**
 * Check if user has specific user type
 * @param string $userType User type to check (customer, seller, admin)
 * @return bool True if user has the type, false otherwise
 */
function hasUserType($userType)
{
    return isLoggedIn() && getCurrentUserType() === $userType;
}

/**
 * Check if current user is admin
 * @return bool True if admin, false otherwise
 */
function isAdmin()
{
    return hasUserType('admin');
}

/**
 * Check if current user is seller
 * @return bool True if seller, false otherwise
 */
function isSeller()
{
    return hasUserType('seller');
}

/**
 * Check if current user is customer
 * @return bool True if customer, false otherwise
 */
function isCustomer()
{
    return hasUserType('customer');
}

/**
 * Login user - set session variables
 * @param array $user User data from database
 */
function loginUser($user)
{
    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    // Set session variables
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['login_time'] = time();

    // Reset login attempts if they exist
    unset($_SESSION['login_attempts']);
    unset($_SESSION['last_attempt_time']);
}

/**
 * Logout user - destroy session and redirect
 * @param string $redirectTo Where to redirect after logout
 */
function logout($redirectTo = 'login.php')
{
    // Unset all session variables
    $_SESSION = array();

    // Delete session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }

    // Destroy the session
    session_destroy();

    // Redirect
    if ($redirectTo) {
        header('Location: ' . $redirectTo . '?message=logged_out');
        exit;
    }
}

/**
 * Get user from database by ID
 * @param int $userId User ID
 * @return array|false User data or false if not found
 */
function getUserById($userId)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND banned = 0 LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error fetching user: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user from database by email
 * @param string $email User email
 * @return array|false User data or false if not found
 */
function getUserByEmail($email)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND banned = 0 LIMIT 1");
        $stmt->execute([$email]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error fetching user by email: " . $e->getMessage());
        return false;
    }
}

/**
 * Verify user password
 * @param string $password Plain text password
 * @param string $hashedPassword Hashed password from database
 * @return bool True if password matches, false otherwise
 */
function verifyPassword($password, $hashedPassword)
{
    return password_verify($password, $hashedPassword);
}

/**
 * Hash password
 * @param string $password Plain text password
 * @return string Hashed password
 */
function hashPassword($password)
{
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Check if email already exists in database
 * @param string $email Email to check
 * @return bool True if exists, false otherwise
 */
function emailExists($email)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Error checking email existence: " . $e->getMessage());
        return false;
    }
}

/**
 * Update last login time for user
 * @param int $userId User ID
 */
function updateLastLogin($userId)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        error_log("Error updating last login: " . $e->getMessage());
    }
}

/**
 * Log authentication attempt
 * @param string $email Email used for login
 * @param bool $success Whether login was successful
 * @param string $ipAddress IP address of user
 */
function logAuthAttempt($email, $success, $ipAddress = null)
{
    $ipAddress = $ipAddress ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $status = $success ? 'SUCCESS' : 'FAILED';
    $message = "Auth attempt for {$email} from {$ipAddress}: {$status}";
    error_log($message);
}

/**
 * Get user's IP address
 * @return string IP address
 */
function getUserIP()
{
    $ip = '';

    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    return $ip;
}

/**
 * Validate password strength
 * @param string $password Password to validate
 * @return array ['valid' => bool, 'message' => string]
 */
function validatePasswordStrength($password)
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Mật khẩu phải có ít nhất 8 ký tự';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Mật khẩu phải có ít nhất 1 chữ hoa';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Mật khẩu phải có ít nhất 1 chữ thường';
    }

    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Mật khẩu phải có ít nhất 1 số';
    }

    return [
        'valid' => empty($errors),
        'message' => implode('. ', $errors)
    ];
}
?>
