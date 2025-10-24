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
                $user_id = (int)$_POST['user_id'];
                
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
                $user_id = (int)$_POST['user_id'];
                $stmt = $db->prepare("UPDATE users SET banned = 0 WHERE id = ?");
                $stmt->execute([$user_id]);
                echo json_encode(['success' => true, 'message' => 'Đã bỏ cấm người dùng thành công']);
                break;
                
            case 'delete_user':
                $user_id = (int)$_POST['user_id'];
                
                // Don't allow deleting yourself
                if ($user_id == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Không thể xóa tài khoản của chính mình']);
                    break;
                }
                
                // Begin transaction
                $db->beginTransaction();
                
                try {
                    // Check if user has orders
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    $has_orders = $stmt->fetch()['count'] > 0;
                    
                    if ($has_orders) {
                        // Soft delete - anonymize user data but keep records
                        $stmt = $db->prepare("
                            UPDATE users 
                            SET name = CONCAT('Deleted User ', id), 
                                email = CONCAT('deleted_', id, '@example.com'), 
                                phone = NULL, 
                                banned = 1, 
                                avatar = NULL, 
                                avatar_original = NULL
                            WHERE id = ?
                        ");
                        $stmt->execute([$user_id]);
                        
                        // Delete sensitive user data
                        $stmt = $db->prepare("DELETE FROM addresses WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        
                        // Remove from wishlist
                        $stmt = $db->prepare("DELETE FROM wishlists WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        
                    } else {
                        // Hard delete - complete removal
                        // Delete related records first
                        $tables = [
                            'addresses', 'carts', 'wishlists', 'club_points', 
                            'wallets', 'customer_package_payments', 'affiliate_logs',
                            'affiliate_users', 'conversations', 'reviews'
                        ];
                        
                        foreach ($tables as $table) {
                            $stmt = $db->prepare("DELETE FROM $table WHERE user_id = ?");
                            $stmt->execute([$user_id]);
                        }
                        
                        // Delete user
                        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                    }
                    
                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'Đã xóa người dùng thành công']);
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                break;
                
            case 'get_user':
                $user_id = (int)$_POST['user_id'];
                
                $stmt = $db->prepare("
                    SELECT u.*, 
                           COALESCE(cu.id, 0) as is_affiliate,
                           COALESCE(cu.balance, 0) as affiliate_balance,
                           COALESCE(s.id, 0) as is_seller
                    FROM users u
                    LEFT JOIN affiliate_users cu ON u.id = cu.user_id
                    LEFT JOIN sellers s ON u.id = s.user_id
                    WHERE u.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    echo json_encode(['success' => false, 'message' => 'Không tìm thấy người dùng']);
                    break;
                }
                
                echo json_encode(['success' => true, 'user' => $user]);
                break;
                
            case 'update_user':
                $user_id = (int)$_POST['user_id'];
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
                
            case 'bulk_ban':
                $user_ids = json_decode($_POST['user_ids'], true);
                if (is_array($user_ids) && !empty($user_ids)) {
                    // Remove current admin from the list
                    $user_ids = array_filter($user_ids, function($id) {
                        return $id != $_SESSION['user_id'];
                    });
                    
                    if (!empty($user_ids)) {
                        $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
                        $stmt = $db->prepare("UPDATE users SET banned = 1 WHERE id IN ($placeholders)");
                        $stmt->execute($user_ids);
                        echo json_encode(['success' => true, 'message' => 'Đã cấm ' . count($user_ids) . ' người dùng']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Không có người dùng nào để cấm']);
                    }
                }
                break;
                
            case 'bulk_unban':
                $user_ids = json_decode($_POST['user_ids'], true);
                if (is_array($user_ids) && !empty($user_ids)) {
                    $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
                    $stmt = $db->prepare("UPDATE users SET banned = 0 WHERE id IN ($placeholders)");
                    $stmt->execute($user_ids);
                    echo json_encode(['success' => true, 'message' => 'Đã bỏ cấm ' . count($user_ids) . ' người dùng']);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("Users action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Pagination and filtering
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$search = $_GET['search'] ?? '';
$user_type_filter = $_GET['user_type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$where_conditions = ['1=1']; // Start with a condition that's always true
$params = [];

if (!empty($search)) {
    $where_conditions[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.id = ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = $search;
}

if (!empty($user_type_filter)) {
    $where_conditions[] = 'u.user_type = ?';
    $params[] = $user_type_filter;
}

if (!empty($status_filter)) {
    switch ($status_filter) {
        case 'active':
            $where_conditions[] = 'u.banned = 0';
            break;
        case 'banned':
            $where_conditions[] = 'u.banned = 1';
            break;
        case 'new':
            // Users registered in the last 30 days
            $where_conditions[] = 'u.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
            break;
        case 'has_orders':
            $where_conditions[] = 'EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)';
            break;
        case 'no_orders':
            $where_conditions[] = 'NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)';
            break;
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Valid sort columns
$valid_sorts = ['u.id', 'u.name', 'u.email', 'u.user_type', 'u.created_at', 'u.balance', 'order_count'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'u.created_at';
}

$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Get users with pagination
$users = [];
$total_users = 0;

try {
    // Count total users
    $count_sql = "
        SELECT COUNT(*) as total
        FROM users u
        WHERE $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_users = $stmt->fetch()['total'];
    
    // Get users
    $sql = "
        SELECT u.*, 
               (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) as order_count,
               (SELECT SUM(o.grand_total) FROM orders o WHERE o.user_id = u.id) as total_spent,
               COALESCE(s.id, 0) as is_seller,
               COALESCE(cu.id, 0) as is_affiliate
        FROM users u
        LEFT JOIN sellers s ON u.id = s.user_id
        LEFT JOIN affiliate_users cu ON u.id = cu.user_id
        WHERE $where_clause
        ORDER BY $sort $order
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Users fetch error: " . $e->getMessage());
    $users = [];
}

// Calculate pagination
$total_pages = ceil($total_users / $per_page);
$start_item = $offset + 1;
$end_item = min($offset + $per_page, $total_users);

// User statistics
$stats = [];
try {
    // Total users
    $stmt = $db->query("SELECT COUNT(*) as count FROM users");
    $stats['total'] = $stmt->fetch()['count'];
    
    // Customer users
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'customer'");
    $stats['customers'] = $stmt->fetch()['count'];
    
    // Seller users
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'seller'");
    $stats['sellers'] = $stmt->fetch()['count'];
    
    // Admin/staff users
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type IN ('admin', 'staff')");
    $stats['admin_staff'] = $stmt->fetch()['count'];
    
    // Banned users
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE banned = 1");
    $stats['banned'] = $stmt->fetch()['count'];
    
    // New users in the last 30 days
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stats['new'] = $stmt->fetch()['count'];
    
    // Users with orders
    $stmt = $db->query("
        SELECT COUNT(DISTINCT u.id) as count 
        FROM users u 
        INNER JOIN orders o ON u.id = o.user_id
    ");
    $stats['with_orders'] = $stmt->fetch()['count'];
    
} catch (PDOException $e) {
    error_log("User stats error: " . $e->getMessage());
    $stats = ['total' => 0, 'customers' => 0, 'sellers' => 0, 'admin_staff' => 0, 'banned' => 0, 'new' => 0, 'with_orders' => 0];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý người dùng - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý người dùng - Admin <?php echo htmlspecialchars($site_name); ?>">
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
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
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
        
        /* Toolbar */
        .toolbar {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            padding: var(--space-5);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            margin-bottom: var(--space-6);
        }
        
        .toolbar-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-4);
            margin-bottom: var(--space-4);
        }
        
        .toolbar-row:last-child {
            margin-bottom: 0;
        }
        
        .toolbar-left {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            flex: 1;
        }
        
        .toolbar-right {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .search-box {
            position: relative;
            flex: 1;
            max-width: 400px;
        }
        
        .search-input {
            width: 100%;
            padding: var(--space-3) var(--space-4) var(--space-3) var(--space-10);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            background: var(--gray-50);
            transition: var(--transition-normal);
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: var(--space-3);
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-tertiary);
            font-size: var(--text-base);
        }
        
        .filter-select {
            padding: var(--space-3) var(--space-4);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            background: var(--surface);
            color: var(--text-primary);
            min-width: 120px;
            cursor: pointer;
            transition: var(--transition-normal);
        }
        
        .filter-select:focus {
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
        
        /* Table */
        .table-container {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            overflow: hidden;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: var(--text-sm);
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
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .table td {
            padding: var(--space-4) var(--space-5);
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
        }
        
        .table tr:hover {
            background: var(--gray-50);
        }
        
        .table th.sortable {
            cursor: pointer;
            user-select: none;
            position: relative;
        }
        
        .table th.sortable:hover {
            background: var(--gray-100);
        }
        
        .table th.sorted::after {
            content: '';
            position: absolute;
            right: var(--space-3);
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 0;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
        }
        
        .table th.sorted.asc::after {
            border-bottom: 6px solid var(--text-secondary);
        }
        
        .table th.sorted.desc::after {
            border-top: 6px solid var(--text-secondary);
        }
        
        /* User avatar */
        .user-avatar-cell {
            width: 40px;
            height: 40px;
            border-radius: var(--rounded-full);
            object-fit: cover;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: var(--font-bold);
            font-size: var(--text-sm);
        }
        
        /* User info cell */
        .user-info-cell {
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }
        
        .user-details {
            flex: 1;
        }
        
        .user-name {
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            margin-bottom: var(--space-1);
        }
        
        .user-id {
            font-family: monospace;
            background: var(--gray-100);
            padding: 2px 6px;
            border-radius: var(--rounded);
            font-size: var(--text-xs);
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
        
        .status-badge.admin {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .status-badge.seller {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .status-badge.customer {
            background: rgba(107, 114, 128, 0.1);
            color: #374151;
        }
        
        .status-badge.staff {
            background: rgba(139, 92, 246, 0.1);
            color: #5b21b6;
        }
        
        /* Action buttons */
        .action-buttons {
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .action-btn {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: var(--rounded);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition-normal);
            font-size: var(--text-sm);
        }
        
        .action-btn.view {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .action-btn.view:hover {
            background: rgba(59, 130, 246, 0.2);
            transform: scale(1.1);
        }
        
        .action-btn.edit {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .action-btn.edit:hover {
            background: rgba(16, 185, 129, 0.2);
            transform: scale(1.1);
        }
        
        .action-btn.ban {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        .action-btn.ban:hover {
            background: rgba(239, 68, 68, 0.2);
            transform: scale(1.1);
        }
        
        .action-btn.unban {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .action-btn.unban:hover {
            background: rgba(16, 185, 129, 0.2);
            transform: scale(1.1);
        }
        
        .action-btn.delete {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        .action-btn.delete:hover {
            background: rgba(239, 68, 68, 0.2);
            transform: scale(1.1);
        }
        
        /* Checkbox */
        .checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }
        
        /* Bulk actions */
        .bulk-actions {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: var(--space-3) var(--space-4);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            margin-bottom: var(--space-4);
            opacity: 0;
            transform: translateY(-10px);
            transition: var(--transition-normal);
        }
        
        .bulk-actions.show {
            opacity: 1;
            transform: translateY(0);
        }
        
        .bulk-count {
            font-size: var(--text-sm);
            color: var(--text-secondary);
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: between;
            gap: var(--space-4);
            margin-top: var(--space-6);
            padding: var(--space-5);
            background: var(--surface);
            border-radius: var(--rounded-xl);
            border: 1px solid var(--border-light);
        }
        
        .pagination-info {
            font-size: var(--text-sm);
            color: var(--text-secondary);
        }
        
        .pagination-nav {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            margin-left: auto;
        }
        
        .pagination-btn {
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--border);
            border-radius: var(--rounded);
            background: var(--surface);
            color: var(--text-primary);
            text-decoration: none;
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            transition: var(--transition-normal);
            min-width: 40px;
            text-align: center;
        }
        
        .pagination-btn:hover:not(.disabled) {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }
        
        .pagination-btn.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }
        
        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
        
        /* Form styles */
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .content {
                padding: var(--space-4);
            }
            
            .toolbar-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .toolbar-left,
            .toolbar-right {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                max-width: none;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            .table {
                min-width: 900px;
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
                            <span>Người dùng</span>
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
                    <h1 class="page-title">Quản lý người dùng</h1>
                    <p class="page-subtitle">Quản lý tất cả người dùng trên hệ thống</p>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Tổng người dùng</div>
                            <div class="stat-icon">👥</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    </div>
                    
                    <div class="stat-card green">
                        <div class="stat-header">
                            <div class="stat-title">Khách hàng</div>
                            <div class="stat-icon">🧑</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['customers']); ?></div>
                    </div>
                    
                    <div class="stat-card blue">
                        <div class="stat-header">
                            <div class="stat-title">Người bán</div>
                            <div class="stat-icon">🏪</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['sellers']); ?></div>
                    </div>
                    
                    <div class="stat-card purple">
                        <div class="stat-header">
                            <div class="stat-title">Admin & Nhân viên</div>
                            <div class="stat-icon">👨‍💼</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['admin_staff']); ?></div>
                    </div>
                    
                    <div class="stat-card orange">
                        <div class="stat-header">
                            <div class="stat-title">Người dùng mới</div>
                            <div class="stat-icon">🆕</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['new']); ?></div>
                    </div>
                    
                    <div class="stat-card green">
                        <div class="stat-header">
                            <div class="stat-title">Đã mua hàng</div>
                            <div class="stat-icon">🛒</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['with_orders']); ?></div>
                    </div>
                    
                    <div class="stat-card orange">
                        <div class="stat-header">
                            <div class="stat-title">Bị cấm</div>
                            <div class="stat-icon">🚫</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['banned']); ?></div>
                    </div>
                </div>
                
                <!-- Toolbar -->
                <div class="toolbar">
                    <div class="toolbar-row">
                        <div class="toolbar-left">
                            <div class="search-box">
                                <span class="search-icon">🔍</span>
                                <input 
                                    type="search" 
                                    class="search-input" 
                                    placeholder="Tìm kiếm người dùng..."
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    id="search-input"
                                >
                            </div>
                        </div>
                        <div class="toolbar-right">
                            <a href="add-user.php" class="btn btn-primary">
                                <span>➕</span>
                                <span>Thêm người dùng</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="toolbar-row">
                        <div class="toolbar-left">
                            <select class="filter-select" id="user-type-filter">
                                <option value="">Tất cả loại người dùng</option>
                                <option value="customer" <?php echo $user_type_filter === 'customer' ? 'selected' : ''; ?>>Khách hàng</option>
                                <option value="seller" <?php echo $user_type_filter === 'seller' ? 'selected' : ''; ?>>Người bán</option>
                                <option value="admin" <?php echo $user_type_filter === 'admin' ? 'selected' : ''; ?>>Quản trị viên</option>
                                <option value="staff" <?php echo $user_type_filter === 'staff' ? 'selected' : ''; ?>>Nhân viên</option>
                            </select>
                            
                            <select class="filter-select" id="status-filter">
                                <option value="">Tất cả trạng thái</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                                <option value="banned" <?php echo $status_filter === 'banned' ? 'selected' : ''; ?>>Bị cấm</option>
                                <option value="new" <?php echo $status_filter === 'new' ? 'selected' : ''; ?>>Người dùng mới</option>
                                <option value="has_orders" <?php echo $status_filter === 'has_orders' ? 'selected' : ''; ?>>Đã mua hàng</option>
                                <option value="no_orders" <?php echo $status_filter === 'no_orders' ? 'selected' : ''; ?>>Chưa mua hàng</option>
                            </select>
                        </div>
                        <div class="toolbar-right">
                            <button class="btn btn-secondary" onclick="exportUsers()">
                                <span>📤</span>
                                <span>Xuất file</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Bulk Actions -->
                <div class="bulk-actions" id="bulk-actions">
                    <span class="bulk-count" id="bulk-count">0 người dùng được chọn</span>
                    <button class="btn btn-warning btn-sm" onclick="bulkAction('ban')">
                        <span>🚫</span>
                        <span>Cấm</span>
                    </button>
                    <button class="btn btn-success btn-sm" onclick="bulkAction('unban')">
                        <span>✅</span>
                        <span>Bỏ cấm</span>
                    </button>
                </div>
                
                <!-- Users Table -->
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" class="checkbox" id="select-all">
                                </th>
                                <th class="sortable <?php echo $sort === 'u.id' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="u.id">
                                    ID
                                </th>
                                <th class="sortable <?php echo $sort === 'u.name' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="u.name">
                                    Tên người dùng
                                </th>
                                <th class="sortable <?php echo $sort === 'u.email' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="u.email">
                                    Email
                                </th>
                                <th>
                                    Số điện thoại
                                </th>
                                <th class="sortable <?php echo $sort === 'u.user_type' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="u.user_type">
                                    Loại
                                </th>
                                <th class="sortable <?php echo $sort === 'order_count' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="order_count">
                                    Đơn hàng
                                </th>
                                <th class="sortable <?php echo $sort === 'u.balance' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="u.balance">
                                    Số dư
                                </th>
                                <th>
                                    Trạng thái
                                </th>
                                <th class="sortable <?php echo $sort === 'u.created_at' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="u.created_at">
                                    Ngày đăng ký
                                </th>
                                <th>
                                    Thao tác
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr data-user-id="<?php echo $user['id']; ?>">
                                    <td>
                                        <input type="checkbox" class="checkbox user-checkbox" value="<?php echo $user['id']; ?>" <?php echo $user['id'] == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                    </td>
                                    <td>
                                        <span class="user-id">#<?php echo $user['id']; ?></span>
                                    </td>
                                    <td>
                                        <div class="user-info-cell">
                                            <div class="user-avatar-cell">
                                                <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                                            </div>
                                            <div class="user-details">
                                                <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                                <?php if ($user['is_seller']): ?>
                                                    <small style="color: var(--text-tertiary);">🏪 Người bán</small>
                                                <?php endif; ?>
                                                <?php if ($user['is_affiliate']): ?>
                                                    <small style="color: var(--text-tertiary);">🔗 Affiliate</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($user['email']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?>
                                    </td>
                                    <td>
                                        <?php
                                        switch ($user['user_type']) {
                                            case 'admin':
                                                echo '<span class="status-badge admin">Admin</span>';
                                                break;
                                            case 'staff':
                                                echo '<span class="status-badge staff">Nhân viên</span>';
                                                break;
                                            case 'seller':
                                                echo '<span class="status-badge seller">Người bán</span>';
                                                break;
                                            default:
                                                echo '<span class="status-badge customer">Khách hàng</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format($user['order_count'] ?? 0); ?></strong>
                                        <?php if (!empty($user['total_spent'])): ?>
                                            <br>
                                            <small style="color: var(--text-tertiary);">
                                                <?php echo formatCurrency($user['total_spent']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo formatCurrency($user['balance'] ?? 0); ?>
                                    </td>
                                    <td>
                                        <?php if ($user['banned']): ?>
                                            <span class="status-badge banned">Bị cấm</span>
                                        <?php else: ?>
                                            <span class="status-badge active">Hoạt động</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo formatDate($user['created_at'], 'd/m/Y'); ?>
                                        <br>
                                        <small style="color: var(--text-tertiary);">
                                            <?php echo formatDate($user['created_at'], 'H:i'); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn view" onclick="viewUser(<?php echo $user['id']; ?>)" title="Xem chi tiết">
                                                👁️
                                            </button>
                                            
                                            <button class="action-btn edit" onclick="editUser(<?php echo $user['id']; ?>)" title="Chỉnh sửa">
                                                ✏️
                                            </button>
                                            
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <?php if ($user['banned']): ?>
                                                    <button class="action-btn unban" onclick="unbanUser(<?php echo $user['id']; ?>)" title="Bỏ cấm">
                                                        ✅
                                                    </button>
                                                <?php else: ?>
                                                    <button class="action-btn ban" onclick="banUser(<?php echo $user['id']; ?>)" title="Cấm">
                                                        🚫
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button class="action-btn delete" onclick="deleteUser(<?php echo $user['id']; ?>)" title="Xóa">
                                                    🗑️
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if (count($users) === 0): ?>
                                <tr>
                                    <td colspan="11" style="text-align: center; padding: var(--space-8);">
                                        <div style="font-size: var(--text-xl); margin-bottom: var(--space-4); color: var(--text-secondary);">
                                            Không tìm thấy người dùng
                                        </div>
                                        <a href="?<?php echo http_build_query(array_filter($_GET, function($k) { return !in_array($k, ['search', 'user_type', 'status']); }, ARRAY_FILTER_USE_KEY)); ?>" class="btn btn-secondary">
                                            <span>🔄</span>
                                            <span>Đặt lại bộ lọc</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="pagination">
                    <div class="pagination-info">
                        Hiển thị <?php echo number_format($start_item); ?>-<?php echo number_format($end_item); ?> trong tổng số <?php echo number_format($total_users); ?> người dùng
                    </div>
                    
                    <div class="pagination-nav">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="pagination-btn">‹‹</a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">‹</a>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">›</a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="pagination-btn">››</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Edit User Modal -->
    <div class="modal-backdrop" id="edit-user-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modal-title">Chỉnh sửa người dùng</h2>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            <div class="modal-body">
                <form id="edit-user-form">
                    <input type="hidden" id="user-id" value="">
                    
                    <div class="form-group">
                        <label class="form-label" for="user-name">Tên người dùng <span style="color: red">*</span></label>
                        <input type="text" class="form-control" id="user-name" placeholder="Nhập tên người dùng" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="user-email">Email <span style="color: red">*</span></label>
                        <input type="email" class="form-control" id="user-email" placeholder="Nhập email" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="user-phone">Số điện thoại</label>
                        <input type="text" class="form-control" id="user-phone" placeholder="Nhập số điện thoại">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="user-type">Loại người dùng <span style="color: red">*</span></label>
                        <select class="form-control" id="user-type" required>
                            <option value="customer">Khách hàng</option>
                            <option value="seller">Người bán</option>
                            <option value="staff">Nhân viên</option>
                            <option value="admin">Quản trị viên</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Hủy</button>
                <button class="btn btn-primary" onclick="saveUser()" id="save-button">Cập nhật</button>
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
        
        // Search functionality
        const searchInput = document.getElementById('search-input');
        let searchTimeout;
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                updateFilters();
            }, 500);
        });
        
        // Filter functionality
        document.getElementById('user-type-filter').addEventListener('change', updateFilters);
        document.getElementById('status-filter').addEventListener('change', updateFilters);
        
        function updateFilters() {
            const params = new URLSearchParams();
            
            const search = searchInput.value.trim();
            if (search) params.set('search', search);
            
            const userType = document.getElementById('user-type-filter').value;
            if (userType) params.set('user_type', userType);
            
            const status = document.getElementById('status-filter').value;
            if (status) params.set('status', status);
            
            // Preserve current sort
            const urlParams = new URLSearchParams(window.location.search);
            const currentSort = urlParams.get('sort');
            const currentOrder = urlParams.get('order');
            
            if (currentSort) params.set('sort', currentSort);
            if (currentOrder) params.set('order', currentOrder);
            
            params.set('page', '1'); // Reset to first page
            
            window.location.search = params.toString();
        }
        
        // Sorting functionality
        document.querySelectorAll('.sortable').forEach(th => {
            th.addEventListener('click', function() {
                const sortBy = this.dataset.sort;
                const urlParams = new URLSearchParams(window.location.search);
                const currentSort = urlParams.get('sort');
                const currentOrder = urlParams.get('order');
                
                let newOrder = 'DESC';
                if (currentSort === sortBy && currentOrder === 'DESC') {
                    newOrder = 'ASC';
                }
                
                urlParams.set('sort', sortBy);
                urlParams.set('order', newOrder);
                urlParams.set('page', '1');
                
                window.location.search = urlParams.toString();
            });
        });
        
        // Select all functionality
        const selectAllCheckbox = document.getElementById('select-all');
        const userCheckboxes = document.querySelectorAll('.user-checkbox:not([disabled])');
        const bulkActions = document.getElementById('bulk-actions');
        const bulkCount = document.getElementById('bulk-count');
        
        selectAllCheckbox.addEventListener('change', function() {
            userCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });
        
        userCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateBulkActions();
                
                // Update select all checkbox
                const checkedCount = document.querySelectorAll('.user-checkbox:not([disabled]):checked').length;
                selectAllCheckbox.checked = checkedCount === userCheckboxes.length;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < userCheckboxes.length;
            });
        });
        
        function updateBulkActions() {
            const selectedUsers = document.querySelectorAll('.user-checkbox:not([disabled]):checked');
            const count = selectedUsers.length;
            
            if (count > 0) {
                bulkActions.classList.add('show');
                bulkCount.textContent = `${count} người dùng được chọn`;
            } else {
                bulkActions.classList.remove('show');
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
        
        // User actions
        function viewUser(userId) {
            window.location.href = `user-edit.php?id=${userId}`;
        }
        
        async function editUser(userId) {
            const result = await makeRequest('get_user', { user_id: userId });
            
            if (result) {
                const user = result.user;
                
                document.getElementById('user-id').value = user.id;
                document.getElementById('user-name').value = user.name;
                document.getElementById('user-email').value = user.email;
                document.getElementById('user-phone').value = user.phone || '';
                document.getElementById('user-type').value = user.user_type;
                
                document.getElementById('modal-title').textContent = 'Chỉnh sửa người dùng';
                document.getElementById('save-button').textContent = 'Cập nhật';
                document.getElementById('edit-user-modal').classList.add('show');
            }
        }
        
        async function saveUser() {
            const userId = document.getElementById('user-id').value;
            const name = document.getElementById('user-name').value.trim();
            const email = document.getElementById('user-email').value.trim();
            const phone = document.getElementById('user-phone').value.trim();
            const userType = document.getElementById('user-type').value;
            
            // Validation
            if (!name) {
                showNotification('Vui lòng nhập tên người dùng', 'error');
                return;
            }
            
            if (!email || !email.match(/^[\w-\.]+@([\w-]+\.)+[\w-]{2,4}$/)) {
                showNotification('Vui lòng nhập email hợp lệ', 'error');
                return;
            }
            
            // Save user
            const saveButton = document.getElementById('save-button');
            saveButton.disabled = true;
            saveButton.innerHTML = '<span class="loading"></span> Đang xử lý';
            
            const result = await makeRequest('update_user', {
                user_id: userId,
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
                saveButton.disabled = false;
                saveButton.textContent = 'Cập nhật';
            }
        }
        
        function closeModal() {
            document.getElementById('edit-user-modal').classList.remove('show');
        }
        
        // Close modal when clicking on backdrop
        document.getElementById('edit-user-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        async function banUser(userId) {
            if (!confirm('Bạn có chắc chắn muốn cấm người dùng này?')) {
                return;
            }
            
            const result = await makeRequest('ban_user', { user_id: userId });
            if (result) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function unbanUser(userId) {
            if (!confirm('Bạn có chắc chắn muốn bỏ cấm người dùng này?')) {
                return;
            }
            
            const result = await makeRequest('unban_user', { user_id: userId });
            if (result) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function deleteUser(userId) {
            if (!confirm('Bạn có chắc chắn muốn xóa người dùng này? Hành động này có thể không hoàn tác được.')) {
                return;
            }
            
            const result = await makeRequest('delete_user', { user_id: userId });
            if (result) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Bulk actions
        async function bulkAction(action) {
            let userIds = [];
            
            document.querySelectorAll('.user-checkbox:checked').forEach(checkbox => {
                userIds.push(checkbox.value);
            });
            
            if (userIds.length === 0) {
                showNotification('Vui lòng chọn ít nhất một người dùng', 'error');
                return;
            }
            
            let confirmMessage = '';
            switch (action) {
                case 'ban':
                    confirmMessage = `Bạn có chắc chắn muốn cấm ${userIds.length} người dùng đã chọn?`;
                    break;
                case 'unban':
                    confirmMessage = `Bạn có chắc chắn muốn bỏ cấm ${userIds.length} người dùng đã chọn?`;
                    break;
            }
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            let ajaxAction = '';
            
            switch (action) {
                case 'ban':
                    ajaxAction = 'bulk_ban';
                    break;
                case 'unban':
                    ajaxAction = 'bulk_unban';
                    break;
            }
            
            const result = await makeRequest(ajaxAction, { user_ids: JSON.stringify(userIds) });
            if (result) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Export users
        function exportUsers() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', '1');
            window.open('?' + params.toString(), '_blank');
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
            console.log('🚀 Users Management - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Users Management - Ready!');
            console.log('👥 User count:', <?php echo $total_users; ?>);
        });
    </script>
</body>
</html>