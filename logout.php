<?php
/**
 * Logout Script
 * Safely destroys user session and redirects to login page
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Store user type before destroying session (for redirect logic)
$user_type = $_SESSION['user_type'] ?? 'customer';

// Unset all session variables
$_SESSION = array();

// Delete session cookie if it exists
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Redirect based on user type
if ($user_type === 'admin') {
    header('Location: admin/login.php?message=logged_out');
} elseif ($user_type === 'seller') {
    header('Location: seller/login.php?message=logged_out');
} else {
    header('Location: login.php?message=logged_out');
}

exit;
?>
