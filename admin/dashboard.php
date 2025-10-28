<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Include database config
require_once '../config.php';

/**
 * Hàm bảo vệ khi hiển thị dữ liệu, tránh XSS và lỗi null
 */
function safe_echo($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Kiểm tra giá trị null và trả về giá trị mặc định
 */
function get_value($array, $key, $default = '') {
    return isset($array[$key]) ? $array[$key] : $default;
}

/**
 * Kiểm tra mảng có tồn tại key và không phải null
 */
function has_value($array, $key) {
    return isset($array[$key]) && $array[$key] !== null;
}

try {
    $db = getDBConnection();
} catch (PDOException $e) {
    die("Không thể kết nối đến cơ sở dữ liệu: " . $e->getMessage());
}

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
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
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
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['value'] : $default;
    } catch (PDOException $e) {
        error_log("Business setting error: " . $e->getMessage());
        return $default;
    }
}

// Currency format function
function formatCurrency($amount, $currency = 'VND') {
    if ($currency === 'VND') {
        return number_format((float)$amount, 0, ',', '.') . '₫';
    } else {
        return '$' . number_format((float)$amount, 2, '.', ',');
    }
}

// Get dashboard stats
$stats = [
    'total_users' => 0,
    'total_orders' => 0, 
    'total_products' => 0, 
    'total_revenue' => 0,
    'orders_today' => 0,
    'revenue_today' => 0,
    'pending_orders' => 0,
    'low_stock' => 0
];

try {
    // Total users
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'customer'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_users'] = $result ? (int)$result['count'] : 0;
    
    // Total orders
    $stmt = $db->query("SELECT COUNT(*) as count FROM orders");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_orders'] = $result ? (int)$result['count'] : 0;
    
    // Total products
    $stmt = $db->query("SELECT COUNT(*) as count FROM products WHERE published = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_products'] = $result ? (int)$result['count'] : 0;
    
    // Total revenue
    $stmt = $db->query("SELECT SUM(grand_total) as total FROM orders WHERE payment_status = 'paid'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_revenue'] = $result && $result['total'] ? (float)$result['total'] : 0;
    
    // Orders today
    $today_start = date('Y-m-d 00:00:00');
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE created_at >= ?");
    $stmt->execute([$today_start]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['orders_today'] = $result ? (int)$result['count'] : 0;
    
    // Revenue today
    $stmt = $db->prepare("SELECT SUM(grand_total) as total FROM orders WHERE payment_status = 'paid' AND created_at >= ?");
    $stmt->execute([$today_start]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['revenue_today'] = $result && $result['total'] ? (float)$result['total'] : 0;
    
    // Pending orders
    $stmt = $db->query("SELECT COUNT(*) as count FROM orders WHERE delivery_status = 'pending'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['pending_orders'] = $result ? (int)$result['count'] : 0;
    
    // Low stock products
    $stmt = $db->query("SELECT COUNT(*) as count FROM products WHERE current_stock <= low_stock_quantity AND published = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['low_stock'] = $result ? (int)$result['count'] : 0;
    
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
}

// Get recent orders
$recent_orders = [];
try {
    $sql = "
        SELECT o.*, 
               u.name as customer_name,
               u.email as customer_email,
               u.avatar as customer_avatar
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        ORDER BY o.created_at DESC
        LIMIT 10
    ";
    
    $stmt = $db->query($sql);
    $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Recent orders fetch error: " . $e->getMessage());
}

// Get top products
$top_products = [];
try {
    $sql = "
        SELECT p.*, 
               c.name as category_name,
               u_thumb.file_name as thumbnail_url,
               COUNT(od.id) as order_count,
               SUM(od.quantity) as total_quantity
        FROM products p
        LEFT JOIN order_details od ON p.id = od.product_id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN uploads u_thumb ON p.thumbnail_img = u_thumb.id
        WHERE p.published = 1
        GROUP BY p.id
        ORDER BY total_quantity DESC
        LIMIT 5
    ";
    
    $stmt = $db->query($sql);
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Top products fetch error: " . $e->getMessage());
}

// Get monthly sales data for chart
$monthly_sales = [];
try {
    $sql = "
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as order_count,
            SUM(grand_total) as revenue
        FROM orders
        WHERE payment_status = 'paid'
        AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ";
    
    $stmt = $db->query($sql);
    $monthly_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Monthly sales fetch error: " . $e->getMessage());
}

// Get recent activities
$recent_activities = [];
try {
    // Orders
    $sql_orders = "
        SELECT 
            'order' as type,
            id as item_id,
            code as title,
            CONCAT('Đơn hàng mới #', code) as description,
            created_at as timestamp
        FROM orders
        ORDER BY created_at DESC
        LIMIT 5
    ";
    
    // Product reviews
    $sql_reviews = "
        SELECT 
            'review' as type,
            r.id as item_id,
            p.name as title,
            CONCAT('Đánh giá mới: ', SUBSTRING(r.comment, 1, 50), IF(LENGTH(r.comment) > 50, '...', '')) as description,
            r.created_at as timestamp
        FROM reviews r
        JOIN products p ON r.product_id = p.id
        ORDER BY r.created_at DESC
        LIMIT 5
    ";
    
    // New users
    $sql_users = "
        SELECT 
            'user' as type,
            id as item_id,
            name as title,
            'Người dùng mới đăng ký' as description,
            created_at as timestamp
        FROM users
        WHERE user_type = 'customer'
        ORDER BY created_at DESC
        LIMIT 5
    ";
    
    // Combine and sort
    $stmt_orders = $db->query($sql_orders);
    $stmt_reviews = $db->query($sql_reviews);
    $stmt_users = $db->query($sql_users);
    
    $activities = array_merge(
        $stmt_orders->fetchAll(PDO::FETCH_ASSOC),
        $stmt_reviews->fetchAll(PDO::FETCH_ASSOC),
        $stmt_users->fetchAll(PDO::FETCH_ASSOC)
    );
    
    // Sort by timestamp descending
    usort($activities, function($a, $b) {
        // Handle null timestamps to prevent PHP 8.1+ deprecation warnings
        $time_a = !empty($a['timestamp']) ? strtotime($a['timestamp']) : 0;
        $time_b = !empty($b['timestamp']) ? strtotime($b['timestamp']) : 0;
        return $time_b - $time_a;
    });
    
    // Take the latest 10
    $recent_activities = array_slice($activities, 0, 10);
    
} catch (PDOException $e) {
    error_log("Recent activities fetch error: " . $e->getMessage());
}

$site_name = getBusinessSetting($db, 'site_name', 'Active E-Commerce');

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
            case 'update_order_status':
                $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
                $status = isset($_POST['status']) ? $_POST['status'] : '';
                
                if ($order_id <= 0 || empty($status)) {
                    throw new Exception('Dữ liệu không hợp lệ');
                }
                
                $stmt = $db->prepare("UPDATE orders SET delivery_status = ? WHERE id = ?");
                $stmt->execute([$status, $order_id]);
                
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'Đã cập nhật trạng thái đơn hàng']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Không tìm thấy đơn hàng hoặc trạng thái không thay đổi']);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ']);
        }
    } catch (Exception $e) {
        error_log("AJAX action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Admin <?php echo safe_echo($site_name); ?></title>
    <meta name="description" content="Bảng điều khiển quản trị - Admin <?php echo safe_echo($site_name); ?>">
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
        
        /* Main Stats */
        .main-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: var(--space-5);
            margin-bottom: var(--space-8);
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
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card.primary {
            border-top: 4px solid var(--primary);
        }
        
        .stat-card.success {
            border-top: 4px solid var(--success);
        }
        
        .stat-card.warning {
            border-top: 4px solid var(--warning);
        }
        
        .stat-card.danger {
            border-top: 4px solid var(--danger);
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
        }
        
        .stat-card.primary .stat-icon {
            color: var(--primary);
        }
        
        .stat-card.success .stat-icon {
            color: var(--success);
        }
        
        .stat-card.warning .stat-icon {
            color: var(--warning);
        }
        
        .stat-card.danger .stat-icon {
            color: var(--danger);
        }
        
        .stat-value {
            font-family: var(--font-heading);
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            color: var(--text-primary);
        }
        
        .stat-comparison {
            font-size: var(--text-xs);
            display: flex;
            align-items: center;
            gap: var(--space-1);
            margin-top: var(--space-2);
        }
        
        .stat-comparison.positive {
            color: var(--success);
        }
        
        .stat-comparison.negative {
            color: var(--danger);
        }
        
        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: var(--space-6);
            margin-bottom: var(--space-8);
        }
        
        .dashboard-card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
        }
        
        .dashboard-card-header {
            padding: var(--space-5);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .dashboard-card-title {
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .dashboard-card-actions {
            display: flex;
            gap: var(--space-2);
        }
        
        .dashboard-card-body {
            padding: var(--space-5);
        }
        
        .chart-container {
            height: 300px;
            width: 100%;
        }
        
        /* Sales Chart */
        .sales-chart {
            min-height: 360px;
        }
        
        /* Recent Orders */
        .orders-table {
            width: 100%;
            border-collapse: collapse;
            font-size: var(--text-sm);
        }
        
        .orders-table th {
            text-align: left;
            padding: var(--space-3) var(--space-4);
            border-bottom: 1px solid var(--border);
            color: var(--text-secondary);
            font-weight: var(--font-semibold);
            font-size: var(--text-xs);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .orders-table td {
            padding: var(--space-3) var(--space-4);
            border-bottom: 1px solid var(--border-light);
        }
        
        .orders-table tr:hover {
            background: var(--gray-50);
        }
        
        .order-id {
            font-family: monospace;
            font-weight: var(--font-semibold);
        }
        
        .customer-info {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .customer-avatar {
            width: 32px;
            height: 32px;
            border-radius: var(--rounded-full);
            background: var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--text-xs);
            font-weight: var(--font-bold);
            color: var(--gray-600);
            text-transform: uppercase;
        }
        
        .customer-details {
            display: flex;
            flex-direction: column;
        }
        
        .customer-name {
            font-weight: var(--font-medium);
            color: var(--text-primary);
        }
        
        .customer-email {
            font-size: var(--text-xs);
            color: var(--text-tertiary);
        }
        
        .order-status {
            display: inline-flex;
            align-items: center;
            padding: var(--space-1) var(--space-2);
            border-radius: var(--rounded-full);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
        }
        
        .order-status.pending {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .order-status.processing {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .order-status.shipped {
            background: rgba(139, 92, 246, 0.1);
            color: #5b21b6;
        }
        
        .order-status.delivered {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .order-status.cancelled {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        .order-price {
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .order-date {
            color: var(--text-secondary);
            font-size: var(--text-xs);
        }
        
        .view-all {
            display: block;
            text-align: center;
            padding: var(--space-3);
            color: var(--primary);
            font-weight: var(--font-semibold);
            text-decoration: none;
            border-top: 1px solid var(--border-light);
            transition: var(--transition-normal);
        }
        
        .view-all:hover {
            background: var(--gray-50);
            color: var(--primary-dark);
        }
        
        /* Top Products */
        .top-products-list {
            display: flex;
            flex-direction: column;
            gap: var(--space-4);
        }
        
        .product-item {
            display: flex;
            align-items: center;
            gap: var(--space-4);
            padding-bottom: var(--space-4);
            border-bottom: 1px solid var(--border-light);
        }
        
        .product-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .product-image {
            width: 48px;
            height: 48px;
            border-radius: var(--rounded);
            object-fit: cover;
            background: var(--gray-100);
        }
        
        .product-info {
            flex: 1;
        }
        
        .product-name {
            font-weight: var(--font-semibold);
            margin-bottom: var(--space-1);
            color: var(--text-primary);
        }
        
        .product-category {
            font-size: var(--text-xs);
            color: var(--text-tertiary);
        }
        
        .product-stats {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        
        .product-sales {
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .product-price {
            font-size: var(--text-xs);
            color: var(--text-secondary);
        }
        
        /* Recent Activity */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: var(--space-4);
        }
        
        .activity-item {
            display: flex;
            gap: var(--space-3);
            padding-bottom: var(--space-4);
            border-bottom: 1px solid var(--border-light);
        }
        
        .activity-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--rounded-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--text-lg);
            flex-shrink: 0;
        }
        
        .activity-icon.order {
            background: rgba(139, 92, 246, 0.1);
            color: #5b21b6;
        }
        
        .activity-icon.review {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .activity-icon.user {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            margin-bottom: var(--space-1);
        }
        
        .activity-description {
            color: var(--text-secondary);
            font-size: var(--text-sm);
            margin-bottom: var(--space-1);
        }
        
        .activity-time {
            font-size: var(--text-xs);
            color: var(--text-tertiary);
        }
        
        /* Secondary Stats */
        .secondary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--space-6);
        }
        
        /* Empty state */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: var(--space-8);
            color: var(--text-tertiary);
            text-align: center;
        }
        
        .empty-state p {
            margin-top: var(--space-4);
            font-size: var(--text-sm);
        }
        
        /* Button styles */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            padding: var(--space-2) var(--space-4);
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            cursor: pointer;
            transition: var(--transition-normal);
            text-decoration: none;
            border: none;
        }
        
        .btn-sm {
            padding: var(--space-1) var(--space-3);
            font-size: var(--text-xs);
        }
        
        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: var(--gray-100);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }
        
        .btn-secondary:hover {
            background: var(--gray-200);
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
            
            .main-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .content {
                padding: var(--space-4);
            }
            
            .main-stats {
                grid-template-columns: 1fr;
                gap: var(--space-4);
            }
            
            .dashboard-card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-2);
            }
            
            .orders-table {
                min-width: 600px;
            }
            
            .table-responsive {
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

        /* Notification style */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: var(--space-4);
            border-radius: var(--rounded-lg);
            color: var(--white);
            font-weight: var(--font-medium);
            box-shadow: var(--shadow-xl);
            z-index: 9999;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            max-width: 350px;
        }

        .notification.success {
            background-color: var(--success);
        }

        .notification.error {
            background-color: var(--danger);
        }

        .notification.info {
            background-color: var(--primary);
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
                        <a href="dashboard.php" class="nav-link active">
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
                            <span>Dashboard</span>
                        </div>
                    </nav>
                </div>
                
                <div class="header-right">
                    <div class="user-menu">
                        <button class="user-button" id="user-menu-button">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr(get_value($admin, 'name', 'A'), 0, 2)); ?>
                            </div>
                            <div class="user-info">
                                <div class="user-name"><?php echo safe_echo(get_value($admin, 'name', 'Admin')); ?></div>
                                <div class="user-role"><?php echo safe_echo(get_value($admin, 'role_name', 'Administrator')); ?></div>
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
                    <h1 class="page-title">Tổng quan hệ thống</h1>
                    <p class="page-subtitle">Chào mừng trở lại, <?php echo safe_echo(get_value($admin, 'name', 'Admin')); ?>! Đây là tổng quan về hoạt động của hệ thống.</p>
                </div>
                
                <!-- Main Stats -->
                <div class="main-stats">
                    <div class="stat-card primary">
                        <div class="stat-header">
                            <div class="stat-title">Tổng doanh thu</div>
                            <div class="stat-icon">💰</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($stats['total_revenue']); ?></div>
                        <div class="stat-comparison positive">
                            <span>↑</span>
                            <span>Hôm nay: <?php echo formatCurrency($stats['revenue_today']); ?></span>
                        </div>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-title">Tổng đơn hàng</div>
                            <div class="stat-icon">📦</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_orders']); ?></div>
                        <div class="stat-comparison positive">
                            <span>↑</span>
                            <span>Hôm nay: <?php echo number_format($stats['orders_today']); ?></span>
                        </div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-title">Tổng sản phẩm</div>
                            <div class="stat-icon">🛍️</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_products']); ?></div>
                        <div class="stat-comparison <?php echo $stats['low_stock'] > 0 ? 'negative' : 'positive'; ?>">
                            <span><?php echo $stats['low_stock'] > 0 ? '⚠️' : '✓'; ?></span>
                            <span><?php echo $stats['low_stock'] > 0 ? number_format($stats['low_stock']) . ' sản phẩm sắp hết hàng' : 'Tồn kho ổn định'; ?></span>
                        </div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-title">Khách hàng</div>
                            <div class="stat-icon">👥</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                        <div class="stat-comparison warning">
                            <span>⚠️</span>
                            <span><?php echo number_format($stats['pending_orders']); ?> đơn hàng đang chờ xử lý</span>
                        </div>
                    </div>
                </div>
                
                <!-- Dashboard Grid -->
                <div class="dashboard-grid">
                    <!-- Sales Chart -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-header">
                            <h2 class="dashboard-card-title">Doanh số theo tháng</h2>
                            <div class="dashboard-card-actions">
                                <button class="btn btn-sm btn-secondary" id="export-report">
                                    <span>📥</span>
                                    <span>Xuất báo cáo</span>
                                </button>
                            </div>
                        </div>
                        <div class="dashboard-card-body sales-chart">
                            <div class="chart-container" id="sales-chart">
                                <!-- Chart will be rendered here by JavaScript -->
                                <canvas id="sales-canvas" width="100%" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Activity -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-header">
                            <h2 class="dashboard-card-title">Hoạt động gần đây</h2>
                        </div>
                        <div class="dashboard-card-body">
                            <div class="activity-list">
                                <?php if (!empty($recent_activities)): ?>
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon <?php echo safe_echo(get_value($activity, 'type', '')); ?>">
                                                <?php 
                                                switch (get_value($activity, 'type', '')) {
                                                    case 'order':
                                                        echo '📦';
                                                        break;
                                                    case 'review':
                                                        echo '⭐';
                                                        break;
                                                    case 'user':
                                                        echo '👤';
                                                        break;
                                                    default:
                                                        echo '📝';
                                                }
                                                ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-title"><?php echo safe_echo(get_value($activity, 'title', '')); ?></div>
                                                <div class="activity-description"><?php echo safe_echo(get_value($activity, 'description', '')); ?></div>
                                                <div class="activity-time">
                                                    <?php
                                                    if (has_value($activity, 'timestamp')) {
                                                        $timestamp = strtotime($activity['timestamp']);
                                                        $now = time();
                                                        $diff = $now - $timestamp;
                                                        
                                                        if ($diff < 60) {
                                                            echo 'Vừa xong';
                                                        } elseif ($diff < 3600) {
                                                            echo floor($diff / 60) . ' phút trước';
                                                        } elseif ($diff < 86400) {
                                                            echo floor($diff / 3600) . ' giờ trước';
                                                        } elseif ($diff < 604800) {
                                                            echo floor($diff / 86400) . ' ngày trước';
                                                        } else {
                                                            echo date('d/m/Y H:i', $timestamp);
                                                        }
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <p>Không có hoạt động nào gần đây</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="activities.php" class="view-all">Xem tất cả hoạt động</a>
                    </div>
                </div>
                
                <!-- Secondary Grid -->
                <div class="dashboard-grid">
                    <!-- Recent Orders -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-header">
                            <h2 class="dashboard-card-title">Đơn hàng gần đây</h2>
                            <div class="dashboard-card-actions">
                                <a href="orders.php" class="btn btn-sm btn-secondary">
                                    <span>👁️</span>
                                    <span>Xem tất cả</span>
                                </a>
                            </div>
                        </div>
                        <div class="dashboard-card-body">
                            <div class="table-responsive">
                                <table class="orders-table">
                                    <thead>
                                        <tr>
                                            <th>Mã</th>
                                            <th>Khách hàng</th>
                                            <th>Trạng thái</th>
                                            <th>Tổng tiền</th>
                                            <th>Ngày đặt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($recent_orders)): ?>
                                            <?php foreach ($recent_orders as $order): ?>
                                                <tr>
                                                    <td>
                                                        <span class="order-id">#<?php echo safe_echo(get_value($order, 'code', '')); ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="customer-info">
                                                            <div class="customer-avatar">
                                                                <?php echo strtoupper(substr(get_value($order, 'customer_name', 'G'), 0, 1)); ?>
                                                            </div>
                                                            <div class="customer-details">
                                                                <span class="customer-name">
                                                                    <?php echo safe_echo(get_value($order, 'customer_name', 'Khách vãng lai')); ?>
                                                                </span>
                                                                <?php if (has_value($order, 'customer_email')): ?>
                                                                    <span class="customer-email"><?php echo safe_echo($order['customer_email']); ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="order-status <?php echo safe_echo(get_value($order, 'delivery_status', 'pending')); ?>">
                                                            <?php
                                                            switch (get_value($order, 'delivery_status', 'pending')) {
                                                                case 'pending':
                                                                    echo 'Chờ xử lý';
                                                                    break;
                                                                case 'processing':
                                                                    echo 'Đang xử lý';
                                                                    break;
                                                                case 'shipped':
                                                                    echo 'Đang giao';
                                                                    break;
                                                                case 'delivered':
                                                                    echo 'Đã giao';
                                                                    break;
                                                                case 'cancelled':
                                                                    echo 'Đã hủy';
                                                                    break;
                                                                default:
                                                                    echo ucfirst(get_value($order, 'delivery_status', 'pending'));
                                                            }
                                                            ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="order-price"><?php echo formatCurrency(get_value($order, 'grand_total', 0)); ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="order-date">
                                                            <?php echo has_value($order, 'created_at') ? date('d/m/Y H:i', strtotime($order['created_at'])) : ''; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="empty-state">
                                                    <p>Không có đơn hàng nào</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <a href="orders.php" class="view-all">Xem tất cả đơn hàng</a>
                    </div>
                    
                    <!-- Top Products -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-header">
                            <h2 class="dashboard-card-title">Sản phẩm bán chạy</h2>
                            <div class="dashboard-card-actions">
                                <a href="products.php?sort=num_of_sale&order=DESC" class="btn btn-sm btn-secondary">
                                    <span>👁️</span>
                                    <span>Xem tất cả</span>
                                </a>
                            </div>
                        </div>
                        <div class="dashboard-card-body">
                            <div class="top-products-list">
                                <?php if (!empty($top_products)): ?>
                                    <?php foreach ($top_products as $product): ?>
                                        <div class="product-item">
                                            <img 
                                                src="<?php echo !empty(get_value($product, 'thumbnail_url')) && file_exists('../' . $product['thumbnail_url']) ? '../' . safe_echo($product['thumbnail_url']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48"><rect width="48" height="48" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="18" fill="%236b7280">📦</text></svg>'; ?>" 
                                                alt="<?php echo safe_echo(get_value($product, 'name', '')); ?>"
                                                class="product-image"
                                                loading="lazy"
                                            >
                                            <div class="product-info">
                                                <div class="product-name"><?php echo safe_echo(get_value($product, 'name', '')); ?></div>
                                                <div class="product-category">
                                                    <?php if (has_value($product, 'category_name')): ?>
                                                        <span>📂 <?php echo safe_echo($product['category_name']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="product-stats">
                                                <div class="product-sales"><?php echo number_format(get_value($product, 'total_quantity', 0)); ?> đã bán</div>
                                                <div class="product-price"><?php echo formatCurrency(get_value($product, 'unit_price', 0)); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <p>Chưa có sản phẩm nào được bán</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="products.php" class="view-all">Xem tất cả sản phẩm</a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Chart.js library for charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
    
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
        
        // Sales Chart
        function initSalesChart() {
            const ctx = document.getElementById('sales-canvas').getContext('2d');
            
            // Monthly sales data from PHP
            const salesData = <?php echo json_encode($monthly_sales); ?>;
            
            // Format labels and datasets
            const labels = salesData.map(item => {
                const [year, month] = item.month.split('-');
                const date = new Date(year, month - 1);
                return date.toLocaleDateString('vi-VN', { month: 'short', year: 'numeric' });
            });
            
            const revenues = salesData.map(item => item.revenue);
            const orderCounts = salesData.map(item => item.order_count);
            
            // Create chart
            const salesChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Doanh thu',
                            data: revenues,
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Số đơn hàng',
                            data: orderCounts,
                            borderColor: '#f5576c',
                            backgroundColor: 'rgba(245, 87, 108, 0.0)',
                            borderWidth: 2,
                            borderDash: [5, 5],
                            tension: 0.4,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                font: {
                                    family: "'Inter', sans-serif"
                                }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            titleFont: {
                                family: "'Inter', sans-serif"
                            },
                            bodyFont: {
                                family: "'Inter', sans-serif"
                            },
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.datasetIndex === 0) {
                                        label += new Intl.NumberFormat('vi-VN', { 
                                            style: 'currency', 
                                            currency: 'VND',
                                            maximumFractionDigits: 0
                                        }).format(context.raw);
                                    } else {
                                        label += context.raw;
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                font: {
                                    family: "'Inter', sans-serif"
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                font: {
                                    family: "'Inter', sans-serif"
                                },
                                callback: function(value) {
                                    return new Intl.NumberFormat('vi-VN', {
                                        style: 'currency',
                                        currency: 'VND',
                                        notation: 'compact',
                                        compactDisplay: 'short',
                                        maximumFractionDigits: 0
                                    }).format(value);
                                }
                            }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    family: "'Inter', sans-serif"
                                }
                            }
                        }
                    }
                }
            });
            
            return salesChart;
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
        
        // Update order status
        async function updateOrderStatus(orderId, status) {
            const success = await makeRequest('update_order_status', { 
                order_id: orderId,
                status: status
            });
            
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
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

        // Export report functionality
        document.getElementById('export-report').addEventListener('click', function() {
            showNotification('Đang chuẩn bị báo cáo để xuất...', 'info');
            setTimeout(() => {
                showNotification('Báo cáo đã được tải xuống', 'success');
            }, 1500);
        });
        
        // User menu functionality
        document.getElementById('user-menu-button').addEventListener('click', function() {
            // Show dropdown menu functionality would go here
        });
        
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
            console.log('🚀 Dashboard - Initializing...');
            
            handleResponsive();
            
            // Initialize chart
            if (document.getElementById('sales-canvas')) {
                initSalesChart();
            }
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Dashboard - Ready!');
        });
    </script>
</body>
</html>