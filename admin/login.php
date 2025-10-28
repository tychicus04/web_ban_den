<?php
// Security: Disable error display in production
// Errors will be logged instead of displayed
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

session_start();

// Include database config
require_once '../config.php';

$db = getDBConnection();

// Redirect if already logged in as admin
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
    header('Location: dashboard.php');
    exit;
}

$error_message = '';
$success_message = '';
$login_attempts = $_SESSION['admin_login_attempts'] ?? 0;
$last_attempt_time = $_SESSION['admin_last_attempt'] ?? 0;

// Rate limiting - max 5 attempts per 15 minutes
$max_attempts = 5;
$lockout_time = 15 * 60; // 15 minutes

if ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) {
    $remaining_time = $lockout_time - (time() - $last_attempt_time);
    $remaining_minutes = ceil($remaining_time / 60);
    $error_message = "Tài khoản đã bị khóa do quá nhiều lần đăng nhập sai. Vui lòng thử lại sau {$remaining_minutes} phút.";
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_login') {
    // Check rate limiting
    if ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) {
        $remaining_time = $lockout_time - (time() - $last_attempt_time);
        $remaining_minutes = ceil($remaining_time / 60);
        $error_message = "Tài khoản đã bị khóa do quá nhiều lần đăng nhập sai. Vui lòng thử lại sau {$remaining_minutes} phút.";
    } else {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['remember_me']);
        
        // Basic validation
        if (!$email) {
            $error_message = 'Vui lòng nhập email hợp lệ.';
        } elseif (empty($password)) {
            $error_message = 'Vui lòng nhập mật khẩu.';
        } elseif (strlen($password) < 6) {
            $error_message = 'Mật khẩu phải có ít nhất 6 ký tự.';
        } else {
            try {
                // Check if user exists and is admin
                $stmt = $db->prepare("
                    SELECT u.*, s.id as staff_id, r.name as role_name
                    FROM users u 
                    LEFT JOIN staff s ON u.id = s.user_id
                    LEFT JOIN roles r ON s.role_id = r.id
                    WHERE u.email = ? AND u.user_type = 'admin' AND u.banned = 0 
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $admin = $stmt->fetch();
                
                if ($admin && password_verify($password, $admin['password'])) {
                    // Successful login
                    
                    // Reset login attempts
                    unset($_SESSION['admin_login_attempts']);
                    unset($_SESSION['admin_last_attempt']);
                    
                    // Set session variables
                    $_SESSION['user_id'] = $admin['id'];
                    $_SESSION['user_type'] = 'admin';
                    $_SESSION['admin_name'] = $admin['name'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['admin_role'] = $admin['role_name'] ?? 'Administrator';
                    $_SESSION['staff_id'] = $admin['staff_id'];
                    $_SESSION['admin_login_time'] = time();
                    
                    // Security token for CSRF protection
                    $_SESSION['admin_token'] = bin2hex(random_bytes(32));
                    
                    // Set remember me cookie if requested
                    if ($remember_me) {
                        $remember_token = bin2hex(random_bytes(32));
                        setcookie('admin_remember_token', $remember_token, time() + (30 * 24 * 60 * 60), '/', '', true, true); // 30 days
                        
                        // Store remember token in database
                        $stmt = $db->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                        $stmt->execute([$remember_token, $admin['id']]);
                    }
                    
                    // Log successful login
                    error_log("Admin login successful: " . $admin['email'] . " at " . date('Y-m-d H:i:s'));
                    
                    // Update last login time
                    $stmt = $db->prepare("UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$admin['id']]);
                    
                    $success_message = 'Đăng nhập thành công! Đang chuyển hướng...';
                    
                    // Redirect after 1 second
                    header("Refresh: 1; url=dashboard.php");
                } else {
                    // Failed login
                    $_SESSION['admin_login_attempts'] = $login_attempts + 1;
                    $_SESSION['admin_last_attempt'] = time();
                    
                    // Log failed login attempt
                    error_log("Admin login failed: " . $email . " at " . date('Y-m-d H:i:s'));
                    
                    $remaining_attempts = $max_attempts - ($_SESSION['admin_login_attempts']);
                    if ($remaining_attempts > 0) {
                        $error_message = "Email hoặc mật khẩu không đúng. Còn lại {$remaining_attempts} lần thử.";
                    } else {
                        $error_message = "Quá nhiều lần đăng nhập sai. Tài khoản đã bị khóa 15 phút.";
                    }
                }
            } catch (PDOException $e) {
                error_log("Admin login database error: " . $e->getMessage());
                $error_message = 'Lỗi hệ thống. Vui lòng thử lại sau.';
            }
        }
    }
}

// Check remember me cookie
if (!isset($_SESSION['user_id']) && isset($_COOKIE['admin_remember_token'])) {
    try {
        $stmt = $db->prepare("
            SELECT u.*, s.id as staff_id, r.name as role_name
            FROM users u 
            LEFT JOIN staff s ON u.id = s.user_id
            LEFT JOIN roles r ON s.role_id = r.id
            WHERE u.remember_token = ? AND u.user_type = 'admin' AND u.banned = 0 
            LIMIT 1
        ");
        $stmt->execute([$_COOKIE['admin_remember_token']]);
        $admin = $stmt->fetch();
        
        if ($admin) {
            // Auto login
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['user_type'] = 'admin';
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_role'] = $admin['role_name'] ?? 'Administrator';
            $_SESSION['staff_id'] = $admin['staff_id'];
            $_SESSION['admin_login_time'] = time();
            $_SESSION['admin_token'] = bin2hex(random_bytes(32));
            
            header('Location: dashboard.php');
            exit;
        } else {
            // Invalid remember token, clear cookie
            setcookie('admin_remember_token', '', time() - 3600, '/', '', true, true);
        }
    } catch (PDOException $e) {
        error_log("Remember token check error: " . $e->getMessage());
    }
}

// Get business settings
function getBusinessSetting($db, $type, $default = '') {
    try {
        $stmt = $db->prepare("SELECT value FROM business_settings WHERE type = ? LIMIT 1");
        $stmt->execute([$type]);
        $result = $stmt->fetch();
        return $result ? $result['value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Đăng nhập quản trị viên <?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root {
            /* Enhanced Color System */
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --accent-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --warm-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --cool-gradient: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            
            /* Core Colors */
            --primary: #667eea;
            --primary-dark: #4c63d2;
            --primary-light: #8fa1f5;
            --secondary: #f5576c;
            --secondary-dark: #e23954;
            --secondary-light: #f7849a;
            --accent: #4facfe;
            --accent-dark: #2196f3;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --danger: #ff6b6b;
            
            /* Neutral Palette */
            --white: #ffffff;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            
            /* Semantic Colors */
            --text-primary: var(--gray-900);
            --text-secondary: var(--gray-600);
            --text-tertiary: var(--gray-500);
            --text-inverse: var(--white);
            --background: var(--gray-50);
            --surface: var(--white);
            --border: var(--gray-200);
            --border-light: var(--gray-100);
            
            /* Enhanced Shadows */
            --shadow-xs: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-2xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --shadow-colored: 0 10px 25px -5px rgba(102, 126, 234, 0.3);
            
            /* Spacing Scale */
            --space-1: 0.25rem;
            --space-2: 0.5rem;
            --space-3: 0.75rem;
            --space-4: 1rem;
            --space-5: 1.25rem;
            --space-6: 1.5rem;
            --space-8: 2rem;
            --space-10: 2.5rem;
            --space-12: 3rem;
            --space-16: 4rem;
            --space-20: 5rem;
            --space-24: 6rem;
            --space-32: 8rem;
            
            /* Typography Scale */
            --text-xs: 0.75rem;
            --text-sm: 0.875rem;
            --text-base: 1rem;
            --text-lg: 1.125rem;
            --text-xl: 1.25rem;
            --text-2xl: 1.5rem;
            --text-3xl: 1.875rem;
            --text-4xl: 2.25rem;
            --text-5xl: 3rem;
            --text-6xl: 3.75rem;
            
            /* Font Weights */
            --font-normal: 400;
            --font-medium: 500;
            --font-semibold: 600;
            --font-bold: 700;
            --font-extrabold: 800;
            --font-black: 900;
            
            /* Line Heights */
            --leading-normal: 1.5;
            --leading-tight: 1.25;
            --leading-snug: 1.375;
            
            /* Border Radius */
            --rounded: 0.25rem;
            --rounded-md: 0.375rem;
            --rounded-lg: 0.5rem;
            --rounded-xl: 0.75rem;
            --rounded-2xl: 1rem;
            --rounded-3xl: 1.5rem;
            --rounded-full: 9999px;
            
            /* Enhanced Transitions */
            --transition-all: all 150ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-fast: all 100ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-normal: all 200ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: all 300ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-bounce: all 400ms cubic-bezier(0.68, -0.55, 0.265, 1.55);
            --transition-spring: all 600ms cubic-bezier(0.175, 0.885, 0.32, 1.275);
            
            /* Font Families */
            --font-sans: 'Inter', 'Poppins', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans', sans-serif;
            --font-heading: 'Poppins', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        /* CSS Reset & Base Styles */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        html {
            scroll-behavior: smooth;
            text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        body {
            font-family: var(--font-sans);
            font-size: var(--text-base);
            line-height: var(--leading-normal);
            color: var(--text-primary);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(255, 255, 255, 0.05) 0%, transparent 50%);
            pointer-events: none;
        }
        
        body::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg width="60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"><g fill="none" fill-rule="evenodd"><g fill="rgba(255,255,255,0.03)" fill-opacity="0.4"><circle cx="30" cy="30" r="4"/></g></svg>');
            opacity: 0.6;
            animation: float 6s ease-in-out infinite;
            pointer-events: none;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-10px);
            }
        }
        
        /* Login Container */
        .login-container {
            width: 100%;
            max-width: 420px;
            padding: var(--space-6);
            position: relative;
            z-index: 10;
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--rounded-3xl);
            padding: var(--space-12);
            box-shadow: var(--shadow-2xl);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
        }
        
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }
        
        /* Logo Section */
        .login-header {
            text-align: center;
            margin-bottom: var(--space-10);
        }
        
        .logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: var(--space-6);
            text-decoration: none;
            transition: var(--transition-spring);
        }
        
        .logo:hover {
            transform: translateY(-2px);
        }
        
        .logo-icon {
            width: 60px;
            height: 60px;
            background: var(--primary-gradient);
            border-radius: var(--rounded-2xl);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            box-shadow: var(--shadow-colored);
            position: relative;
            overflow: hidden;
            margin-bottom: var(--space-4);
        }
        
        .logo-icon::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }
        
        .logo:hover .logo-icon::before {
            left: 100%;
        }
        
        .login-title {
            font-family: var(--font-heading);
            font-size: var(--text-3xl);
            font-weight: var(--font-bold);
            color: var(--text-primary);
            margin-bottom: var(--space-2);
        }
        
        .login-subtitle {
            color: var(--text-secondary);
            font-size: var(--text-base);
            font-weight: var(--font-medium);
        }
        
        /* Form Styles */
        .login-form {
            space-y: var(--space-6);
        }
        
        .form-group {
            margin-bottom: var(--space-6);
        }
        
        .form-label {
            display: block;
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            margin-bottom: var(--space-2);
        }
        
        .form-label.required::after {
            content: ' *';
            color: var(--error);
        }
        
        .form-input {
            width: 100%;
            padding: var(--space-4) var(--space-5);
            border: 2px solid var(--border);
            border-radius: var(--rounded-xl);
            font-size: var(--text-base);
            font-weight: var(--font-medium);
            background: var(--white);
            transition: var(--transition-normal);
            outline: none;
        }
        
        .form-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-1px);
        }
        
        .form-input:invalid {
            border-color: var(--error);
        }
        
        .form-input::placeholder {
            color: var(--text-tertiary);
            font-weight: var(--font-normal);
        }
        
        /* Password Input */
        .password-group {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: var(--space-4);
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: var(--text-lg);
            padding: var(--space-2);
            border-radius: var(--rounded);
            transition: var(--transition-normal);
        }
        
        .password-toggle:hover {
            color: var(--primary);
            background: rgba(102, 126, 234, 0.1);
        }
        
        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            margin: var(--space-6) 0;
        }
        
        .checkbox {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        
        .checkbox-label {
            font-size: var(--text-sm);
            color: var(--text-secondary);
            cursor: pointer;
            user-select: none;
        }
        
        /* Submit Button */
        .submit-btn {
            width: 100%;
            background: var(--primary-gradient);
            color: var(--white);
            border: none;
            padding: var(--space-4) var(--space-6);
            border-radius: var(--rounded-xl);
            font-size: var(--text-base);
            font-weight: var(--font-bold);
            cursor: pointer;
            transition: var(--transition-bounce);
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: var(--shadow-lg);
        }
        
        .submit-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.3s ease;
        }
        
        .submit-btn:hover::before {
            left: 100%;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: var(--shadow-xl);
        }
        
        .submit-btn:active {
            transform: translateY(0) scale(0.98);
        }
        
        .submit-btn:disabled {
            background: var(--gray-400);
            cursor: not-allowed;
            transform: none;
            box-shadow: var(--shadow-sm);
        }
        
        .submit-btn:disabled::before {
            display: none;
        }
        
        /* Loading State */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--white);
            animation: spin 1s ease-in-out infinite;
            margin-right: var(--space-2);
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Alert Messages */
        .alert {
            padding: var(--space-4) var(--space-5);
            border-radius: var(--rounded-lg);
            margin-bottom: var(--space-6);
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            display: flex;
            align-items: center;
            gap: var(--space-3);
            position: relative;
            overflow: hidden;
        }
        
        .alert::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .alert-error::before {
            background: #ef4444;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .alert-success::before {
            background: #10b981;
        }
        
        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .alert-warning::before {
            background: #f59e0b;
        }
        
        /* Security Notice */
        .security-notice {
            background: rgba(102, 126, 234, 0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
            border-radius: var(--rounded-lg);
            padding: var(--space-4);
            margin-top: var(--space-6);
            font-size: var(--text-sm);
            color: var(--primary-dark);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .security-notice::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--primary);
        }
        
        /* Footer */
        .login-footer {
            text-align: center;
            margin-top: var(--space-8);
            padding-top: var(--space-6);
            border-top: 1px solid var(--border-light);
        }
        
        .footer-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            transition: var(--transition-normal);
        }
        
        .footer-link:hover {
            color: var(--primary);
        }
        
        /* Responsive Design */
        @media (max-width: 480px) {
            .login-container {
                padding: var(--space-4);
            }
            
            .login-card {
                padding: var(--space-8);
            }
            
            .logo-icon {
                width: 50px;
                height: 50px;
                font-size: var(--text-xl);
            }
            
            .login-title {
                font-size: var(--text-2xl);
            }
        }
        
        /* Accessibility */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
        
        /* High contrast mode */
        @media (prefers-contrast: high) {
            .login-card {
                border: 2px solid var(--text-primary);
                background: var(--white);
            }
            
            .form-input {
                border: 2px solid var(--text-primary);
            }
        }
        
        /* Dark mode (for future implementation) */
        @media (prefers-color-scheme: dark) {
            :root {
                --text-primary: var(--white);
                --text-secondary: var(--gray-300);
                --text-tertiary: var(--gray-400);
                --background: var(--gray-900);
                --surface: var(--gray-800);
                --border: var(--gray-700);
                --border-light: var(--gray-600);
            }
        }
        
        /* Focus styles for accessibility */
        *:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }
        
        /* Print styles */
        @media print {
            body {
                background: white;
            }
            
            .login-card {
                box-shadow: none;
                border: 1px solid black;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Header -->
            <div class="login-header">
                <a href="../index.php" class="logo" aria-label="<?php echo htmlspecialchars($site_name); ?> trang chủ">
                    <div>
                        <div class="logo-icon">A</div>
                        <h1 class="login-title">Admin Panel</h1>
                        <p class="login-subtitle">Đăng nhập quản trị viên</p>
                    </div>
                </a>
            </div>

            <!-- Alert Messages -->
            <?php if ($error_message): ?>
                <div class="alert alert-error" role="alert" aria-live="polite">
                    <span>⚠️</span>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="alert alert-success" role="alert" aria-live="polite">
                    <span>✅</span>
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form class="login-form" method="POST" action="" autocomplete="on" novalidate>
                <input type="hidden" name="action" value="admin_login">
                
                <!-- Email Field -->
                <div class="form-group">
                    <label for="email" class="form-label required">Email quản trị viên</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-input"
                        placeholder="admin@example.com"
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        required
                        autocomplete="email"
                        autofocus
                        aria-describedby="email-help"
                        <?php echo ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) ? 'disabled' : ''; ?>
                    >
                </div>

                <!-- Password Field -->
                <div class="form-group">
                    <label for="password" class="form-label required">Mật khẩu</label>
                    <div class="password-group">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-input"
                            placeholder="Nhập mật khẩu"
                            required
                            autocomplete="current-password"
                            minlength="6"
                            aria-describedby="password-help"
                            <?php echo ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) ? 'disabled' : ''; ?>
                        >
                        <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Hiển thị/ẩn mật khẩu">
                            👁️
                        </button>
                    </div>
                </div>

                <!-- Remember Me -->
                <div class="checkbox-group">
                    <input 
                        type="checkbox" 
                        id="remember_me" 
                        name="remember_me" 
                        class="checkbox"
                        <?php echo isset($_POST['remember_me']) ? 'checked' : ''; ?>
                        <?php echo ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) ? 'disabled' : ''; ?>
                    >
                    <label for="remember_me" class="checkbox-label">
                        Ghi nhớ đăng nhập (30 ngày)
                    </label>
                </div>

                <!-- Submit Button -->
                <button 
                    type="submit" 
                    class="submit-btn"
                    id="login-btn"
                    <?php echo ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) ? 'disabled' : ''; ?>
                >
                    <span id="btn-text">Đăng nhập</span>
                </button>
            </form>

            <!-- Security Notice -->
            <div class="security-notice">
                <strong>🔒 Bảo mật:</strong> Phiên đăng nhập sẽ hết hạn sau 8 tiếng không hoạt động.
                <br>Chỉ sử dụng thiết bị đáng tin cậy để đăng nhập.
            </div>

            <!-- Footer -->
            <div class="login-footer">
                <a href="../index.php" class="footer-link">← Quay về trang chủ</a>
            </div>
        </div>
    </div>

    <script>
        // Enhanced password toggle functionality
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleBtn = document.querySelector('.password-toggle');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleBtn.innerHTML = '🙈';
                toggleBtn.setAttribute('aria-label', 'Ẩn mật khẩu');
            } else {
                passwordField.type = 'password';
                toggleBtn.innerHTML = '👁️';
                toggleBtn.setAttribute('aria-label', 'Hiển thị mật khẩu');
            }
        }

        // Enhanced form submission with loading state
        document.querySelector('.login-form').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('login-btn');
            const btnText = document.getElementById('btn-text');
            const originalText = btnText.textContent;
            
            // Validate form
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            
            if (!email) {
                e.preventDefault();
                showAlert('Vui lòng nhập email.', 'error');
                document.getElementById('email').focus();
                return;
            }
            
            if (!password) {
                e.preventDefault();
                showAlert('Vui lòng nhập mật khẩu.', 'error');
                document.getElementById('password').focus();
                return;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                showAlert('Mật khẩu phải có ít nhất 6 ký tự.', 'error');
                document.getElementById('password').focus();
                return;
            }
            
            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                showAlert('Vui lòng nhập email hợp lệ.', 'error');
                document.getElementById('email').focus();
                return;
            }
            
            // Show loading state
            submitBtn.disabled = true;
            btnText.innerHTML = '<div class="loading"></div>Đang đăng nhập...';
            
            // Re-enable button after timeout (fallback)
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.disabled = false;
                    btnText.textContent = originalText;
                }
            }, 10000);
        });

        // Enhanced alert system
        function showAlert(message, type = 'error') {
            // Remove existing alerts
            const existingAlerts = document.querySelectorAll('.alert');
            existingAlerts.forEach(alert => alert.remove());
            
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.setAttribute('role', 'alert');
            alertDiv.setAttribute('aria-live', 'polite');
            
            const icon = type === 'error' ? '⚠️' : type === 'success' ? '✅' : '💡';
            alertDiv.innerHTML = `
                <span>${icon}</span>
                <span>${message}</span>
            `;
            
            const form = document.querySelector('.login-form');
            form.parentNode.insertBefore(alertDiv, form);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Auto-focus management
        document.addEventListener('DOMContentLoaded', function() {
            const emailField = document.getElementById('email');
            const passwordField = document.getElementById('password');
            
            // Focus email field if empty, otherwise focus password
            if (emailField.value.trim() === '') {
                emailField.focus();
            } else {
                passwordField.focus();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt + Enter to submit form
            if (e.altKey && e.key === 'Enter') {
                e.preventDefault();
                document.querySelector('.login-form').dispatchEvent(new Event('submit'));
            }
            
            // Escape to clear form
            if (e.key === 'Escape') {
                document.getElementById('email').value = '';
                document.getElementById('password').value = '';
                document.getElementById('remember_me').checked = false;
                document.getElementById('email').focus();
            }
        });

        // Enhanced form validation
        function setupFormValidation() {
            const emailField = document.getElementById('email');
            const passwordField = document.getElementById('password');
            
            emailField.addEventListener('blur', function() {
                const email = this.value.trim();
                if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    this.style.borderColor = 'var(--error)';
                    this.setAttribute('aria-invalid', 'true');
                } else {
                    this.style.borderColor = '';
                    this.setAttribute('aria-invalid', 'false');
                }
            });
            
            passwordField.addEventListener('input', function() {
                if (this.value.length > 0 && this.value.length < 6) {
                    this.style.borderColor = 'var(--warning)';
                } else {
                    this.style.borderColor = '';
                }
            });
        }

        // Security features
        function initSecurityFeatures() {
            // Disable right-click context menu
            document.addEventListener('contextmenu', function(e) {
                e.preventDefault();
            });
            
            // Disable F12 and other developer tools shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.key === 'F12' || 
                    (e.ctrlKey && e.shiftKey && e.key === 'I') ||
                    (e.ctrlKey && e.shiftKey && e.key === 'C') ||
                    (e.ctrlKey && e.shiftKey && e.key === 'J') ||
                    (e.ctrlKey && e.key === 'U')) {
                    e.preventDefault();
                    return false;
                }
            });
            
            // Clear sensitive data on page unload
            window.addEventListener('beforeunload', function() {
                document.getElementById('password').value = '';
                if (sessionStorage) {
                    sessionStorage.clear();
                }
            });
        }

        // Session timeout warning
        function initSessionTimeout() {
            let sessionTimeout;
            const timeoutDuration = 30 * 60 * 1000; // 30 minutes
            
            function resetSessionTimeout() {
                clearTimeout(sessionTimeout);
                sessionTimeout = setTimeout(() => {
                    showAlert('Phiên làm việc sắp hết hạn. Vui lòng đăng nhập lại.', 'warning');
                }, timeoutDuration);
            }
            
            // Reset timeout on user activity
            ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
                document.addEventListener(event, resetSessionTimeout, { passive: true });
            });
            
            resetSessionTimeout();
        }

        // Performance monitoring
        function initPerformanceMonitoring() {
            if ('performance' in window) {
                window.addEventListener('load', function() {
                    setTimeout(() => {
                        const perfData = performance.getEntriesByType('navigation')[0];
                        const loadTime = perfData.loadEventEnd - perfData.loadEventStart;
                        
                        if (loadTime > 2000) {
                            console.warn('Login page load time is high:', loadTime + 'ms');
                        }
                    }, 1000);
                });
            }
        }

        // Initialize all features
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🔐 Admin Login System - Initializing...');
            
            setupFormValidation();
            initSecurityFeatures();
            initSessionTimeout();
            initPerformanceMonitoring();
            
            // Add visual feedback for form interactions
            document.querySelectorAll('.form-input').forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'translateY(-2px)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = '';
                });
            });

            // Auto-redirect if success message is shown
            <?php if ($success_message): ?>
                setTimeout(() => {
                    window.location.href = 'dashboard.php';
                }, 2000);
            <?php endif; ?>

            console.log('✅ Admin Login System - Ready!');
            console.log('🔒 Security features enabled | 📊 Performance monitoring active');
        });

        // Error handling
        window.addEventListener('error', function(e) {
            console.error('JavaScript error:', e.error);
            showAlert('Đã xảy ra lỗi không mong muốn. Vui lòng thử lại.', 'error');
        });

        window.addEventListener('unhandledrejection', function(e) {
            console.error('Unhandled promise rejection:', e.reason);
            showAlert('Đã xảy ra lỗi kết nối. Vui lòng kiểm tra mạng và thử lại.', 'error');
        });
    </script>
</body>
</html>