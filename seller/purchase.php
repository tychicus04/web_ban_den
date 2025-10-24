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

// Handle actions
if ($_POST) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_coupon') {
        $type = $_POST['type'] ?? 'discount';
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $discount = (float) ($_POST['discount'] ?? 0);
        $discount_type = $_POST['discount_type'] ?? 'percent';
        $min_buy = (float) ($_POST['min_buy'] ?? 0);
        $usage_limit = (int) ($_POST['usage_limit'] ?? 0);
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $product_ids = $_POST['product_ids'] ?? [];

        try {
            // Validate input
            if (empty($code) || $discount <= 0) {
                throw new Exception("Mã giảm giá và giá trị không được để trống!");
            }

            if ($discount_type === 'percent' && $discount > 100) {
                throw new Exception("Giảm giá theo % không được vượt quá 100%!");
            }

            if (!empty($start_date) && !empty($end_date) && strtotime($start_date) >= strtotime($end_date)) {
                throw new Exception("Ngày kết thúc phải sau ngày bắt đầu!");
            }

            // Check if code already exists for this seller
            $check_stmt = $pdo->prepare("SELECT id FROM coupons WHERE user_id = ? AND code = ?");
            $check_stmt->execute([$seller_id, $code]);
            if ($check_stmt->fetch()) {
                throw new Exception("Mã giảm giá này đã tồn tại!");
            }

            // Prepare details
            $details = json_encode([
                'description' => $description,
                'min_buy' => $min_buy,
                'usage_limit' => $usage_limit,
                'used_count' => 0,
                'product_ids' => $product_ids
            ]);

            // Insert coupon
            $stmt = $pdo->prepare("
                INSERT INTO coupons (
                    user_id, type, code, details, discount, discount_type, 
                    start_date, end_date, status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");

            $start_timestamp = $start_date ? strtotime($start_date) : null;
            $end_timestamp = $end_date ? strtotime($end_date) : null;

            $stmt->execute([
                $seller_id,
                $type,
                $code,
                $details,
                $discount,
                $discount_type,
                $start_timestamp,
                $end_timestamp
            ]);

            $success = "Tạo phiếu giảm giá thành công!";

        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = "Có lỗi xảy ra: " . $e->getMessage();
        }
    }

    if ($action === 'toggle_status') {
        $coupon_id = (int) ($_POST['coupon_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("
                UPDATE coupons 
                SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END, updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$coupon_id, $seller_id]);
            $success = "Cập nhật trạng thái thành công!";
        } catch (PDOException $e) {
            $error = "Có lỗi xảy ra khi cập nhật!";
        }
    }

    if ($action === 'delete_coupon') {
        $coupon_id = (int) ($_POST['coupon_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM coupons WHERE id = ? AND user_id = ?");
            $stmt->execute([$coupon_id, $seller_id]);
            $success = "Xóa phiếu giảm giá thành công!";
        } catch (PDOException $e) {
            $error = "Không thể xóa! Có thể phiếu này đã được sử dụng.";
        }
    }
}

// Get filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

try {
    // Build WHERE clause
    $where_conditions = ["c.user_id = ?"];
    $params = [$seller_id];

    if (!empty($search)) {
        $where_conditions[] = "(c.code LIKE ? OR JSON_EXTRACT(c.details, '$.description') LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
    }

    if ($status_filter !== '') {
        if ($status_filter === 'active') {
            $where_conditions[] = "c.status = 1 AND (c.end_date IS NULL OR c.end_date > UNIX_TIMESTAMP())";
        } elseif ($status_filter === 'expired') {
            $where_conditions[] = "c.end_date IS NOT NULL AND c.end_date <= UNIX_TIMESTAMP()";
        } elseif ($status_filter === 'disabled') {
            $where_conditions[] = "c.status = 0";
        }
    }

    if ($type_filter !== '') {
        $where_conditions[] = "c.type = ?";
        $params[] = $type_filter;
    }

    $where_clause = "WHERE " . implode(" AND ", $where_conditions);

    // Get coupons with usage statistics
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            COALESCE(usage_stats.usage_count, 0) as actual_usage_count,
            CASE 
                WHEN c.status = 0 THEN 'disabled'
                WHEN c.end_date IS NOT NULL AND c.end_date <= UNIX_TIMESTAMP() THEN 'expired'
                WHEN c.start_date IS NOT NULL AND c.start_date > UNIX_TIMESTAMP() THEN 'scheduled'
                ELSE 'active'
            END as coupon_status
        FROM coupons c
        LEFT JOIN (
            SELECT coupon_id, COUNT(*) as usage_count
            FROM coupon_usages
            GROUP BY coupon_id
        ) usage_stats ON c.id = usage_stats.coupon_id
        $where_clause
        ORDER BY c.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $coupons = $stmt->fetchAll();

    // Get total count
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM coupons c $where_clause
    ");
    $count_stmt->execute($params);
    $total_coupons = $count_stmt->fetchColumn();
    $total_pages = ceil($total_coupons / $limit);

    // Get statistics
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 1 AND (end_date IS NULL OR end_date > UNIX_TIMESTAMP()) THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN end_date IS NOT NULL AND end_date <= UNIX_TIMESTAMP() THEN 1 ELSE 0 END) as expired,
            SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as disabled
        FROM coupons 
        WHERE user_id = ?
    ");
    $stats_stmt->execute([$seller_id]);
    $stats = $stats_stmt->fetch();

    // Get seller's products for coupon assignment
    $products_stmt = $pdo->prepare("
        SELECT id, name, unit_price
        FROM products 
        WHERE user_id = ? AND published = 1 
        ORDER BY name ASC
        LIMIT 100
    ");
    $products_stmt->execute([$seller_id]);
    $seller_products = $products_stmt->fetchAll();

} catch (PDOException $e) {
    $coupons = [];
    $total_coupons = 0;
    $total_pages = 1;
    $stats = ['total' => 0, 'active' => 0, 'expired' => 0, 'disabled' => 0];
    $seller_products = [];
    $error = "Có lỗi xảy ra khi tải dữ liệu.";
}

function formatCurrency($amount)
{
    return number_format($amount, 0, ',', '.') . 'đ';
}

function generateRandomCode($length = 8)
{
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

function getStatusBadge($coupon)
{
    $status = $coupon['coupon_status'];
    $badges = [
        'active' => '<span class="status-badge status-active">Đang hoạt động</span>',
        'expired' => '<span class="status-badge status-expired">Hết hạn</span>',
        'disabled' => '<span class="status-badge status-disabled">Tắt</span>',
        'scheduled' => '<span class="status-badge status-scheduled">Chờ kích hoạt</span>'
    ];
    return $badges[$status] ?? '';
}

function getCouponProgress($coupon)
{
    $details = json_decode($coupon['details'], true);
    $usage_limit = $details['usage_limit'] ?? 0;
    $actual_usage = $coupon['actual_usage_count'];

    if ($usage_limit > 0) {
        $percentage = min(100, ($actual_usage / $usage_limit) * 100);
        return [
            'current' => $actual_usage,
            'limit' => $usage_limit,
            'percentage' => $percentage
        ];
    }

    return [
        'current' => $actual_usage,
        'limit' => 'Không giới hạn',
        'percentage' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu giảm giá - TikTok Shop Seller</title>
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
        line-height: 1.6;
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

    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        color: #6b7280;
        margin-top: 4px;
    }

    .breadcrumb a {
        color: #ff0050;
        text-decoration: none;
    }

    .breadcrumb a:hover {
        text-decoration: underline;
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

    .header-actions {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .btn {
        padding: 8px 16px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-primary {
        background: #ff0050;
        color: white;
    }

    .btn-primary:hover {
        background: #cc0040;
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: #f3f4f6;
        color: #4b5563;
        border: 1px solid #d1d5db;
    }

    .btn-secondary:hover {
        background: #e5e7eb;
    }

    .btn-success {
        background: #10b981;
        color: white;
    }

    .btn-success:hover {
        background: #059669;
    }

    .btn-danger {
        background: #ef4444;
        color: white;
    }

    .btn-danger:hover {
        background: #dc2626;
    }

    .content-wrapper {
        padding: 24px;
    }

    /* Statistics Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
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

    .stat-number {
        font-size: 24px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 4px;
    }

    .stat-label {
        font-size: 14px;
        color: #6b7280;
    }

    .stat-card.total .stat-number {
        color: #3b82f6;
    }

    .stat-card.active .stat-number {
        color: #10b981;
    }

    .stat-card.expired .stat-number {
        color: #f59e0b;
    }

    .stat-card.disabled .stat-number {
        color: #ef4444;
    }

    /* Create Coupon Form */
    .create-section {
        background: white;
        padding: 24px;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        margin-bottom: 24px;
    }

    .section-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .form-label {
        font-size: 14px;
        font-weight: 500;
        color: #374151;
    }

    .form-input {
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        transition: border-color 0.3s ease;
    }

    .form-input:focus {
        outline: none;
        border-color: #ff0050;
    }

    .form-select {
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        background: white;
        cursor: pointer;
    }

    .form-select:focus {
        outline: none;
        border-color: #ff0050;
    }

    .form-textarea {
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        resize: vertical;
        min-height: 80px;
    }

    .form-textarea:focus {
        outline: none;
        border-color: #ff0050;
    }

    .input-group {
        display: flex;
        gap: 8px;
        align-items: end;
    }

    .input-group .form-input {
        flex: 1;
    }

    .generate-btn {
        padding: 10px 12px;
        background: #f3f4f6;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        cursor: pointer;
        font-size: 12px;
        color: #4b5563;
        transition: all 0.3s ease;
    }

    .generate-btn:hover {
        background: #e5e7eb;
    }

    .form-row {
        grid-column: 1 / -1;
    }

    .checkbox-group {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 8px;
    }

    .checkbox-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 14px;
    }

    .checkbox-item:hover {
        background: #f9fafb;
    }

    .checkbox-item.selected {
        background: #fef2f2;
        border-color: #ff0050;
        color: #ff0050;
    }

    /* Filters */
    .filters-section {
        background: white;
        padding: 20px;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        margin-bottom: 24px;
    }

    .filters-row {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr auto;
        gap: 16px;
        align-items: end;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .filter-label {
        font-size: 14px;
        font-weight: 500;
        color: #374151;
    }

    .filter-input {
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        transition: border-color 0.3s ease;
    }

    .filter-input:focus {
        outline: none;
        border-color: #ff0050;
    }

    .filter-select {
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        background: white;
        cursor: pointer;
    }

    .filter-select:focus {
        outline: none;
        border-color: #ff0050;
    }

    /* Coupons Table */
    .coupons-section {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    .coupons-header {
        padding: 20px 24px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .coupons-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
    }

    .coupons-table {
        width: 100%;
        border-collapse: collapse;
    }

    .coupons-table th,
    .coupons-table td {
        padding: 12px 16px;
        text-align: left;
        border-bottom: 1px solid #f3f4f6;
    }

    .coupons-table th {
        background: #f8f9fa;
        font-weight: 600;
        color: #374151;
        font-size: 14px;
    }

    .coupons-table tr:hover {
        background: #f9fafb;
    }

    .coupon-code {
        font-family: monospace;
        background: #f3f4f6;
        padding: 4px 8px;
        border-radius: 4px;
        font-weight: 600;
        color: #1f2937;
    }

    .discount-info {
        display: flex;
        flex-direction: column;
    }

    .discount-value {
        font-weight: 600;
        color: #ff0050;
    }

    .discount-condition {
        font-size: 12px;
        color: #6b7280;
    }

    .usage-progress {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .usage-text {
        font-size: 12px;
        color: #6b7280;
    }

    .progress-bar {
        width: 100%;
        height: 4px;
        background: #f3f4f6;
        border-radius: 2px;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        background: #ff0050;
        transition: width 0.3s ease;
    }

    .date-info {
        font-size: 12px;
        color: #6b7280;
    }

    .status-badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
        text-transform: uppercase;
    }

    .status-active {
        background: #dcfce7;
        color: #166534;
    }

    .status-expired {
        background: #fef3c7;
        color: #92400e;
    }

    .status-disabled {
        background: #fee2e2;
        color: #dc2626;
    }

    .status-scheduled {
        background: #e0e7ff;
        color: #3730a3;
    }

    .actions-cell {
        text-align: center;
    }

    .action-dropdown {
        position: relative;
        display: inline-block;
    }

    .action-btn {
        background: #f3f4f6;
        border: 1px solid #d1d5db;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        color: #4b5563;
    }

    .action-btn:hover {
        background: #e5e7eb;
    }

    .action-menu {
        position: absolute;
        top: 100%;
        right: 0;
        background: white;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        min-width: 150px;
        z-index: 100;
        display: none;
    }

    .action-menu.show {
        display: block;
    }

    .action-menu-item {
        display: block;
        padding: 8px 12px;
        color: #374151;
        text-decoration: none;
        font-size: 14px;
        border: none;
        background: none;
        width: 100%;
        text-align: left;
        cursor: pointer;
    }

    .action-menu-item:hover {
        background: #f3f4f6;
    }

    .action-menu-item.danger {
        color: #dc2626;
    }

    .action-menu-item.danger:hover {
        background: #fef2f2;
    }

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        padding: 24px;
        border-top: 1px solid #e5e7eb;
    }

    .pagination-btn {
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        background: white;
        color: #4b5563;
        text-decoration: none;
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .pagination-btn:hover {
        background: #f3f4f6;
    }

    .pagination-btn.active {
        background: #ff0050;
        color: white;
        border-color: #ff0050;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6b7280;
    }

    .empty-state-icon {
        font-size: 64px;
        margin-bottom: 16px;
        opacity: 0.5;
    }

    .empty-state h3 {
        font-size: 18px;
        color: #374151;
        margin-bottom: 8px;
    }

    .empty-state p {
        font-size: 14px;
        margin-bottom: 16px;
    }

    /* Alert */
    .alert {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
    }

    .alert-success {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
    }

    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }

    /* Toggle */
    .toggle-section {
        margin-bottom: 20px;
    }

    .toggle-btn {
        background: #f3f4f6;
        border: 1px solid #d1d5db;
        padding: 10px 16px;
        border-radius: 8px;
        cursor: pointer;
        color: #4b5563;
        transition: all 0.3s ease;
    }

    .toggle-btn.active {
        background: #ff0050;
        color: white;
        border-color: #ff0050;
    }

    .collapsible-content {
        display: none;
        margin-top: 20px;
    }

    .collapsible-content.show {
        display: block;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .form-grid {
            grid-template-columns: 1fr;
        }

        .filters-row {
            grid-template-columns: 1fr;
            gap: 12px;
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

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .create-section,
        .filters-section {
            padding: 16px;
        }

        .coupons-header {
            padding: 16px;
            flex-direction: column;
            gap: 12px;
            align-items: stretch;
        }

        .coupons-table {
            font-size: 12px;
        }

        .coupons-table th,
        .coupons-table td {
            padding: 8px 12px;
        }

        .header-actions {
            flex-wrap: wrap;
            gap: 8px;
        }
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
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
                    <div>
                        <h1>Phiếu giảm giá</h1>
                        <div class="breadcrumb">
                            <a href="dashboard.php">Dashboard</a>
                            <span>›</span>
                            <span>Phiếu giảm giá</span>
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <button class="btn btn-secondary" onclick="toggleCreateForm()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                        </svg>
                        Tạo phiếu mới
                    </button>
                    <button class="btn btn-secondary" onclick="window.location.reload()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 8 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z" />
                        </svg>
                        Làm mới
                    </button>
                </div>
            </div>

            <div class="content-wrapper">
                <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card total">
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Tổng phiếu giảm giá</div>
                    </div>
                    <div class="stat-card active">
                        <div class="stat-number"><?php echo $stats['active']; ?></div>
                        <div class="stat-label">Đang hoạt động</div>
                    </div>
                    <div class="stat-card expired">
                        <div class="stat-number"><?php echo $stats['expired']; ?></div>
                        <div class="stat-label">Hết hạn</div>
                    </div>
                    <div class="stat-card disabled">
                        <div class="stat-number"><?php echo $stats['disabled']; ?></div>
                        <div class="stat-label">Đã tắt</div>
                    </div>
                </div>

                <!-- Create Coupon Form -->
                <div class="toggle-section">
                    <button class="toggle-btn" id="createToggle" onclick="toggleCreateForm()">
                        ➕ Tạo phiếu giảm giá mới
                    </button>
                </div>

                <div class="create-section collapsible-content" id="createForm">
                    <form method="POST" id="couponForm">
                        <input type="hidden" name="action" value="create_coupon">

                        <h3 class="section-title">
                            🎫 Tạo phiếu giảm giá mới
                        </h3>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Mã giảm giá *</label>
                                <div class="input-group">
                                    <input type="text" name="code" class="form-input"
                                        placeholder="VD: SALE50, NEWUSER..." required>
                                    <button type="button" class="generate-btn" onclick="generateCode()">
                                        Tự động tạo
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Loại giảm giá *</label>
                                <select name="discount_type" class="form-select"
                                    onchange="updateDiscountLabel(this.value)">
                                    <option value="percent">Giảm theo phần trăm (%)</option>
                                    <option value="amount">Giảm số tiền cố định (đ)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label" id="discountLabel">Giá trị giảm (%) *</label>
                                <input type="number" name="discount" class="form-input" placeholder="VD: 10, 20, 50..."
                                    min="0" step="0.1" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Đơn hàng tối thiểu</label>
                                <input type="number" name="min_buy" class="form-input" placeholder="VD: 100000" min="0">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Số lần sử dụng tối đa</label>
                                <input type="number" name="usage_limit" class="form-input"
                                    placeholder="0 = Không giới hạn" min="0">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Ngày bắt đầu</label>
                                <input type="datetime-local" name="start_date" class="form-input">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Ngày kết thúc</label>
                                <input type="datetime-local" name="end_date" class="form-input">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Loại phiếu</label>
                                <select name="type" class="form-select">
                                    <option value="discount">Giảm giá chung</option>
                                    <option value="shipping">Miễn phí vận chuyển</option>
                                    <option value="product">Giảm giá sản phẩm cụ thể</option>
                                </select>
                            </div>

                            <div class="form-group form-row">
                                <label class="form-label">Mô tả</label>
                                <textarea name="description" class="form-textarea"
                                    placeholder="Mô tả chi tiết về phiếu giảm giá..."></textarea>
                            </div>

                            <div class="form-group form-row">
                                <label class="form-label">Áp dụng cho sản phẩm (tùy chọn)</label>
                                <div class="checkbox-group">
                                    <?php foreach ($seller_products as $product): ?>
                                    <label class="checkbox-item"
                                        onclick="toggleProduct(this, <?php echo $product['id']; ?>)">
                                        <input type="checkbox" name="product_ids[]"
                                            value="<?php echo $product['id']; ?>" style="display: none;">
                                        <span><?php echo htmlspecialchars($product['name']); ?>
                                            (<?php echo formatCurrency($product['unit_price']); ?>)</span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div style="text-align: center; margin-top: 20px;">
                            <button type="submit" class="btn btn-primary">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                                </svg>
                                Tạo phiếu giảm giá
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Filters -->
                <div class="filters-section">
                    <form method="GET" id="filterForm">
                        <div class="filters-row">
                            <div class="filter-group">
                                <label class="filter-label">Tìm kiếm</label>
                                <input type="text" name="search" class="filter-input"
                                    placeholder="Mã giảm giá, mô tả..."
                                    value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Trạng thái</label>
                                <select name="status" class="filter-select">
                                    <option value="">Tất cả</option>
                                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>
                                        Đang hoạt động</option>
                                    <option value="expired"
                                        <?php echo $status_filter == 'expired' ? 'selected' : ''; ?>>
                                        Hết hạn</option>
                                    <option value="disabled"
                                        <?php echo $status_filter == 'disabled' ? 'selected' : ''; ?>>
                                        Đã tắt</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Loại phiếu</label>
                                <select name="type" class="filter-select">
                                    <option value="">Tất cả</option>
                                    <option value="discount"
                                        <?php echo $type_filter == 'discount' ? 'selected' : ''; ?>>
                                        Giảm giá chung</option>
                                    <option value="shipping"
                                        <?php echo $type_filter == 'shipping' ? 'selected' : ''; ?>>
                                        Miễn phí ship</option>
                                    <option value="product" <?php echo $type_filter == 'product' ? 'selected' : ''; ?>>
                                        Sản phẩm cụ thể</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <button type="submit" class="btn btn-primary">Lọc</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Coupons Table -->
                <div class="coupons-section">
                    <div class="coupons-header">
                        <div class="coupons-title">
                            Danh sách phiếu giảm giá
                            <span style="color: #6b7280; font-weight: normal;">
                                (<?php echo $total_coupons; ?> phiếu)
                            </span>
                        </div>
                    </div>

                    <?php if (empty($coupons)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🎫</div>
                        <h3>Chưa có phiếu giảm giá nào</h3>
                        <p>Tạo phiếu giảm giá đầu tiên để thu hút khách hàng</p>
                        <button class="btn btn-primary" onclick="toggleCreateForm()">
                            Tạo phiếu mới
                        </button>
                    </div>
                    <?php else: ?>
                    <table class="coupons-table">
                        <thead>
                            <tr>
                                <th>Mã giảm giá</th>
                                <th>Giá trị</th>
                                <th>Điều kiện</th>
                                <th>Sử dụng</th>
                                <th>Thời gian</th>
                                <th>Trạng thái</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($coupons as $coupon):
                                    $details = json_decode($coupon['details'], true);
                                    $progress = getCouponProgress($coupon);
                                    ?>
                            <tr>
                                <td>
                                    <div class="coupon-code"><?php echo htmlspecialchars($coupon['code']); ?></div>
                                    <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                        <?php echo htmlspecialchars($details['description'] ?? ''); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="discount-info">
                                        <div class="discount-value">
                                            <?php
                                                    if ($coupon['discount_type'] === 'percent') {
                                                        echo $coupon['discount'] . '%';
                                                    } else {
                                                        echo formatCurrency($coupon['discount']);
                                                    }
                                                    ?>
                                        </div>
                                        <div class="discount-condition">
                                            <?php echo ucfirst($coupon['type']); ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($details['min_buy'] > 0): ?>
                                    <div class="discount-condition">
                                        Đơn tối thiểu: <?php echo formatCurrency($details['min_buy']); ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($details['product_ids'])): ?>
                                    <div class="discount-condition">
                                        Áp dụng cho <?php echo count($details['product_ids']); ?> sản phẩm
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="usage-progress">
                                        <div class="usage-text">
                                            <?php echo $progress['current']; ?> / <?php echo $progress['limit']; ?>
                                        </div>
                                        <?php if (is_numeric($progress['limit'])): ?>
                                        <div class="progress-bar">
                                            <div class="progress-fill"
                                                style="width: <?php echo $progress['percentage']; ?>%"></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($coupon['start_date']): ?>
                                    <div class="date-info">
                                        Từ: <?php echo date('d/m/Y H:i', $coupon['start_date']); ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($coupon['end_date']): ?>
                                    <div class="date-info">
                                        Đến: <?php echo date('d/m/Y H:i', $coupon['end_date']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo getStatusBadge($coupon); ?>
                                </td>
                                <td class="actions-cell">
                                    <div class="action-dropdown">
                                        <button class="action-btn" onclick="toggleActionMenu(this)">
                                            ⋯
                                        </button>
                                        <div class="action-menu">
                                            <button class="action-menu-item"
                                                onclick="toggleCouponStatus(<?php echo $coupon['id']; ?>)">
                                                <?php echo $coupon['status'] ? '⏸️ Tắt' : '▶️ Bật'; ?>
                                            </button>
                                            <button class="action-menu-item"
                                                onclick="copyCouponCode('<?php echo $coupon['code']; ?>')">
                                                📋 Copy mã
                                            </button>
                                            <button class="action-menu-item danger"
                                                onclick="deleteCoupon(<?php echo $coupon['id']; ?>, '<?php echo htmlspecialchars($coupon['code']); ?>')">
                                                🗑️ Xóa
                                            </button>
                                        </div>
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
                                $current_params = $_GET;
                                unset($current_params['page']);
                                $base_url = '?' . http_build_query($current_params);
                                $base_url = $base_url === '?' ? '?page=' : $base_url . '&page=';
                                ?>

                        <?php if ($page > 1): ?>
                        <a href="<?php echo $base_url . ($page - 1); ?>" class="pagination-btn">‹ Trước</a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="<?php echo $base_url . $i; ?>"
                            class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                        <a href="<?php echo $base_url . ($page + 1); ?>" class="pagination-btn">Tiếp ›</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden Forms -->
    <form id="statusForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="coupon_id" id="statusCouponId">
    </form>

    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete_coupon">
        <input type="hidden" name="coupon_id" id="deleteCouponId">
    </form>

    <script>
    // Toggle create form
    function toggleCreateForm() {
        const form = document.getElementById('createForm');
        const toggle = document.getElementById('createToggle');

        if (form.classList.contains('show')) {
            form.classList.remove('show');
            toggle.classList.remove('active');
            toggle.innerHTML = '➕ Tạo phiếu giảm giá mới';
        } else {
            form.classList.add('show');
            toggle.classList.add('active');
            toggle.innerHTML = '➖ Ẩn form tạo phiếu';
        }
    }

    // Generate random coupon code
    function generateCode() {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        let code = '';
        for (let i = 0; i < 8; i++) {
            code += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.querySelector('input[name="code"]').value = code;
    }

    // Update discount label
    function updateDiscountLabel(type) {
        const label = document.getElementById('discountLabel');
        if (type === 'percent') {
            label.textContent = 'Giá trị giảm (%) *';
        } else {
            label.textContent = 'Giá trị giảm (đ) *';
        }
    }

    // Toggle product selection
    function toggleProduct(element, productId) {
        const checkbox = element.querySelector('input[type="checkbox"]');
        checkbox.checked = !checkbox.checked;

        if (checkbox.checked) {
            element.classList.add('selected');
        } else {
            element.classList.remove('selected');
        }
    }

    // Action menu toggle
    function toggleActionMenu(button) {
        const menu = button.nextElementSibling;
        const isShow = menu.classList.contains('show');

        // Close all menus
        document.querySelectorAll('.action-menu').forEach(m => m.classList.remove('show'));

        if (!isShow) {
            menu.classList.add('show');
        }
    }

    // Close action menus when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.action-dropdown')) {
            document.querySelectorAll('.action-menu').forEach(menu => {
                menu.classList.remove('show');
            });
        }
    });

    // Toggle coupon status
    function toggleCouponStatus(couponId) {
        if (confirm('Bạn có muốn thay đổi trạng thái của phiếu giảm giá này?')) {
            document.getElementById('statusCouponId').value = couponId;
            document.getElementById('statusForm').submit();
        }
    }

    // Delete coupon
    function deleteCoupon(couponId, couponCode) {
        if (confirm(`Bạn có chắc chắn muốn xóa phiếu giảm giá "${couponCode}"?\n\nHành động này không thể hoàn tác!`)) {
            document.getElementById('deleteCouponId').value = couponId;
            document.getElementById('deleteForm').submit();
        }
    }

    // Copy coupon code
    function copyCouponCode(code) {
        navigator.clipboard.writeText(code).then(function() {
            alert(`Đã copy mã "${code}" vào clipboard!`);
        }, function() {
            alert('Không thể copy mã. Vui lòng copy thủ công.');
        });
    }

    // Auto-submit filter form on change
    document.querySelectorAll('.filter-select, .filter-input').forEach(input => {
        if (input.name !== 'search') {
            input.addEventListener('change', function() {
                if (this.name === 'search') return;
                document.getElementById('filterForm').submit();
            });
        }
    });

    // Search with debounce
    let searchTimeout;
    document.querySelector('input[name="search"]').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            document.getElementById('filterForm').submit();
        }, 500);
    });

    // Set default dates
    document.addEventListener('DOMContentLoaded', function() {
        const startDate = document.querySelector('input[name="start_date"]');
        const endDate = document.querySelector('input[name="end_date"]');

        // Set start date to now
        const now = new Date();
        startDate.value = now.toISOString().slice(0, 16);

        // Set end date to 30 days from now
        const future = new Date(now.getTime() + 30 * 24 * 60 * 60 * 1000);
        endDate.value = future.toISOString().slice(0, 16);
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey || e.metaKey) {
            if (e.key === 'n') {
                e.preventDefault();
                toggleCreateForm();
            }
            if (e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
        }
        if (e.key === 'Escape') {
            document.querySelectorAll('.action-menu').forEach(menu => {
                menu.classList.remove('show');
            });
        }
    });

    // Sidebar toggle for mobile
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            sidebar.classList.toggle('open');
        }
    }
    </script>
</body>

</html>