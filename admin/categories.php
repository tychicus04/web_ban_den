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
    
    <link rel="stylesheet" href="../asset/css/pages/admin-categories.css">
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