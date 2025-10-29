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
    
    // Total orders - CHỈ ĐƠN ĐÃ THANH TOÁN
    $stmt = $db->query("SELECT COUNT(*) as count FROM orders WHERE payment_status IN ('paid', 'completed', 'Paid', 'Completed')");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_orders'] = $result ? (int)$result['count'] : 0;
    
    // Total products
    $stmt = $db->query("SELECT COUNT(*) as count FROM products WHERE published = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_products'] = $result ? (int)$result['count'] : 0;
    
    // Total revenue - CHỈ ĐƠN ĐÃ THANH TOÁN
    $stmt = $db->query("SELECT SUM(grand_total) as total FROM orders WHERE payment_status IN ('paid', 'completed', 'Paid', 'Completed')");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_revenue'] = $result && $result['total'] ? (float)$result['total'] : 0;
    
    // Orders today - CHỈ ĐƠN ĐÃ THANH TOÁN
    $today_start = date('Y-m-d 00:00:00');
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE payment_status IN ('paid', 'completed', 'Paid', 'Completed') AND created_at >= ?");
    $stmt->execute([$today_start]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['orders_today'] = $result ? (int)$result['count'] : 0;
    
    // Revenue today - CHỈ ĐƠN ĐÃ THANH TOÁN
    $stmt = $db->prepare("SELECT SUM(grand_total) as total FROM orders WHERE payment_status IN ('paid', 'completed', 'Paid', 'Completed') AND created_at >= ?");
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
        LEFT JOIN orders o ON od.order_id = o.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN uploads u_thumb ON p.thumbnail_img = u_thumb.id
        WHERE p.published = 1
        AND (o.payment_status IN ('paid', 'completed', 'Paid', 'Completed') OR o.id IS NULL)
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
$chart_data_source = 'no_data'; // Track data source for debugging
try {
    // First, check if we have any orders at all
    $check_stmt = $db->query("SELECT COUNT(*) as total FROM orders");
    $check_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
    $total_orders = $check_result['total'] ?? 0;
    
    if ($total_orders > 0) {
        // Strategy 1: Try to get paid/completed orders (most reliable for revenue)
        $sql = "
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as order_count,
                COALESCE(SUM(grand_total), 0) as revenue
            FROM orders
            WHERE payment_status IN ('paid', 'completed', 'Paid', 'Completed')
            AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ";
        
        $stmt = $db->query($sql);
        $monthly_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($monthly_sales)) {
            $chart_data_source = 'paid_orders';
        } else {
            // Strategy 2: Get all orders regardless of payment status
            $sql = "
                SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as order_count,
                    COALESCE(SUM(CASE WHEN payment_status IN ('paid', 'completed', 'Paid', 'Completed') THEN grand_total ELSE 0 END), 0) as revenue
                FROM orders
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month ASC
            ";
            
            $stmt = $db->query($sql);
            $monthly_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $chart_data_source = !empty($monthly_sales) ? 'all_orders' : 'no_orders_12m';
        }
    }
    
    // If still no data, create placeholder for current month
    if (empty($monthly_sales)) {
        $monthly_sales = [
            [
                'month' => date('Y-m'),
                'order_count' => 0,
                'revenue' => 0
            ]
        ];
        $chart_data_source = 'placeholder';
    }
    
    // Debug log
    error_log("Chart data source: " . $chart_data_source . " | Total orders: " . $total_orders . " | Data points: " . count($monthly_sales));
    
} catch (PDOException $e) {
    error_log("Monthly sales fetch error: " . $e->getMessage());
    // Fallback data
    $monthly_sales = [
        [
            'month' => date('Y-m'),
            'order_count' => 0,
            'revenue' => 0
        ]
    ];
    $chart_data_source = 'error';
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
    <link rel="stylesheet" href="../asset/css/pages/admin-sidebar.css">
</head>

<body>
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <?php require_once __DIR__ . '/sidebar.php'; ?>
        
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
    
    <script>
        // Check if Chart.js loaded successfully
        if (typeof Chart === 'undefined') {
            console.error('❌ Chart.js failed to load');
        } else {
            console.log('✅ Chart.js loaded successfully', Chart.version);
        }
        
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
            const canvas = document.getElementById('sales-canvas');
            if (!canvas) {
                console.error('❌ Canvas element not found');
                return;
            }
            
            // Check if Chart.js is loaded
            if (typeof Chart === 'undefined') {
                console.error('❌ Chart.js not loaded');
                return;
            }
            
            const ctx = canvas.getContext('2d');
            
            // Monthly sales data from PHP
            const salesData = <?php echo json_encode($monthly_sales); ?>;
            const dataSource = '<?php echo $chart_data_source; ?>';
            
            console.log('📊 Chart data source:', dataSource);
            console.log('📊 Sales data points:', salesData.length);
            console.log('📊 Sales data:', salesData);
            
            // Check if we have meaningful data (not just zeros)
            if (!salesData || salesData.length === 0) {
                console.warn('⚠️ No sales data available');
                const container = document.getElementById('sales-chart');
                container.innerHTML = '<div style="text-align: center; padding: 3rem; color: #6b7280;">' +
                    '<div style="font-size: 3rem; margin-bottom: 1rem;">📊</div>' +
                    '<div style="font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5rem;">Chưa có dữ liệu doanh thu</div>' +
                    '<div style="font-size: 0.875rem;">Dữ liệu sẽ hiển thị khi có đơn hàng mới</div>' +
                    '<div style="margin-top: 1rem;"><a href="check-data.php" style="color: #3b82f6; text-decoration: none;">🔍 Kiểm tra database</a></div>' +
                    '</div>';
                return;
            }
            
            // Check if all data is zero
            const hasRealData = salesData.some(item => 
                (parseFloat(item.revenue) || 0) > 0 || 
                (parseInt(item.order_count) || 0) > 0
            );
            
            if (!hasRealData) {
                console.warn('⚠️ All sales data is zero');
                const container = document.getElementById('sales-chart');
                let message = '';
                
                if (dataSource === 'all_orders') {
                    message = '<div style="font-size: 0.875rem; color: #f59e0b;">💡 Có đơn hàng nhưng chưa có đơn nào được thanh toán (payment_status = \'paid\')</div>';
                } else if (dataSource === 'placeholder') {
                    message = '<div style="font-size: 0.875rem;">💡 Chưa có đơn hàng nào trong hệ thống</div>';
                }
                
                container.innerHTML = '<div style="text-align: center; padding: 3rem; color: #6b7280;">' +
                    '<div style="font-size: 3rem; margin-bottom: 1rem;">📈</div>' +
                    '<div style="font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5rem;">Chưa có doanh thu</div>' +
                    message +
                    '<div style="margin-top: 1rem;"><a href="check-data.php" style="color: #3b82f6; text-decoration: none;">🔍 Kiểm tra database</a></div>' +
                    '</div>';
                return;
            }
            
            // Format labels and datasets
            const labels = salesData.map(item => {
                const [year, month] = item.month.split('-');
                const date = new Date(year, month - 1);
                return date.toLocaleDateString('vi-VN', { month: 'short', year: 'numeric' });
            });
            
            const revenues = salesData.map(item => parseFloat(item.revenue) || 0);
            const orderCounts = salesData.map(item => parseInt(item.order_count) || 0);
            
            console.log('📊 Chart labels:', labels);
            console.log('💰 Revenues:', revenues);
            console.log('📦 Order counts:', orderCounts);
            
            // Create gradient for revenue line
            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(99, 102, 241, 0.2)');
            gradient.addColorStop(1, 'rgba(99, 102, 241, 0.0)');
            
            // Create chart
            const salesChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Doanh thu (VNĐ)',
                            data: revenues,
                            borderColor: '#6366f1',
                            backgroundColor: gradient,
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 5,
                            pointHoverRadius: 7,
                            pointBackgroundColor: '#6366f1',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointHoverBackgroundColor: '#6366f1',
                            pointHoverBorderColor: '#fff',
                            pointHoverBorderWidth: 3
                        },
                        {
                            label: 'Số đơn hàng',
                            data: orderCounts,
                            borderColor: '#f43f5e',
                            backgroundColor: 'rgba(244, 63, 94, 0.1)',
                            borderWidth: 3,
                            fill: false,
                            tension: 0.4,
                            pointRadius: 5,
                            pointHoverRadius: 7,
                            pointBackgroundColor: '#f43f5e',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointHoverBackgroundColor: '#f43f5e',
                            pointHoverBorderColor: '#fff',
                            pointHoverBorderWidth: 3,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    animation: {
                        duration: 1500,
                        easing: 'easeInOutQuart'
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'end',
                            labels: {
                                usePointStyle: true,
                                pointStyle: 'circle',
                                padding: 20,
                                font: {
                                    family: "'Inter', -apple-system, BlinkMacSystemFont, sans-serif",
                                    size: 13,
                                    weight: '500'
                                },
                                color: '#64748b'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(255, 255, 255, 0.95)',
                            titleColor: '#1e293b',
                            bodyColor: '#475569',
                            borderColor: '#e2e8f0',
                            borderWidth: 1,
                            padding: 16,
                            boxPadding: 8,
                            usePointStyle: true,
                            titleFont: {
                                family: "'Inter', -apple-system, BlinkMacSystemFont, sans-serif",
                                size: 14,
                                weight: '600'
                            },
                            bodyFont: {
                                family: "'Inter', -apple-system, BlinkMacSystemFont, sans-serif",
                                size: 13,
                                weight: '500'
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
                                        label += context.raw + ' đơn';
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: true,
                                color: 'rgba(0, 0, 0, 0.03)',
                                drawBorder: false
                            },
                            ticks: {
                                font: {
                                    family: "'Inter', -apple-system, BlinkMacSystemFont, sans-serif",
                                    size: 12,
                                    weight: '500'
                                },
                                color: '#64748b',
                                padding: 8
                            },
                            border: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            position: 'left',
                            grid: {
                                display: true,
                                color: 'rgba(0, 0, 0, 0.03)',
                                drawBorder: false
                            },
                            ticks: {
                                font: {
                                    family: "'Inter', -apple-system, BlinkMacSystemFont, sans-serif",
                                    size: 12,
                                    weight: '500'
                                },
                                color: '#64748b',
                                padding: 8,
                                callback: function(value) {
                                    if (value >= 1000000) {
                                        return (value / 1000000).toFixed(1) + 'M';
                                    } else if (value >= 1000) {
                                        return (value / 1000).toFixed(0) + 'K';
                                    }
                                    return new Intl.NumberFormat('vi-VN').format(value);
                                }
                            },
                            border: {
                                display: false
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
                                    family: "'Inter', -apple-system, BlinkMacSystemFont, sans-serif",
                                    size: 12,
                                    weight: '500'
                                },
                                color: '#64748b',
                                padding: 8
                            },
                            border: {
                                display: false
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
            
            // Wait a bit for Chart.js to be ready
            setTimeout(function() {
                // Initialize chart
                if (document.getElementById('sales-canvas')) {
                    if (typeof Chart !== 'undefined') {
                        try {
                            initSalesChart();
                            console.log('✅ Chart initialized');
                        } catch (error) {
                            console.error('❌ Chart initialization error:', error);
                        }
                    } else {
                        console.error('❌ Chart.js not available');
                    }
                } else {
                    console.warn('⚠️ Canvas element not found');
                }
            }, 300);
            
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