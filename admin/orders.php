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

// Pagination function
function paginate($current_page, $total_pages, $url_pattern) {
    $range = 2;
    $output = '<div class="pagination">';
    
    if ($current_page > 1) {
        $output .= '<a href="' . sprintf($url_pattern, 1) . '" class="pagination-item" aria-label="First page">«</a>';
        $output .= '<a href="' . sprintf($url_pattern, $current_page - 1) . '" class="pagination-item" aria-label="Previous page">‹</a>';
    } else {
        $output .= '<span class="pagination-item disabled">«</span>';
        $output .= '<span class="pagination-item disabled">‹</span>';
    }
    
    for ($i = max(1, $current_page - $range); $i <= min($total_pages, $current_page + $range); $i++) {
        if ($i == $current_page) {
            $output .= '<span class="pagination-item active">' . $i . '</span>';
        } else {
            $output .= '<a href="' . sprintf($url_pattern, $i) . '" class="pagination-item">' . $i . '</a>';
        }
    }
    
    if ($current_page < $total_pages) {
        $output .= '<a href="' . sprintf($url_pattern, $current_page + 1) . '" class="pagination-item" aria-label="Next page">›</a>';
        $output .= '<a href="' . sprintf($url_pattern, $total_pages) . '" class="pagination-item" aria-label="Last page">»</a>';
    } else {
        $output .= '<span class="pagination-item disabled">›</span>';
        $output .= '<span class="pagination-item disabled">»</span>';
    }
    
    $output .= '</div>';
    return $output;
}

$site_name = getBusinessSetting($db, 'site_name', 'Active E-Commerce');

// Set default filters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sort_order = isset($_GET['order']) ? strtoupper($_GET['order']) : 'DESC';

// Validate sort parameters
$allowed_sort_fields = ['id', 'code', 'grand_total', 'created_at', 'delivery_status', 'payment_status'];
if (!in_array($sort_by, $allowed_sort_fields)) {
    $sort_by = 'created_at';
}

$allowed_sort_orders = ['ASC', 'DESC'];
if (!in_array($sort_order, $allowed_sort_orders)) {
    $sort_order = 'DESC';
}

// Get orders with filters
$orders = [];
$total_orders = 0;

try {
    $params = [];
    $where_clauses = [];
    
    // Build WHERE clause based on filters
    if (!empty($status_filter)) {
        $where_clauses[] = "o.delivery_status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($search_term)) {
        $where_clauses[] = "(o.code LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
        $search_pattern = "%{$search_term}%";
        $params[] = $search_pattern;
        $params[] = $search_pattern;
        $params[] = $search_pattern;
        $params[] = $search_pattern;
    }
    
    if (!empty($date_from)) {
        $where_clauses[] = "o.created_at >= ?";
        $params[] = $date_from . ' 00:00:00';
    }
    
    if (!empty($date_to)) {
        $where_clauses[] = "o.created_at <= ?";
        $params[] = $date_to . ' 23:59:59';
    }
    
    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
    
    // Count total orders for pagination
    $count_sql = "
        SELECT COUNT(*) as count
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        $where_sql
    ";
    
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_orders = $result ? (int)$result['count'] : 0;
    
    // Calculate total pages
    $total_pages = ceil($total_orders / $per_page);
    $offset = ($page - 1) * $per_page;
    
    // Get orders with pagination
    $orders_sql = "
        SELECT o.*, 
               u.name as customer_name,
               u.email as customer_email,
               u.phone as customer_phone,
               u.avatar as customer_avatar
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        $where_sql
        ORDER BY o.$sort_by $sort_order
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($orders_sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Orders fetch error: " . $e->getMessage());
}

// Get order stats
$order_stats = [
    'total' => 0,
    'pending' => 0,
    'processing' => 0,
    'shipped' => 0,
    'delivered' => 0,
    'cancelled' => 0,
    'total_revenue' => 0,
    'paid_revenue' => 0
];

try {
    // Total orders
    $stmt = $db->query("SELECT COUNT(*) as count FROM orders");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $order_stats['total'] = $result ? (int)$result['count'] : 0;
    
    // Orders by status
    $stmt = $db->query("SELECT delivery_status, COUNT(*) as count FROM orders GROUP BY delivery_status");
    $status_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($status_counts as $status) {
        if (isset($order_stats[$status['delivery_status']])) {
            $order_stats[$status['delivery_status']] = (int)$status['count'];
        }
    }
    
    // Total revenue
    $stmt = $db->query("SELECT SUM(grand_total) as total FROM orders");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $order_stats['total_revenue'] = $result && $result['total'] ? (float)$result['total'] : 0;
    
    // Paid revenue
    $stmt = $db->query("SELECT SUM(grand_total) as total FROM orders WHERE payment_status = 'paid'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $order_stats['paid_revenue'] = $result && $result['total'] ? (float)$result['total'] : 0;
    
} catch (PDOException $e) {
    error_log("Order stats error: " . $e->getMessage());
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
                
            case 'get_order_details':
                $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
                
                if ($order_id <= 0) {
                    throw new Exception('Dữ liệu không hợp lệ');
                }
                
                // Get order info
                $stmt = $db->prepare("
                    SELECT o.*, 
                           u.name as customer_name,
                           u.email as customer_email,
                           u.phone as customer_phone
                    FROM orders o
                    LEFT JOIN users u ON o.user_id = u.id
                    WHERE o.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$order_id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$order) {
                    throw new Exception('Không tìm thấy đơn hàng');
                }
                
                // Get order items
                $stmt = $db->prepare("
                    SELECT od.*, 
                           p.name as product_name,
                           p.thumbnail_img,
                           u.file_name as product_image
                    FROM order_details od
                    LEFT JOIN products p ON od.product_id = p.id
                    LEFT JOIN uploads u ON p.thumbnail_img = u.id
                    WHERE od.order_id = ?
                ");
                $stmt->execute([$order_id]);
                $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true, 
                    'order' => $order,
                    'items' => $order_items
                ]);
                break;
                
            case 'delete_order':
                $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
                
                if ($order_id <= 0) {
                    throw new Exception('Dữ liệu không hợp lệ');
                }
                
                // Start transaction
                $db->beginTransaction();
                
                // Delete order details
                $stmt = $db->prepare("DELETE FROM order_details WHERE order_id = ?");
                $stmt->execute([$order_id]);
                
                // Delete order
                $stmt = $db->prepare("DELETE FROM orders WHERE id = ?");
                $stmt->execute([$order_id]);
                
                if ($stmt->rowCount() > 0) {
                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'Đã xóa đơn hàng thành công']);
                } else {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Không tìm thấy đơn hàng hoặc xóa không thành công']);
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

// Generate filter URL
function getFilterUrl($page = 1, $override = []) {
    global $status_filter, $search_term, $date_from, $date_to, $sort_by, $sort_order;
    
    $params = [
        'page' => $page,
        'status' => $status_filter,
        'search' => $search_term,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'sort' => $sort_by,
        'order' => strtolower($sort_order)
    ];
    
    // Override params
    foreach ($override as $key => $value) {
        $params[$key] = $value;
    }
    
    // Remove empty params
    $params = array_filter($params, function($value) {
        return $value !== '';
    });
    
    return '?' . http_build_query($params);
}

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý đơn hàng - Admin <?php echo safe_echo($site_name); ?></title>
    <meta name="description" content="Quản lý đơn hàng - Admin <?php echo safe_echo($site_name); ?>">
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
        
        .page-title-wrapper {
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
        
        .page-actions {
            display: flex;
            gap: var(--space-3);
        }
        
        /* Filter Bar */
        .filter-bar {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            padding: var(--space-5);
            margin-bottom: var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
        }
        
        .filter-row {
            display: flex;
            gap: var(--space-4);
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filter-item {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-buttons {
            display: flex;
            gap: var(--space-3);
            margin-top: var(--space-4);
        }
        
        /* Order Stats */
        .order-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-6);
        }
        
        .stat-card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            padding: var(--space-4);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            transition: var(--transition-normal);
            display: flex;
            flex-direction: column;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-card.primary {
            border-left: 4px solid var(--primary);
        }
        
        .stat-card.success {
            border-left: 4px solid var(--success);
        }
        
        .stat-card.warning {
            border-left: 4px solid var(--warning);
        }
        
        .stat-card.danger {
            border-left: 4px solid var(--danger);
        }
        
        .stat-card.info {
            border-left: 4px solid var(--accent);
        }
        
        .stat-card.secondary {
            border-left: 4px solid var(--secondary);
        }
        
        .stat-title {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--text-secondary);
            margin-bottom: var(--space-2);
        }
        
        .stat-value {
            font-family: var(--font-heading);
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            color: var(--text-primary);
            margin-bottom: var(--space-1);
        }
        
        .stat-subtitle {
            font-size: var(--text-xs);
            color: var(--text-tertiary);
            margin-top: auto;
        }
        
        /* Orders Table */
        .orders-container {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            overflow: hidden;
        }
        
        .table-header {
            padding: var(--space-4) var(--space-5);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-title {
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .table-actions {
            display: flex;
            gap: var(--space-3);
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .orders-table {
            width: 100%;
            border-collapse: collapse;
            white-space: nowrap;
        }
        
        .orders-table th {
            text-align: left;
            padding: var(--space-3) var(--space-4);
            background: var(--gray-50);
            border-bottom: 1px solid var(--border);
            color: var(--text-secondary);
            font-weight: var(--font-semibold);
            font-size: var(--text-xs);
            text-transform: uppercase;
            white-space: nowrap;
        }
        
        .orders-table th.sortable {
            cursor: pointer;
        }
        
        .orders-table th.sortable:hover {
            background: var(--gray-100);
        }
        
        .orders-table th.sorted-asc::after {
            content: " ↑";
        }
        
        .orders-table th.sorted-desc::after {
            content: " ↓";
        }
        
        .orders-table td {
            padding: var(--space-3) var(--space-4);
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
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
            flex-shrink: 0;
        }
        
        .customer-details {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        
        .customer-name {
            font-weight: var(--font-medium);
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 180px;
        }
        
        .customer-email {
            font-size: var(--text-xs);
            color: var(--text-tertiary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 180px;
        }
        
        .order-status {
            display: inline-flex;
            align-items: center;
            padding: var(--space-1) var(--space-2);
            border-radius: var(--rounded-full);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            white-space: nowrap;
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
        
        .payment-status {
            display: inline-flex;
            align-items: center;
            padding: var(--space-1) var(--space-2);
            border-radius: var(--rounded-full);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
        }
        
        .payment-status.paid {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .payment-status.unpaid {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        .order-price {
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            white-space: nowrap;
        }
        
        .order-date {
            white-space: nowrap;
            color: var(--text-secondary);
            font-size: var(--text-sm);
        }
        
        .order-actions {
            display: flex;
            gap: var(--space-2);
            justify-content: flex-end;
        }
        
        /* Order detail modal */
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1100;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition-normal);
        }
        
        .modal-backdrop.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-xl);
            width: 100%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            opacity: 0;
            transition: var(--transition-normal);
        }
        
        .modal.active {
            transform: translateY(0);
            opacity: 1;
        }
        
        .modal-header {
            padding: var(--space-4) var(--space-5);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--surface);
            z-index: 10;
        }
        
        .modal-title {
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: var(--text-2xl);
            color: var(--text-tertiary);
            cursor: pointer;
            transition: var(--transition-fast);
            padding: var(--space-1);
            border-radius: var(--rounded);
            line-height: 1;
        }
        
        .modal-close:hover {
            color: var(--text-primary);
            background: var(--gray-100);
        }
        
        .modal-body {
            padding: var(--space-5);
        }
        
        .modal-section {
            margin-bottom: var(--space-6);
        }
        
        .modal-section:last-child {
            margin-bottom: 0;
        }
        
        .modal-section-title {
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-2);
            border-bottom: 1px solid var(--border-light);
        }
        
        .order-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
        }
        
        .order-info-item {
            margin-bottom: var(--space-4);
        }
        
        .order-info-label {
            font-size: var(--text-sm);
            color: var(--text-tertiary);
            margin-bottom: var(--space-1);
        }
        
        .order-info-value {
            font-weight: var(--font-medium);
            color: var(--text-primary);
        }
        
        .order-items-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .order-items-table th {
            text-align: left;
            padding: var(--space-3);
            background: var(--gray-50);
            border-bottom: 1px solid var(--border);
            color: var(--text-secondary);
            font-weight: var(--font-semibold);
            font-size: var(--text-xs);
            text-transform: uppercase;
        }
        
        .order-items-table td {
            padding: var(--space-3);
            border-bottom: 1px solid var(--border-light);
        }
        
        .order-items-table tr:last-child td {
            border-bottom: none;
        }
        
        .product-info {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .product-image {
            width: 40px;
            height: 40px;
            border-radius: var(--rounded);
            object-fit: cover;
            background: var(--gray-100);
        }
        
        .product-details {
            display: flex;
            flex-direction: column;
        }
        
        .product-name {
            font-weight: var(--font-medium);
            color: var(--text-primary);
        }
        
        .product-variant {
            font-size: var(--text-xs);
            color: var(--text-tertiary);
        }
        
        .order-total-section {
            border-top: 1px solid var(--border);
            padding-top: var(--space-4);
            margin-top: var(--space-6);
        }
        
        .order-total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: var(--space-2);
        }
        
        .order-total-label {
            color: var(--text-secondary);
        }
        
        .order-total-value {
            font-weight: var(--font-medium);
        }
        
        .order-total-row.grand-total {
            font-weight: var(--font-bold);
            font-size: var(--text-lg);
            color: var(--text-primary);
            margin-top: var(--space-2);
            padding-top: var(--space-2);
            border-top: 1px solid var(--border-light);
        }
        
        .modal-footer {
            padding: var(--space-4) var(--space-5);
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
            position: sticky;
            bottom: 0;
            background: var(--surface);
            z-index: 10;
        }
        
        /* Pagination */
        .pagination-container {
            margin-top: var(--space-6);
            display: flex;
            justify-content: center;
        }
        
        .pagination {
            display: flex;
            gap: var(--space-1);
            align-items: center;
        }
        
        .pagination-item {
            min-width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 var(--space-3);
            border-radius: var(--rounded);
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition-fast);
            border: 1px solid var(--border);
        }
        
        .pagination-item:hover {
            background: var(--gray-100);
            color: var(--text-primary);
        }
        
        .pagination-item.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }
        
        .pagination-item.disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        
        /* Form Controls */
        .form-group {
            display: flex;
            flex-direction: column;
            margin-bottom: var(--space-4);
        }
        
        .form-label {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--text-secondary);
            margin-bottom: var(--space-2);
        }
        
        .form-control {
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--border);
            border-radius: var(--rounded);
            font-size: var(--text-sm);
            transition: var(--transition-fast);
            background: var(--white);
            color: var(--text-primary);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-control::placeholder {
            color: var(--text-tertiary);
        }
        
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: var(--space-8);
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
        
        .btn-lg {
            padding: var(--space-3) var(--space-6);
            font-size: var(--text-base);
        }
        
        .btn-icon {
            padding: var(--space-2);
            border-radius: var(--rounded);
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
        
        .btn-success {
            background: var(--success);
            color: var(--white);
        }
        
        .btn-success:hover {
            background: #0d926a;
        }
        
        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }
        
        .btn-danger:hover {
            background: #c81e1e;
        }
        
        .btn-warning {
            background: var(--warning);
            color: var(--white);
        }
        
        .btn-warning:hover {
            background: #d48806;
        }
        
        .btn-outline-primary {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }
        
        .btn-outline-primary:hover {
            background: rgba(102, 126, 234, 0.1);
        }
        
        .btn-outline-danger {
            background: transparent;
            color: var(--danger);
            border: 1px solid var(--danger);
        }
        
        .btn-outline-danger:hover {
            background: rgba(239, 68, 68, 0.1);
        }
        
        /* Empty state */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: var(--space-12);
            color: var(--text-tertiary);
            text-align: center;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: var(--space-4);
            opacity: 0.7;
        }
        
        .empty-state-title {
            font-weight: var(--font-semibold);
            font-size: var(--text-xl);
            margin-bottom: var(--space-2);
            color: var(--text-secondary);
        }
        
        .empty-state-description {
            margin-bottom: var(--space-6);
            max-width: 400px;
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
            
            .order-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table-responsive {
                margin: 0 -1.5rem;
                padding: 0 1.5rem;
                width: calc(100% + 3rem);
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
            
            .page-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .order-stats {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-3);
            }
            
            .table-actions {
                width: 100%;
            }
            
            .order-info-grid {
                grid-template-columns: 1fr;
            }
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
                        <a href="orders.php" class="nav-link active">
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
                            <span>Đơn hàng</span>
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
                    <div class="page-title-wrapper">
                        <h1 class="page-title">Quản lý đơn hàng</h1>
                        <p class="page-subtitle">Quản lý và theo dõi tất cả các đơn hàng trên hệ thống</p>
                    </div>
                    
                    <div class="page-actions">
                        <a href="pos.php" class="btn btn-primary">
                            <span>🛒</span>
                            <span>Quản lý hệ thống POS</span>
                        </a>
                        <button class="btn btn-secondary" id="export-orders">
                            <span>📥</span>
                            <span>Xuất đơn hàng</span>
                        </button>
                    </div>
                </div>
                
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form action="orders.php" method="GET" class="filter-form">
                        <div class="filter-row">
                            <div class="filter-item">
                                <div class="form-group">
                                    <label for="status-filter" class="form-label">Trạng thái</label>
                                    <select id="status-filter" name="status" class="form-control">
                                        <option value="">Tất cả trạng thái</option>
                                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Chờ xử lý</option>
                                        <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Đang xử lý</option>
                                        <option value="shipped" <?php echo $status_filter === 'shipped' ? 'selected' : ''; ?>>Đang giao</option>
                                        <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Đã giao</option>
                                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Đã hủy</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="filter-item">
                                <div class="form-group">
                                    <label for="search-filter" class="form-label">Tìm kiếm</label>
                                    <input type="text" id="search-filter" name="search" class="form-control" placeholder="Mã đơn, tên khách hàng..." value="<?php echo safe_echo($search_term); ?>">
                                </div>
                            </div>
                            
                            <div class="filter-item">
                                <div class="form-group">
                                    <label for="date-from" class="form-label">Từ ngày</label>
                                    <input type="date" id="date-from" name="date_from" class="form-control" value="<?php echo safe_echo($date_from); ?>">
                                </div>
                            </div>
                            
                            <div class="filter-item">
                                <div class="form-group">
                                    <label for="date-to" class="form-label">Đến ngày</label>
                                    <input type="date" id="date-to" name="date_to" class="form-control" value="<?php echo safe_echo($date_to); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="filter-buttons">
                            <button type="submit" class="btn btn-primary">
                                <span>🔍</span>
                                <span>Lọc kết quả</span>
                            </button>
                            <a href="orders.php" class="btn btn-secondary">
                                <span>🔄</span>
                                <span>Đặt lại</span>
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Order Stats -->
                <div class="order-stats">
                    <div class="stat-card primary">
                        <div class="stat-title">Tổng đơn hàng</div>
                        <div class="stat-value"><?php echo number_format($order_stats['total']); ?></div>
                        <div class="stat-subtitle">Tất cả đơn hàng trên hệ thống</div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-title">Chờ xử lý</div>
                        <div class="stat-value"><?php echo number_format($order_stats['pending']); ?></div>
                        <div class="stat-subtitle">Đơn hàng đang chờ xử lý</div>
                    </div>
                    
                    <div class="stat-card info">
                        <div class="stat-title">Đang xử lý/giao</div>
                        <div class="stat-value"><?php echo number_format($order_stats['processing'] + $order_stats['shipped']); ?></div>
                        <div class="stat-subtitle">Đơn hàng đang được xử lý</div>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-title">Đã giao</div>
                        <div class="stat-value"><?php echo number_format($order_stats['delivered']); ?></div>
                        <div class="stat-subtitle">Đơn hàng đã giao thành công</div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-title">Đã hủy</div>
                        <div class="stat-value"><?php echo number_format($order_stats['cancelled']); ?></div>
                        <div class="stat-subtitle">Đơn hàng đã bị hủy</div>
                    </div>
                    
                    <div class="stat-card secondary">
                        <div class="stat-title">Tổng doanh thu</div>
                        <div class="stat-value"><?php echo formatCurrency($order_stats['total_revenue']); ?></div>
                        <div class="stat-subtitle">Đã thanh toán: <?php echo formatCurrency($order_stats['paid_revenue']); ?></div>
                    </div>
                </div>
                
                <!-- Orders Table -->
                <div class="orders-container">
                    <div class="table-header">
                        <h2 class="table-title">Danh sách đơn hàng</h2>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-secondary" id="print-orders">
                                <span>🖨️</span>
                                <span>In</span>
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="orders-table">
                            <thead>
                                <tr>
                                    <th class="sortable <?php echo $sort_by === 'id' ? 'sorted-' . strtolower($sort_order) : ''; ?>" data-sort="id">ID</th>
                                    <th class="sortable <?php echo $sort_by === 'code' ? 'sorted-' . strtolower($sort_order) : ''; ?>" data-sort="code">Mã đơn</th>
                                    <th>Khách hàng</th>
                                    <th class="sortable <?php echo $sort_by === 'delivery_status' ? 'sorted-' . strtolower($sort_order) : ''; ?>" data-sort="delivery_status">Trạng thái</th>
                                    <th>Thanh toán</th>
                                    <th class="sortable <?php echo $sort_by === 'grand_total' ? 'sorted-' . strtolower($sort_order) : ''; ?>" data-sort="grand_total">Tổng tiền</th>
                                    <th class="sortable <?php echo $sort_by === 'created_at' ? 'sorted-' . strtolower($sort_order) : ''; ?>" data-sort="created_at">Ngày đặt</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($orders)): ?>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td>
                                                <span class="order-id">#<?php echo (int)$order['id']; ?></span>
                                            </td>
                                            <td>
                                                <?php echo safe_echo(get_value($order, 'code', '-')); ?>
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
                                                        <?php if (has_value($order, 'customer_email') || has_value($order, 'customer_phone')): ?>
                                                            <span class="customer-email">
                                                                <?php echo has_value($order, 'customer_email') ? safe_echo($order['customer_email']) : safe_echo($order['customer_phone']); ?>
                                                            </span>
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
                                                <span class="payment-status <?php echo safe_echo(get_value($order, 'payment_status', 'unpaid')); ?>">
                                                    <?php echo get_value($order, 'payment_status', 'unpaid') === 'paid' ? 'Đã thanh toán' : 'Chưa thanh toán'; ?>
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
                                            <td>
                                                <div class="order-actions">
                                                    <button class="btn btn-sm btn-secondary btn-view-order" data-id="<?php echo (int)$order['id']; ?>">
                                                        <span>👁️</span>
                                                    </button>
                                                    <button class="btn btn-sm btn-secondary btn-change-status" data-id="<?php echo (int)$order['id']; ?>" data-current="<?php echo safe_echo(get_value($order, 'delivery_status', 'pending')); ?>">
                                                        <span>📝</span>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger btn-delete-order" data-id="<?php echo (int)$order['id']; ?>">
                                                        <span>🗑️</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <div class="empty-state-icon">📭</div>
                                                <h3 class="empty-state-title">Không tìm thấy đơn hàng nào</h3>
                                                <p class="empty-state-description">
                                                    Không có đơn hàng nào phù hợp với điều kiện tìm kiếm của bạn. Hãy thử thay đổi bộ lọc hoặc tạo đơn hàng mới.
                                                </p>
                                                <a href="orders.php" class="btn btn-primary">
                                                    <span>🔄</span>
                                                    <span>Đặt lại bộ lọc</span>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-container">
                            <?php echo paginate($page, $total_pages, getFilterUrl('%d')); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Order Detail Modal -->
    <div class="modal-backdrop" id="order-detail-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Chi tiết đơn hàng #<span id="modal-order-id"></span></h2>
                <button class="modal-close" id="modal-close">×</button>
            </div>
            <div class="modal-body">
                <!-- Order Details will be loaded here via JavaScript -->
                <div id="order-detail-content"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="modal-close-btn">Đóng</button>
                <button class="btn btn-primary" id="modal-update-status">Cập nhật trạng thái</button>
            </div>
        </div>
    </div>
    
    <!-- Change Status Modal -->
    <div class="modal-backdrop" id="change-status-modal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title">Thay đổi trạng thái đơn hàng</h2>
                <button class="modal-close" id="status-modal-close">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="order-status" class="form-label">Chọn trạng thái mới</label>
                    <select id="order-status" class="form-control">
                        <option value="pending">Chờ xử lý</option>
                        <option value="processing">Đang xử lý</option>
                        <option value="shipped">Đang giao</option>
                        <option value="delivered">Đã giao</option>
                        <option value="cancelled">Đã hủy</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="status-modal-cancel">Hủy</button>
                <button class="btn btn-primary" id="status-modal-save">Lưu thay đổi</button>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal-backdrop" id="delete-confirm-modal">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header">
                <h2 class="modal-title">Xác nhận xóa</h2>
                <button class="modal-close" id="delete-modal-close">×</button>
            </div>
            <div class="modal-body">
                <p>Bạn có chắc chắn muốn xóa đơn hàng này? Hành động này không thể hoàn tác.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="delete-modal-cancel">Hủy</button>
                <button class="btn btn-danger" id="delete-modal-confirm">Xóa</button>
            </div>
        </div>
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
        
        // Table sorting
        document.querySelectorAll('th.sortable').forEach(header => {
            header.addEventListener('click', () => {
                const sort = header.dataset.sort;
                let order = 'asc';
                
                // If already sorted, toggle order
                if (header.classList.contains('sorted-asc')) {
                    order = 'desc';
                } else if (header.classList.contains('sorted-desc')) {
                    order = 'asc';
                }
                
                window.location.href = '<?php echo getFilterUrl(1, []); ?>&sort=' + sort + '&order=' + order;
            });
        });
        
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
                
                return await response.json();
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
                return { success: false, message: error.message };
            }
        }
        
        // Order detail modal
        const orderDetailModal = document.getElementById('order-detail-modal');
        const orderDetailContent = document.getElementById('order-detail-content');
        const modalOrderId = document.getElementById('modal-order-id');
        
        // View order button click
        document.querySelectorAll('.btn-view-order').forEach(btn => {
            btn.addEventListener('click', async function() {
                const orderId = this.dataset.id;
                modalOrderId.textContent = orderId;
                
                // Show loading
                orderDetailContent.innerHTML = '<div class="empty-state"><div class="empty-state-icon">⌛</div><h3>Đang tải...</h3></div>';
                
                // Show modal
                orderDetailModal.classList.add('active');
                setTimeout(() => {
                    orderDetailModal.querySelector('.modal').classList.add('active');
                }, 10);
                
                // Fetch order details
                const result = await makeRequest('get_order_details', { order_id: orderId });
                
                if (result.success) {
                    // Format order details
                    const order = result.order;
                    const items = result.items;
                    
                    let shippingAddress = '';
                    try {
                        if (order.shipping_address) {
                            const addressObj = JSON.parse(order.shipping_address);
                            shippingAddress = [
                                addressObj.address,
                                addressObj.city,
                                addressObj.state,
                                addressObj.country,
                                addressObj.postal_code
                            ].filter(Boolean).join(', ');
                        }
                    } catch (e) {
                        shippingAddress = order.shipping_address;
                    }
                    
                    // Format created_at date
                    const orderDate = new Date(order.created_at);
                    const formattedDate = orderDate.toLocaleDateString('vi-VN', { 
                        day: '2-digit', 
                        month: '2-digit', 
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    // Delivery status text
                    let deliveryStatusText = '';
                    switch (order.delivery_status) {
                        case 'pending': deliveryStatusText = 'Chờ xử lý'; break;
                        case 'processing': deliveryStatusText = 'Đang xử lý'; break;
                        case 'shipped': deliveryStatusText = 'Đang giao'; break;
                        case 'delivered': deliveryStatusText = 'Đã giao'; break;
                        case 'cancelled': deliveryStatusText = 'Đã hủy'; break;
                        default: deliveryStatusText = order.delivery_status;
                    }
                    
                    // Payment status text
                    const paymentStatusText = order.payment_status === 'paid' ? 'Đã thanh toán' : 'Chưa thanh toán';
                    
                    // Build HTML content
                    let html = `
                        <div class="modal-section">
                            <h3 class="modal-section-title">Thông tin đơn hàng</h3>
                            <div class="order-info-grid">
                                <div class="order-info-item">
                                    <div class="order-info-label">Mã đơn hàng</div>
                                    <div class="order-info-value">${order.code || '-'}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Ngày đặt</div>
                                    <div class="order-info-value">${formattedDate}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Trạng thái giao hàng</div>
                                    <div class="order-info-value">
                                        <span class="order-status ${order.delivery_status}">${deliveryStatusText}</span>
                                    </div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Trạng thái thanh toán</div>
                                    <div class="order-info-value">
                                        <span class="payment-status ${order.payment_status}">${paymentStatusText}</span>
                                    </div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Phương thức thanh toán</div>
                                    <div class="order-info-value">${order.payment_type || 'Không có thông tin'}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Phương thức vận chuyển</div>
                                    <div class="order-info-value">${order.shipping_type || 'Không có thông tin'}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="modal-section">
                            <h3 class="modal-section-title">Thông tin khách hàng</h3>
                            <div class="order-info-grid">
                                <div class="order-info-item">
                                    <div class="order-info-label">Tên khách hàng</div>
                                    <div class="order-info-value">${order.customer_name || 'Khách vãng lai'}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Email</div>
                                    <div class="order-info-value">${order.customer_email || 'Không có thông tin'}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Số điện thoại</div>
                                    <div class="order-info-value">${order.customer_phone || 'Không có thông tin'}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Địa chỉ giao hàng</div>
                                    <div class="order-info-value">${shippingAddress || 'Không có thông tin'}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="modal-section">
                            <h3 class="modal-section-title">Sản phẩm đã đặt</h3>
                            <div class="table-responsive">
                                <table class="order-items-table">
                                    <thead>
                                        <tr>
                                            <th>Sản phẩm</th>
                                            <th>Đơn giá</th>
                                            <th>Số lượng</th>
                                            <th>Thành tiền</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                    `;
                    
                    // Calculate totals
                    let subtotal = 0;
                    let totalTax = 0;
                    let totalShipping = 0;
                    
                    items.forEach(item => {
                        const itemTotal = parseFloat(item.price) * parseInt(item.quantity);
                        subtotal += itemTotal;
                        totalTax += parseFloat(item.tax) || 0;
                        totalShipping += parseFloat(item.shipping_cost) || 0;
                        
                        // Format variation if exists
                        let variationText = '';
                        try {
                            if (item.variation) {
                                const variation = JSON.parse(item.variation);
                                variationText = Object.entries(variation)
                                    .map(([key, value]) => `${key}: ${value}`)
                                    .join(', ');
                            }
                        } catch (e) {
                            variationText = item.variation;
                        }
                        
                        html += `
                            <tr>
                                <td>
                                    <div class="product-info">
                                        <img src="${item.product_image ? '../' + item.product_image : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><rect width="40" height="40" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="14" fill="%236b7280">📦</text></svg>'}" alt="${item.product_name}" class="product-image">
                                        <div class="product-details">
                                            <div class="product-name">${item.product_name}</div>
                                            ${variationText ? `<div class="product-variant">${variationText}</div>` : ''}
                                        </div>
                                    </div>
                                </td>
                                <td>${formatCurrency(item.price)}</td>
                                <td>${item.quantity}</td>
                                <td>${formatCurrency(itemTotal)}</td>
                            </tr>
                        `;
                    });
                    
                    const couponDiscount = parseFloat(order.coupon_discount) || 0;
                    const grandTotal = subtotal + totalTax + totalShipping - couponDiscount;
                    
                    html += `
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="order-total-section">
                                <div class="order-total-row">
                                    <div class="order-total-label">Tạm tính</div>
                                    <div class="order-total-value">${formatCurrency(subtotal)}</div>
                                </div>
                                ${totalTax > 0 ? `
                                <div class="order-total-row">
                                    <div class="order-total-label">Thuế</div>
                                    <div class="order-total-value">${formatCurrency(totalTax)}</div>
                                </div>
                                ` : ''}
                                ${totalShipping > 0 ? `
                                <div class="order-total-row">
                                    <div class="order-total-label">Phí vận chuyển</div>
                                    <div class="order-total-value">${formatCurrency(totalShipping)}</div>
                                </div>
                                ` : ''}
                                ${couponDiscount > 0 ? `
                                <div class="order-total-row">
                                    <div class="order-total-label">Giảm giá</div>
                                    <div class="order-total-value">-${formatCurrency(couponDiscount)}</div>
                                </div>
                                ` : ''}
                                <div class="order-total-row grand-total">
                                    <div class="order-total-label">Tổng thanh toán</div>
                                    <div class="order-total-value">${formatCurrency(order.grand_total)}</div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    orderDetailContent.innerHTML = html;
                    
                    // Set current order ID for update status button
                    document.getElementById('modal-update-status').dataset.id = orderId;
                    document.getElementById('modal-update-status').dataset.current = order.delivery_status;
                    
                } else {
                    orderDetailContent.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state-icon">❌</div>
                            <h3 class="empty-state-title">Lỗi khi tải dữ liệu</h3>
                            <p>${result.message || 'Không thể tải thông tin đơn hàng. Vui lòng thử lại sau.'}</p>
                        </div>
                    `;
                }
            });
        });
        
        // Close order detail modal
        document.getElementById('modal-close').addEventListener('click', closeOrderDetailModal);
        document.getElementById('modal-close-btn').addEventListener('click', closeOrderDetailModal);
        
        function closeOrderDetailModal() {
            orderDetailModal.querySelector('.modal').classList.remove('active');
            setTimeout(() => {
                orderDetailModal.classList.remove('active');
            }, 300);
        }
        
        // Update order status modal
        const changeStatusModal = document.getElementById('change-status-modal');
        const orderStatusSelect = document.getElementById('order-status');
        let currentOrderId = null;
        
        // Show change status modal from detail modal
        document.getElementById('modal-update-status').addEventListener('click', function() {
            const orderId = this.dataset.id;
            const currentStatus = this.dataset.current;
            
            // Set current order ID
            currentOrderId = orderId;
            
            // Set current status in select
            orderStatusSelect.value = currentStatus;
            
            // Show modal
            changeStatusModal.classList.add('active');
            setTimeout(() => {
                changeStatusModal.querySelector('.modal').classList.add('active');
            }, 10);
        });
        
        // Show change status modal from list
        document.querySelectorAll('.btn-change-status').forEach(btn => {
            btn.addEventListener('click', function() {
                const orderId = this.dataset.id;
                const currentStatus = this.dataset.current;
                
                // Set current order ID
                currentOrderId = orderId;
                
                // Set current status in select
                orderStatusSelect.value = currentStatus;
                
                // Show modal
                changeStatusModal.classList.add('active');
                setTimeout(() => {
                    changeStatusModal.querySelector('.modal').classList.add('active');
                }, 10);
            });
        });
        
        // Close change status modal
        document.getElementById('status-modal-close').addEventListener('click', closeChangeStatusModal);
        document.getElementById('status-modal-cancel').addEventListener('click', closeChangeStatusModal);
        
        function closeChangeStatusModal() {
            changeStatusModal.querySelector('.modal').classList.remove('active');
            setTimeout(() => {
                changeStatusModal.classList.remove('active');
                currentOrderId = null;
            }, 300);
        }
        
        // Save status change
        document.getElementById('status-modal-save').addEventListener('click', async function() {
            if (!currentOrderId) {
                showNotification('Không tìm thấy ID đơn hàng', 'error');
                return;
            }
            
            const newStatus = orderStatusSelect.value;
            
            // Show loading state
            this.disabled = true;
            this.innerHTML = '⌛ Đang xử lý...';
            
            // Update status
            const result = await makeRequest('update_order_status', {
                order_id: currentOrderId,
                status: newStatus
            });
            
            if (result.success) {
                showNotification(result.message, 'success');
                closeChangeStatusModal();
                
                // Reload page after a short delay
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showNotification(result.message || 'Có lỗi xảy ra khi cập nhật trạng thái', 'error');
                this.disabled = false;
                this.innerHTML = 'Lưu thay đổi';
            }
        });
        
        // Delete order confirmation
        const deleteConfirmModal = document.getElementById('delete-confirm-modal');
        let deleteOrderId = null;
        
        // Show delete confirmation modal
        document.querySelectorAll('.btn-delete-order').forEach(btn => {
            btn.addEventListener('click', function() {
                deleteOrderId = this.dataset.id;
                
                // Show modal
                deleteConfirmModal.classList.add('active');
                setTimeout(() => {
                    deleteConfirmModal.querySelector('.modal').classList.add('active');
                }, 10);
            });
        });
        
        // Close delete confirmation modal
        document.getElementById('delete-modal-close').addEventListener('click', closeDeleteModal);
        document.getElementById('delete-modal-cancel').addEventListener('click', closeDeleteModal);
        
        function closeDeleteModal() {
            deleteConfirmModal.querySelector('.modal').classList.remove('active');
            setTimeout(() => {
                deleteConfirmModal.classList.remove('active');
                deleteOrderId = null;
            }, 300);
        }
        
        // Confirm delete
        document.getElementById('delete-modal-confirm').addEventListener('click', async function() {
            if (!deleteOrderId) {
                showNotification('Không tìm thấy ID đơn hàng', 'error');
                return;
            }
            
            // Show loading state
            this.disabled = true;
            this.innerHTML = '⌛ Đang xử lý...';
            
            // Delete order
            const result = await makeRequest('delete_order', {
                order_id: deleteOrderId
            });
            
            if (result.success) {
                showNotification(result.message, 'success');
                closeDeleteModal();
                
                // Reload page after a short delay
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showNotification(result.message || 'Có lỗi xảy ra khi xóa đơn hàng', 'error');
                this.disabled = false;
                this.innerHTML = 'Xóa';
            }
        });
        
        // Export orders
        document.getElementById('export-orders').addEventListener('click', function() {
            showNotification('Đang chuẩn bị xuất dữ liệu đơn hàng...', 'info');
            
            // Simulate export process
            setTimeout(() => {
                showNotification('Đã xuất dữ liệu đơn hàng thành công', 'success');
            }, 1500);
        });
        
        // Print orders
        document.getElementById('print-orders').addEventListener('click', function() {
            showNotification('Đang chuẩn bị in danh sách đơn hàng...', 'info');
            
            // Simulate print process
            setTimeout(() => {
                window.print();
            }, 500);
        });
        
        // Format currency helper
        function formatCurrency(amount) {
            return new Intl.NumberFormat('vi-VN', {
                style: 'currency',
                currency: 'VND',
                maximumFractionDigits: 0
            }).format(amount);
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
            console.log('🚀 Orders - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Orders - Ready!');
        });
    </script>
</body>
</html>