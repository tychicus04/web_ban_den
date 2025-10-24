<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Include database config
require_once '../config.php';

$db = getDBConnection();

// Authentication check - FIXED: Check user_type with null coalescing operator
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
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

// Check if shop ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: sellers.php?error=invalid_id');
    exit;
}

$shop_id = (int)$_GET['id'];

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
            case 'approve_shop':
                $stmt = $db->prepare("UPDATE shops SET verification_status = 1 WHERE id = ?");
                $stmt->execute([$shop_id]);
                echo json_encode(['success' => true, 'message' => 'Cửa hàng đã được xác thực']);
                break;
                
            case 'reject_shop':
                $stmt = $db->prepare("UPDATE shops SET verification_status = 0 WHERE id = ?");
                $stmt->execute([$shop_id]);
                echo json_encode(['success' => true, 'message' => 'Đã từ chối xác thực cửa hàng']);
                break;
                
            case 'ban_shop':
                // Get user_id from shop
                $stmt = $db->prepare("SELECT user_id FROM shops WHERE id = ?");
                $stmt->execute([$shop_id]);
                $shop_data = $stmt->fetch();
                $user_id = $shop_data ? $shop_data['user_id'] : null;
                
                if ($user_id) {
                    $stmt = $db->prepare("UPDATE users SET banned = 1 WHERE id = ? AND id != ?");
                    $stmt->execute([$user_id, $_SESSION['user_id']]);
                    echo json_encode(['success' => true, 'message' => 'Đã cấm người bán']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Không tìm thấy người dùng']);
                }
                break;
                
            case 'unban_shop':
                // Get user_id from shop
                $stmt = $db->prepare("SELECT user_id FROM shops WHERE id = ?");
                $stmt->execute([$shop_id]);
                $shop_data = $stmt->fetch();
                $user_id = $shop_data ? $shop_data['user_id'] : null;
                
                if ($user_id) {
                    $stmt = $db->prepare("UPDATE users SET banned = 0 WHERE id = ?");
                    $stmt->execute([$user_id]);
                    echo json_encode(['success' => true, 'message' => 'Đã bỏ cấm người bán']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Không tìm thấy người dùng']);
                }
                break;
                
            case 'update_commission':
                $commission = (float)$_POST['commission'];
                
                if ($commission < 0 || $commission > 100) {
                    echo json_encode(['success' => false, 'message' => 'Hoa hồng phải từ 0 đến 100%']);
                    break;
                }
                
                $stmt = $db->prepare("UPDATE shops SET commission_percentage = ? WHERE id = ?");
                $stmt->execute([$commission, $shop_id]);
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật tỷ lệ hoa hồng']);
                break;
                
            case 'process_payment':
                // Begin transaction
                $db->beginTransaction();
                try {
                    // Get admin_to_pay amount
                    $stmt = $db->prepare("SELECT user_id, admin_to_pay FROM shops WHERE id = ?");
                    $stmt->execute([$shop_id]);
                    $shop = $stmt->fetch();
                    
                    if (!$shop || $shop['admin_to_pay'] <= 0) {
                        throw new Exception('Không có thanh toán cần xử lý');
                    }
                    
                    $amount = $shop['admin_to_pay'];
                    $seller_id = $shop['user_id'];
                    
                    // Insert payment record
                    $stmt = $db->prepare("
                        INSERT INTO payments (
                            seller_id, amount, payment_details, payment_method, txn_code, 
                            created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, 'Thanh toán thủ công', ?, NOW(), NOW()
                        )
                    ");
                    $payment_details = json_encode([
                        'processed_by' => $_SESSION['user_id'],
                        'processed_at' => date('Y-m-d H:i:s'),
                        'notes' => 'Thanh toán từ admin panel'
                    ]);
                    $txn_code = 'PAY-' . strtoupper(substr(md5(uniqid()), 0, 8));
                    $stmt->execute([$seller_id, $amount, $payment_details, $txn_code]);
                    
                    // Reset admin_to_pay to 0
                    $stmt = $db->prepare("UPDATE shops SET admin_to_pay = 0 WHERE id = ?");
                    $stmt->execute([$shop_id]);
                    
                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'Đã xử lý thanh toán: ' . formatCurrency($amount)]);
                } catch (Exception $e) {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
                break;
                
            case 'update_shop':
                $name = trim($_POST['name']);
                $phone = trim($_POST['phone']);
                $address = trim($_POST['address']);
                
                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Tên cửa hàng không được để trống']);
                    break;
                }
                
                $stmt = $db->prepare("
                    UPDATE shops 
                    SET name = ?, phone = ?, address = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$name, $phone, $address, $shop_id]);
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật thông tin cửa hàng']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("Shop action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Get shop details
$shop = null;
try {
    $stmt = $db->prepare("
        SELECT sh.*, 
               u.id as user_id, u.name as user_name, u.email, u.phone as user_phone, 
               u.banned, u.created_at as user_created_at, u.avatar as user_avatar,
               u.user_type, /* FIXED: Added user_type to the query */
               s.id as seller_id, s.verification_status as seller_verification_status, 
               s.verification_info, s.bank_name, s.bank_acc_name, s.bank_acc_no,
               s.bank_routing_no, s.bank_payment_status,
               (SELECT COUNT(*) FROM products p WHERE p.user_id = sh.user_id) as product_count,
               (SELECT COUNT(*) FROM orders o WHERE o.seller_id = sh.user_id) as order_count,
               (SELECT SUM(grand_total) FROM orders o WHERE o.seller_id = sh.user_id) as total_earnings
        FROM shops sh
        JOIN users u ON sh.user_id = u.id
        LEFT JOIN sellers s ON u.id = s.user_id
        WHERE sh.id = ?
    ");
    $stmt->execute([$shop_id]);
    $shop = $stmt->fetch();
    
    if (!$shop) {
        header('Location: sellers.php?error=shop_not_found');
        exit;
    }
    
    // Get shop payment history
    $stmt = $db->prepare("
        SELECT p.*, u.name as admin_name
        FROM payments p
        LEFT JOIN users u ON p.payment_details LIKE CONCAT('%', u.id, '%')
        WHERE p.seller_id = ?
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$shop['user_id']]);
    $payments = $stmt->fetchAll();
    
    // Get shop products
    $stmt = $db->prepare("
        SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.user_id = ?
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$shop['user_id']]);
    $products = $stmt->fetchAll();
    
    // Get shop orders
    $stmt = $db->prepare("
        SELECT o.*
        FROM orders o
        WHERE o.seller_id = ?
        ORDER BY o.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$shop['user_id']]);
    $orders = $stmt->fetchAll();
    
    // Get shop reviews
    $stmt = $db->prepare("
        SELECT r.*, p.name as product_name, u.name as user_name
        FROM reviews r
        JOIN products p ON r.product_id = p.id
        JOIN users u ON r.user_id = u.id
        WHERE p.user_id = ?
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$shop['user_id']]);
    $reviews = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Shop fetch error: " . $e->getMessage());
    header('Location: sellers.php?error=database_error');
    exit;
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');

// Parse verification info if available
$verification_info = [];
if (!empty($shop['verification_info'])) {
    $verification_info = json_decode($shop['verification_info'], true) ?? [];
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chi tiết cửa hàng - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Chi tiết cửa hàng - Admin <?php echo htmlspecialchars($site_name); ?>">
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
            margin-bottom: var(--space-6);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .page-title-wrapper {
            flex: 1;
        }
        
        .page-title {
            font-family: var(--font-heading);
            font-size: var(--text-3xl);
            font-weight: var(--font-bold);
            color: var(--text-primary);
            margin-bottom: var(--space-2);
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .page-subtitle {
            color: var(--text-secondary);
            font-size: var(--text-base);
        }
        
        .page-actions {
            display: flex;
            gap: var(--space-3);
        }
        
        /* Shop header */
        .shop-header {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            display: flex;
            gap: var(--space-6);
        }
        
        .shop-logo {
            width: 120px;
            height: 120px;
            border-radius: var(--rounded-xl);
            object-fit: cover;
            border: 1px solid var(--border);
            flex-shrink: 0;
        }
        
        .shop-logo-placeholder {
            width: 120px;
            height: 120px;
            border-radius: var(--rounded-xl);
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: var(--font-bold);
            font-size: var(--text-3xl);
            flex-shrink: 0;
        }
        
        .shop-info {
            flex: 1;
        }
        
        .shop-name {
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            margin-bottom: var(--space-2);
        }
        
        .shop-meta {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-4);
            margin-bottom: var(--space-4);
        }
        
        .shop-meta-item {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            color: var(--text-secondary);
            font-size: var(--text-sm);
        }
        
        .shop-actions {
            display: flex;
            gap: var(--space-3);
            margin-top: var(--space-4);
        }
        
        /* Grid Layout */
        .grid-layout {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: var(--space-6);
            margin-bottom: var(--space-8);
        }
        
        .grid-col-4 {
            grid-column: span 4;
        }
        
        .grid-col-8 {
            grid-column: span 8;
        }
        
        .grid-col-6 {
            grid-column: span 6;
        }
        
        .grid-col-12 {
            grid-column: span 12;
        }
        
        /* Stats Cards */
        .stats-row {
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
        
        /* Card component */
        .card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .card-header {
            padding: var(--space-5);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-title {
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .card-body {
            padding: var(--space-5);
            flex: 1;
            overflow: auto;
        }
        
        .card-footer {
            padding: var(--space-5);
            border-top: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        /* Form components */
        .form-group {
            margin-bottom: var(--space-4);
        }
        
        .form-group:last-child {
            margin-bottom: 0;
        }
        
        .form-label {
            display: block;
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--text-secondary);
            margin-bottom: var(--space-2);
        }
        
        .form-control {
            width: 100%;
            padding: var(--space-3) var(--space-4);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            background: var(--surface);
            transition: var(--transition-normal);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
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
        
        .status-badge.banned {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        .status-badge.verified {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .status-badge.pending {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .status-badge.unverified {
            background: rgba(107, 114, 128, 0.1);
            color: #374151;
        }
        
        .status-badge.due {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .status-badge.processed {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: var(--text-sm);
        }
        
        .table th {
            background: var(--gray-50);
            color: var(--text-secondary);
            font-weight: var(--font-semibold);
            padding: var(--space-3) var(--space-4);
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: var(--text-xs);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table td {
            padding: var(--space-3) var(--space-4);
            border-bottom: 1px solid var(--border-light);
        }
        
        .table tr:last-child td {
            border-bottom: none;
        }
        
        .table tr:hover {
            background: var(--gray-50);
        }
        
        /* Rating stars */
        .rating-stars {
            display: flex;
            align-items: center;
            gap: 2px;
            color: #f59e0b;
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
        
        .btn-link {
            background: transparent;
            color: var(--primary);
            padding: 0;
            font-weight: var(--font-medium);
        }
        
        .btn-link:hover {
            text-decoration: underline;
        }
        
        .btn-sm {
            padding: var(--space-2) var(--space-3);
            font-size: var(--text-xs);
        }
        
        .btn-lg {
            padding: var(--space-4) var(--space-6);
            font-size: var(--text-base);
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        
        .btn-icon.btn-sm {
            width: 28px;
            height: 28px;
        }
        
        /* Owner avatar */
        .owner-avatar {
            width: 48px;
            height: 48px;
            border-radius: var(--rounded-full);
            background: var(--secondary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: var(--font-bold);
            font-size: var(--text-lg);
        }
        
        /* Shop banners */
        .shop-banner {
            width: 100%;
            height: 120px;
            border-radius: var(--rounded-lg);
            object-fit: cover;
            margin-bottom: var(--space-3);
        }
        
        /* Commission input */
        .commission-input {
            width: 100px;
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--border);
            border-radius: var(--rounded);
            font-size: var(--text-sm);
            text-align: center;
        }
        
        /* Verify documents display */
        .verify-documents {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: var(--space-4);
        }
        
        .document-item {
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            overflow: hidden;
        }
        
        .document-image {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }
        
        .document-caption {
            padding: var(--space-2) var(--space-3);
            background: var(--gray-50);
            font-size: var(--text-xs);
            text-align: center;
            color: var(--text-secondary);
        }
        
        /* Info list */
        .info-list {
            list-style: none;
        }
        
        .info-item {
            display: flex;
            margin-bottom: var(--space-3);
            border-bottom: 1px solid var(--border-light);
            padding-bottom: var(--space-3);
        }
        
        .info-item:last-child {
            margin-bottom: 0;
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .info-label {
            flex: 0 0 40%;
            font-weight: var(--font-medium);
            color: var(--text-secondary);
        }
        
        .info-value {
            flex: 0 0 60%;
        }
        
        /* Data tables */
        .data-table-wrapper {
            overflow-x: auto;
        }
        
        /* Tab navigation */
        .tab-nav {
            display: flex;
            gap: var(--space-1);
            border-bottom: 1px solid var(--border-light);
            margin-bottom: var(--space-5);
        }
        
        .tab-link {
            padding: var(--space-3) var(--space-4);
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: var(--font-medium);
            border-bottom: 2px solid transparent;
            transition: var(--transition-normal);
        }
        
        .tab-link:hover {
            color: var(--text-primary);
        }
        
        .tab-link.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Product item */
        .product-item {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .product-image {
            width: 50px;
            height: 50px;
            border-radius: var(--rounded);
            object-fit: cover;
        }
        
        .product-details {
            flex: 1;
        }
        
        .product-name {
            font-weight: var(--font-medium);
            margin-bottom: var(--space-1);
        }
        
        .product-category {
            font-size: var(--text-xs);
            color: var(--text-tertiary);
        }
        
        /* Order status */
        .order-status {
            display: inline-block;
            padding: var(--space-1) var(--space-3);
            border-radius: var(--rounded-full);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            text-transform: uppercase;
        }
        
        .order-status.pending {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .order-status.processing {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .order-status.delivered {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .order-status.cancelled {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        /* Responsive */
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
            
            .grid-col-4,
            .grid-col-6,
            .grid-col-8 {
                grid-column: span 12;
            }
            
            .shop-header {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .shop-meta {
                justify-content: center;
            }
            
            .shop-actions {
                justify-content: center;
            }
        }
        
        @media (max-width: 768px) {
            .content {
                padding: var(--space-4);
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-4);
            }
            
            .page-actions {
                width: 100%;
                justify-content: flex-start;
            }
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
                        <a href="sellers.php" class="nav-link active">
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
                            <a href="sellers.php">Cửa hàng</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span><?php echo htmlspecialchars($shop['name'] ?? ''); ?></span>
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
                                <div class="user-role"><?php echo htmlspecialchars($admin['role_name'] ?? 'Administrator'); ?></div>
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
                    <div class="page-title-wrapper">
                        <h1 class="page-title">
                            <?php if ($shop['logo']): ?>
                                <img src="<?php echo htmlspecialchars($shop['logo']); ?>" alt="Shop logo" width="50" height="50" style="border-radius: var(--rounded-lg); object-fit: cover;">
                            <?php else: ?>
                                <div style="width: 50px; height: 50px; background: var(--primary-gradient); border-radius: var(--rounded-lg); display: flex; align-items: center; justify-content: center; color: white; font-weight: var(--font-bold);">
                                    <?php echo strtoupper(substr($shop['name'] ?? '', 0, 2)); ?>
                                </div>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($shop['name'] ?? ''); ?>
                            
                            <?php if (isset($shop['verification_status']) && $shop['verification_status'] == 1): ?>
                                <span class="status-badge verified" style="margin-left: 10px;">Đã xác thực</span>
                            <?php elseif (isset($shop['verification_status']) && $shop['verification_status'] == 2): ?>
                                <span class="status-badge pending" style="margin-left: 10px;">Chờ duyệt</span>
                            <?php else: ?>
                                <span class="status-badge unverified" style="margin-left: 10px;">Chưa xác thực</span>
                            <?php endif; ?>
                            
                            <?php if (isset($shop['banned']) && $shop['banned']): ?>
                                <span class="status-badge banned" style="margin-left: 10px;">Bị cấm</span>
                            <?php endif; ?>
                        </h1>
                        <p class="page-subtitle">ID: #<?php echo isset($shop['id']) ? $shop['id'] : ''; ?> | Tạo lúc: <?php echo isset($shop['created_at']) ? date('d/m/Y H:i', strtotime($shop['created_at'])) : ''; ?></p>
                    </div>
                    <div class="page-actions">
                        <?php if (isset($shop['verification_status']) && $shop['verification_status'] == 2): ?>
                            <button class="btn btn-success" onclick="approveShop()">
                                <span>✅</span>
                                <span>Duyệt cửa hàng</span>
                            </button>
                            <button class="btn btn-danger" onclick="rejectShop()">
                                <span>❌</span>
                                <span>Từ chối</span>
                            </button>
                        <?php endif; ?>
                        
                        <?php if (isset($shop['admin_to_pay']) && $shop['admin_to_pay'] > 0): ?>
                            <button class="btn btn-primary" onclick="processPayment()">
                                <span>💰</span>
                                <span>Xử lý thanh toán</span>
                            </button>
                        <?php endif; ?>
                        
                        <?php if (isset($shop['user_id']) && isset($_SESSION['user_id']) && $shop['user_id'] != $_SESSION['user_id']): ?>
                            <?php if (isset($shop['banned']) && $shop['banned']): ?>
                                <button class="btn btn-success" onclick="unbanShop()">
                                    <span>✅</span>
                                    <span>Bỏ cấm</span>
                                </button>
                            <?php else: ?>
                                <button class="btn btn-warning" onclick="banShop()">
                                    <span>🚫</span>
                                    <span>Cấm</span>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Sản phẩm</div>
                            <div class="stat-icon">🛒</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($shop['product_count'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card blue">
                        <div class="stat-header">
                            <div class="stat-title">Đơn hàng</div>
                            <div class="stat-icon">📦</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($shop['order_count'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card green">
                        <div class="stat-header">
                            <div class="stat-title">Tổng doanh thu</div>
                            <div class="stat-icon">💰</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($shop['total_earnings'] ?? 0); ?></div>
                    </div>
                    
                    <div class="stat-card purple">
                        <div class="stat-header">
                            <div class="stat-title">Đánh giá</div>
                            <div class="stat-icon">⭐</div>
                        </div>
                        <div class="stat-value">
                            <?php echo number_format($shop['rating'] ?? 0, 1); ?>
                            <small style="font-size: 14px;">(<?php echo number_format($shop['num_of_reviews'] ?? 0); ?>)</small>
                        </div>
                    </div>
                    
                    <div class="stat-card orange">
                        <div class="stat-header">
                            <div class="stat-title">Hoa hồng</div>
                            <div class="stat-icon">💸</div>
                        </div>
                        <div class="stat-value">
                            <?php echo number_format($shop['commission_percentage'] ?? 0, 1); ?>%
                            <?php if (isset($shop['admin_to_pay']) && $shop['admin_to_pay'] > 0): ?>
                                <small style="font-size: 14px; color: var(--warning);">(<?php echo formatCurrency($shop['admin_to_pay']); ?>)</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Tabs -->
                <div class="tab-nav">
                    <a href="#details" class="tab-link active" data-tab="details">Thông tin</a>
                    <a href="#products" class="tab-link" data-tab="products">Sản phẩm</a>
                    <a href="#orders" class="tab-link" data-tab="orders">Đơn hàng</a>
                    <a href="#reviews" class="tab-link" data-tab="reviews">Đánh giá</a>
                    <a href="#payments" class="tab-link" data-tab="payments">Thanh toán</a>
                    <a href="#banners" class="tab-link" data-tab="banners">Banners</a>
                </div>
                
                <!-- Tab Contents -->
                <div class="tab-content active" id="details-content">
                    <div class="grid-layout">
                        <!-- Shop Info -->
                        <div class="grid-col-6">
                            <div class="card">
                                <div class="card-header">
                                    <h2 class="card-title">Thông tin cửa hàng</h2>
                                    <button class="btn btn-secondary btn-sm" onclick="editShopInfo()">
                                        <span>✏️</span>
                                        <span>Sửa</span>
                                    </button>
                                </div>
                                <div class="card-body">
                                    <ul class="info-list">
                                        <li class="info-item">
                                            <div class="info-label">Tên cửa hàng:</div>
                                            <div class="info-value"><?php echo htmlspecialchars($shop['name'] ?? ''); ?></div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Đường dẫn:</div>
                                            <div class="info-value">
                                                <?php if (isset($shop['slug']) && $shop['slug']): ?>
                                                    <a href="../shop/<?php echo htmlspecialchars($shop['slug']); ?>" target="_blank">
                                                        <?php echo htmlspecialchars($shop['slug']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--text-tertiary);">Chưa có</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Số điện thoại:</div>
                                            <div class="info-value">
                                                <?php if (isset($shop['phone']) && $shop['phone']): ?>
                                                    <?php echo htmlspecialchars($shop['phone']); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-tertiary);">Chưa có</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Địa chỉ:</div>
                                            <div class="info-value">
                                                <?php if (isset($shop['address']) && $shop['address']): ?>
                                                    <?php echo htmlspecialchars($shop['address']); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-tertiary);">Chưa có</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Tỷ lệ hoa hồng:</div>
                                            <div class="info-value">
                                                <div style="display: flex; align-items: center; gap: var(--space-2);">
                                                    <input 
                                                        type="number" 
                                                        class="commission-input" 
                                                        value="<?php echo number_format($shop['commission_percentage'] ?? 0, 2); ?>" 
                                                        min="0" 
                                                        max="100" 
                                                        step="0.1"
                                                        onchange="updateCommission(this.value)"
                                                    >
                                                    <span>%</span>
                                                </div>
                                            </div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Thanh toán chưa xử lý:</div>
                                            <div class="info-value">
                                                <?php if (isset($shop['admin_to_pay']) && $shop['admin_to_pay'] > 0): ?>
                                                    <strong style="color: var(--warning);"><?php echo formatCurrency($shop['admin_to_pay']); ?></strong>
                                                    <button class="btn btn-success btn-sm" style="margin-left: 10px;" onclick="processPayment()">
                                                        <span>💰</span>
                                                        <span>Xử lý thanh toán</span>
                                                    </button>
                                                <?php else: ?>
                                                    <span style="color: var(--success);">Đã thanh toán</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Facebook:</div>
                                            <div class="info-value">
                                                <?php if (isset($shop['facebook']) && $shop['facebook']): ?>
                                                    <a href="<?php echo htmlspecialchars($shop['facebook']); ?>" target="_blank">
                                                        <?php echo htmlspecialchars($shop['facebook']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--text-tertiary);">Chưa có</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Instagram:</div>
                                            <div class="info-value">
                                                <?php if (isset($shop['instagram']) && $shop['instagram']): ?>
                                                    <a href="<?php echo htmlspecialchars($shop['instagram']); ?>" target="_blank">
                                                        <?php echo htmlspecialchars($shop['instagram']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--text-tertiary);">Chưa có</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Owner Info -->
                        <div class="grid-col-6">
                            <div class="card">
                                <div class="card-header">
                                    <h2 class="card-title">Thông tin chủ sở hữu</h2>
                                    <a href="user-edit.php?id=<?php echo $shop['user_id'] ?? ''; ?>" class="btn btn-secondary btn-sm">
                                        <span>👤</span>
                                        <span>Xem chi tiết</span>
                                    </a>
                                </div>
                                <div class="card-body">
                                    <div style="display: flex; align-items: center; gap: var(--space-4); margin-bottom: var(--space-4);">
                                        <div class="owner-avatar">
                                            <?php echo strtoupper(substr($shop['user_name'] ?? '', 0, 2)); ?>
                                        </div>
                                        <div>
                                            <h3 style="font-weight: var(--font-semibold); margin-bottom: var(--space-1);">
                                                <?php echo htmlspecialchars($shop['user_name'] ?? ''); ?>
                                            </h3>
                                            <div style="color: var(--text-secondary); font-size: var(--text-sm);">
                                                <?php echo htmlspecialchars($shop['user_type'] ?? 'Người bán'); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <ul class="info-list">
                                        <li class="info-item">
                                            <div class="info-label">ID:</div>
                                            <div class="info-value">#<?php echo $shop['user_id'] ?? ''; ?></div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Email:</div>
                                            <div class="info-value"><?php echo htmlspecialchars($shop['email'] ?? ''); ?></div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Số điện thoại:</div>
                                            <div class="info-value">
                                                <?php if (isset($shop['user_phone']) && $shop['user_phone']): ?>
                                                    <?php echo htmlspecialchars($shop['user_phone']); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-tertiary);">Chưa có</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Ngày tham gia:</div>
                                            <div class="info-value"><?php echo isset($shop['user_created_at']) ? date('d/m/Y H:i', strtotime($shop['user_created_at'])) : ''; ?></div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Trạng thái:</div>
                                            <div class="info-value">
                                                <?php if (isset($shop['banned']) && $shop['banned']): ?>
                                                    <span class="status-badge banned">Bị cấm</span>
                                                <?php else: ?>
                                                    <span class="status-badge active">Đang hoạt động</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            
                            <!-- Bank Info -->
                            <div class="card" style="margin-top: var(--space-6);">
                                <div class="card-header">
                                    <h2 class="card-title">Thông tin ngân hàng</h2>
                                </div>
                                <div class="card-body">
                                    <ul class="info-list">
                                        <li class="info-item">
                                            <div class="info-label">Tên ngân hàng:</div>
                                            <div class="info-value">
                                                <?php if (isset($shop['bank_name']) && $shop['bank_name']): ?>
                                                    <?php echo htmlspecialchars($shop['bank_name']); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-tertiary);">Chưa có</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Tên tài khoản:</div>
                                            <div class="info-value">
                                                <?php if (isset($shop['bank_acc_name']) && $shop['bank_acc_name']): ?>
                                                    <?php echo htmlspecialchars($shop['bank_acc_name']); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-tertiary);">Chưa có</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Số tài khoản:</div>
                                            <div class="info-value">
                                                <?php if (isset($shop['bank_acc_no']) && $shop['bank_acc_no']): ?>
                                                    <?php echo htmlspecialchars($shop['bank_acc_no']); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-tertiary);">Chưa có</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                        <li class="info-item">
                                            <div class="info-label">Routing Number:</div>
                                            <div class="info-value">
                                                <?php if (isset($shop['bank_routing_no']) && $shop['bank_routing_no']): ?>
                                                    <?php echo htmlspecialchars($shop['bank_routing_no']); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-tertiary);">Chưa có</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Verification Info -->
                        <?php if (!empty($verification_info)): ?>
                        <div class="grid-col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h2 class="card-title">Thông tin xác thực</h2>
                                    <?php if (isset($shop['verification_status']) && $shop['verification_status'] == 2): ?>
                                        <div>
                                            <button class="btn btn-success btn-sm" onclick="approveShop()">
                                                <span>✅</span>
                                                <span>Duyệt</span>
                                            </button>
                                            <button class="btn btn-danger btn-sm" onclick="rejectShop()">
                                                <span>❌</span>
                                                <span>Từ chối</span>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <ul class="info-list">
                                        <?php foreach ($verification_info as $key => $value): ?>
                                            <?php if ($key != 'verification_images'): ?>
                                                <li class="info-item">
                                                    <div class="info-label"><?php echo ucwords(str_replace('_', ' ', $key)); ?>:</div>
                                                    <div class="info-value"><?php echo htmlspecialchars($value); ?></div>
                                                </li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </ul>
                                    
                                    <?php if (isset($verification_info['verification_images']) && !empty($verification_info['verification_images'])): ?>
                                        <h3 style="margin: var(--space-4) 0;">Hình ảnh xác thực</h3>
                                        <div class="verify-documents">
                                            <?php foreach ($verification_info['verification_images'] as $index => $image): ?>
                                                <div class="document-item">
                                                    <img src="<?php echo htmlspecialchars($image); ?>" alt="Verification image <?php echo $index + 1; ?>" class="document-image">
                                                    <div class="document-caption">Hình ảnh <?php echo $index + 1; ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Products Tab -->
                <div class="tab-content" id="products-content">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Sản phẩm (<?php echo number_format($shop['product_count'] ?? 0); ?>)</h2>
                            <a href="products.php?seller_id=<?php echo $shop['user_id'] ?? ''; ?>" class="btn btn-primary btn-sm">
                                <span>🔍</span>
                                <span>Xem tất cả</span>
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="data-table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Sản phẩm</th>
                                            <th>Giá</th>
                                            <th>Danh mục</th>
                                            <th>Số lượng</th>
                                            <th>Đã bán</th>
                                            <th>Trạng thái</th>
                                            <th>Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($products)): ?>
                                            <tr>
                                                <td colspan="8" style="text-align: center; padding: var(--space-6);">Không có sản phẩm nào</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($products as $product): ?>
                                                <tr>
                                                    <td>#<?php echo $product['id']; ?></td>
                                                    <td>
                                                        <div class="product-item">
                                                            <?php if (isset($product['thumbnail_img']) && $product['thumbnail_img']): ?>
                                                                <img src="<?php echo htmlspecialchars($product['thumbnail_img']); ?>" alt="Product image" class="product-image">
                                                            <?php else: ?>
                                                                <div class="product-image" style="background: var(--gray-200); display: flex; align-items: center; justify-content: center; color: var(--text-tertiary);">
                                                                    🖼️
                                                                </div>
                                                            <?php endif; ?>
                                                            <div class="product-details">
                                                                <div class="product-name"><?php echo htmlspecialchars($product['name'] ?? ''); ?></div>
                                                                <div class="product-category"><?php echo htmlspecialchars($product['category_name'] ?? 'Không có danh mục'); ?></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo formatCurrency($product['unit_price'] ?? 0); ?></td>
                                                    <td><?php echo htmlspecialchars($product['category_name'] ?? 'Không có danh mục'); ?></td>
                                                    <td><?php echo number_format($product['current_stock'] ?? 0); ?></td>
                                                    <td><?php echo number_format($product['num_of_sale'] ?? 0); ?></td>
                                                    <td>
                                                        <?php if (isset($product['published']) && $product['published']): ?>
                                                            <span class="status-badge active">Đang bán</span>
                                                        <?php else: ?>
                                                            <span class="status-badge unverified">Đã ẩn</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="product-edit.php?id=<?php echo $product['id']; ?>" class="btn btn-secondary btn-sm">
                                                            <span>👁️</span>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php if (!empty($products) && isset($shop['product_count']) && $shop['product_count'] > count($products)): ?>
                                <div style="text-align: center; margin-top: var(--space-5);">
                                    <a href="products.php?seller_id=<?php echo $shop['user_id'] ?? ''; ?>" class="btn btn-secondary">
                                        <span>🔍</span>
                                        <span>Xem tất cả <?php echo number_format($shop['product_count']); ?> sản phẩm</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Orders Tab -->
                <div class="tab-content" id="orders-content">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Đơn hàng (<?php echo number_format($shop['order_count'] ?? 0); ?>)</h2>
                            <a href="orders.php?seller_id=<?php echo $shop['user_id'] ?? ''; ?>" class="btn btn-primary btn-sm">
                                <span>🔍</span>
                                <span>Xem tất cả</span>
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="data-table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Mã đơn hàng</th>
                                            <th>Khách hàng</th>
                                            <th>Tổng tiền</th>
                                            <th>Thanh toán</th>
                                            <th>Trạng thái</th>
                                            <th>Ngày tạo</th>
                                            <th>Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($orders)): ?>
                                            <tr>
                                                <td colspan="8" style="text-align: center; padding: var(--space-6);">Không có đơn hàng nào</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($orders as $order): ?>
                                                <tr>
                                                    <td>#<?php echo $order['id']; ?></td>
                                                    <td><?php echo htmlspecialchars($order['code'] ?? ''); ?></td>
                                                    <td>
                                                        <?php if (isset($order['user_id']) && $order['user_id']): ?>
                                                            <a href="user-edit.php?id=<?php echo $order['user_id']; ?>">
                                                                #<?php echo $order['user_id']; ?>
                                                            </a>
                                                        <?php else: ?>
                                                            Khách vãng lai
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo formatCurrency($order['grand_total'] ?? 0); ?></td>
                                                    <td>
                                                        <?php if (isset($order['payment_status']) && $order['payment_status'] == 'paid'): ?>
                                                            <span class="status-badge active">Đã thanh toán</span>
                                                        <?php else: ?>
                                                            <span class="status-badge pending">Chưa thanh toán</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $status_class = '';
                                                        $status_text = 'Chờ xử lý';
                                                        
                                                        if (isset($order['delivery_status'])) {
                                                            switch ($order['delivery_status']) {
                                                                case 'pending':
                                                                    $status_class = 'pending';
                                                                    $status_text = 'Chờ xử lý';
                                                                    break;
                                                                case 'processing':
                                                                    $status_class = 'processing';
                                                                    $status_text = 'Đang xử lý';
                                                                    break;
                                                                case 'delivered':
                                                                    $status_class = 'delivered';
                                                                    $status_text = 'Đã giao';
                                                                    break;
                                                                case 'cancelled':
                                                                    $status_class = 'cancelled';
                                                                    $status_text = 'Đã hủy';
                                                                    break;
                                                                default:
                                                                    $status_class = 'pending';
                                                                    $status_text = 'Chờ xử lý';
                                                            }
                                                        }
                                                        ?>
                                                        <span class="order-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                                    </td>
                                                    <td><?php echo isset($order['date']) ? date('d/m/Y H:i', $order['date']) : ''; ?></td>
                                                    <td>
                                                        <a href="order-details.php?id=<?php echo $order['id']; ?>" class="btn btn-secondary btn-sm">
                                                            <span>👁️</span>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php if (!empty($orders) && isset($shop['order_count']) && $shop['order_count'] > count($orders)): ?>
                                <div style="text-align: center; margin-top: var(--space-5);">
                                    <a href="orders.php?seller_id=<?php echo $shop['user_id'] ?? ''; ?>" class="btn btn-secondary">
                                        <span>🔍</span>
                                        <span>Xem tất cả <?php echo number_format($shop['order_count']); ?> đơn hàng</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Reviews Tab -->
                <div class="tab-content" id="reviews-content">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Đánh giá (<?php echo number_format($shop['num_of_reviews'] ?? 0); ?>)</h2>
                            <a href="reviews.php?seller_id=<?php echo $shop['user_id'] ?? ''; ?>" class="btn btn-primary btn-sm">
                                <span>🔍</span>
                                <span>Xem tất cả</span>
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="data-table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Người dùng</th>
                                            <th>Sản phẩm</th>
                                            <th>Đánh giá</th>
                                            <th>Nội dung</th>
                                            <th>Ngày tạo</th>
                                            <th>Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($reviews)): ?>
                                            <tr>
                                                <td colspan="7" style="text-align: center; padding: var(--space-6);">Không có đánh giá nào</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($reviews as $review): ?>
                                                <tr>
                                                    <td>#<?php echo $review['id']; ?></td>
                                                    <td>
                                                        <a href="user-edit.php?id=<?php echo $review['user_id']; ?>">
                                                            <?php echo htmlspecialchars($review['user_name'] ?? ''); ?>
                                                        </a>
                                                    </td>
                                                    <td>
                                                        <a href="product-edit.php?id=<?php echo $review['product_id']; ?>">
                                                            <?php echo htmlspecialchars($review['product_name'] ?? ''); ?>
                                                        </a>
                                                    </td>
                                                    <td>
                                                        <div class="rating-stars">
                                                            <?php 
                                                            $rating = $review['rating'] ?? 0;
                                                            for ($i = 1; $i <= 5; $i++) {
                                                                echo $i <= $rating ? '★' : '☆';
                                                            }
                                                            ?>
                                                        </div>
                                                    </td>
                                                    <td><?php echo isset($review['comment']) ? htmlspecialchars(substr($review['comment'], 0, 50)) . (strlen($review['comment']) > 50 ? '...' : '') : ''; ?></td>
                                                    <td><?php echo isset($review['created_at']) ? date('d/m/Y H:i', strtotime($review['created_at'])) : ''; ?></td>
                                                    <td>
                                                        <a href="review-details.php?id=<?php echo $review['id']; ?>" class="btn btn-secondary btn-sm">
                                                            <span>👁️</span>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php if (!empty($reviews) && isset($shop['num_of_reviews']) && $shop['num_of_reviews'] > count($reviews)): ?>
                                <div style="text-align: center; margin-top: var(--space-5);">
                                    <a href="reviews.php?seller_id=<?php echo $shop['user_id'] ?? ''; ?>" class="btn btn-secondary">
                                        <span>🔍</span>
                                        <span>Xem tất cả <?php echo number_format($shop['num_of_reviews']); ?> đánh giá</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Payments Tab -->
                <div class="tab-content" id="payments-content">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Lịch sử thanh toán</h2>
                            <?php if (isset($shop['admin_to_pay']) && $shop['admin_to_pay'] > 0): ?>
                                <button class="btn btn-success btn-sm" onclick="processPayment()">
                                    <span>💰</span>
                                    <span>Xử lý thanh toán (<?php echo formatCurrency($shop['admin_to_pay']); ?>)</span>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="data-table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Số tiền</th>
                                            <th>Phương thức</th>
                                            <th>Mã giao dịch</th>
                                            <th>Người xử lý</th>
                                            <th>Ngày thanh toán</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($payments)): ?>
                                            <tr>
                                                <td colspan="6" style="text-align: center; padding: var(--space-6);">Không có giao dịch nào</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($payments as $payment): ?>
                                                <tr>
                                                    <td>#<?php echo $payment['id']; ?></td>
                                                    <td><?php echo formatCurrency($payment['amount'] ?? 0); ?></td>
                                                    <td><?php echo htmlspecialchars($payment['payment_method'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($payment['txn_code'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($payment['admin_name'] ?? 'System'); ?></td>
                                                    <td><?php echo isset($payment['created_at']) ? date('d/m/Y H:i', strtotime($payment['created_at'])) : ''; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Banners Tab -->
                <div class="tab-content" id="banners-content">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Banners của cửa hàng</h2>
                        </div>
                        <div class="card-body">
                            <?php if (isset($shop['sliders']) && $shop['sliders']): ?>
                                <h3 style="margin-bottom: var(--space-3);">Sliders</h3>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: var(--space-4); margin-bottom: var(--space-6);">
                                    <?php
                                    $sliders = json_decode($shop['sliders'], true);
                                    if (is_array($sliders)):
                                        foreach ($sliders as $slider):
                                    ?>
                                        <div>
                                            <img src="<?php echo htmlspecialchars($slider); ?>" alt="Slider image" class="shop-banner">
                                        </div>
                                    <?php
                                        endforeach;
                                    endif;
                                    ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (isset($shop['top_banner']) && $shop['top_banner']): ?>
                                <h3 style="margin-bottom: var(--space-3);">Top Banner</h3>
                                <div style="margin-bottom: var(--space-6);">
                                    <img src="<?php echo htmlspecialchars($shop['top_banner']); ?>" alt="Top banner" class="shop-banner" style="height: 150px;">
                                </div>
                            <?php endif; ?>
                            
                            <?php if (isset($shop['banner_full_width_1']) && $shop['banner_full_width_1']): ?>
                                <h3 style="margin-bottom: var(--space-3);">Banner Full Width 1</h3>
                                <div style="margin-bottom: var(--space-6);">
                                    <img src="<?php echo htmlspecialchars($shop['banner_full_width_1']); ?>" alt="Banner full width 1" class="shop-banner" style="height: 150px;">
                                </div>
                            <?php endif; ?>
                            
                            <?php if (isset($shop['banners_half_width']) && $shop['banners_half_width']): ?>
                                <h3 style="margin-bottom: var(--space-3);">Banners Half Width</h3>
                                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-4); margin-bottom: var(--space-6);">
                                    <?php
                                    $half_banners = json_decode($shop['banners_half_width'], true);
                                    if (is_array($half_banners)):
                                        foreach ($half_banners as $banner):
                                    ?>
                                        <div>
                                            <img src="<?php echo htmlspecialchars($banner); ?>" alt="Half width banner" class="shop-banner">
                                        </div>
                                    <?php
                                        endforeach;
                                    endif;
                                    ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (isset($shop['banner_full_width_2']) && $shop['banner_full_width_2']): ?>
                                <h3 style="margin-bottom: var(--space-3);">Banner Full Width 2</h3>
                                <div style="margin-bottom: var(--space-6);">
                                    <img src="<?php echo htmlspecialchars($shop['banner_full_width_2']); ?>" alt="Banner full width 2" class="shop-banner" style="height: 150px;">
                                </div>
                            <?php endif; ?>
                            
                            <?php if (
                                (!isset($shop['sliders']) || !$shop['sliders']) && 
                                (!isset($shop['top_banner']) || !$shop['top_banner']) && 
                                (!isset($shop['banner_full_width_1']) || !$shop['banner_full_width_1']) && 
                                (!isset($shop['banners_half_width']) || !$shop['banners_half_width']) && 
                                (!isset($shop['banner_full_width_2']) || !$shop['banner_full_width_2'])
                            ): ?>
                                <div style="text-align: center; padding: var(--space-10) 0; color: var(--text-tertiary);">
                                    Cửa hàng chưa có banner nào
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Edit Shop Info Modal (CSS only, would need actual HTML) -->
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background-color: var(--surface);
            margin: 10% auto;
            padding: var(--space-6);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-xl);
            width: 500px;
            max-width: 90%;
            position: relative;
            animation: modalOpen 0.3s ease;
        }
        
        @keyframes modalOpen {
            from {opacity: 0; transform: translateY(-30px);}
            to {opacity: 1; transform: translateY(0);}
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
        }
        
        .modal-title {
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
        }
        
        .modal-close {
            font-size: var(--text-2xl);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-tertiary);
            transition: var(--transition-normal);
        }
        
        .modal-close:hover {
            color: var(--text-primary);
        }
        
        .modal-body {
            margin-bottom: var(--space-5);
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
        }
    </style>

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
        
        // Tab navigation
        const tabLinks = document.querySelectorAll('.tab-link');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                
                const tabId = this.getAttribute('data-tab');
                
                // Remove active class from all links and contents
                tabLinks.forEach(el => el.classList.remove('active'));
                tabContents.forEach(el => el.classList.remove('active'));
                
                // Add active class to current link and content
                this.classList.add('active');
                document.getElementById(`${tabId}-content`).classList.add('active');
                
                // Update URL hash
                window.location.hash = tabId;
            });
        });
        
        // Check for hash in URL and activate corresponding tab
        function checkUrlHash() {
            const hash = window.location.hash.substring(1);
            if (hash) {
                const tabLink = document.querySelector(`.tab-link[data-tab="${hash}"]`);
                if (tabLink) {
                    tabLink.click();
                }
            }
        }
        
        // Check hash on page load
        window.addEventListener('DOMContentLoaded', checkUrlHash);
        
        // AJAX helper function
        async function makeRequest(action, data = {}) {
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
                    return true;
                } else {
                    showNotification(result.message, 'error');
                    return false;
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
                return false;
            }
        }
        
        // Shop actions
        async function approveShop() {
            if (!confirm('Bạn có chắc chắn muốn duyệt cửa hàng này?')) {
                return;
            }
            
            const success = await makeRequest('approve_shop');
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function rejectShop() {
            if (!confirm('Bạn có chắc chắn muốn từ chối xác thực cửa hàng này?')) {
                return;
            }
            
            const success = await makeRequest('reject_shop');
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function processPayment() {
            if (!confirm('Bạn có chắc chắn muốn xử lý thanh toán cho cửa hàng này?')) {
                return;
            }
            
            const success = await makeRequest('process_payment');
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function banShop() {
            if (!confirm('Bạn có chắc chắn muốn cấm cửa hàng này?')) {
                return;
            }
            
            const success = await makeRequest('ban_shop');
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function unbanShop() {
            if (!confirm('Bạn có chắc chắn muốn bỏ cấm cửa hàng này?')) {
                return;
            }
            
            const success = await makeRequest('unban_shop');
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function updateCommission(commission) {
            const numericCommission = parseFloat(commission);
            
            if (isNaN(numericCommission) || numericCommission < 0 || numericCommission > 100) {
                showNotification('Hoa hồng phải từ 0 đến 100%', 'error');
                return;
            }
            
            const success = await makeRequest('update_commission', { commission: numericCommission });
            if (success) {
                // No need to reload, just update the UI
            }
        }
        
        // Edit shop info modal
        function editShopInfo() {
            // Create the modal HTML dynamically
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.id = 'editShopModal';
            
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">Chỉnh sửa thông tin cửa hàng</h3>
                        <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label class="form-label">Tên cửa hàng</label>
                            <input type="text" class="form-control" id="shop-name" value="<?php echo htmlspecialchars($shop['name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Số điện thoại</label>
                            <input type="text" class="form-control" id="shop-phone" value="<?php echo htmlspecialchars($shop['phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Địa chỉ</label>
                            <textarea class="form-control" id="shop-address"><?php echo htmlspecialchars($shop['address'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">Hủy</button>
                        <button type="button" class="btn btn-primary" onclick="saveShopInfo()">Lưu</button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            modal.style.display = 'block';
        }
        
        function closeModal() {
            const modal = document.getElementById('editShopModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.removeChild(modal);
            }
        }
        
        async function saveShopInfo() {
            const name = document.getElementById('shop-name').value;
            const phone = document.getElementById('shop-phone').value;
            const address = document.getElementById('shop-address').value;
            
            const success = await makeRequest('update_shop', {
                name: name,
                phone: phone,
                address: address
            });
            
            if (success) {
                closeModal();
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Click outside modal to close
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('editShopModal');
            if (modal && e.target === modal) {
                closeModal();
            }
        });
        
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
            console.log('🚀 Shop Details - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Shop Details - Ready!');
        });
    </script>
</body>
</html>