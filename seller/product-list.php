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
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sort_order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Handle add product action
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'add_product') {
    $original_product_id = (int)($_POST['product_id'] ?? 0);
    $commission_percent = (float)($_POST['commission_percent'] ?? 0);
    
    if ($original_product_id > 0 && $commission_percent >= 0 && $commission_percent <= 200) {
        try {
            // Get original product details
            $stmt = $pdo->prepare("
                SELECT * FROM products 
                WHERE id = ? AND user_id != ? AND published = 1 AND approved = 1
            ");
            $stmt->execute([$original_product_id, $seller_id]);
            $original_product = $stmt->fetch();
            
            if ($original_product) {
                // Check if seller already has this product
                $check_stmt = $pdo->prepare("
                    SELECT id FROM products 
                    WHERE user_id = ? AND name = ? AND added_by = 'reseller'
                ");
                $check_stmt->execute([$seller_id, $original_product['name']]);
                
                if (!$check_stmt->fetch()) {
                    // Calculate new price
                    $original_price = $original_product['unit_price'];
                    $new_price = $original_price * (1 + $commission_percent / 100);
                    
                    // Create new product entry
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO products (
                            name, added_by, user_id, category_id, brand_id, photos, thumbnail_img,
                            video_provider, video_link, tags, description, unit_price, share_price,
                            purchase_price, variant_product, attributes, choice_options, colors,
                            variations, published, approved, stock_visibility_state, cash_on_delivery,
                            current_stock, unit, weight, min_qty, low_stock_quantity, discount,
                            discount_type, tax, tax_type, shipping_type, shipping_cost,
                            is_quantity_multiplied, est_shipping_days, meta_title, meta_description,
                            meta_img, pdf, slug, earn_point, refundable, digital, file_name,
                            file_path, external_link, external_link_btn, wholesale_product,
                            frequently_bought_selection_type, created_at, updated_at
                        ) VALUES (
                            ?, 'reseller', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            0, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                        )
                    ");
                    
                    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $original_product['name']))) . '-' . $seller_id . '-' . time();
                    
                    $insert_stmt->execute([
                        $original_product['name'],
                        $seller_id,
                        $original_product['category_id'],
                        $original_product['brand_id'],
                        $original_product['photos'],
                        $original_product['thumbnail_img'],
                        $original_product['video_provider'],
                        $original_product['video_link'],
                        $original_product['tags'],
                        $original_product['description'],
                        $new_price,
                        $commission_percent, // Store commission % in share_price field
                        $original_price,
                        $original_product['variant_product'],
                        $original_product['attributes'],
                        $original_product['choice_options'],
                        $original_product['colors'],
                        $original_product['variations'],
                        $original_product['stock_visibility_state'],
                        $original_product['cash_on_delivery'],
                        $original_product['current_stock'],
                        $original_product['unit'],
                        $original_product['weight'],
                        $original_product['min_qty'],
                        $original_product['low_stock_quantity'],
                        $original_product['discount'],
                        $original_product['discount_type'],
                        $original_product['tax'],
                        $original_product['tax_type'],
                        $original_product['shipping_type'],
                        $original_product['shipping_cost'],
                        $original_product['is_quantity_multiplied'],
                        $original_product['est_shipping_days'],
                        $original_product['meta_title'],
                        $original_product['meta_description'],
                        $original_product['meta_img'],
                        $original_product['pdf'],
                        $slug,
                        $original_product['earn_point'],
                        $original_product['refundable'],
                        $original_product['digital'],
                        $original_product['file_name'],
                        $original_product['file_path'],
                        $original_product['external_link'],
                        $original_product['external_link_btn'],
                        $original_product['wholesale_product'],
                        $original_product['frequently_bought_selection_type']
                    ]);
                    
                    $success = "Đã thêm sản phẩm vào shop của bạn thành công!";
                } else {
                    $error = "Bạn đã có sản phẩm này trong shop rồi!";
                }
            } else {
                $error = "Không tìm thấy sản phẩm hoặc sản phẩm không khả dụng!";
            }
        } catch (PDOException $e) {
            $error = "Có lỗi xảy ra khi thêm sản phẩm: " . $e->getMessage();
        }
    } else {
        $error = "Thông tin không hợp lệ!";
    }
}

try {
    // Build WHERE clause - exclude own products and only show published/approved products
    $where_conditions = ["p.user_id != ?", "p.published = 1", "p.approved = 1"];
    $params = [$seller_id];
    
    if (!empty($search)) {
        $where_conditions[] = "(p.name LIKE ? OR p.tags LIKE ? OR u.name LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if ($category_filter > 0) {
        $where_conditions[] = "p.category_id = ?";
        $params[] = $category_filter;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // Validate sort columns
    $allowed_sorts = ['name', 'unit_price', 'current_stock', 'created_at', 'num_of_sale'];
    if (!in_array($sort_by, $allowed_sorts)) {
        $sort_by = 'created_at';
    }
    $sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
    
    // Get products
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            c.name as category_name,
            u.name as seller_name,
            thumb.file_name as thumbnail_file,
            COALESCE(SUM(od.quantity), 0) as total_sold
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN users u ON p.user_id = u.id
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
        LEFT JOIN users u ON p.user_id = u.id
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
        WHERE p.user_id != ? AND p.published = 1 AND p.approved = 1
        GROUP BY c.id, c.name
        ORDER BY c.name
    ");
    $categories_stmt->execute([$seller_id]);
    $categories = $categories_stmt->fetchAll();

} catch (PDOException $e) {
    $products = [];
    $total_products = 0;
    $total_pages = 1;
    $categories = [];
    $error = "Có lỗi xảy ra khi tải dữ liệu.";
}

// Helper functions
function getProductImage($product, $pdo = null) {
    if (!empty($product['thumbnail_file'])) {
        return '../' . $product['thumbnail_file'];
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
                    return '../' . $img_result['file_name'];
                }
            } catch (PDOException $e) {
                // Ignore error
            }
        }
    }
    return '';
}

function formatCurrency($amount) {
    return number_format($amount, 0, ',', '.') . 'đ';
}

function calculateNewPrice($original_price, $commission_percent) {
    return $original_price * (1 + $commission_percent / 100);
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chọn sản phẩm có sẵn - TikTok Shop Seller</title>
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

    .btn-success {
        background: #10b981;
        color: white;
    }

    .btn-success:hover {
        background: #059669;
    }

    .content-wrapper {
        padding: 24px;
    }

    /* Info Banner */
    .info-banner {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 24px;
    }

    .info-banner h3 {
        font-size: 18px;
        margin-bottom: 8px;
    }

    .info-banner p {
        opacity: 0.9;
        line-height: 1.5;
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

    /* Products Section */
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

    /* Products Grid */
    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
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
        line-height: 1.4;
    }

    .product-card-seller {
        font-size: 12px;
        color: #6b7280;
        margin-bottom: 8px;
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
        font-size: 12px;
        color: #6b7280;
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
        text-align: center;
        text-decoration: none;
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

    .stock-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
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

    .price-preview {
        background: #f8fafc;
        padding: 16px;
        border-radius: 8px;
        margin: 16px 0;
    }

    .price-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
    }

    .price-row.total {
        font-weight: 600;
        color: #1f2937;
        border-top: 1px solid #e5e7eb;
        padding-top: 8px;
        margin-top: 8px;
    }

    .commission-input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        margin-bottom: 16px;
    }

    .commission-input:focus {
        outline: none;
        border-color: #ff0050;
    }

    .commission-suggestions {
        display: flex;
        gap: 8px;
        margin-bottom: 16px;
    }

    .suggestion-btn {
        padding: 4px 8px;
        border: 1px solid #d1d5db;
        border-radius: 4px;
        background: white;
        color: #4b5563;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .suggestion-btn:hover {
        background: #ff0050;
        color: white;
        border-color: #ff0050;
    }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
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

        .filters-section {
            padding: 16px;
        }

        .products-header {
            padding: 16px;
        }

        .products-grid {
            grid-template-columns: 1fr;
            padding: 16px;
        }
    }

    @media (max-width: 480px) {
        .header-actions {
            flex-direction: column;
            gap: 8px;
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
                        <h1>Chọn sản phẩm có sẵn</h1>
                        <div class="breadcrumb">
                            <a href="dashboard.php">Dashboard</a>
                            <span>›</span>
                            <a href="products.php">Sản phẩm</a>
                            <span>›</span>
                            <span>Chọn sản phẩm</span>
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="products.php" class="btn btn-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" />
                        </svg>
                        Quay lại
                    </a>
                </div>
            </div>

            <div class="content-wrapper">
                <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Info Banner -->
                <div class="info-banner">
                    <h3>🛍️ Bán sản phẩm của seller khác</h3>
                    <p>Chọn sản phẩm từ các seller khác để bán trong shop của bạn. Bạn có thể đặt mức hoa hồng để bán
                        với giá cao hơn và kiếm lời từ việc bán lại.</p>
                </div>

                <!-- Filters -->
                <div class="filters-section">
                    <form method="GET" id="filterForm">
                        <div class="filters-row">
                            <div class="filter-group">
                                <label class="filter-label">Tìm kiếm</label>
                                <input type="text" name="search" class="filter-input"
                                    placeholder="Tên sản phẩm, tên seller..."
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
                                <label class="filter-label">Sắp xếp</label>
                                <select name="sort" class="filter-select"
                                    onchange="document.getElementById('filterForm').submit()">
                                    <option value="created_at"
                                        <?php echo $sort_by == 'created_at' ? 'selected' : ''; ?>>Mới nhất</option>
                                    <option value="name" <?php echo $sort_by == 'name' ? 'selected' : ''; ?>>Tên A-Z
                                    </option>
                                    <option value="unit_price"
                                        <?php echo $sort_by == 'unit_price' ? 'selected' : ''; ?>>Giá thấp</option>
                                    <option value="num_of_sale"
                                        <?php echo $sort_by == 'num_of_sale' ? 'selected' : ''; ?>>Bán chạy</option>
                                </select>
                                <input type="hidden" name="order" value="<?php echo $sort_order; ?>">
                            </div>
                            <div class="filter-group">
                                <button type="submit" class="btn btn-primary">Tìm kiếm</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Products Section -->
                <div class="products-section">
                    <div class="products-header">
                        <div class="products-title">
                            Sản phẩm có sẵn
                            <span style="color: #6b7280; font-weight: normal;">
                                (<?php echo $total_products; ?> sản phẩm)
                            </span>
                        </div>
                    </div>

                    <?php if (empty($products)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🔍</div>
                        <h3>Không tìm thấy sản phẩm nào</h3>
                        <p>Thử thay đổi bộ lọc hoặc từ khóa tìm kiếm</p>
                    </div>
                    <?php else: ?>
                    <div class="products-grid">
                        <?php foreach ($products as $product): 
                                $product_image = getProductImage($product, $pdo);
                            ?>
                        <div class="product-card">
                            <?php if ($product_image): ?>
                            <img src="<?php echo htmlspecialchars($product_image); ?>"
                                alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-card-image"
                                onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzAwIiBoZWlnaHQ9IjIwMCIgdmlld0JveD0iMCAwIDMwMCAyMDAiIGZpbGw9Im5vbmUiPjxyZWN0IHdpZHRoPSIzMDAiIGhlaWdodD0iMjAwIiBmaWxsPSIjZjNmNGY2Ii8+PHBhdGggZD0iTTEzMCA4MGg0MHY0MGgtNDB6IiBmaWxsPSIjZDlkY2UwIi8+PC9zdmc+'">
                            <?php else: ?>
                            <div class="product-card-image"
                                style="display: flex; align-items: center; justify-content: center; background: #f3f4f6; color: #9ca3af; font-size: 48px;">
                                📦
                            </div>
                            <?php endif; ?>

                            <div class="product-card-info">
                                <div class="product-card-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                <div class="product-card-seller">Bởi:
                                    <?php echo htmlspecialchars($product['seller_name']); ?></div>
                                <div class="product-card-price"><?php echo formatCurrency($product['unit_price']); ?>
                                </div>

                                <div class="product-card-meta">
                                    <span>Kho: <?php echo $product['current_stock']; ?></span>
                                    <span>Đã bán: <?php echo $product['total_sold']; ?></span>
                                    <?php if ($product['current_stock'] > 0): ?>
                                    <span class="stock-badge stock-ok">Còn hàng</span>
                                    <?php else: ?>
                                    <span class="stock-badge stock-out">Hết hàng</span>
                                    <?php endif; ?>
                                </div>

                                <div class="product-card-actions">
                                    <?php if ($product['current_stock'] > 0): ?>
                                    <button class="card-action-btn primary"
                                        onclick="selectProduct(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name']); ?>', <?php echo $product['unit_price']; ?>)">
                                        Chọn bán
                                    </button>
                                    <?php else: ?>
                                    <button class="card-action-btn" disabled>Hết hàng</button>
                                    <?php endif; ?>
                                    <a href="#" class="card-action-btn"
                                        onclick="previewProduct(<?php echo $product['id']; ?>)">
                                        Xem chi tiết
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
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

    <!-- Commission Selection Modal -->
    <div id="commissionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Chọn mức hoa hồng</h3>
            </div>
            <div class="modal-body">
                <p>Đặt mức hoa hồng bạn muốn kiếm từ sản phẩm: <strong id="selectedProductName"></strong></p>

                <div style="margin: 16px 0;">
                    <label for="commissionPercent" style="display: block; margin-bottom: 8px; font-weight: 500;">
                        Hoa hồng (%):
                    </label>
                    <input type="number" id="commissionPercent" class="commission-input" min="0" max="200" step="0.1"
                        value="10" placeholder="Nhập % hoa hồng (0-200%)" oninput="updatePricePreview()">
                </div>

                <div class="commission-suggestions">
                    <span style="font-size: 12px; color: #6b7280; margin-right: 8px;">Gợi ý:</span>
                    <button type="button" class="suggestion-btn" onclick="setCommission(5)">5%</button>
                    <button type="button" class="suggestion-btn" onclick="setCommission(10)">10%</button>
                    <button type="button" class="suggestion-btn" onclick="setCommission(15)">15%</button>
                    <button type="button" class="suggestion-btn" onclick="setCommission(20)">20%</button>
                    <button type="button" class="suggestion-btn" onclick="setCommission(30)">30%</button>
                </div>

                <div class="price-preview">
                    <div class="price-row">
                        <span>Giá gốc:</span>
                        <span id="originalPrice">0đ</span>
                    </div>
                    <div class="price-row">
                        <span>Hoa hồng (<span id="commissionDisplay">10</span>%):</span>
                        <span id="commissionAmount">0đ</span>
                    </div>
                    <div class="price-row total">
                        <span>Giá bán của bạn:</span>
                        <span id="finalPrice">0đ</span>
                    </div>
                </div>

                <div style="font-size: 12px; color: #6b7280; margin-top: 12px;">
                    💡 Mức hoa hồng hợp lý sẽ giúp sản phẩm của bạn cạnh tranh tốt hơn trên thị trường.
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeCommissionModal()">Hủy</button>
                <button class="btn btn-success" id="confirmAddBtn" onclick="confirmAddProduct()">
                    Thêm vào shop
                </button>
            </div>
        </div>
    </div>

    <!-- Hidden Form -->
    <form id="addProductForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="add_product">
        <input type="hidden" name="product_id" id="selectedProductId">
        <input type="hidden" name="commission_percent" id="selectedCommissionPercent">
    </form>

    <script>
    let selectedProductData = {};

    // Select product for selling
    function selectProduct(productId, productName, originalPrice) {
        selectedProductData = {
            id: productId,
            name: productName,
            price: originalPrice
        };

        document.getElementById('selectedProductName').textContent = productName;
        document.getElementById('selectedProductId').value = productId;
        document.getElementById('originalPrice').textContent = formatCurrency(originalPrice);

        // Reset commission to 10%
        document.getElementById('commissionPercent').value = 10;
        updatePricePreview();

        document.getElementById('commissionModal').classList.add('show');
    }

    // Set commission percentage
    function setCommission(percent) {
        document.getElementById('commissionPercent').value = percent;
        updatePricePreview();
    }

    // Update price preview
    function updatePricePreview() {
        const commission = parseFloat(document.getElementById('commissionPercent').value) || 0;
        const originalPrice = selectedProductData.price || 0;
        const commissionAmount = originalPrice * (commission / 100);
        const finalPrice = originalPrice + commissionAmount;

        document.getElementById('commissionDisplay').textContent = commission;
        document.getElementById('commissionAmount').textContent = formatCurrency(commissionAmount);
        document.getElementById('finalPrice').textContent = formatCurrency(finalPrice);
        document.getElementById('selectedCommissionPercent').value = commission;
    }

    // Confirm add product
    function confirmAddProduct() {
        const commission = parseFloat(document.getElementById('commissionPercent').value);

        if (commission < 0 || commission > 200) {
            alert('Mức hoa hồng phải từ 0% đến 200%');
            return;
        }

        if (confirm(`Bạn có muốn thêm sản phẩm "${selectedProductData.name}" vào shop với hoa hồng ${commission}%?`)) {
            document.getElementById('addProductForm').submit();
        }
    }

    // Close commission modal
    function closeCommissionModal() {
        document.getElementById('commissionModal').classList.remove('show');
    }

    // Preview product (placeholder)
    function previewProduct(productId) {
        // You can implement product preview functionality here
        alert('Tính năng xem chi tiết sẽ được phát triển trong phiên bản tiếp theo');
    }

    // Format currency
    function formatCurrency(amount) {
        return new Intl.NumberFormat('vi-VN').format(amount) + 'đ';
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

    // Close modal when clicking outside
    document.getElementById('commissionModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeCommissionModal();
        }
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeCommissionModal();
        }
        if (e.ctrlKey || e.metaKey) {
            if (e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
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