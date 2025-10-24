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

// Get seller statistics
try {
    // Get seller balance
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$seller_id]);
    $seller_balance = $stmt->fetchColumn() ?? 0;

    // Đơn hàng thống kê
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN delivery_status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
            SUM(CASE WHEN delivery_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
            SUM(CASE WHEN delivery_status = 'shipping' THEN 1 ELSE 0 END) as shipping_orders,
            SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders
        FROM orders 
        WHERE seller_id = ?
    ");
    $stmt->execute([$seller_id]);
    $order_stats = $stmt->fetch();

    // Sản phẩm thống kê
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_products FROM products WHERE user_id = ? AND published = 1");
    $stmt->execute([$seller_id]);
    $total_products = $stmt->fetchColumn();

    // Số lượng đã bán (từ order_details)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(od.quantity), 0) as total_sold
        FROM order_details od
        INNER JOIN orders o ON od.order_id = o.id
        WHERE o.seller_id = ? AND o.payment_status = 'paid'
    ");
    $stmt->execute([$seller_id]);
    $total_sold = $stmt->fetchColumn();

    // Tổng doanh thu
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(grand_total), 0) as total_revenue 
        FROM orders 
        WHERE seller_id = ? AND payment_status = 'paid'
    ");
    $stmt->execute([$seller_id]);
    $total_revenue = $stmt->fetchColumn();

    // Xếp hạng sản phẩm (tính theo số lượt review trung bình)
    $stmt = $pdo->prepare("
        SELECT COALESCE(AVG(r.rating), 0) as avg_rating
        FROM reviews r
        INNER JOIN products p ON r.product_id = p.id
        WHERE p.user_id = ? AND r.status = 1
    ");
    $stmt->execute([$seller_id]);
    $avg_rating = round($stmt->fetchColumn(), 1);

    // Top selling products với thông tin chi tiết
    $stmt = $pdo->prepare("
        SELECT 
            p.id, 
            p.name, 
            p.thumbnail_img, 
            p.photos,
            p.unit_price,
            p.discount,
            p.discount_type,
            p.current_stock,
            thumb.file_name as thumbnail_file,
            COALESCE(SUM(od.quantity), 0) as total_sold,
            COALESCE(AVG(r.rating), 0) as avg_rating
        FROM products p 
        LEFT JOIN order_details od ON p.id = od.product_id 
        LEFT JOIN orders o ON od.order_id = o.id AND o.payment_status = 'paid'
        LEFT JOIN reviews r ON p.id = r.product_id AND r.status = 1
        LEFT JOIN uploads thumb ON p.thumbnail_img = thumb.id AND thumb.deleted_at IS NULL
        WHERE p.user_id = ? AND p.published = 1
        GROUP BY p.id 
        ORDER BY total_sold DESC, p.created_at DESC
        LIMIT 12
    ");
    $stmt->execute([$seller_id]);
    $top_products = $stmt->fetchAll();

    // Thống kê theo danh mục
    $stmt = $pdo->prepare("
        SELECT 
            c.name as category_name,
            COUNT(p.id) as product_count,
            COALESCE(SUM(CASE WHEN p.current_stock > 0 THEN 1 ELSE 0 END), 0) as in_stock
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id AND p.user_id = ? AND p.published = 1
        WHERE c.id IN (SELECT DISTINCT category_id FROM products WHERE user_id = ?)
        GROUP BY c.id, c.name
        ORDER BY product_count DESC
        LIMIT 4
    ");
    $stmt->execute([$seller_id, $seller_id]);
    $category_stats = $stmt->fetchAll();

    // Đơn hàng gần đây
    $stmt = $pdo->prepare("
        SELECT 
            o.id,
            o.grand_total,
            o.delivery_status,
            o.payment_status,
            o.created_at,
            u.name as customer_name,
            COUNT(od.id) as item_count
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN order_details od ON o.id = od.order_id
        WHERE o.seller_id = ?
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT 8
    ");
    $stmt->execute([$seller_id]);
    $recent_orders = $stmt->fetchAll();

    // Sản phẩm hết hàng hoặc sắp hết
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as low_stock_count
        FROM products 
        WHERE user_id = ? AND published = 1 AND current_stock <= COALESCE(low_stock_quantity, 5)
    ");
    $stmt->execute([$seller_id]);
    $low_stock_count = $stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    // Set default values if error
    $seller_balance = 0;
    $order_stats = [
        'total_orders' => 0,
        'pending_orders' => 0,
        'cancelled_orders' => 0,
        'shipping_orders' => 0,
        'delivered_orders' => 0
    ];
    $total_products = $total_sold = $total_revenue = $avg_rating = $low_stock_count = 0;
    $top_products = $category_stats = $recent_orders = [];
}

// Tính toán giá sau giảm giá
function calculateDiscountPrice($original_price, $discount, $discount_type)
{
    if ($discount <= 0)
        return $original_price;

    if ($discount_type == 'percent') {
        return $original_price * (1 - $discount / 100);
    } else {
        return max(0, $original_price - $discount);
    }
}

// Function to get product image (tương tự index.php)
function getProductImage($product, $pdo = null)
{
    // Priority: thumbnail from JOIN
    if (!empty($product['thumbnail_file'])) {
        return '' . $product['thumbnail_file'];
    }
    // Backup: get first image from photos JSON
    elseif (!empty($product['photos']) && $pdo) {
        $photos_json = json_decode($product['photos'], true);
        if (is_array($photos_json) && !empty($photos_json)) {
            try {
                $first_photo_id = $photos_json[0];
                $stmt_img = $pdo->prepare("SELECT file_name FROM uploads WHERE id = ? AND deleted_at IS NULL");
                $stmt_img->execute([$first_photo_id]);
                $img_result = $stmt_img->fetch();
                if ($img_result) {
                    return '' . $img_result['file_name'];
                }
            } catch (PDOException $e) {
                // Ignore error
            }
        }
    }
    // Fallback: check if thumbnail_img is direct filename
    elseif (!empty($product['thumbnail_img'])) {
        // If thumbnail_img contains filename directly
        if (is_string($product['thumbnail_img']) && !is_numeric($product['thumbnail_img'])) {
            return '' . $product['thumbnail_img'];
        }
    }
    return '';
}

// Format trạng thái đơn hàng
function getOrderStatusText($status)
{
    switch ($status) {
        case 'pending':
            return 'Chờ xử lý';
        case 'confirmed':
            return 'Đã xác nhận';
        case 'shipping':
            return 'Đang giao';
        case 'delivered':
            return 'Đã giao';
        case 'cancelled':
            return 'Đã hủy';
        default:
            return 'Chưa xác định';
    }
}

function getPaymentStatusText($status)
{
    switch ($status) {
        case 'paid':
            return 'Đã thanh toán';
        case 'unpaid':
            return 'Chưa thanh toán';
        case 'partial':
            return 'Thanh toán 1 phần';
        default:
            return 'Chưa xác định';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bảng điều khiển - TikTok Shop Seller</title>
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

    /* Order Statistics Cards */
    .stats-overview {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
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
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .stat-card .icon {
        width: 48px;
        height: 48px;
        margin: 0 auto 12px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }

    .stat-card h3 {
        font-size: 14px;
        color: #6b7280;
        margin-bottom: 8px;
        font-weight: 500;
    }

    .stat-card .number {
        font-size: 32px;
        font-weight: 700;
        color: #1f2937;
    }

    .stat-card.pending .icon {
        background: linear-gradient(45deg, #3b82f6, #60a5fa);
    }

    .stat-card.cancelled .icon {
        background: linear-gradient(45deg, #ef4444, #f87171);
    }

    .stat-card.shipping .icon {
        background: linear-gradient(45deg, #f59e0b, #fbbf24);
    }

    .stat-card.delivered .icon {
        background: linear-gradient(45deg, #10b981, #34d399);
    }

    /* Secondary Statistics */
    .secondary-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .secondary-stat-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 24px;
        border-radius: 16px;
        position: relative;
        overflow: hidden;
    }

    .secondary-stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 100px;
        height: 100px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        transform: translate(30%, -30%);
    }

    .secondary-stat-card .content {
        position: relative;
        z-index: 2;
    }

    .secondary-stat-card .number {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .secondary-stat-card .label {
        font-size: 14px;
        opacity: 0.9;
    }

    .secondary-stat-card .icon-large {
        position: absolute;
        top: 16px;
        right: 16px;
        font-size: 32px;
        opacity: 0.3;
    }

    /* Action Buttons */
    .action-buttons {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .action-btn {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 24px;
        text-align: center;
        text-decoration: none;
        color: #4b5563;
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
    }

    .action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        color: #ff0050;
    }

    .action-btn .icon {
        width: 48px;
        height: 48px;
        background: #f3f4f6;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }

    .action-btn:hover .icon {
        background: #fef2f2;
        color: #ff0050;
    }

    .action-btn .text {
        font-size: 14px;
        font-weight: 500;
    }

    /* Content Grid */
    .content-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 24px;
        margin-bottom: 24px;
    }

    /* Products Section */
    .products-section {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    .products-header {
        padding: 24px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .products-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
    }

    .products-grid {
        display: flex;
        gap: 20px;
        padding: 24px;
        overflow-x: auto;
        scroll-behavior: smooth;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .products-grid::-webkit-scrollbar {
        display: none;
    }

    .product-card {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        overflow: hidden;
        transition: all 0.3s ease;
        min-width: 200px;
        flex-shrink: 0;
    }

    .product-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .product-image {
        width: 100%;
        height: 160px;
        object-fit: cover;
        background: #f3f4f6;
        border-radius: 8px 8px 0 0;
        position: relative;
        overflow: hidden;
    }

    .product-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .product-image.no-image::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f3f4f6;
        color: #9ca3af;
        font-size: 48px;
    }

    .product-info {
        padding: 16px;
    }

    .product-name {
        font-size: 14px;
        font-weight: 500;
        color: #1f2937;
        margin-bottom: 8px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        line-height: 1.3;
    }

    .product-price {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }

    .current-price {
        font-size: 16px;
        font-weight: 600;
        color: #ff0050;
    }

    .original-price {
        font-size: 14px;
        color: #9ca3af;
        text-decoration: line-through;
    }

    .product-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        color: #6b7280;
    }

    .product-sold {
        color: #10b981;
    }

    .product-stock {
        color: #f59e0b;
    }

    .product-stock.out {
        color: #ef4444;
    }

    /* Orders Section */
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

    .view-all-link {
        color: #ff0050;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
    }

    .view-all-link:hover {
        text-decoration: underline;
    }

    .orders-list {
        max-height: 400px;
        overflow-y: auto;
    }

    .order-item {
        padding: 16px 24px;
        border-bottom: 1px solid #f3f4f6;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: background 0.3s ease;
    }

    .order-item:hover {
        background: #f9fafb;
    }

    .order-item:last-child {
        border-bottom: none;
    }

    .order-info h4 {
        font-size: 14px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 4px;
    }

    .order-meta {
        font-size: 12px;
        color: #6b7280;
    }

    .order-amount {
        font-size: 14px;
        font-weight: 600;
        color: #ff0050;
        margin-bottom: 4px;
    }

    .status-badge {
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 500;
        text-transform: uppercase;
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

    .alerts-section {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        padding: 24px;
    }

    .alerts-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 16px;
    }

    .alert-item {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 14px;
    }

    .alert-warning {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fbbf24;
    }

    .alert-info {
        background: #dbeafe;
        color: #1e40af;
        border: 1px solid #60a5fa;
    }

    .alert-success {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #34d399;
    }

    @media (max-width: 1200px) {
        .content-grid {
            grid-template-columns: 1fr;
        }

        .secondary-stats {
            grid-template-columns: repeat(2, 1fr);
        }

        .action-buttons {
            grid-template-columns: repeat(2, 1fr);
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

        .secondary-stats {
            grid-template-columns: 1fr;
        }

        .action-buttons {
            grid-template-columns: 1fr;
        }

        .product-card {
            min-width: 160px;
        }
    }

    @media (max-width: 480px) {
        .stats-overview {
            grid-template-columns: 1fr;
        }

        .product-card {
            min-width: 140px;
        }

        .user-details {
            display: none;
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
                    <h1>Bảng điều khiển</h1>
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
                <!-- Order Statistics -->
                <div class="stats-overview">
                    <div class="stat-card pending">
                        <div class="icon">📋</div>
                        <h3>Đơn hàng mới</h3>
                        <div class="number"><?php echo $order_stats['pending_orders']; ?></div>
                    </div>
                    <div class="stat-card cancelled">
                        <div class="icon">❌</div>
                        <h3>Đơn hàng hủy</h3>
                        <div class="number"><?php echo $order_stats['cancelled_orders']; ?></div>
                    </div>
                    <div class="stat-card shipping">
                        <div class="icon">🚚</div>
                        <h3>Đang giao hàng</h3>
                        <div class="number"><?php echo $order_stats['shipping_orders']; ?></div>
                    </div>
                    <div class="stat-card delivered">
                        <div class="icon">✅</div>
                        <h3>Đã giao hàng</h3>
                        <div class="number"><?php echo $order_stats['delivered_orders']; ?></div>
                    </div>
                </div>

                <!-- Secondary Statistics -->
                <div class="secondary-stats">
                    <div class="secondary-stat-card">
                        <div class="icon-large">📦</div>
                        <div class="content">
                            <div class="number"><?php echo $total_products; ?></div>
                            <div class="label">Các sản phẩm</div>
                        </div>
                    </div>

                    <div class="secondary-stat-card">
                        <div class="icon-large">⭐</div>
                        <div class="content">
                            <div class="number"><?php echo $avg_rating > 0 ? $avg_rating : '0'; ?></div>
                            <div class="label">Xếp hạng</div>
                        </div>
                    </div>

                    <div class="secondary-stat-card">
                        <div class="icon-large">📋</div>
                        <div class="content">
                            <div class="number"><?php echo $order_stats['total_orders']; ?></div>
                            <div class="label">Tổng số đơn</div>
                        </div>
                    </div>

                    <div class="secondary-stat-card">
                        <div class="icon-large">💰</div>
                        <div class="content">
                            <div class="number"><?php echo $total_sold; ?>k</div>
                            <div class="label">Tổng lượt bán</div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <a href="withdraw.php" class="action-btn">
                        <div class="icon">💳</div>
                        <div class="text">Rút tiền</div>
                    </a>

                    <a href="add-product.php" class="action-btn">
                        <div class="icon">➕</div>
                        <div class="text">Thêm sản phẩm mới</div>
                    </a>

                    <a href="store-settings.php" class="action-btn">
                        <div class="icon">🏪</div>
                        <div class="text">Cài đặt cửa hàng</div>
                    </a>

                    <a href="payment-settings.php" class="action-btn">
                        <div class="icon">💼</div>
                        <div class="text">Cài đặt thanh toán</div>
                    </a>
                </div>

                <!-- Content Grid -->
                <div class="content-grid">
                    <!-- Top Selling Products -->
                    <div class="products-section">
                        <div class="products-header">
                            <h2 class="products-title">12 sản phẩm hàng đầu</h2>
                            <div style="display: flex; gap: 8px;">
                                <button onclick="scrollProducts('left')"
                                    style="background: #f3f4f6; border: none; border-radius: 50%; width: 32px; height: 32px; cursor: pointer; color: #6b7280;">‹</button>
                                <button onclick="scrollProducts('right')"
                                    style="background: #f3f4f6; border: none; border-radius: 50%; width: 32px; height: 32px; cursor: pointer; color: #6b7280;">›</button>
                            </div>
                        </div>

                        <?php if (empty($top_products)): ?>
                        <div class="no-data">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M19 7h-3V6c0-1.1-.9-2-2-2H10c-1.1 0-2 .9-2 2v1H5c-1.1 0-2 .9-2 2v11c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V9c0-1.1-.9-2-2-2z" />
                            </svg>
                            <h3>Chưa có sản phẩm nào</h3>
                            <p>Hãy thêm sản phẩm đầu tiên để bắt đầu bán hàng</p>
                        </div>
                        <?php else: ?>
                        <div class="products-grid" id="productsGrid">
                            <?php foreach ($top_products as $product):
                                    $product_image = getProductImage($product, $pdo);
                                    $display_price = calculateDiscountPrice($product['unit_price'], $product['discount'], $product['discount_type']);
                                    $has_discount = $product['discount'] > 0;
                                    ?>
                            <div class="product-card">
                                <div class="product-image">
                                    <?php if ($product_image): ?>
                                    <img src="../<?php echo htmlspecialchars($product_image); ?>"
                                        alt="<?php echo htmlspecialchars($product['name']); ?>"
                                        onerror="this.style.display='none'; this.parentElement.classList.add('no-image');">
                                    <?php endif; ?>

                                    <?php if (!$product_image): ?>
                                    <div
                                        style="display: flex; align-items: center; justify-content: center; height: 100%; background: #f3f4f6; color: #9ca3af;">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor">
                                            <path
                                                d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" />
                                        </svg>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="product-info">
                                    <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                    <div class="product-price">
                                        <span
                                            class="current-price"><?php echo number_format($display_price, 0, ',', '.'); ?>đ</span>
                                        <?php if ($has_discount): ?>
                                        <span
                                            class="original-price"><?php echo number_format($product['unit_price'], 0, ',', '.'); ?>đ</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="product-meta">
                                        <span class="product-sold">Đã bán: <?php echo $product['total_sold']; ?></span>
                                        <span
                                            class="product-stock <?php echo $product['current_stock'] == 0 ? 'out' : ''; ?>">
                                            Kho: <?php echo $product['current_stock']; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Alerts and Recent Orders -->
                    <div>
                        <!-- Alerts Section -->
                        <div class="alerts-section" style="margin-bottom: 24px;">
                            <h2 class="alerts-title">Thông báo & Cảnh báo</h2>

                            <?php if ($order_stats['pending_orders'] > 0): ?>
                            <div class="alert-item alert-warning">
                                <span>⚠️</span>
                                <span>Bạn có <?php echo $order_stats['pending_orders']; ?> đơn hàng chờ xử lý</span>
                            </div>
                            <?php endif; ?>

                            <?php if ($low_stock_count > 0): ?>
                            <div class="alert-item alert-warning">
                                <span>📦</span>
                                <span><?php echo $low_stock_count; ?> sản phẩm sắp hết hàng</span>
                            </div>
                            <?php endif; ?>

                            <?php if ($order_stats['delivered_orders'] > 0): ?>
                            <div class="alert-item alert-success">
                                <span>🎉</span>
                                <span>Đã giao thành công <?php echo $order_stats['delivered_orders']; ?> đơn
                                    hàng!</span>
                            </div>
                            <?php endif; ?>

                            <div class="alert-item alert-info">
                                <span>💡</span>
                                <span>Mẹo: Cập nhật ảnh sản phẩm chất lượng cao để tăng tỷ lệ chuyển đổi</span>
                            </div>
                        </div>

                        <!-- Recent Orders -->
                        <div class="orders-section">
                            <div class="orders-header">
                                <h2 class="orders-title">Đơn hàng gần đây</h2>
                                <a href="orders.php" class="view-all-link">Xem tất cả →</a>
                            </div>

                            <?php if (empty($recent_orders)): ?>
                            <div class="no-data">
                                <svg viewBox="0 0 24 24" fill="currentColor">
                                    <path
                                        d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" />
                                </svg>
                                <p>Chưa có đơn hàng nào</p>
                            </div>
                            <?php else: ?>
                            <div class="orders-list">
                                <?php foreach ($recent_orders as $order): ?>
                                <div class="order-item">
                                    <div class="order-info">
                                        <h4>#<?php echo $order['id']; ?> -
                                            <?php echo htmlspecialchars($order['customer_name'] ?? 'Khách vãng lai'); ?>
                                        </h4>
                                        <div class="order-meta">
                                            <?php echo $order['item_count']; ?> sản phẩm •
                                            <?php echo date('d/m H:i', strtotime($order['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <div class="order-amount">
                                            <?php echo number_format($order['grand_total'], 0, ',', '.'); ?>đ
                                        </div>
                                        <div style="display: flex; gap: 4px; flex-direction: column;">
                                            <span class="status-badge status-<?php echo $order['delivery_status']; ?>">
                                                <?php echo getOrderStatusText($order['delivery_status']); ?>
                                            </span>
                                            <span class="status-badge status-<?php echo $order['payment_status']; ?>">
                                                <?php echo getPaymentStatusText($order['payment_status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Product carousel scroll
    function scrollProducts(direction) {
        const grid = document.getElementById('productsGrid');
        const scrollAmount = 220;

        if (direction === 'left') {
            grid.scrollLeft -= scrollAmount;
        } else {
            grid.scrollLeft += scrollAmount;
        }
    }

    // Handle image loading errors
    document.addEventListener('DOMContentLoaded', function() {
        const productImages = document.querySelectorAll('.product-image img');
        productImages.forEach(img => {
            img.addEventListener('error', function() {
                const placeholder = document.createElement('div');
                placeholder.style.cssText = `
                        display: flex; 
                        align-items: center; 
                        justify-content: center; 
                        height: 100%; 
                        background: #f3f4f6; 
                        color: #9ca3af;
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                    `;
                placeholder.innerHTML = `
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/>
                        </svg>
                    `;
                this.style.display = 'none';
                this.parentElement.appendChild(placeholder);
            });
        });
    });
    </script>
</body>

</html>