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

// Get filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sort_order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Handle actions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    $product_id = (int)($_POST['product_id'] ?? 0);
    
    if ($action === 'toggle_status' && $product_id > 0) {
        try {
            // Verify product belongs to seller
            $stmt = $pdo->prepare("SELECT published FROM products WHERE id = ? AND user_id = ?");
            $stmt->execute([$product_id, $seller_id]);
            $product = $stmt->fetch();
            
            if ($product) {
                $new_status = $product['published'] == 1 ? 0 : 1;
                $update_stmt = $pdo->prepare("UPDATE products SET published = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                $update_stmt->execute([$new_status, $product_id, $seller_id]);
                
                header('Location: products.php?' . http_build_query($_GET));
                exit;
            }
        } catch (PDOException $e) {
            $error = "Có lỗi xảy ra khi cập nhật trạng thái sản phẩm.";
        }
    }
    
    if ($action === 'delete_product' && $product_id > 0) {
        try {
            // Verify product belongs to seller and delete
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND user_id = ?");
            $stmt->execute([$product_id, $seller_id]);
            
            if ($stmt->rowCount() > 0) {
                header('Location: products.php?' . http_build_query($_GET));
                exit;
            }
        } catch (PDOException $e) {
            $error = "Không thể xóa sản phẩm. Có thể sản phẩm đã có đơn hàng.";
        }
    }
}

try {
    // Build WHERE clause
    $where_conditions = ["p.user_id = ?"];
    $params = [$seller_id];
    
    if (!empty($search)) {
        $where_conditions[] = "(p.name LIKE ? OR p.tags LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if ($category_filter > 0) {
        $where_conditions[] = "p.category_id = ?";
        $params[] = $category_filter;
    }
    
    if ($status_filter !== '') {
        if ($status_filter === 'published') {
            $where_conditions[] = "p.published = 1";
        } elseif ($status_filter === 'unpublished') {
            $where_conditions[] = "p.published = 0";
        } elseif ($status_filter === 'approved') {
            $where_conditions[] = "p.approved = 1";
        } elseif ($status_filter === 'pending') {
            $where_conditions[] = "p.approved = 0";
        } elseif ($status_filter === 'featured') {
            $where_conditions[] = "p.featured = 1";
        } elseif ($status_filter === 'low_stock') {
            $where_conditions[] = "p.current_stock <= COALESCE(p.low_stock_quantity, 5)";
        } elseif ($status_filter === 'out_of_stock') {
            $where_conditions[] = "p.current_stock = 0";
        }
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // Validate sort columns
    $allowed_sorts = ['name', 'unit_price', 'current_stock', 'created_at', 'updated_at', 'num_of_sale'];
    if (!in_array($sort_by, $allowed_sorts)) {
        $sort_by = 'created_at';
    }
    $sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
    
    // Get products
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            c.name as category_name,
            thumb.file_name as thumbnail_file,
            COALESCE(SUM(od.quantity), 0) as total_sold
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN uploads thumb ON p.thumbnail_img = thumb.id AND thumb.deleted_at IS NULL
        LEFT JOIN order_details od ON p.id = od.product_id
        LEFT JOIN orders o ON od.order_id = o.id AND o.payment_status = 'paid'
        $where_clause
        GROUP BY p.id
        ORDER BY p.$sort_by $sort_order
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    
    // Get total count
    $count_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.id) 
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        $where_clause
    ");
    $count_stmt->execute($params);
    $total_products = $count_stmt->fetchColumn();
    $total_pages = ceil($total_products / $limit);
    
    // Get categories for filter
    $categories_stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.name, COUNT(p.id) as product_count
        FROM categories c
        INNER JOIN products p ON c.id = p.category_id
        WHERE p.user_id = ?
        GROUP BY c.id, c.name
        ORDER BY c.name
    ");
    $categories_stmt->execute([$seller_id]);
    $categories = $categories_stmt->fetchAll();
    
    // Get statistics
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN published = 1 THEN 1 ELSE 0 END) as published,
            SUM(CASE WHEN published = 0 THEN 1 ELSE 0 END) as unpublished,
            SUM(CASE WHEN approved = 0 THEN 1 ELSE 0 END) as pending_approval,
            SUM(CASE WHEN current_stock = 0 THEN 1 ELSE 0 END) as out_of_stock,
            SUM(CASE WHEN current_stock <= COALESCE(low_stock_quantity, 5) AND current_stock > 0 THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN featured = 1 THEN 1 ELSE 0 END) as featured
        FROM products 
        WHERE user_id = ?
    ");
    $stats_stmt->execute([$seller_id]);
    $stats = $stats_stmt->fetch();

} catch (PDOException $e) {
    $products = [];
    $total_products = 0;
    $total_pages = 1;
    $categories = [];
    $stats = [
        'total' => 0, 'published' => 0, 'unpublished' => 0, 
        'pending_approval' => 0, 'out_of_stock' => 0, 'low_stock' => 0, 'featured' => 0
    ];
    $error = "Có lỗi xảy ra khi tải dữ liệu.";
}

// Helper functions
function getProductImage($product, $pdo = null) {
    if (!empty($product['thumbnail_file'])) {
        return '' . $product['thumbnail_file'];
    }
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
    return '';
}

function calculateDiscountPrice($original_price, $discount, $discount_type) {
    if ($discount <= 0) return $original_price;
    
    if ($discount_type == 'percent') {
        return $original_price * (1 - $discount / 100);
    } else {
        return max(0, $original_price - $discount);
    }
}

function formatCurrency($amount) {
    return number_format($amount, 0, ',', '.') . 'đ';
}

function getStatusBadge($published, $approved) {
    if (!$approved) {
        return '<span class="status-badge status-pending">Chờ duyệt</span>';
    }
    if ($published) {
        return '<span class="status-badge status-published">Đang bán</span>';
    } else {
        return '<span class="status-badge status-unpublished">Ẩn</span>';
    }
}

function getStockStatus($current_stock, $low_stock_quantity = 5) {
    if ($current_stock == 0) {
        return '<span class="stock-badge stock-out">Hết hàng</span>';
    } elseif ($current_stock <= $low_stock_quantity) {
        return '<span class="stock-badge stock-low">Sắp hết</span>';
    } else {
        return '<span class="stock-badge stock-ok">Còn hàng</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý sản phẩm - TikTok Shop Seller</title>
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

    .stat-card.published .stat-number {
        color: #10b981;
    }

    .stat-card.unpublished .stat-number {
        color: #f59e0b;
    }

    .stat-card.pending .stat-number {
        color: #ef4444;
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

    /* Products Grid/Table */
    .products-section {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    .products-header {
        padding: 20px 24px;
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

    .view-toggle {
        display: flex;
        gap: 4px;
        background: #f3f4f6;
        padding: 4px;
        border-radius: 8px;
    }

    .view-toggle-btn {
        padding: 8px 12px;
        border: none;
        background: transparent;
        cursor: pointer;
        border-radius: 6px;
        color: #6b7280;
        transition: all 0.3s ease;
    }

    .view-toggle-btn.active {
        background: white;
        color: #ff0050;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    /* Table View */
    .products-table {
        width: 100%;
        border-collapse: collapse;
    }

    .products-table th,
    .products-table td {
        padding: 12px 16px;
        text-align: left;
        border-bottom: 1px solid #f3f4f6;
    }

    .products-table th {
        background: #f8f9fa;
        font-weight: 600;
        color: #374151;
        font-size: 14px;
        position: sticky;
        top: 0;
    }

    .products-table tr:hover {
        background: #f9fafb;
    }

    .product-image-cell {
        width: 60px;
    }

    .product-image-small {
        width: 50px;
        height: 50px;
        object-fit: cover;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
    }

    .product-name-cell {
        max-width: 250px;
    }

    .product-name-link {
        color: #1f2937;
        text-decoration: none;
        font-weight: 500;
        display: block;
        margin-bottom: 4px;
    }

    .product-name-link:hover {
        color: #ff0050;
    }

    .product-sku {
        font-size: 12px;
        color: #9ca3af;
    }

    .price-cell {
        text-align: right;
    }

    .price-current {
        font-weight: 600;
        color: #1f2937;
    }

    .price-original {
        font-size: 12px;
        color: #9ca3af;
        text-decoration: line-through;
        display: block;
    }

    .stock-cell {
        text-align: center;
    }

    .status-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
        text-transform: uppercase;
    }

    .status-published {
        background: #dcfce7;
        color: #166534;
    }

    .status-unpublished {
        background: #fef3c7;
        color: #92400e;
    }

    .status-pending {
        background: #fee2e2;
        color: #dc2626;
    }

    .stock-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }

    .stock-ok {
        background: #dcfce7;
        color: #166534;
    }

    .stock-low {
        background: #fef3c7;
        color: #92400e;
    }

    .stock-out {
        background: #fee2e2;
        color: #dc2626;
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

    /* Grid View */
    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        padding: 24px;
    }

    .product-card {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        overflow: hidden;
        transition: all 0.3s ease;
        background: white;
    }

    .product-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .product-card-image {
        width: 100%;
        height: 200px;
        object-fit: cover;
        background: #f3f4f6;
    }

    .product-card-info {
        padding: 16px;
    }

    .product-card-name {
        font-size: 16px;
        font-weight: 500;
        color: #1f2937;
        margin-bottom: 8px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .product-card-price {
        font-size: 18px;
        font-weight: 600;
        color: #ff0050;
        margin-bottom: 8px;
    }

    .product-card-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .product-card-actions {
        display: flex;
        gap: 8px;
    }

    .card-action-btn {
        flex: 1;
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: white;
        color: #4b5563;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .card-action-btn:hover {
        background: #f3f4f6;
    }

    .card-action-btn.primary {
        background: #ff0050;
        color: white;
        border-color: #ff0050;
    }

    .card-action-btn.primary:hover {
        background: #cc0040;
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

    .pagination-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
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

    /* Modal */
    .modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .modal.show {
        opacity: 1;
        visibility: visible;
    }

    .modal-content {
        background: white;
        border-radius: 12px;
        padding: 24px;
        max-width: 500px;
        width: 90%;
        transform: scale(0.9);
        transition: transform 0.3s ease;
    }

    .modal.show .modal-content {
        transform: scale(1);
    }

    .modal-header {
        margin-bottom: 16px;
    }

    .modal-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
    }

    .modal-body {
        margin-bottom: 24px;
        color: #6b7280;
        line-height: 1.5;
    }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .filters-row {
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 16px;
            padding: 16px;
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

        .filters-section {
            padding: 16px;
        }

        .products-header {
            padding: 16px;
            flex-direction: column;
            gap: 12px;
            align-items: stretch;
        }

        .products-table {
            font-size: 12px;
        }

        .products-table th,
        .products-table td {
            padding: 8px 12px;
        }

        .products-grid {
            grid-template-columns: 1fr;
            padding: 16px;
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

        .header-actions {
            flex-direction: column;
            gap: 8px;
            width: 100%;
        }

        .btn {
            width: 100%;
            justify-content: center;
        }
    }

    /* Loading State */
    .loading {
        opacity: 0.6;
        pointer-events: none;
    }

    .loading::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 20px;
        height: 20px;
        margin: -10px 0 0 -10px;
        border: 2px solid #f3f4f6;
        border-top: 2px solid #ff0050;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* Success Message */
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

    .sort-header {
        cursor: pointer;
        user-select: none;
        position: relative;
    }

    .sort-header:hover {
        background: #f3f4f6;
    }

    .sort-header.active::after {
        content: '';
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        width: 0;
        height: 0;
        border-left: 4px solid transparent;
        border-right: 4px solid transparent;
    }

    .sort-header.asc::after {
        border-bottom: 6px solid #ff0050;
    }

    .sort-header.desc::after {
        border-top: 6px solid #ff0050;
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
                        <h1>Quản lý sản phẩm</h1>
                        <div class="breadcrumb">
                            <a href="dashboard.php">Dashboard</a>
                            <span>›</span>
                            <span>Sản phẩm</span>
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="add-product.php" class="btn btn-primary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                        </svg>
                        Thêm sản phẩm
                    </a>
                    <a href="product-list.php" class="btn btn-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z" />
                        </svg>
                        Chọn sản phẩm có sẵn
                    </a>
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
                <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card total">
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Tổng sản phẩm</div>
                    </div>
                    <div class="stat-card published">
                        <div class="stat-number"><?php echo $stats['published']; ?></div>
                        <div class="stat-label">Đang bán</div>
                    </div>
                    <div class="stat-card unpublished">
                        <div class="stat-number"><?php echo $stats['unpublished']; ?></div>
                        <div class="stat-label">Ẩn</div>
                    </div>
                    <div class="stat-card pending">
                        <div class="stat-number"><?php echo $stats['pending_approval']; ?></div>
                        <div class="stat-label">Chờ duyệt</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['featured']; ?></div>
                        <div class="stat-label">Nổi bật</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['out_of_stock']; ?></div>
                        <div class="stat-label">Hết hàng</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-section">
                    <form method="GET" id="filterForm">
                        <div class="filters-row">
                            <div class="filter-group">
                                <label class="filter-label">Tìm kiếm</label>
                                <input type="text" name="search" class="filter-input"
                                    placeholder="Tên sản phẩm, SKU, tags..."
                                    value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Danh mục</label>
                                <select name="category" class="filter-select">
                                    <option value="">Tất cả danh mục</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>"
                                        <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                        (<?php echo $category['product_count']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Trạng thái</label>
                                <select name="status" class="filter-select">
                                    <option value="">Tất cả</option>
                                    <option value="published"
                                        <?php echo $status_filter == 'published' ? 'selected' : ''; ?>>Đang bán</option>
                                    <option value="unpublished"
                                        <?php echo $status_filter == 'unpublished' ? 'selected' : ''; ?>>Ẩn</option>
                                    <option value="pending"
                                        <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Chờ duyệt</option>
                                    <option value="featured"
                                        <?php echo $status_filter == 'featured' ? 'selected' : ''; ?>>Nổi bật</option>
                                    <option value="low_stock"
                                        <?php echo $status_filter == 'low_stock' ? 'selected' : ''; ?>>Sắp hết hàng
                                    </option>
                                    <option value="out_of_stock"
                                        <?php echo $status_filter == 'out_of_stock' ? 'selected' : ''; ?>>Hết hàng
                                    </option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Sắp xếp</label>
                                <select name="sort" class="filter-select"
                                    onchange="document.getElementById('filterForm').submit()">
                                    <option value="created_at"
                                        <?php echo $sort_by == 'created_at' ? 'selected' : ''; ?>>Ngày tạo</option>
                                    <option value="updated_at"
                                        <?php echo $sort_by == 'updated_at' ? 'selected' : ''; ?>>Cập nhật</option>
                                    <option value="name" <?php echo $sort_by == 'name' ? 'selected' : ''; ?>>Tên A-Z
                                    </option>
                                    <option value="unit_price"
                                        <?php echo $sort_by == 'unit_price' ? 'selected' : ''; ?>>Giá</option>
                                    <option value="current_stock"
                                        <?php echo $sort_by == 'current_stock' ? 'selected' : ''; ?>>Tồn kho</option>
                                    <option value="num_of_sale"
                                        <?php echo $sort_by == 'num_of_sale' ? 'selected' : ''; ?>>Đã bán</option>
                                </select>
                                <input type="hidden" name="order" value="<?php echo $sort_order; ?>">
                            </div>
                            <div class="filter-group">
                                <button type="submit" class="btn btn-primary">Lọc</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Products Section -->
                <div class="products-section">
                    <div class="products-header">
                        <div class="products-title">
                            Danh sách sản phẩm
                            <span style="color: #6b7280; font-weight: normal;">
                                (<?php echo $total_products; ?> sản phẩm)
                            </span>
                        </div>
                        <div class="view-toggle">
                            <button class="view-toggle-btn active" onclick="switchView('table')">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M3 3h18v2H3V3zm0 4h18v2H3V7zm0 4h18v2H3v-2zm0 4h18v2H3v-2z" />
                                </svg>
                                Bảng
                            </button>
                            <button class="view-toggle-btn" onclick="switchView('grid')">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M3 3h8v8H3V3zm10 0h8v8h-8V3zM3 13h8v8H3v-8zm10 0h8v8h-8v-8z" />
                                </svg>
                                Lưới
                            </button>
                        </div>
                    </div>

                    <?php if (empty($products)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📦</div>
                        <h3>Chưa có sản phẩm nào</h3>
                        <p>Hãy thêm sản phẩm đầu tiên để bắt đầu bán hàng trên TikTok Shop</p>
                        <div style="display: flex; gap: 12px; justify-content: center; margin-top: 16px;">
                            <a href="add-product.php" class="btn btn-primary">Thêm sản phẩm mới</a>
                            <a href="product-list.php" class="btn btn-secondary">Chọn sản phẩm có sẵn</a>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Table View -->
                    <div id="tableView">
                        <table class="products-table">
                            <thead>
                                <tr>
                                    <th>Ảnh</th>
                                    <th class="sort-header <?php echo $sort_by == 'name' ? strtolower($sort_order) : ''; ?>"
                                        onclick="sortBy('name')">Tên sản phẩm</th>
                                    <th>Danh mục</th>
                                    <th class="sort-header <?php echo $sort_by == 'unit_price' ? strtolower($sort_order) : ''; ?>"
                                        onclick="sortBy('unit_price')">Giá</th>
                                    <th class="sort-header <?php echo $sort_by == 'current_stock' ? strtolower($sort_order) : ''; ?>"
                                        onclick="sortBy('current_stock')">Kho</th>
                                    <th>Trạng thái</th>
                                    <th class="sort-header <?php echo $sort_by == 'created_at' ? strtolower($sort_order) : ''; ?>"
                                        onclick="sortBy('created_at')">Ngày tạo</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $product): 
                                        $product_image = getProductImage($product, $pdo);
                                        $display_price = calculateDiscountPrice($product['unit_price'], $product['discount'], $product['discount_type']);
                                    ?>
                                <tr>
                                    <td class="product-image-cell">
                                        <?php if ($product_image): ?>
                                        <img src="../<?php echo htmlspecialchars($product_image); ?>"
                                            alt="<?php echo htmlspecialchars($product['name']); ?>"
                                            class="product-image-small"
                                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNTAiIGhlaWdodD0iNTAiIHZpZXdCb3g9IjAgMCA1MCA1MCIgZmlsbD0ibm9uZSI+PHJlY3Qgd2lkdGg9IjUwIiBoZWlnaHQ9IjUwIiBmaWxsPSIjZjNmNGY2Ii8+PHBhdGggZD0iTTIwIDIwaDEwdjEwSDIweiIgZmlsbD0iI2Q5ZGNlMCIvPjwvc3ZnPg=='">
                                        <?php else: ?>
                                        <div class="product-image-small"
                                            style="display: flex; align-items: center; justify-content: center; background: #f3f4f6; color: #9ca3af;">
                                            📦
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="product-name-cell">
                                        <a href="edit-product.php?id=<?php echo $product['id']; ?>"
                                            class="product-name-link">
                                            <?php echo htmlspecialchars($product['name']); ?>
                                        </a>
                                        <div class="product-sku">ID: #<?php echo $product['id']; ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['category_name'] ?: 'Chưa phân loại'); ?>
                                    </td>
                                    <td class="price-cell">
                                        <div class="price-current"><?php echo formatCurrency($display_price); ?></div>
                                        <?php if ($product['discount'] > 0): ?>
                                        <div class="price-original">
                                            <?php echo formatCurrency($product['unit_price']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="stock-cell">
                                        <div><?php echo $product['current_stock']; ?></div>
                                        <?php echo getStockStatus($product['current_stock'], $product['low_stock_quantity']); ?>
                                    </td>
                                    <td>
                                        <?php echo getStatusBadge($product['published'], $product['approved']); ?>
                                        <?php if ($product['featured']): ?>
                                        <span class="status-badge"
                                            style="background: #fef3c7; color: #92400e; margin-left: 4px;">Nổi
                                            bật</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo date('d/m/Y', strtotime($product['created_at'])); ?></div>
                                        <div style="font-size: 12px; color: #9ca3af;">
                                            <?php echo date('H:i', strtotime($product['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="actions-cell">
                                        <div class="action-dropdown">
                                            <button class="action-btn" onclick="toggleActionMenu(this)">
                                                ⋯
                                            </button>
                                            <div class="action-menu">
                                                <a href="edit-product.php?id=<?php echo $product['id']; ?>"
                                                    class="action-menu-item">
                                                    ✏️ Chỉnh sửa
                                                </a>
                                                <button class="action-menu-item"
                                                    onclick="toggleProductStatus(<?php echo $product['id']; ?>)">
                                                    <?php echo $product['published'] ? '👁️ Ẩn sản phẩm' : '👁️ Hiện sản phẩm'; ?>
                                                </button>
                                                <a href="view-product.php?id=<?php echo $product['id']; ?>"
                                                    class="action-menu-item" target="_blank">
                                                    🔍 Xem trước
                                                </a>
                                                <button class="action-menu-item danger"
                                                    onclick="deleteProduct(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name']); ?>')">
                                                    🗑️ Xóa
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Grid View (Hidden by default) -->
                    <div id="gridView" style="display: none;">
                        <div class="products-grid">
                            <?php foreach ($products as $product): 
                                    $product_image = getProductImage($product, $pdo);
                                    $display_price = calculateDiscountPrice($product['unit_price'], $product['discount'], $product['discount_type']);
                                ?>
                            <div class="product-card">
                                <?php if ($product_image): ?>
                                <img src="../<?php echo htmlspecialchars($product_image); ?>"
                                    alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-card-image"
                                    onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjgwIiBoZWlnaHQ9IjIwMCIgdmlld0JveD0iMCAwIDI4MCAyMDAiIGZpbGw9Im5vbmUiPjxyZWN0IHdpZHRoPSIyODAiIGhlaWdodD0iMjAwIiBmaWxsPSIjZjNmNGY2Ii8+PHBhdGggZD0iTTEyMCA4MGg0MHY0MGgtNDB6IiBmaWxsPSIjZDlkY2UwIi8+PC9zdmc+'">
                                <?php else: ?>
                                <div class="product-card-image"
                                    style="display: flex; align-items: center; justify-content: center; background: #f3f4f6; color: #9ca3af; font-size: 48px;">
                                    📦
                                </div>
                                <?php endif; ?>

                                <div class="product-card-info">
                                    <div class="product-card-name"><?php echo htmlspecialchars($product['name']); ?>
                                    </div>
                                    <div class="product-card-price"><?php echo formatCurrency($display_price); ?></div>

                                    <div class="product-card-meta">
                                        <?php echo getStatusBadge($product['published'], $product['approved']); ?>
                                        <?php echo getStockStatus($product['current_stock'], $product['low_stock_quantity']); ?>
                                    </div>

                                    <div class="product-card-actions">
                                        <a href="edit-product.php?id=<?php echo $product['id']; ?>"
                                            class="card-action-btn primary">
                                            Chỉnh sửa
                                        </a>
                                        <button class="card-action-btn"
                                            onclick="toggleProductStatus(<?php echo $product['id']; ?>)">
                                            <?php echo $product['published'] ? 'Ẩn' : 'Hiện'; ?>
                                        </button>
                                        <button class="card-action-btn"
                                            onclick="deleteProduct(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name']); ?>')">
                                            Xóa
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
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
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Xác nhận xóa sản phẩm</h3>
            </div>
            <div class="modal-body">
                <p>Bạn có chắc chắn muốn xóa sản phẩm "<span id="deleteProductName"></span>"?</p>
                <p style="color: #dc2626; font-size: 14px; margin-top: 8px;">
                    ⚠️ Hành động này không thể hoàn tác!
                </p>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeModal()">Hủy</button>
                <button class="btn btn-primary" id="confirmDeleteBtn" style="background: #dc2626;">Xóa</button>
            </div>
        </div>
    </div>

    <!-- Hidden Forms -->
    <form id="statusForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="product_id" id="statusProductId">
    </form>

    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete_product">
        <input type="hidden" name="product_id" id="deleteProductId">
    </form>

    <script>
    // View switching
    function switchView(view) {
        const tableView = document.getElementById('tableView');
        const gridView = document.getElementById('gridView');
        const buttons = document.querySelectorAll('.view-toggle-btn');

        buttons.forEach(btn => btn.classList.remove('active'));

        if (view === 'table') {
            tableView.style.display = 'block';
            gridView.style.display = 'none';
            buttons[0].classList.add('active');
        } else {
            tableView.style.display = 'none';
            gridView.style.display = 'block';
            buttons[1].classList.add('active');
        }

        localStorage.setItem('productsView', view);
    }

    // Restore view preference
    document.addEventListener('DOMContentLoaded', function() {
        const savedView = localStorage.getItem('productsView');
        if (savedView) {
            switchView(savedView);
        }
    });

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

    // Sorting
    function sortBy(column) {
        const form = document.getElementById('filterForm');
        const sortInput = form.querySelector('select[name="sort"]');
        const orderInput = form.querySelector('input[name="order"]');

        if (sortInput.value === column) {
            orderInput.value = orderInput.value === 'ASC' ? 'DESC' : 'ASC';
        } else {
            sortInput.value = column;
            orderInput.value = 'DESC';
        }

        form.submit();
    }

    // Toggle product status
    function toggleProductStatus(productId) {
        if (confirm('Bạn có muốn thay đổi trạng thái hiển thị của sản phẩm này?')) {
            document.getElementById('statusProductId').value = productId;
            document.getElementById('statusForm').submit();
        }
    }

    // Delete product
    function deleteProduct(productId, productName) {
        document.getElementById('deleteProductName').textContent = productName;
        document.getElementById('deleteProductId').value = productId;
        document.getElementById('deleteModal').classList.add('show');

        document.getElementById('confirmDeleteBtn').onclick = function() {
            document.getElementById('deleteForm').submit();
        };
    }

    // Close modal
    function closeModal() {
        document.getElementById('deleteModal').classList.remove('show');
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

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey || e.metaKey) {
            if (e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
            if (e.key === 'n') {
                e.preventDefault();
                window.location.href = 'add-product.php';
            }
        }
        if (e.key === 'Escape') {
            closeModal();
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