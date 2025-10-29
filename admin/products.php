<?php
/**
 * Admin Products Page
 *
 * @refactored Uses centralized admin_init.php for authentication and helpers
 */

// Initialize admin page with authentication and admin info
require_once __DIR__ . '/../includes/admin_init.php';
$admin = initAdminPage(true, true);
$db = getDB();

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
            case 'delete_product':
                $product_id = (int)$_POST['product_id'];
                $stmt = $db->prepare("UPDATE products SET published = 0, approved = 0 WHERE id = ?");
                $stmt->execute([$product_id]);
                echo json_encode(['success' => true, 'message' => 'Sản phẩm đã được xóa']);
                break;
                
            case 'toggle_featured':
                $product_id = (int)$_POST['product_id'];
                $stmt = $db->prepare("UPDATE products SET featured = 1 - featured WHERE id = ?");
                $stmt->execute([$product_id]);
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật trạng thái nổi bật']);
                break;
                
            case 'toggle_status':
                $product_id = (int)$_POST['product_id'];
                $stmt = $db->prepare("UPDATE products SET published = 1 - published WHERE id = ?");
                $stmt->execute([$product_id]);
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật trạng thái sản phẩm']);
                break;
                
            case 'bulk_delete':
                $product_ids = json_decode($_POST['product_ids'], true);
                if (is_array($product_ids) && !empty($product_ids)) {
                    $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
                    $stmt = $db->prepare("UPDATE products SET published = 0, approved = 0 WHERE id IN ($placeholders)");
                    $stmt->execute($product_ids);
                    echo json_encode(['success' => true, 'message' => 'Đã xóa ' . count($product_ids) . ' sản phẩm']);
                }
                break;
                
            case 'bulk_feature':
                $product_ids = json_decode($_POST['product_ids'], true);
                if (is_array($product_ids) && !empty($product_ids)) {
                    $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
                    $stmt = $db->prepare("UPDATE products SET featured = 1 WHERE id IN ($placeholders)");
                    $stmt->execute($product_ids);
                    echo json_encode(['success' => true, 'message' => 'Đã đặt ' . count($product_ids) . ' sản phẩm làm nổi bật']);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("Products action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// Pagination and filtering
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$brand_filter = $_GET['brand'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$where_conditions = ['1=1'];
$params = [];

if (!empty($search)) {
    $where_conditions[] = '(p.name LIKE ? OR p.tags LIKE ? OR p.id = ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = $search;
}

if (!empty($category_filter)) {
    $where_conditions[] = 'p.category_id = ?';
    $params[] = $category_filter;
}

if (!empty($brand_filter)) {
    $where_conditions[] = 'p.brand_id = ?';
    $params[] = $brand_filter;
}

if (!empty($status_filter)) {
    switch ($status_filter) {
        case 'published':
            $where_conditions[] = 'p.published = 1 AND p.approved = 1';
            break;
        case 'draft':
            $where_conditions[] = 'p.published = 0';
            break;
        case 'pending':
            $where_conditions[] = 'p.approved = 0';
            break;
        case 'featured':
            $where_conditions[] = 'p.featured = 1';
            break;
        case 'low_stock':
            $where_conditions[] = 'p.current_stock <= p.low_stock_quantity';
            break;
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Valid sort columns
$valid_sorts = ['id', 'name', 'unit_price', 'current_stock', 'num_of_sale', 'created_at', 'updated_at'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'created_at';
}

$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Get products with pagination
$products = [];
$total_products = 0;

try {
    // Count total products
    $count_sql = "
        SELECT COUNT(*) as total
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_products = $stmt->fetch()['total'];
    
    // Get products
    $sql = "
        SELECT p.*,
               c.name as category_name,
               b.name as brand_name,
               u_thumb.file_name as thumbnail_url,
               COALESCE((SELECT AVG(rating) FROM reviews WHERE product_id = p.id), 0) as avg_rating,
               COALESCE((SELECT COUNT(*) FROM reviews WHERE product_id = p.id), 0) as review_count,
               CASE
                   WHEN p.current_stock <= p.low_stock_quantity THEN 1
                   ELSE 0
               END as is_low_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN uploads u_thumb ON p.thumbnail_img = u_thumb.id
        WHERE $where_clause
        ORDER BY p.$sort $order
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Products fetch error: " . $e->getMessage());
    $products = [];
}

// Get categories for filter
$categories = [];
try {
    $stmt = $db->query("SELECT id, name FROM categories WHERE level = 0 ORDER BY name ASC");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Categories fetch error: " . $e->getMessage());
}

// Get brands for filter
$brands = [];
try {
    $stmt = $db->query("SELECT id, name FROM brands ORDER BY name ASC");
    $brands = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Brands fetch error: " . $e->getMessage());
}

// Calculate pagination
$total_pages = ceil($total_products / $per_page);
$start_item = $offset + 1;
$end_item = min($offset + $per_page, $total_products);

// Product statistics
$stats = [];
try {
    // Total products
    $stmt = $db->query("SELECT COUNT(*) as count FROM products WHERE published = 1 AND approved = 1");
    $stats['total'] = $stmt->fetch()['count'];
    
    // Published products
    $stmt = $db->query("SELECT COUNT(*) as count FROM products WHERE published = 1");
    $stats['published'] = $stmt->fetch()['count'];
    
    // Draft products
    $stmt = $db->query("SELECT COUNT(*) as count FROM products WHERE published = 0");
    $stats['draft'] = $stmt->fetch()['count'];
    
    // Low stock products
    $stmt = $db->query("SELECT COUNT(*) as count FROM products WHERE current_stock <= low_stock_quantity AND published = 1");
    $stats['low_stock'] = $stmt->fetch()['count'];
    
    // Featured products
    $stmt = $db->query("SELECT COUNT(*) as count FROM products WHERE featured = 1 AND published = 1");
    $stats['featured'] = $stmt->fetch()['count'];
    
} catch (PDOException $e) {
    error_log("Product stats error: " . $e->getMessage());
    $stats = ['total' => 0, 'published' => 0, 'draft' => 0, 'low_stock' => 0, 'featured' => 0];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý sản phẩm - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý sản phẩm - Admin <?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-products.css">
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
                        <a href="products.php" class="nav-link active">
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
                            <span>Sản phẩm</span>
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
                    <h1 class="page-title">Quản lý sản phẩm</h1>
                    <p class="page-subtitle">Quản lý tất cả sản phẩm trong hệ thống</p>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Tổng sản phẩm</div>
                            <div class="stat-icon">🛍️</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Đã xuất bản</div>
                            <div class="stat-icon">✅</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['published']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Bản nháp</div>
                            <div class="stat-icon">📝</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['draft']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Sắp hết hàng</div>
                            <div class="stat-icon">⚠️</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['low_stock']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Nổi bật</div>
                            <div class="stat-icon">⭐</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['featured']); ?></div>
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
                                    placeholder="Tìm kiếm sản phẩm..."
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    id="search-input"
                                >
                            </div>
                        </div>
                        <div class="toolbar-right">
                            <a href="product-edit.php" class="btn btn-primary">
                                <span>➕</span>
                                <span>Thêm sản phẩm</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="toolbar-row">
                        <div class="toolbar-left">
                            <select class="filter-select" id="category-filter">
                                <option value="">Tất cả danh mục</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select class="filter-select" id="brand-filter">
                                <option value="">Tất cả thương hiệu</option>
                                <?php foreach ($brands as $brand): ?>
                                    <option value="<?php echo $brand['id']; ?>" <?php echo $brand_filter == $brand['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($brand['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select class="filter-select" id="status-filter">
                                <option value="">Tất cả trạng thái</option>
                                <option value="published" <?php echo $status_filter === 'published' ? 'selected' : ''; ?>>Đã xuất bản</option>
                                <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Bản nháp</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Chờ duyệt</option>
                                <option value="featured" <?php echo $status_filter === 'featured' ? 'selected' : ''; ?>>Nổi bật</option>
                                <option value="low_stock" <?php echo $status_filter === 'low_stock' ? 'selected' : ''; ?>>Sắp hết hàng</option>
                            </select>
                        </div>
                        <div class="toolbar-right">
                            <button class="btn btn-secondary" onclick="exportProducts()">
                                <span>📤</span>
                                <span>Xuất file</span>
                            </button>
                            <button class="btn btn-secondary" onclick="importProducts()">
                                <span>📥</span>
                                <span>Nhập file</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Bulk Actions -->
                <div class="bulk-actions" id="bulk-actions">
                    <span class="bulk-count" id="bulk-count">0 sản phẩm được chọn</span>
                    <button class="btn btn-success btn-sm" onclick="bulkAction('feature')">
                        <span>⭐</span>
                        <span>Đặt nổi bật</span>
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="bulkAction('publish')">
                        <span>✅</span>
                        <span>Xuất bản</span>
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="bulkAction('delete')">
                        <span>🗑️</span>
                        <span>Xóa</span>
                    </button>
                </div>
                
                <!-- Products Table -->
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
                                <th>Sản phẩm</th>
                                <th class="sortable <?php echo $sort === 'unit_price' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="unit_price">
                                    Giá
                                </th>
                                <th class="sortable <?php echo $sort === 'current_stock' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="current_stock">
                                    Kho
                                </th>
                                <th class="sortable <?php echo $sort === 'num_of_sale' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="num_of_sale">
                                    Đã bán
                                </th>
                                <th>Đánh giá</th>
                                <th>Trạng thái</th>
                                <th class="sortable <?php echo $sort === 'created_at' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="created_at">
                                    Ngày tạo
                                </th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr data-product-id="<?php echo $product['id']; ?>">
                                    <td>
                                        <input type="checkbox" class="checkbox product-checkbox" value="<?php echo $product['id']; ?>">
                                    </td>
                                    <td>
                                        <span class="product-id">#<?php echo $product['id']; ?></span>
                                    </td>
                                    <td>
                                        <div class="product-info">
                                            <img 
                                                src="<?php echo !empty($product['thumbnail_url']) && file_exists('../' . $product['thumbnail_url']) ? '../' . htmlspecialchars($product['thumbnail_url']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 60 60"><rect width="60" height="60" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="24" fill="%236b7280">📦</text></svg>'; ?>" 
                                                alt="<?php echo htmlspecialchars($product['name']); ?>"
                                                class="product-image"
                                                loading="lazy"
                                            >
                                            <div class="product-details">
                                                <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                                <div class="product-meta">
                                                    <?php if ($product['category_name']): ?>
                                                        <span>📂 <?php echo htmlspecialchars($product['category_name']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($product['brand_name']): ?>
                                                        <span>🏷️ <?php echo htmlspecialchars($product['brand_name']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo formatCurrency($product['unit_price']); ?></strong>
                                        <?php if ($product['discount'] > 0): ?>
                                            <br>
                                            <small style="color: var(--secondary);">
                                                -<?php echo $product['discount_type'] === 'percent' ? $product['discount'] . '%' : formatCurrency($product['discount']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="stock-indicator">
                                            <span class="stock-value <?php echo $product['is_low_stock'] ? 'stock-danger' : ($product['current_stock'] < 20 ? 'stock-warning' : 'stock-good'); ?>">
                                                <?php echo number_format($product['current_stock']); ?>
                                            </span>
                                            <?php if ($product['is_low_stock']): ?>
                                                <span class="status-badge low-stock">Sắp hết</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format($product['num_of_sale']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($product['review_count'] > 0): ?>
                                            <div class="rating-display">
                                                <span class="rating-stars">
                                                    <?php
                                                    $rating = $product['avg_rating'];
                                                    for ($i = 1; $i <= 5; $i++) {
                                                        echo $i <= $rating ? '★' : '☆';
                                                    }
                                                    ?>
                                                </span>
                                                <span class="rating-count">(<?php echo $product['review_count']; ?>)</span>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-tertiary">Chưa có đánh giá</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: var(--space-1);">
                                            <?php if ($product['published'] && $product['approved']): ?>
                                                <span class="status-badge published">Đã xuất bản</span>
                                            <?php elseif (!$product['published']): ?>
                                                <span class="status-badge draft">Bản nháp</span>
                                            <?php else: ?>
                                                <span class="status-badge pending">Chờ duyệt</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($product['featured']): ?>
                                                <span class="status-badge featured">Nổi bật</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime($product['created_at'])); ?>
                                        <br>
                                        <small style="color: var(--text-tertiary);">
                                            <?php echo date('H:i', strtotime($product['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn edit" onclick="editProduct(<?php echo $product['id']; ?>)" title="Chỉnh sửa">
                                                ✏️
                                            </button>
                                            <button class="action-btn feature <?php echo $product['featured'] ? 'active' : ''; ?>" onclick="toggleFeatured(<?php echo $product['id']; ?>)" title="<?php echo $product['featured'] ? 'Bỏ nổi bật' : 'Đặt nổi bật'; ?>">
                                                <?php echo $product['featured'] ? '⭐' : '☆'; ?>
                                            </button>
                                            <button class="action-btn delete" onclick="deleteProduct(<?php echo $product['id']; ?>)" title="Xóa">
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
                        Hiển thị <?php echo number_format($start_item); ?>-<?php echo number_format($end_item); ?> trong tổng số <?php echo number_format($total_products); ?> sản phẩm
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
        document.getElementById('category-filter').addEventListener('change', updateFilters);
        document.getElementById('brand-filter').addEventListener('change', updateFilters);
        document.getElementById('status-filter').addEventListener('change', updateFilters);
        
        function updateFilters() {
            const params = new URLSearchParams();
            
            const search = searchInput.value.trim();
            if (search) params.set('search', search);
            
            const category = document.getElementById('category-filter').value;
            if (category) params.set('category', category);
            
            const brand = document.getElementById('brand-filter').value;
            if (brand) params.set('brand', brand);
            
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
        const productCheckboxes = document.querySelectorAll('.product-checkbox');
        const bulkActions = document.getElementById('bulk-actions');
        const bulkCount = document.getElementById('bulk-count');
        
        selectAllCheckbox.addEventListener('change', function() {
            productCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });
        
        productCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateBulkActions();
                
                // Update select all checkbox
                const checkedCount = document.querySelectorAll('.product-checkbox:checked').length;
                selectAllCheckbox.checked = checkedCount === productCheckboxes.length;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < productCheckboxes.length;
            });
        });
        
        function updateBulkActions() {
            const selectedProducts = document.querySelectorAll('.product-checkbox:checked');
            const count = selectedProducts.length;
            
            if (count > 0) {
                bulkActions.classList.add('show');
                bulkCount.textContent = `${count} sản phẩm được chọn`;
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
        
        // Product actions
        function editProduct(productId) {
            window.location.href = `product-edit.php?id=${productId}`;
        }
        
        async function deleteProduct(productId) {
            if (!confirm('Bạn có chắc chắn muốn xóa sản phẩm này?')) {
                return;
            }
            
            const success = await makeRequest('delete_product', { product_id: productId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function toggleFeatured(productId) {
            const success = await makeRequest('toggle_featured', { product_id: productId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function toggleStatus(productId) {
            const success = await makeRequest('toggle_status', { product_id: productId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Bulk actions
        async function bulkAction(action) {
            const selectedProducts = Array.from(document.querySelectorAll('.product-checkbox:checked'))
                .map(checkbox => checkbox.value);
            
            if (selectedProducts.length === 0) {
                showNotification('Vui lòng chọn ít nhất một sản phẩm', 'error');
                return;
            }
            
            let confirmMessage = '';
            switch (action) {
                case 'delete':
                    confirmMessage = `Bạn có chắc chắn muốn xóa ${selectedProducts.length} sản phẩm đã chọn?`;
                    break;
                case 'feature':
                    confirmMessage = `Bạn có chắc chắn muốn đặt ${selectedProducts.length} sản phẩm làm nổi bật?`;
                    break;
                case 'publish':
                    confirmMessage = `Bạn có chắc chắn muốn xuất bản ${selectedProducts.length} sản phẩm đã chọn?`;
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
                case 'publish':
                    ajaxAction = 'bulk_publish';
                    break;
            }
            
            const success = await makeRequest(ajaxAction, { 
                product_ids: JSON.stringify(selectedProducts) 
            });
            
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Export/Import functions
        function exportProducts() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', '1');
            window.open('?' + params.toString(), '_blank');
        }
        
        function importProducts() {
            // This would open an import modal or redirect to import page
            window.location.href = 'product-import.php';
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
            console.log('🚀 Products Management - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Products Management - Ready!');
            console.log('📊 Product count:', <?php echo $total_products; ?>);
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
            
            // Ctrl/Cmd + N for new product
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'product-edit.php';
            }
        });
        
        // Auto-save filter preferences
        function saveFilterPreferences() {
            const preferences = {
                category: document.getElementById('category-filter').value,
                brand: document.getElementById('brand-filter').value,
                status: document.getElementById('status-filter').value
            };
            localStorage.setItem('productFilters', JSON.stringify(preferences));
        }
        
        function loadFilterPreferences() {
            const saved = localStorage.getItem('productFilters');
            if (saved) {
                const preferences = JSON.parse(saved);
                // Only apply if no URL parameters are set
                const urlParams = new URLSearchParams(window.location.search);
                if (!urlParams.has('category') && preferences.category) {
                    document.getElementById('category-filter').value = preferences.category;
                }
                if (!urlParams.has('brand') && preferences.brand) {
                    document.getElementById('brand-filter').value = preferences.brand;
                }
                if (!urlParams.has('status') && preferences.status) {
                    document.getElementById('status-filter').value = preferences.status;
                }
            }
        }
        
        // Save preferences when filters change
        document.getElementById('category-filter').addEventListener('change', saveFilterPreferences);
        document.getElementById('brand-filter').addEventListener('change', saveFilterPreferences);
        document.getElementById('status-filter').addEventListener('change', saveFilterPreferences);
        
        // Load preferences on page load
        loadFilterPreferences();
    </script>
</body>
</html>