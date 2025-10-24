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

$message = '';
$error = '';
$order_id = intval($_GET['id'] ?? 0);

if ($order_id <= 0) {
    header('Location: orders.php?error=invalid_order_id');
    exit;
}

// Handle order updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['admin_token'], $_POST['csrf_token'])) {
        $error = 'Invalid CSRF token';
    } else {
        switch ($_POST['action']) {
            case 'update_status':
                $delivery_status = $_POST['delivery_status'] ?? '';
                $payment_status = $_POST['payment_status'] ?? '';
                $tracking_code = trim($_POST['tracking_code'] ?? '');
                $admin_notes = trim($_POST['admin_notes'] ?? '');
                
                try {
                    $stmt = $db->prepare("
                        UPDATE orders 
                        SET delivery_status = ?, 
                            payment_status = ?, 
                            tracking_code = ?,
                            updated_at = CURRENT_TIMESTAMP 
                        WHERE id = ?
                    ");
                    
                    if ($stmt->execute([$delivery_status, $payment_status, $tracking_code, $order_id])) {
                        // Add admin note if provided
                        if (!empty($admin_notes)) {
                            $stmt = $db->prepare("
                                INSERT INTO order_notes (order_id, user_id, note, note_type, created_at) 
                                VALUES (?, ?, ?, 'admin', CURRENT_TIMESTAMP)
                            ");
                            $stmt->execute([$order_id, $_SESSION['user_id'], $admin_notes]);
                        }
                        
                        $message = "Cập nhật đơn hàng #$order_id thành công!";
                        
                        // Log activity
                        error_log("Order #$order_id updated by admin ID: " . $_SESSION['user_id']);
                    } else {
                        $error = "Không thể cập nhật đơn hàng.";
                    }
                } catch (PDOException $e) {
                    error_log("Update order error: " . $e->getMessage());
                    $error = "Lỗi cơ sở dữ liệu khi cập nhật.";
                }
                break;
                
            case 'add_note':
                $note = trim($_POST['note'] ?? '');
                if (!empty($note)) {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO order_notes (order_id, user_id, note, note_type, created_at) 
                            VALUES (?, ?, ?, 'admin', CURRENT_TIMESTAMP)
                        ");
                        
                        if ($stmt->execute([$order_id, $_SESSION['user_id'], $note])) {
                            $message = "Thêm ghi chú thành công!";
                        }
                    } catch (PDOException $e) {
                        error_log("Add note error: " . $e->getMessage());
                        $error = "Không thể thêm ghi chú.";
                    }
                }
                break;
        }
    }
}

// Get order details
$order = null;
try {
    $stmt = $db->prepare("
        SELECT o.*, 
               u.name as customer_name, 
               u.email as customer_email,
               u.phone as customer_phone,
               u.avatar as customer_avatar,
               co.shipping_address as combined_address
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN combined_orders co ON o.combined_order_id = co.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        header('Location: orders.php?error=order_not_found');
        exit;
    }
} catch (PDOException $e) {
    error_log("Fetch order error: " . $e->getMessage());
    header('Location: orders.php?error=database_error');
    exit;
}

// Get order items
$order_items = [];
try {
    $stmt = $db->prepare("
        SELECT od.*, 
               p.name as product_name,
               p.slug as product_slug,
               p.sku as product_sku,
               p.weight as product_weight,
               u.file_name as product_image,
               b.name as brand_name,
               us.name as seller_name
        FROM order_details od
        LEFT JOIN products p ON od.product_id = p.id
        LEFT JOIN uploads u ON p.thumbnail_img = u.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN users us ON p.user_id = us.id
        WHERE od.order_id = ?
        ORDER BY od.id ASC
    ");
    $stmt->execute([$order_id]);
    $order_items = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Fetch order items error: " . $e->getMessage());
    $order_items = [];
}

// Get order notes/history
$order_notes = [];
try {
    $stmt = $db->prepare("
        SELECT on.*, u.name as user_name
        FROM order_notes on
        LEFT JOIN users u ON on.user_id = u.id
        WHERE on.order_id = ?
        ORDER BY on.created_at DESC
    ");
    $stmt->execute([$order_id]);
    $order_notes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Fetch order notes error: " . $e->getMessage());
    $order_notes = [];
}

// Get shipping info
$shipping_info = null;
if ($order['shipping_address']) {
    $shipping_info = json_decode($order['shipping_address'], true);
} elseif ($order['combined_address']) {
    $shipping_info = json_decode($order['combined_address'], true);
}

// Currency formatting
function formatCurrency($amount, $currency = 'VND') {
    if ($currency === 'VND') {
        return number_format($amount, 0, ',', '.') . '₫';
    } else {
        return '$' . number_format($amount, 2, '.', ',');
    }
}

// Status translation
function getStatusText($status, $type = 'delivery') {
    $delivery_statuses = [
        'pending' => 'Chờ xử lý',
        'confirmed' => 'Đã xác nhận',
        'processing' => 'Đang xử lý',
        'shipped' => 'Đã gửi hàng',
        'delivered' => 'Đã giao hàng',
        'cancelled' => 'Đã hủy',
        'returned' => 'Trả hàng'
    ];
    
    $payment_statuses = [
        'unpaid' => 'Chưa thanh toán',
        'pending' => 'Chờ thanh toán',
        'paid' => 'Đã thanh toán',
        'refunded' => 'Đã hoàn tiền',
        'failed' => 'Thanh toán thất bại'
    ];
    
    if ($type === 'payment') {
        return $payment_statuses[$status] ?? ucfirst($status);
    }
    
    return $delivery_statuses[$status] ?? ucfirst($status);
}

// Order timeline
function getOrderTimeline($order, $order_notes) {
    $timeline = [];
    
    // Order created
    $timeline[] = [
        'date' => $order['created_at'],
        'title' => 'Đơn hàng được tạo',
        'description' => 'Khách hàng đã đặt đơn hàng #' . $order['id'],
        'type' => 'created',
        'icon' => '📦'
    ];
    
    // Add notes to timeline
    foreach ($order_notes as $note) {
        $timeline[] = [
            'date' => $note['created_at'],
            'title' => $note['note_type'] === 'admin' ? 'Ghi chú từ Admin' : 'Ghi chú hệ thống',
            'description' => $note['note'],
            'type' => 'note',
            'icon' => '📝',
            'user' => $note['user_name']
        ];
    }
    
    // Sort by date
    usort($timeline, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    return $timeline;
}

// Business settings
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

// Calculate totals
$subtotal = 0;
$total_tax = 0;
$total_shipping = 0;
$total_items = 0;

foreach ($order_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
    $total_tax += $item['tax'];
    $total_shipping += $item['shipping_cost'];
    $total_items += $item['quantity'];
}

$timeline = getOrderTimeline($order, $order_notes);
$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đơn hàng #<?php echo $order['id']; ?> - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Chi tiết đơn hàng #<?php echo $order['id']; ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root {
            /* Enhanced Color System */
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --accent-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
            --danger-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            
            /* Core Colors */
            --primary: #667eea;
            --primary-dark: #4c63d2;
            --primary-light: #8fa1f5;
            --secondary: #f5576c;
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
            
            /* Shadows & Effects */
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
            
            /* Typography */
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
            --transition-normal: all 200ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: all 300ms cubic-bezier(0.4, 0, 0.2, 1);
            
            /* Font Families */
            --font-sans: 'Inter', 'Poppins', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-heading: 'Poppins', system-ui, sans-serif;
        }
        
        /* Reset & Base */
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
        
        /* Sidebar (simplified version) */
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
        
        .breadcrumb-separator {
            color: var(--text-tertiary);
        }
        
        .breadcrumb-link {
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition-normal);
        }
        
        .breadcrumb-link:hover {
            text-decoration: underline;
        }
        
        .header-actions {
            display: flex;
            gap: var(--space-3);
            align-items: center;
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
        
        /* Order Header */
        .order-header {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            padding: var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            margin-bottom: var(--space-6);
            position: relative;
            overflow: hidden;
        }
        
        .order-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }
        
        .order-header-content {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: var(--space-6);
            align-items: start;
        }
        
        .order-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-6);
        }
        
        .order-field {
            display: flex;
            flex-direction: column;
            gap: var(--space-1);
        }
        
        .order-label {
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .order-value {
            font-size: var(--text-base);
            font-weight: var(--font-medium);
            color: var(--text-primary);
        }
        
        .order-value.large {
            font-size: var(--text-xl);
            font-weight: var(--font-bold);
        }
        
        .order-actions {
            display: flex;
            gap: var(--space-3);
            flex-wrap: wrap;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: var(--space-2) var(--space-4);
            border-radius: var(--rounded-full);
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-badge.pending {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .status-badge.confirmed {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .status-badge.processing {
            background: rgba(139, 92, 246, 0.1);
            color: #5b21b6;
        }
        
        .status-badge.shipped {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .status-badge.delivered {
            background: rgba(34, 197, 94, 0.1);
            color: #14532d;
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
        
        .status-badge.refunded {
            background: rgba(107, 114, 128, 0.1);
            color: #374151;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            padding: var(--space-3) var(--space-5);
            border: none;
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            cursor: pointer;
            text-decoration: none;
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
            background: var(--gray-100);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }
        
        .btn-secondary:hover {
            background: var(--gray-200);
        }
        
        .btn-success {
            background: var(--success-gradient);
            color: var(--white);
        }
        
        .btn-warning {
            background: var(--warning-gradient);
            color: var(--white);
        }
        
        .btn-danger {
            background: var(--danger-gradient);
            color: var(--white);
        }
        
        .btn-sm {
            padding: var(--space-2) var(--space-3);
            font-size: var(--text-xs);
        }
        
        /* Grid Layout */
        .order-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: var(--space-6);
            margin-bottom: var(--space-6);
        }
        
        /* Cards */
        .card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            overflow: hidden;
        }
        
        .card-header {
            padding: var(--space-6);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-title {
            font-family: var(--font-heading);
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .card-content {
            padding: var(--space-6);
        }
        
        /* Order Items */
        .order-items {
            margin-bottom: var(--space-6);
        }
        
        .item-list {
            space-y: var(--space-4);
        }
        
        .order-item {
            display: flex;
            gap: var(--space-4);
            padding: var(--space-4);
            border: 1px solid var(--border-light);
            border-radius: var(--rounded-lg);
            transition: var(--transition-normal);
            margin-bottom: var(--space-4);
        }
        
        .order-item:hover {
            box-shadow: var(--shadow-sm);
            border-color: var(--primary);
        }
        
        .item-image {
            width: 80px;
            height: 80px;
            border-radius: var(--rounded-lg);
            object-fit: cover;
            background: var(--gray-100);
            flex-shrink: 0;
        }
        
        .item-details {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: var(--space-2);
        }
        
        .item-name {
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            font-size: var(--text-base);
            line-height: 1.4;
        }
        
        .item-meta {
            display: flex;
            gap: var(--space-4);
            font-size: var(--text-sm);
            color: var(--text-secondary);
            flex-wrap: wrap;
        }
        
        .item-variation {
            background: var(--gray-100);
            padding: var(--space-1) var(--space-2);
            border-radius: var(--rounded);
            font-size: var(--text-xs);
        }
        
        .item-pricing {
            text-align: right;
            display: flex;
            flex-direction: column;
            gap: var(--space-1);
            align-items: flex-end;
        }
        
        .item-price {
            font-size: var(--text-lg);
            font-weight: var(--font-bold);
            color: var(--text-primary);
        }
        
        .item-quantity {
            font-size: var(--text-sm);
            color: var(--text-secondary);
        }
        
        .item-total {
            font-size: var(--text-base);
            font-weight: var(--font-semibold);
            color: var(--primary);
        }
        
        /* Order Summary */
        .order-summary {
            background: var(--gray-50);
            border-radius: var(--rounded-lg);
            padding: var(--space-5);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--space-2) 0;
            border-bottom: 1px solid var(--border-light);
        }
        
        .summary-row:last-child {
            border-bottom: none;
            font-weight: var(--font-bold);
            font-size: var(--text-lg);
            color: var(--primary);
        }
        
        .summary-label {
            color: var(--text-secondary);
            font-size: var(--text-sm);
        }
        
        .summary-value {
            font-weight: var(--font-medium);
            color: var(--text-primary);
        }
        
        /* Customer Info */
        .customer-card {
            padding: var(--space-5);
        }
        
        .customer-avatar {
            width: 60px;
            height: 60px;
            background: var(--primary-gradient);
            border-radius: var(--rounded-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: var(--font-bold);
            font-size: var(--text-xl);
            margin-bottom: var(--space-4);
        }
        
        .customer-name {
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            margin-bottom: var(--space-2);
        }
        
        .customer-detail {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            margin-bottom: var(--space-2);
            font-size: var(--text-sm);
            color: var(--text-secondary);
        }
        
        /* Shipping Address */
        .address-card {
            padding: var(--space-5);
        }
        
        .address-text {
            line-height: 1.6;
            color: var(--text-primary);
        }
        
        /* Timeline */
        .timeline-container {
            margin-bottom: var(--space-6);
        }
        
        .timeline {
            position: relative;
            padding-left: var(--space-8);
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: var(--space-6);
            background: var(--surface);
            border-radius: var(--rounded-lg);
            padding: var(--space-4);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-xs);
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -28px;
            top: 20px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary);
            border: 3px solid var(--surface);
            box-shadow: 0 0 0 2px var(--border);
        }
        
        .timeline-icon {
            position: absolute;
            left: -35px;
            top: 15px;
            font-size: var(--text-lg);
            background: var(--surface);
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .timeline-content {
            margin-left: 0;
        }
        
        .timeline-title {
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            margin-bottom: var(--space-1);
        }
        
        .timeline-description {
            color: var(--text-secondary);
            font-size: var(--text-sm);
            margin-bottom: var(--space-2);
        }
        
        .timeline-date {
            font-size: var(--text-xs);
            color: var(--text-tertiary);
        }
        
        /* Messages */
        .message {
            padding: var(--space-4) var(--space-5);
            border-radius: var(--rounded-lg);
            margin-bottom: var(--space-6);
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .message.success {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .message.error {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        /* Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition-normal);
        }
        
        .modal.show {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background: var(--white);
            border-radius: var(--rounded-xl);
            padding: var(--space-6);
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-2xl);
            transform: scale(0.9);
            transition: var(--transition-normal);
        }
        
        .modal.show .modal-content {
            transform: scale(1);
        }
        
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-4);
        }
        
        .modal-title {
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: var(--text-xl);
            color: var(--text-secondary);
            cursor: pointer;
            padding: var(--space-2);
            border-radius: var(--rounded);
            transition: var(--transition-normal);
        }
        
        .modal-close:hover {
            background: var(--gray-100);
            color: var(--text-primary);
        }
        
        .form-group {
            margin-bottom: var(--space-4);
        }
        
        .form-label {
            display: block;
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--text-primary);
            margin-bottom: var(--space-2);
        }
        
        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: var(--space-3) var(--space-4);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            background: var(--white);
            transition: var(--transition-normal);
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .modal-actions {
            display: flex;
            gap: var(--space-3);
            justify-content: flex-end;
            margin-top: var(--space-6);
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
            
            .order-grid {
                grid-template-columns: 1fr;
            }
            
            .order-header-content {
                grid-template-columns: 1fr;
            }
            
            .order-actions {
                justify-content: stretch;
            }
            
            .order-actions .btn {
                flex: 1;
                justify-content: center;
            }
        }
        
        @media (max-width: 768px) {
            .header {
                padding: var(--space-3) var(--space-4);
            }
            
            .content {
                padding: var(--space-4);
            }
            
            .page-title {
                font-size: var(--text-2xl);
            }
            
            .order-info {
                grid-template-columns: 1fr;
            }
            
            .order-item {
                flex-direction: column;
                align-items: start;
            }
            
            .item-pricing {
                align-items: flex-start;
                text-align: left;
            }
            
            .timeline {
                padding-left: var(--space-6);
            }
            
            .timeline-item::before,
            .timeline-icon {
                left: -20px;
            }
        }
        
        @media (max-width: 480px) {
            .modal-content {
                padding: var(--space-4);
                width: 95%;
            }
            
            .btn {
                padding: var(--space-2) var(--space-3);
                font-size: var(--text-xs);
            }
            
            .card-header,
            .card-content {
                padding: var(--space-4);
            }
        }
        
        /* Print Styles */
        @media print {
            .sidebar,
            .header,
            .modal,
            .btn {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .content {
                padding: 0;
            }
            
            body {
                background: white;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid #000;
            }
        }
        
        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid var(--border);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Accessibility */
        *:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }
        
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
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
                        <a href="dashboard.php" class="breadcrumb-link">Admin</a>
                        <span class="breadcrumb-separator">›</span>
                        <a href="orders.php" class="breadcrumb-link">Đơn hàng</a>
                        <span class="breadcrumb-separator">›</span>
                        <span>Chi tiết #<?php echo $order['id']; ?></span>
                    </nav>
                </div>
                
                <div class="header-actions">
                    <button type="button" class="btn btn-secondary" onclick="window.print()">
                        🖨️ In đơn hàng
                    </button>
                    <button type="button" class="btn btn-warning" onclick="showModal('update-order-modal')">
                        ✏️ Cập nhật trạng thái
                    </button>
                    <a href="orders.php" class="btn btn-primary">
                        ← Quay lại danh sách
                    </a>
                </div>
            </header>
            
            <!-- Content -->
            <div class="content">
                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="message success">
                        <span>✅</span>
                        <span><?php echo htmlspecialchars($message); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="message error">
                        <span>❌</span>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>
                
                <!-- Order Header -->
                <div class="order-header">
                    <div class="order-header-content">
                        <div class="order-info">
                            <div class="order-field">
                                <div class="order-label">Mã đơn hàng</div>
                                <div class="order-value large">#<?php echo $order['id']; ?></div>
                            </div>
                            
                            <div class="order-field">
                                <div class="order-label">Ngày đặt hàng</div>
                                <div class="order-value"><?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?></div>
                            </div>
                            
                            <div class="order-field">
                                <div class="order-label">Tổng tiền</div>
                                <div class="order-value large"><?php echo formatCurrency($order['grand_total']); ?></div>
                            </div>
                            
                            <div class="order-field">
                                <div class="order-label">Trạng thái giao hàng</div>
                                <span class="status-badge <?php echo $order['delivery_status']; ?>">
                                    <?php echo getStatusText($order['delivery_status'], 'delivery'); ?>
                                </span>
                            </div>
                            
                            <div class="order-field">
                                <div class="order-label">Trạng thái thanh toán</div>
                                <span class="status-badge <?php echo $order['payment_status']; ?>">
                                    <?php echo getStatusText($order['payment_status'], 'payment'); ?>
                                </span>
                            </div>
                            
                            <?php if ($order['tracking_code']): ?>
                            <div class="order-field">
                                <div class="order-label">Mã vận đơn</div>
                                <div class="order-value"><?php echo htmlspecialchars($order['tracking_code']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Order Content Grid -->
                <div class="order-grid">
                    <!-- Order Items -->
                    <div class="order-items">
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title">Sản phẩm đặt hàng (<?php echo $total_items; ?> sản phẩm)</h2>
                            </div>
                            <div class="card-content">
                                <div class="item-list">
                                    <?php foreach ($order_items as $item): ?>
                                        <div class="order-item">
                                            <img 
                                                src="<?php echo !empty($item['product_image']) && file_exists('../' . $item['product_image']) ? '../' . htmlspecialchars($item['product_image']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80"><rect width="80" height="80" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="24" fill="%236b7280">📦</text></svg>'; ?>" 
                                                alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                                class="item-image"
                                                loading="lazy"
                                            >
                                            
                                            <div class="item-details">
                                                <div class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                                
                                                <div class="item-meta">
                                                    <?php if ($item['product_sku']): ?>
                                                        <span>SKU: <?php echo htmlspecialchars($item['product_sku']); ?></span>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($item['brand_name']): ?>
                                                        <span>Thương hiệu: <?php echo htmlspecialchars($item['brand_name']); ?></span>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($item['seller_name']): ?>
                                                        <span>Người bán: <?php echo htmlspecialchars($item['seller_name']); ?></span>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($item['product_weight']): ?>
                                                        <span>Trọng lượng: <?php echo $item['product_weight']; ?>g</span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <?php if ($item['variation'] && $item['variation'] !== '[]'): ?>
                                                    <div class="item-variation">
                                                        <?php 
                                                            $variations = json_decode($item['variation'], true);
                                                            if (is_array($variations)) {
                                                                foreach ($variations as $key => $value) {
                                                                    echo htmlspecialchars($key) . ': ' . htmlspecialchars($value) . ' ';
                                                                }
                                                            }
                                                        ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="item-pricing">
                                                <div class="item-price"><?php echo formatCurrency($item['price']); ?></div>
                                                <div class="item-quantity">Số lượng: <?php echo $item['quantity']; ?></div>
                                                <div class="item-total"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Order Summary -->
                                <div class="order-summary">
                                    <div class="summary-row">
                                        <span class="summary-label">Tạm tính:</span>
                                        <span class="summary-value"><?php echo formatCurrency($subtotal); ?></span>
                                    </div>
                                    
                                    <?php if ($total_tax > 0): ?>
                                    <div class="summary-row">
                                        <span class="summary-label">Thuế:</span>
                                        <span class="summary-value"><?php echo formatCurrency($total_tax); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="summary-row">
                                        <span class="summary-label">Phí vận chuyển:</span>
                                        <span class="summary-value"><?php echo formatCurrency($total_shipping); ?></span>
                                    </div>
                                    
                                    <?php if ($order['coupon_discount'] > 0): ?>
                                    <div class="summary-row">
                                        <span class="summary-label">Giảm giá:</span>
                                        <span class="summary-value">-<?php echo formatCurrency($order['coupon_discount']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="summary-row">
                                        <span class="summary-label">Tổng cộng:</span>
                                        <span class="summary-value"><?php echo formatCurrency($order['grand_total']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sidebar Info -->
                    <div class="order-sidebar">
                        <!-- Customer Info -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Thông tin khách hàng</h3>
                            </div>
                            <div class="customer-card">
                                <div class="customer-avatar">
                                    <?php echo strtoupper(substr($order['customer_name'] ?? 'G', 0, 1)); ?>
                                </div>
                                
                                <div class="customer-name"><?php echo htmlspecialchars($order['customer_name'] ?? 'Khách vãng lai'); ?></div>
                                
                                <?php if ($order['customer_email']): ?>
                                    <div class="customer-detail">
                                        <span>📧</span>
                                        <span><?php echo htmlspecialchars($order['customer_email']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($order['customer_phone']): ?>
                                    <div class="customer-detail">
                                        <span>📞</span>
                                        <span><?php echo htmlspecialchars($order['customer_phone']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="customer-detail">
                                    <span>🎯</span>
                                    <span>Khách hàng <?php echo $order['user_id'] ? 'thành viên' : 'vãng lai'; ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Shipping Address -->
                        <?php if ($shipping_info): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Địa chỉ giao hàng</h3>
                            </div>
                            <div class="address-card">
                                <div class="address-text">
                                    <?php if (is_array($shipping_info)): ?>
                                        <?php echo htmlspecialchars($shipping_info['address'] ?? ''); ?><br>
                                        <?php echo htmlspecialchars($shipping_info['city'] ?? ''); ?><br>
                                        <?php echo htmlspecialchars($shipping_info['state'] ?? ''); ?><br>
                                        <?php echo htmlspecialchars($shipping_info['country'] ?? 'Vietnam'); ?><br>
                                        <?php if (!empty($shipping_info['postal_code'])): ?>
                                            <?php echo htmlspecialchars($shipping_info['postal_code']); ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Payment & Delivery Info -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Thanh toán & Giao hàng</h3>
                            </div>
                            <div class="card-content">
                                <div class="order-field">
                                    <div class="order-label">Hình thức thanh toán</div>
                                    <div class="order-value"><?php echo ucfirst($order['payment_type'] ?? 'Chưa xác định'); ?></div>
                                </div>
                                
                                <div class="order-field">
                                    <div class="order-label">Hình thức vận chuyển</div>
                                    <div class="order-value"><?php echo ucfirst($order['shipping_type'] ?? 'Standard'); ?></div>
                                </div>
                                
                                <?php if ($order['pickup_point_id']): ?>
                                <div class="order-field">
                                    <div class="order-label">Điểm nhận hàng</div>
                                    <div class="order-value"><?php echo htmlspecialchars($order['pickup_point_id']); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Order Timeline -->
                <div class="timeline-container">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Lịch sử đơn hàng</h2>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="showModal('add-note-modal')">
                                📝 Thêm ghi chú
                            </button>
                        </div>
                        <div class="card-content">
                            <div class="timeline">
                                <?php foreach ($timeline as $item): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-icon"><?php echo $item['icon']; ?></div>
                                        <div class="timeline-content">
                                            <div class="timeline-title"><?php echo htmlspecialchars($item['title']); ?></div>
                                            <div class="timeline-description"><?php echo htmlspecialchars($item['description']); ?></div>
                                            <div class="timeline-date">
                                                <?php echo date('d/m/Y H:i', strtotime($item['date'])); ?>
                                                <?php if (isset($item['user'])): ?>
                                                    - bởi <?php echo htmlspecialchars($item['user']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Update Order Modal -->
    <div class="modal" id="update-order-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Cập nhật trạng thái đơn hàng #<?php echo $order['id']; ?></h3>
                <button type="button" class="modal-close" onclick="closeModal('update-order-modal')">×</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_token']; ?>">
                <input type="hidden" name="action" value="update_status">
                
                <div class="form-group">
                    <label class="form-label">Trạng thái giao hàng</label>
                    <select name="delivery_status" class="form-select">
                        <option value="pending" <?php echo $order['delivery_status'] === 'pending' ? 'selected' : ''; ?>>Chờ xử lý</option>
                        <option value="confirmed" <?php echo $order['delivery_status'] === 'confirmed' ? 'selected' : ''; ?>>Đã xác nhận</option>
                        <option value="processing" <?php echo $order['delivery_status'] === 'processing' ? 'selected' : ''; ?>>Đang xử lý</option>
                        <option value="shipped" <?php echo $order['delivery_status'] === 'shipped' ? 'selected' : ''; ?>>Đã gửi hàng</option>
                        <option value="delivered" <?php echo $order['delivery_status'] === 'delivered' ? 'selected' : ''; ?>>Đã giao hàng</option>
                        <option value="cancelled" <?php echo $order['delivery_status'] === 'cancelled' ? 'selected' : ''; ?>>Đã hủy</option>
                        <option value="returned" <?php echo $order['delivery_status'] === 'returned' ? 'selected' : ''; ?>>Trả hàng</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Trạng thái thanh toán</label>
                    <select name="payment_status" class="form-select">
                        <option value="unpaid" <?php echo $order['payment_status'] === 'unpaid' ? 'selected' : ''; ?>>Chưa thanh toán</option>
                        <option value="pending" <?php echo $order['payment_status'] === 'pending' ? 'selected' : ''; ?>>Chờ thanh toán</option>
                        <option value="paid" <?php echo $order['payment_status'] === 'paid' ? 'selected' : ''; ?>>Đã thanh toán</option>
                        <option value="refunded" <?php echo $order['payment_status'] === 'refunded' ? 'selected' : ''; ?>>Đã hoàn tiền</option>
                        <option value="failed" <?php echo $order['payment_status'] === 'failed' ? 'selected' : ''; ?>>Thanh toán thất bại</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Mã vận đơn</label>
                    <input 
                        type="text" 
                        name="tracking_code" 
                        class="form-input" 
                        placeholder="Nhập mã vận đơn..."
                        value="<?php echo htmlspecialchars($order['tracking_code'] ?? ''); ?>"
                    >
                </div>
                
                <div class="form-group">
                    <label class="form-label">Ghi chú admin</label>
                    <textarea 
                        name="admin_notes" 
                        class="form-textarea" 
                        placeholder="Thêm ghi chú về việc cập nhật này..."
                    ></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('update-order-modal')">
                        Hủy
                    </button>
                    <button type="submit" class="btn btn-primary">
                        💾 Cập nhật đơn hàng
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Add Note Modal -->
    <div class="modal" id="add-note-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Thêm ghi chú</h3>
                <button type="button" class="modal-close" onclick="closeModal('add-note-modal')">×</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_token']; ?>">
                <input type="hidden" name="action" value="add_note">
                
                <div class="form-group">
                    <label class="form-label">Nội dung ghi chú</label>
                    <textarea 
                        name="note" 
                        class="form-textarea" 
                        placeholder="Nhập ghi chú về đơn hàng này..."
                        required
                    ></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('add-note-modal')">
                        Hủy
                    </button>
                    <button type="submit" class="btn btn-primary">
                        📝 Thêm ghi chú
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Sidebar toggle
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
        
        // Modal functions
        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }
        
        // Close modal on backdrop click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });
        
        // Auto-refresh order status every 60 seconds
        let autoRefreshInterval;
        
        function startAutoRefresh() {
            autoRefreshInterval = setInterval(() => {
                if (!document.querySelector('.modal.show')) {
                    // Check for order status updates
                    fetch(`order-status.php?id=<?php echo $order['id']; ?>`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.delivery_status !== '<?php echo $order['delivery_status']; ?>' || 
                                data.payment_status !== '<?php echo $order['payment_status']; ?>') {
                                // Reload page if status changed
                                location.reload();
                            }
                        })
                        .catch(error => {
                            console.error('Auto-refresh error:', error);
                        });
                }
            }, 60000); // 60 seconds
        }
        
        function stopAutoRefresh() {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
            }
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close modals
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    closeModal(modal.id);
                });
            }
            
            // Ctrl/Cmd + P to print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            
            // Ctrl/Cmd + U to update status
            if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
                e.preventDefault();
                showModal('update-order-modal');
            }
            
            // Ctrl/Cmd + N to add note
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                showModal('add-note-modal');
            }
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('📦 Order Details - Initializing...');
            
            // Start auto-refresh
            startAutoRefresh();
            
            // Add loading states to forms
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<div class="loading"></div> Đang xử lý...';
                    }
                });
            });
            
            // Highlight current order status
            const statusBadges = document.querySelectorAll('.status-badge');
            statusBadges.forEach(badge => {
                badge.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.05)';
                });
                badge.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });
            
            console.log('✅ Order Details - Ready!');
            console.log('🔄 Auto-refresh enabled | ⌨️ Keyboard shortcuts active | 🖨️ Print ready');
        });
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            stopAutoRefresh();
        });
        
        // Page visibility API - pause auto-refresh when tab is hidden
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopAutoRefresh();
            } else {
                startAutoRefresh();
            }
        });
        
        // Print optimization
        window.addEventListener('beforeprint', function() {
            // Hide elements that shouldn't be printed
            document.querySelectorAll('.btn, .modal').forEach(el => {
                el.style.display = 'none';
            });
        });
        
        window.addEventListener('afterprint', function() {
            // Restore elements after printing
            document.querySelectorAll('.btn, .modal').forEach(el => {
                el.style.display = '';
            });
        });
        
        // Enhanced order item interactions
        document.querySelectorAll('.order-item').forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.borderColor = 'var(--primary)';
                this.style.boxShadow = 'var(--shadow-md)';
            });
            
            item.addEventListener('mouseleave', function() {
                this.style.borderColor = 'var(--border-light)';
                this.style.boxShadow = 'var(--shadow-xs)';
            });
        });
        
        // Error handling
        window.addEventListener('error', function(e) {
            console.error('JavaScript error:', e.error);
        });
        
        // Copy tracking code functionality
        function copyTrackingCode() {
            const trackingCode = '<?php echo $order['tracking_code'] ?? ''; ?>';
            if (trackingCode) {
                navigator.clipboard.writeText(trackingCode).then(() => {
                    // Show success message
                    const message = document.createElement('div');
                    message.className = 'message success';
                    message.innerHTML = '<span>✅</span><span>Đã copy mã vận đơn!</span>';
                    document.querySelector('.content').insertBefore(message, document.querySelector('.order-header'));
                    
                    setTimeout(() => {
                        message.remove();
                    }, 3000);
                });
            }
        }
        
        // Add click handler for tracking code if it exists
        <?php if ($order['tracking_code']): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const trackingElements = document.querySelectorAll('[data-tracking-code]');
            trackingElements.forEach(el => {
                el.style.cursor = 'pointer';
                el.title = 'Click để copy mã vận đơn';
                el.addEventListener('click', copyTrackingCode);
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>