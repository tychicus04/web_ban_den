<?php
/**
 * Admin Analytics Page
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
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
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
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['value'] : $default;
    } catch (PDOException $e) {
        error_log("Business setting error: " . $e->getMessage());
        return $default;
    }
}

 else {
        return '$' . number_format((float)$amount, 2, '.', ',');
    }
}

// Date filter parameters
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : '30days';
$custom_start = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$custom_end = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Calculate date range based on filter
$end_date = date('Y-m-d');
$start_date = '';

switch ($date_filter) {
    case '7days':
        $start_date = date('Y-m-d', strtotime('-7 days'));
        break;
    case '30days':
        $start_date = date('Y-m-d', strtotime('-30 days'));
        break;
    case '90days':
        $start_date = date('Y-m-d', strtotime('-90 days'));
        break;
    case 'year':
        $start_date = date('Y-01-01');
        break;
    case 'last_year':
        $start_date = date('Y-01-01', strtotime('-1 year'));
        $end_date = date('Y-12-31', strtotime('-1 year'));
        break;
    case 'custom':
        if (!empty($custom_start)) {
            $start_date = $custom_start;
        } else {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }
        
        if (!empty($custom_end)) {
            $end_date = $custom_end;
        }
        break;
    default:
        $start_date = date('Y-m-d', strtotime('-30 days'));
}

// Format for SQL queries
$start_date_sql = $start_date . ' 00:00:00';
$end_date_sql = $end_date . ' 23:59:59';

// Get sales summary
$sales_summary = [];
try {
    $sql = "
        SELECT 
            COUNT(*) as total_orders,
            SUM(grand_total) as total_revenue,
            COUNT(DISTINCT user_id) as unique_customers,
            SUM(grand_total) / COUNT(*) as average_order_value,
            SUM(CASE WHEN payment_status = 'paid' THEN grand_total ELSE 0 END) as paid_amount,
            SUM(CASE WHEN payment_status = 'unpaid' THEN grand_total ELSE 0 END) as unpaid_amount
        FROM orders
        WHERE created_at BETWEEN ? AND ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date_sql, $end_date_sql]);
    $sales_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Sales summary error: " . $e->getMessage());
    $sales_summary = [
        'total_orders' => 0,
        'total_revenue' => 0,
        'unique_customers' => 0,
        'average_order_value' => 0,
        'paid_amount' => 0,
        'unpaid_amount' => 0
    ];
}

// Compare with previous period
$prev_start_date = date('Y-m-d', strtotime($start_date . ' -' . (strtotime($end_date) - strtotime($start_date)) . ' seconds'));
$prev_end_date = date('Y-m-d', strtotime($end_date . ' -' . (strtotime($end_date) - strtotime($start_date)) . ' seconds'));

$prev_start_date_sql = $prev_start_date . ' 00:00:00';
$prev_end_date_sql = $prev_end_date . ' 23:59:59';

$previous_summary = [];
try {
    $sql = "
        SELECT 
            COUNT(*) as total_orders,
            SUM(grand_total) as total_revenue
        FROM orders
        WHERE created_at BETWEEN ? AND ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$prev_start_date_sql, $prev_end_date_sql]);
    $previous_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate percentage changes
    $sales_summary['orders_change'] = $previous_summary['total_orders'] > 0 
        ? (($sales_summary['total_orders'] - $previous_summary['total_orders']) / $previous_summary['total_orders'] * 100) 
        : 100;
    
    $sales_summary['revenue_change'] = $previous_summary['total_revenue'] > 0 
        ? (($sales_summary['total_revenue'] - $previous_summary['total_revenue']) / $previous_summary['total_revenue'] * 100) 
        : 100;
    
} catch (PDOException $e) {
    error_log("Previous period comparison error: " . $e->getMessage());
    $sales_summary['orders_change'] = 0;
    $sales_summary['revenue_change'] = 0;
}

// Get daily sales data
$daily_sales = [];
try {
    $sql = "
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as order_count,
            SUM(grand_total) as revenue
        FROM orders
        WHERE created_at BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date_sql, $end_date_sql]);
    $daily_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Daily sales error: " . $e->getMessage());
    $daily_sales = [];
}

// Get sales by category
$category_sales = [];
try {
    $sql = "
        SELECT 
            c.id as category_id,
            c.name as category_name,
            COUNT(DISTINCT o.id) as order_count,
            SUM(od.quantity) as total_items,
            SUM(od.price * od.quantity) as revenue
        FROM orders o
        JOIN order_details od ON o.id = od.order_id
        JOIN products p ON od.product_id = p.id
        JOIN categories c ON p.category_id = c.id
        WHERE o.created_at BETWEEN ? AND ?
        GROUP BY c.id
        ORDER BY revenue DESC
        LIMIT 10
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date_sql, $end_date_sql]);
    $category_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Category sales error: " . $e->getMessage());
    $category_sales = [];
}

// Get top selling products
$top_products = [];
try {
    $sql = "
        SELECT 
            p.id as product_id,
            p.name as product_name,
            p.thumbnail_img,
            u_thumb.file_name as thumbnail_url,
            COUNT(DISTINCT o.id) as order_count,
            SUM(od.quantity) as total_quantity,
            SUM(od.price * od.quantity) as revenue
        FROM orders o
        JOIN order_details od ON o.id = od.order_id
        JOIN products p ON od.product_id = p.id
        LEFT JOIN uploads u_thumb ON p.thumbnail_img = u_thumb.id
        WHERE o.created_at BETWEEN ? AND ?
        GROUP BY p.id
        ORDER BY total_quantity DESC
        LIMIT 10
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date_sql, $end_date_sql]);
    $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Top products error: " . $e->getMessage());
    $top_products = [];
}

// Get customer acquisition data
$new_customers = [];
try {
    $sql = "
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as customer_count
        FROM users
        WHERE user_type = 'customer' AND created_at BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date_sql, $end_date_sql]);
    $new_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("New customers error: " . $e->getMessage());
    $new_customers = [];
}

// Get payment method stats
$payment_methods = [];
try {
    $sql = "
        SELECT 
            payment_type,
            COUNT(*) as order_count,
            SUM(grand_total) as amount
        FROM orders
        WHERE created_at BETWEEN ? AND ?
        GROUP BY payment_type
        ORDER BY amount DESC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date_sql, $end_date_sql]);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Payment methods error: " . $e->getMessage());
    $payment_methods = [];
}

// Optimize payment methods data for chart
$payment_labels = [];
$payment_values = [];
$payment_colors = [
    'cash_on_delivery' => '#10B981',
    'bank_transfer' => '#667EEA',
    'card' => '#F59E0B',
    'paypal' => '#3B82F6',
    'stripe' => '#8B5CF6',
    'wallet' => '#EC4899'
];

$default_colors = ['#10B981', '#667EEA', '#F59E0B', '#3B82F6', '#8B5CF6', '#EC4899', '#EF4444', '#14B8A6'];

foreach ($payment_methods as $index => $method) {
    $payment_type = $method['payment_type'] ?: 'unknown';
    $payment_labels[] = ucwords(str_replace('_', ' ', $payment_type));
    $payment_values[] = (float)$method['amount'];
}

// Get site name
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
            case 'export_analytics':
                // This would normally generate a CSV/Excel file
                // For now we just return success
                echo json_encode(['success' => true, 'message' => 'Báo cáo đã được xuất thành công']);
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
    <title>Phân tích dữ liệu - Admin <?php echo safe_echo($site_name); ?></title>
    <meta name="description" content="Phân tích dữ liệu bán hàng - Admin <?php echo safe_echo($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-analytics.css">
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
                        <a href="analytics.php" class="nav-link active">
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
                            <span>Phân tích</span>
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
                    <h1 class="page-title">Phân tích dữ liệu</h1>
                    <p class="page-subtitle">Theo dõi và phân tích hiệu suất kinh doanh của cửa hàng.</p>
                </div>
                
                <!-- Filter bar -->
                <form action="" method="get" class="filter-bar">
                    <div class="filter-group">
                        <label class="filter-label" for="date-filter">Khoảng thời gian:</label>
                        <select name="date_filter" id="date-filter" class="filter-select" onchange="toggleCustomDateInputs()">
                            <option value="7days" <?php echo $date_filter === '7days' ? 'selected' : ''; ?>>7 ngày qua</option>
                            <option value="30days" <?php echo $date_filter === '30days' ? 'selected' : ''; ?>>30 ngày qua</option>
                            <option value="90days" <?php echo $date_filter === '90days' ? 'selected' : ''; ?>>90 ngày qua</option>
                            <option value="year" <?php echo $date_filter === 'year' ? 'selected' : ''; ?>>Năm nay</option>
                            <option value="last_year" <?php echo $date_filter === 'last_year' ? 'selected' : ''; ?>>Năm trước</option>
                            <option value="custom" <?php echo $date_filter === 'custom' ? 'selected' : ''; ?>>Tùy chỉnh</option>
                        </select>
                        
                        <div id="custom-date-inputs" class="custom-date-inputs" style="display: <?php echo $date_filter === 'custom' ? 'flex' : 'none'; ?>">
                            <input type="date" name="start_date" class="filter-input" value="<?php echo $custom_start; ?>">
                            <span>đến</span>
                            <input type="date" name="end_date" class="filter-input" value="<?php echo $custom_end; ?>">
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <span>🔍</span>
                            <span>Áp dụng</span>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" id="export-data">
                            <span>📥</span>
                            <span>Xuất dữ liệu</span>
                        </button>
                    </div>
                </form>
                
                <!-- Main Stats -->
                <div class="stats-grid">
                    <div class="stat-card primary">
                        <div class="stat-header">
                            <div class="stat-title">Tổng doanh thu</div>
                            <div class="stat-icon">💰</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency(get_value($sales_summary, 'total_revenue', 0)); ?></div>
                        <div class="stat-comparison <?php echo get_value($sales_summary, 'revenue_change', 0) >= 0 ? 'positive' : 'negative'; ?>">
                            <span><?php echo get_value($sales_summary, 'revenue_change', 0) >= 0 ? '↑' : '↓'; ?></span>
                            <span><?php echo number_format(abs(get_value($sales_summary, 'revenue_change', 0)), 1); ?>% so với kỳ trước</span>
                        </div>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-title">Tổng đơn hàng</div>
                            <div class="stat-icon">📦</div>
                        </div>
                        <div class="stat-value"><?php echo number_format(get_value($sales_summary, 'total_orders', 0)); ?></div>
                        <div class="stat-comparison <?php echo get_value($sales_summary, 'orders_change', 0) >= 0 ? 'positive' : 'negative'; ?>">
                            <span><?php echo get_value($sales_summary, 'orders_change', 0) >= 0 ? '↑' : '↓'; ?></span>
                            <span><?php echo number_format(abs(get_value($sales_summary, 'orders_change', 0)), 1); ?>% so với kỳ trước</span>
                        </div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-title">Giá trị đơn hàng TB</div>
                            <div class="stat-icon">📊</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency(get_value($sales_summary, 'average_order_value', 0)); ?></div>
                        <div class="stat-comparison positive">
                            <span>👥</span>
                            <span><?php echo number_format(get_value($sales_summary, 'unique_customers', 0)); ?> khách hàng</span>
                        </div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-title">Tỷ lệ thanh toán</div>
                            <div class="stat-icon">💳</div>
                        </div>
                        <?php 
                        $total_revenue = (float)get_value($sales_summary, 'total_revenue', 0);
                        $paid_amount = (float)get_value($sales_summary, 'paid_amount', 0);
                        $payment_rate = $total_revenue > 0 ? ($paid_amount / $total_revenue) * 100 : 0;
                        ?>
                        <div class="stat-value"><?php echo number_format($payment_rate, 1); ?>%</div>
                        <div class="stat-comparison <?php echo $payment_rate >= 80 ? 'positive' : 'negative'; ?>">
                            <span><?php echo $payment_rate >= 80 ? '✅' : '⚠️'; ?></span>
                            <span><?php echo formatCurrency($paid_amount); ?> đã thanh toán</span>
                        </div>
                    </div>
                </div>
                
                <!-- Charts Grid -->
                <div class="charts-grid">
                    <!-- Sales Chart -->
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <div>
                                <h2 class="chart-card-title">Doanh số theo thời gian</h2>
                                <div class="chart-card-subtitle">
                                    <?php echo date('d/m/Y', strtotime($start_date)); ?> - <?php echo date('d/m/Y', strtotime($end_date)); ?>
                                </div>
                            </div>
                            <div class="chart-card-actions">
                                <button class="btn btn-sm btn-secondary" id="toggle-chart-view">
                                    <span>📊</span>
                                    <span>Đổi chế độ xem</span>
                                </button>
                            </div>
                        </div>
                        <div class="chart-card-body">
                            <div class="chart-container">
                                <canvas id="sales-chart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Methods Chart -->
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <div>
                                <h2 class="chart-card-title">Phương thức thanh toán</h2>
                                <div class="chart-card-subtitle">Theo doanh thu</div>
                            </div>
                        </div>
                        <div class="chart-card-body">
                            <div class="chart-container">
                                <canvas id="payment-methods-chart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Categories and Products -->
                <div class="charts-grid">
                    <!-- Categories -->
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <div>
                                <h2 class="chart-card-title">Doanh thu theo danh mục</h2>
                                <div class="chart-card-subtitle">Top 10 danh mục có doanh thu cao nhất</div>
                            </div>
                        </div>
                        <div class="chart-card-body">
                            <?php if (!empty($category_sales)): ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Danh mục</th>
                                            <th>Số đơn</th>
                                            <th>Số sản phẩm</th>
                                            <th>Doanh thu</th>
                                            <th>Tỷ trọng</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_category_revenue = array_sum(array_column($category_sales, 'revenue'));
                                        foreach ($category_sales as $category): 
                                        ?>
                                            <tr>
                                                <td><?php echo safe_echo(get_value($category, 'category_name', '')); ?></td>
                                                <td><?php echo number_format(get_value($category, 'order_count', 0)); ?></td>
                                                <td><?php echo number_format(get_value($category, 'total_items', 0)); ?></td>
                                                <td><?php echo formatCurrency(get_value($category, 'revenue', 0)); ?></td>
                                                <td>
                                                    <?php 
                                                    $percentage = $total_category_revenue > 0 ? ((float)$category['revenue'] / $total_category_revenue * 100) : 0;
                                                    ?>
                                                    <div class="progress-bar">
                                                        <div class="progress-fill primary" style="width: <?php echo $percentage; ?>%"></div>
                                                    </div>
                                                    <div style="font-size: var(--text-xs); margin-top: var(--space-1);">
                                                        <?php echo number_format($percentage, 1); ?>%
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <p>Không có dữ liệu danh mục trong khoảng thời gian này</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Top Products -->
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <div>
                                <h2 class="chart-card-title">Sản phẩm bán chạy</h2>
                                <div class="chart-card-subtitle">Top 10 sản phẩm bán chạy nhất</div>
                            </div>
                        </div>
                        <div class="chart-card-body">
                            <div class="product-list">
                                <?php if (!empty($top_products)): ?>
                                    <?php foreach ($top_products as $product): ?>
                                        <div class="product-item">
                                            <img 
                                                src="<?php echo !empty(get_value($product, 'thumbnail_url')) && file_exists('../' . $product['thumbnail_url']) ? '../' . safe_echo($product['thumbnail_url']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48"><rect width="48" height="48" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="18" fill="%236b7280">📦</text></svg>'; ?>" 
                                                alt="<?php echo safe_echo(get_value($product, 'product_name', '')); ?>"
                                                class="product-image"
                                                loading="lazy"
                                            >
                                            <div class="product-info">
                                                <div class="product-name"><?php echo safe_echo(get_value($product, 'product_name', '')); ?></div>
                                                <div class="product-sales"><?php echo number_format(get_value($product, 'total_quantity', 0)); ?> sản phẩm đã bán</div>
                                            </div>
                                            <div class="product-stats">
                                                <div class="product-revenue"><?php echo formatCurrency(get_value($product, 'revenue', 0)); ?></div>
                                                <div class="product-orders"><?php echo number_format(get_value($product, 'order_count', 0)); ?> đơn hàng</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <p>Không có dữ liệu sản phẩm trong khoảng thời gian này</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Customer Growth -->
                <div class="chart-card" style="margin-bottom: var(--space-8);">
                    <div class="chart-card-header">
                        <div>
                            <h2 class="chart-card-title">Tăng trưởng khách hàng</h2>
                            <div class="chart-card-subtitle">Số lượng khách hàng mới đăng ký theo thời gian</div>
                        </div>
                    </div>
                    <div class="chart-card-body">
                        <div class="chart-container">
                            <canvas id="customer-growth-chart"></canvas>
                        </div>
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
        
        // Toggle custom date inputs
        function toggleCustomDateInputs() {
            const dateFilter = document.getElementById('date-filter');
            const customDateInputs = document.getElementById('custom-date-inputs');
            
            if (dateFilter.value === 'custom') {
                customDateInputs.style.display = 'flex';
            } else {
                customDateInputs.style.display = 'none';
            }
        }
        
        // Sales Chart
        function initSalesChart() {
            const ctx = document.getElementById('sales-chart').getContext('2d');
            
            // Daily sales data from PHP
            const salesData = <?php echo json_encode($daily_sales); ?>;
            
            // Format labels and datasets
            const labels = salesData.map(item => {
                const date = new Date(item.date);
                return date.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' });
            });
            
            const revenues = salesData.map(item => parseFloat(item.revenue));
            const orderCounts = salesData.map(item => parseInt(item.order_count));
            
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
            
            // Toggle between line and bar chart
            document.getElementById('toggle-chart-view').addEventListener('click', function() {
                salesChart.config.type = salesChart.config.type === 'line' ? 'bar' : 'line';
                salesChart.update();
            });
            
            return salesChart;
        }
        
        // Payment Methods Chart
        function initPaymentMethodsChart() {
            const ctx = document.getElementById('payment-methods-chart').getContext('2d');
            
            // Payment methods data
            const labels = <?php echo json_encode($payment_labels); ?>;
            const values = <?php echo json_encode($payment_values); ?>;
            
            // Custom colors
            const colors = [
                '#10B981', // success
                '#667EEA', // primary
                '#F59E0B', // warning
                '#3B82F6', // blue
                '#8B5CF6', // purple
                '#EC4899', // pink
                '#EF4444', // danger
                '#14B8A6'  // teal
            ];
            
            // Create chart
            const paymentChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: colors.slice(0, labels.length),
                        borderWidth: 0,
                        hoverOffset: 15
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                usePointStyle: true,
                                font: {
                                    family: "'Inter', sans-serif",
                                    size: 11
                                },
                                padding: 15
                            }
                        },
                        tooltip: {
                            titleFont: {
                                family: "'Inter', sans-serif"
                            },
                            bodyFont: {
                                family: "'Inter', sans-serif"
                            },
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    
                                    return `${label}: ${new Intl.NumberFormat('vi-VN', { 
                                        style: 'currency', 
                                        currency: 'VND',
                                        maximumFractionDigits: 0
                                    }).format(value)} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
            
            return paymentChart;
        }
        
        // Customer Growth Chart
        function initCustomerGrowthChart() {
            const ctx = document.getElementById('customer-growth-chart').getContext('2d');
            
            // New customers data
            const customersData = <?php echo json_encode($new_customers); ?>;
            
            // Format labels and datasets
            const labels = customersData.map(item => {
                const date = new Date(item.date);
                return date.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' });
            });
            
            const customers = customersData.map(item => parseInt(item.customer_count));
            
            // Calculate cumulative data
            let cumulativeCustomers = [];
            let sum = 0;
            for (const count of customers) {
                sum += count;
                cumulativeCustomers.push(sum);
            }
            
            // Create chart
            const customerChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Khách hàng mới',
                            data: customers,
                            backgroundColor: 'rgba(16, 185, 129, 0.7)',
                            borderColor: 'rgba(16, 185, 129, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Tổng khách hàng',
                            data: cumulativeCustomers,
                            type: 'line',
                            fill: false,
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
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
            
            return customerChart;
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
        
        // Export data functionality
        document.getElementById('export-data').addEventListener('click', async function() {
            const success = await makeRequest('export_analytics', {
                date_filter: '<?php echo $date_filter; ?>',
                start_date: '<?php echo $start_date; ?>',
                end_date: '<?php echo $end_date; ?>'
            });
            
            if (success) {
                // The success notification is shown by makeRequest
            }
        });
        
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
            console.log('🚀 Analytics - Initializing...');
            
            handleResponsive();
            
            // Initialize charts
            if (document.getElementById('sales-chart')) {
                initSalesChart();
            }
            
            if (document.getElementById('payment-methods-chart')) {
                initPaymentMethodsChart();
            }
            
            if (document.getElementById('customer-growth-chart')) {
                initCustomerGrowthChart();
            }
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Analytics - Ready!');
        });
    </script>
</body>
</html>