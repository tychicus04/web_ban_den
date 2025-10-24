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

// Date filter parameters
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : '30days';
$custom_start = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$custom_end = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Calculate date range based on filter
$end_date = date('Y-m-d');
$start_date = '';

switch ($date_filter) {
    case '7days':
        $start_date = date('Y-m-d', strtotime('-7 days'));
        break;
    case '30days':
        $start_date = date('Y-m-d', strtotime('-30 days'));
        break;
    case '90days':
        $start_date = date('Y-m-d', strtotime('-90 days'));
        break;
    case 'year':
        $start_date = date('Y-01-01');
        break;
    case 'last_year':
        $start_date = date('Y-01-01', strtotime('-1 year'));
        $end_date = date('Y-12-31', strtotime('-1 year'));
        break;
    case 'custom':
        if (!empty($custom_start)) {
            $start_date = $custom_start;
        } else {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        
        if (!empty($custom_end)) {
            $end_date = $custom_end;
        }
        break;
    default:
        $start_date = date('Y-m-d', strtotime('-30 days'));
}

// Format for SQL queries
$start_date_sql = $start_date . ' 00:00:00';
$end_date_sql = $end_date . ' 23:59:59';

// Get sales summary
$sales_summary = [];
try {
    $sql = "
        SELECT 
            COUNT(*) as total_orders,
            SUM(grand_total) as total_revenue,
            COUNT(DISTINCT user_id) as unique_customers,
            SUM(grand_total) / COUNT(*) as average_order_value,
            SUM(CASE WHEN payment_status = 'paid' THEN grand_total ELSE 0 END) as paid_amount,
            SUM(CASE WHEN payment_status = 'unpaid' THEN grand_total ELSE 0 END) as unpaid_amount
        FROM orders
        WHERE created_at BETWEEN ? AND ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date_sql, $end_date_sql]);
    $sales_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Sales summary error: " . $e->getMessage());
    $sales_summary = [
        'total_orders' => 0,
        'total_revenue' => 0,
        'unique_customers' => 0,
        'average_order_value' => 0,
        'paid_amount' => 0,
        'unpaid_amount' => 0
    ];
}

// Compare with previous period
$prev_start_date = date('Y-m-d', strtotime($start_date . ' -' . (strtotime($end_date) - strtotime($start_date)) . ' seconds'));
$prev_end_date = date('Y-m-d', strtotime($end_date . ' -' . (strtotime($end_date) - strtotime($start_date)) . ' seconds'));

$prev_start_date_sql = $prev_start_date . ' 00:00:00';
$prev_end_date_sql = $prev_end_date . ' 23:59:59';

$previous_summary = [];
try {
    $sql = "
        SELECT 
            COUNT(*) as total_orders,
            SUM(grand_total) as total_revenue
        FROM orders
        WHERE created_at BETWEEN ? AND ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$prev_start_date_sql, $prev_end_date_sql]);
    $previous_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate percentage changes
    $sales_summary['orders_change'] = $previous_summary['total_orders'] > 0 
        ? (($sales_summary['total_orders'] - $previous_summary['total_orders']) / $previous_summary['total_orders'] * 100) 
        : 100;
    
    $sales_summary['revenue_change'] = $previous_summary['total_revenue'] > 0 
        ? (($sales_summary['total_revenue'] - $previous_summary['total_revenue']) / $previous_summary['total_revenue'] * 100) 
        : 100;
    
} catch (PDOException $e) {
    error_log("Previous period comparison error: " . $e->getMessage());
    $sales_summary['orders_change'] = 0;
    $sales_summary['revenue_change'] = 0;
}

// Get daily sales data
$daily_sales = [];
try {
    $sql = "
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as order_count,
            SUM(grand_total) as revenue
        FROM orders
        WHERE created_at BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date_sql, $end_date_sql]);
    $daily_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Daily sales error: " . $e->getMessage());
    $daily_sales = [];
}

// Get sales by category
$category_sales = [];
try {
    $sql = "
        SELECT 
            c.id as category_id,
            c.name as category_name,
            COUNT(DISTINCT o.id) as order_count,
            SUM(od.quantity) as total_items,
            SUM(od.price * od.quantity) as revenue
        FROM orders o
        JOIN order_details od ON o.id = od.order_id
        JOIN products p ON od.product_id = p.id
        JOIN categories c ON p.category_id = c.id
        WHERE o.created_at BETWEEN ? AND ?
        GROUP BY c.id
        ORDER BY revenue DESC
        LIMIT 10
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date_sql, $end_date_sql]);
    $category_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Category sales error: " . $e->getMessage());
    $category_sales = [];
}

// Get top selling products
$top_products = [];
try {
    $sql = "
        SELECT 
            p.id as product_id,
            p.name as product_name,
            p.thumbnail_img,
            u_thumb.file_name as thumbnail_url,
            COUNT(DISTINCT o.id) as order_count,
            SUM(od.quantity) as total_quantity,
            SUM(od.price * od.quantity) as revenue
        FROM orders o
        JOIN order_details od ON o.id = od.order_id
        JOIN products p ON od.product_id = p.id
        LEFT JOIN uploads u_thumb ON p.thumbnail_img = u_thumb.id
        WHERE o.created_at BETWEEN ? AND ?
        GROUP BY p.id
        ORDER BY total_quantity DESC
        LIMIT 10
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date_sql, $end_date_sql]);
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Top products error: " . $e->getMessage());
    $top_products = [];
}

// Get customer acquisition data
$new_customers = [];
try {
    $sql = "
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as customer_count
        FROM users
        WHERE user_type = 'customer' AND created_at BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date_sql, $end_date_sql]);
    $new_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("New customers error: " . $e->getMessage());
    $new_customers = [];
}

// Get payment method stats
$payment_methods = [];
try {
    $sql = "
        SELECT 
            payment_type,
            COUNT(*) as order_count,
            SUM(grand_total) as amount
        FROM orders
        WHERE created_at BETWEEN ? AND ?
        GROUP BY payment_type
        ORDER BY amount DESC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date_sql, $end_date_sql]);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Payment methods error: " . $e->getMessage());
    $payment_methods = [];
}

// Optimize payment methods data for chart
$payment_labels = [];
$payment_values = [];
$payment_colors = [
    'cash_on_delivery' => '#10B981',
    'bank_transfer' => '#667EEA',
    'card' => '#F59E0B',
    'paypal' => '#3B82F6',
    'stripe' => '#8B5CF6',
    'wallet' => '#EC4899'
];

$default_colors = ['#10B981', '#667EEA', '#F59E0B', '#3B82F6', '#8B5CF6', '#EC4899', '#EF4444', '#14B8A6'];

foreach ($payment_methods as $index => $method) {
    $payment_type = $method['payment_type'] ?: 'unknown';
    $payment_labels[] = ucwords(str_replace('_', ' ', $payment_type));
    $payment_values[] = (float)$method['amount'];
}

// Get site name
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
            case 'export_analytics':
                // This would normally generate a CSV/Excel file
                // For now we just return success
                echo json_encode(['success' => true, 'message' => 'Báo cáo đã được xuất thành công']);
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
    <title>Phân tích dữ liệu - Admin <?php echo safe_echo($site_name); ?></title>
    <meta name="description" content="Phân tích dữ liệu bán hàng - Admin <?php echo safe_echo($site_name); ?>">
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
        
        /* Filter bar */
        .filter-bar {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            padding: var(--space-5);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            margin-bottom: var(--space-6);
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-4);
            align-items: center;
            justify-content: space-between;
        }
        
        .filter-group {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-3);
            align-items: center;
        }
        
        .filter-label {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--text-secondary);
        }
        
        .filter-select, .filter-input {
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            color: var(--text-primary);
            background-color: var(--white);
            min-width: 120px;
        }
        
        .filter-input {
            min-width: 150px;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        
        /* Main Stats */
        .stats-grid {
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
        
        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: var(--space-6);
            margin-bottom: var(--space-8);
        }
        
        .chart-card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
        }
        
        .chart-card-header {
            padding: var(--space-5);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .chart-card-title {
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .chart-card-subtitle {
            font-size: var(--text-sm);
            color: var(--text-secondary);
            margin-top: var(--space-1);
        }
        
        .chart-card-actions {
            display: flex;
            gap: var(--space-2);
        }
        
        .chart-card-body {
            padding: var(--space-5);
        }
        
        .chart-container {
            height: 300px;
            width: 100%;
        }
        
        /* Top Products */
        .product-list {
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
        
        .product-sales {
            font-size: var(--text-xs);
            color: var(--text-tertiary);
        }
        
        .product-stats {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        
        .product-revenue {
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .product-orders {
            font-size: var(--text-xs);
            color: var(--text-secondary);
        }
        
        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: var(--text-sm);
        }
        
        .data-table th {
            text-align: left;
            padding: var(--space-3) var(--space-4);
            border-bottom: 1px solid var(--border);
            color: var(--text-secondary);
            font-weight: var(--font-semibold);
            font-size: var(--text-xs);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .data-table td {
            padding: var(--space-3) var(--space-4);
            border-bottom: 1px solid var(--border-light);
        }
        
        .data-table tr:hover {
            background: var(--gray-50);
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            padding: var(--space-2) var(--space-4);
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            cursor: pointer;
            transition: var(--transition-normal);
            border: none;
            white-space: nowrap;
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
        
        /* Progress bar */
        .progress-bar {
            height: 8px;
            background: var(--gray-200);
            border-radius: var(--rounded-full);
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: var(--rounded-full);
        }
        
        .progress-fill.primary {
            background: var(--primary-gradient);
        }
        
        .progress-fill.success {
            background: var(--success-gradient);
        }
        
        .progress-fill.warning {
            background: var(--warning-gradient);
        }
        
        .progress-fill.danger {
            background: var(--danger-gradient);
        }
        
        /* Date range picker custom styles */
        .date-range-group {
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .date-input-group {
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .custom-date-inputs {
            display: flex;
            align-items: center;
            gap: var(--space-2);
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .content {
                padding: var(--space-4);
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: var(--space-4);
            }
            
            .filter-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-group {
                width: 100%;
                justify-content: space-between;
            }
            
            .date-range-group {
                flex-wrap: wrap;
            }
            
            .custom-date-inputs {
                width: 100%;
                margin-top: var(--space-2);
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
                        <a href="analytics.php" class="nav-link active">
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
                            <span>Phân tích</span>
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
                    <h1 class="page-title">Phân tích dữ liệu</h1>
                    <p class="page-subtitle">Theo dõi và phân tích hiệu suất kinh doanh của cửa hàng.</p>
                </div>
                
                <!-- Filter bar -->
                <form action="" method="get" class="filter-bar">
                    <div class="filter-group">
                        <label class="filter-label" for="date-filter">Khoảng thời gian:</label>
                        <select name="date_filter" id="date-filter" class="filter-select" onchange="toggleCustomDateInputs()">
                            <option value="7days" <?php echo $date_filter === '7days' ? 'selected' : ''; ?>>7 ngày qua</option>
                            <option value="30days" <?php echo $date_filter === '30days' ? 'selected' : ''; ?>>30 ngày qua</option>
                            <option value="90days" <?php echo $date_filter === '90days' ? 'selected' : ''; ?>>90 ngày qua</option>
                            <option value="year" <?php echo $date_filter === 'year' ? 'selected' : ''; ?>>Năm nay</option>
                            <option value="last_year" <?php echo $date_filter === 'last_year' ? 'selected' : ''; ?>>Năm trước</option>
                            <option value="custom" <?php echo $date_filter === 'custom' ? 'selected' : ''; ?>>Tùy chỉnh</option>
                        </select>
                        
                        <div id="custom-date-inputs" class="custom-date-inputs" style="display: <?php echo $date_filter === 'custom' ? 'flex' : 'none'; ?>">
                            <input type="date" name="start_date" class="filter-input" value="<?php echo $custom_start; ?>">
                            <span>đến</span>
                            <input type="date" name="end_date" class="filter-input" value="<?php echo $custom_end; ?>">
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <span>🔍</span>
                            <span>Áp dụng</span>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" id="export-data">
                            <span>📥</span>
                            <span>Xuất dữ liệu</span>
                        </button>
                    </div>
                </form>
                
                <!-- Main Stats -->
                <div class="stats-grid">
                    <div class="stat-card primary">
                        <div class="stat-header">
                            <div class="stat-title">Tổng doanh thu</div>
                            <div class="stat-icon">💰</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency(get_value($sales_summary, 'total_revenue', 0)); ?></div>
                        <div class="stat-comparison <?php echo get_value($sales_summary, 'revenue_change', 0) >= 0 ? 'positive' : 'negative'; ?>">
                            <span><?php echo get_value($sales_summary, 'revenue_change', 0) >= 0 ? '↑' : '↓'; ?></span>
                            <span><?php echo number_format(abs(get_value($sales_summary, 'revenue_change', 0)), 1); ?>% so với kỳ trước</span>
                        </div>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-title">Tổng đơn hàng</div>
                            <div class="stat-icon">📦</div>
                        </div>
                        <div class="stat-value"><?php echo number_format(get_value($sales_summary, 'total_orders', 0)); ?></div>
                        <div class="stat-comparison <?php echo get_value($sales_summary, 'orders_change', 0) >= 0 ? 'positive' : 'negative'; ?>">
                            <span><?php echo get_value($sales_summary, 'orders_change', 0) >= 0 ? '↑' : '↓'; ?></span>
                            <span><?php echo number_format(abs(get_value($sales_summary, 'orders_change', 0)), 1); ?>% so với kỳ trước</span>
                        </div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-title">Giá trị đơn hàng TB</div>
                            <div class="stat-icon">📊</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency(get_value($sales_summary, 'average_order_value', 0)); ?></div>
                        <div class="stat-comparison positive">
                            <span>👥</span>
                            <span><?php echo number_format(get_value($sales_summary, 'unique_customers', 0)); ?> khách hàng</span>
                        </div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-title">Tỷ lệ thanh toán</div>
                            <div class="stat-icon">💳</div>
                        </div>
                        <?php 
                        $total_revenue = (float)get_value($sales_summary, 'total_revenue', 0);
                        $paid_amount = (float)get_value($sales_summary, 'paid_amount', 0);
                        $payment_rate = $total_revenue > 0 ? ($paid_amount / $total_revenue) * 100 : 0;
                        ?>
                        <div class="stat-value"><?php echo number_format($payment_rate, 1); ?>%</div>
                        <div class="stat-comparison <?php echo $payment_rate >= 80 ? 'positive' : 'negative'; ?>">
                            <span><?php echo $payment_rate >= 80 ? '✅' : '⚠️'; ?></span>
                            <span><?php echo formatCurrency($paid_amount); ?> đã thanh toán</span>
                        </div>
                    </div>
                </div>
                
                <!-- Charts Grid -->
                <div class="charts-grid">
                    <!-- Sales Chart -->
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <div>
                                <h2 class="chart-card-title">Doanh số theo thời gian</h2>
                                <div class="chart-card-subtitle">
                                    <?php echo date('d/m/Y', strtotime($start_date)); ?> - <?php echo date('d/m/Y', strtotime($end_date)); ?>
                                </div>
                            </div>
                            <div class="chart-card-actions">
                                <button class="btn btn-sm btn-secondary" id="toggle-chart-view">
                                    <span>📊</span>
                                    <span>Đổi chế độ xem</span>
                                </button>
                            </div>
                        </div>
                        <div class="chart-card-body">
                            <div class="chart-container">
                                <canvas id="sales-chart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Methods Chart -->
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <div>
                                <h2 class="chart-card-title">Phương thức thanh toán</h2>
                                <div class="chart-card-subtitle">Theo doanh thu</div>
                            </div>
                        </div>
                        <div class="chart-card-body">
                            <div class="chart-container">
                                <canvas id="payment-methods-chart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Categories and Products -->
                <div class="charts-grid">
                    <!-- Categories -->
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <div>
                                <h2 class="chart-card-title">Doanh thu theo danh mục</h2>
                                <div class="chart-card-subtitle">Top 10 danh mục có doanh thu cao nhất</div>
                            </div>
                        </div>
                        <div class="chart-card-body">
                            <?php if (!empty($category_sales)): ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Danh mục</th>
                                            <th>Số đơn</th>
                                            <th>Số sản phẩm</th>
                                            <th>Doanh thu</th>
                                            <th>Tỷ trọng</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_category_revenue = array_sum(array_column($category_sales, 'revenue'));
                                        foreach ($category_sales as $category): 
                                        ?>
                                            <tr>
                                                <td><?php echo safe_echo(get_value($category, 'category_name', '')); ?></td>
                                                <td><?php echo number_format(get_value($category, 'order_count', 0)); ?></td>
                                                <td><?php echo number_format(get_value($category, 'total_items', 0)); ?></td>
                                                <td><?php echo formatCurrency(get_value($category, 'revenue', 0)); ?></td>
                                                <td>
                                                    <?php 
                                                    $percentage = $total_category_revenue > 0 ? ((float)$category['revenue'] / $total_category_revenue * 100) : 0;
                                                    ?>
                                                    <div class="progress-bar">
                                                        <div class="progress-fill primary" style="width: <?php echo $percentage; ?>%"></div>
                                                    </div>
                                                    <div style="font-size: var(--text-xs); margin-top: var(--space-1);">
                                                        <?php echo number_format($percentage, 1); ?>%
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <p>Không có dữ liệu danh mục trong khoảng thời gian này</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Top Products -->
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <div>
                                <h2 class="chart-card-title">Sản phẩm bán chạy</h2>
                                <div class="chart-card-subtitle">Top 10 sản phẩm bán chạy nhất</div>
                            </div>
                        </div>
                        <div class="chart-card-body">
                            <div class="product-list">
                                <?php if (!empty($top_products)): ?>
                                    <?php foreach ($top_products as $product): ?>
                                        <div class="product-item">
                                            <img 
                                                src="<?php echo !empty(get_value($product, 'thumbnail_url')) && file_exists('../' . $product['thumbnail_url']) ? '../' . safe_echo($product['thumbnail_url']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48"><rect width="48" height="48" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="18" fill="%236b7280">📦</text></svg>'; ?>" 
                                                alt="<?php echo safe_echo(get_value($product, 'product_name', '')); ?>"
                                                class="product-image"
                                                loading="lazy"
                                            >
                                            <div class="product-info">
                                                <div class="product-name"><?php echo safe_echo(get_value($product, 'product_name', '')); ?></div>
                                                <div class="product-sales"><?php echo number_format(get_value($product, 'total_quantity', 0)); ?> sản phẩm đã bán</div>
                                            </div>
                                            <div class="product-stats">
                                                <div class="product-revenue"><?php echo formatCurrency(get_value($product, 'revenue', 0)); ?></div>
                                                <div class="product-orders"><?php echo number_format(get_value($product, 'order_count', 0)); ?> đơn hàng</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <p>Không có dữ liệu sản phẩm trong khoảng thời gian này</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Customer Growth -->
                <div class="chart-card" style="margin-bottom: var(--space-8);">
                    <div class="chart-card-header">
                        <div>
                            <h2 class="chart-card-title">Tăng trưởng khách hàng</h2>
                            <div class="chart-card-subtitle">Số lượng khách hàng mới đăng ký theo thời gian</div>
                        </div>
                    </div>
                    <div class="chart-card-body">
                        <div class="chart-container">
                            <canvas id="customer-growth-chart"></canvas>
                        </div>
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
        
        // Toggle custom date inputs
        function toggleCustomDateInputs() {
            const dateFilter = document.getElementById('date-filter');
            const customDateInputs = document.getElementById('custom-date-inputs');
            
            if (dateFilter.value === 'custom') {
                customDateInputs.style.display = 'flex';
            } else {
                customDateInputs.style.display = 'none';
            }
        }
        
        // Sales Chart
        function initSalesChart() {
            const ctx = document.getElementById('sales-chart').getContext('2d');
            
            // Daily sales data from PHP
            const salesData = <?php echo json_encode($daily_sales); ?>;
            
            // Format labels and datasets
            const labels = salesData.map(item => {
                const date = new Date(item.date);
                return date.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' });
            });
            
            const revenues = salesData.map(item => parseFloat(item.revenue));
            const orderCounts = salesData.map(item => parseInt(item.order_count));
            
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
            
            // Toggle between line and bar chart
            document.getElementById('toggle-chart-view').addEventListener('click', function() {
                salesChart.config.type = salesChart.config.type === 'line' ? 'bar' : 'line';
                salesChart.update();
            });
            
            return salesChart;
        }
        
        // Payment Methods Chart
        function initPaymentMethodsChart() {
            const ctx = document.getElementById('payment-methods-chart').getContext('2d');
            
            // Payment methods data
            const labels = <?php echo json_encode($payment_labels); ?>;
            const values = <?php echo json_encode($payment_values); ?>;
            
            // Custom colors
            const colors = [
                '#10B981', // success
                '#667EEA', // primary
                '#F59E0B', // warning
                '#3B82F6', // blue
                '#8B5CF6', // purple
                '#EC4899', // pink
                '#EF4444', // danger
                '#14B8A6'  // teal
            ];
            
            // Create chart
            const paymentChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: colors.slice(0, labels.length),
                        borderWidth: 0,
                        hoverOffset: 15
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                usePointStyle: true,
                                font: {
                                    family: "'Inter', sans-serif",
                                    size: 11
                                },
                                padding: 15
                            }
                        },
                        tooltip: {
                            titleFont: {
                                family: "'Inter', sans-serif"
                            },
                            bodyFont: {
                                family: "'Inter', sans-serif"
                            },
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    
                                    return `${label}: ${new Intl.NumberFormat('vi-VN', { 
                                        style: 'currency', 
                                        currency: 'VND',
                                        maximumFractionDigits: 0
                                    }).format(value)} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
            
            return paymentChart;
        }
        
        // Customer Growth Chart
        function initCustomerGrowthChart() {
            const ctx = document.getElementById('customer-growth-chart').getContext('2d');
            
            // New customers data
            const customersData = <?php echo json_encode($new_customers); ?>;
            
            // Format labels and datasets
            const labels = customersData.map(item => {
                const date = new Date(item.date);
                return date.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' });
            });
            
            const customers = customersData.map(item => parseInt(item.customer_count));
            
            // Calculate cumulative data
            let cumulativeCustomers = [];
            let sum = 0;
            for (const count of customers) {
                sum += count;
                cumulativeCustomers.push(sum);
            }
            
            // Create chart
            const customerChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Khách hàng mới',
                            data: customers,
                            backgroundColor: 'rgba(16, 185, 129, 0.7)',
                            borderColor: 'rgba(16, 185, 129, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Tổng khách hàng',
                            data: cumulativeCustomers,
                            type: 'line',
                            fill: false,
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
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
            
            return customerChart;
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
        
        // Export data functionality
        document.getElementById('export-data').addEventListener('click', async function() {
            const success = await makeRequest('export_analytics', {
                date_filter: '<?php echo $date_filter; ?>',
                start_date: '<?php echo $start_date; ?>',
                end_date: '<?php echo $end_date; ?>'
            });
            
            if (success) {
                // The success notification is shown by makeRequest
            }
        });
        
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
            console.log('🚀 Analytics - Initializing...');
            
            handleResponsive();
            
            // Initialize charts
            if (document.getElementById('sales-chart')) {
                initSalesChart();
            }
            
            if (document.getElementById('payment-methods-chart')) {
                initPaymentMethodsChart();
            }
            
            if (document.getElementById('customer-growth-chart')) {
                initCustomerGrowthChart();
            }
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Analytics - Ready!');
        });
    </script>
</body>
</html>