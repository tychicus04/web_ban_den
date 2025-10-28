<?php
session_start();
require_once 'config.php';

// Set page-specific variables
$current_page = 'orders';
$require_login = true;

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get user info
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_type = $_SESSION['user_type'];

// Handle order actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'cancel_order':
                $order_id = $_POST['order_id'];

                // Check if order belongs to user and can be cancelled
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? AND delivery_status = 'pending'");
                $stmt->execute([$order_id, $user_id]);
                $order = $stmt->fetch();

                if ($order) {
                    // Update order status
                    $stmt = $pdo->prepare("UPDATE orders SET delivery_status = 'cancelled' WHERE id = ?");
                    $stmt->execute([$order_id]);

                    // Update order details status
                    $stmt = $pdo->prepare("UPDATE order_details SET delivery_status = 'cancelled' WHERE order_id = ?");
                    $stmt->execute([$order_id]);

                    $message = 'Đã hủy đơn hàng thành công!';
                    $message_type = 'success';
                } else {
                    $message = 'Không thể hủy đơn hàng này!';
                    $message_type = 'error';
                }
                break;
        }
    } catch (PDOException $e) {
        $message = 'Có lỗi xảy ra: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Get filters and search parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Pagination parameters
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build WHERE clause for filters
$where_conditions = ["o.user_id = ?"];
$params = [$user_id];

if ($status_filter !== 'all') {
    $where_conditions[] = "o.delivery_status = ?";
    $params[] = $status_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = "(o.id = ? OR o.tracking_code LIKE ?)";
    $params[] = $search_query;
    $params[] = "%$search_query%";
}

if (!empty($date_from)) {
    $where_conditions[] = "FROM_UNIXTIME(o.date) >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if (!empty($date_to)) {
    $where_conditions[] = "FROM_UNIXTIME(o.date) <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$where_clause = implode(' AND ', $where_conditions);

// Get orders with order details count and total items
try {
    $sql = "SELECT o.*, 
                   COUNT(od.id) as item_count,
                   SUM(od.quantity) as total_quantity,
                   (SELECT s.name FROM users s WHERE s.id = o.seller_id) as seller_name
            FROM orders o 
            LEFT JOIN order_details od ON o.id = od.order_id 
            WHERE $where_clause
            GROUP BY o.id 
            ORDER BY o.created_at DESC 
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    // Bind filter parameters
    foreach ($params as $index => $param) {
        $stmt->bindValue($index + 1, $param);
    }

    $stmt->execute();
    $orders = $stmt->fetchAll();

    // Get total count for pagination
    $count_sql = "SELECT COUNT(DISTINCT o.id) FROM orders o WHERE $where_clause";
    $count_stmt = $pdo->prepare($count_sql);

    foreach ($params as $index => $param) {
        $count_stmt->bindValue($index + 1, $param);
    }

    $count_stmt->execute();
    $total_orders = $count_stmt->fetchColumn();
    $total_pages = ceil($total_orders / $limit);
} catch (PDOException $e) {
    $orders = [];
    $total_orders = 0;
    $total_pages = 1;
}

// Get order statistics
try {
    $stats_stmt = $pdo->prepare("SELECT 
                          COUNT(*) as total_orders,
                          SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) as completed_orders,
                          SUM(CASE WHEN delivery_status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                          SUM(CASE WHEN delivery_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
                          SUM(CASE WHEN delivery_status = 'shipped' THEN 1 ELSE 0 END) as shipped_orders,
                          SUM(grand_total) as total_spent
                          FROM orders WHERE user_id = ?");
    $stats_stmt->execute([$user_id]);
    $order_stats = $stats_stmt->fetch();
} catch (PDOException $e) {
    $order_stats = [
        'total_orders' => 0,
        'completed_orders' => 0,
        'pending_orders' => 0,
        'cancelled_orders' => 0,
        'shipped_orders' => 0,
        'total_spent' => 0
    ];
}

// Function to get status badge class
function getStatusBadgeClass($status)
{
    $classes = [
        'pending' => 'status-pending',
        'shipped' => 'status-shipped',
        'delivered' => 'status-delivered',
        'cancelled' => 'status-cancelled'
    ];
    return $classes[$status] ?? 'status-default';
}

// Function to get status text in Vietnamese
function getStatusText($status)
{
    $texts = [
        'pending' => 'Đang xử lý',
        'shipped' => 'Đang giao',
        'delivered' => 'Đã giao',
        'cancelled' => 'Đã hủy'
    ];
    return $texts[$status] ?? ucfirst($status);
}

// Function to get payment status text
function getPaymentStatusText($status)
{
    $texts = [
        'paid' => 'Đã thanh toán',
        'unpaid' => 'Chưa thanh toán',
        'refunded' => 'Đã hoàn tiền'
    ];
    return $texts[$status] ?? ucfirst($status);
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đơn hàng của tôi - TikTok Shop</title>
    <meta name="description" content="Quản lý và theo dõi đơn hàng của bạn trên TikTok Shop">
    <!-- CSS Files -->
    <link rel="stylesheet" href="asset/css/global.css">
    <link rel="stylesheet" href="asset/css/components.css">
    <link rel="stylesheet" href="asset/css/base.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <style>
    .orders-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    .page-header {
        background: white;
        border-radius: 12px;
        padding: 30px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .page-title {
        font-size: 32px;
        color: #333;
        margin-bottom: 10px;
    }

    .page-subtitle {
        color: #666;
        font-size: 16px;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin: 20px 0;
    }

    .stat-card {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 12px;
        text-align: center;
        border: 2px solid transparent;
        transition: all 0.3s;
    }

    .stat-card:hover {
        border-color: #fe2c55;
        transform: translateY(-2px);
    }

    .stat-number {
        font-size: 28px;
        font-weight: bold;
        color: #fe2c55;
        margin-bottom: 5px;
    }

    .stat-label {
        color: #666;
        font-size: 14px;
    }

    .filters-section {
        background: white;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .filter-row {
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 20px;
        align-items: end;
        margin-bottom: 20px;
    }

    .status-filters {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .filter-btn {
        padding: 10px 20px;
        border: 2px solid #e0e6ed;
        background: white;
        border-radius: 25px;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        color: #333;
        font-size: 14px;
        font-weight: 500;
    }

    .filter-btn:hover,
    .filter-btn.active {
        background: #fe2c55;
        border-color: #fe2c55;
        color: white;
    }

    .search-group {
        display: flex;
        gap: 15px;
        align-items: end;
    }

    .form-group {
        display: flex;
        flex-direction: column;
    }

    .form-group label {
        margin-bottom: 5px;
        color: #333;
        font-size: 14px;
        font-weight: 500;
    }

    .form-group input,
    .form-group select {
        padding: 10px;
        border: 2px solid #e0e6ed;
        border-radius: 8px;
        font-size: 14px;
    }

    .btn {
        background: #fe2c55;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        transition: background 0.3s;
        height: fit-content;
    }

    .btn:hover {
        background: #e91e63;
    }

    .btn-secondary {
        background: #6c757d;
    }

    .btn-secondary:hover {
        background: #5a6268;
    }

    .orders-section {
        background: white;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
    }

    .section-title {
        font-size: 24px;
        color: #333;
        margin: 0;
    }

    .results-info {
        color: #666;
        font-size: 14px;
    }

    .orders-grid {
        display: grid;
        gap: 20px;
    }

    .order-card {
        border: 2px solid #e0e6ed;
        border-radius: 12px;
        padding: 25px;
        transition: all 0.3s;
    }

    .order-card:hover {
        border-color: #fe2c55;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(254, 44, 85, 0.1);
    }

    .order-header {
        display: grid;
        grid-template-columns: 1fr auto auto;
        gap: 20px;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid #e0e6ed;
    }

    .order-id-section h3 {
        margin: 0 0 5px 0;
        color: #333;
        font-size: 18px;
    }

    .order-date {
        color: #666;
        font-size: 14px;
    }

    .order-status {
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-pending {
        background: #fff3cd;
        color: #856404;
    }

    .status-shipped {
        background: #cff4fc;
        color: #055160;
    }

    .status-delivered {
        background: #d1edff;
        color: #084298;
    }

    .status-cancelled {
        background: #f8d7da;
        color: #721c24;
    }

    .order-total {
        font-size: 20px;
        font-weight: bold;
        color: #fe2c55;
    }

    .order-body {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 20px;
        align-items: center;
    }

    .order-info {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }

    .info-item {
        display: flex;
        flex-direction: column;
    }

    .info-label {
        font-size: 12px;
        color: #666;
        text-transform: uppercase;
        margin-bottom: 5px;
    }

    .info-value {
        font-size: 14px;
        color: #333;
        font-weight: 500;
    }

    .order-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .order-actions .btn {
        padding: 8px 16px;
        font-size: 12px;
        white-space: nowrap;
    }

    .btn-outline {
        background: transparent;
        color: #fe2c55;
        border: 2px solid #fe2c55;
    }

    .btn-outline:hover {
        background: #fe2c55;
        color: white;
    }

    .btn-danger {
        background: #dc3545;
    }

    .btn-danger:hover {
        background: #c82333;
    }

    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-top: 30px;
    }

    .pagination-btn {
        padding: 10px 15px;
        border: 2px solid #e0e6ed;
        background: white;
        border-radius: 8px;
        color: #333;
        text-decoration: none;
        transition: all 0.3s;
    }

    .pagination-btn:hover,
    .pagination-btn.active {
        background: #fe2c55;
        border-color: #fe2c55;
        color: white;
    }

    .pagination-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .no-orders {
        text-align: center;
        padding: 60px 20px;
        color: #666;
    }

    .no-orders-icon {
        font-size: 64px;
        margin-bottom: 20px;
    }

    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .alert-success {
        background: #d1edff;
        color: #084298;
        border: 1px solid #b6d7ff;
    }

    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c2c7;
    }

    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
    }

    .modal-content {
        background-color: white;
        margin: 5% auto;
        padding: 30px;
        border-radius: 12px;
        width: 90%;
        max-width: 800px;
        max-height: 80vh;
        overflow-y: auto;
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid #e0e6ed;
    }

    .modal-title {
        font-size: 24px;
        color: #333;
        margin: 0;
    }

    .close {
        font-size: 28px;
        cursor: pointer;
        color: #666;
    }

    .close:hover {
        color: #fe2c55;
    }

    @media (max-width: 768px) {
        .orders-container {
            padding: 10px;
        }

        .filter-row {
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .search-group {
            flex-direction: column;
        }

        .order-header {
            grid-template-columns: 1fr;
            gap: 10px;
            text-align: center;
        }

        .order-body {
            grid-template-columns: 1fr;
        }

        .order-info {
            grid-template-columns: 1fr;
        }

        .order-actions {
            flex-direction: row;
            justify-content: center;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>

    <div class="orders-container">
        <!-- Page Header -->
        <header class="page-header">
            <h1 class="page-title">Đơn hàng của tôi</h1>
            <p class="page-subtitle">Quản lý và theo dõi tất cả đơn hàng của bạn</p>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $order_stats['total_orders']; ?></div>
                    <div class="stat-label">Tổng đơn hàng</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $order_stats['completed_orders']; ?></div>
                    <div class="stat-label">Đã hoàn thành</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $order_stats['pending_orders']; ?></div>
                    <div class="stat-label">Đang xử lý</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $order_stats['shipped_orders']; ?></div>
                    <div class="stat-label">Đang giao</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($order_stats['total_spent'], 0, ',', '.'); ?>đ
                    </div>
                    <div class="stat-label">Tổng chi tiêu</div>
                </div>
            </div>
        </header>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <span><?php echo $message_type === 'success' ? '✅' : '❌'; ?></span>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <!-- Filters Section -->
        <section class="filters-section">
            <form method="GET" action="">
                <div class="filter-row">
                    <!-- Status Filters -->
                    <div class="status-filters">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'all', 'page' => 1])); ?>"
                            class="filter-btn <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                            Tất cả (<?php echo $order_stats['total_orders']; ?>)
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'pending', 'page' => 1])); ?>"
                            class="filter-btn <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                            Đang xử lý (<?php echo $order_stats['pending_orders']; ?>)
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'shipped', 'page' => 1])); ?>"
                            class="filter-btn <?php echo $status_filter === 'shipped' ? 'active' : ''; ?>">
                            Đang giao (<?php echo $order_stats['shipped_orders']; ?>)
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'delivered', 'page' => 1])); ?>"
                            class="filter-btn <?php echo $status_filter === 'delivered' ? 'active' : ''; ?>">
                            Đã giao (<?php echo $order_stats['completed_orders']; ?>)
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'cancelled', 'page' => 1])); ?>"
                            class="filter-btn <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">
                            Đã hủy (<?php echo $order_stats['cancelled_orders']; ?>)
                        </a>
                    </div>

                    <!-- Search and Date Filters -->
                    <div class="search-group">
                        <div class="form-group">
                            <label for="search">Tìm kiếm</label>
                            <input type="text" id="search" name="search" placeholder="Mã đơn hàng, mã vận đơn..."
                                value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>

                        <div class="form-group">
                            <label for="date_from">Từ ngày</label>
                            <input type="date" id="date_from" name="date_from"
                                value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>

                        <div class="form-group">
                            <label for="date_to">Đến ngày</label>
                            <input type="date" id="date_to" name="date_to"
                                value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn">🔍 Lọc</button>
                </div>
            </form>
        </section>

        <!-- Orders Section -->
        <section class="orders-section">
            <div class="section-header">
                <h2 class="section-title">
                    <?php
                    $status_titles = [
                        'all' => 'Tất cả đơn hàng',
                        'pending' => 'Đơn hàng đang xử lý',
                        'shipped' => 'Đơn hàng đang giao',
                        'delivered' => 'Đơn hàng đã giao',
                        'cancelled' => 'Đơn hàng đã hủy'
                    ];
                    echo $status_titles[$status_filter] ?? 'Đơn hàng';
                    ?>
                </h2>
                <div class="results-info">
                    Trang <?php echo $page; ?> / <?php echo $total_pages; ?>
                    (<?php echo $total_orders; ?> đơn hàng)
                </div>
            </div>

            <?php if (!empty($orders)): ?>
            <div class="orders-grid">
                <?php foreach ($orders as $order): ?>
                <div class="order-card">
                    <div class="order-header">
                        <div class="order-id-section">
                            <h3>Đơn hàng #<?php echo $order['id']; ?></h3>
                            <div class="order-date">
                                <?php echo date('d/m/Y H:i', $order['date']); ?>
                            </div>
                            <?php if ($order['tracking_code']): ?>
                            <div class="order-date">
                                Mã vận đơn: <strong><?php echo htmlspecialchars($order['tracking_code']); ?></strong>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="order-status <?php echo getStatusBadgeClass($order['delivery_status']); ?>">
                            <?php echo getStatusText($order['delivery_status']); ?>
                        </div>

                        <div class="order-total">
                            <?php echo number_format($order['grand_total'], 0, ',', '.'); ?>đ
                        </div>
                    </div>

                    <div class="order-body">
                        <div class="order-info">
                            <div class="info-item">
                                <div class="info-label">Số lượng sản phẩm</div>
                                <div class="info-value"><?php echo $order['total_quantity']; ?> sản phẩm</div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">Thanh toán</div>
                                <div class="info-value"><?php echo getPaymentStatusText($order['payment_status']); ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">Phương thức thanh toán</div>
                                <div class="info-value">
                                    <?php
                                            $payment_types = [
                                                'cash_on_delivery' => 'Thanh toán khi nhận hàng',
                                                'wallet' => 'Ví điện tử',
                                                'bank_transfer' => 'Chuyển khoản',
                                                'credit_card' => 'Thẻ tín dụng'
                                            ];
                                            echo $payment_types[$order['payment_type']] ?? ucfirst($order['payment_type']);
                                            ?>
                                </div>
                            </div>

                            <?php if ($order['seller_name']): ?>
                            <div class="info-item">
                                <div class="info-label">Người bán</div>
                                <div class="info-value"><?php echo htmlspecialchars($order['seller_name']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="order-actions">
                            <button class="btn btn-outline" onclick="viewOrderDetails(<?php echo $order['id']; ?>)">
                                📋 Chi tiết
                            </button>

                            <?php if ($order['delivery_status'] === 'pending'): ?>
                            <button class="btn btn-danger" onclick="cancelOrder(<?php echo $order['id']; ?>)">
                                ❌ Hủy đơn
                            </button>
                            <?php endif; ?>

                            <?php if ($order['delivery_status'] === 'delivered'): ?>
                            <button class="btn btn-secondary" onclick="reorderItems(<?php echo $order['id']; ?>)">
                                🔄 Mua lại
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
                    class="pagination-btn">‹ Trước</a>
                <?php endif; ?>

                <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);

                        for ($i = $start; $i <= $end; $i++):
                            ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                    class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
                    class="pagination-btn">Tiếp ›</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="no-orders">
                <div class="no-orders-icon">📦</div>
                <h3>Không có đơn hàng nào</h3>
                <p>
                    <?php if ($status_filter === 'all'): ?>
                    Bạn chưa có đơn hàng nào. Hãy bắt đầu mua sắm ngay!
                    <?php else: ?>
                    Không tìm thấy đơn hàng nào với điều kiện lọc hiện tại.
                    <?php endif; ?>
                </p>
                <a href="index.php" class="btn" style="margin-top: 20px;">🛒 Bắt đầu mua sắm</a>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Order Details Modal -->
    <div id="orderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Chi tiết đơn hàng</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div id="orderModalBody">
                <!-- Order details will be loaded here -->
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <script>
    function viewOrderDetails(orderId) {
        const modal = document.getElementById('orderModal');
        const modalBody = document.getElementById('orderModalBody');

        modal.style.display = 'block';
        modalBody.innerHTML = '<p>Đang tải...</p>';

        // AJAX call to get order details
        fetch(`order-details.php?id=${orderId}`)
            .then(response => response.text())
            .then(html => {
                modalBody.innerHTML = html;
            })
            .catch(error => {
                modalBody.innerHTML = '<p>Có lỗi xảy ra khi tải chi tiết đơn hàng.</p>';
            });
    }

    function closeModal() {
        document.getElementById('orderModal').style.display = 'none';
    }

    function cancelOrder(orderId) {
        if (confirm('Bạn có chắc chắn muốn hủy đơn hàng này?')) {
            // Create form to submit cancellation
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                    <input type="hidden" name="action" value="cancel_order">
                    <input type="hidden" name="order_id" value="${orderId}">
                `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    function reorderItems(orderId) {
        if (confirm('Bạn muốn mua lại tất cả sản phẩm trong đơn hàng này?')) {
            fetch('reorder.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        order_id: orderId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Đã thêm sản phẩm vào giỏ hàng!');
                        // Update cart count if function exists
                        if (typeof updateCartCount === 'function') {
                            updateCartCount();
                        }
                    } else {
                        alert(data.message || 'Có lỗi xảy ra!');
                    }
                })
                .catch(error => {
                    alert('Có lỗi xảy ra khi thêm sản phẩm vào giỏ hàng!');
                });
        }
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('orderModal');
        if (event.target === modal) {
            closeModal();
        }
    }

    // Auto-submit form when date inputs change
    document.getElementById('date_from').addEventListener('change', function() {
        if (this.value && document.getElementById('date_to').value) {
            this.form.submit();
        }
    });

    document.getElementById('date_to').addEventListener('change', function() {
        if (this.value && document.getElementById('date_from').value) {
            this.form.submit();
        }
    });
    </script>
    <!-- JavaScript Files -->
    <script src="asset/js/global.js"></script>
    <script src="asset/js/components.js"></script>
</body>

</html>