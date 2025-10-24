<?php
require_once '../config.php';
session_start();

// Check if seller is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'seller') {
    header('Location: login.php');
    exit;
}

$seller_id = $_SESSION['user_id'];
$seller_name = $_SESSION['user_name'];

// Messages
$success_message = '';
$error_message = '';

// Get seller balance
try {
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$seller_id]);
    $seller_balance = $stmt->fetchColumn() ?? 0;
} catch (PDOException $e) {
    $seller_balance = 0;
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $order_id = intval($_POST['order_id']);
    $new_status = $_POST['delivery_status'];
    $tracking_code = $_POST['tracking_code'] ?? '';
    
    try {
        // Verify order belongs to seller
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND seller_id = ?");
        $stmt->execute([$order_id, $seller_id]);
        
        if ($stmt->fetch()) {
            // Update order status
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET delivery_status = ?, tracking_code = ?, updated_at = NOW() 
                WHERE id = ? AND seller_id = ?
            ");
            $stmt->execute([$new_status, $tracking_code, $order_id, $seller_id]);
            
            // Also update order details
            $stmt = $pdo->prepare("
                UPDATE order_details 
                SET delivery_status = ?, updated_at = NOW() 
                WHERE order_id = ?
            ");
            $stmt->execute([$new_status, $order_id]);
            
            $success_message = "Cập nhật trạng thái đơn hàng thành công!";
        } else {
            $error_message = "Không tìm thấy đơn hàng hoặc bạn không có quyền cập nhật.";
        }
    } catch (PDOException $e) {
        $error_message = "Có lỗi xảy ra khi cập nhật trạng thái.";
        error_log("Update order status error: " . $e->getMessage());
    }
}

// Filters and pagination
$status_filter = $_GET['status'] ?? '';
$search_query = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = ["o.seller_id = ?"];
$params = [$seller_id];

if (!empty($status_filter)) {
    $where_conditions[] = "o.delivery_status = ?";
    $params[] = $status_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = "(o.id LIKE ? OR u.name LIKE ? OR o.tracking_code LIKE ?)";
    $search_param = "%{$search_query}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get order statistics
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN delivery_status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
            SUM(CASE WHEN delivery_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_orders,
            SUM(CASE WHEN delivery_status = 'shipping' THEN 1 ELSE 0 END) as shipping_orders,
            SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
            SUM(CASE WHEN delivery_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders
        FROM orders o
        WHERE o.seller_id = ?
    ");
    $stmt->execute([$seller_id]);
    $order_stats = $stmt->fetch();
} catch (PDOException $e) {
    $order_stats = [
        'total_orders' => 0, 'pending_orders' => 0, 'confirmed_orders' => 0,
        'shipping_orders' => 0, 'delivered_orders' => 0, 'cancelled_orders' => 0
    ];
}

// Get total count for pagination
try {
    $count_sql = "
        SELECT COUNT(DISTINCT o.id) as total
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        {$where_clause}
    ";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_orders = $stmt->fetchColumn();
    $total_pages = ceil($total_orders / $per_page);
} catch (PDOException $e) {
    $total_orders = 0;
    $total_pages = 1;
}

// Get orders
try {
    $sql = "
        SELECT 
            o.*,
            u.name as customer_name,
            u.email as customer_email,
            u.phone as customer_phone,
            COUNT(od.id) as item_count,
            SUM(od.quantity) as total_quantity
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN order_details od ON o.id = od.order_id
        {$where_clause}
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT {$per_page} OFFSET {$offset}
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
} catch (PDOException $e) {
    $orders = [];
    error_log("Get orders error: " . $e->getMessage());
}

// Status options
$status_options = [
    'pending' => 'Chờ xử lý',
    'confirmed' => 'Đã xác nhận', 
    'shipping' => 'Đang giao',
    'delivered' => 'Đã giao',
    'cancelled' => 'Đã hủy'
];

// Payment status options
$payment_status_options = [
    'unpaid' => 'Chưa thanh toán',
    'paid' => 'Đã thanh toán',
    'partial' => 'Thanh toán 1 phần'
];

// Helper functions
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'pending': return 'status-pending';
        case 'confirmed': return 'status-confirmed';
        case 'shipping': return 'status-shipping';
        case 'delivered': return 'status-delivered';
        case 'cancelled': return 'status-cancelled';
        default: return 'status-pending';
    }
}

function getPaymentStatusBadgeClass($status) {
    switch ($status) {
        case 'paid': return 'status-paid';
        case 'unpaid': return 'status-unpaid';
        case 'partial': return 'status-partial';
        default: return 'status-unpaid';
    }
}

function canUpdateStatus($current_status) {
    return !in_array($current_status, ['delivered', 'cancelled']);
}

function getNextStatuses($current_status) {
    switch ($current_status) {
        case 'pending':
            return ['confirmed' => 'Xác nhận đơn hàng', 'cancelled' => 'Hủy đơn hàng'];
        case 'confirmed':
            return ['shipping' => 'Bắt đầu giao hàng', 'cancelled' => 'Hủy đơn hàng'];
        case 'shipping':
            return ['delivered' => 'Đã giao thành công'];
        default:
            return [];
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý đơn hàng - TikTok Shop Seller</title>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #f8fafc;
        color: #333;
    }

    .layout {
        display: flex;
        min-height: 100vh;
    }

    .main-content {
        flex: 1;
        margin-left: 280px;
        min-height: 100vh;
    }

    .top-header {
        background: white;
        padding: 16px 24px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 50;
    }

    .header-left {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .header-left h1 {
        font-size: 24px;
        color: #1f2937;
        font-weight: 600;
    }

    .mobile-menu-btn {
        display: none;
        background: none;
        border: none;
        cursor: pointer;
        color: #4b5563;
        padding: 8px;
        border-radius: 6px;
        transition: all 0.3s ease;
    }

    .mobile-menu-btn:hover {
        background: #f3f4f6;
        color: #ff0050;
    }

    .header-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .user-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        background: linear-gradient(45deg, #ff0050, #ff4d6d);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 16px;
    }

    .user-details h3 {
        font-size: 14px;
        font-weight: 600;
        color: #1f2937;
        margin: 0;
    }

    .user-balance {
        font-size: 12px;
        color: #10b981;
        font-weight: 500;
    }

    .content-wrapper {
        padding: 24px;
    }

    /* Order Statistics */
    .stats-overview {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        text-align: center;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .stat-card.active {
        border-color: #ff0050;
        background: #fef2f2;
    }

    .stat-card .number {
        font-size: 24px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 8px;
    }

    .stat-card .label {
        font-size: 12px;
        color: #6b7280;
        font-weight: 500;
        text-transform: uppercase;
    }

    /* Filters Section */
    .filters-section {
        background: white;
        padding: 20px 24px;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        margin-bottom: 24px;
    }

    .filters-row {
        display: flex;
        gap: 16px;
        align-items: center;
        flex-wrap: wrap;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .filter-label {
        font-size: 12px;
        font-weight: 500;
        color: #6b7280;
        text-transform: uppercase;
    }

    .filter-select {
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
        min-width: 150px;
    }

    .search-box {
        position: relative;
        flex: 1;
        max-width: 300px;
    }

    .search-input {
        width: 100%;
        padding: 8px 12px 8px 36px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
    }

    .search-input:focus {
        outline: none;
        border-color: #ff0050;
    }

    .search-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
    }

    .filter-btn {
        padding: 8px 16px;
        background: #ff0050;
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .filter-btn:hover {
        background: #dc2626;
    }

    .reset-btn {
        padding: 8px 16px;
        background: #6b7280;
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .reset-btn:hover {
        background: #4b5563;
    }

    /* Orders Table */
    .orders-section {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    .orders-header {
        padding: 20px 24px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .orders-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
    }

    .orders-count {
        font-size: 14px;
        color: #6b7280;
    }

    .orders-table {
        width: 100%;
        border-collapse: collapse;
    }

    .orders-table th {
        text-align: left;
        padding: 16px 24px;
        font-size: 12px;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
        background: #f9fafb;
        border-bottom: 1px solid #e5e7eb;
    }

    .orders-table td {
        padding: 16px 24px;
        border-bottom: 1px solid #f3f4f6;
        font-size: 14px;
        vertical-align: top;
    }

    .orders-table tr:hover {
        background: #f9fafb;
    }

    .order-id {
        font-weight: 600;
        color: #1f2937;
    }

    .order-customer {
        color: #1f2937;
        font-weight: 500;
    }

    .customer-info {
        font-size: 12px;
        color: #6b7280;
        margin-top: 2px;
    }

    .order-amount {
        font-weight: 600;
        color: #ff0050;
    }

    .order-items {
        font-size: 12px;
        color: #6b7280;
    }

    .status-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 500;
        text-transform: uppercase;
        display: inline-block;
        margin-bottom: 4px;
    }

    .status-pending {
        background: #fef3c7;
        color: #92400e;
    }

    .status-confirmed {
        background: #dbeafe;
        color: #1e40af;
    }

    .status-shipping {
        background: #fed7c7;
        color: #c2410c;
    }

    .status-delivered {
        background: #dcfce7;
        color: #166534;
    }

    .status-cancelled {
        background: #fee2e2;
        color: #dc2626;
    }

    .status-paid {
        background: #dcfce7;
        color: #166534;
    }

    .status-unpaid {
        background: #fee2e2;
        color: #dc2626;
    }

    .status-partial {
        background: #fef3c7;
        color: #92400e;
    }

    .order-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .action-btn {
        padding: 4px 8px;
        border: 1px solid #d1d5db;
        border-radius: 4px;
        background: white;
        color: #374151;
        font-size: 11px;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .action-btn:hover {
        background: #f3f4f6;
        border-color: #9ca3af;
    }

    .action-btn.primary {
        background: #ff0050;
        color: white;
        border-color: #ff0050;
    }

    .action-btn.primary:hover {
        background: #dc2626;
        border-color: #dc2626;
    }

    .tracking-code {
        font-family: monospace;
        background: #f3f4f6;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 11px;
    }

    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }

    .modal.show {
        display: flex;
    }

    .modal-content {
        background: white;
        border-radius: 12px;
        padding: 24px;
        max-width: 500px;
        width: 90%;
        max-height: 80vh;
        overflow-y: auto;
    }

    .modal-header {
        margin-bottom: 20px;
    }

    .modal-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 8px;
    }

    .modal-subtitle {
        font-size: 14px;
        color: #6b7280;
    }

    .form-group {
        margin-bottom: 16px;
    }

    .form-label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #374151;
        margin-bottom: 8px;
    }

    .form-select {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
    }

    .form-input {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
    }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 20px;
    }

    .modal-btn {
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .modal-btn.cancel {
        background: #6b7280;
        color: white;
    }

    .modal-btn.confirm {
        background: #ff0050;
        color: white;
    }

    .modal-btn:hover {
        opacity: 0.9;
    }

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        padding: 20px;
        background: white;
        border-top: 1px solid #e5e7eb;
    }

    .pagination a,
    .pagination span {
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        color: #374151;
        text-decoration: none;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .pagination a:hover {
        background: #f3f4f6;
    }

    .pagination .current {
        background: #ff0050;
        color: white;
        border-color: #ff0050;
    }

    .pagination .disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Messages */
    .message {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
        font-weight: 500;
    }

    .message.success {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #34d399;
    }

    .message.error {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #f87171;
    }

    .no-data {
        text-align: center;
        padding: 60px 24px;
        color: #9ca3af;
    }

    .no-data svg {
        width: 64px;
        height: 64px;
        margin-bottom: 16px;
        opacity: 0.5;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .stats-overview {
            grid-template-columns: repeat(3, 1fr);
        }

        .filters-row {
            flex-direction: column;
            align-items: stretch;
        }

        .search-box {
            max-width: none;
        }
    }

    @media (max-width: 768px) {
        .main-content {
            margin-left: 0;
        }

        .mobile-menu-btn {
            display: block;
        }

        .content-wrapper {
            padding: 16px;
        }

        .stats-overview {
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .orders-table {
            font-size: 12px;
        }

        .orders-table th,
        .orders-table td {
            padding: 12px 16px;
        }

        .user-details {
            display: none;
        }

        .modal-content {
            padding: 20px;
            margin: 20px;
        }
    }

    @media (max-width: 480px) {
        .stats-overview {
            grid-template-columns: 1fr;
        }

        .orders-table {
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }
    }
    </style>
</head>

<body>
    <div class="layout">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="top-header">
                <div class="header-left">
                    <button class="mobile-menu-btn" onclick="toggleSidebar()">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" />
                        </svg>
                    </button>
                    <h1>Quản lý đơn hàng</h1>
                </div>
                <div class="header-right">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($seller_name, 0, 1)); ?>
                        </div>
                        <div class="user-details">
                            <h3><?php echo htmlspecialchars($seller_name); ?></h3>
                            <div class="user-balance">Số dư: <?php echo number_format($seller_balance, 0, ',', '.'); ?>đ
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-wrapper">
                <!-- Messages -->
                <?php if ($success_message): ?>
                <div class="message success"><?php echo $success_message; ?></div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="message error"><?php echo $error_message; ?></div>
                <?php endif; ?>

                <!-- Order Statistics -->
                <div class="stats-overview">
                    <div class="stat-card <?php echo empty($status_filter) ? 'active' : ''; ?>"
                        onclick="filterByStatus('')">
                        <div class="number"><?php echo $order_stats['total_orders']; ?></div>
                        <div class="label">Tổng đơn hàng</div>
                    </div>
                    <div class="stat-card <?php echo $status_filter === 'pending' ? 'active' : ''; ?>"
                        onclick="filterByStatus('pending')">
                        <div class="number"><?php echo $order_stats['pending_orders']; ?></div>
                        <div class="label">Chờ xử lý</div>
                    </div>
                    <div class="stat-card <?php echo $status_filter === 'confirmed' ? 'active' : ''; ?>"
                        onclick="filterByStatus('confirmed')">
                        <div class="number"><?php echo $order_stats['confirmed_orders']; ?></div>
                        <div class="label">Đã xác nhận</div>
                    </div>
                    <div class="stat-card <?php echo $status_filter === 'shipping' ? 'active' : ''; ?>"
                        onclick="filterByStatus('shipping')">
                        <div class="number"><?php echo $order_stats['shipping_orders']; ?></div>
                        <div class="label">Đang giao</div>
                    </div>
                    <div class="stat-card <?php echo $status_filter === 'delivered' ? 'active' : ''; ?>"
                        onclick="filterByStatus('delivered')">
                        <div class="number"><?php echo $order_stats['delivered_orders']; ?></div>
                        <div class="label">Đã giao</div>
                    </div>
                    <div class="stat-card <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>"
                        onclick="filterByStatus('cancelled')">
                        <div class="number"><?php echo $order_stats['cancelled_orders']; ?></div>
                        <div class="label">Đã hủy</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-section">
                    <form method="GET" action="">
                        <div class="filters-row">
                            <div class="filter-group">
                                <label class="filter-label">Trạng thái</label>
                                <select name="status" class="filter-select">
                                    <option value="">Tất cả trạng thái</option>
                                    <?php foreach ($status_options as $value => $label): ?>
                                    <option value="<?php echo $value; ?>"
                                        <?php echo $status_filter === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="search-box">
                                <input type="text" name="search" placeholder="Tìm theo mã đơn, tên khách hàng..."
                                    class="search-input" value="<?php echo htmlspecialchars($search_query); ?>">
                                <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                    <path
                                        d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
                                </svg>
                            </div>

                            <button type="submit" class="filter-btn">Lọc</button>
                            <a href="orders.php" class="reset-btn">Đặt lại</a>
                        </div>
                    </form>
                </div>

                <!-- Orders Table -->
                <div class="orders-section">
                    <div class="orders-header">
                        <h2 class="orders-title">Danh sách đơn hàng</h2>
                        <div class="orders-count">
                            Hiển thị <?php echo count($orders); ?> / <?php echo $total_orders; ?> đơn hàng
                        </div>
                    </div>

                    <?php if (empty($orders)): ?>
                    <div class="no-data">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" />
                        </svg>
                        <h3>Không tìm thấy đơn hàng nào</h3>
                        <p>Thử thay đổi bộ lọc hoặc tìm kiếm với từ khóa khác</p>
                    </div>
                    <?php else: ?>
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Mã đơn hàng</th>
                                <th>Khách hàng</th>
                                <th>Sản phẩm</th>
                                <th>Tổng tiền</th>
                                <th>Trạng thái</th>
                                <th>Ngày đặt</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                            <tr>
                                <td>
                                    <div class="order-id">#<?php echo $order['id']; ?></div>
                                    <?php if ($order['tracking_code']): ?>
                                    <div class="tracking-code"><?php echo htmlspecialchars($order['tracking_code']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="order-customer">
                                        <?php echo htmlspecialchars($order['customer_name'] ?? 'Khách vãng lai'); ?>
                                    </div>
                                    <?php if ($order['customer_email']): ?>
                                    <div class="customer-info"><?php echo htmlspecialchars($order['customer_email']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($order['customer_phone']): ?>
                                    <div class="customer-info"><?php echo htmlspecialchars($order['customer_phone']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="order-items">
                                        <?php echo $order['item_count']; ?> sản phẩm
                                        (<?php echo $order['total_quantity']; ?> món)
                                    </div>
                                </td>
                                <td>
                                    <div class="order-amount">
                                        <?php echo number_format($order['grand_total'], 0, ',', '.'); ?>đ
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <span
                                            class="status-badge <?php echo getStatusBadgeClass($order['delivery_status']); ?>">
                                            <?php echo $status_options[$order['delivery_status']] ?? $order['delivery_status']; ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span
                                            class="status-badge <?php echo getPaymentStatusBadgeClass($order['payment_status']); ?>">
                                            <?php echo $payment_status_options[$order['payment_status']] ?? $order['payment_status']; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo date('d/m/Y', strtotime($order['created_at'])); ?></div>
                                    <div style="font-size: 12px; color: #6b7280;">
                                        <?php echo date('H:i', strtotime($order['created_at'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="order-actions">
                                        <a href="order-details.php?id=<?php echo $order['id']; ?>" class="action-btn">
                                            Chi tiết
                                        </a>
                                        <?php if (canUpdateStatus($order['delivery_status'])): ?>
                                        <button class="action-btn primary"
                                            onclick="openUpdateModal(<?php echo $order['id']; ?>, '<?php echo $order['delivery_status']; ?>', '<?php echo htmlspecialchars($order['tracking_code'] ?? ''); ?>')">
                                            Cập nhật
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php
                            $query_params = $_GET;
                            unset($query_params['page']);
                            $base_url = 'orders.php?' . http_build_query($query_params);
                            $base_url .= empty($query_params) ? 'page=' : '&page=';
                        ?>

                        <?php if ($page > 1): ?>
                        <a href="<?php echo $base_url . ($page - 1); ?>">‹ Trước</a>
                        <?php else: ?>
                        <span class="disabled">‹ Trước</span>
                        <?php endif; ?>

                        <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                        <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                        <a href="<?php echo $base_url . $i; ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                        <a href="<?php echo $base_url . ($page + 1); ?>">Sau ›</a>
                        <?php else: ?>
                        <span class="disabled">Sau ›</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div id="updateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Cập nhật trạng thái đơn hàng</h3>
                <p class="modal-subtitle">Cập nhật trạng thái và mã vận đơn</p>
            </div>

            <form method="POST" id="updateForm">
                <input type="hidden" name="order_id" id="updateOrderId">

                <div class="form-group">
                    <label class="form-label">Trạng thái mới</label>
                    <select name="delivery_status" id="updateStatus" class="form-select" required>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Mã vận đơn (tùy chọn)</label>
                    <input type="text" name="tracking_code" id="updateTrackingCode" class="form-input"
                        placeholder="Nhập mã vận đơn...">
                </div>

                <div class="modal-actions">
                    <button type="button" class="modal-btn cancel" onclick="closeUpdateModal()">Hủy</button>
                    <button type="submit" name="update_status" class="modal-btn confirm">Cập nhật</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Filter by status
    function filterByStatus(status) {
        const url = new URL(window.location);
        if (status) {
            url.searchParams.set('status', status);
        } else {
            url.searchParams.delete('status');
        }
        url.searchParams.delete('page');
        window.location.href = url.toString();
    }

    // Update modal functions
    function openUpdateModal(orderId, currentStatus, trackingCode) {
        document.getElementById('updateOrderId').value = orderId;
        document.getElementById('updateTrackingCode').value = trackingCode;

        // Clear and populate status options
        const statusSelect = document.getElementById('updateStatus');
        statusSelect.innerHTML = '';

        const nextStatuses = getNextStatuses(currentStatus);
        for (const [value, label] of Object.entries(nextStatuses)) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            statusSelect.appendChild(option);
        }

        document.getElementById('updateModal').classList.add('show');
    }

    function closeUpdateModal() {
        document.getElementById('updateModal').classList.remove('show');
    }

    function getNextStatuses(currentStatus) {
        const statusMap = {
            'pending': {
                'confirmed': 'Xác nhận đơn hàng',
                'cancelled': 'Hủy đơn hàng'
            },
            'confirmed': {
                'shipping': 'Bắt đầu giao hàng',
                'cancelled': 'Hủy đơn hàng'
            },
            'shipping': {
                'delivered': 'Đã giao thành công'
            }
        };
        return statusMap[currentStatus] || {};
    }

    // Close modal when clicking outside
    document.getElementById('updateModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeUpdateModal();
        }
    });

    // Handle form submission
    document.getElementById('updateForm').addEventListener('submit', function(e) {
        const statusSelect = document.getElementById('updateStatus');
        if (!statusSelect.value) {
            e.preventDefault();
            alert('Vui lòng chọn trạng thái mới.');
        }
    });
    </script>
</body>

</html>