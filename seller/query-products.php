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

// Handle reply submission
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'reply_query') {
    $query_id = (int)($_POST['query_id'] ?? 0);
    $reply = trim($_POST['reply'] ?? '');
    
    try {
        if (empty($reply)) {
            throw new Exception("Vui lòng nhập nội dung trả lời!");
        }
        
        // Verify query belongs to seller
        $verify_stmt = $pdo->prepare("
            SELECT pq.*, p.name as product_name, u.name as customer_name
            FROM product_queries pq
            LEFT JOIN products p ON pq.product_id = p.id  
            LEFT JOIN users u ON pq.customer_id = u.id
            WHERE pq.id = ? AND pq.seller_id = ?
        ");
        $verify_stmt->execute([$query_id, $seller_id]);
        $query_info = $verify_stmt->fetch();
        
        if (!$query_info) {
            throw new Exception("Không tìm thấy câu hỏi!");
        }
        
        // Update reply
        $reply_stmt = $pdo->prepare("
            UPDATE product_queries 
            SET reply = ?, updated_at = NOW() 
            WHERE id = ? AND seller_id = ?
        ");
        $reply_stmt->execute([$reply, $query_id, $seller_id]);
        
        // Create notification for customer (optional)
        try {
            $notif_stmt = $pdo->prepare("
                INSERT INTO firebase_notifications (
                    title, text, item_type, item_type_id, receiver_id, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $notif_stmt->execute([
                "Seller đã trả lời câu hỏi của bạn",
                "Về sản phẩm: " . $query_info['product_name'],
                "product_query",
                $query_id,
                $query_info['customer_id']
            ]);
        } catch (Exception $e) {
            // Ignore notification error
        }
        
        $success = "Đã trả lời câu hỏi thành công!";
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = "Có lỗi xảy ra: " . $e->getMessage();
    }
}

// Handle bulk actions
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'bulk_action') {
    $bulk_action = $_POST['bulk_action'] ?? '';
    $selected_queries = $_POST['selected_queries'] ?? [];
    
    if (!empty($selected_queries) && !empty($bulk_action)) {
        try {
            $placeholders = str_repeat('?,', count($selected_queries) - 1) . '?';
            $params = array_merge($selected_queries, [$seller_id]);
            
            if ($bulk_action === 'mark_read') {
                // Mark as read (you could add a read status field)
                $success = "Đã đánh dấu " . count($selected_queries) . " câu hỏi đã đọc.";
            } elseif ($bulk_action === 'delete') {
                $delete_stmt = $pdo->prepare("
                    DELETE FROM product_queries 
                    WHERE id IN ($placeholders) AND seller_id = ?
                ");
                $delete_stmt->execute($params);
                $success = "Đã xóa " . count($selected_queries) . " câu hỏi.";
            }
        } catch (PDOException $e) {
            $error = "Có lỗi xảy ra khi thực hiện thao tác hàng loạt.";
        }
    }
}

// Get filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$product_filter = isset($_GET['product']) ? (int)$_GET['product'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sort_order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

try {
    // Build WHERE clause
    $where_conditions = ["pq.seller_id = ?"];
    $params = [$seller_id];
    
    if (!empty($search)) {
        $where_conditions[] = "(pq.question LIKE ? OR pq.reply LIKE ? OR u.name LIKE ? OR p.name LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if ($status_filter !== '') {
        if ($status_filter === 'answered') {
            $where_conditions[] = "pq.reply IS NOT NULL AND pq.reply != ''";
        } elseif ($status_filter === 'pending') {
            $where_conditions[] = "(pq.reply IS NULL OR pq.reply = '')";
        }
    }
    
    if ($product_filter > 0) {
        $where_conditions[] = "pq.product_id = ?";
        $params[] = $product_filter;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // Validate sort columns
    $allowed_sorts = ['created_at', 'updated_at', 'customer_name', 'product_name'];
    if (!in_array($sort_by, $allowed_sorts)) {
        $sort_by = 'created_at';
    }
    $sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
    
    // Get queries with customer and product info
    $stmt = $pdo->prepare("
        SELECT 
            pq.*,
            u.name as customer_name,
            u.avatar as customer_avatar,
            p.name as product_name,
            p.thumbnail_img as product_image,
            thumb.file_name as product_image_file,
            CASE 
                WHEN pq.reply IS NOT NULL AND pq.reply != '' THEN 'answered'
                ELSE 'pending'
            END as status,
            TIMESTAMPDIFF(HOUR, pq.created_at, NOW()) as hours_ago
        FROM product_queries pq
        LEFT JOIN users u ON pq.customer_id = u.id
        LEFT JOIN products p ON pq.product_id = p.id
        LEFT JOIN uploads thumb ON p.thumbnail_img = thumb.id AND thumb.deleted_at IS NULL
        $where_clause
        ORDER BY pq.$sort_by $sort_order
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $queries = $stmt->fetchAll();
    
    // Get total count
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM product_queries pq
        LEFT JOIN users u ON pq.customer_id = u.id
        LEFT JOIN products p ON pq.product_id = p.id
        $where_clause
    ");
    $count_stmt->execute($params);
    $total_queries = $count_stmt->fetchColumn();
    $total_pages = ceil($total_queries / $limit);
    
    // Get statistics
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN reply IS NOT NULL AND reply != '' THEN 1 ELSE 0 END) as answered,
            SUM(CASE WHEN reply IS NULL OR reply = '' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week
        FROM product_queries 
        WHERE seller_id = ?
    ");
    $stats_stmt->execute([$seller_id]);
    $stats = $stats_stmt->fetch();
    
    // Get seller's products for filter
    $products_stmt = $pdo->prepare("
        SELECT DISTINCT p.id, p.name, COUNT(pq.id) as query_count
        FROM products p
        LEFT JOIN product_queries pq ON p.id = pq.product_id
        WHERE p.user_id = ? AND p.published = 1
        GROUP BY p.id, p.name
        HAVING query_count > 0
        ORDER BY query_count DESC, p.name ASC
    ");
    $products_stmt->execute([$seller_id]);
    $products = $products_stmt->fetchAll();
    
    // Get recent activity (last 5 queries)
    $recent_stmt = $pdo->prepare("
        SELECT 
            pq.created_at,
            u.name as customer_name,
            p.name as product_name,
            CASE 
                WHEN pq.reply IS NOT NULL AND pq.reply != '' THEN 'answered'
                ELSE 'pending'
            END as status
        FROM product_queries pq
        LEFT JOIN users u ON pq.customer_id = u.id
        LEFT JOIN products p ON pq.product_id = p.id
        WHERE pq.seller_id = ?
        ORDER BY pq.created_at DESC
        LIMIT 5
    ");
    $recent_stmt->execute([$seller_id]);
    $recent_activity = $recent_stmt->fetchAll();

} catch (PDOException $e) {
    $queries = [];
    $total_queries = 0;
    $total_pages = 1;
    $stats = ['total' => 0, 'answered' => 0, 'pending' => 0, 'today' => 0, 'this_week' => 0];
    $products = [];
    $recent_activity = [];
    $error = "Có lỗi xảy ra khi tải dữ liệu.";
}

function getStatusBadge($status) {
    $badges = [
        'answered' => '<span class="status-badge status-answered">Đã trả lời</span>',
        'pending' => '<span class="status-badge status-pending">Chờ trả lời</span>'
    ];
    return $badges[$status] ?? '';
}

function getProductImage($query, $pdo = null) {
    if (!empty($query['product_image_file'])) {
        return '../' . $query['product_image_file'];
    }
    return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSI+PHJlY3Qgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBmaWxsPSIjZjNmNGY2Ii8+PHBhdGggZD0iTTE2IDE2aDh2OGgtOHoiIGZpbGw9IiNkOWRjZTAiLz48L3N2Zz4=';
}

function getTimeAgo($hours) {
    if ($hours < 1) {
        return "Vừa xong";
    } elseif ($hours < 24) {
        return $hours . " giờ trước";
    } elseif ($hours < 168) { // 7 days
        $days = floor($hours / 24);
        return $days . " ngày trước";
    } else {
        $weeks = floor($hours / 168);
        return $weeks . " tuần trước";
    }
}

function truncateText($text, $length = 100) {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . '...';
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Câu hỏi sản phẩm - TikTok Shop Seller</title>
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
        position: relative;
        overflow: hidden;
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--accent-color, #ff0050);
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

    .stat-card.total {
        --accent-color: #3b82f6;
    }

    .stat-card.answered {
        --accent-color: #10b981;
    }

    .stat-card.pending {
        --accent-color: #f59e0b;
    }

    .stat-card.today {
        --accent-color: #ef4444;
    }

    /* Two Column Layout */
    .two-column {
        display: grid;
        grid-template-columns: 1fr 300px;
        gap: 24px;
        margin-bottom: 24px;
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
        grid-template-columns: 2fr 1fr 1fr 1fr auto;
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

    /* Queries Section */
    .queries-section {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    .queries-header {
        padding: 20px 24px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .queries-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
    }

    .bulk-actions {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .bulk-select {
        padding: 6px 10px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 12px;
        background: white;
    }

    .bulk-btn {
        padding: 6px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 12px;
        background: white;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .bulk-btn:hover {
        background: #f3f4f6;
    }

    /* Query Items */
    .query-list {
        max-height: 600px;
        overflow-y: auto;
    }

    .query-item {
        padding: 20px 24px;
        border-bottom: 1px solid #f3f4f6;
        transition: background 0.3s ease;
        position: relative;
    }

    .query-item:hover {
        background: #f9fafb;
    }

    .query-item.unread::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 4px;
        background: #ff0050;
    }

    .query-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
    }

    .query-checkbox {
        margin-right: 8px;
    }

    .product-info {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1;
    }

    .product-image {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        object-fit: cover;
        border: 1px solid #e5e7eb;
    }

    .product-details {
        flex: 1;
    }

    .product-name {
        font-weight: 500;
        color: #1f2937;
        font-size: 14px;
        margin-bottom: 2px;
    }

    .customer-info {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: #6b7280;
    }

    .customer-avatar {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        object-fit: cover;
    }

    .query-meta {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .query-time {
        font-size: 12px;
        color: #6b7280;
    }

    .status-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 500;
        text-transform: uppercase;
    }

    .status-answered {
        background: #dcfce7;
        color: #166534;
    }

    .status-pending {
        background: #fef3c7;
        color: #92400e;
    }

    .query-content {
        margin-bottom: 16px;
    }

    .question {
        background: #f8fafc;
        padding: 12px;
        border-radius: 8px;
        border-left: 4px solid #3b82f6;
        margin-bottom: 12px;
    }

    .question-label {
        font-size: 12px;
        color: #6b7280;
        margin-bottom: 4px;
        text-transform: uppercase;
        font-weight: 500;
    }

    .question-text {
        color: #1f2937;
        line-height: 1.5;
    }

    .reply {
        background: #f0fdf4;
        padding: 12px;
        border-radius: 8px;
        border-left: 4px solid #10b981;
    }

    .reply-label {
        font-size: 12px;
        color: #6b7280;
        margin-bottom: 4px;
        text-transform: uppercase;
        font-weight: 500;
    }

    .reply-text {
        color: #1f2937;
        line-height: 1.5;
    }

    .query-actions {
        display: flex;
        gap: 8px;
    }

    .action-btn {
        padding: 6px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
        background: white;
        color: #4b5563;
    }

    .action-btn:hover {
        background: #f3f4f6;
    }

    .action-btn.primary {
        background: #ff0050;
        color: white;
        border-color: #ff0050;
    }

    .action-btn.primary:hover {
        background: #cc0040;
    }

    /* Reply Form */
    .reply-form {
        margin-top: 12px;
        padding: 12px;
        background: #f9fafb;
        border-radius: 8px;
        display: none;
    }

    .reply-form.show {
        display: block;
    }

    .reply-textarea {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
        resize: vertical;
        min-height: 80px;
        margin-bottom: 8px;
    }

    .reply-textarea:focus {
        outline: none;
        border-color: #ff0050;
    }

    .reply-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
    }

    /* Recent Activity Sidebar */
    .sidebar-section {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        padding: 20px;
        height: fit-content;
    }

    .sidebar-title {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 16px;
    }

    .activity-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 8px 0;
        border-bottom: 1px solid #f3f4f6;
    }

    .activity-item:last-child {
        border-bottom: none;
    }

    .activity-text {
        font-size: 14px;
        color: #1f2937;
    }

    .activity-meta {
        font-size: 12px;
        color: #6b7280;
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

    /* Responsive */
    @media (max-width: 1200px) {
        .filters-row {
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .two-column {
            grid-template-columns: 1fr;
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

        .filters-section,
        .queries-section,
        .sidebar-section {
            padding: 16px;
        }

        .queries-header {
            padding: 16px;
            flex-direction: column;
            gap: 12px;
            align-items: stretch;
        }

        .query-item {
            padding: 16px;
        }

        .query-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }

        .product-info {
            order: 1;
        }

        .query-meta {
            order: 2;
            align-self: flex-end;
        }
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .bulk-actions {
            flex-direction: column;
            gap: 4px;
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
                        <h1>Câu hỏi sản phẩm</h1>
                        <div class="breadcrumb">
                            <a href="dashboard.php">Dashboard</a>
                            <span>›</span>
                            <span>Câu hỏi sản phẩm</span>
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <button class="btn btn-secondary" onclick="markAllAsRead()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z" />
                        </svg>
                        Đánh dấu đã đọc
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
                        <div class="stat-label">Tổng câu hỏi</div>
                    </div>
                    <div class="stat-card answered">
                        <div class="stat-number"><?php echo $stats['answered']; ?></div>
                        <div class="stat-label">Đã trả lời</div>
                    </div>
                    <div class="stat-card pending">
                        <div class="stat-number"><?php echo $stats['pending']; ?></div>
                        <div class="stat-label">Chờ trả lời</div>
                    </div>
                    <div class="stat-card today">
                        <div class="stat-number"><?php echo $stats['today']; ?></div>
                        <div class="stat-label">Hôm nay</div>
                    </div>
                </div>

                <!-- Two Column Layout -->
                <div class="two-column">
                    <!-- Main Content -->
                    <div>
                        <!-- Filters -->
                        <div class="filters-section">
                            <form method="GET" id="filterForm">
                                <div class="filters-row">
                                    <div class="filter-group">
                                        <label class="filter-label">Tìm kiếm</label>
                                        <input type="text" name="search" class="filter-input"
                                            placeholder="Câu hỏi, sản phẩm, khách hàng..."
                                            value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                    <div class="filter-group">
                                        <label class="filter-label">Trạng thái</label>
                                        <select name="status" class="filter-select">
                                            <option value="">Tất cả</option>
                                            <option value="pending"
                                                <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>
                                                Chờ trả lời</option>
                                            <option value="answered"
                                                <?php echo $status_filter == 'answered' ? 'selected' : ''; ?>>
                                                Đã trả lời</option>
                                        </select>
                                    </div>
                                    <div class="filter-group">
                                        <label class="filter-label">Sản phẩm</label>
                                        <select name="product" class="filter-select">
                                            <option value="">Tất cả sản phẩm</option>
                                            <?php foreach ($products as $product): ?>
                                            <option value="<?php echo $product['id']; ?>"
                                                <?php echo $product_filter == $product['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($product['name']); ?>
                                                (<?php echo $product['query_count']; ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="filter-group">
                                        <label class="filter-label">Sắp xếp</label>
                                        <select name="sort" class="filter-select"
                                            onchange="document.getElementById('filterForm').submit()">
                                            <option value="created_at"
                                                <?php echo $sort_by == 'created_at' ? 'selected' : ''; ?>>
                                                Mới nhất</option>
                                            <option value="updated_at"
                                                <?php echo $sort_by == 'updated_at' ? 'selected' : ''; ?>>
                                                Cập nhật</option>
                                            <option value="customer_name"
                                                <?php echo $sort_by == 'customer_name' ? 'selected' : ''; ?>>
                                                Tên khách hàng</option>
                                        </select>
                                        <input type="hidden" name="order" value="<?php echo $sort_order; ?>">
                                    </div>
                                    <div class="filter-group">
                                        <button type="submit" class="btn btn-primary">Lọc</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Queries Section -->
                        <div class="queries-section">
                            <div class="queries-header">
                                <div class="queries-title">
                                    Danh sách câu hỏi
                                    <span style="color: #6b7280; font-weight: normal;">
                                        (<?php echo $total_queries; ?> câu hỏi)
                                    </span>
                                </div>
                                <div class="bulk-actions">
                                    <select class="bulk-select" id="bulkAction">
                                        <option value="">Thao tác hàng loạt</option>
                                        <option value="mark_read">Đánh dấu đã đọc</option>
                                        <option value="delete">Xóa đã chọn</option>
                                    </select>
                                    <button class="bulk-btn" onclick="executeBulkAction()">Thực hiện</button>
                                </div>
                            </div>

                            <div class="query-list">
                                <?php if (empty($queries)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">❓</div>
                                    <h3>Chưa có câu hỏi nào</h3>
                                    <p>Khách hàng sẽ gửi câu hỏi về sản phẩm của bạn tại đây</p>
                                </div>
                                <?php else: ?>
                                <?php foreach ($queries as $query): ?>
                                <div class="query-item <?php echo $query['status'] == 'pending' ? 'unread' : ''; ?>">
                                    <div class="query-header">
                                        <input type="checkbox" class="query-checkbox"
                                            value="<?php echo $query['id']; ?>">
                                        <div class="product-info">
                                            <img src="<?php echo getProductImage($query); ?>"
                                                alt="<?php echo htmlspecialchars($query['product_name']); ?>"
                                                class="product-image">
                                            <div class="product-details">
                                                <div class="product-name">
                                                    <?php echo htmlspecialchars($query['product_name']); ?></div>
                                                <div class="customer-info">
                                                    <span>👤
                                                        <?php echo htmlspecialchars($query['customer_name']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="query-meta">
                                            <div class="query-time"><?php echo getTimeAgo($query['hours_ago']); ?></div>
                                            <?php echo getStatusBadge($query['status']); ?>
                                        </div>
                                    </div>

                                    <div class="query-content">
                                        <div class="question">
                                            <div class="question-label">Câu hỏi</div>
                                            <div class="question-text">
                                                <?php echo nl2br(htmlspecialchars($query['question'])); ?></div>
                                        </div>

                                        <?php if (!empty($query['reply'])): ?>
                                        <div class="reply">
                                            <div class="reply-label">Trả lời của bạn</div>
                                            <div class="reply-text">
                                                <?php echo nl2br(htmlspecialchars($query['reply'])); ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="query-actions">
                                        <?php if (empty($query['reply'])): ?>
                                        <button class="action-btn primary"
                                            onclick="showReplyForm(<?php echo $query['id']; ?>)">
                                            💬 Trả lời
                                        </button>
                                        <?php else: ?>
                                        <button class="action-btn" onclick="showReplyForm(<?php echo $query['id']; ?>)">
                                            ✏️ Chỉnh sửa
                                        </button>
                                        <?php endif; ?>
                                        <button class="action-btn" onclick="copyQueryText(<?php echo $query['id']; ?>)">
                                            📋 Copy
                                        </button>
                                    </div>

                                    <!-- Reply Form -->
                                    <div class="reply-form" id="replyForm<?php echo $query['id']; ?>">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="reply_query">
                                            <input type="hidden" name="query_id" value="<?php echo $query['id']; ?>">
                                            <textarea name="reply" class="reply-textarea"
                                                placeholder="Nhập câu trả lời của bạn..."
                                                required><?php echo htmlspecialchars($query['reply']); ?></textarea>
                                            <div class="reply-actions">
                                                <button type="button" class="action-btn"
                                                    onclick="hideReplyForm(<?php echo $query['id']; ?>)">
                                                    Hủy
                                                </button>
                                                <button type="submit" class="action-btn primary">
                                                    <?php echo empty($query['reply']) ? 'Gửi trả lời' : 'Cập nhật'; ?>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

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
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="sidebar-section">
                        <div class="sidebar-title">Hoạt động gần đây</div>
                        <?php if (empty($recent_activity)): ?>
                        <div style="text-align: center; color: #6b7280; padding: 20px;">
                            <div style="font-size: 32px; margin-bottom: 8px; opacity: 0.5;">📭</div>
                            <p>Chưa có hoạt động nào</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($recent_activity as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-text">
                                <strong><?php echo htmlspecialchars($activity['customer_name']); ?></strong>
                                <?php echo $activity['status'] == 'answered' ? 'đã được trả lời' : 'hỏi về'; ?>
                                <strong><?php echo htmlspecialchars(truncateText($activity['product_name'], 30)); ?></strong>
                            </div>
                            <div class="activity-meta">
                                <?php echo date('d/m/Y H:i', strtotime($activity['created_at'])); ?>
                                • <?php echo $activity['status'] == 'answered' ? '✅ Đã trả lời' : '⏳ Chờ trả lời'; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden Forms -->
    <form id="bulkForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="bulk_action">
        <input type="hidden" name="bulk_action" id="bulkActionValue">
        <div id="selectedQueriesContainer"></div>
    </form>

    <script>
    // Show reply form
    function showReplyForm(queryId) {
        const form = document.getElementById('replyForm' + queryId);
        form.classList.add('show');
        form.querySelector('textarea').focus();
    }

    // Hide reply form
    function hideReplyForm(queryId) {
        const form = document.getElementById('replyForm' + queryId);
        form.classList.remove('show');
    }

    // Copy query text
    function copyQueryText(queryId) {
        const queryItem = document.querySelector(`[data-query-id="${queryId}"]`);
        if (queryItem) {
            const questionText = queryItem.querySelector('.question-text').textContent;
            navigator.clipboard.writeText(questionText).then(function() {
                alert('Đã copy câu hỏi vào clipboard!');
            });
        }
    }

    // Mark all as read
    function markAllAsRead() {
        if (confirm('Đánh dấu tất cả câu hỏi đã đọc?')) {
            // This would typically send an AJAX request
            alert('Tính năng sẽ được phát triển trong phiên bản tiếp theo');
        }
    }

    // Execute bulk action
    function executeBulkAction() {
        const action = document.getElementById('bulkAction').value;
        const checkboxes = document.querySelectorAll('.query-checkbox:checked');

        if (!action) {
            alert('Vui lòng chọn thao tác!');
            return;
        }

        if (checkboxes.length === 0) {
            alert('Vui lòng chọn ít nhất một câu hỏi!');
            return;
        }

        const actionText = action === 'delete' ? 'xóa' : 'đánh dấu đã đọc';
        if (confirm(`Bạn có chắc muốn ${actionText} ${checkboxes.length} câu hỏi đã chọn?`)) {
            document.getElementById('bulkActionValue').value = action;

            // Add selected queries to form
            const container = document.getElementById('selectedQueriesContainer');
            container.innerHTML = '';
            checkboxes.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_queries[]';
                input.value = checkbox.value;
                container.appendChild(input);
            });

            document.getElementById('bulkForm').submit();
        }
    }

    // Select all/none checkboxes
    document.addEventListener('DOMContentLoaded', function() {
        // Add select all functionality
        const selectAllBtn = document.createElement('button');
        selectAllBtn.textContent = 'Chọn tất cả';
        selectAllBtn.className = 'bulk-btn';
        selectAllBtn.type = 'button';
        selectAllBtn.onclick = function() {
            const checkboxes = document.querySelectorAll('.query-checkbox');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            checkboxes.forEach(cb => cb.checked = !allChecked);
            this.textContent = allChecked ? 'Chọn tất cả' : 'Bỏ chọn tất cả';
        };

        document.querySelector('.bulk-actions').appendChild(selectAllBtn);
    });

    // Auto-submit filter form on change
    document.querySelectorAll('.filter-select, .filter-input').forEach(input => {
        if (input.name !== 'search' && input.name !== 'sort') {
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

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey || e.metaKey) {
            if (e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
            if (e.key === 'a') {
                e.preventDefault();
                const checkboxes = document.querySelectorAll('.query-checkbox');
                const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                checkboxes.forEach(cb => cb.checked = !allChecked);
            }
        }
    });

    // Auto refresh every 30 seconds for new queries
    setInterval(function() {
        // Only refresh if no forms are open
        const openForms = document.querySelectorAll('.reply-form.show');
        if (openForms.length === 0) {
            window.location.reload();
        }
    }, 30000);

    // Sidebar toggle for mobile
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            sidebar.classList.toggle('open');
        }
    }

    // Smooth scroll animations
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    // Observe query items
    document.querySelectorAll('.query-item').forEach(item => {
        item.style.opacity = '0';
        item.style.transform = 'translateY(20px)';
        item.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(item);
    });
    </script>
</body>

</html>