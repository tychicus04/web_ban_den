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

// Fix invalid category levels in database
function fixCategoryLevels($db) {
    try {
        // Check for invalid levels
        $stmt = $db->query("SELECT id, level FROM categories WHERE level < 0 OR level > 10");
        $invalid_categories = $stmt->fetchAll();
        
        if (!empty($invalid_categories)) {
            error_log("Found " . count($invalid_categories) . " categories with invalid levels");
            
            // Fix invalid levels
            $fix_stmt = $db->prepare("UPDATE categories SET level = 0 WHERE id = ?");
            foreach ($invalid_categories as $cat) {
                $fix_stmt->execute([$cat['id']]);
                error_log("Fixed category ID {$cat['id']} level from {$cat['level']} to 0");
            }
        }
    } catch (PDOException $e) {
        error_log("Error fixing category levels: " . $e->getMessage());
    }
}

// Run the fix
fixCategoryLevels($db);

// Helper function to get category level padding
function getCategoryLevelPadding($level) {
    // Validate and normalize level to prevent errors
    $level = max(0, min((int)$level, 10)); // Limit to 0-10 levels
    return str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
}

// Helper function to get category icon by level
function getCategoryIcon($level) {
    // Validate level
    $level = max(0, min((int)$level, 10));
    switch ($level) {
        case 0: return '📁';
        case 1: return '📂';
        case 2: return '📄';
        default: return '📋';
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
            case 'delete_category':
                $category_id = (int)$_POST['category_id'];
                
                // Check if category has children
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM categories WHERE parent_id = ?");
                $stmt->execute([$category_id]);
                $has_children = $stmt->fetch()['count'] > 0;
                
                if ($has_children) {
                    echo json_encode(['success' => false, 'message' => 'Không thể xóa danh mục có danh mục con']);
                    break;
                }
                
                // Check if category has products
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
                $stmt->execute([$category_id]);
                $has_products = $stmt->fetch()['count'] > 0;
                
                if ($has_products) {
                    echo json_encode(['success' => false, 'message' => 'Không thể xóa danh mục có sản phẩm']);
                    break;
                }
                
                // Delete category and translations
                $db->beginTransaction();
                
                $stmt = $db->prepare("DELETE FROM category_translations WHERE category_id = ?");
                $stmt->execute([$category_id]);
                
                $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
                $stmt->execute([$category_id]);
                
                $db->commit();
                
                echo json_encode(['success' => true, 'message' => 'Danh mục đã được xóa']);
                break;
                
            case 'toggle_featured':
                $category_id = (int)$_POST['category_id'];
                $stmt = $db->prepare("UPDATE categories SET featured = 1 - featured WHERE id = ?");
                $stmt->execute([$category_id]);
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật trạng thái nổi bật']);
                break;
                
            case 'toggle_top':
                $category_id = (int)$_POST['category_id'];
                $stmt = $db->prepare("UPDATE categories SET top = 1 - top WHERE id = ?");
                $stmt->execute([$category_id]);
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật trạng thái top']);
                break;
                
            case 'bulk_delete':
                $category_ids = json_decode($_POST['category_ids'], true);
                if (is_array($category_ids) && !empty($category_ids)) {
                    $placeholders = str_repeat('?,', count($category_ids) - 1) . '?';
                    
                    // Check for children
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM categories WHERE parent_id IN ($placeholders)");
                    $stmt->execute($category_ids);
                    $has_children = $stmt->fetch()['count'] > 0;
                    
                    if ($has_children) {
                        echo json_encode(['success' => false, 'message' => 'Một số danh mục có danh mục con, không thể xóa']);
                        break;
                    }
                    
                    $db->beginTransaction();
                    
                    $stmt = $db->prepare("DELETE FROM category_translations WHERE category_id IN ($placeholders)");
                    $stmt->execute($category_ids);
                    
                    $stmt = $db->prepare("DELETE FROM categories WHERE id IN ($placeholders)");
                    $stmt->execute($category_ids);
                    
                    $db->commit();
                    
                    echo json_encode(['success' => true, 'message' => 'Đã xóa ' . count($category_ids) . ' danh mục']);
                }
                break;
                
            case 'bulk_feature':
                $category_ids = json_decode($_POST['category_ids'], true);
                if (is_array($category_ids) && !empty($category_ids)) {
                    $placeholders = str_repeat('?,', count($category_ids) - 1) . '?';
                    $stmt = $db->prepare("UPDATE categories SET featured = 1 WHERE id IN ($placeholders)");
                    $stmt->execute($category_ids);
                    echo json_encode(['success' => true, 'message' => 'Đã đặt ' . count($category_ids) . ' danh mục làm nổi bật']);
                }
                break;
                
            case 'update_order':
                $orders = json_decode($_POST['orders'], true);
                if (is_array($orders)) {
                    $db->beginTransaction();
                    $stmt = $db->prepare("UPDATE categories SET order_level = ? WHERE id = ?");
                    foreach ($orders as $item) {
                        $stmt->execute([$item['order'], $item['id']]);
                    }
                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'Đã cập nhật thứ tự danh mục']);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Categories action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// Pagination and filtering
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50; // More items for categories
$offset = ($page - 1) * $per_page;

$search = $_GET['search'] ?? '';
$level_filter = $_GET['level'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'order_level';
$order = $_GET['order'] ?? 'ASC';

// Build WHERE clause
$where_conditions = ['1=1'];
$params = [];

if (!empty($search)) {
    $where_conditions[] = '(c.name LIKE ? OR c.slug LIKE ? OR c.id = ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = $search;
}

if ($level_filter !== '') {
    $where_conditions[] = 'c.level = ?';
    $params[] = $level_filter;
}

if (!empty($status_filter)) {
    switch ($status_filter) {
        case 'featured':
            $where_conditions[] = 'c.featured = 1';
            break;
        case 'top':
            $where_conditions[] = 'c.top = 1';
            break;
        case 'digital':
            $where_conditions[] = 'c.digital = 1';
            break;
        case 'has_products':
            $where_conditions[] = 'product_count > 0';
            break;
        case 'empty':
            $where_conditions[] = 'product_count = 0';
            break;
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Valid sort columns
$valid_sorts = ['id', 'name', 'level', 'order_level', 'created_at', 'updated_at', 'product_count'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'order_level';
}

$order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

// Get categories with pagination
$categories = [];
$total_categories = 0;

try {
    // Count total categories
    $count_sql = "
        SELECT COUNT(*) as total
        FROM categories c
        LEFT JOIN (
            SELECT category_id, COUNT(*) as product_count 
            FROM products 
            WHERE published = 1 
            GROUP BY category_id
        ) p ON c.id = p.category_id
        WHERE $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_categories = $stmt->fetch()['total'];
    
    // Get categories
    $sql = "
        SELECT c.*, 
               pc.name as parent_name,
               COALESCE(p.product_count, 0) as product_count,
               u_banner.file_name as banner_url,
               u_icon.file_name as icon_url,
               u_cover.file_name as cover_url,
               GREATEST(0, LEAST(c.level, 10)) as safe_level
        FROM categories c
        LEFT JOIN categories pc ON c.parent_id = pc.id
        LEFT JOIN (
            SELECT category_id, COUNT(*) as product_count 
            FROM products 
            WHERE published = 1 
            GROUP BY category_id
        ) p ON c.id = p.category_id
        LEFT JOIN uploads u_banner ON c.banner = u_banner.id
        LEFT JOIN uploads u_icon ON c.icon = u_icon.id
        LEFT JOIN uploads u_cover ON c.cover_image = u_cover.id
        WHERE $where_clause
        ORDER BY c.$sort $order, c.parent_id ASC, c.order_level ASC
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $categories = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Categories fetch error: " . $e->getMessage());
    $categories = [];
}

// Get parent categories for filter
$parent_categories = [];
try {
    $stmt = $db->query("SELECT id, name FROM categories WHERE level = 0 ORDER BY order_level ASC, name ASC");
    $parent_categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Parent categories fetch error: " . $e->getMessage());
}

// Calculate pagination
$total_pages = ceil($total_categories / $per_page);
$start_item = $offset + 1;
$end_item = min($offset + $per_page, $total_categories);

// Category statistics
$stats = [];
try {
    // Total categories
    $stmt = $db->query("SELECT COUNT(*) as count FROM categories");
    $stats['total'] = $stmt->fetch()['count'];
    
    // Top level categories
    $stmt = $db->query("SELECT COUNT(*) as count FROM categories WHERE level = 0");
    $stats['top_level'] = $stmt->fetch()['count'];
    
    // Featured categories
    $stmt = $db->query("SELECT COUNT(*) as count FROM categories WHERE featured = 1");
    $stats['featured'] = $stmt->fetch()['count'];
    
    // Categories with products
    $stmt = $db->query("
        SELECT COUNT(DISTINCT c.id) as count 
        FROM categories c 
        INNER JOIN products p ON c.id = p.category_id 
        WHERE p.published = 1
    ");
    $stats['with_products'] = $stmt->fetch()['count'];
    
    // Empty categories
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM categories c 
        LEFT JOIN products p ON c.id = p.category_id AND p.published = 1
        WHERE p.id IS NULL
    ");
    $stats['empty'] = $stmt->fetch()['count'];
    
} catch (PDOException $e) {
    error_log("Category stats error: " . $e->getMessage());
    $stats = ['total' => 0, 'top_level' => 0, 'featured' => 0, 'with_products' => 0, 'empty' => 0];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý danh mục - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý danh mục sản phẩm - Admin <?php echo htmlspecialchars($site_name); ?>">
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
        
        /* Category specific styles */
        .category-image {
            width: 50px;
            height: 50px;
            border-radius: var(--rounded-lg);
            object-fit: cover;
            background: var(--gray-100);
            box-shadow: var(--shadow-sm);
        }
        
        .category-info {
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }
        
        .category-details {
            flex: 1;
        }
        
        .category-name {
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            margin-bottom: var(--space-1);
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .category-level {
            font-size: var(--text-lg);
        }
        
        .category-hierarchy {
            display: inline-block;
            color: var(--text-tertiary);
        }
        
        .category-meta {
            font-size: var(--text-xs);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .category-id {
            font-family: monospace;
            background: var(--gray-100);
            padding: 2px 6px;
            border-radius: var(--rounded);
        }
        
        .category-slug {
            font-family: monospace;
            color: var(--text-tertiary);
        }
        
        .commission-rate {
            font-weight: var(--font-semibold);
            color: var(--primary);
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
        
        .status-badge.featured {
            background: rgba(139, 92, 246, 0.1);
            color: #5b21b6;
        }
        
        .status-badge.top {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .status-badge.digital {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .status-badge.has-products {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .status-badge.empty {
            background: rgba(107, 114, 128, 0.1);
            color: #374151;
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
        
        .action-btn.feature {
            background: rgba(139, 92, 246, 0.1);
            color: #5b21b6;
        }
        
        .action-btn.feature:hover {
            background: rgba(139, 92, 246, 0.2);
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
        
        /* Drag and drop styles */
        .sortable-row {
            cursor: move;
        }
        
        .sortable-row:hover {
            background: var(--gray-50) !important;
        }
        
        .sortable-row.dragging {
            opacity: 0.5;
            background: var(--primary-light) !important;
        }
        
        .drop-indicator {
            height: 2px;
            background: var(--primary);
            margin: 2px 0;
            border-radius: 1px;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        
        .drop-indicator.active {
            opacity: 1;
        }
        
        .drag-handle {
            color: var(--text-tertiary);
            cursor: grab;
            padding: var(--space-2);
        }
        
        .drag-handle:hover {
            color: var(--text-secondary);
        }
        
        .drag-handle:active {
            cursor: grabbing;
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
                        <a href="categories.php" class="nav-link active">
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
                            <span>Danh mục</span>
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
                    <h1 class="page-title">Quản lý danh mục</h1>
                    <p class="page-subtitle">Quản lý cấu trúc danh mục sản phẩm và phân cấp</p>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Tổng danh mục</div>
                            <div class="stat-icon">📂</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Danh mục gốc</div>
                            <div class="stat-icon">📁</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['top_level']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Nổi bật</div>
                            <div class="stat-icon">⭐</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['featured']); ?></div>
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
                                    placeholder="Tìm kiếm danh mục..."
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    id="search-input"
                                >
                            </div>
                        </div>
                        <div class="toolbar-right">
                            <a href="category-edit.php" class="btn btn-primary">
                                <span>➕</span>
                                <span>Thêm danh mục</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="toolbar-row">
                        <div class="toolbar-left">
                            <select class="filter-select" id="level-filter">
                                <option value="">Tất cả cấp độ</option>
                                <option value="0" <?php echo $level_filter === '0' ? 'selected' : ''; ?>>Cấp 1 (Gốc)</option>
                                <option value="1" <?php echo $level_filter === '1' ? 'selected' : ''; ?>>Cấp 2</option>
                                <option value="2" <?php echo $level_filter === '2' ? 'selected' : ''; ?>>Cấp 3</option>
                                <option value="3" <?php echo $level_filter === '3' ? 'selected' : ''; ?>>Cấp 4+</option>
                            </select>
                            
                            <select class="filter-select" id="status-filter">
                                <option value="">Tất cả trạng thái</option>
                                <option value="featured" <?php echo $status_filter === 'featured' ? 'selected' : ''; ?>>Nổi bật</option>
                                <option value="top" <?php echo $status_filter === 'top' ? 'selected' : ''; ?>>Top</option>
                                <option value="digital" <?php echo $status_filter === 'digital' ? 'selected' : ''; ?>>Số</option>
                                <option value="has_products" <?php echo $status_filter === 'has_products' ? 'selected' : ''; ?>>Có sản phẩm</option>
                                <option value="empty" <?php echo $status_filter === 'empty' ? 'selected' : ''; ?>>Trống</option>
                            </select>
                        </div>
                        <div class="toolbar-right">
                            <button class="btn btn-secondary" onclick="exportCategories()">
                                <span>📤</span>
                                <span>Xuất file</span>
                            </button>
                            <button class="btn btn-secondary" onclick="importCategories()">
                                <span>📥</span>
                                <span>Nhập file</span>
                            </button>
                            <button class="btn btn-secondary" onclick="toggleSortMode()" id="sort-toggle">
                                <span>🔀</span>
                                <span>Sắp xếp</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Bulk Actions -->
                <div class="bulk-actions" id="bulk-actions">
                    <span class="bulk-count" id="bulk-count">0 danh mục được chọn</span>
                    <button class="btn btn-success btn-sm" onclick="bulkAction('feature')">
                        <span>⭐</span>
                        <span>Đặt nổi bật</span>
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="bulkAction('top')">
                        <span>🔝</span>
                        <span>Đặt top</span>
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="bulkAction('delete')">
                        <span>🗑️</span>
                        <span>Xóa</span>
                    </button>
                </div>
                
                <!-- Categories Table -->
                <div class="table-container">
                    <table class="table" id="categories-table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" class="checkbox" id="select-all">
                                </th>
                                <th class="sortable <?php echo $sort === 'id' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="id">
                                    ID
                                </th>
                                <th>Danh mục</th>
                                <th class="sortable <?php echo $sort === 'level' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="level">
                                    Cấp độ
                                </th>
                                <th>Hoa hồng</th>
                                <th>Sản phẩm</th>
                                <th>Trạng thái</th>
                                <th class="sortable <?php echo $sort === 'order_level' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="order_level">
                                    Thứ tự
                                </th>
                                <th class="sortable <?php echo $sort === 'created_at' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="created_at">
                                    Ngày tạo
                                </th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody id="categories-tbody">
                            <?php foreach ($categories as $category): ?>
                                <tr class="sortable-row" data-category-id="<?php echo $category['id']; ?>" data-order="<?php echo $category['order_level']; ?>">
                                    <td>
                                        <div style="display: flex; align-items: center; gap: var(--space-2);">
                                            <span class="drag-handle" style="cursor: grab;">⋮⋮</span>
                                            <input type="checkbox" class="checkbox category-checkbox" value="<?php echo $category['id']; ?>">
                                        </div>
                                    </td>
                                    <td>
                                        <span class="category-id">#<?php echo $category['id']; ?></span>
                                    </td>
                                    <td>
                                        <div class="category-info">
                                            <img 
                                                src="<?php echo !empty($category['icon_url']) && file_exists('../' . $category['icon_url']) ? '../' . htmlspecialchars($category['icon_url']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50" viewBox="0 0 50 50"><rect width="50" height="50" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="20" fill="%236b7280">' . getCategoryIcon($category['level']) . '</text></svg>'; ?>" 
                                                alt="<?php echo htmlspecialchars($category['name']); ?>"
                                                class="category-image"
                                                loading="lazy"
                                            >
                                            <div class="category-details">
                                                <div class="category-name">
                                                    <span class="category-level"><?php echo getCategoryIcon($category['safe_level']); ?></span>
                                                    <span class="category-hierarchy"><?php echo getCategoryLevelPadding($category['safe_level']); ?></span>
                                                    <?php echo htmlspecialchars($category['name']); ?>
                                                </div>
                                                <div class="category-meta">
                                                    <?php if ($category['parent_name']): ?>
                                                        <span>📁 <?php echo htmlspecialchars($category['parent_name']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($category['slug']): ?>
                                                        <span class="category-slug">/{<?php echo htmlspecialchars($category['slug']); ?>}</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge" style="background: rgba(<?php echo ($category['safe_level'] * 60) % 255; ?>, <?php echo (255 - $category['safe_level'] * 60) % 255; ?>, 200, 0.1); color: rgb(<?php echo ($category['safe_level'] * 60) % 200; ?>, <?php echo (200 - $category['safe_level'] * 60) % 200; ?>, 150);">
                                            Cấp <?php echo $category['safe_level'] + 1; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($category['commision_rate'] > 0): ?>
                                            <span class="commission-rate"><?php echo number_format($category['commision_rate'], 2); ?>%</span>
                                        <?php else: ?>
                                            <span style="color: var(--text-tertiary);">Không</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $productCount = (int)$category['product_count'];
                                        $countClass = $productCount == 0 ? 'none' : ($productCount <= 5 ? 'low' : ($productCount <= 20 ? 'medium' : 'high'));
                                        ?>
                                        <span class="product-count <?php echo $countClass; ?>">
                                            <?php echo number_format($productCount); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: var(--space-1);">
                                            <?php if ($category['featured']): ?>
                                                <span class="status-badge featured">Nổi bật</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($category['top']): ?>
                                                <span class="status-badge top">Top</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($category['digital']): ?>
                                                <span class="status-badge digital">Số</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($category['product_count'] > 0): ?>
                                                <span class="status-badge has-products">Có sản phẩm</span>
                                            <?php else: ?>
                                                <span class="status-badge empty">Trống</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo $category['order_level']; ?></strong>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime($category['created_at'])); ?>
                                        <br>
                                        <small style="color: var(--text-tertiary);">
                                            <?php echo date('H:i', strtotime($category['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn edit" onclick="editCategory(<?php echo $category['id']; ?>)" title="Chỉnh sửa">
                                                ✏️
                                            </button>
                                            <button class="action-btn feature <?php echo $category['featured'] ? 'active' : ''; ?>" onclick="toggleFeatured(<?php echo $category['id']; ?>)" title="<?php echo $category['featured'] ? 'Bỏ nổi bật' : 'Đặt nổi bật'; ?>">
                                                <?php echo $category['featured'] ? '⭐' : '☆'; ?>
                                            </button>
                                            <button class="action-btn top <?php echo $category['top'] ? 'active' : ''; ?>" onclick="toggleTop(<?php echo $category['id']; ?>)" title="<?php echo $category['top'] ? 'Bỏ top' : 'Đặt top'; ?>">
                                                <?php echo $category['top'] ? '🔝' : '🔺'; ?>
                                            </button>
                                            <button class="action-btn delete" onclick="deleteCategory(<?php echo $category['id']; ?>)" title="Xóa">
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
                        Hiển thị <?php echo number_format($start_item); ?>-<?php echo number_format($end_item); ?> trong tổng số <?php echo number_format($total_categories); ?> danh mục
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
        document.getElementById('level-filter').addEventListener('change', updateFilters);
        document.getElementById('status-filter').addEventListener('change', updateFilters);
        
        function updateFilters() {
            const params = new URLSearchParams();
            
            const search = searchInput.value.trim();
            if (search) params.set('search', search);
            
            const level = document.getElementById('level-filter').value;
            if (level !== '') params.set('level', level);
            
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
        const categoryCheckboxes = document.querySelectorAll('.category-checkbox');
        const bulkActions = document.getElementById('bulk-actions');
        const bulkCount = document.getElementById('bulk-count');
        
        selectAllCheckbox.addEventListener('change', function() {
            categoryCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });
        
        categoryCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateBulkActions();
                
                // Update select all checkbox
                const checkedCount = document.querySelectorAll('.category-checkbox:checked').length;
                selectAllCheckbox.checked = checkedCount === categoryCheckboxes.length;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < categoryCheckboxes.length;
            });
        });
        
        function updateBulkActions() {
            const selectedCategories = document.querySelectorAll('.category-checkbox:checked');
            const count = selectedCategories.length;
            
            if (count > 0) {
                bulkActions.classList.add('show');
                bulkCount.textContent = `${count} danh mục được chọn`;
            } else {
                bulkActions.classList.remove('show');
            }
        }
        
        // Drag and drop sorting
        let sortMode = false;
        let draggedElement = null;
        
        function toggleSortMode() {
            sortMode = !sortMode;
            const sortToggle = document.getElementById('sort-toggle');
            const tbody = document.getElementById('categories-tbody');
            
            if (sortMode) {
                sortToggle.innerHTML = '<span>✅</span><span>Lưu thứ tự</span>';
                sortToggle.className = 'btn btn-success';
                tbody.classList.add('sortable-mode');
                enableDragAndDrop();
            } else {
                sortToggle.innerHTML = '<span>🔀</span><span>Sắp xếp</span>';
                sortToggle.className = 'btn btn-secondary';
                tbody.classList.remove('sortable-mode');
                disableDragAndDrop();
                saveSortOrder();
            }
        }
        
        function enableDragAndDrop() {
            const rows = document.querySelectorAll('.sortable-row');
            rows.forEach(row => {
                row.draggable = true;
                
                row.addEventListener('dragstart', function(e) {
                    draggedElement = this;
                    this.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                });
                
                row.addEventListener('dragend', function() {
                    this.classList.remove('dragging');
                    draggedElement = null;
                });
                
                row.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                });
                
                row.addEventListener('drop', function(e) {
                    e.preventDefault();
                    if (draggedElement && draggedElement !== this) {
                        const tbody = this.parentNode;
                        const draggedIndex = Array.from(tbody.children).indexOf(draggedElement);
                        const targetIndex = Array.from(tbody.children).indexOf(this);
                        
                        if (draggedIndex < targetIndex) {
                            tbody.insertBefore(draggedElement, this.nextSibling);
                        } else {
                            tbody.insertBefore(draggedElement, this);
                        }
                        
                        updateRowOrders();
                    }
                });
            });
        }
        
        function disableDragAndDrop() {
            const rows = document.querySelectorAll('.sortable-row');
            rows.forEach(row => {
                row.draggable = false;
                // Remove event listeners by cloning and replacing
                const newRow = row.cloneNode(true);
                row.parentNode.replaceChild(newRow, row);
            });
        }
        
        function updateRowOrders() {
            const rows = document.querySelectorAll('.sortable-row');
            rows.forEach((row, index) => {
                row.dataset.order = index + 1;
                const orderCell = row.querySelector('td:nth-child(8) strong');
                if (orderCell) {
                    orderCell.textContent = index + 1;
                }
            });
        }
        
        async function saveSortOrder() {
            const rows = document.querySelectorAll('.sortable-row');
            const orders = Array.from(rows).map((row, index) => ({
                id: parseInt(row.dataset.categoryId),
                order: index + 1
            }));
            
            const success = await makeRequest('update_order', { 
                orders: JSON.stringify(orders) 
            });
            
            if (success) {
                showNotification('Đã cập nhật thứ tự danh mục', 'success');
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
        
        // Category actions
        function editCategory(categoryId) {
            window.location.href = `category-edit.php?id=${categoryId}`;
        }
        
        async function deleteCategory(categoryId) {
            if (!confirm('Bạn có chắc chắn muốn xóa danh mục này?\n\nLưu ý: Không thể xóa danh mục có danh mục con hoặc có sản phẩm.')) {
                return;
            }
            
            const success = await makeRequest('delete_category', { category_id: categoryId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function toggleFeatured(categoryId) {
            const success = await makeRequest('toggle_featured', { category_id: categoryId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function toggleTop(categoryId) {
            const success = await makeRequest('toggle_top', { category_id: categoryId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Bulk actions
        async function bulkAction(action) {
            const selectedCategories = Array.from(document.querySelectorAll('.category-checkbox:checked'))
                .map(checkbox => checkbox.value);
            
            if (selectedCategories.length === 0) {
                showNotification('Vui lòng chọn ít nhất một danh mục', 'error');
                return;
            }
            
            let confirmMessage = '';
            switch (action) {
                case 'delete':
                    confirmMessage = `Bạn có chắc chắn muốn xóa ${selectedCategories.length} danh mục đã chọn?\n\nLưu ý: Không thể xóa danh mục có danh mục con hoặc có sản phẩm.`;
                    break;
                case 'feature':
                    confirmMessage = `Bạn có chắc chắn muốn đặt ${selectedCategories.length} danh mục làm nổi bật?`;
                    break;
                case 'top':
                    confirmMessage = `Bạn có chắc chắn muốn đặt ${selectedCategories.length} danh mục làm top?`;
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
                case 'feature':
                    ajaxAction = 'bulk_feature';
                    break;
                case 'top':
                    ajaxAction = 'bulk_top';
                    break;
            }
            
            const success = await makeRequest(ajaxAction, { 
                category_ids: JSON.stringify(selectedCategories) 
            });
            
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Export/Import functions
        function exportCategories() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', '1');
            window.open('?' + params.toString(), '_blank');
        }
        
        function importCategories() {
            window.location.href = 'category-import.php';
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
            console.log('🚀 Categories Management - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Categories Management - Ready!');
            console.log('📂 Category count:', <?php echo $total_categories; ?>);
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
            
            // Ctrl/Cmd + N for new category
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'category-edit.php';
            }
            
            // Ctrl/Cmd + S to toggle sort mode
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                toggleSortMode();
            }
        });
        
        // Auto-save filter preferences
        function saveFilterPreferences() {
            const preferences = {
                level: document.getElementById('level-filter').value,
                status: document.getElementById('status-filter').value
            };
            localStorage.setItem('categoryFilters', JSON.stringify(preferences));
        }
        
        function loadFilterPreferences() {
            const saved = localStorage.getItem('categoryFilters');
            if (saved) {
                const preferences = JSON.parse(saved);
                // Only apply if no URL parameters are set
                const urlParams = new URLSearchParams(window.location.search);
                if (!urlParams.has('level') && preferences.level !== undefined) {
                    document.getElementById('level-filter').value = preferences.level;
                }
                if (!urlParams.has('status') && preferences.status) {
                    document.getElementById('status-filter').value = preferences.status;
                }
            }
        }
        
        // Save preferences when filters change
        document.getElementById('level-filter').addEventListener('change', saveFilterPreferences);
        document.getElementById('status-filter').addEventListener('change', saveFilterPreferences);
        
        // Load preferences on page load
        loadFilterPreferences();
    </script>
</body>
</html>