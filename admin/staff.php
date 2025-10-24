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
            case 'delete_staff':
                $staff_id = (int)$_POST['staff_id'];
                
                // Get user_id from staff record
                $stmt = $db->prepare("SELECT user_id FROM staff WHERE id = ?");
                $stmt->execute([$staff_id]);
                $staff = $stmt->fetch();
                
                if (!$staff) {
                    echo json_encode(['success' => false, 'message' => 'Nhân viên không tồn tại']);
                    exit;
                }
                
                $user_id = $staff['user_id'];
                
                // Check if deleting own account
                if ($user_id == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Bạn không thể xóa tài khoản của chính mình']);
                    exit;
                }
                
                // Begin transaction
                $db->beginTransaction();
                
                // Delete staff record
                $stmt = $db->prepare("DELETE FROM staff WHERE id = ?");
                $stmt->execute([$staff_id]);
                
                // Update user record (change user_type to customer)
                $stmt = $db->prepare("UPDATE users SET user_type = 'customer' WHERE id = ?");
                $stmt->execute([$user_id]);
                
                // Delete role associations
                $stmt = $db->prepare("DELETE FROM model_has_roles WHERE model_id = ? AND model_type = 'App\\Models\\User'");
                $stmt->execute([$user_id]);
                
                $db->commit();
                
                echo json_encode(['success' => true, 'message' => 'Nhân viên đã được xóa thành công']);
                break;
                
            case 'toggle_status':
                $user_id = (int)$_POST['user_id'];
                
                // Check if toggling own account
                if ($user_id == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Bạn không thể vô hiệu hóa tài khoản của chính mình']);
                    exit;
                }
                
                // Toggle banned status
                $stmt = $db->prepare("UPDATE users SET banned = 1 - banned WHERE id = ? AND user_type = 'admin'");
                $stmt->execute([$user_id]);
                
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật trạng thái nhân viên']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Staff action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Pagination and filtering
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$where_conditions = ["u.user_type = 'admin'"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($role_filter)) {
    $where_conditions[] = 'r.id = ?';
    $params[] = $role_filter;
}

if (!empty($status_filter)) {
    if ($status_filter === 'active') {
        $where_conditions[] = 'u.banned = 0';
    } else {
        $where_conditions[] = 'u.banned = 1';
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Valid sort columns
$valid_sorts = ['id', 'name', 'email', 'created_at', 'updated_at'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'created_at';
}

$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Get staff with pagination
$staff_members = [];
$total_staff = 0;

try {
    // Count total staff
    $count_sql = "
        SELECT COUNT(*) as total
        FROM users u
        LEFT JOIN staff s ON u.id = s.user_id
        LEFT JOIN roles r ON s.role_id = r.id
        WHERE $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_staff = $stmt->fetch()['total'];
    
    // Get staff members
    $sql = "
        SELECT u.*, s.id as staff_id, r.id as role_id, r.name as role_name,
               (SELECT COUNT(*) FROM model_has_permissions WHERE model_id = u.id AND model_type = 'App\\\\Models\\\\User') as permission_count
        FROM users u
        LEFT JOIN staff s ON u.id = s.user_id
        LEFT JOIN model_has_roles mhr ON u.id = mhr.model_id AND mhr.model_type = 'App\\\\Models\\\\User'
        LEFT JOIN roles r ON mhr.role_id = r.id
        WHERE $where_clause
        ORDER BY u.$sort $order
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $staff_members = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Staff fetch error: " . $e->getMessage());
    $staff_members = [];
}

// Calculate pagination
$total_pages = ceil($total_staff / $per_page);
$start_item = $offset + 1;
$end_item = min($offset + $per_page, $total_staff);

// Get roles for filter
$roles = [];
try {
    $stmt = $db->query("SELECT id, name FROM roles ORDER BY name ASC");
    $roles = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Roles fetch error: " . $e->getMessage());
}

// Staff statistics
$stats = [];
try {
    // Total staff
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'admin'");
    $stats['total'] = $stmt->fetch()['count'];
    
    // Active staff
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'admin' AND banned = 0");
    $stats['active'] = $stmt->fetch()['count'];
    
    // Inactive staff
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'admin' AND banned = 1");
    $stats['inactive'] = $stmt->fetch()['count'];
    
    // Staff by role
    $stmt = $db->query("
        SELECT r.name, COUNT(*) as count
        FROM users u
        JOIN model_has_roles mhr ON u.id = mhr.model_id AND mhr.model_type = 'App\\Models\\User'
        JOIN roles r ON mhr.role_id = r.id
        WHERE u.user_type = 'admin'
        GROUP BY r.name
    ");
    $stats['by_role'] = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Staff stats error: " . $e->getMessage());
    $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'by_role' => []];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý nhân viên - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý nhân viên - Admin <?php echo htmlspecialchars($site_name); ?>">
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
        
        /* Staff info */
        .staff-info {
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }
        
        .staff-avatar {
            width: 50px;
            height: 50px;
            background: var(--primary-gradient);
            border-radius: var(--rounded-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: var(--font-bold);
            font-size: var(--text-xl);
        }
        
        .staff-details {
            flex: 1;
        }
        
        .staff-name {
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            margin-bottom: var(--space-1);
        }
        
        .staff-meta {
            font-size: var(--text-xs);
            color: var(--text-secondary);
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
        
        .status-badge.inactive {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        .status-badge.role {
            background: rgba(139, 92, 246, 0.1);
            color: #5b21b6;
        }
        
        /* Role display */
        .role-display {
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .role-pill {
            padding: var(--space-1) var(--space-3);
            border-radius: var(--rounded-full);
            background: rgba(139, 92, 246, 0.1);
            color: #5b21b6;
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
        }
        
        /* Permission count */
        .permission-count {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            font-size: var(--text-xs);
        }
        
        .permission-badge {
            padding: var(--space-1) var(--space-2);
            border-radius: var(--rounded-full);
            background: var(--gray-200);
            color: var(--text-secondary);
            font-weight: var(--font-medium);
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
        
        .action-btn.edit {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .action-btn.edit:hover {
            background: rgba(59, 130, 246, 0.2);
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
        
        .action-btn.permission {
            background: rgba(139, 92, 246, 0.1);
            color: #5b21b6;
        }
        
        .action-btn.permission:hover {
            background: rgba(139, 92, 246, 0.2);
            transform: scale(1.1);
        }
        
        /* Switch toggle */
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
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
            height: 16px;
            width: 16px;
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
            transform: translateX(18px);
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
        
        /* Role list */
        .role-list {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-2);
            margin-top: var(--space-4);
        }
        
        .role-item {
            padding: var(--space-2) var(--space-4);
            border-radius: var(--rounded-lg);
            background: var(--gray-100);
            border: 1px solid var(--border);
            font-size: var(--text-sm);
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .role-item-count {
            background: var(--primary);
            color: var(--white);
            border-radius: var(--rounded-full);
            padding: 2px 8px;
            font-size: var(--text-xs);
            font-weight: var(--font-bold);
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: var(--space-8);
            color: var(--text-tertiary);
        }
        
        .empty-icon {
            font-size: var(--text-4xl);
            margin-bottom: var(--space-4);
        }
        
        .empty-title {
            font-weight: var(--font-medium);
            margin-bottom: var(--space-2);
        }
        
        .empty-description {
            font-size: var(--text-sm);
            margin-bottom: var(--space-6);
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
                min-width: 800px;
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
                        <a href="settings.php" class="nav-link">
                            <span class="nav-icon">⚙️</span>
                            <span class="nav-text">Cài đặt</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="staff.php" class="nav-link active">
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
                            <span>Nhân viên</span>
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
                    <h1 class="page-title">Quản lý nhân viên</h1>
                    <p class="page-subtitle">Quản lý tài khoản nhân viên và phân quyền truy cập hệ thống</p>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Tổng nhân viên</div>
                            <div class="stat-icon">👥</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Đang hoạt động</div>
                            <div class="stat-icon">✅</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['active']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Không hoạt động</div>
                            <div class="stat-icon">❌</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['inactive']); ?></div>
                    </div>
                    
                    <?php if (!empty($stats['by_role'])): ?>
                        <?php foreach ($stats['by_role'] as $role_stat): ?>
                            <div class="stat-card">
                                <div class="stat-header">
                                    <div class="stat-title"><?php echo htmlspecialchars($role_stat['name']); ?></div>
                                    <div class="stat-icon">👨‍💼</div>
                                </div>
                                <div class="stat-value"><?php echo number_format($role_stat['count']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
                                    placeholder="Tìm kiếm nhân viên..."
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    id="search-input"
                                >
                            </div>
                        </div>
                        <div class="toolbar-right">
                            <a href="staff-edit.php" class="btn btn-primary">
                                <span>➕</span>
                                <span>Thêm nhân viên mới</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="toolbar-row">
                        <div class="toolbar-left">
                            <select class="filter-select" id="role-filter">
                                <option value="">Tất cả vai trò</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>" <?php echo $role_filter == $role['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select class="filter-select" id="status-filter">
                                <option value="">Tất cả trạng thái</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Không hoạt động</option>
                            </select>
                        </div>
                        <div class="toolbar-right">
                            <a href="roles.php" class="btn btn-secondary">
                                <span>🔐</span>
                                <span>Quản lý vai trò</span>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Role List (Optional) -->
                <?php if (!empty($roles)): ?>
                    <div class="role-list">
                        <?php foreach ($roles as $role): ?>
                            <div class="role-item">
                                <span><?php echo htmlspecialchars($role['name']); ?></span>
                                <?php
                                // Count staff with this role
                                $role_count = 0;
                                foreach ($stats['by_role'] as $role_stat) {
                                    if ($role_stat['name'] === $role['name']) {
                                        $role_count = $role_stat['count'];
                                        break;
                                    }
                                }
                                ?>
                                <span class="role-item-count"><?php echo $role_count; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Staff Table -->
                <div class="table-container" style="margin-top: var(--space-6);">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="sortable <?php echo $sort === 'id' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="id">
                                    ID
                                </th>
                                <th>Nhân viên</th>
                                <th>Liên hệ</th>
                                <th>Vai trò</th>
                                <th>Quyền hạn</th>
                                <th>Trạng thái</th>
                                <th class="sortable <?php echo $sort === 'created_at' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="created_at">
                                    Ngày tạo
                                </th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staff_members)): ?>
                                <tr>
                                    <td colspan="8" class="empty-state">
                                        <div class="empty-icon">👨‍💼</div>
                                        <div class="empty-title">Chưa có nhân viên nào</div>
                                        <div class="empty-description">Hãy thêm nhân viên đầu tiên của bạn</div>
                                        <a href="staff-edit.php" class="btn btn-primary">
                                            <span>➕</span>
                                            <span>Thêm nhân viên mới</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($staff_members as $staff): ?>
                                    <tr>
                                        <td><?php echo $staff['id']; ?></td>
                                        <td>
                                            <div class="staff-info">
                                                <div class="staff-avatar">
                                                    <?php echo strtoupper(substr($staff['name'] ?? 'A', 0, 2)); ?>
                                                </div>
                                                <div class="staff-details">
                                                    <div class="staff-name"><?php echo htmlspecialchars($staff['name']); ?></div>
                                                    <div class="staff-meta">
                                                        <?php if ($staff['id'] === $_SESSION['user_id']): ?>
                                                            <span class="status-badge active">Đang đăng nhập</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <div><?php echo htmlspecialchars($staff['email']); ?></div>
                                                <?php if (!empty($staff['phone'])): ?>
                                                    <div style="color: var(--text-tertiary); font-size: var(--text-xs);">
                                                        <?php echo htmlspecialchars($staff['phone']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($staff['role_name'])): ?>
                                                <span class="role-pill"><?php echo htmlspecialchars($staff['role_name']); ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--text-tertiary);">Chưa gán vai trò</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="permission-count">
                                                <span class="permission-badge"><?php echo $staff['permission_count']; ?></span>
                                                <span>quyền</span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($staff['id'] !== $_SESSION['user_id']): ?>
                                                <label class="switch">
                                                    <input type="checkbox" onchange="toggleStaffStatus(<?php echo $staff['id']; ?>)" <?php echo $staff['banned'] ? '' : 'checked'; ?>>
                                                    <span class="slider"></span>
                                                </label>
                                            <?php else: ?>
                                                <span class="status-badge active">Hoạt động</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo date('d/m/Y', strtotime($staff['created_at'])); ?>
                                            <br>
                                            <small style="color: var(--text-tertiary);">
                                                <?php echo date('H:i', strtotime($staff['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="staff-edit.php?id=<?php echo $staff['id']; ?>" class="action-btn edit" title="Chỉnh sửa">
                                                    ✏️
                                                </a>
                                                <a href="staff-permissions.php?id=<?php echo $staff['id']; ?>" class="action-btn permission" title="Phân quyền">
                                                    🔐
                                                </a>
                                                <?php if ($staff['id'] !== $_SESSION['user_id']): ?>
                                                    <button class="action-btn delete" onclick="deleteStaff(<?php echo $staff['staff_id']; ?>)" title="Xóa">
                                                        🗑️
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_staff > 0): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Hiển thị <?php echo number_format($start_item); ?>-<?php echo number_format($end_item); ?> trong tổng số <?php echo number_format($total_staff); ?> nhân viên
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
                <?php endif; ?>
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
        document.getElementById('role-filter').addEventListener('change', updateFilters);
        document.getElementById('status-filter').addEventListener('change', updateFilters);
        
        function updateFilters() {
            const params = new URLSearchParams();
            
            const search = searchInput.value.trim();
            if (search) params.set('search', search);
            
            const role = document.getElementById('role-filter').value;
            if (role) params.set('role', role);
            
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
        
        // Staff actions
        async function deleteStaff(staffId) {
            if (!confirm('Bạn có chắc chắn muốn xóa nhân viên này?')) {
                return;
            }
            
            const success = await makeRequest('delete_staff', { staff_id: staffId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function toggleStaffStatus(userId) {
            const success = await makeRequest('toggle_status', { user_id: userId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
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
            console.log('🚀 Staff Management - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Staff Management - Ready!');
            console.log('👥 Staff count:', <?php echo $total_staff; ?>);
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
            }
            
            // Escape to blur search
            if (e.key === 'Escape' && document.activeElement === searchInput) {
                searchInput.blur();
            }
            
            // Ctrl/Cmd + N for new staff
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'staff-edit.php';
            }
        });
    </script>
</body>
</html>