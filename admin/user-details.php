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

// Currency format function
function formatCurrency($amount, $currency = 'VND') {
    if ($currency === 'VND') {
        return number_format($amount, 0, ',', '.') . '₫';
    } else {
        return '$' . number_format($amount, 2, '.', ',');
    }
}

// Format date function
function formatDate($date, $format = 'd/m/Y H:i') {
    if (!$date) return 'N/A';
    return date($format, strtotime($date));
}

// Check if user ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: users.php');
    exit;
}

$user_id = (int)$_GET['id'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    try {
        switch ($_POST['action']) {
            case 'ban_user':
                // Don't allow banning yourself
                if ($user_id == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Không thể cấm tài khoản của chính mình']);
                    break;
                }
                
                $stmt = $db->prepare("UPDATE users SET banned = 1 WHERE id = ?");
                $stmt->execute([$user_id]);
                echo json_encode(['success' => true, 'message' => 'Đã cấm người dùng thành công']);
                break;
                
            case 'unban_user':
                $stmt = $db->prepare("UPDATE users SET banned = 0 WHERE id = ?");
                $stmt->execute([$user_id]);
                echo json_encode(['success' => true, 'message' => 'Đã bỏ cấm người dùng thành công']);
                break;
                
            case 'update_user':
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $user_type = trim($_POST['user_type'] ?? 'customer');
                
                // Validation
                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Tên không được để trống']);
                    break;
                }
                
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Email không hợp lệ']);
                    break;
                }
                
                // Check if email is already used by another user
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $stmt->execute([$email, $user_id]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Email đã được sử dụng bởi người dùng khác']);
                    break;
                }
                
                // Begin transaction
                $db->beginTransaction();
                
                try {
                    // Update user
                    $stmt = $db->prepare("
                        UPDATE users 
                        SET name = ?, 
                            email = ?, 
                            phone = ?, 
                            user_type = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $email, $phone, $user_type, $user_id]);
                    
                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'Đã cập nhật thông tin người dùng']);
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                break;
                
            case 'update_address':
                $address_id = (int)$_POST['address_id'];
                $address = trim($_POST['address'] ?? '');
                $country_id = (int)$_POST['country_id'];
                $state_id = (int)$_POST['state_id'];
                $city_id = (int)$_POST['city_id'];
                $postal_code = trim($_POST['postal_code'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                
                // Validation
                if (empty($address)) {
                    echo json_encode(['success' => false, 'message' => 'Địa chỉ không được để trống']);
                    break;
                }
                
                // Update address
                $stmt = $db->prepare("
                    UPDATE addresses 
                    SET address = ?, 
                        country_id = ?, 
                        state_id = ?, 
                        city_id = ?, 
                        postal_code = ?, 
                        phone = ?,
                        updated_at = NOW()
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$address, $country_id, $state_id, $city_id, $postal_code, $phone, $address_id, $user_id]);
                
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật địa chỉ']);
                break;
                
            case 'delete_address':
                $address_id = (int)$_POST['address_id'];
                
                $stmt = $db->prepare("DELETE FROM addresses WHERE id = ? AND user_id = ?");
                $stmt->execute([$address_id, $user_id]);
                
                echo json_encode(['success' => true, 'message' => 'Đã xóa địa chỉ']);
                break;
                
            case 'add_wallet_balance':
                $amount = (float)$_POST['amount'];
                $payment_method = trim($_POST['payment_method'] ?? 'manual');
                $details = trim($_POST['details'] ?? '');
                
                // Validation
                if ($amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Số tiền phải lớn hơn 0']);
                    break;
                }
                
                // Begin transaction
                $db->beginTransaction();
                
                try {
                    // Add transaction record
                    $stmt = $db->prepare("
                        INSERT INTO wallets (
                            user_id, amount, payment_method, payment_details, approval, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?, 1, NOW(), NOW()
                        )
                    ");
                    $payment_details = json_encode([
                        'added_by' => $_SESSION['user_id'],
                        'added_at' => date('Y-m-d H:i:s'),
                        'details' => $details
                    ]);
                    $stmt->execute([$user_id, $amount, $payment_method, $payment_details]);
                    
                    // Update user balance
                    $stmt = $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                    $stmt->execute([$amount, $user_id]);
                    
                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'Đã thêm ' . formatCurrency($amount) . ' vào ví người dùng']);
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                break;
                
            case 'change_password':
                $password = trim($_POST['password'] ?? '');
                $confirm_password = trim($_POST['confirm_password'] ?? '');
                
                // Validation
                if (strlen($password) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Mật khẩu phải có ít nhất 6 ký tự']);
                    break;
                }
                
                if ($password !== $confirm_password) {
                    echo json_encode(['success' => false, 'message' => 'Mật khẩu xác nhận không khớp']);
                    break;
                }
                
                // Update password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
                
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật mật khẩu']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("User action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Get user details
$user = null;
try {
    $stmt = $db->prepare("
        SELECT u.*,
               COALESCE(au.id, 0) as is_affiliate,
               COALESCE(au.balance, 0) as affiliate_balance,
               COALESCE(s.id, 0) as is_seller,
               COALESCE(sh.id, 0) as has_shop,
               sh.name as shop_name,
               sh.logo as shop_logo,
               sh.verification_status as shop_verification
        FROM users u
        LEFT JOIN affiliate_users au ON u.id = au.user_id
        LEFT JOIN sellers s ON u.id = s.user_id
        LEFT JOIN shops sh ON u.id = sh.user_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        header('Location: users.php?error=user_not_found');
        exit;
    }
} catch (PDOException $e) {
    error_log("User fetch error: " . $e->getMessage());
    header('Location: users.php?error=database_error');
    exit;
}

// Get user addresses
$addresses = [];
try {
    $stmt = $db->prepare("
        SELECT a.*,
               c.name as country_name,
               s.name as state_name,
               ci.name as city_name
        FROM addresses a
        LEFT JOIN countries c ON a.country_id = c.id
        LEFT JOIN states s ON a.state_id = s.id
        LEFT JOIN cities ci ON a.city_id = ci.id
        WHERE a.user_id = ?
        ORDER BY a.set_default DESC, a.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Addresses fetch error: " . $e->getMessage());
    $addresses = [];
}

// Get user orders
$orders = [];
try {
    $stmt = $db->prepare("
        SELECT o.*,
               c.code as combined_order_code
        FROM orders o
        LEFT JOIN combined_orders c ON o.combined_order_id = c.id
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Orders fetch error: " . $e->getMessage());
    $orders = [];
}

// Get user wallet transactions
$wallet_transactions = [];
try {
    $stmt = $db->prepare("
        SELECT *
        FROM wallets
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $wallet_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Wallet transactions fetch error: " . $e->getMessage());
    $wallet_transactions = [];
}

// Get countries, states, cities for address form
$countries = [];
try {
    $stmt = $db->query("SELECT id, name FROM countries WHERE status = 1 ORDER BY name");
    $countries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Countries fetch error: " . $e->getMessage());
    $countries = [];
}

$states = [];
try {
    $stmt = $db->query("SELECT id, name, country_id FROM states WHERE status = 1 ORDER BY name");
    $states = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("States fetch error: " . $e->getMessage());
    $states = [];
}

$cities = [];
try {
    $stmt = $db->query("SELECT id, name, state_id FROM cities WHERE status = 1 ORDER BY name");
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Cities fetch error: " . $e->getMessage());
    $cities = [];
}

// Get user statistics
$stats = [];
try {
    // Total orders
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $stats['total_orders'] = $stmt->fetch()['count'];
    
    // Total spent
    $stmt = $db->prepare("SELECT COALESCE(SUM(grand_total), 0) as total FROM orders WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $stats['total_spent'] = $stmt->fetch()['total'];
    
    // Wishlist items
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM wishlists WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $stats['wishlist_items'] = $stmt->fetch()['count'];
    
    // Last login
    $stmt = $db->prepare("SELECT MAX(created_at) as last_login FROM user_logins WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    $stats['last_login'] = $result ? $result['last_login'] : null;
    
} catch (PDOException $e) {
    error_log("User stats error: " . $e->getMessage());
    $stats = ['total_orders' => 0, 'total_spent' => 0, 'wishlist_items' => 0, 'last_login' => null];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chi tiết người dùng - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Chi tiết người dùng - Admin <?php echo htmlspecialchars($site_name); ?>">
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
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .page-header-left {
            flex: 1;
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
        
        .page-header-actions {
            display: flex;
            gap: var(--space-3);
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
            text-decoration: none;
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
        
        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }
        
        .btn-danger:hover {
            background: var(--secondary-dark);
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: var(--success);
            color: var(--white);
        }
        
        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
        }
        
        .btn-warning {
            background: var(--warning);
            color: var(--white);
        }
        
        .btn-warning:hover {
            background: #d97706;
            transform: translateY(-1px);
        }
        
        .btn-sm {
            padding: var(--space-2) var(--space-3);
            font-size: var(--text-xs);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }
        
        /* User Profile */
        .profile-header {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            box-shadow: var(--shadow-md);
            display: flex;
            gap: var(--space-6);
            align-items: center;
            border: 1px solid var(--border-light);
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: var(--rounded-2xl);
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: var(--text-3xl);
            font-weight: var(--font-bold);
            box-shadow: var(--shadow-lg);
        }
        
        .profile-info {
            flex: 1;
        }
        
        .profile-name {
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            margin-bottom: var(--space-1);
        }
        
        .profile-meta {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-4);
            margin-bottom: var(--space-4);
        }
        
        .profile-meta-item {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            color: var(--text-secondary);
            font-size: var(--text-sm);
        }
        
        .profile-badges {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-2);
            margin-top: var(--space-3);
        }
        
        .profile-badge {
            display: inline-flex;
            align-items: center;
            padding: var(--space-1) var(--space-3);
            border-radius: var(--rounded-full);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .profile-badge.active {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .profile-badge.banned {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        .profile-badge.admin {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .profile-badge.seller {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .profile-badge.customer {
            background: rgba(107, 114, 128, 0.1);
            color: #374151;
        }
        
        .profile-badge.affiliate {
            background: rgba(139, 92, 246, 0.1);
            color: #5b21b6;
        }
        
        .profile-actions {
            display: flex;
            gap: var(--space-2);
            margin-top: var(--space-4);
        }
        
        /* Tabs */
        .tab-nav {
            display: flex;
            border-bottom: 1px solid var(--border);
            margin-bottom: var(--space-6);
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        
        .tab-nav::-webkit-scrollbar {
            display: none;
        }
        
        .tab-btn {
            padding: var(--space-4) var(--space-5);
            color: var(--text-secondary);
            font-weight: var(--font-medium);
            border: none;
            background: none;
            cursor: pointer;
            position: relative;
            white-space: nowrap;
        }
        
        .tab-btn::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
            transform: scaleX(0);
            transition: var(--transition-normal);
        }
        
        .tab-btn:hover {
            color: var(--text-primary);
        }
        
        .tab-btn.active {
            color: var(--primary);
            font-weight: var(--font-semibold);
        }
        
        .tab-btn.active::after {
            transform: scaleX(1);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Cards */
        .card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            border: 1px solid var(--border-light);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--space-6);
        }
        
        .card-header {
            padding: var(--space-4) var(--space-6);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-title {
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
        }
        
        .card-body {
            padding: var(--space-6);
        }
        
        .card-footer {
            padding: var(--space-4) var(--space-6);
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: var(--space-3);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-6);
        }
        
        .stat-card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            padding: var(--space-5);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            transition: var(--transition-normal);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-3);
        }
        
        .stat-title {
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            color: var(--text-secondary);
        }
        
        .stat-icon {
            font-size: var(--text-xl);
            color: var(--primary);
        }
        
        .stat-value {
            font-family: var(--font-heading);
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            color: var(--text-primary);
        }
        
        .stat-card.orange::before {
            background: var(--warning-gradient);
        }
        
        .stat-card.green::before {
            background: var(--success-gradient);
        }
        
        .stat-card.purple::before {
            background: var(--secondary-gradient);
        }
        
        .stat-card.blue::before {
            background: var(--accent-gradient);
        }
        
        /* Tables */
        .table-container {
            overflow-x: auto;
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            margin-bottom: var(--space-6);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: var(--text-sm);
            white-space: nowrap;
        }
        
        .table th {
            background: var(--gray-50);
            color: var(--text-secondary);
            font-weight: var(--font-semibold);
            padding: var(--space-4) var(--space-5);
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: var(--text-xs);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table td {
            padding: var(--space-4) var(--space-5);
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
        }
        
        .table tr:hover {
            background: var(--gray-50);
        }
        
        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: var(--space-1) var(--space-3);
            border-radius: var(--rounded-full);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-badge.active {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .status-badge.pending {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .status-badge.delivered {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .status-badge.cancelled {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        .status-badge.paid {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .status-badge.unpaid {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        /* Forms */
        .form-group {
            margin-bottom: var(--space-4);
        }
        
        .form-label {
            display: block;
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            margin-bottom: var(--space-2);
            color: var(--text-secondary);
        }
        
        .form-control {
            width: 100%;
            padding: var(--space-3) var(--space-4);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            transition: var(--transition-normal);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-hint {
            margin-top: var(--space-1);
            font-size: var(--text-xs);
            color: var(--text-tertiary);
        }
        
        /* Shop Card */
        .shop-card {
            display: flex;
            align-items: center;
            gap: var(--space-4);
            padding: var(--space-4);
            background: var(--surface);
            border-radius: var(--rounded-xl);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--space-4);
        }
        
        .shop-logo {
            width: 80px;
            height: 80px;
            border-radius: var(--rounded-lg);
            object-fit: cover;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: var(--text-xl);
            font-weight: var(--font-bold);
        }
        
        .shop-info {
            flex: 1;
        }
        
        .shop-name {
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
            margin-bottom: var(--space-1);
        }
        
        .shop-meta {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-4);
            margin-bottom: var(--space-2);
            color: var(--text-secondary);
            font-size: var(--text-sm);
        }
        
        .shop-verification {
            margin-top: var(--space-2);
        }
        
        /* Address Card */
        .address-card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            border: 1px solid var(--border-light);
            padding: var(--space-4);
            margin-bottom: var(--space-4);
            position: relative;
        }
        
        .address-default {
            position: absolute;
            top: var(--space-4);
            right: var(--space-4);
            padding: var(--space-1) var(--space-3);
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
            border-radius: var(--rounded-full);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
        }
        
        .address-content {
            margin-bottom: var(--space-3);
        }
        
        .address-actions {
            display: flex;
            justify-content: flex-end;
            gap: var(--space-2);
        }
        
        /* Modal */
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1050;
            opacity: 0;
            transition: opacity 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }
        
        .modal-backdrop.show {
            opacity: 1;
            pointer-events: auto;
        }
        
        .modal-content {
            background-color: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-xl);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }
        
        .modal-backdrop.show .modal-content {
            transform: translateY(0);
        }
        
        .modal-header {
            padding: var(--space-4) var(--space-6);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-title {
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: var(--text-xl);
            cursor: pointer;
            color: var(--text-secondary);
            transition: var(--transition-normal);
        }
        
        .modal-close:hover {
            color: var(--text-primary);
        }
        
        .modal-body {
            padding: var(--space-6);
        }
        
        .modal-footer {
            padding: var(--space-4) var(--space-6);
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: var(--space-3);
        }
        
        /* Loading */
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--border);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Notification system */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--primary);
            color: white;
            padding: var(--space-4) var(--space-5);
            border-radius: var(--rounded-lg);
            box-shadow: var(--shadow-xl);
            z-index: 9999;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            max-width: 350px;
            font-weight: var(--font-medium);
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification.success {
            background: var(--success);
        }
        
        .notification.error {
            background: var(--danger);
        }
        
        .notification.warning {
            background: var(--warning);
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
            
            .profile-header {
                flex-direction: column;
                align-items: flex-start;
                padding: var(--space-4);
            }
            
            .profile-avatar {
                width: 80px;
                height: 80px;
                font-size: var(--text-2xl);
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .content {
                padding: var(--space-4);
            }
            
            .page-header {
                flex-direction: column;
                gap: var(--space-4);
            }
            
            .page-header-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .tab-nav {
                padding-bottom: 0;
            }
            
            .tab-btn {
                padding: var(--space-3) var(--space-4);
                font-size: var(--text-sm);
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
                        <a href="users.php" class="nav-link active">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Người dùng</span>
                        </a>
                    </div>   
                    <div class="nav-item">
                        <a href="sellers.php" class="nav-link">
                            <span class="nav-icon">🏪</span>
                            <span class="nav-text">Cửa hàng</span>
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
                        <a href="settings.php" class="nav-link">
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
                            <a href="users.php">Người dùng</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span>Chi tiết</span>
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
                    <div class="page-header-left">
                        <h1 class="page-title">Chi tiết người dùng</h1>
                        <p class="page-subtitle">Xem và quản lý thông tin của người dùng <?php echo htmlspecialchars($user['name']); ?></p>
                    </div>
                    
                    <div class="page-header-actions">
                        <a href="users.php" class="btn btn-secondary">
                            <span>↩️</span>
                            <span>Quay lại</span>
                        </a>
                        
                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                            <?php if ($user['banned']): ?>
                                <button class="btn btn-success" onclick="unbanUser()">
                                    <span>✅</span>
                                    <span>Bỏ cấm</span>
                                </button>
                            <?php else: ?>
                                <button class="btn btn-warning" onclick="banUser()">
                                    <span>🚫</span>
                                    <span>Cấm người dùng</span>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                    </div>
                    
                    <div class="profile-info">
                        <h2 class="profile-name"><?php echo htmlspecialchars($user['name']); ?></h2>
                        
                        <div class="profile-meta">
                            <div class="profile-meta-item">
                                <span>📧</span>
                                <span><?php echo htmlspecialchars($user['email']); ?></span>
                            </div>
                            
                            <?php if ($user['phone']): ?>
                            <div class="profile-meta-item">
                                <span>📱</span>
                                <span><?php echo htmlspecialchars($user['phone']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="profile-meta-item">
                                <span>🆔</span>
                                <span>#<?php echo $user['id']; ?></span>
                            </div>
                            
                            <div class="profile-meta-item">
                                <span>📅</span>
                                <span>Đăng ký: <?php echo formatDate($user['created_at'], 'd/m/Y'); ?></span>
                            </div>
                        </div>
                        
                        <div class="profile-badges">
                            <?php if ($user['banned']): ?>
                                <span class="profile-badge banned">Bị cấm</span>
                            <?php else: ?>
                                <span class="profile-badge active">Hoạt động</span>
                            <?php endif; ?>
                            
                            <?php 
                            switch ($user['user_type']) {
                                case 'admin':
                                    echo '<span class="profile-badge admin">Admin</span>';
                                    break;
                                case 'staff':
                                    echo '<span class="profile-badge admin">Nhân viên</span>';
                                    break;
                                case 'seller':
                                    echo '<span class="profile-badge seller">Người bán</span>';
                                    break;
                                default:
                                    echo '<span class="profile-badge customer">Khách hàng</span>';
                            }
                            ?>
                            
                            <?php if ($user['is_affiliate']): ?>
                                <span class="profile-badge affiliate">Affiliate</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="profile-actions">
                            <button class="btn btn-primary btn-sm" onclick="openEditModal()">
                                <span>✏️</span>
                                <span>Chỉnh sửa</span>
                            </button>
                            
                            <button class="btn btn-secondary btn-sm" onclick="openPasswordModal()">
                                <span>🔑</span>
                                <span>Đổi mật khẩu</span>
                            </button>
                            
                            <button class="btn btn-secondary btn-sm" onclick="openWalletModal()">
                                <span>💰</span>
                                <span>Thêm tiền vào ví</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Tổng đơn hàng</div>
                            <div class="stat-icon">🛒</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_orders']); ?></div>
                    </div>
                    
                    <div class="stat-card green">
                        <div class="stat-header">
                            <div class="stat-title">Tổng chi tiêu</div>
                            <div class="stat-icon">💸</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($stats['total_spent']); ?></div>
                    </div>
                    
                    <div class="stat-card blue">
                        <div class="stat-header">
                            <div class="stat-title">Số dư ví</div>
                            <div class="stat-icon">💰</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($user['balance'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card purple">
                        <div class="stat-header">
                            <div class="stat-title">Sản phẩm yêu thích</div>
                            <div class="stat-icon">❤️</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['wishlist_items']); ?></div>
                    </div>
                    
                    <?php if ($user['is_affiliate']): ?>
                    <div class="stat-card orange">
                        <div class="stat-header">
                            <div class="stat-title">Hoa hồng Affiliate</div>
                            <div class="stat-icon">🔗</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($user['affiliate_balance'] ?? 0); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Tabs -->
                <div class="tab-nav">
                    <button class="tab-btn active" data-tab="info">Thông tin cơ bản</button>
                    <button class="tab-btn" data-tab="orders">Đơn hàng</button>
                    <button class="tab-btn" data-tab="wallet">Lịch sử ví</button>
                    <button class="tab-btn" data-tab="addresses">Địa chỉ</button>
                    <?php if ($user['is_seller'] && $user['has_shop']): ?>
                        <button class="tab-btn" data-tab="shop">Cửa hàng</button>
                    <?php endif; ?>
                </div>
                
                <!-- Tab Content - Info -->
                <div class="tab-content active" id="info-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Thông tin người dùng</h3>
                        </div>
                        <div class="card-body">
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--space-4);">
                                <div>
                                    <p style="margin-bottom: var(--space-2);"><strong>Họ tên:</strong> <?php echo htmlspecialchars($user['name']); ?></p>
                                    <p style="margin-bottom: var(--space-2);"><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                                    <p style="margin-bottom: var(--space-2);"><strong>Số điện thoại:</strong> <?php echo htmlspecialchars($user['phone'] ?? 'Chưa cập nhật'); ?></p>
                                    <p style="margin-bottom: var(--space-2);"><strong>Loại tài khoản:</strong> <?php echo ucfirst(htmlspecialchars($user['user_type'])); ?></p>
                                </div>
                                <div>
                                    <p style="margin-bottom: var(--space-2);"><strong>Ngày đăng ký:</strong> <?php echo formatDate($user['created_at'], 'd/m/Y H:i'); ?></p>
                                    <p style="margin-bottom: var(--space-2);"><strong>Lần đăng nhập gần nhất:</strong> <?php echo $stats['last_login'] ? formatDate($stats['last_login'], 'd/m/Y H:i') : 'Không có dữ liệu'; ?></p>
                                    <p style="margin-bottom: var(--space-2);"><strong>Trạng thái:</strong> <?php echo $user['banned'] ? '<span class="status-badge banned">Bị cấm</span>' : '<span class="status-badge active">Hoạt động</span>'; ?></p>
                                    <p style="margin-bottom: var(--space-2);"><strong>Mã giới thiệu:</strong> <?php echo htmlspecialchars($user['referral_code'] ?? 'Không có'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tab Content - Orders -->
                <div class="tab-content" id="orders-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Đơn hàng gần đây</h3>
                        </div>
                        <div class="card-body">
                            <?php if (count($orders) > 0): ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Mã đơn</th>
                                                <th>Ngày đặt</th>
                                                <th>Tổng tiền</th>
                                                <th>Trạng thái thanh toán</th>
                                                <th>Trạng thái giao hàng</th>
                                                <th>Thao tác</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($orders as $order): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($order['code']); ?></td>
                                                    <td><?php echo formatDate($order['created_at'], 'd/m/Y H:i'); ?></td>
                                                    <td><?php echo formatCurrency($order['grand_total']); ?></td>
                                                    <td>
                                                        <?php
                                                        switch ($order['payment_status']) {
                                                            case 'paid':
                                                                echo '<span class="status-badge paid">Đã thanh toán</span>';
                                                                break;
                                                            case 'unpaid':
                                                                echo '<span class="status-badge unpaid">Chưa thanh toán</span>';
                                                                break;
                                                            default:
                                                                echo '<span class="status-badge pending">Đang xử lý</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        switch ($order['delivery_status']) {
                                                            case 'delivered':
                                                                echo '<span class="status-badge delivered">Đã giao</span>';
                                                                break;
                                                            case 'cancelled':
                                                                echo '<span class="status-badge cancelled">Đã hủy</span>';
                                                                break;
                                                            default:
                                                                echo '<span class="status-badge pending">Đang xử lý</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <a href="order-details.php?id=<?php echo $order['id']; ?>" class="btn btn-secondary btn-sm">Xem chi tiết</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div style="text-align: center; margin-top: var(--space-4);">
                                    <a href="orders.php?user_id=<?php echo $user['id']; ?>" class="btn btn-primary">Xem tất cả đơn hàng</a>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: var(--space-8);">
                                    <div style="font-size: var(--text-xl); margin-bottom: var(--space-4); color: var(--text-secondary);">
                                        Người dùng chưa có đơn hàng nào
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Tab Content - Wallet -->
                <div class="tab-content" id="wallet-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Lịch sử giao dịch ví</h3>
                        </div>
                        <div class="card-body">
                            <?php if (count($wallet_transactions) > 0): ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Số tiền</th>
                                                <th>Phương thức</th>
                                                <th>Ngày</th>
                                                <th>Trạng thái</th>
                                                <th>Chi tiết</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($wallet_transactions as $transaction): ?>
                                                <tr>
                                                    <td>#<?php echo $transaction['id']; ?></td>
                                                    <td><?php echo formatCurrency($transaction['amount']); ?></td>
                                                    <td><?php echo htmlspecialchars($transaction['payment_method']); ?></td>
                                                    <td><?php echo formatDate($transaction['created_at'], 'd/m/Y H:i'); ?></td>
                                                    <td>
                                                        <?php if ($transaction['approval']): ?>
                                                            <span class="status-badge paid">Đã duyệt</span>
                                                        <?php else: ?>
                                                            <span class="status-badge pending">Chờ duyệt</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $details = json_decode($transaction['payment_details'] ?? '{}', true);
                                                        if (!empty($details)) {
                                                            if (isset($details['added_by'])) {
                                                                echo 'Thêm bởi admin';
                                                            } elseif (isset($details['type'])) {
                                                                echo htmlspecialchars($details['type']);
                                                            } else {
                                                                echo 'Chi tiết giao dịch';
                                                            }
                                                        } else {
                                                            echo 'N/A';
                                                        }
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: var(--space-8);">
                                    <div style="font-size: var(--text-xl); margin-bottom: var(--space-4); color: var(--text-secondary);">
                                        Chưa có giao dịch ví nào
                                    </div>
                                    <button class="btn btn-primary" onclick="openWalletModal()">
                                        <span>💰</span>
                                        <span>Thêm tiền vào ví</span>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-footer">
                            <div style="margin-right: auto;">
                                <strong>Số dư hiện tại:</strong> <?php echo formatCurrency($user['balance'] ?? 0); ?>
                            </div>
                            <button class="btn btn-primary" onclick="openWalletModal()">
                                <span>💰</span>
                                <span>Thêm tiền vào ví</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Tab Content - Addresses -->
                <div class="tab-content" id="addresses-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Địa chỉ của người dùng</h3>
                        </div>
                        <div class="card-body">
                            <?php if (count($addresses) > 0): ?>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--space-4);">
                                    <?php foreach ($addresses as $address): ?>
                                        <div class="address-card">
                                            <?php if ($address['set_default']): ?>
                                                <div class="address-default">Mặc định</div>
                                            <?php endif; ?>
                                            
                                            <div class="address-content">
                                                <p style="margin-bottom: var(--space-2);">
                                                    <strong>Địa chỉ:</strong> <?php echo htmlspecialchars($address['address']); ?>
                                                </p>
                                                <p style="margin-bottom: var(--space-2);">
                                                    <strong>Thành phố:</strong> <?php echo htmlspecialchars($address['city_name'] ?? 'N/A'); ?>
                                                </p>
                                                <p style="margin-bottom: var(--space-2);">
                                                    <strong>Tỉnh/Thành phố:</strong> <?php echo htmlspecialchars($address['state_name'] ?? 'N/A'); ?>
                                                </p>
                                                <p style="margin-bottom: var(--space-2);">
                                                    <strong>Quốc gia:</strong> <?php echo htmlspecialchars($address['country_name'] ?? 'N/A'); ?>
                                                </p>
                                                <?php if ($address['postal_code']): ?>
                                                    <p style="margin-bottom: var(--space-2);">
                                                        <strong>Mã bưu điện:</strong> <?php echo htmlspecialchars($address['postal_code']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if ($address['phone']): ?>
                                                    <p style="margin-bottom: var(--space-2);">
                                                        <strong>Số điện thoại:</strong> <?php echo htmlspecialchars($address['phone']); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="address-actions">
                                                <button class="btn btn-secondary btn-sm" onclick="editAddress(<?php echo $address['id']; ?>)">
                                                    <span>✏️</span>
                                                    <span>Sửa</span>
                                                </button>
                                                <button class="btn btn-danger btn-sm" onclick="deleteAddress(<?php echo $address['id']; ?>)">
                                                    <span>🗑️</span>
                                                    <span>Xóa</span>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: var(--space-8);">
                                    <div style="font-size: var(--text-xl); margin-bottom: var(--space-4); color: var(--text-secondary);">
                                        Người dùng chưa có địa chỉ nào
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Tab Content - Shop -->
                <?php if ($user['is_seller'] && $user['has_shop']): ?>
                <div class="tab-content" id="shop-tab">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Thông tin cửa hàng</h3>
                        </div>
                        <div class="card-body">
                            <div class="shop-card">
                                <?php if ($user['shop_logo']): ?>
                                    <img src="<?php echo htmlspecialchars($user['shop_logo']); ?>" alt="Shop logo" class="shop-logo">
                                <?php else: ?>
                                    <div class="shop-logo">
                                        <?php echo strtoupper(substr($user['shop_name'] ?? 'S', 0, 2)); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="shop-info">
                                    <h3 class="shop-name"><?php echo htmlspecialchars($user['shop_name']); ?></h3>
                                    
                                    <div class="shop-meta">
                                        <span>
                                            <strong>ID:</strong> #<?php echo $user['has_shop']; ?>
                                        </span>
                                        <span>
                                            <strong>Chủ sở hữu:</strong> <?php echo htmlspecialchars($user['name']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="shop-verification">
                                        <?php
                                        switch ($user['shop_verification']) {
                                            case 1:
                                                echo '<span class="status-badge active">Đã xác thực</span>';
                                                break;
                                            case 2:
                                                echo '<span class="status-badge pending">Chờ xác thực</span>';
                                                break;
                                            default:
                                                echo '<span class="status-badge banned">Chưa xác thực</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="margin-top: var(--space-4); text-align: center;">
                                <a href="shop-details.php?id=<?php echo $user['has_shop']; ?>" class="btn btn-primary">
                                    <span>🏪</span>
                                    <span>Xem chi tiết cửa hàng</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Edit User Modal -->
    <div class="modal-backdrop" id="edit-user-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Chỉnh sửa thông tin người dùng</h2>
                <button class="modal-close" onclick="closeModal('edit-user-modal')">×</button>
            </div>
            <div class="modal-body">
                <form id="edit-user-form">
                    <div class="form-group">
                        <label class="form-label" for="edit-name">Họ tên <span style="color: red">*</span></label>
                        <input type="text" class="form-control" id="edit-name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="edit-email">Email <span style="color: red">*</span></label>
                        <input type="email" class="form-control" id="edit-email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="edit-phone">Số điện thoại</label>
                        <input type="text" class="form-control" id="edit-phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="edit-user-type">Loại người dùng <span style="color: red">*</span></label>
                        <select class="form-control" id="edit-user-type">
                            <option value="customer" <?php echo $user['user_type'] === 'customer' ? 'selected' : ''; ?>>Khách hàng</option>
                            <option value="seller" <?php echo $user['user_type'] === 'seller' ? 'selected' : ''; ?>>Người bán</option>
                            <option value="staff" <?php echo $user['user_type'] === 'staff' ? 'selected' : ''; ?>>Nhân viên</option>
                            <option value="admin" <?php echo $user['user_type'] === 'admin' ? 'selected' : ''; ?>>Quản trị viên</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('edit-user-modal')">Hủy</button>
                <button class="btn btn-primary" onclick="updateUser()" id="update-user-btn">Cập nhật</button>
            </div>
        </div>
    </div>
    
    <!-- Change Password Modal -->
    <div class="modal-backdrop" id="password-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Đổi mật khẩu</h2>
                <button class="modal-close" onclick="closeModal('password-modal')">×</button>
            </div>
            <div class="modal-body">
                <form id="password-form">
                    <div class="form-group">
                        <label class="form-label" for="new-password">Mật khẩu mới <span style="color: red">*</span></label>
                        <input type="password" class="form-control" id="new-password" placeholder="Nhập mật khẩu mới" required>
                        <div class="form-hint">Mật khẩu phải có ít nhất 6 ký tự</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="confirm-password">Xác nhận mật khẩu <span style="color: red">*</span></label>
                        <input type="password" class="form-control" id="confirm-password" placeholder="Nhập lại mật khẩu mới" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('password-modal')">Hủy</button>
                <button class="btn btn-primary" onclick="changePassword()" id="change-password-btn">Cập nhật mật khẩu</button>
            </div>
        </div>
    </div>
    
    <!-- Add Wallet Balance Modal -->
    <div class="modal-backdrop" id="wallet-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Thêm tiền vào ví</h2>
                <button class="modal-close" onclick="closeModal('wallet-modal')">×</button>
            </div>
            <div class="modal-body">
                <form id="wallet-form">
                    <div class="form-group">
                        <label class="form-label" for="wallet-amount">Số tiền <span style="color: red">*</span></label>
                        <input type="number" class="form-control" id="wallet-amount" placeholder="Nhập số tiền" min="0" step="1000" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="wallet-payment-method">Phương thức <span style="color: red">*</span></label>
                        <select class="form-control" id="wallet-payment-method">
                            <option value="manual">Thêm thủ công</option>
                            <option value="bank_payment">Chuyển khoản ngân hàng</option>
                            <option value="cash">Tiền mặt</option>
                            <option value="system_reward">Thưởng từ hệ thống</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="wallet-details">Chi tiết</label>
                        <textarea class="form-control" id="wallet-details" rows="3" placeholder="Nhập ghi chú hoặc chi tiết"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('wallet-modal')">Hủy</button>
                <button class="btn btn-primary" onclick="addWalletBalance()" id="add-wallet-btn">Thêm tiền</button>
            </div>
        </div>
    </div>
    
    <!-- Edit Address Modal -->
    <div class="modal-backdrop" id="address-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Chỉnh sửa địa chỉ</h2>
                <button class="modal-close" onclick="closeModal('address-modal')">×</button>
            </div>
            <div class="modal-body">
                <form id="address-form">
                    <input type="hidden" id="address-id" value="">
                    
                    <div class="form-group">
                        <label class="form-label" for="address-address">Địa chỉ <span style="color: red">*</span></label>
                        <input type="text" class="form-control" id="address-address" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="address-country">Quốc gia <span style="color: red">*</span></label>
                        <select class="form-control" id="address-country" onchange="updateStates()">
                            <option value="">Chọn quốc gia</option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?php echo $country['id']; ?>"><?php echo htmlspecialchars($country['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="address-state">Tỉnh/Thành phố <span style="color: red">*</span></label>
                        <select class="form-control" id="address-state" onchange="updateCities()">
                            <option value="">Chọn tỉnh/thành phố</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="address-city">Quận/Huyện</label>
                        <select class="form-control" id="address-city">
                            <option value="">Chọn quận/huyện</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="address-postal">Mã bưu điện</label>
                        <input type="text" class="form-control" id="address-postal">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="address-phone">Số điện thoại</label>
                        <input type="text" class="form-control" id="address-phone">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('address-modal')">Hủy</button>
                <button class="btn btn-primary" onclick="updateAddress()" id="update-address-btn">Cập nhật</button>
            </div>
        </div>
    </div>

    <script>
        // Store country, state, city data for address form
        const countryData = <?php echo json_encode($countries); ?>;
        const stateData = <?php echo json_encode($states); ?>;
        const cityData = <?php echo json_encode($cities); ?>;
        
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
        
        // Tab functionality
        const tabButtons = document.querySelectorAll('.tab-btn');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', function() {
                const tabId = this.dataset.tab;
                
                // Deactivate all tabs
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));
                
                // Activate clicked tab
                this.classList.add('active');
                document.getElementById(tabId + '-tab').classList.add('active');
                
                // Save active tab in localStorage
                localStorage.setItem('activeUserTab', tabId);
            });
        });
        
        // Restore active tab from localStorage
        const savedTab = localStorage.getItem('activeUserTab');
        if (savedTab) {
            const tabButton = document.querySelector(`.tab-btn[data-tab="${savedTab}"]`);
            if (tabButton) {
                tabButton.click();
            }
        }
        
        // AJAX helper function
        async function makeRequest(action, data) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
            
            Object.keys(data).forEach(key => {
                formData.append(key, data[key]);
            });
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                    return result;
                } else {
                    showNotification(result.message, 'error');
                    return false;
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
                return false;
            }
        }
        
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }
        
        // Close modal when clicking on backdrop
        document.querySelectorAll('.modal-backdrop').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                }
            });
        });
        
        // Edit user modal
        function openEditModal() {
            openModal('edit-user-modal');
        }
        
        async function updateUser() {
            const name = document.getElementById('edit-name').value.trim();
            const email = document.getElementById('edit-email').value.trim();
            const phone = document.getElementById('edit-phone').value.trim();
            const userType = document.getElementById('edit-user-type').value;
            
            // Validation
            if (!name) {
                showNotification('Vui lòng nhập họ tên', 'error');
                return;
            }
            
            if (!email || !email.match(/^[\w-\.]+@([\w-]+\.)+[\w-]{2,4}$/)) {
                showNotification('Vui lòng nhập email hợp lệ', 'error');
                return;
            }
            
            // Update button state
            const updateBtn = document.getElementById('update-user-btn');
            updateBtn.disabled = true;
            updateBtn.innerHTML = '<span class="loading"></span> Đang xử lý';
            
            const result = await makeRequest('update_user', {
                name,
                email,
                phone,
                user_type: userType
            });
            
            if (result) {
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                updateBtn.disabled = false;
                updateBtn.textContent = 'Cập nhật';
            }
        }
        
        // Password modal
        function openPasswordModal() {
            document.getElementById('new-password').value = '';
            document.getElementById('confirm-password').value = '';
            openModal('password-modal');
        }
        
        async function changePassword() {
            const password = document.getElementById('new-password').value;
            const confirmPassword = document.getElementById('confirm-password').value;
            
            // Validation
            if (password.length < 6) {
                showNotification('Mật khẩu phải có ít nhất 6 ký tự', 'error');
                return;
            }
            
            if (password !== confirmPassword) {
                showNotification('Mật khẩu xác nhận không khớp', 'error');
                return;
            }
            
            // Update button state
            const passwordBtn = document.getElementById('change-password-btn');
            passwordBtn.disabled = true;
            passwordBtn.innerHTML = '<span class="loading"></span> Đang xử lý';
            
            const result = await makeRequest('change_password', {
                password,
                confirm_password: confirmPassword
            });
            
            if (result) {
                closeModal('password-modal');
                passwordBtn.disabled = false;
                passwordBtn.textContent = 'Cập nhật mật khẩu';
            } else {
                passwordBtn.disabled = false;
                passwordBtn.textContent = 'Cập nhật mật khẩu';
            }
        }
        
        // Wallet modal
        function openWalletModal() {
            document.getElementById('wallet-amount').value = '';
            document.getElementById('wallet-payment-method').value = 'manual';
            document.getElementById('wallet-details').value = '';
            openModal('wallet-modal');
        }
        
        async function addWalletBalance() {
            const amount = parseFloat(document.getElementById('wallet-amount').value);
            const paymentMethod = document.getElementById('wallet-payment-method').value;
            const details = document.getElementById('wallet-details').value;
            
            // Validation
            if (isNaN(amount) || amount <= 0) {
                showNotification('Vui lòng nhập số tiền hợp lệ', 'error');
                return;
            }
            
            // Update button state
            const walletBtn = document.getElementById('add-wallet-btn');
            walletBtn.disabled = true;
            walletBtn.innerHTML = '<span class="loading"></span> Đang xử lý';
            
            const result = await makeRequest('add_wallet_balance', {
                amount,
                payment_method: paymentMethod,
                details
            });
            
            if (result) {
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                walletBtn.disabled = false;
                walletBtn.textContent = 'Thêm tiền';
            }
        }
        
        // Address functions
        function updateStates() {
            const countryId = document.getElementById('address-country').value;
            const stateSelect = document.getElementById('address-state');
            
            // Clear state select
            stateSelect.innerHTML = '<option value="">Chọn tỉnh/thành phố</option>';
            
            // Clear city select
            document.getElementById('address-city').innerHTML = '<option value="">Chọn quận/huyện</option>';
            
            if (countryId) {
                // Filter states by country
                const states = stateData.filter(state => state.country_id == countryId);
                
                // Add options
                states.forEach(state => {
                    const option = document.createElement('option');
                    option.value = state.id;
                    option.textContent = state.name;
                    stateSelect.appendChild(option);
                });
            }
        }
        
        function updateCities() {
            const stateId = document.getElementById('address-state').value;
            const citySelect = document.getElementById('address-city');
            
            // Clear city select
            citySelect.innerHTML = '<option value="">Chọn quận/huyện</option>';
            
            if (stateId) {
                // Filter cities by state
                const cities = cityData.filter(city => city.state_id == stateId);
                
                // Add options
                cities.forEach(city => {
                    const option = document.createElement('option');
                    option.value = city.id;
                    option.textContent = city.name;
                    citySelect.appendChild(option);
                });
            }
        }
        
        // Edit address modal
        async function editAddress(addressId) {
            // Get address details from addresses array
            const address = <?php echo json_encode($addresses); ?>.find(a => a.id == addressId);
            
            if (!address) {
                showNotification('Không tìm thấy địa chỉ', 'error');
                return;
            }
            
            // Fill form
            document.getElementById('address-id').value = address.id;
            document.getElementById('address-address').value = address.address;
            document.getElementById('address-country').value = address.country_id || '';
            updateStates();
            
            if (address.state_id) {
                document.getElementById('address-state').value = address.state_id;
                updateCities();
            }
            
            if (address.city_id) {
                document.getElementById('address-city').value = address.city_id;
            }
            
            document.getElementById('address-postal').value = address.postal_code || '';
            document.getElementById('address-phone').value = address.phone || '';
            
            openModal('address-modal');
        }
        
        async function updateAddress() {
            const addressId = document.getElementById('address-id').value;
            const address = document.getElementById('address-address').value.trim();
            const countryId = document.getElementById('address-country').value;
            const stateId = document.getElementById('address-state').value;
            const cityId = document.getElementById('address-city').value;
            const postalCode = document.getElementById('address-postal').value.trim();
            const phone = document.getElementById('address-phone').value.trim();
            
            // Validation
            if (!address) {
                showNotification('Vui lòng nhập địa chỉ', 'error');
                return;
            }
            
            if (!countryId) {
                showNotification('Vui lòng chọn quốc gia', 'error');
                return;
            }
            
            if (!stateId) {
                showNotification('Vui lòng chọn tỉnh/thành phố', 'error');
                return;
            }
            
            // Update button state
            const addressBtn = document.getElementById('update-address-btn');
            addressBtn.disabled = true;
            addressBtn.innerHTML = '<span class="loading"></span> Đang xử lý';
            
            const result = await makeRequest('update_address', {
                address_id: addressId,
                address,
                country_id: countryId,
                state_id: stateId,
                city_id: cityId || 0,
                postal_code: postalCode,
                phone
            });
            
            if (result) {
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                addressBtn.disabled = false;
                addressBtn.textContent = 'Cập nhật';
            }
        }
        
        async function deleteAddress(addressId) {
            if (!confirm('Bạn có chắc chắn muốn xóa địa chỉ này?')) {
                return;
            }
            
            const result = await makeRequest('delete_address', {
                address_id: addressId
            });
            
            if (result) {
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        }
        
        // Ban/unban user
        async function banUser() {
            if (!confirm('Bạn có chắc chắn muốn cấm người dùng này?')) {
                return;
            }
            
            const result = await makeRequest('ban_user', {});
            
            if (result) {
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        }
        
        async function unbanUser() {
            if (!confirm('Bạn có chắc chắn muốn bỏ cấm người dùng này?')) {
                return;
            }
            
            const result = await makeRequest('unban_user', {});
            
            if (result) {
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        }
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // Hide and remove notification after 3 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }
        
        // Mobile responsive handling
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
            console.log('🚀 User Details - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ User Details - Ready!');
            console.log('👤 User ID:', <?php echo $user_id; ?>);
        });
    </script>
</body>
</html>