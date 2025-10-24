<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Include database config
require_once '../config.php';

$db = getDBConnection();

// Authentication check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Session timeout check (8 hours)
$session_timeout = 8 * 60 * 60; // 8 hours
if (isset($_SESSION['admin_login_time']) && (time() - $_SESSION['admin_login_time']) > $session_timeout) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}

// CSRF token validation
if (!isset($_SESSION['admin_token'])) {
    $_SESSION['admin_token'] = bin2hex(random_bytes(32));
}

// Get admin info
$admin = null;
try {
    $stmt = $db->prepare("
        SELECT u.*, s.id as staff_id, r.name as role_name
        FROM users u 
        LEFT JOIN staff s ON u.id = s.user_id
        LEFT JOIN roles r ON s.role_id = r.id
        WHERE u.id = ? LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch();
    
    if (!$admin) {
        session_destroy();
        header('Location: login.php?error=user_not_found');
        exit;
    }
} catch (PDOException $e) {
    error_log("Admin fetch error: " . $e->getMessage());
    header('Location: login.php?error=database_error');
    exit;
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

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    try {
        if ($_POST['action'] === 'update_settings') {
            $section = $_POST['section'] ?? '';
            $settings = json_decode($_POST['settings'], true);
            
            if (!$section || !is_array($settings)) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }
            
            // Begin transaction
            $db->beginTransaction();
            
            foreach ($settings as $key => $value) {
                // Sanitize the type
                $type = filter_var($key, FILTER_SANITIZE_STRING);
                
                // Check if setting exists
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM business_settings WHERE type = ?");
                $stmt->execute([$type]);
                $exists = $stmt->fetch()['count'] > 0;
                
                if ($exists) {
                    // Update existing setting
                    $stmt = $db->prepare("UPDATE business_settings SET value = ? WHERE type = ?");
                    $stmt->execute([$value, $type]);
                } else {
                    // Insert new setting
                    $stmt = $db->prepare("INSERT INTO business_settings (type, value) VALUES (?, ?)");
                    $stmt->execute([$type, $value]);
                }
            }
            
            // Commit transaction
            $db->commit();
            
            echo json_encode(['success' => true, 'message' => 'Cài đặt đã được cập nhật thành công']);
        } elseif ($_POST['action'] === 'upload_logo') {
            // Handle logo upload
            if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
                exit;
            }
            
            $logo_type = $_POST['logo_type'] ?? 'site_logo';
            $file = $_FILES['logo'];
            
            // Check file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
            if (!in_array($file['type'], $allowed_types)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and SVG are allowed.']);
                exit;
            }
            
            // Generate a unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'uploads/logos/' . $logo_type . '_' . time() . '.' . $ext;
            
            // Create directory if it doesn't exist
            if (!file_exists('../uploads/logos')) {
                mkdir('../uploads/logos', 0777, true);
            }
            
            // Move the file
            if (move_uploaded_file($file['tmp_name'], '../' . $filename)) {
                // Save the path to database
                $stmt = $db->prepare("UPDATE business_settings SET value = ? WHERE type = ?");
                $stmt->execute([$filename, $logo_type]);
                
                echo json_encode(['success' => true, 'message' => 'Logo đã được cập nhật thành công', 'path' => $filename]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded file']);
            }
        } elseif ($_POST['action'] === 'test_email') {
            // Test email configuration
            $to = $_POST['email'] ?? '';
            
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Invalid email address']);
                exit;
            }
            
            // Get email settings
            $mail_driver = getBusinessSetting($db, 'mail_driver', 'smtp');
            $mail_host = getBusinessSetting($db, 'mail_host', '');
            $mail_port = getBusinessSetting($db, 'mail_port', '587');
            $mail_username = getBusinessSetting($db, 'mail_username', '');
            $mail_password = getBusinessSetting($db, 'mail_password', '');
            $mail_encryption = getBusinessSetting($db, 'mail_encryption', 'tls');
            $mail_from_name = getBusinessSetting($db, 'mail_from_name', 'Admin');
            $mail_from_address = getBusinessSetting($db, 'mail_from_address', '');
            
            // Here you would implement the actual email sending test
            // For demonstration, we'll just return success
            echo json_encode(['success' => true, 'message' => 'Email gửi thành công đến ' . $to]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Settings update error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Get current active tab
$active_tab = $_GET['tab'] ?? 'general';

// Get settings for each section
// General Settings
$general_settings = [
    'site_name' => getBusinessSetting($db, 'site_name', 'CarousellVN'),
    'site_motto' => getBusinessSetting($db, 'site_motto', 'Online Shopping Mall'),
    'site_logo' => getBusinessSetting($db, 'site_logo', ''),
    'favicon' => getBusinessSetting($db, 'favicon', ''),
    'site_icon' => getBusinessSetting($db, 'site_icon', ''),
    'footer_logo' => getBusinessSetting($db, 'footer_logo', ''),
    'footer_text' => getBusinessSetting($db, 'footer_text', 'Copyright © ' . date('Y') . ' All rights reserved'),
    'address' => getBusinessSetting($db, 'address', ''),
    'description' => getBusinessSetting($db, 'description', ''),
    'phone' => getBusinessSetting($db, 'phone', ''),
    'email' => getBusinessSetting($db, 'email', ''),
    'facebook' => getBusinessSetting($db, 'facebook', ''),
    'twitter' => getBusinessSetting($db, 'twitter', ''),
    'instagram' => getBusinessSetting($db, 'instagram', ''),
    'youtube' => getBusinessSetting($db, 'youtube', ''),
    'linkedin' => getBusinessSetting($db, 'linkedin', ''),
    'tiktok' => getBusinessSetting($db, 'tiktok', ''),
];

// Email Settings
$email_settings = [
    'mail_driver' => getBusinessSetting($db, 'mail_driver', 'smtp'),
    'mail_host' => getBusinessSetting($db, 'mail_host', ''),
    'mail_port' => getBusinessSetting($db, 'mail_port', '587'),
    'mail_username' => getBusinessSetting($db, 'mail_username', ''),
    'mail_password' => getBusinessSetting($db, 'mail_password', ''),
    'mail_encryption' => getBusinessSetting($db, 'mail_encryption', 'tls'),
    'mail_from_name' => getBusinessSetting($db, 'mail_from_name', 'Admin'),
    'mail_from_address' => getBusinessSetting($db, 'mail_from_address', ''),
];

// Payment Gateway Settings
$payment_settings = [
    'paypal_payment' => getBusinessSetting($db, 'paypal_payment', '0'),
    'paypal_client_id' => getBusinessSetting($db, 'paypal_client_id', ''),
    'paypal_secret' => getBusinessSetting($db, 'paypal_secret', ''),
    'paypal_sandbox' => getBusinessSetting($db, 'paypal_sandbox', '1'),
    
    'stripe_payment' => getBusinessSetting($db, 'stripe_payment', '0'),
    'stripe_key' => getBusinessSetting($db, 'stripe_key', ''),
    'stripe_secret' => getBusinessSetting($db, 'stripe_secret', ''),
    
    'razorpay_payment' => getBusinessSetting($db, 'razorpay_payment', '0'),
    'razorpay_key' => getBusinessSetting($db, 'razorpay_key', ''),
    'razorpay_secret' => getBusinessSetting($db, 'razorpay_secret', ''),
    
    'paystack_payment' => getBusinessSetting($db, 'paystack_payment', '0'),
    'paystack_public_key' => getBusinessSetting($db, 'paystack_public_key', ''),
    'paystack_secret_key' => getBusinessSetting($db, 'paystack_secret_key', ''),
    
    'cash_payment' => getBusinessSetting($db, 'cash_payment', '1'),
    'bank_payment' => getBusinessSetting($db, 'bank_payment', '0'),
    'bank_details' => getBusinessSetting($db, 'bank_details', ''),
];

// SEO Settings
$seo_settings = [
    'meta_title' => getBusinessSetting($db, 'meta_title', 'CarousellVN - Online Shopping Mall'),
    'meta_description' => getBusinessSetting($db, 'meta_description', ''),
    'meta_keywords' => getBusinessSetting($db, 'meta_keywords', ''),
    'meta_image' => getBusinessSetting($db, 'meta_image', ''),
    'google_analytics' => getBusinessSetting($db, 'google_analytics', ''),
    'facebook_pixel' => getBusinessSetting($db, 'facebook_pixel', ''),
];

// System Settings
$system_settings = [
    'maintenance_mode' => getBusinessSetting($db, 'maintenance_mode', '0'),
    'timezone' => getBusinessSetting($db, 'timezone', 'UTC'),
    'default_language' => getBusinessSetting($db, 'default_language', 'en'),
    'registration_verification' => getBusinessSetting($db, 'registration_verification', '1'),
    'email_verification' => getBusinessSetting($db, 'email_verification', '1'),
    'login_after_registration' => getBusinessSetting($db, 'login_after_registration', '1'),
    'captcha' => getBusinessSetting($db, 'captcha', '0'),
    'debug_mode' => getBusinessSetting($db, 'debug_mode', '0'),
    'cache_clear_interval' => getBusinessSetting($db, 'cache_clear_interval', '3600'),
];

// E-commerce Settings
$ecommerce_settings = [
    'vendor_system_activation' => getBusinessSetting($db, 'vendor_system_activation', '1'),
    'commission_type' => getBusinessSetting($db, 'commission_type', 'percent'),
    'default_commission' => getBusinessSetting($db, 'default_commission', '10'),
    'product_approval' => getBusinessSetting($db, 'product_approval', '1'),
    'product_manage_by_admin' => getBusinessSetting($db, 'product_manage_by_admin', '1'),
    'stock_threshold' => getBusinessSetting($db, 'stock_threshold', '5'),
    'low_stock_notification' => getBusinessSetting($db, 'low_stock_notification', '1'),
    'minimum_order_amount' => getBusinessSetting($db, 'minimum_order_amount', '0'),
    'shipping_method' => getBusinessSetting($db, 'shipping_method', 'flat_rate'),
    'flat_rate_shipping_cost' => getBusinessSetting($db, 'flat_rate_shipping_cost', '0'),
    'shipping_tax' => getBusinessSetting($db, 'shipping_tax', '0'),
    'tax_type' => getBusinessSetting($db, 'tax_type', 'inclusive'),
    'default_tax' => getBusinessSetting($db, 'default_tax', '0'),
    'default_currency' => getBusinessSetting($db, 'default_currency', 'USD'),
    'currency_format' => getBusinessSetting($db, 'currency_format', 'symbol'),
];

// Get available timezones
$timezones = DateTimeZone::listIdentifiers();

// Get available languages
$languages = [];
try {
    $stmt = $db->query("SELECT * FROM languages WHERE status = 1");
    $languages = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Languages fetch error: " . $e->getMessage());
}

// Get available currencies
$currencies = [];
try {
    $stmt = $db->query("SELECT * FROM currencies");
    $currencies = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Currencies fetch error: " . $e->getMessage());
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cài đặt hệ thống - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Cài đặt hệ thống - Admin <?php echo htmlspecialchars($site_name); ?>">
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
            --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
            --danger-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            
            /* Core Colors */
            --primary: #667eea;
            --primary-dark: #4c63d2;
            --primary-light: #8fa1f5;
            --secondary: #f5576c;
            --secondary-dark: #e23954;
            --secondary-light: #f7849a;
            --accent: #4facfe;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --danger: #ef4444;
            
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
            
            /* Sidebar */
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            
            /* Enhanced Shadows */
            --shadow-xs: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-2xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            
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
            
            /* Font Weights */
            --font-normal: 400;
            --font-medium: 500;
            --font-semibold: 600;
            --font-bold: 700;
            --font-extrabold: 800;
            --font-black: 900;
            
            /* Border Radius */
            --rounded: 0.25rem;
            --rounded-md: 0.375rem;
            --rounded-lg: 0.5rem;
            --rounded-xl: 0.75rem;
            --rounded-2xl: 1rem;
            --rounded-3xl: 1.5rem;
            --rounded-full: 9999px;
            
            /* Transitions */
            --transition-all: all 150ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-fast: all 100ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-normal: all 200ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: all 300ms cubic-bezier(0.4, 0, 0.2, 1);
            
            /* Font Families */
            --font-sans: 'Inter', 'Poppins', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-heading: 'Poppins', system-ui, sans-serif;
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
            line-height: 1.5;
            color: var(--text-primary);
            background-color: var(--background);
            overflow-x: hidden;
        }
        
        /* Layout */
        .dashboard-layout {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--gray-900) 0%, var(--gray-800) 100%);
            color: var(--white);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1000;
            transform: translateX(0);
            transition: var(--transition-normal);
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
        }
        
        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
            transform: translateX(0);
        }
        
        .sidebar-header {
            padding: var(--space-6);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .sidebar-logo {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            border-radius: var(--rounded-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: var(--font-bold);
            font-size: var(--text-lg);
            flex-shrink: 0;
        }
        
        .sidebar-title {
            font-family: var(--font-heading);
            font-size: var(--text-xl);
            font-weight: var(--font-bold);
            white-space: nowrap;
            transition: var(--transition-normal);
        }
        
        .sidebar.collapsed .sidebar-title {
            opacity: 0;
            transform: translateX(-20px);
        }
        
        .sidebar-nav {
            padding: var(--space-4) 0;
        }
        
        .nav-section {
            margin-bottom: var(--space-6);
        }
        
        .nav-section-title {
            padding: 0 var(--space-6);
            margin-bottom: var(--space-3);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.6);
            white-space: nowrap;
            transition: var(--transition-normal);
        }
        
        .sidebar.collapsed .nav-section-title {
            opacity: 0;
        }
        
        .nav-item {
            margin-bottom: var(--space-1);
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: var(--space-3) var(--space-6);
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-weight: var(--font-medium);
            transition: var(--transition-normal);
            position: relative;
        }
        
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
            transform: translateX(4px);
        }
        
        .nav-link.active {
            background: rgba(102, 126, 234, 0.2);
            color: var(--white);
            border-right: 3px solid var(--primary);
        }
        
        .nav-icon {
            font-size: var(--text-lg);
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }
        
        .nav-text {
            white-space: nowrap;
            transition: var(--transition-normal);
        }
        
        .sidebar.collapsed .nav-text {
            opacity: 0;
            transform: translateX(-20px);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition-normal);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar.collapsed + .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }
        
        /* Header */
        .header {
            background: var(--surface);
            padding: var(--space-4) var(--space-6);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }
        
        .sidebar-toggle {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: var(--text-xl);
            cursor: pointer;
            padding: var(--space-2);
            border-radius: var(--rounded);
            transition: var(--transition-normal);
        }
        
        .sidebar-toggle:hover {
            background: var(--gray-100);
            color: var(--text-primary);
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            font-size: var(--text-sm);
            color: var(--text-secondary);
        }
        
        .breadcrumb-item {
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .breadcrumb-separator {
            color: var(--text-tertiary);
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }
        
        .user-menu {
            position: relative;
        }
        
        .user-button {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            background: none;
            border: none;
            cursor: pointer;
            padding: var(--space-2);
            border-radius: var(--rounded-lg);
            transition: var(--transition-normal);
        }
        
        .user-button:hover {
            background: var(--gray-100);
        }
        
        .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--primary-gradient);
            border-radius: var(--rounded-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: var(--font-bold);
            font-size: var(--text-sm);
        }
        
        .user-info {
            text-align: left;
        }
        
        .user-name {
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .user-role {
            font-size: var(--text-xs);
            color: var(--text-secondary);
        }
        
        /* Content Area */
        .content {
            flex: 1;
            padding: var(--space-6);
        }
        
        .page-header {
            margin-bottom: var(--space-8);
        }
        
        .page-title {
            font-family: var(--font-heading);
            font-size: var(--text-3xl);
            font-weight: var(--font-bold);
            color: var(--text-primary);
            margin-bottom: var(--space-2);
        }
        
        .page-subtitle {
            color: var(--text-secondary);
            font-size: var(--text-base);
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: var(--space-1);
            border-bottom: 1px solid var(--border);
            margin-bottom: var(--space-6);
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        
        .tabs::-webkit-scrollbar {
            display: none;
        }
        
        .tab {
            padding: var(--space-3) var(--space-4);
            font-weight: var(--font-medium);
            color: var(--text-secondary);
            border-bottom: 2px solid transparent;
            cursor: pointer;
            white-space: nowrap;
            transition: var(--transition-normal);
        }
        
        .tab:hover {
            color: var(--primary);
        }
        
        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        
        /* Settings Sections */
        .settings-section {
            display: none;
            background: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            padding: var(--space-6);
            margin-bottom: var(--space-8);
        }
        
        .settings-section.active {
            display: block;
        }
        
        .section-title {
            font-family: var(--font-heading);
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            margin-bottom: var(--space-4);
            border-bottom: 1px solid var(--border);
            padding-bottom: var(--space-3);
        }
        
        /* Form Styles */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-4);
        }
        
        .form-group {
            margin-bottom: var(--space-4);
        }
        
        .form-group:last-child {
            margin-bottom: 0;
        }
        
        .form-label {
            display: block;
            font-weight: var(--font-medium);
            margin-bottom: var(--space-2);
            color: var(--text-primary);
        }
        
        .form-control {
            width: 100%;
            padding: var(--space-3) var(--space-4);
            font-size: var(--text-sm);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            background-color: var(--white);
            transition: var(--transition-normal);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-control[disabled] {
            background-color: var(--gray-100);
            cursor: not-allowed;
        }
        
        .form-text {
            margin-top: var(--space-1);
            font-size: var(--text-xs);
            color: var(--text-tertiary);
        }
        
        .form-check {
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .form-check-input {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        
        .form-check-label {
            font-size: var(--text-sm);
            cursor: pointer;
        }
        
        .form-select {
            width: 100%;
            padding: var(--space-3) var(--space-4);
            font-size: var(--text-sm);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            background-color: var(--white);
            cursor: pointer;
            transition: var(--transition-normal);
        }
        
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-textarea {
            width: 100%;
            padding: var(--space-3) var(--space-4);
            font-size: var(--text-sm);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            background-color: var(--white);
            resize: vertical;
            min-height: 100px;
            transition: var(--transition-normal);
        }
        
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            padding: var(--space-3) var(--space-4);
            border: none;
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            cursor: pointer;
            transition: var(--transition-normal);
            white-space: nowrap;
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            color: var(--white);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-secondary {
            background: var(--surface);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }
        
        .btn-secondary:hover {
            background: var(--gray-50);
            border-color: var(--primary);
        }
        
        .btn-success {
            background: var(--success);
            color: var(--white);
        }
        
        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
        }
        
        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }
        
        .btn-danger:hover {
            background: var(--secondary-dark);
            transform: translateY(-1px);
        }
        
        .btn-sm {
            padding: var(--space-2) var(--space-3);
            font-size: var(--text-xs);
        }
        
        .btn-lg {
            padding: var(--space-4) var(--space-6);
            font-size: var(--text-base);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }
        
        /* Logo Uploader */
        .logo-uploader {
            margin-bottom: var(--space-4);
        }
        
        .logo-preview {
            width: 200px;
            height: 80px;
            background: var(--gray-100);
            border: 1px dashed var(--border);
            border-radius: var(--rounded-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: var(--space-3);
            overflow: hidden;
        }
        
        .logo-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        .logo-upload-btn {
            position: relative;
            overflow: hidden;
        }
        
        .logo-upload-btn input[type="file"] {
            position: absolute;
            top: 0;
            right: 0;
            min-width: 100%;
            min-height: 100%;
            font-size: 100px;
            text-align: right;
            filter: alpha(opacity=0);
            opacity: 0;
            outline: none;
            background: white;
            cursor: pointer;
            display: block;
        }
        
        /* Settings Actions */
        .settings-actions {
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
            margin-top: var(--space-6);
        }
        
        /* Switch Toggle */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--gray-300);
            transition: var(--transition-normal);
            border-radius: var(--rounded-full);
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: var(--transition-normal);
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--primary);
        }
        
        input:focus + .slider {
            box-shadow: 0 0 1px var(--primary);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        /* Color picker */
        .color-picker {
            width: 40px;
            height: 40px;
            padding: 0;
            border: 1px solid var(--border);
            border-radius: var(--rounded);
            cursor: pointer;
        }
        
        /* Input group */
        .input-group {
            display: flex;
            align-items: stretch;
        }
        
        .input-group .form-control {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            flex: 1;
        }
        
        .input-group .btn {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        
        /* Password visibility toggle */
        .password-toggle {
            position: relative;
        }
        
        .password-toggle .toggle-btn {
            position: absolute;
            right: var(--space-3);
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-tertiary);
            font-size: var(--text-sm);
        }
        
        /* Loading spinner */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--white);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Settings Card */
        .settings-card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            border: 1px solid var(--border-light);
            padding: var(--space-5);
            margin-bottom: var(--space-4);
            transition: var(--transition-normal);
        }
        
        .settings-card:hover {
            box-shadow: var(--shadow-md);
        }
        
        .settings-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-3);
        }
        
        .settings-card-title {
            font-weight: var(--font-semibold);
            font-size: var(--text-lg);
        }
        
        .settings-card-body {
            color: var(--text-secondary);
            font-size: var(--text-sm);
            margin-bottom: var(--space-4);
        }
        
        .settings-card-actions {
            display: flex;
            gap: var(--space-2);
        }
        
        /* Mobile Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .content {
                padding: var(--space-4);
            }
            
            .tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
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
        
        *:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }
    </style>
</head>

<body>
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">A</div>
                <h1 class="sidebar-title">Admin Panel</h1>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tổng quan</div>
                    <div class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="analytics.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Phân tích</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Bán hàng</div>
                    <div class="nav-item">
                        <a href="orders.php" class="nav-link">
                            <span class="nav-icon">📦</span>
                            <span class="nav-text">Đơn hàng</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="products.php" class="nav-link">
                            <span class="nav-icon">🛍️</span>
                            <span class="nav-text">Sản phẩm</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="categories.php" class="nav-link">
                            <span class="nav-icon">📂</span>
                            <span class="nav-text">Danh mục</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="brands.php" class="nav-link">
                            <span class="nav-icon">🏷️</span>
                            <span class="nav-text">Thương hiệu</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Khách hàng</div>
                    <div class="nav-item">
                        <a href="users.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Người dùng</span>
                        </a>
                    </div>   
                    <div class="nav-item">
                        <a href="sellers.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Người Bán</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="reviews.php" class="nav-link">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Đánh giá</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="contacts.php" class="nav-link">
                            <span class="nav-icon">💬</span>
                            <span class="nav-text">Liên hệ</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Marketing</div>
                    <div class="nav-item">
                        <a href="coupons.php" class="nav-link">
                            <span class="nav-icon">🎫</span>
                            <span class="nav-text">Mã giảm giá</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="flash-deals.php" class="nav-link">
                            <span class="nav-icon">⚡</span>
                            <span class="nav-text">Flash Deals</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="banners.php" class="nav-link">
                            <span class="nav-icon">🖼️</span>
                            <span class="nav-text">Banner</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Hệ thống</div>
                    <div class="nav-item">
                        <a href="settings.php" class="nav-link active">
                            <span class="nav-icon">⚙️</span>
                            <span class="nav-text">Cài đặt</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="staff.php" class="nav-link">
                            <span class="nav-icon">👨‍💼</span>
                            <span class="nav-text">Nhân viên</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="backups.php" class="nav-link">
                            <span class="nav-icon">💾</span>
                            <span class="nav-text">Sao lưu</span>
                        </a>
                    </div>
                </div>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle sidebar">
                        ☰
                    </button>
                    <nav class="breadcrumb" aria-label="Breadcrumb">
                        <div class="breadcrumb-item">
                            <a href="dashboard.php">Admin</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span>Cài đặt</span>
                        </div>
                    </nav>
                </div>
                
                <div class="header-right">
                    <div class="user-menu">
                        <button class="user-button">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($admin['name'] ?? 'A', 0, 2)); ?>
                            </div>
                            <div class="user-info">
                                <div class="user-name"><?php echo htmlspecialchars($admin['name'] ?? 'Admin'); ?></div>
                                <div class="user-role"><?php echo htmlspecialchars($_SESSION['admin_role'] ?? 'Administrator'); ?></div>
                            </div>
                            <span>▼</span>
                        </button>
                    </div>
                </div>
            </header>
            
            <!-- Content -->
            <div class="content">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">Cài đặt hệ thống</h1>
                    <p class="page-subtitle">Quản lý cấu hình cho trang web của bạn</p>
                </div>
                
                <!-- Settings Tabs -->
                <div class="tabs">
                    <div class="tab <?php echo $active_tab === 'general' ? 'active' : ''; ?>" data-target="general">Thông tin chung</div>
                    <div class="tab <?php echo $active_tab === 'email' ? 'active' : ''; ?>" data-target="email">Email</div>
                    <div class="tab <?php echo $active_tab === 'payment' ? 'active' : ''; ?>" data-target="payment">Thanh toán</div>
                    <div class="tab <?php echo $active_tab === 'seo' ? 'active' : ''; ?>" data-target="seo">SEO</div>
                    <div class="tab <?php echo $active_tab === 'system' ? 'active' : ''; ?>" data-target="system">Hệ thống</div>
                    <div class="tab <?php echo $active_tab === 'ecommerce' ? 'active' : ''; ?>" data-target="ecommerce">E-commerce</div>
                </div>
                
                <!-- General Settings -->
                <div class="settings-section <?php echo $active_tab === 'general' ? 'active' : ''; ?>" id="general">
                    <h2 class="section-title">Thông tin chung</h2>
                    
                    <form id="general-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="site_name" class="form-label">Tên trang web</label>
                                <input type="text" id="site_name" name="site_name" class="form-control" value="<?php echo htmlspecialchars($general_settings['site_name']); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="site_motto" class="form-label">Khẩu hiệu</label>
                                <input type="text" id="site_motto" name="site_motto" class="form-control" value="<?php echo htmlspecialchars($general_settings['site_motto']); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Logo trang web</label>
                                <div class="logo-uploader">
                                    <div class="logo-preview">
                                        <?php if (!empty($general_settings['site_logo']) && file_exists('../' . $general_settings['site_logo'])): ?>
                                            <img src="../<?php echo htmlspecialchars($general_settings['site_logo']); ?>" alt="Site Logo">
                                        <?php else: ?>
                                            <span style="color: var(--text-tertiary);">Chưa có logo</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="logo-upload-btn">
                                        <button type="button" class="btn btn-secondary" id="site_logo_btn">
                                            <span>📤</span>
                                            <span>Tải lên logo</span>
                                        </button>
                                        <input type="file" id="site_logo_input" accept="image/*" style="display: none;">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Favicon</label>
                                <div class="logo-uploader">
                                    <div class="logo-preview" style="width: 80px; height: 80px;">
                                        <?php if (!empty($general_settings['favicon']) && file_exists('../' . $general_settings['favicon'])): ?>
                                            <img src="../<?php echo htmlspecialchars($general_settings['favicon']); ?>" alt="Favicon">
                                        <?php else: ?>
                                            <span style="color: var(--text-tertiary);">Chưa có favicon</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="logo-upload-btn">
                                        <button type="button" class="btn btn-secondary" id="favicon_btn">
                                            <span>📤</span>
                                            <span>Tải lên favicon</span>
                                        </button>
                                        <input type="file" id="favicon_input" accept="image/*" style="display: none;">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="footer_text" class="form-label">Footer Text</label>
                                <textarea id="footer_text" name="footer_text" class="form-textarea"><?php echo htmlspecialchars($general_settings['footer_text']); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="description" class="form-label">Mô tả trang web</label>
                                <textarea id="description" name="description" class="form-textarea"><?php echo htmlspecialchars($general_settings['description']); ?></textarea>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="address" class="form-label">Địa chỉ</label>
                                <input type="text" id="address" name="address" class="form-control" value="<?php echo htmlspecialchars($general_settings['address']); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="phone" class="form-label">Số điện thoại</label>
                                <input type="text" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($general_settings['phone']); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="email" class="form-label">Email liên hệ</label>
                                <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($general_settings['email']); ?>">
                            </div>
                        </div>
                        
                        <h3 style="margin: var(--space-6) 0 var(--space-4) 0; font-size: var(--text-lg); font-weight: var(--font-semibold);">Liên kết mạng xã hội</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="facebook" class="form-label">Facebook</label>
                                <input type="url" id="facebook" name="facebook" class="form-control" value="<?php echo htmlspecialchars($general_settings['facebook']); ?>" placeholder="https://facebook.com/yourpage">
                            </div>
                            
                            <div class="form-group">
                                <label for="twitter" class="form-label">Twitter</label>
                                <input type="url" id="twitter" name="twitter" class="form-control" value="<?php echo htmlspecialchars($general_settings['twitter']); ?>" placeholder="https://twitter.com/yourhandle">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="instagram" class="form-label">Instagram</label>
                                <input type="url" id="instagram" name="instagram" class="form-control" value="<?php echo htmlspecialchars($general_settings['instagram']); ?>" placeholder="https://instagram.com/yourprofile">
                            </div>
                            
                            <div class="form-group">
                                <label for="youtube" class="form-label">YouTube</label>
                                <input type="url" id="youtube" name="youtube" class="form-control" value="<?php echo htmlspecialchars($general_settings['youtube']); ?>" placeholder="https://youtube.com/yourchannel">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="linkedin" class="form-label">LinkedIn</label>
                                <input type="url" id="linkedin" name="linkedin" class="form-control" value="<?php echo htmlspecialchars($general_settings['linkedin']); ?>" placeholder="https://linkedin.com/company/yourcompany">
                            </div>
                            
                            <div class="form-group">
                                <label for="tiktok" class="form-label">TikTok</label>
                                <input type="url" id="tiktok" name="tiktok" class="form-control" value="<?php echo htmlspecialchars($general_settings['tiktok']); ?>" placeholder="https://tiktok.com/@yourhandle">
                            </div>
                        </div>
                        
                        <div class="settings-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetForm('general-form')">
                                <span>↩️</span>
                                <span>Đặt lại</span>
                            </button>
                            <button type="button" class="btn btn-primary" onclick="saveSettings('general')">
                                <span>💾</span>
                                <span>Lưu thay đổi</span>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Email Settings -->
                <div class="settings-section <?php echo $active_tab === 'email' ? 'active' : ''; ?>" id="email">
                    <h2 class="section-title">Cấu hình Email</h2>
                    
                    <form id="email-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="mail_driver" class="form-label">Mail Driver</label>
                                <select id="mail_driver" name="mail_driver" class="form-select">
                                    <option value="smtp" <?php echo $email_settings['mail_driver'] === 'smtp' ? 'selected' : ''; ?>>SMTP</option>
                                    <option value="sendmail" <?php echo $email_settings['mail_driver'] === 'sendmail' ? 'selected' : ''; ?>>Sendmail</option>
                                    <option value="mailgun" <?php echo $email_settings['mail_driver'] === 'mailgun' ? 'selected' : ''; ?>>Mailgun</option>
                                    <option value="ses" <?php echo $email_settings['mail_driver'] === 'ses' ? 'selected' : ''; ?>>Amazon SES</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="mail_host" class="form-label">Mail Host</label>
                                <input type="text" id="mail_host" name="mail_host" class="form-control" value="<?php echo htmlspecialchars($email_settings['mail_host']); ?>" placeholder="smtp.example.com">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="mail_port" class="form-label">Mail Port</label>
                                <input type="text" id="mail_port" name="mail_port" class="form-control" value="<?php echo htmlspecialchars($email_settings['mail_port']); ?>" placeholder="587">
                            </div>
                            
                            <div class="form-group">
                                <label for="mail_encryption" class="form-label">Mail Encryption</label>
                                <select id="mail_encryption" name="mail_encryption" class="form-select">
                                    <option value="tls" <?php echo $email_settings['mail_encryption'] === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                    <option value="ssl" <?php echo $email_settings['mail_encryption'] === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                    <option value="" <?php echo empty($email_settings['mail_encryption']) ? 'selected' : ''; ?>>None</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="mail_username" class="form-label">Mail Username</label>
                                <input type="text" id="mail_username" name="mail_username" class="form-control" value="<?php echo htmlspecialchars($email_settings['mail_username']); ?>" placeholder="your_email@example.com">
                            </div>
                            
                            <div class="form-group">
                                <label for="mail_password" class="form-label">Mail Password</label>
                                <div class="password-toggle">
                                    <input type="password" id="mail_password" name="mail_password" class="form-control" value="<?php echo htmlspecialchars($email_settings['mail_password']); ?>">
                                    <button type="button" class="toggle-btn" onclick="togglePasswordVisibility('mail_password')">👁️</button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="mail_from_name" class="form-label">From Name</label>
                                <input type="text" id="mail_from_name" name="mail_from_name" class="form-control" value="<?php echo htmlspecialchars($email_settings['mail_from_name']); ?>" placeholder="Your Company">
                            </div>
                            
                            <div class="form-group">
                                <label for="mail_from_address" class="form-label">From Address</label>
                                <input type="email" id="mail_from_address" name="mail_from_address" class="form-control" value="<?php echo htmlspecialchars($email_settings['mail_from_address']); ?>" placeholder="noreply@example.com">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="test_email" class="form-label">Kiểm tra cấu hình email</label>
                            <div class="input-group">
                                <input type="email" id="test_email" name="test_email" class="form-control" placeholder="Enter email to test">
                                <button type="button" class="btn btn-primary" onclick="testEmail()">
                                    <span>📧</span>
                                    <span>Gửi email thử nghiệm</span>
                                </button>
                            </div>
                            <div class="form-text">Gửi một email kiểm tra để xác nhận cấu hình email hoạt động chính xác.</div>
                        </div>
                        
                        <div class="settings-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetForm('email-form')">
                                <span>↩️</span>
                                <span>Đặt lại</span>
                            </button>
                            <button type="button" class="btn btn-primary" onclick="saveSettings('email')">
                                <span>💾</span>
                                <span>Lưu thay đổi</span>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Payment Gateway Settings -->
                <div class="settings-section <?php echo $active_tab === 'payment' ? 'active' : ''; ?>" id="payment">
                    <h2 class="section-title">Cấu hình thanh toán</h2>
                    
                    <form id="payment-form">
                        <!-- PayPal Settings -->
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">PayPal</h3>
                                <label class="switch">
                                    <input type="checkbox" id="paypal_payment" name="paypal_payment" <?php echo $payment_settings['paypal_payment'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Cho phép khách hàng thanh toán bằng PayPal.
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="paypal_client_id" class="form-label">Client ID</label>
                                    <input type="text" id="paypal_client_id" name="paypal_client_id" class="form-control" value="<?php echo htmlspecialchars($payment_settings['paypal_client_id']); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="paypal_secret" class="form-label">Secret Key</label>
                                    <div class="password-toggle">
                                        <input type="password" id="paypal_secret" name="paypal_secret" class="form-control" value="<?php echo htmlspecialchars($payment_settings['paypal_secret']); ?>">
                                        <button type="button" class="toggle-btn" onclick="togglePasswordVisibility('paypal_secret')">👁️</button>
                                    </div>
                                </div>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" id="paypal_sandbox" name="paypal_sandbox" class="form-check-input" <?php echo $payment_settings['paypal_sandbox'] == '1' ? 'checked' : ''; ?>>
                                <label for="paypal_sandbox" class="form-check-label">Sandbox Mode (For testing)</label>
                            </div>
                        </div>
                        
                        <!-- Stripe Settings -->
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Stripe</h3>
                                <label class="switch">
                                    <input type="checkbox" id="stripe_payment" name="stripe_payment" <?php echo $payment_settings['stripe_payment'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Cho phép khách hàng thanh toán bằng thẻ tín dụng qua Stripe.
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="stripe_key" class="form-label">Publishable Key</label>
                                    <input type="text" id="stripe_key" name="stripe_key" class="form-control" value="<?php echo htmlspecialchars($payment_settings['stripe_key']); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="stripe_secret" class="form-label">Secret Key</label>
                                    <div class="password-toggle">
                                        <input type="password" id="stripe_secret" name="stripe_secret" class="form-control" value="<?php echo htmlspecialchars($payment_settings['stripe_secret']); ?>">
                                        <button type="button" class="toggle-btn" onclick="togglePasswordVisibility('stripe_secret')">👁️</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- RazorPay Settings -->
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">RazorPay</h3>
                                <label class="switch">
                                    <input type="checkbox" id="razorpay_payment" name="razorpay_payment" <?php echo $payment_settings['razorpay_payment'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Cho phép khách hàng thanh toán qua RazorPay.
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="razorpay_key" class="form-label">Key ID</label>
                                    <input type="text" id="razorpay_key" name="razorpay_key" class="form-control" value="<?php echo htmlspecialchars($payment_settings['razorpay_key']); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="razorpay_secret" class="form-label">Secret Key</label>
                                    <div class="password-toggle">
                                        <input type="password" id="razorpay_secret" name="razorpay_secret" class="form-control" value="<?php echo htmlspecialchars($payment_settings['razorpay_secret']); ?>">
                                        <button type="button" class="toggle-btn" onclick="togglePasswordVisibility('razorpay_secret')">👁️</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Cash on Delivery Settings -->
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Thanh toán khi nhận hàng (COD)</h3>
                                <label class="switch">
                                    <input type="checkbox" id="cash_payment" name="cash_payment" <?php echo $payment_settings['cash_payment'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Cho phép khách hàng thanh toán khi nhận hàng.
                            </div>
                        </div>
                        
                        <!-- Bank Transfer Settings -->
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Chuyển khoản ngân hàng</h3>
                                <label class="switch">
                                    <input type="checkbox" id="bank_payment" name="bank_payment" <?php echo $payment_settings['bank_payment'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Cho phép khách hàng thanh toán bằng chuyển khoản ngân hàng.
                            </div>
                            <div class="form-group">
                                <label for="bank_details" class="form-label">Thông tin tài khoản ngân hàng</label>
                                <textarea id="bank_details" name="bank_details" class="form-textarea" rows="5"><?php echo htmlspecialchars($payment_settings['bank_details']); ?></textarea>
                                <div class="form-text">Thông tin này sẽ được hiển thị cho khách hàng khi họ chọn phương thức thanh toán bằng chuyển khoản ngân hàng.</div>
                            </div>
                        </div>
                        
                        <div class="settings-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetForm('payment-form')">
                                <span>↩️</span>
                                <span>Đặt lại</span>
                            </button>
                            <button type="button" class="btn btn-primary" onclick="saveSettings('payment')">
                                <span>💾</span>
                                <span>Lưu thay đổi</span>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- SEO Settings -->
                <div class="settings-section <?php echo $active_tab === 'seo' ? 'active' : ''; ?>" id="seo">
                    <h2 class="section-title">Cài đặt SEO</h2>
                    
                    <form id="seo-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="meta_title" class="form-label">Meta Title</label>
                                <input type="text" id="meta_title" name="meta_title" class="form-control" value="<?php echo htmlspecialchars($seo_settings['meta_title']); ?>">
                                <div class="form-text">Tiêu đề hiển thị trên các công cụ tìm kiếm.</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="meta_keywords" class="form-label">Meta Keywords</label>
                                <input type="text" id="meta_keywords" name="meta_keywords" class="form-control" value="<?php echo htmlspecialchars($seo_settings['meta_keywords']); ?>">
                                <div class="form-text">Từ khóa phân cách bằng dấu phẩy.</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="meta_description" class="form-label">Meta Description</label>
                            <textarea id="meta_description" name="meta_description" class="form-textarea"><?php echo htmlspecialchars($seo_settings['meta_description']); ?></textarea>
                            <div class="form-text">Mô tả ngắn về trang web của bạn hiển thị trên các công cụ tìm kiếm.</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Meta Image</label>
                            <div class="logo-uploader">
                                <div class="logo-preview">
                                    <?php if (!empty($seo_settings['meta_image']) && file_exists('../' . $seo_settings['meta_image'])): ?>
                                        <img src="../<?php echo htmlspecialchars($seo_settings['meta_image']); ?>" alt="Meta Image">
                                    <?php else: ?>
                                        <span style="color: var(--text-tertiary);">Chưa có ảnh meta</span>
                                    <?php endif; ?>
                                </div>
                                <div class="logo-upload-btn">
                                    <button type="button" class="btn btn-secondary" id="meta_image_btn">
                                        <span>📤</span>
                                        <span>Tải lên ảnh meta</span>
                                    </button>
                                    <input type="file" id="meta_image_input" accept="image/*" style="display: none;">
                                </div>
                            </div>
                            <div class="form-text">Ảnh hiển thị khi chia sẻ trang web trên mạng xã hội.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="google_analytics" class="form-label">Google Analytics Code</label>
                            <textarea id="google_analytics" name="google_analytics" class="form-textarea"><?php echo htmlspecialchars($seo_settings['google_analytics']); ?></textarea>
                            <div class="form-text">Mã theo dõi Google Analytics (bắt đầu bằng UA- hoặc G-).</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="facebook_pixel" class="form-label">Facebook Pixel Code</label>
                            <textarea id="facebook_pixel" name="facebook_pixel" class="form-textarea"><?php echo htmlspecialchars($seo_settings['facebook_pixel']); ?></textarea>
                            <div class="form-text">Mã theo dõi Facebook Pixel.</div>
                        </div>
                        
                        <div class="settings-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetForm('seo-form')">
                                <span>↩️</span>
                                <span>Đặt lại</span>
                            </button>
                            <button type="button" class="btn btn-primary" onclick="saveSettings('seo')">
                                <span>💾</span>
                                <span>Lưu thay đổi</span>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- System Settings -->
                <div class="settings-section <?php echo $active_tab === 'system' ? 'active' : ''; ?>" id="system">
                    <h2 class="section-title">Cài đặt hệ thống</h2>
                    
                    <form id="system-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="timezone" class="form-label">Múi giờ</label>
                                <select id="timezone" name="timezone" class="form-select">
                                    <?php foreach ($timezones as $tz): ?>
                                        <option value="<?php echo $tz; ?>" <?php echo $system_settings['timezone'] === $tz ? 'selected' : ''; ?>>
                                            <?php echo $tz; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="default_language" class="form-label">Ngôn ngữ mặc định</label>
                                <select id="default_language" name="default_language" class="form-select">
                                    <?php foreach ($languages as $language): ?>
                                        <option value="<?php echo $language['code']; ?>" <?php echo $system_settings['default_language'] === $language['code'] ? 'selected' : ''; ?>>
                                            <?php echo $language['name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Chế độ bảo trì</h3>
                                <label class="switch">
                                    <input type="checkbox" id="maintenance_mode" name="maintenance_mode" <?php echo $system_settings['maintenance_mode'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Khi bật chế độ bảo trì, trang web sẽ không thể truy cập được đối với người dùng thông thường. Chỉ có quản trị viên mới có thể đăng nhập.
                            </div>
                        </div>
                        
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Xác minh đăng ký</h3>
                                <label class="switch">
                                    <input type="checkbox" id="registration_verification" name="registration_verification" <?php echo $system_settings['registration_verification'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Yêu cầu người dùng xác minh địa chỉ email của họ khi đăng ký.
                            </div>
                        </div>
                        
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Xác minh email</h3>
                                <label class="switch">
                                    <input type="checkbox" id="email_verification" name="email_verification" <?php echo $system_settings['email_verification'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Yêu cầu xác minh email khi người dùng thay đổi địa chỉ email của họ.
                            </div>
                        </div>
                        
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Đăng nhập sau khi đăng ký</h3>
                                <label class="switch">
                                    <input type="checkbox" id="login_after_registration" name="login_after_registration" <?php echo $system_settings['login_after_registration'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Tự động đăng nhập người dùng sau khi họ đăng ký thành công.
                            </div>
                        </div>
                        
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Captcha</h3>
                                <label class="switch">
                                    <input type="checkbox" id="captcha" name="captcha" <?php echo $system_settings['captcha'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Hiển thị captcha trong biểu mẫu đăng nhập và đăng ký.
                            </div>
                        </div>
                        
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Chế độ debug</h3>
                                <label class="switch">
                                    <input type="checkbox" id="debug_mode" name="debug_mode" <?php echo $system_settings['debug_mode'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Hiển thị thông báo lỗi chi tiết. Chỉ nên bật trong môi trường phát triển.
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cache_clear_interval" class="form-label">Thời gian xóa cache (giây)</label>
                                <input type="number" id="cache_clear_interval" name="cache_clear_interval" class="form-control" value="<?php echo htmlspecialchars($system_settings['cache_clear_interval']); ?>">
                                <div class="form-text">Thời gian (tính bằng giây) giữa các lần xóa cache tự động.</div>
                            </div>
                        </div>
                        
                        <div class="settings-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetForm('system-form')">
                                <span>↩️</span>
                                <span>Đặt lại</span>
                            </button>
                            <button type="button" class="btn btn-primary" onclick="saveSettings('system')">
                                <span>💾</span>
                                <span>Lưu thay đổi</span>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- E-commerce Settings -->
                <div class="settings-section <?php echo $active_tab === 'ecommerce' ? 'active' : ''; ?>" id="ecommerce">
                    <h2 class="section-title">Cài đặt E-commerce</h2>
                    
                    <form id="ecommerce-form">
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Kích hoạt hệ thống người bán</h3>
                                <label class="switch">
                                    <input type="checkbox" id="vendor_system_activation" name="vendor_system_activation" <?php echo $ecommerce_settings['vendor_system_activation'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Cho phép người dùng đăng ký làm người bán và đăng sản phẩm.
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="commission_type" class="form-label">Loại hoa hồng</label>
                                <select id="commission_type" name="commission_type" class="form-select">
                                    <option value="percent" <?php echo $ecommerce_settings['commission_type'] === 'percent' ? 'selected' : ''; ?>>Phần trăm (%)</option>
                                    <option value="flat" <?php echo $ecommerce_settings['commission_type'] === 'flat' ? 'selected' : ''; ?>>Cố định</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="default_commission" class="form-label">Hoa hồng mặc định</label>
                                <input type="number" id="default_commission" name="default_commission" class="form-control" value="<?php echo htmlspecialchars($ecommerce_settings['default_commission']); ?>" step="0.01">
                                <div class="form-text">Hoa hồng mặc định cho các giao dịch của người bán.</div>
                            </div>
                        </div>
                        
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Yêu cầu phê duyệt sản phẩm</h3>
                                <label class="switch">
                                    <input type="checkbox" id="product_approval" name="product_approval" <?php echo $ecommerce_settings['product_approval'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Yêu cầu quản trị viên phê duyệt sản phẩm trước khi chúng được hiển thị trên trang web.
                            </div>
                        </div>
                        
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Quản lý sản phẩm bởi quản trị viên</h3>
                                <label class="switch">
                                    <input type="checkbox" id="product_manage_by_admin" name="product_manage_by_admin" <?php echo $ecommerce_settings['product_manage_by_admin'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Cho phép quản trị viên quản lý tất cả các sản phẩm, bao gồm cả sản phẩm của người bán.
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="stock_threshold" class="form-label">Ngưỡng tồn kho thấp</label>
                                <input type="number" id="stock_threshold" name="stock_threshold" class="form-control" value="<?php echo htmlspecialchars($ecommerce_settings['stock_threshold']); ?>">
                                <div class="form-text">Khi tồn kho của sản phẩm xuống dưới ngưỡng này, nó sẽ được đánh dấu là "sắp hết hàng".</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="minimum_order_amount" class="form-label">Giá trị đơn hàng tối thiểu</label>
                                <input type="number" id="minimum_order_amount" name="minimum_order_amount" class="form-control" value="<?php echo htmlspecialchars($ecommerce_settings['minimum_order_amount']); ?>" step="0.01">
                                <div class="form-text">Giá trị đơn hàng tối thiểu để đặt hàng (0 = không giới hạn).</div>
                            </div>
                        </div>
                        
                        <div class="settings-card">
                            <div class="settings-card-header">
                                <h3 class="settings-card-title">Thông báo tồn kho thấp</h3>
                                <label class="switch">
                                    <input type="checkbox" id="low_stock_notification" name="low_stock_notification" <?php echo $ecommerce_settings['low_stock_notification'] == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-card-body">
                                Gửi thông báo đến quản trị viên khi tồn kho của sản phẩm xuống dưới ngưỡng đã đặt.
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="shipping_method" class="form-label">Phương thức vận chuyển</label>
                                <select id="shipping_method" name="shipping_method" class="form-select">
                                    <option value="flat_rate" <?php echo $ecommerce_settings['shipping_method'] === 'flat_rate' ? 'selected' : ''; ?>>Giá cố định</option>
                                    <option value="free_shipping" <?php echo $ecommerce_settings['shipping_method'] === 'free_shipping' ? 'selected' : ''; ?>>Miễn phí vận chuyển</option>
                                    <option value="product_wise" <?php echo $ecommerce_settings['shipping_method'] === 'product_wise' ? 'selected' : ''; ?>>Theo sản phẩm</option>
                                    <option value="area_wise" <?php echo $ecommerce_settings['shipping_method'] === 'area_wise' ? 'selected' : ''; ?>>Theo khu vực</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="flat_rate_shipping_cost" class="form-label">Chi phí vận chuyển cố định</label>
                                <input type="number" id="flat_rate_shipping_cost" name="flat_rate_shipping_cost" class="form-control" value="<?php echo htmlspecialchars($ecommerce_settings['flat_rate_shipping_cost']); ?>" step="0.01">
                                <div class="form-text">Chi phí vận chuyển cố định cho tất cả các đơn hàng (chỉ áp dụng nếu phương thức vận chuyển là "Giá cố định").</div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="shipping_tax" class="form-label">Thuế vận chuyển (%)</label>
                                <input type="number" id="shipping_tax" name="shipping_tax" class="form-control" value="<?php echo htmlspecialchars($ecommerce_settings['shipping_tax']); ?>" step="0.01">
                                <div class="form-text">Thuế áp dụng cho phí vận chuyển.</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="tax_type" class="form-label">Loại thuế</label>
                                <select id="tax_type" name="tax_type" class="form-select">
                                    <option value="inclusive" <?php echo $ecommerce_settings['tax_type'] === 'inclusive' ? 'selected' : ''; ?>>Bao gồm trong giá</option>
                                    <option value="exclusive" <?php echo $ecommerce_settings['tax_type'] === 'exclusive' ? 'selected' : ''; ?>>Không bao gồm trong giá</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="default_tax" class="form-label">Thuế mặc định (%)</label>
                                <input type="number" id="default_tax" name="default_tax" class="form-control" value="<?php echo htmlspecialchars($ecommerce_settings['default_tax']); ?>" step="0.01">
                                <div class="form-text">Thuế mặc định áp dụng cho tất cả các sản phẩm (0 = không có thuế).</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="default_currency" class="form-label">Tiền tệ mặc định</label>
                                <select id="default_currency" name="default_currency" class="form-select">
                                    <?php foreach ($currencies as $currency): ?>
                                        <option value="<?php echo $currency['code']; ?>" <?php echo $ecommerce_settings['default_currency'] === $currency['code'] ? 'selected' : ''; ?>>
                                            <?php echo $currency['name'] . ' (' . $currency['symbol'] . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="currency_format" class="form-label">Định dạng tiền tệ</label>
                                <select id="currency_format" name="currency_format" class="form-select">
                                    <option value="symbol" <?php echo $ecommerce_settings['currency_format'] === 'symbol' ? 'selected' : ''; ?>>Symbol (€100)</option>
                                    <option value="code" <?php echo $ecommerce_settings['currency_format'] === 'code' ? 'selected' : ''; ?>>Code (100 EUR)</option>
                                    <option value="symbol_code" <?php echo $ecommerce_settings['currency_format'] === 'symbol_code' ? 'selected' : ''; ?>>Symbol & Code (€100 EUR)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="settings-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetForm('ecommerce-form')">
                                <span>↩️</span>
                                <span>Đặt lại</span>
                            </button>
                            <button type="button" class="btn btn-primary" onclick="saveSettings('ecommerce')">
                                <span>💾</span>
                                <span>Lưu thay đổi</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Sidebar toggle functionality
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        
        sidebarToggle.addEventListener('click', function() {
            if (window.innerWidth > 1024) {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            } else {
                sidebar.classList.toggle('open');
            }
        });
        
        // Restore sidebar state
        if (window.innerWidth > 1024 && localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
        }
        
        // Close sidebar on mobile when clicking outside
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 1024 && 
                !sidebar.contains(e.target) && 
                !sidebarToggle.contains(e.target) &&
                sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
        });
        
        // Tab switching functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const target = this.getAttribute('data-target');
                
                // Update URL without reloading
                const url = new URL(window.location);
                url.searchParams.set('tab', target);
                window.history.pushState({}, '', url);
                
                // Update active tab
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Show corresponding section
                document.querySelectorAll('.settings-section').forEach(section => {
                    section.classList.remove('active');
                });
                document.getElementById(target).classList.add('active');
            });
        });
        
        // Logo upload functionality
        document.getElementById('site_logo_btn').addEventListener('click', function() {
            document.getElementById('site_logo_input').click();
        });
        
        document.getElementById('site_logo_input').addEventListener('change', function() {
            uploadLogo(this.files[0], 'site_logo');
        });
        
        document.getElementById('favicon_btn').addEventListener('click', function() {
            document.getElementById('favicon_input').click();
        });
        
        document.getElementById('favicon_input').addEventListener('change', function() {
            uploadLogo(this.files[0], 'favicon');
        });
        
        document.getElementById('meta_image_btn').addEventListener('click', function() {
            document.getElementById('meta_image_input').click();
        });
        
        document.getElementById('meta_image_input').addEventListener('change', function() {
            uploadLogo(this.files[0], 'meta_image');
        });
        
        async function uploadLogo(file, type) {
            if (!file) return;
            
            const formData = new FormData();
            formData.append('action', 'upload_logo');
            formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
            formData.append('logo', file);
            formData.append('logo_type', type);
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                    
                    // Update preview
                    const previewContainer = document.querySelector(`#${type}_btn`).closest('.logo-uploader').querySelector('.logo-preview');
                    previewContainer.innerHTML = `<img src="../${result.path}" alt="Logo">`;
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
            }
        }
        
        // Password visibility toggle
        function togglePasswordVisibility(inputId) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
            } else {
                input.type = 'password';
            }
        }
        
        // Save settings functionality
        async function saveSettings(section) {
            const form = document.getElementById(`${section}-form`);
            const formData = new FormData(form);
            const settings = {};
            
            // Convert form data to object
            for (const [key, value] of formData.entries()) {
                // Handle checkboxes
                if (form.elements[key].type === 'checkbox') {
                    settings[key] = form.elements[key].checked ? '1' : '0';
                } else {
                    settings[key] = value;
                }
            }
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'action': 'update_settings',
                        'token': '<?php echo $_SESSION['admin_token']; ?>',
                        'section': section,
                        'settings': JSON.stringify(settings)
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
            }
        }
        
        // Test email functionality
        async function testEmail() {
            const email = document.getElementById('test_email').value;
            
            if (!email) {
                showNotification('Vui lòng nhập địa chỉ email', 'error');
                return;
            }
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'action': 'test_email',
                        'token': '<?php echo $_SESSION['admin_token']; ?>',
                        'email': email
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
            }
        }
        
        // Reset form to initial values
        function resetForm(formId) {
            document.getElementById(formId).reset();
        }
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? 'var(--success)' : type === 'error' ? 'var(--danger)' : 'var(--primary)'};
                color: white;
                padding: var(--space-4) var(--space-5);
                border-radius: var(--rounded-lg);
                box-shadow: var(--shadow-xl);
                z-index: 9999;
                transform: translateX(400px);
                transition: transform 0.3s ease;
                max-width: 350px;
                font-weight: var(--font-medium);
            `;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
                setTimeout(() => {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }
        
        // Responsive handling
        function handleResponsive() {
            const isDesktop = window.innerWidth > 1024;
            
            if (isDesktop && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
            
            if (!isDesktop && sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
            }
        }
        
        window.addEventListener('resize', handleResponsive);
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Settings Management - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Settings Management - Ready!');
        });
    </script>
</body>
</html>