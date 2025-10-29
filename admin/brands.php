<?php
/**
 * Admin Brands Page
 *
 * @refactored Uses centralized admin_init.php for authentication and helpers
 */

// Initialize admin page with authentication and admin info
require_once __DIR__ . '/../includes/admin_init.php';
$admin = initAdminPage(true, true);
$db = getDB();

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
    
    <link rel="stylesheet" href="../asset/css/pages/admin-brands.css">
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