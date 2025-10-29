<?php
/**
 * Admin Orders Page
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

// Pagination function
function paginate($current_page, $total_pages, $url_pattern) {
    $range = 2;
    $output = '<div class="pagination">';
    
    if ($current_page > 1) {
        $output .= '<a href="' . sprintf($url_pattern, 1) . '" class="pagination-item" aria-label="First page">«</a>';
        $output .= '<a href="' . sprintf($url_pattern, $current_page - 1) . '" class="pagination-item" aria-label="Previous page">‹</a>';
    } else {
        $output .= '<span class="pagination-item disabled">«</span>';
        $output .= '<span class="pagination-item disabled">‹</span>';
    }
    
    for ($i = max(1, $current_page - $range); $i <= min($total_pages, $current_page + $range); $i++) {
        if ($i == $current_page) {
            $output .= '<span class="pagination-item active">' . $i . '</span>';
        } else {
            $output .= '<a href="' . sprintf($url_pattern, $i) . '" class="pagination-item">' . $i . '</a>';
        }
    }
    
    if ($current_page < $total_pages) {
        $output .= '<a href="' . sprintf($url_pattern, $current_page + 1) . '" class="pagination-item" aria-label="Next page">›</a>';
        $output .= '<a href="' . sprintf($url_pattern, $total_pages) . '" class="pagination-item" aria-label="Last page">»</a>';
    } else {
        $output .= '<span class="pagination-item disabled">›</span>';
        $output .= '<span class="pagination-item disabled">»</span>';
    }
    
    $output .= '</div>';
    return $output;
}

$site_name = getBusinessSetting($db, 'site_name', 'Active E-Commerce');

// Set default filters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sort_order = isset($_GET['order']) ? strtoupper($_GET['order']) : 'DESC';

// Validate sort parameters
$allowed_sort_fields = ['id', 'code', 'grand_total', 'created_at', 'delivery_status', 'payment_status'];
if (!in_array($sort_by, $allowed_sort_fields)) {
    $sort_by = 'created_at';
}

$allowed_sort_orders = ['ASC', 'DESC'];
if (!in_array($sort_order, $allowed_sort_orders)) {
    $sort_order = 'DESC';
}

// Get orders with filters
$orders = [];
$total_orders = 0;

try {
    $params = [];
    $where_clauses = [];
    
    // Build WHERE clause based on filters
    if (!empty($status_filter)) {
        $where_clauses[] = "o.delivery_status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($search_term)) {
        $where_clauses[] = "(o.code LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
        $search_pattern = "%{$search_term}%";
        $params[] = $search_pattern;
        $params[] = $search_pattern;
        $params[] = $search_pattern;
        $params[] = $search_pattern;
    }
    
    if (!empty($date_from)) {
        $where_clauses[] = "o.created_at >= ?";
        $params[] = $date_from . ' 00:00:00';
    }
    
    if (!empty($date_to)) {
        $where_clauses[] = "o.created_at <= ?";
        $params[] = $date_to . ' 23:59:59';
    }
    
    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
    
    // Count total orders for pagination
    $count_sql = "
        SELECT COUNT(*) as count
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        $where_sql
    ";
    
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_orders = $result ? (int)$result['count'] : 0;
    
    // Calculate total pages
    $total_pages = ceil($total_orders / $per_page);
    $offset = ($page - 1) * $per_page;
    
    // Get orders with pagination
    $orders_sql = "
        SELECT o.*, 
               u.name as customer_name,
               u.email as customer_email,
               u.phone as customer_phone,
               u.avatar as customer_avatar
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        $where_sql
        ORDER BY o.$sort_by $sort_order
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($orders_sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Orders fetch error: " . $e->getMessage());
}

// Get order stats
$order_stats = [
    'total' => 0,
    'pending' => 0,
    'processing' => 0,
    'shipped' => 0,
    'delivered' => 0,
    'cancelled' => 0,
    'total_revenue' => 0,
    'paid_revenue' => 0
];

try {
    // Total orders
    $stmt = $db->query("SELECT COUNT(*) as count FROM orders");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $order_stats['total'] = $result ? (int)$result['count'] : 0;
    
    // Orders by status
    $stmt = $db->query("SELECT delivery_status, COUNT(*) as count FROM orders GROUP BY delivery_status");
    $status_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($status_counts as $status) {
        if (isset($order_stats[$status['delivery_status']])) {
            $order_stats[$status['delivery_status']] = (int)$status['count'];
        }
    }
    
    // Total revenue
    $stmt = $db->query("SELECT SUM(grand_total) as total FROM orders");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $order_stats['total_revenue'] = $result && $result['total'] ? (float)$result['total'] : 0;
    
    // Paid revenue
    $stmt = $db->query("SELECT SUM(grand_total) as total FROM orders WHERE payment_status = 'paid'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $order_stats['paid_revenue'] = $result && $result['total'] ? (float)$result['total'] : 0;
    
} catch (PDOException $e) {
    error_log("Order stats error: " . $e->getMessage());
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
                
            case 'get_order_details':
                $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
                
                if ($order_id <= 0) {
                    throw new Exception('Dữ liệu không hợp lệ');
                }
                
                // Get order info
                $stmt = $db->prepare("
                    SELECT o.*, 
                           u.name as customer_name,
                           u.email as customer_email,
                           u.phone as customer_phone
                    FROM orders o
                    LEFT JOIN users u ON o.user_id = u.id
                    WHERE o.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$order_id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$order) {
                    throw new Exception('Không tìm thấy đơn hàng');
                }
                
                // Get order items
                $stmt = $db->prepare("
                    SELECT od.*, 
                           p.name as product_name,
                           p.thumbnail_img,
                           u.file_name as product_image
                    FROM order_details od
                    LEFT JOIN products p ON od.product_id = p.id
                    LEFT JOIN uploads u ON p.thumbnail_img = u.id
                    WHERE od.order_id = ?
                ");
                $stmt->execute([$order_id]);
                $order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true, 
                    'order' => $order,
                    'items' => $order_items
                ]);
                break;
                
            case 'delete_order':
                $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
                
                if ($order_id <= 0) {
                    throw new Exception('Dữ liệu không hợp lệ');
                }
                
                // Start transaction
                $db->beginTransaction();
                
                // Delete order details
                $stmt = $db->prepare("DELETE FROM order_details WHERE order_id = ?");
                $stmt->execute([$order_id]);
                
                // Delete order
                $stmt = $db->prepare("DELETE FROM orders WHERE id = ?");
                $stmt->execute([$order_id]);
                
                if ($stmt->rowCount() > 0) {
                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'Đã xóa đơn hàng thành công']);
                } else {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Không tìm thấy đơn hàng hoặc xóa không thành công']);
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

// Generate filter URL
function getFilterUrl($page = 1, $override = []) {
    global $status_filter, $search_term, $date_from, $date_to, $sort_by, $sort_order;
    
    $params = [
        'page' => $page,
        'status' => $status_filter,
        'search' => $search_term,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'sort' => $sort_by,
        'order' => strtolower($sort_order)
    ];
    
    // Override params
    foreach ($override as $key => $value) {
        $params[$key] = $value;
    }
    
    // Remove empty params
    $params = array_filter($params, function($value) {
        return $value !== '';
    });
    
    return '?' . http_build_query($params);
}

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý đơn hàng - Admin <?php echo safe_echo($site_name); ?></title>
    <meta name="description" content="Quản lý đơn hàng - Admin <?php echo safe_echo($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-orders.css">
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
                        <a href="orders.php" class="nav-link active">
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
                            <span>Đơn hàng</span>
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
                    <div class="page-title-wrapper">
                        <h1 class="page-title">Quản lý đơn hàng</h1>
                        <p class="page-subtitle">Quản lý và theo dõi tất cả các đơn hàng trên hệ thống</p>
                    </div>
                    
                    <div class="page-actions">
                        <a href="pos.php" class="btn btn-primary">
                            <span>🛒</span>
                            <span>Quản lý hệ thống POS</span>
                        </a>
                        <button class="btn btn-secondary" id="export-orders">
                            <span>📥</span>
                            <span>Xuất đơn hàng</span>
                        </button>
                    </div>
                </div>
                
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form action="orders.php" method="GET" class="filter-form">
                        <div class="filter-row">
                            <div class="filter-item">
                                <div class="form-group">
                                    <label for="status-filter" class="form-label">Trạng thái</label>
                                    <select id="status-filter" name="status" class="form-control">
                                        <option value="">Tất cả trạng thái</option>
                                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Chờ xử lý</option>
                                        <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Đang xử lý</option>
                                        <option value="shipped" <?php echo $status_filter === 'shipped' ? 'selected' : ''; ?>>Đang giao</option>
                                        <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Đã giao</option>
                                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Đã hủy</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="filter-item">
                                <div class="form-group">
                                    <label for="search-filter" class="form-label">Tìm kiếm</label>
                                    <input type="text" id="search-filter" name="search" class="form-control" placeholder="Mã đơn, tên khách hàng..." value="<?php echo safe_echo($search_term); ?>">
                                </div>
                            </div>
                            
                            <div class="filter-item">
                                <div class="form-group">
                                    <label for="date-from" class="form-label">Từ ngày</label>
                                    <input type="date" id="date-from" name="date_from" class="form-control" value="<?php echo safe_echo($date_from); ?>">
                                </div>
                            </div>
                            
                            <div class="filter-item">
                                <div class="form-group">
                                    <label for="date-to" class="form-label">Đến ngày</label>
                                    <input type="date" id="date-to" name="date_to" class="form-control" value="<?php echo safe_echo($date_to); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="filter-buttons">
                            <button type="submit" class="btn btn-primary">
                                <span>🔍</span>
                                <span>Lọc kết quả</span>
                            </button>
                            <a href="orders.php" class="btn btn-secondary">
                                <span>🔄</span>
                                <span>Đặt lại</span>
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Order Stats -->
                <div class="order-stats">
                    <div class="stat-card primary">
                        <div class="stat-title">Tổng đơn hàng</div>
                        <div class="stat-value"><?php echo number_format($order_stats['total']); ?></div>
                        <div class="stat-subtitle">Tất cả đơn hàng trên hệ thống</div>
                    </div>
                    
                    <div class="stat-card warning">
                        <div class="stat-title">Chờ xử lý</div>
                        <div class="stat-value"><?php echo number_format($order_stats['pending']); ?></div>
                        <div class="stat-subtitle">Đơn hàng đang chờ xử lý</div>
                    </div>
                    
                    <div class="stat-card info">
                        <div class="stat-title">Đang xử lý/giao</div>
                        <div class="stat-value"><?php echo number_format($order_stats['processing'] + $order_stats['shipped']); ?></div>
                        <div class="stat-subtitle">Đơn hàng đang được xử lý</div>
                    </div>
                    
                    <div class="stat-card success">
                        <div class="stat-title">Đã giao</div>
                        <div class="stat-value"><?php echo number_format($order_stats['delivered']); ?></div>
                        <div class="stat-subtitle">Đơn hàng đã giao thành công</div>
                    </div>
                    
                    <div class="stat-card danger">
                        <div class="stat-title">Đã hủy</div>
                        <div class="stat-value"><?php echo number_format($order_stats['cancelled']); ?></div>
                        <div class="stat-subtitle">Đơn hàng đã bị hủy</div>
                    </div>
                    
                    <div class="stat-card secondary">
                        <div class="stat-title">Tổng doanh thu</div>
                        <div class="stat-value"><?php echo formatCurrency($order_stats['total_revenue']); ?></div>
                        <div class="stat-subtitle">Đã thanh toán: <?php echo formatCurrency($order_stats['paid_revenue']); ?></div>
                    </div>
                </div>
                
                <!-- Orders Table -->
                <div class="orders-container">
                    <div class="table-header">
                        <h2 class="table-title">Danh sách đơn hàng</h2>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-secondary" id="print-orders">
                                <span>🖨️</span>
                                <span>In</span>
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="orders-table">
                            <thead>
                                <tr>
                                    <th class="sortable <?php echo $sort_by === 'id' ? 'sorted-' . strtolower($sort_order) : ''; ?>" data-sort="id">ID</th>
                                    <th class="sortable <?php echo $sort_by === 'code' ? 'sorted-' . strtolower($sort_order) : ''; ?>" data-sort="code">Mã đơn</th>
                                    <th>Khách hàng</th>
                                    <th class="sortable <?php echo $sort_by === 'delivery_status' ? 'sorted-' . strtolower($sort_order) : ''; ?>" data-sort="delivery_status">Trạng thái</th>
                                    <th>Thanh toán</th>
                                    <th class="sortable <?php echo $sort_by === 'grand_total' ? 'sorted-' . strtolower($sort_order) : ''; ?>" data-sort="grand_total">Tổng tiền</th>
                                    <th class="sortable <?php echo $sort_by === 'created_at' ? 'sorted-' . strtolower($sort_order) : ''; ?>" data-sort="created_at">Ngày đặt</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($orders)): ?>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td>
                                                <span class="order-id">#<?php echo (int)$order['id']; ?></span>
                                            </td>
                                            <td>
                                                <?php echo safe_echo(get_value($order, 'code', '-')); ?>
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
                                                        <?php if (has_value($order, 'customer_email') || has_value($order, 'customer_phone')): ?>
                                                            <span class="customer-email">
                                                                <?php echo has_value($order, 'customer_email') ? safe_echo($order['customer_email']) : safe_echo($order['customer_phone']); ?>
                                                            </span>
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
                                                <span class="payment-status <?php echo safe_echo(get_value($order, 'payment_status', 'unpaid')); ?>">
                                                    <?php echo get_value($order, 'payment_status', 'unpaid') === 'paid' ? 'Đã thanh toán' : 'Chưa thanh toán'; ?>
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
                                            <td>
                                                <div class="order-actions">
                                                    <button class="btn btn-sm btn-secondary btn-view-order" data-id="<?php echo (int)$order['id']; ?>">
                                                        <span>👁️</span>
                                                    </button>
                                                    <button class="btn btn-sm btn-secondary btn-change-status" data-id="<?php echo (int)$order['id']; ?>" data-current="<?php echo safe_echo(get_value($order, 'delivery_status', 'pending')); ?>">
                                                        <span>📝</span>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger btn-delete-order" data-id="<?php echo (int)$order['id']; ?>">
                                                        <span>🗑️</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <div class="empty-state-icon">📭</div>
                                                <h3 class="empty-state-title">Không tìm thấy đơn hàng nào</h3>
                                                <p class="empty-state-description">
                                                    Không có đơn hàng nào phù hợp với điều kiện tìm kiếm của bạn. Hãy thử thay đổi bộ lọc hoặc tạo đơn hàng mới.
                                                </p>
                                                <a href="orders.php" class="btn btn-primary">
                                                    <span>🔄</span>
                                                    <span>Đặt lại bộ lọc</span>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-container">
                            <?php echo paginate($page, $total_pages, getFilterUrl('%d')); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Order Detail Modal -->
    <div class="modal-backdrop" id="order-detail-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Chi tiết đơn hàng #<span id="modal-order-id"></span></h2>
                <button class="modal-close" id="modal-close">×</button>
            </div>
            <div class="modal-body">
                <!-- Order Details will be loaded here via JavaScript -->
                <div id="order-detail-content"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="modal-close-btn">Đóng</button>
                <button class="btn btn-primary" id="modal-update-status">Cập nhật trạng thái</button>
            </div>
        </div>
    </div>
    
    <!-- Change Status Modal -->
    <div class="modal-backdrop" id="change-status-modal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title">Thay đổi trạng thái đơn hàng</h2>
                <button class="modal-close" id="status-modal-close">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="order-status" class="form-label">Chọn trạng thái mới</label>
                    <select id="order-status" class="form-control">
                        <option value="pending">Chờ xử lý</option>
                        <option value="processing">Đang xử lý</option>
                        <option value="shipped">Đang giao</option>
                        <option value="delivered">Đã giao</option>
                        <option value="cancelled">Đã hủy</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="status-modal-cancel">Hủy</button>
                <button class="btn btn-primary" id="status-modal-save">Lưu thay đổi</button>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal-backdrop" id="delete-confirm-modal">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header">
                <h2 class="modal-title">Xác nhận xóa</h2>
                <button class="modal-close" id="delete-modal-close">×</button>
            </div>
            <div class="modal-body">
                <p>Bạn có chắc chắn muốn xóa đơn hàng này? Hành động này không thể hoàn tác.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="delete-modal-cancel">Hủy</button>
                <button class="btn btn-danger" id="delete-modal-confirm">Xóa</button>
            </div>
        </div>
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
        
        // Table sorting
        document.querySelectorAll('th.sortable').forEach(header => {
            header.addEventListener('click', () => {
                const sort = header.dataset.sort;
                let order = 'asc';
                
                // If already sorted, toggle order
                if (header.classList.contains('sorted-asc')) {
                    order = 'desc';
                } else if (header.classList.contains('sorted-desc')) {
                    order = 'asc';
                }
                
                window.location.href = '<?php echo getFilterUrl(1, []); ?>&sort=' + sort + '&order=' + order;
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
                
                return await response.json();
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
                return { success: false, message: error.message };
            }
        }
        
        // Order detail modal
        const orderDetailModal = document.getElementById('order-detail-modal');
        const orderDetailContent = document.getElementById('order-detail-content');
        const modalOrderId = document.getElementById('modal-order-id');
        
        // View order button click
        document.querySelectorAll('.btn-view-order').forEach(btn => {
            btn.addEventListener('click', async function() {
                const orderId = this.dataset.id;
                modalOrderId.textContent = orderId;
                
                // Show loading
                orderDetailContent.innerHTML = '<div class="empty-state"><div class="empty-state-icon">⌛</div><h3>Đang tải...</h3></div>';
                
                // Show modal
                orderDetailModal.classList.add('active');
                setTimeout(() => {
                    orderDetailModal.querySelector('.modal').classList.add('active');
                }, 10);
                
                // Fetch order details
                const result = await makeRequest('get_order_details', { order_id: orderId });
                
                if (result.success) {
                    // Format order details
                    const order = result.order;
                    const items = result.items;
                    
                    let shippingAddress = '';
                    try {
                        if (order.shipping_address) {
                            const addressObj = JSON.parse(order.shipping_address);
                            shippingAddress = [
                                addressObj.address,
                                addressObj.city,
                                addressObj.state,
                                addressObj.country,
                                addressObj.postal_code
                            ].filter(Boolean).join(', ');
                        }
                    } catch (e) {
                        shippingAddress = order.shipping_address;
                    }
                    
                    // Format created_at date
                    const orderDate = new Date(order.created_at);
                    const formattedDate = orderDate.toLocaleDateString('vi-VN', { 
                        day: '2-digit', 
                        month: '2-digit', 
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    // Delivery status text
                    let deliveryStatusText = '';
                    switch (order.delivery_status) {
                        case 'pending': deliveryStatusText = 'Chờ xử lý'; break;
                        case 'processing': deliveryStatusText = 'Đang xử lý'; break;
                        case 'shipped': deliveryStatusText = 'Đang giao'; break;
                        case 'delivered': deliveryStatusText = 'Đã giao'; break;
                        case 'cancelled': deliveryStatusText = 'Đã hủy'; break;
                        default: deliveryStatusText = order.delivery_status;
                    }
                    
                    // Payment status text
                    const paymentStatusText = order.payment_status === 'paid' ? 'Đã thanh toán' : 'Chưa thanh toán';
                    
                    // Build HTML content
                    let html = `
                        <div class="modal-section">
                            <h3 class="modal-section-title">Thông tin đơn hàng</h3>
                            <div class="order-info-grid">
                                <div class="order-info-item">
                                    <div class="order-info-label">Mã đơn hàng</div>
                                    <div class="order-info-value">${order.code || '-'}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Ngày đặt</div>
                                    <div class="order-info-value">${formattedDate}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Trạng thái giao hàng</div>
                                    <div class="order-info-value">
                                        <span class="order-status ${order.delivery_status}">${deliveryStatusText}</span>
                                    </div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Trạng thái thanh toán</div>
                                    <div class="order-info-value">
                                        <span class="payment-status ${order.payment_status}">${paymentStatusText}</span>
                                    </div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Phương thức thanh toán</div>
                                    <div class="order-info-value">${order.payment_type || 'Không có thông tin'}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Phương thức vận chuyển</div>
                                    <div class="order-info-value">${order.shipping_type || 'Không có thông tin'}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="modal-section">
                            <h3 class="modal-section-title">Thông tin khách hàng</h3>
                            <div class="order-info-grid">
                                <div class="order-info-item">
                                    <div class="order-info-label">Tên khách hàng</div>
                                    <div class="order-info-value">${order.customer_name || 'Khách vãng lai'}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Email</div>
                                    <div class="order-info-value">${order.customer_email || 'Không có thông tin'}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Số điện thoại</div>
                                    <div class="order-info-value">${order.customer_phone || 'Không có thông tin'}</div>
                                </div>
                                <div class="order-info-item">
                                    <div class="order-info-label">Địa chỉ giao hàng</div>
                                    <div class="order-info-value">${shippingAddress || 'Không có thông tin'}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="modal-section">
                            <h3 class="modal-section-title">Sản phẩm đã đặt</h3>
                            <div class="table-responsive">
                                <table class="order-items-table">
                                    <thead>
                                        <tr>
                                            <th>Sản phẩm</th>
                                            <th>Đơn giá</th>
                                            <th>Số lượng</th>
                                            <th>Thành tiền</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                    `;
                    
                    // Calculate totals
                    let subtotal = 0;
                    let totalTax = 0;
                    let totalShipping = 0;
                    
                    items.forEach(item => {
                        const itemTotal = parseFloat(item.price) * parseInt(item.quantity);
                        subtotal += itemTotal;
                        totalTax += parseFloat(item.tax) || 0;
                        totalShipping += parseFloat(item.shipping_cost) || 0;
                        
                        // Format variation if exists
                        let variationText = '';
                        try {
                            if (item.variation) {
                                const variation = JSON.parse(item.variation);
                                variationText = Object.entries(variation)
                                    .map(([key, value]) => `${key}: ${value}`)
                                    .join(', ');
                            }
                        } catch (e) {
                            variationText = item.variation;
                        }
                        
                        html += `
                            <tr>
                                <td>
                                    <div class="product-info">
                                        <img src="${item.product_image ? '../' + item.product_image : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><rect width="40" height="40" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="14" fill="%236b7280">📦</text></svg>'}" alt="${item.product_name}" class="product-image">
                                        <div class="product-details">
                                            <div class="product-name">${item.product_name}</div>
                                            ${variationText ? `<div class="product-variant">${variationText}</div>` : ''}
                                        </div>
                                    </div>
                                </td>
                                <td>${formatCurrency(item.price)}</td>
                                <td>${item.quantity}</td>
                                <td>${formatCurrency(itemTotal)}</td>
                            </tr>
                        `;
                    });
                    
                    const couponDiscount = parseFloat(order.coupon_discount) || 0;
                    const grandTotal = subtotal + totalTax + totalShipping - couponDiscount;
                    
                    html += `
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="order-total-section">
                                <div class="order-total-row">
                                    <div class="order-total-label">Tạm tính</div>
                                    <div class="order-total-value">${formatCurrency(subtotal)}</div>
                                </div>
                                ${totalTax > 0 ? `
                                <div class="order-total-row">
                                    <div class="order-total-label">Thuế</div>
                                    <div class="order-total-value">${formatCurrency(totalTax)}</div>
                                </div>
                                ` : ''}
                                ${totalShipping > 0 ? `
                                <div class="order-total-row">
                                    <div class="order-total-label">Phí vận chuyển</div>
                                    <div class="order-total-value">${formatCurrency(totalShipping)}</div>
                                </div>
                                ` : ''}
                                ${couponDiscount > 0 ? `
                                <div class="order-total-row">
                                    <div class="order-total-label">Giảm giá</div>
                                    <div class="order-total-value">-${formatCurrency(couponDiscount)}</div>
                                </div>
                                ` : ''}
                                <div class="order-total-row grand-total">
                                    <div class="order-total-label">Tổng thanh toán</div>
                                    <div class="order-total-value">${formatCurrency(order.grand_total)}</div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    orderDetailContent.innerHTML = html;
                    
                    // Set current order ID for update status button
                    document.getElementById('modal-update-status').dataset.id = orderId;
                    document.getElementById('modal-update-status').dataset.current = order.delivery_status;
                    
                } else {
                    orderDetailContent.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state-icon">❌</div>
                            <h3 class="empty-state-title">Lỗi khi tải dữ liệu</h3>
                            <p>${result.message || 'Không thể tải thông tin đơn hàng. Vui lòng thử lại sau.'}</p>
                        </div>
                    `;
                }
            });
        });
        
        // Close order detail modal
        document.getElementById('modal-close').addEventListener('click', closeOrderDetailModal);
        document.getElementById('modal-close-btn').addEventListener('click', closeOrderDetailModal);
        
        function closeOrderDetailModal() {
            orderDetailModal.querySelector('.modal').classList.remove('active');
            setTimeout(() => {
                orderDetailModal.classList.remove('active');
            }, 300);
        }
        
        // Update order status modal
        const changeStatusModal = document.getElementById('change-status-modal');
        const orderStatusSelect = document.getElementById('order-status');
        let currentOrderId = null;
        
        // Show change status modal from detail modal
        document.getElementById('modal-update-status').addEventListener('click', function() {
            const orderId = this.dataset.id;
            const currentStatus = this.dataset.current;
            
            // Set current order ID
            currentOrderId = orderId;
            
            // Set current status in select
            orderStatusSelect.value = currentStatus;
            
            // Show modal
            changeStatusModal.classList.add('active');
            setTimeout(() => {
                changeStatusModal.querySelector('.modal').classList.add('active');
            }, 10);
        });
        
        // Show change status modal from list
        document.querySelectorAll('.btn-change-status').forEach(btn => {
            btn.addEventListener('click', function() {
                const orderId = this.dataset.id;
                const currentStatus = this.dataset.current;
                
                // Set current order ID
                currentOrderId = orderId;
                
                // Set current status in select
                orderStatusSelect.value = currentStatus;
                
                // Show modal
                changeStatusModal.classList.add('active');
                setTimeout(() => {
                    changeStatusModal.querySelector('.modal').classList.add('active');
                }, 10);
            });
        });
        
        // Close change status modal
        document.getElementById('status-modal-close').addEventListener('click', closeChangeStatusModal);
        document.getElementById('status-modal-cancel').addEventListener('click', closeChangeStatusModal);
        
        function closeChangeStatusModal() {
            changeStatusModal.querySelector('.modal').classList.remove('active');
            setTimeout(() => {
                changeStatusModal.classList.remove('active');
                currentOrderId = null;
            }, 300);
        }
        
        // Save status change
        document.getElementById('status-modal-save').addEventListener('click', async function() {
            if (!currentOrderId) {
                showNotification('Không tìm thấy ID đơn hàng', 'error');
                return;
            }
            
            const newStatus = orderStatusSelect.value;
            
            // Show loading state
            this.disabled = true;
            this.innerHTML = '⌛ Đang xử lý...';
            
            // Update status
            const result = await makeRequest('update_order_status', {
                order_id: currentOrderId,
                status: newStatus
            });
            
            if (result.success) {
                showNotification(result.message, 'success');
                closeChangeStatusModal();
                
                // Reload page after a short delay
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showNotification(result.message || 'Có lỗi xảy ra khi cập nhật trạng thái', 'error');
                this.disabled = false;
                this.innerHTML = 'Lưu thay đổi';
            }
        });
        
        // Delete order confirmation
        const deleteConfirmModal = document.getElementById('delete-confirm-modal');
        let deleteOrderId = null;
        
        // Show delete confirmation modal
        document.querySelectorAll('.btn-delete-order').forEach(btn => {
            btn.addEventListener('click', function() {
                deleteOrderId = this.dataset.id;
                
                // Show modal
                deleteConfirmModal.classList.add('active');
                setTimeout(() => {
                    deleteConfirmModal.querySelector('.modal').classList.add('active');
                }, 10);
            });
        });
        
        // Close delete confirmation modal
        document.getElementById('delete-modal-close').addEventListener('click', closeDeleteModal);
        document.getElementById('delete-modal-cancel').addEventListener('click', closeDeleteModal);
        
        function closeDeleteModal() {
            deleteConfirmModal.querySelector('.modal').classList.remove('active');
            setTimeout(() => {
                deleteConfirmModal.classList.remove('active');
                deleteOrderId = null;
            }, 300);
        }
        
        // Confirm delete
        document.getElementById('delete-modal-confirm').addEventListener('click', async function() {
            if (!deleteOrderId) {
                showNotification('Không tìm thấy ID đơn hàng', 'error');
                return;
            }
            
            // Show loading state
            this.disabled = true;
            this.innerHTML = '⌛ Đang xử lý...';
            
            // Delete order
            const result = await makeRequest('delete_order', {
                order_id: deleteOrderId
            });
            
            if (result.success) {
                showNotification(result.message, 'success');
                closeDeleteModal();
                
                // Reload page after a short delay
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showNotification(result.message || 'Có lỗi xảy ra khi xóa đơn hàng', 'error');
                this.disabled = false;
                this.innerHTML = 'Xóa';
            }
        });
        
        // Export orders
        document.getElementById('export-orders').addEventListener('click', function() {
            showNotification('Đang chuẩn bị xuất dữ liệu đơn hàng...', 'info');
            
            // Simulate export process
            setTimeout(() => {
                showNotification('Đã xuất dữ liệu đơn hàng thành công', 'success');
            }, 1500);
        });
        
        // Print orders
        document.getElementById('print-orders').addEventListener('click', function() {
            showNotification('Đang chuẩn bị in danh sách đơn hàng...', 'info');
            
            // Simulate print process
            setTimeout(() => {
                window.print();
            }, 500);
        });
        
        // Format currency helper
        ).format(amount);
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
            console.log('🚀 Orders - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Orders - Ready!');
        });
    </script>
</body>
</html>