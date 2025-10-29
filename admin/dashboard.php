<?php
// Initialize admin page with authentication and admin info
require_once __DIR__ . '/../includes/admin_init.php';
$admin = initAdminPage(true, true);
$db = getDB(); // Get database connection

// Get dashboard stats
$stats = [
    'total_users' => 0,
    'total_orders' => 0, 
    'total_products' => 0, 
    'total_revenue' => 0,
    'orders_today' => 0,
    'revenue_today' => 0,
    'pending_orders' => 0,
    'low_stock' => 0
];

try {
    // Total users
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'customer'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_users'] = $result ? (int)$result['count'] : 0;
    
    // Total orders
    $stmt = $db->query("SELECT COUNT(*) as count FROM orders");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_orders'] = $result ? (int)$result['count'] : 0;
    
    // Total products
    $stmt = $db->query("SELECT COUNT(*) as count FROM products WHERE published = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_products'] = $result ? (int)$result['count'] : 0;
    
    // Total revenue
    $stmt = $db->query("SELECT SUM(grand_total) as total FROM orders WHERE payment_status = 'paid'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_revenue'] = $result && $result['total'] ? (float)$result['total'] : 0;
    
    // Orders today
    $today_start = date('Y-m-d 00:00:00');
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE created_at >= ?");
    $stmt->execute([$today_start]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['orders_today'] = $result ? (int)$result['count'] : 0;
    
    // Revenue today
    $stmt = $db->prepare("SELECT SUM(grand_total) as total FROM orders WHERE payment_status = 'paid' AND created_at >= ?");
    $stmt->execute([$today_start]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['revenue_today'] = $result && $result['total'] ? (float)$result['total'] : 0;
    
    // Pending orders
    $stmt = $db->query("SELECT COUNT(*) as count FROM orders WHERE delivery_status = 'pending'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['pending_orders'] = $result ? (int)$result['count'] : 0;
    
    // Low stock products
    $stmt = $db->query("SELECT COUNT(*) as count FROM products WHERE current_stock <= low_stock_quantity AND published = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['low_stock'] = $result ? (int)$result['count'] : 0;
    
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
}

// Get recent orders
$recent_orders = [];
try {
    $sql = "
        SELECT o.*, 
               u.name as customer_name,
               u.email as customer_email,
               u.avatar as customer_avatar
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        ORDER BY o.created_at DESC
        LIMIT 10
    ";
    
    $stmt = $db->query($sql);
    $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Recent orders fetch error: " . $e->getMessage());
}

// Get top products
$top_products = [];
try {
    $sql = "
        SELECT p.*, 
               c.name as category_name,
               u_thumb.file_name as thumbnail_url,
               COUNT(od.id) as order_count,
               SUM(od.quantity) as total_quantity
        FROM products p
        LEFT JOIN order_details od ON p.id = od.product_id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN uploads u_thumb ON p.thumbnail_img = u_thumb.id
        WHERE p.published = 1
        GROUP BY p.id
        ORDER BY total_quantity DESC
        LIMIT 5
    ";
    
    $stmt = $db->query($sql);
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Top products fetch error: " . $e->getMessage());
}

// Get monthly sales data for chart
$monthly_sales = [];
try {
    $sql = "
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as order_count,
            SUM(grand_total) as revenue
        FROM orders
        WHERE payment_status = 'paid'
        AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ";
    
    $stmt = $db->query($sql);
    $monthly_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Monthly sales fetch error: " . $e->getMessage());
}

// Get recent activities
$recent_activities = [];
try {
    // Orders
    $sql_orders = "
        SELECT 
            'order' as type,
            id as item_id,
            code as title,
            CONCAT('Đơn hàng mới #', code) as description,
            created_at as timestamp
        FROM orders
        ORDER BY created_at DESC
        LIMIT 5
    ";
    
    // Product reviews
    $sql_reviews = "
        SELECT 
            'review' as type,
            r.id as item_id,
            p.name as title,
            CONCAT('Đánh giá mới: ', SUBSTRING(r.comment, 1, 50), IF(LENGTH(r.comment) > 50, '...', '')) as description,
            r.created_at as timestamp
        FROM reviews r
        JOIN products p ON r.product_id = p.id
        ORDER BY r.created_at DESC
        LIMIT 5
    ";
    
    // New users
    $sql_users = "
        SELECT 
            'user' as type,
            id as item_id,
            name as title,
            'Người dùng mới đăng ký' as description,
            created_at as timestamp
        FROM users
        WHERE user_type = 'customer'
        ORDER BY created_at DESC
        LIMIT 5
    ";
    
    // Combine and sort
    $stmt_orders = $db->query($sql_orders);
    $stmt_reviews = $db->query($sql_reviews);
    $stmt_users = $db->query($sql_users);
    
    $activities = array_merge(
        $stmt_orders->fetchAll(PDO::FETCH_ASSOC),
        $stmt_reviews->fetchAll(PDO::FETCH_ASSOC),
        $stmt_users->fetchAll(PDO::FETCH_ASSOC)
    );
    
    // Sort by timestamp descending
    usort($activities, function($a, $b) {
        $time_a = !empty($a['timestamp']) ? strtotime($a['timestamp']) : 0;
        $time_b = !empty($b['timestamp']) ? strtotime($b['timestamp']) : 0;
        return $time_b - $time_a;
    });
    
    // Take the latest 10
    $recent_activities = array_slice($activities, 0, 10);
    
} catch (PDOException $e) {
    error_log("Recent activities fetch error: " . $e->getMessage());
}

$site_name = getBusinessSetting($db, 'site_name', 'Active E-Commerce');

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
                
            default:
                echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ']);
        }
    } catch (Exception $e) {
        error_log("AJAX action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Admin <?php echo safe_echo($site_name); ?></title>
    <meta name="description" content="Bảng điều khiển quản trị - Admin <?php echo safe_echo($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-dashboard.css">
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
                        <a href="dashboard.php" class="nav-link active">
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
                            <span>Dashboard</span>
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
                    <h1 class="page-title">Tổng quan hệ thống</h1>
                    <p class="page-subtitle">Chào mừng trở lại, <?php echo safe_echo(get_value($admin, 'name', 'Admin')); ?>! Đây là tổng quan về hoạt động của hệ thống.</p>
                </div>
                
                <!-- Main Stats -->
                <div class="main-stats">
                    <div class="stat-card primary">
                        <div class="stat-header">
                            <div class="stat-title">Tổng doanh thu</div>
                            <div class="stat-icon">💰</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($stats['total_revenue']); ?></div>
                        <div class="stat-comparison positive">
                            <span>↑</span>
                            <span>Hôm nay: <?php echo formatCurrency($stats['revenue_today']); ?></span>
                        </div>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-title">Tổng đơn hàng</div>
                            <div class="stat-icon">📦</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_orders']); ?></div>
                        <div class="stat-comparison positive">
                            <span>↑</span>
                            <span>Hôm nay: <?php echo number_format($stats['orders_today']); ?></span>
                        </div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-title">Tổng sản phẩm</div>
                            <div class="stat-icon">🛍️</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_products']); ?></div>
                        <div class="stat-comparison <?php echo $stats['low_stock'] > 0 ? 'negative' : 'positive'; ?>">
                            <span><?php echo $stats['low_stock'] > 0 ? '⚠️' : '✓'; ?></span>
                            <span><?php echo $stats['low_stock'] > 0 ? number_format($stats['low_stock']) . ' sản phẩm sắp hết hàng' : 'Tồn kho ổn định'; ?></span>
                        </div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-title">Khách hàng</div>
                            <div class="stat-icon">👥</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                        <div class="stat-comparison warning">
                            <span>⚠️</span>
                            <span><?php echo number_format($stats['pending_orders']); ?> đơn hàng đang chờ xử lý</span>
                        </div>
                    </div>
                </div>
                
                <!-- Dashboard Grid -->
                <div class="dashboard-grid">
                    <!-- Sales Chart -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-header">
                            <h2 class="dashboard-card-title">Doanh số theo tháng</h2>
                            <div class="dashboard-card-actions">
                                <button class="btn btn-sm btn-secondary" id="export-report">
                                    <span>📥</span>
                                    <span>Xuất báo cáo</span>
                                </button>
                            </div>
                        </div>
                        <div class="dashboard-card-body sales-chart">
                            <div class="chart-container" id="sales-chart">
                                <!-- Chart will be rendered here by JavaScript -->
                                <canvas id="sales-canvas" width="100%" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Activity -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-header">
                            <h2 class="dashboard-card-title">Hoạt động gần đây</h2>
                        </div>
                        <div class="dashboard-card-body">
                            <div class="activity-list">
                                <?php if (!empty($recent_activities)): ?>
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon <?php echo safe_echo(get_value($activity, 'type', '')); ?>">
                                                <?php 
                                                switch (get_value($activity, 'type', '')) {
                                                    case 'order':
                                                        echo '📦';
                                                        break;
                                                    case 'review':
                                                        echo '⭐';
                                                        break;
                                                    case 'user':
                                                        echo '👤';
                                                        break;
                                                    default:
                                                        echo '📝';
                                                }
                                                ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-title"><?php echo safe_echo(get_value($activity, 'title', '')); ?></div>
                                                <div class="activity-description"><?php echo safe_echo(get_value($activity, 'description', '')); ?></div>
                                                <div class="activity-time">
                                                    <?php
                                                    if (has_value($activity, 'timestamp')) {
                                                        $timestamp = strtotime($activity['timestamp']);
                                                        $now = time();
                                                        $diff = $now - $timestamp;
                                                        
                                                        if ($diff < 60) {
                                                            echo 'Vừa xong';
                                                        } elseif ($diff < 3600) {
                                                            echo floor($diff / 60) . ' phút trước';
                                                        } elseif ($diff < 86400) {
                                                            echo floor($diff / 3600) . ' giờ trước';
                                                        } elseif ($diff < 604800) {
                                                            echo floor($diff / 86400) . ' ngày trước';
                                                        } else {
                                                            echo date('d/m/Y H:i', $timestamp);
                                                        }
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <p>Không có hoạt động nào gần đây</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="activities.php" class="view-all">Xem tất cả hoạt động</a>
                    </div>
                </div>
                
                <!-- Secondary Grid -->
                <div class="dashboard-grid">
                    <!-- Recent Orders -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-header">
                            <h2 class="dashboard-card-title">Đơn hàng gần đây</h2>
                            <div class="dashboard-card-actions">
                                <a href="orders.php" class="btn btn-sm btn-secondary">
                                    <span>👁️</span>
                                    <span>Xem tất cả</span>
                                </a>
                            </div>
                        </div>
                        <div class="dashboard-card-body">
                            <div class="table-responsive">
                                <table class="orders-table">
                                    <thead>
                                        <tr>
                                            <th>Mã</th>
                                            <th>Khách hàng</th>
                                            <th>Trạng thái</th>
                                            <th>Tổng tiền</th>
                                            <th>Ngày đặt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($recent_orders)): ?>
                                            <?php foreach ($recent_orders as $order): ?>
                                                <tr>
                                                    <td>
                                                        <span class="order-id">#<?php echo safe_echo(get_value($order, 'code', '')); ?></span>
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
                                                                <?php if (has_value($order, 'customer_email')): ?>
                                                                    <span class="customer-email"><?php echo safe_echo($order['customer_email']); ?></span>
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
                                                        <span class="order-price"><?php echo formatCurrency(get_value($order, 'grand_total', 0)); ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="order-date">
                                                            <?php echo has_value($order, 'created_at') ? date('d/m/Y H:i', strtotime($order['created_at'])) : ''; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="empty-state">
                                                    <p>Không có đơn hàng nào</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <a href="orders.php" class="view-all">Xem tất cả đơn hàng</a>
                    </div>
                    
                    <!-- Top Products -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-header">
                            <h2 class="dashboard-card-title">Sản phẩm bán chạy</h2>
                            <div class="dashboard-card-actions">
                                <a href="products.php?sort=num_of_sale&order=DESC" class="btn btn-sm btn-secondary">
                                    <span>👁️</span>
                                    <span>Xem tất cả</span>
                                </a>
                            </div>
                        </div>
                        <div class="dashboard-card-body">
                            <div class="top-products-list">
                                <?php if (!empty($top_products)): ?>
                                    <?php foreach ($top_products as $product): ?>
                                        <div class="product-item">
                                            <img 
                                                src="<?php echo !empty(get_value($product, 'thumbnail_url')) && file_exists('../' . $product['thumbnail_url']) ? '../' . safe_echo($product['thumbnail_url']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48"><rect width="48" height="48" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="18" fill="%236b7280">📦</text></svg>'; ?>" 
                                                alt="<?php echo safe_echo(get_value($product, 'name', '')); ?>"
                                                class="product-image"
                                                loading="lazy"
                                            >
                                            <div class="product-info">
                                                <div class="product-name"><?php echo safe_echo(get_value($product, 'name', '')); ?></div>
                                                <div class="product-category">
                                                    <?php if (has_value($product, 'category_name')): ?>
                                                        <span>📂 <?php echo safe_echo($product['category_name']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="product-stats">
                                                <div class="product-sales"><?php echo number_format(get_value($product, 'total_quantity', 0)); ?> đã bán</div>
                                                <div class="product-price"><?php echo formatCurrency(get_value($product, 'unit_price', 0)); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <p>Chưa có sản phẩm nào được bán</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="products.php" class="view-all">Xem tất cả sản phẩm</a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Chart.js library for charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
    
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
        
        // Sales Chart
        function initSalesChart() {
            const ctx = document.getElementById('sales-canvas').getContext('2d');
            
            // Monthly sales data from PHP
            const salesData = <?php echo json_encode($monthly_sales); ?>;
            
            // Format labels and datasets
            const labels = salesData.map(item => {
                const [year, month] = item.month.split('-');
                const date = new Date(year, month - 1);
                return date.toLocaleDateString('vi-VN', { month: 'short', year: 'numeric' });
            });
            
            const revenues = salesData.map(item => item.revenue);
            const orderCounts = salesData.map(item => item.order_count);
            
            // Create chart
            const salesChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Doanh thu',
                            data: revenues,
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Số đơn hàng',
                            data: orderCounts,
                            borderColor: '#f5576c',
                            backgroundColor: 'rgba(245, 87, 108, 0.0)',
                            borderWidth: 2,
                            borderDash: [5, 5],
                            tension: 0.4,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                font: {
                                    family: "'Inter', sans-serif"
                                }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            titleFont: {
                                family: "'Inter', sans-serif"
                            },
                            bodyFont: {
                                family: "'Inter', sans-serif"
                            },
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.datasetIndex === 0) {
                                        label += new Intl.NumberFormat('vi-VN', { 
                                            style: 'currency', 
                                            currency: 'VND',
                                            maximumFractionDigits: 0
                                        }).format(context.raw);
                                    } else {
                                        label += context.raw;
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                font: {
                                    family: "'Inter', sans-serif"
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                font: {
                                    family: "'Inter', sans-serif"
                                },
                                callback: function(value) {
                                    return new Intl.NumberFormat('vi-VN', {
                                        style: 'currency',
                                        currency: 'VND',
                                        notation: 'compact',
                                        compactDisplay: 'short',
                                        maximumFractionDigits: 0
                                    }).format(value);
                                }
                            }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    family: "'Inter', sans-serif"
                                }
                            }
                        }
                    }
                }
            });
            
            return salesChart;
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
        
        // Update order status
        async function updateOrderStatus(orderId, status) {
            const success = await makeRequest('update_order_status', { 
                order_id: orderId,
                status: status
            });
            
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
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

        // Export report functionality
        document.getElementById('export-report').addEventListener('click', function() {
            showNotification('Đang chuẩn bị báo cáo để xuất...', 'info');
            setTimeout(() => {
                showNotification('Báo cáo đã được tải xuống', 'success');
            }, 1500);
        });
        
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
            console.log('🚀 Dashboard - Initializing...');
            
            handleResponsive();
            
            // Initialize chart
            if (document.getElementById('sales-canvas')) {
                initSalesChart();
            }
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Dashboard - Ready!');
        });
    </script>
</body>
</html>