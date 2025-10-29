<?php
/**
 * Admin Initialization & Authentication
 * Common initialization code for all admin pages
 *
 * This file consolidates admin session management, authentication,
 * and initialization code that was duplicated across 23+ admin files.
 *
 * @author TK-MALL Development Team
 * @version 2.0.0
 */

// Enable error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include required files
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../security-headers.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/ui_components.php';

// Set security headers
setSecurityHeaders();

/**
 * Check if user is authenticated as admin
 * Redirects to login page if not authenticated
 *
 * @param bool $exitOnFail Whether to exit if authentication fails (default: true)
 * @return bool True if authenticated, false otherwise
 */
function checkAdminAuth($exitOnFail = true)
{
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
        if ($exitOnFail) {
            header('Location: login.php?error=not_logged_in');
            exit;
        }
        return false;
    }

    // Check if user is admin
    if ($_SESSION['user_type'] !== 'admin') {
        if ($exitOnFail) {
            header('Location: login.php?error=unauthorized');
            exit;
        }
        return false;
    }

    // Check session timeout (8 hours)
    $session_timeout = 8 * 60 * 60; // 8 hours
    if (isset($_SESSION['admin_login_time'])) {
        if ((time() - $_SESSION['admin_login_time']) > $session_timeout) {
            session_destroy();
            if ($exitOnFail) {
                header('Location: login.php?error=session_timeout');
                exit;
            }
            return false;
        }
    }

    return true;
}

/**
 * Get admin info from database
 * Fetches complete admin information including role
 *
 * @return array|false Admin data or false on error
 */
function getAdminInfo()
{
    global $pdo;

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT u.*, s.id as staff_id, r.name as role_name
            FROM users u
            LEFT JOIN staff s ON u.id = s.user_id
            LEFT JOIN roles r ON s.role_id = r.id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $admin = $stmt->fetch();

        if (!$admin) {
            error_log("Admin user not found: " . $_SESSION['user_id']);
            return false;
        }

        return $admin;
    } catch (PDOException $e) {
        error_log("Error fetching admin info: " . $e->getMessage());
        return false;
    }
}

/**
 * Initialize admin CSRF token
 * Generates and stores admin CSRF token in session
 *
 * @return string CSRF token
 */
function initAdminCSRFToken()
{
    if (!isset($_SESSION['admin_token'])) {
        $_SESSION['admin_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_token'];
}

/**
 * Verify admin CSRF token
 *
 * @param string $token Token to verify
 * @return bool True if valid, false otherwise
 */
function verifyAdminCSRFToken($token)
{
    if (!isset($_SESSION['admin_token'])) {
        return false;
    }
    return hash_equals($_SESSION['admin_token'], $token);
}

/**
 * Get database connection
 * Wrapper for backward compatibility
 *
 * @return PDO Database connection
 */
function getDB()
{
    global $pdo;
    return $pdo;
}

/**
 * Initialize admin page
 * Complete initialization for admin pages
 *
 * @param bool $requireAuth Require authentication (default: true)
 * @param bool $fetchAdminInfo Fetch admin info from DB (default: false)
 * @return array|null Admin info if $fetchAdminInfo is true, null otherwise
 */
function initAdminPage($requireAuth = true, $fetchAdminInfo = false)
{
    // Check authentication
    if ($requireAuth) {
        checkAdminAuth(true);
    }

    // Initialize CSRF token
    initAdminCSRFToken();

    // Fetch admin info if requested
    if ($fetchAdminInfo) {
        $admin = getAdminInfo();
        if (!$admin && $requireAuth) {
            session_destroy();
            header('Location: login.php?error=user_not_found');
            exit;
        }
        return $admin;
    }

    return null;
}

/**
 * Check admin permission
 * Check if admin has specific permission
 *
 * @param string $permission Permission to check
 * @return bool True if has permission, false otherwise
 */
function hasAdminPermission($permission)
{
    // Implement permission checking logic here
    // For now, all authenticated admins have all permissions
    // TODO: Implement role-based access control
    return checkAdminAuth(false);
}

/**
 * Log admin action
 * Log admin actions for audit trail
 *
 * @param string $action Action description
 * @param string $details Additional details (optional)
 */
function logAdminAction($action, $details = '')
{
    global $pdo;

    if (!isset($_SESSION['user_id'])) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_logs (admin_id, action, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $stmt->execute([
            $_SESSION['user_id'],
            $action,
            $details,
            $ip_address
        ]);
    } catch (PDOException $e) {
        // Log to error log if database insert fails
        error_log("Failed to log admin action: " . $e->getMessage());
    }
}

/**
 * Get admin statistics
 * Get common statistics for admin dashboard
 *
 * @return array Statistics data
 */
function getAdminStats()
{
    global $pdo;

    try {
        // Total users
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'customer'");
        $total_users = $stmt->fetchColumn();

        // Total products
        $stmt = $pdo->query("SELECT COUNT(*) FROM products");
        $total_products = $stmt->fetchColumn();

        // Total orders
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders");
        $total_orders = $stmt->fetchColumn();

        // Total revenue
        $stmt = $pdo->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'completed'");
        $total_revenue = $stmt->fetchColumn();

        // Pending orders
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
        $pending_orders = $stmt->fetchColumn();

        return [
            'total_users' => $total_users,
            'total_products' => $total_products,
            'total_orders' => $total_orders,
            'total_revenue' => $total_revenue,
            'pending_orders' => $pending_orders
        ];
    } catch (PDOException $e) {
        error_log("Error fetching admin stats: " . $e->getMessage());
        return [
            'total_users' => 0,
            'total_products' => 0,
            'total_orders' => 0,
            'total_revenue' => 0,
            'pending_orders' => 0
        ];
    }
}

// Auto-initialize for pages that include this file
// Check authentication by default
if (!defined('NO_AUTO_INIT')) {
    checkAdminAuth(true);
}

// Initialize CSRF token
initAdminCSRFToken();
?>
