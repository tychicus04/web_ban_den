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
$session_timeout = 8 * 60 * 60;
if (isset($_SESSION['admin_login_time']) && (time() - $_SESSION['admin_login_time']) > $session_timeout) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}

// CSRF token validation
if (!isset($_SESSION['admin_token'])) {
    $_SESSION['admin_token'] = bin2hex(random_bytes(32));
}

// Get product ID from URL
$product_id = (int)($_GET['id'] ?? 0);
if (!$product_id) {
    header('Location: products.php?error=invalid_id');
    exit;
}

// Helper Functions
function formatCurrency($amount, $currency = 'VND') {
    if ($currency === 'VND') {
        return number_format($amount, 0, ',', '.') . '₫';
    }
    return '$' . number_format($amount, 2, '.', ',');
}

function getProductImageUrl($filename) {
    if (empty($filename)) {
        return getPlaceholderImage();
    }
    
    $image_path = '../uploads/all/' . $filename;
    if (file_exists($image_path)) {
        return $image_path;
    }
    
    return getPlaceholderImage();
}

function getPlaceholderImage() {
    return 'data:image/svg+xml;base64,' . base64_encode('
        <svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400">
            <rect width="400" height="400" fill="#f3f4f6"/>
            <text x="200" y="200" text-anchor="middle" font-family="Arial" font-size="48" fill="#6b7280">📦</text>
            <text x="200" y="250" text-anchor="middle" font-family="Arial" font-size="16" fill="#9ca3af">No Image</text>
        </svg>
    ');
}

function getStatusBadge($published, $approved) {
    if ($published && $approved) {
        return '<span class="badge badge-success">Đã xuất bản</span>';
    } elseif ($published && !$approved) {
        return '<span class="badge badge-warning">Chờ duyệt</span>';
    }
    return '<span class="badge badge-secondary">Bản nháp</span>';
}

function getStockStatus($current_stock, $low_stock_qty) {
    if ($current_stock <= 0) {
        return ['status' => 'out', 'text' => 'Hết hàng', 'class' => 'badge-danger'];
    } elseif ($current_stock <= $low_stock_qty) {
        return ['status' => 'low', 'text' => 'Sắp hết', 'class' => 'badge-warning'];
    }
    return ['status' => 'in', 'text' => 'Còn hàng', 'class' => 'badge-success'];
}

function timeAgo($datetime) {
     
    if (empty($datetime)) {
        return 'không xác định';
    }

    $time = time() - strtotime($datetime);

    if ($time < 60) return 'vừa xong';
    if ($time < 3600) return floor($time/60) . ' phút trước';
    if ($time < 86400) return floor($time/3600) . ' giờ trước';
    if ($time < 2592000) return floor($time/86400) . ' ngày trước';
    if ($time < 31104000) return floor($time/2592000) . ' tháng trước';
    return floor($time/31104000) . ' năm trước';
}

// Get product details
$product = null;
try {
    $stmt = $db->prepare("
        SELECT p.*, 
               c.name as category_name,
               b.name as brand_name,
               u.name as seller_name,
               up.file_name as thumbnail_url,
               COALESCE((SELECT AVG(rating) FROM reviews WHERE product_id = p.id), 0) as avg_rating,
               (SELECT COUNT(*) FROM reviews WHERE product_id = p.id) as review_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id  
        LEFT JOIN users u ON p.user_id = u.id
        LEFT JOIN uploads up ON p.thumbnail_img = up.id
        WHERE p.id = ? LIMIT 1
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
    
    if (!$product) {
        header('Location: products.php?error=not_found');
        exit;
    }
} catch (PDOException $e) {
    error_log("Product fetch error: " . $e->getMessage());
    header('Location: products.php?error=database_error');
    exit;
}

// Get product images
$product_images = [];
try {
    if (!empty($product['photos'])) {
        $photo_ids = json_decode($product['photos'], true);
        if (is_array($photo_ids) && !empty($photo_ids)) {
            $placeholders = str_repeat('?,', count($photo_ids) - 1) . '?';
            $stmt = $db->prepare("SELECT file_name FROM uploads WHERE id IN ($placeholders)");
            $stmt->execute($photo_ids);
            $product_images = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
} catch (PDOException $e) {
    error_log("Product images fetch error: " . $e->getMessage());
}

// Get product variants/stocks
$product_stocks = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM product_stocks 
        WHERE product_id = ? 
        ORDER BY variant
    ");
    $stmt->execute([$product_id]);
    $product_stocks = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Product stocks fetch error: " . $e->getMessage());
}

// Get recent orders
$recent_orders = [];
try {
    $stmt = $db->prepare("
        SELECT od.*, o.created_at, o.payment_status, u.name as customer_name
        FROM order_details od
        LEFT JOIN orders o ON od.order_id = o.id
        LEFT JOIN users u ON o.user_id = u.id
        WHERE od.product_id = ?
        ORDER BY o.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$product_id]);
    $recent_orders = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Recent orders fetch error: " . $e->getMessage());
}

// Get reviews
$reviews = [];
try {
    $stmt = $db->prepare("
        SELECT r.*, u.name as customer_name
        FROM reviews r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.product_id = ?
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$product_id]);
    $reviews = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Reviews fetch error: " . $e->getMessage());
}

// Get sales statistics
$sales_stats = [];
try {
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(quantity) as total_sold,
            SUM(price * quantity) as total_revenue,
            AVG(price) as avg_order_price
        FROM order_details 
        WHERE product_id = ?
    ");
    $stmt->execute([$product_id]);
    $sales_stats = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Sales stats fetch error: " . $e->getMessage());
    $sales_stats = ['total_orders' => 0, 'total_sold' => 0, 'total_revenue' => 0, 'avg_order_price' => 0];
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
} catch (PDOException $e) {
    error_log("Admin fetch error: " . $e->getMessage());
}

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

$site_name = getBusinessSetting($db, 'site_name', 'E-Commerce Admin');
$stock_status = getStockStatus($product['current_stock'], $product['low_stock_quantity'] ?? 0);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chi tiết sản phẩm: <?php echo htmlspecialchars($product['name']); ?> - <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-product-view.css">
</head>

<body>
    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <div class="logo-icon">A</div>
                    <div class="logo-text">Admin</div>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tổng quan</div>
                    <a href="dashboard.php" class="nav-link">
                        <span class="nav-icon">📊</span>
                        <span>Dashboard</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Bán hàng</div>
                    <a href="orders.php" class="nav-link">
                        <span class="nav-icon">📦</span>
                        <span>Đơn hàng</span>
                    </a>
                    <a href="products.php" class="nav-link active">
                        <span class="nav-icon">🛍️</span>
                        <span>Sản phẩm</span>
                    </a>
                    <a href="categories.php" class="nav-link">
                        <span class="nav-icon">📂</span>
                        <span>Danh mục</span>
                    </a>
                    <a href="brands.php" class="nav-link">
                        <span class="nav-icon">🏷️</span>
                        <span>Thương hiệu</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Khách hàng</div>
                    <a href="users.php" class="nav-link">
                        <span class="nav-icon">👥</span>
                        <span>Người dùng</span>
                    </a>
                    <a href="reviews.php" class="nav-link">
                        <span class="nav-icon">⭐</span>
                        <span>Đánh giá</span>
                    </a>
                </div>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <button class="btn btn-secondary" id="sidebar-toggle">☰</button>
                    <nav class="breadcrumb">
                        <a href="dashboard.php">Admin</a>
                        <span class="breadcrumb-separator">›</span>
                        <a href="products.php">Sản phẩm</a>
                        <span class="breadcrumb-separator">›</span>
                        <span>Chi tiết</span>
                    </nav>
                </div>
                
                <div class="header-right">
                    <div class="header-actions">
                        <a href="products.php" class="btn btn-secondary">
                            ← Quay lại
                        </a>
                        <a href="product-edit.php?id=<?php echo $product['id']; ?>" class="btn btn-primary">
                            ✏️ Chỉnh sửa
                        </a>
                    </div>
                    <div class="user-menu">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($admin['name'] ?? 'A', 0, 2)); ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($admin['name'] ?? 'Admin'); ?></div>
                            <div class="user-role">Administrator</div>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- Content -->
            <div class="content">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title"><?php echo htmlspecialchars($product['name']); ?></h1>
                    <p class="page-subtitle">Chi tiết đầy đủ về sản phẩm</p>
                </div>
                
                <!-- Product Grid -->
                <div class="product-grid">
                    <!-- Main Content -->
                    <div class="product-main">
                        <!-- Product Images -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Hình ảnh sản phẩm</h3>
                            </div>
                            <div class="card-content">
                                <div class="product-images">
                                    <img 
                                        src="<?php echo getProductImageUrl($product['thumbnail_url']); ?>" 
                                        alt="<?php echo htmlspecialchars($product['name']); ?>"
                                        class="main-image"
                                        id="mainImage"
                                    >
                                    
                                    <?php if (!empty($product_images)): ?>
                                        <div class="image-gallery">
                                            <img 
                                                src="<?php echo getProductImageUrl($product['thumbnail_url']); ?>" 
                                                alt="Main"
                                                class="gallery-thumb active"
                                                onclick="changeMainImage(this.src)"
                                            >
                                            <?php foreach ($product_images as $image): ?>
                                                <img 
                                                    src="<?php echo getProductImageUrl($image); ?>" 
                                                    alt="Gallery"
                                                    class="gallery-thumb"
                                                    onclick="changeMainImage(this.src)"
                                                >
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Product Info -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Thông tin sản phẩm</h3>
                            </div>
                            <div class="card-content">
                                <div class="product-info">
                                    <div class="product-meta">
                                        <span class="product-id">ID: <?php echo $product['id']; ?></span>
                                        <?php echo getStatusBadge($product['published'], $product['approved']); ?>
                                        <span class="badge <?php echo $stock_status['class']; ?>">
                                            <?php echo $stock_status['text']; ?>
                                        </span>
                                        <?php if ($product['featured']): ?>
                                            <span class="badge badge-primary">Nổi bật</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="product-price">
                                        <?php if ($product['discount'] > 0): ?>
                                            <div class="price-original"><?php echo formatCurrency($product['unit_price']); ?></div>
                                            <div class="price-current">
                                                <?php 
                                                    $sale_price = $product['discount_type'] === 'percent' 
                                                        ? $product['unit_price'] * (1 - $product['discount'] / 100)
                                                        : $product['unit_price'] - $product['discount'];
                                                    echo formatCurrency($sale_price);
                                                ?>
                                            </div>
                                            <div class="price-discount">
                                                Giảm <?php echo $product['discount_type'] === 'percent' ? $product['discount'] . '%' : formatCurrency($product['discount']); ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="price-current"><?php echo formatCurrency($product['unit_price']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <div class="info-label">Danh mục</div>
                                            <div class="info-value"><?php echo htmlspecialchars($product['category_name'] ?? 'Chưa phân loại'); ?></div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Thương hiệu</div>
                                            <div class="info-value"><?php echo htmlspecialchars($product['brand_name'] ?? 'Không có'); ?></div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Đơn vị</div>
                                            <div class="info-value"><?php echo htmlspecialchars($product['unit'] ?? 'Chiếc'); ?></div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Cân nặng</div>
                                            <div class="info-value"><?php echo $product['weight'] > 0 ? $product['weight'] . ' kg' : 'Chưa cập nhật'; ?></div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Số lượng tối thiểu</div>
                                            <div class="info-value"><?php echo number_format($product['min_qty']); ?></div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Ngày tạo</div>
                                            <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($product['created_at'])); ?></div>
                                        </div>
                                        <div class="info-item">
                                            <div class="info-label">Cập nhật lần cuối</div>
                                            <div class="info-value"><?php echo timeAgo($product['updated_at']); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Product Description -->
                        <?php if (!empty($product['description'])): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Mô tả sản phẩm</h3>
                                </div>
                                <div class="card-content">
                                    <div class="product-description">
                                        <?php echo nl2br(htmlspecialchars($product['description'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Product Variants -->
                        <?php if (!empty($product_stocks)): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Biến thể sản phẩm</h3>
                                </div>
                                <div class="card-content">
                                    <div class="table-container">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Biến thể</th>
                                                    <th>SKU</th>
                                                    <th>Giá</th>
                                                    <th>Tồn kho</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($product_stocks as $stock): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($stock['variant']); ?></td>
                                                        <td><?php echo htmlspecialchars($stock['sku'] ?? 'N/A'); ?></td>
                                                        <td><?php echo formatCurrency($stock['price']); ?></td>
                                                        <td><?php echo number_format($stock['qty']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Sidebar -->
                    <div class="product-sidebar">
                        <!-- Sales Statistics -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Thống kê bán hàng</h3>
                            </div>
                            <div class="card-content">
                                <div class="stats-grid">
                                    <div class="stat-card primary">
                                        <div class="stat-number"><?php echo number_format($sales_stats['total_orders']); ?></div>
                                        <div class="stat-label">Tổng đơn hàng</div>
                                    </div>
                                    <div class="stat-card success">
                                        <div class="stat-number"><?php echo number_format($sales_stats['total_sold']); ?></div>
                                        <div class="stat-label">Đã bán</div>
                                    </div>
                                </div>
                                
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Doanh thu</div>
                                        <div class="info-value"><?php echo formatCurrency($sales_stats['total_revenue']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Giá TB/đơn</div>
                                        <div class="info-value"><?php echo formatCurrency($sales_stats['avg_order_price']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Stock Info -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Thông tin kho</h3>
                            </div>
                            <div class="card-content">
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Tồn kho hiện tại</div>
                                        <div class="info-value">
                                            <span style="color: var(--<?php echo $stock_status['status'] === 'out' ? 'danger' : ($stock_status['status'] === 'low' ? 'warning' : 'success'); ?>); font-weight: 600;">
                                                <?php echo number_format($product['current_stock']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Cảnh báo hết hàng</div>
                                        <div class="info-value"><?php echo number_format($product['low_stock_quantity'] ?? 0); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Rating & Reviews -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Đánh giá</h3>
                            </div>
                            <div class="card-content">
                                <div class="rating">
                                    <span class="rating-stars">⭐</span>
                                    <span class="rating-number"><?php echo number_format($product['avg_rating'], 1); ?></span>
                                    <span class="rating-count">(<?php echo $product['review_count']; ?> đánh giá)</span>
                                </div>
                                
                                <?php if (!empty($reviews)): ?>
                                    <div style="margin-top: 1rem;">
                                        <?php foreach (array_slice($reviews, 0, 3) as $review): ?>
                                            <div class="review-item">
                                                <div class="review-header">
                                                    <span class="review-author"><?php echo htmlspecialchars($review['customer_name'] ?? 'Khách hàng'); ?></span>
                                                    <span class="review-date"><?php echo timeAgo($review['created_at']); ?></span>
                                                </div>
                                                <div class="review-rating">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <span class="review-star <?php echo $i <= $review['rating'] ? '' : 'empty'; ?>">★</span>
                                                    <?php endfor; ?>
                                                </div>
                                                <div class="review-comment"><?php echo htmlspecialchars($review['comment']); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <?php if (count($reviews) > 3): ?>
                                            <div style="text-align: center; margin-top: 1rem;">
                                                <a href="reviews.php?product_id=<?php echo $product['id']; ?>" class="btn btn-secondary btn-sm">
                                                    Xem tất cả đánh giá
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <div class="empty-icon">💬</div>
                                        <p>Chưa có đánh giá nào</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Orders -->
                <?php if (!empty($recent_orders)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Đơn hàng gần đây</h3>
                        </div>
                        <div class="card-content">
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Đơn hàng</th>
                                            <th>Khách hàng</th>
                                            <th>Số lượng</th>
                                            <th>Giá</th>
                                            <th>Trạng thái</th>
                                            <th>Ngày</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_orders as $order): ?>
                                            <tr>
                                                <td>#<?php echo $order['order_id']; ?></td>
                                                <td><?php echo htmlspecialchars($order['customer_name'] ?? 'Khách vãng lai'); ?></td>
                                                <td><?php echo number_format($order['quantity']); ?></td>
                                                <td><?php echo formatCurrency($order['price']); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $order['payment_status'] === 'paid' ? 'badge-success' : 'badge-warning'; ?>">
                                                        <?php echo $order['payment_status'] === 'paid' ? 'Đã thanh toán' : 'Chờ thanh toán'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo timeAgo($order['created_at']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            initializeProductView();
        });
        
        function initializeProductView() {
            // Sidebar toggle
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebar-toggle');
            
            sidebarToggle?.addEventListener('click', function() {
                if (window.innerWidth > 1024) {
                    sidebar.classList.toggle('collapsed');
                } else {
                    sidebar.classList.toggle('open');
                }
            });
            
            // Close sidebar on mobile when clicking outside
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 1024 && 
                    !sidebar.contains(e.target) && 
                    !sidebarToggle?.contains(e.target) &&
                    sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                }
            });
            
            console.log('✅ Product view initialized');
        }
        
        function changeMainImage(src) {
            const mainImage = document.getElementById('mainImage');
            const galleryThumbs = document.querySelectorAll('.gallery-thumb');
            
            if (mainImage) {
                mainImage.src = src;
            }
            
            // Update active thumbnail
            galleryThumbs.forEach(thumb => {
                thumb.classList.remove('active');
                if (thumb.src === src) {
                    thumb.classList.add('active');
                }
            });
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to go back
            if (e.key === 'Escape') {
                window.location.href = 'products.php';
            }
            
            // E to edit
            if (e.key === 'e' || e.key === 'E') {
                if (!e.target.matches('input, textarea')) {
                    window.location.href = 'product-edit.php?id=<?php echo $product['id']; ?>';
                }
            }
        });
        
        console.log('👁️ Product view loaded successfully');
    </script>
</body>
</html>