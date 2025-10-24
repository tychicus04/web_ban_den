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
            case 'delete_brand':
                $brand_id = (int)$_POST['brand_id'];
                
                // Check if brand has products
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE brand_id = ?");
                $stmt->execute([$brand_id]);
                $has_products = $stmt->fetch()['count'] > 0;
                
                if ($has_products) {
                    echo json_encode(['success' => false, 'message' => 'Không thể xóa thương hiệu có sản phẩm']);
                    break;
                }
                
                // Delete brand and translations
                $db->beginTransaction();
                
                $stmt = $db->prepare("DELETE FROM brand_translations WHERE brand_id = ?");
                $stmt->execute([$brand_id]);
                
                $stmt = $db->prepare("DELETE FROM brands WHERE id = ?");
                $stmt->execute([$brand_id]);
                
                $db->commit();
                
                echo json_encode(['success' => true, 'message' => 'Thương hiệu đã được xóa']);
                break;
                
            case 'toggle_top':
                $brand_id = (int)$_POST['brand_id'];
                $stmt = $db->prepare("UPDATE brands SET top = 1 - top WHERE id = ?");
                $stmt->execute([$brand_id]);
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật trạng thái top']);
                break;
                
            case 'bulk_delete':
                $brand_ids = json_decode($_POST['brand_ids'], true);
                if (is_array($brand_ids) && !empty($brand_ids)) {
                    $placeholders = str_repeat('?,', count($brand_ids) - 1) . '?';
                    
                    // Check for products
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE brand_id IN ($placeholders)");
                    $stmt->execute($brand_ids);
                    $has_products = $stmt->fetch()['count'] > 0;
                    
                    if ($has_products) {
                        echo json_encode(['success' => false, 'message' => 'Một số thương hiệu có sản phẩm, không thể xóa']);
                        break;
                    }
                    
                    $db->beginTransaction();
                    
                    $stmt = $db->prepare("DELETE FROM brand_translations WHERE brand_id IN ($placeholders)");
                    $stmt->execute($brand_ids);
                    
                    $stmt = $db->prepare("DELETE FROM brands WHERE id IN ($placeholders)");
                    $stmt->execute($brand_ids);
                    
                    $db->commit();
                    
                    echo json_encode(['success' => true, 'message' => 'Đã xóa ' . count($brand_ids) . ' thương hiệu']);
                }
                break;
                
            case 'bulk_top':
                $brand_ids = json_decode($_POST['brand_ids'], true);
                if (is_array($brand_ids) && !empty($brand_ids)) {
                    $placeholders = str_repeat('?,', count($brand_ids) - 1) . '?';
                    $stmt = $db->prepare("UPDATE brands SET top = 1 WHERE id IN ($placeholders)");
                    $stmt->execute($brand_ids);
                    echo json_encode(['success' => true, 'message' => 'Đã đặt ' . count($brand_ids) . ' thương hiệu làm top']);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Brands action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// Pagination and filtering
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$where_conditions = ['1=1'];
$params = [];

if (!empty($search)) {
    $where_conditions[] = '(b.name LIKE ? OR b.slug LIKE ? OR b.id = ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = $search;
}

if (!empty($status_filter)) {
    switch ($status_filter) {
        case 'top':
            $where_conditions[] = 'b.top = 1';
            break;
        case 'has_products':
            $where_conditions[] = 'product_count > 0';
            break;
        case 'empty':
            $where_conditions[] = 'product_count = 0';
            break;
        case 'has_logo':
            $where_conditions[] = 'b.logo IS NOT NULL';
            break;
        case 'no_logo':
            $where_conditions[] = 'b.logo IS NULL';
            break;
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Valid sort columns
$valid_sorts = ['id', 'name', 'created_at', 'updated_at', 'product_count'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'created_at';
}

$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Get brands with pagination
$brands = [];
$total_brands = 0;

try {
    // Count total brands
    $count_sql = "
        SELECT COUNT(*) as total
        FROM brands b
        LEFT JOIN (
            SELECT brand_id, COUNT(*) as product_count 
            FROM products 
            WHERE published = 1 
            GROUP BY brand_id
        ) p ON b.id = p.brand_id
        WHERE $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_brands = $stmt->fetch()['total'];
    
    // Get brands
    $sql = "
        SELECT b.*, 
               COALESCE(p.product_count, 0) as product_count,
               u_logo.file_name as logo_url
        FROM brands b
        LEFT JOIN (
            SELECT brand_id, COUNT(*) as product_count 
            FROM products 
            WHERE published = 1 
            GROUP BY brand_id
        ) p ON b.id = p.brand_id
        LEFT JOIN uploads u_logo ON b.logo = u_logo.id
        WHERE $where_clause
        ORDER BY b.$sort $order
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $brands = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Brands fetch error: " . $e->getMessage());
    $brands = [];
}

// Calculate pagination
$total_pages = ceil($total_brands / $per_page);
$start_item = $offset + 1;
$end_item = min($offset + $per_page, $total_brands);

// Brand statistics
$stats = [];
try {
    // Total brands
    $stmt = $db->query("SELECT COUNT(*) as count FROM brands");
    $stats['total'] = $stmt->fetch()['count'];
    
    // Top brands
    $stmt = $db->query("SELECT COUNT(*) as count FROM brands WHERE top = 1");
    $stats['top'] = $stmt->fetch()['count'];
    
    // Brands with products
    $stmt = $db->query("
        SELECT COUNT(DISTINCT b.id) as count 
        FROM brands b 
        INNER JOIN products p ON b.id = p.brand_id 
        WHERE p.published = 1
    ");
    $stats['with_products'] = $stmt->fetch()['count'];
    
    // Empty brands
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM brands b 
        LEFT JOIN products p ON b.id = p.brand_id AND p.published = 1
        WHERE p.id IS NULL
    ");
    $stats['empty'] = $stmt->fetch()['count'];
    
    // Brands with logo
    $stmt = $db->query("SELECT COUNT(*) as count FROM brands WHERE logo IS NOT NULL");
    $stats['with_logo'] = $stmt->fetch()['count'];
    
} catch (PDOException $e) {
    error_log("Brand stats error: " . $e->getMessage());
    $stats = ['total' => 0, 'top' => 0, 'with_products' => 0, 'empty' => 0, 'with_logo' => 0];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý thương hiệu - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý thương hiệu sản phẩm - Admin <?php echo htmlspecialchars($site_name); ?>">
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
        
        /* Brand specific styles */
        .brand-logo {
            width: 50px;
            height: 50px;
            border-radius: var(--rounded-lg);
            object-fit: contain;
            background: var(--gray-50);
            box-shadow: var(--shadow-sm);
            padding: var(--space-1);
        }
        
        .brand-info {
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }
        
        .brand-details {
            flex: 1;
        }
        
        .brand-name {
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            margin-bottom: var(--space-1);
            font-size: var(--text-base);
        }
        
        .brand-meta {
            font-size: var(--text-xs);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .brand-id {
            font-family: monospace;
            background: var(--gray-100);
            padding: 2px 6px;
            border-radius: var(--rounded);
        }
        
        .brand-slug {
            font-family: monospace;
            color: var(--text-tertiary);
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
        
        .status-badge.top {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .status-badge.has-products {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .status-badge.empty {
            background: rgba(107, 114, 128, 0.1);
            color: #374151;
        }
        
        .status-badge.has-logo {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .status-badge.no-logo {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        /* Product count indicator */
        .product-count {
            font-weight: var(--font-semibold);
            padding: var(--space-1) var(--space-2);
            border-radius: var(--rounded);
            font-size: var(--text-xs);
        }
        
        .product-count.none {
            background: var(--gray-100);
            color: var(--text-tertiary);
        }
        
        .product-count.low {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .product-count.medium {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .product-count.high {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
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
        
        .action-btn.top {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .action-btn.top:hover {
            background: rgba(245, 158, 11, 0.2);
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
                        <a href="brands.php" class="nav-link active">
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
                            <span>Thương hiệu</span>
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
                    <h1 class="page-title">Quản lý thương hiệu</h1>
                    <p class="page-subtitle">Quản lý các thương hiệu sản phẩm trong hệ thống</p>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Tổng thương hiệu</div>
                            <div class="stat-icon">🏷️</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Top thương hiệu</div>
                            <div class="stat-icon">🔝</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['top']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Có sản phẩm</div>
                            <div class="stat-icon">📦</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['with_products']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Trống</div>
                            <div class="stat-icon">📋</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['empty']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Có logo</div>
                            <div class="stat-icon">🖼️</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['with_logo']); ?></div>
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
                                    placeholder="Tìm kiếm thương hiệu..."
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    id="search-input"
                                >
                            </div>
                        </div>
                        <div class="toolbar-right">
                            <a href="brand-edit.php" class="btn btn-primary">
                                <span>➕</span>
                                <span>Thêm thương hiệu</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="toolbar-row">
                        <div class="toolbar-left">
                            <select class="filter-select" id="status-filter">
                                <option value="">Tất cả trạng thái</option>
                                <option value="top" <?php echo $status_filter === 'top' ? 'selected' : ''; ?>>Top</option>
                                <option value="has_products" <?php echo $status_filter === 'has_products' ? 'selected' : ''; ?>>Có sản phẩm</option>
                                <option value="empty" <?php echo $status_filter === 'empty' ? 'selected' : ''; ?>>Trống</option>
                                <option value="has_logo" <?php echo $status_filter === 'has_logo' ? 'selected' : ''; ?>>Có logo</option>
                                <option value="no_logo" <?php echo $status_filter === 'no_logo' ? 'selected' : ''; ?>>Không logo</option>
                            </select>
                        </div>
                        <div class="toolbar-right">
                            <button class="btn btn-secondary" onclick="exportBrands()">
                                <span>📤</span>
                                <span>Xuất file</span>
                            </button>
                            <button class="btn btn-secondary" onclick="importBrands()">
                                <span>📥</span>
                                <span>Nhập file</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Bulk Actions -->
                <div class="bulk-actions" id="bulk-actions">
                    <span class="bulk-count" id="bulk-count">0 thương hiệu được chọn</span>
                    <button class="btn btn-success btn-sm" onclick="bulkAction('top')">
                        <span>🔝</span>
                        <span>Đặt top</span>
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="bulkAction('delete')">
                        <span>🗑️</span>
                        <span>Xóa</span>
                    </button>
                </div>
                
                <!-- Brands Table -->
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" class="checkbox" id="select-all">
                                </th>
                                <th class="sortable <?php echo $sort === 'id' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="id">
                                    ID
                                </th>
                                <th>Thương hiệu</th>
                                <th class="sortable <?php echo $sort === 'product_count' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="product_count">
                                    Sản phẩm
                                </th>
                                <th>Trạng thái</th>
                                <th class="sortable <?php echo $sort === 'created_at' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="created_at">
                                    Ngày tạo
                                </th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($brands as $brand): ?>
                                <tr data-brand-id="<?php echo $brand['id']; ?>">
                                    <td>
                                        <input type="checkbox" class="checkbox brand-checkbox" value="<?php echo $brand['id']; ?>">
                                    </td>
                                    <td>
                                        <span class="brand-id">#<?php echo $brand['id']; ?></span>
                                    </td>
                                    <td>
                                        <div class="brand-info">
                                            <img 
                                                src="<?php echo !empty($brand['logo_url']) && file_exists('../' . $brand['logo_url']) ? '../' . htmlspecialchars($brand['logo_url']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50" viewBox="0 0 50 50"><rect width="50" height="50" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="20" fill="%236b7280">🏷️</text></svg>'; ?>" 
                                                alt="<?php echo htmlspecialchars($brand['name']); ?>"
                                                class="brand-logo"
                                                loading="lazy"
                                            >
                                            <div class="brand-details">
                                                <div class="brand-name"><?php echo htmlspecialchars($brand['name']); ?></div>
                                                <div class="brand-meta">
                                                    <?php if ($brand['slug']): ?>
                                                        <span class="brand-slug">/{<?php echo htmlspecialchars($brand['slug']); ?>}</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $productCount = (int)$brand['product_count'];
                                        $countClass = $productCount == 0 ? 'none' : ($productCount <= 5 ? 'low' : ($productCount <= 20 ? 'medium' : 'high'));
                                        ?>
                                        <span class="product-count <?php echo $countClass; ?>">
                                            <?php echo number_format($productCount); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: var(--space-1);">
                                            <?php if ($brand['top']): ?>
                                                <span class="status-badge top">Top</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($brand['product_count'] > 0): ?>
                                                <span class="status-badge has-products">Có sản phẩm</span>
                                            <?php else: ?>
                                                <span class="status-badge empty">Trống</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($brand['logo']): ?>
                                                <span class="status-badge has-logo">Có logo</span>
                                            <?php else: ?>
                                                <span class="status-badge no-logo">Không logo</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime($brand['created_at'])); ?>
                                        <br>
                                        <small style="color: var(--text-tertiary);">
                                            <?php echo date('H:i', strtotime($brand['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn edit" onclick="editBrand(<?php echo $brand['id']; ?>)" title="Chỉnh sửa">
                                                ✏️
                                            </button>
                                            <button class="action-btn top <?php echo $brand['top'] ? 'active' : ''; ?>" onclick="toggleTop(<?php echo $brand['id']; ?>)" title="<?php echo $brand['top'] ? 'Bỏ top' : 'Đặt top'; ?>">
                                                <?php echo $brand['top'] ? '🔝' : '🔺'; ?>
                                            </button>
                                            <button class="action-btn delete" onclick="deleteBrand(<?php echo $brand['id']; ?>)" title="Xóa">
                                                🗑️
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="pagination">
                    <div class="pagination-info">
                        Hiển thị <?php echo number_format($start_item); ?>-<?php echo number_format($end_item); ?> trong tổng số <?php echo number_format($total_brands); ?> thương hiệu
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
        document.getElementById('status-filter').addEventListener('change', updateFilters);
        
        function updateFilters() {
            const params = new URLSearchParams();
            
            const search = searchInput.value.trim();
            if (search) params.set('search', search);
            
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
                
                let newOrder = 'ASC';
                if (currentSort === sortBy && currentOrder === 'ASC') {
                    newOrder = 'DESC';
                }
                
                urlParams.set('sort', sortBy);
                urlParams.set('order', newOrder);
                urlParams.set('page', '1');
                
                window.location.search = urlParams.toString();
            });
        });
        
        // Select all functionality
        const selectAllCheckbox = document.getElementById('select-all');
        const brandCheckboxes = document.querySelectorAll('.brand-checkbox');
        const bulkActions = document.getElementById('bulk-actions');
        const bulkCount = document.getElementById('bulk-count');
        
        selectAllCheckbox.addEventListener('change', function() {
            brandCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });
        
        brandCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateBulkActions();
                
                // Update select all checkbox
                const checkedCount = document.querySelectorAll('.brand-checkbox:checked').length;
                selectAllCheckbox.checked = checkedCount === brandCheckboxes.length;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < brandCheckboxes.length;
            });
        });
        
        function updateBulkActions() {
            const selectedBrands = document.querySelectorAll('.brand-checkbox:checked');
            const count = selectedBrands.length;
            
            if (count > 0) {
                bulkActions.classList.add('show');
                bulkCount.textContent = `${count} thương hiệu được chọn`;
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
        
        // Brand actions
        function editBrand(brandId) {
            window.location.href = `brand-edit.php?id=${brandId}`;
        }
        
        async function deleteBrand(brandId) {
            if (!confirm('Bạn có chắc chắn muốn xóa thương hiệu này?\n\nLưu ý: Không thể xóa thương hiệu có sản phẩm.')) {
                return;
            }
            
            const success = await makeRequest('delete_brand', { brand_id: brandId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function toggleTop(brandId) {
            const success = await makeRequest('toggle_top', { brand_id: brandId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Bulk actions
        async function bulkAction(action) {
            const selectedBrands = Array.from(document.querySelectorAll('.brand-checkbox:checked'))
                .map(checkbox => checkbox.value);
            
            if (selectedBrands.length === 0) {
                showNotification('Vui lòng chọn ít nhất một thương hiệu', 'error');
                return;
            }
            
            let confirmMessage = '';
            switch (action) {
                case 'delete':
                    confirmMessage = `Bạn có chắc chắn muốn xóa ${selectedBrands.length} thương hiệu đã chọn?\n\nLưu ý: Không thể xóa thương hiệu có sản phẩm.`;
                    break;
                case 'top':
                    confirmMessage = `Bạn có chắc chắn muốn đặt ${selectedBrands.length} thương hiệu làm top?`;
                    break;
            }
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            let ajaxAction = '';
            switch (action) {
                case 'delete':
                    ajaxAction = 'bulk_delete';
                    break;
                case 'top':
                    ajaxAction = 'bulk_top';
                    break;
            }
            
            const success = await makeRequest(ajaxAction, { 
                brand_ids: JSON.stringify(selectedBrands) 
            });
            
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Export/Import functions
        function exportBrands() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', '1');
            window.open('?' + params.toString(), '_blank');
        }
        
        function importBrands() {
            window.location.href = 'brand-import.php';
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
            console.log('🚀 Brands Management - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Brands Management - Ready!');
            console.log('🏷️ Brand count:', <?php echo $total_brands; ?>);
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
            
            // Ctrl/Cmd + N for new brand
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'brand-edit.php';
            }
        });
        
        // Auto-save filter preferences
        function saveFilterPreferences() {
            const preferences = {
                status: document.getElementById('status-filter').value
            };
            localStorage.setItem('brandFilters', JSON.stringify(preferences));
        }
        
        function loadFilterPreferences() {
            const saved = localStorage.getItem('brandFilters');
            if (saved) {
                const preferences = JSON.parse(saved);
                // Only apply if no URL parameters are set
                const urlParams = new URLSearchParams(window.location.search);
                if (!urlParams.has('status') && preferences.status) {
                    document.getElementById('status-filter').value = preferences.status;
                }
            }
        }
        
        // Save preferences when filters change
        document.getElementById('status-filter').addEventListener('change', saveFilterPreferences);
        
        // Load preferences on page load
        loadFilterPreferences();
    </script>
</body>
</html>