<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Check if config file exists
if (!file_exists('config.php')) {
    die('Error: config.php file not found!');
}

if (!file_exists('constants.php')) {
    die('Error: constants.php file not found!');
}

require_once 'config.php';
require_once 'constants.php';

// Check if PDO connection exists
if (!isset($pdo)) {
    die('Error: Database connection not found in config.php!');
}

// Set page-specific variables
$current_page = 'products';
$require_login = false; // Allow guests to view products

// Get user info if logged in
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : null;
$user_type = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : null;

// Pagination parameters
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : ITEMS_PER_PAGE;
$limit = in_array($limit, [12, 24, 48]) ? $limit : ITEMS_PER_PAGE; // Validate limit
$offset = ($page - 1) * $limit;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_id = isset($_GET['category_id']) ? (int) $_GET['category_id'] : '';
$brand_id = isset($_GET['brand_id']) ? (int) $_GET['brand_id'] : '';
$min_price = isset($_GET['min_price']) ? (float) $_GET['min_price'] : '';
$max_price = isset($_GET['max_price']) ? (float) $_GET['max_price'] : '';
$featured = isset($_GET['featured']) ? 1 : '';
$on_sale = isset($_GET['on_sale']) ? 1 : '';
$in_stock = isset($_GET['in_stock']) ? 1 : '';

// Sort parameters
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$valid_sorts = ['newest', 'oldest', 'price_low', 'price_high', 'name_asc', 'name_desc', 'rating_high', 'popular'];
$sort = in_array($sort, $valid_sorts) ? $sort : 'newest';

// View type
$view = isset($_GET['view']) ? $_GET['view'] : 'grid';
$view = in_array($view, ['grid', 'list']) ? $view : 'grid';

// Build WHERE clause
$where_conditions = ["p.published = 1", "p.approved = 1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.name LIKE :search OR p.description LIKE :search OR p.tags LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($category_id !== '') {
    $where_conditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $category_id;
}

if ($brand_id !== '') {
    $where_conditions[] = "p.brand_id = :brand_id";
    $params[':brand_id'] = $brand_id;
}

if ($min_price !== '') {
    $where_conditions[] = "p.unit_price >= :min_price";
    $params[':min_price'] = $min_price;
}

if ($max_price !== '') {
    $where_conditions[] = "p.unit_price <= :max_price";
    $params[':max_price'] = $max_price;
}

if ($featured !== '') {
    $where_conditions[] = "p.featured = 1";
}

if ($on_sale !== '') {
    $where_conditions[] = "p.discount > 0";
}

if ($in_stock !== '') {
    $where_conditions[] = "p.current_stock > 0";
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Build ORDER BY clause
$order_by = match ($sort) {
    'newest' => 'p.created_at DESC',
    'oldest' => 'p.created_at ASC',
    'price_low' => 'p.unit_price ASC',
    'price_high' => 'p.unit_price DESC',
    'name_asc' => 'p.name ASC',
    'name_desc' => 'p.name DESC',
    'rating_high' => 'p.rating DESC, p.created_at DESC',
    'popular' => 'p.num_of_sale DESC, p.created_at DESC',
    default => 'p.created_at DESC'
};

// Get products with pagination
try {
    $stmt = $pdo->prepare("
        SELECT p.*, 
               u.name as seller_name,
               c.name as category_name,
               b.name as brand_name,
               thumb.file_name as thumbnail_file,
               (CASE 
                   WHEN p.discount_type = 'percent' THEN p.unit_price * (1 - p.discount/100)
                   WHEN p.discount_type = 'amount' THEN p.unit_price - p.discount
                   ELSE p.unit_price 
               END) as final_price
        FROM products p 
        LEFT JOIN users u ON p.user_id = u.id 
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN uploads thumb ON p.thumbnail_img = thumb.id AND thumb.deleted_at IS NULL
        $where_clause
        ORDER BY $order_by
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll();

    // Get total count for pagination
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        $where_clause
    ");
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_products = $count_stmt->fetchColumn();
    $total_pages = ceil($total_products / $limit);
} catch (PDOException $e) {
    error_log("Database Error in products.php: " . $e->getMessage());
    $products = [];
    $total_products = 0;
    $total_pages = 1;
}

// Get categories for filter
try {
    $stmt = $pdo->prepare("
        SELECT c.*, COUNT(p.id) as product_count 
        FROM categories c 
        LEFT JOIN products p ON c.id = p.category_id AND p.published = 1 AND p.approved = 1
        GROUP BY c.id 
        HAVING product_count > 0 
        ORDER BY c.name ASC
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    $categories = [];
}

// Get brands for filter
try {
    $stmt = $pdo->prepare("
        SELECT b.*, COUNT(p.id) as product_count 
        FROM brands b 
        LEFT JOIN products p ON b.id = p.brand_id AND p.published = 1 AND p.approved = 1
        GROUP BY b.id 
        HAVING product_count > 0 
        ORDER BY b.name ASC
    ");
    $stmt->execute();
    $brands = $stmt->fetchAll();
} catch (PDOException $e) {
    $brands = [];
}

// Get price range
try {
    $stmt = $pdo->prepare("
        SELECT MIN(unit_price) as min_price, MAX(unit_price) as max_price 
        FROM products 
        WHERE published = 1 AND approved = 1
    ");
    $stmt->execute();
    $price_range = $stmt->fetch();

    // Ensure we have valid price range
    if (!$price_range || !$price_range['min_price']) {
        $price_range = ['min_price' => 0, 'max_price' => 10000000];
    }
} catch (PDOException $e) {
    $price_range = ['min_price' => 0, 'max_price' => 10000000];
}

// Function to get product image
function getProductImage($product, $pdo)
{
    // Priority: thumbnail from JOIN
    if (!empty($product['thumbnail_file'])) {
        return $product['thumbnail_file'];
    }
    // Backup: get first image from photos JSON
    elseif (!empty($product['photos'])) {
        $photos_json = json_decode($product['photos'], true);
        if (is_array($photos_json) && !empty($photos_json)) {
            try {
                $first_photo_id = $photos_json[0];
                $stmt_img = $pdo->prepare("SELECT file_name FROM uploads WHERE id = ? AND deleted_at IS NULL");
                $stmt_img->execute([$first_photo_id]);
                $img_result = $stmt_img->fetch();
                if ($img_result) {
                    return $img_result['file_name'];
                }
            } catch (PDOException $e) {
                // Ignore error
            }
        }
    }
    return '';
}

// Function to calculate discount percentage
function getDiscountPercentage($product)
{
    if ($product['discount'] <= 0)
        return 0;

    if ($product['discount_type'] === 'percent') {
        return (int) $product['discount'];
    } else {
        return round(($product['discount'] / $product['unit_price']) * 100);
    }
}

// Function to build query string for pagination
function buildQueryString($exclude = [])
{
    $params = $_GET;
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    return http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sản phẩm - <?php echo SITE_NAME; ?></title>
    <meta name="description"
        content="Khám phá hàng triệu sản phẩm chất lượng tại <?php echo SITE_NAME; ?>. <?php echo SITE_DESCRIPTION; ?>">
    <meta name="keywords" content="sản phẩm, <?php echo SITE_KEYWORDS; ?>">
    <!-- CSS Files -->
    <link rel="stylesheet" href="asset/css/global.css">
    <link rel="stylesheet" href="asset/css/components.css">
    <link rel="stylesheet" href="asset/css/base.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <style>
    /* Additional styles for products page */

    /* Main Layout */
    .products-layout {
        display: flex;
        gap: 30px;
        align-items: flex-start;
    }

    /* Categories Sidebar */
    .categories-sidebar {
        width: 280px;
        background: white;
        border-radius: 15px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        padding: 25px;
        position: sticky;
        top: 20px;
        max-height: calc(100vh - 40px);
        overflow-y: auto;
    }

    .sidebar-title {
        font-size: 18px;
        font-weight: 700;
        color: #333;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f8f9fa;
    }

    .categories-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .category-item {
        margin-bottom: 8px;
    }

    .category-link {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 15px;
        text-decoration: none;
        color: #666;
        border-radius: 8px;
        transition: all 0.3s;
        font-size: 14px;
    }

    .category-link:hover {
        background: #f8f9fa;
        color: #1877f2;
        transform: translateX(5px);
    }

    .category-link.active {
        background: #e3f2fd;
        color: #1877f2;
        font-weight: 600;
    }

    .category-name {
        flex: 1;
    }

    .category-count {
        background: #f8f9fa;
        color: #666;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }

    .category-link.active .category-count {
        background: #1877f2;
        color: white;
    }

    /* Products Content */
    .products-content {
        flex: 1;
        min-width: 0;
    }

    /* Products Header */
    .products-header {
        background: white;
        padding: 20px 25px;
        border-radius: 15px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        margin-bottom: 25px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }

    .products-title {
        font-size: 20px;
        font-weight: 600;
        color: #333;
        margin: 0;
    }

    .products-controls {
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
    }

    .sort-controls {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .sort-select {
        padding: 8px 12px;
        border: 2px solid #e1e8ed;
        border-radius: 6px;
        font-size: 14px;
        outline: none;
        transition: border-color 0.3s;
    }

    .sort-select:focus {
        border-color: #1877f2;
    }

    .limit-select {
        padding: 8px 12px;
        border: 2px solid #e1e8ed;
        border-radius: 6px;
        font-size: 14px;
        outline: none;
        transition: border-color 0.3s;
    }

    .view-controls {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .view-btn {
        background: white;
        border: 2px solid #e1e8ed;
        padding: 8px 12px;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 14px;
    }

    .view-btn.active {
        background: #1877f2;
        color: white;
        border-color: #1877f2;
    }

    .view-btn:hover:not(.active) {
        border-color: #1877f2;
        color: #1877f2;
    }

    .results-info {
        font-size: 14px;
        color: #666;
    }

    /* Products Grid */
    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 40px;
    }

    .product-card {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        transition: all 0.3s;
        cursor: pointer;
        position: relative;
    }

    .product-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }

    .product-image {
        width: 100%;
        height: 220px;
        background: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        position: relative;
    }

    .product-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s;
    }

    .product-card:hover .product-image img {
        transform: scale(1.05);
    }

    .product-placeholder {
        font-size: 60px;
        color: #ccc;
    }

    .product-badges {
        position: absolute;
        top: 10px;
        left: 10px;
        display: flex;
        flex-direction: column;
        gap: 5px;
        z-index: 2;
    }

    .product-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        backdrop-filter: blur(10px);
    }

    .badge-featured {
        background: rgba(255, 107, 53, 0.9);
        color: white;
    }

    .badge-sale {
        background: rgba(220, 53, 69, 0.9);
        color: white;
    }

    .badge-new {
        background: rgba(40, 167, 69, 0.9);
        color: white;
    }

    .product-info {
        padding: 20px;
    }

    .product-brand {
        font-size: 12px;
        color: #666;
        margin-bottom: 5px;
    }

    .product-name {
        font-size: 16px;
        font-weight: 600;
        color: #333;
        margin-bottom: 10px;
        line-height: 1.3;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }

    .product-rating {
        display: flex;
        align-items: center;
        gap: 5px;
        margin-bottom: 10px;
        font-size: 14px;
    }

    .stars {
        color: #ffc107;
    }

    .rating-count {
        color: #666;
        font-size: 12px;
    }

    .product-price {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 15px;
    }

    .current-price {
        font-size: 18px;
        font-weight: 700;
        color: #ff6b35;
    }

    .original-price {
        font-size: 14px;
        color: #999;
        text-decoration: line-through;
    }

    .discount-percent {
        background: #ff6b35;
        color: white;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
    }

    .product-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        font-size: 12px;
        color: #666;
    }

    .seller-name {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .stock-status {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .stock-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
    }

    .in-stock {
        background: #28a745;
    }

    .low-stock {
        background: #ffc107;
    }

    .out-of-stock {
        background: #dc3545;
    }

    .product-actions {
        display: flex;
        gap: 10px;
    }

    .action-btn {
        flex: 1;
        padding: 10px;
        border: none;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-cart {
        background: #1877f2;
        color: white;
    }

    .btn-cart:hover {
        background: #166fe5;
    }

    .btn-cart:disabled {
        background: #ccc;
        cursor: not-allowed;
    }

    .btn-wishlist {
        background: #f8f9fa;
        color: #666;
        border: 1px solid #e1e8ed;
    }

    .btn-wishlist:hover {
        background: #e9ecef;
        color: #333;
    }

    .btn-wishlist.active {
        background: #ff6b35;
        color: white;
        border-color: #ff6b35;
    }

    /* Products List View */
    .products-list {
        display: none;
    }

    .products-list.active {
        display: block;
    }

    .product-list-item {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        display: flex;
        gap: 20px;
        transition: all 0.3s;
        cursor: pointer;
    }

    .product-list-item:hover {
        transform: translateX(10px);
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
    }

    .product-list-image {
        width: 150px;
        height: 150px;
        background: #f8f9fa;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
        position: relative;
    }

    .product-list-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .product-list-content {
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .product-list-header {
        margin-bottom: 10px;
    }

    .product-list-name {
        font-size: 18px;
        font-weight: 600;
        color: #333;
        margin-bottom: 5px;
    }

    .product-list-brand {
        font-size: 14px;
        color: #666;
    }

    .product-list-description {
        font-size: 14px;
        color: #666;
        line-height: 1.5;
        margin-bottom: 15px;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
    }

    .product-list-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: auto;
    }

    .product-list-price {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .product-list-actions {
        display: flex;
        gap: 10px;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 80px 20px;
        color: #666;
    }

    .empty-state-icon {
        font-size: 100px;
        margin-bottom: 30px;
        opacity: 0.5;
    }

    .empty-state-title {
        font-size: 24px;
        font-weight: 600;
        margin-bottom: 15px;
    }

    .empty-state-description {
        font-size: 16px;
        margin-bottom: 30px;
        line-height: 1.5;
    }

    .empty-state-action {
        background: #1877f2;
        color: white;
        padding: 12px 24px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: background 0.3s;
    }

    .empty-state-action:hover {
        background: #166fe5;
    }

    /* Responsive Design */
    @media (max-width: 1200px) {
        .categories-sidebar {
            width: 250px;
        }
    }

    @media (max-width: 992px) {
        .products-layout {
            flex-direction: column;
            gap: 20px;
        }

        .categories-sidebar {
            width: 100%;
            position: static;
            max-height: none;
        }

        .categories-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }

        .category-link {
            text-align: center;
        }
    }

    @media (max-width: 768px) {
        .products-header {
            flex-direction: column;
            align-items: stretch;
            gap: 15px;
        }

        .products-controls {
            flex-direction: column;
            align-items: stretch;
            gap: 15px;
        }

        .sort-controls {
            justify-content: space-between;
        }

        .view-controls {
            justify-content: center;
        }

        .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }

        .product-list-item {
            flex-direction: column;
            text-align: center;
        }

        .product-list-image {
            width: 100%;
            height: 200px;
        }

        .product-list-meta {
            flex-direction: column;
            gap: 15px;
            align-items: center;
        }

        .categories-list {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }

        .product-info {
            padding: 15px;
        }
    }
    </style>
</head>

<body>
    <?php
    if (file_exists('header.php')) {
        include 'header.php';
    } else {
        echo '<div style="padding: 20px; background: #f8f9fa; text-align: center;">Header not found</div>';
    }
    ?>

    <div class="section" style="margin-top: 40px;">
        <div class="products-layout">
            <!-- Categories Sidebar -->
            <div class="categories-sidebar">
                <h3 class="sidebar-title">Category</h3>
                <ul class="categories-list">
                    <li class="category-item">
                        <a href="products.php" class="category-link <?php echo empty($category_id) ? 'active' : ''; ?>">
                            <span class="category-name">All Products</span>
                            <span class="category-count"><?php echo number_format($total_products); ?></span>
                        </a>
                    </li>
                    <?php foreach ($categories as $category): ?>
                    <li class="category-item">
                        <a href="products.php?category_id=<?php echo $category['id']; ?>"
                            class="category-link <?php echo $category_id == $category['id'] ? 'active' : ''; ?>">
                            <span class="category-name"><?php echo htmlspecialchars($category['name']); ?></span>
                            <span class="category-count"><?php echo number_format($category['product_count']); ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Products Content -->
            <div class="products-content">
                <!-- Products Header -->
                <div class="products-header">
                    <div>
                        <h1 class="products-title">
                            <?php
                            if ($category_id && !empty($categories)) {
                                $selected_category = array_filter($categories, function ($cat) use ($category_id) {
                                    return $cat['id'] == $category_id;
                                });
                                $selected_category = reset($selected_category);
                                if ($selected_category && isset($selected_category['name'])) {
                                    echo htmlspecialchars($selected_category['name']);
                                } else {
                                    echo 'All Products';
                                }
                            } else {
                                echo 'All Products';
                            }
                            ?>
                        </h1>
                        <div class="results-info">
                            Hiển thị <?php echo count($products); ?> / <?php echo number_format($total_products); ?> sản
                            phẩm
                            • Trang <?php echo $page; ?> / <?php echo $total_pages; ?>
                        </div>
                    </div>

                    <div class="products-controls">
                        <div class="sort-controls">
                            <label>Sắp xếp:</label>
                            <select name="sort" class="sort-select" onchange="changeSort(this.value)">
                                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Mới nhất
                                </option>
                                <option value="popular" <?php echo $sort === 'popular' ? 'selected' : ''; ?>>Bán chạy
                                </option>
                                <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Giá
                                    thấp
                                    → cao</option>
                                <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Giá
                                    cao
                                    → thấp</option>
                                <option value="rating_high" <?php echo $sort === 'rating_high' ? 'selected' : ''; ?>>
                                    Đánh
                                    giá cao</option>
                                <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Tên A → Z
                                </option>
                            </select>

                            <label>Hiển thị:</label>
                            <select name="limit" class="limit-select" onchange="changeLimit(this.value)">
                                <option value="12" <?php echo $limit == 12 ? 'selected' : ''; ?>>12 sản phẩm</option>
                                <option value="24" <?php echo $limit == 24 ? 'selected' : ''; ?>>24 sản phẩm</option>
                                <option value="48" <?php echo $limit == 48 ? 'selected' : ''; ?>>48 sản phẩm</option>
                            </select>
                        </div>

                        <div class="view-controls">
                            <button type="button" class="view-btn <?php echo $view === 'grid' ? 'active' : ''; ?>"
                                onclick="switchView('grid')">🔷 Lưới</button>
                            <button type="button" class="view-btn <?php echo $view === 'list' ? 'active' : ''; ?>"
                                onclick="switchView('list')">📋 Danh sách</button>
                        </div>
                    </div>
                </div>

                <!-- Products Grid View -->
                <div class="products-grid-view <?php echo $view === 'grid' ? 'active' : ''; ?>">
                    <?php if (!empty($products)): ?>
                    <div class="products-grid">
                        <?php foreach ($products as $product): ?>
                        <?php
                                $product_image = getProductImage($product, $pdo);
                                $discount_percent = getDiscountPercentage($product);
                                $is_new = (time() - strtotime($product['created_at'])) < (7 * 24 * 60 * 60); // 7 days
                                $current_stock = intval($product['current_stock']);
                                ?>
                        <div class="product-card" onclick="navigateToProduct(<?php echo $product['id']; ?>)">
                            <div class="product-image">
                                <?php if ($product_image): ?>
                                <img src="<?php echo htmlspecialchars($product_image); ?>"
                                    alt="<?php echo htmlspecialchars($product['name']); ?>"
                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="product-placeholder" style="display: none;">📦</div>
                                <?php else: ?>
                                <div class="product-placeholder">📦</div>
                                <?php endif; ?>

                                <div class="product-badges">
                                    <?php if ($product['featured']): ?>
                                    <span class="product-badge badge-featured">Nổi bật</span>
                                    <?php endif; ?>
                                    <?php if ($discount_percent > 0): ?>
                                    <span class="product-badge badge-sale">-<?php echo $discount_percent; ?>%</span>
                                    <?php endif; ?>
                                    <?php if ($is_new): ?>
                                    <span class="product-badge badge-new">Mới</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="product-info">
                                <?php if (!empty($product['brand_name'])): ?>
                                <div class="product-brand"><?php echo htmlspecialchars($product['brand_name']); ?></div>
                                <?php endif; ?>

                                <h3 class="product-name"><?php echo htmlspecialchars($product['name']); ?></h3>

                                <?php if ($product['rating'] > 0): ?>
                                <div class="product-rating">
                                    <span class="stars">
                                        <?php
                                                    $rating = round($product['rating']);
                                                    for ($i = 1; $i <= 5; $i++) {
                                                        echo $i <= $rating ? '★' : '☆';
                                                    }
                                                    ?>
                                    </span>
                                    <span class="rating-count">(<?php echo intval($product['num_of_sale']); ?>)</span>
                                </div>
                                <?php endif; ?>

                                <div class="product-price">
                                    <span
                                        class="current-price"><?php echo formatPrice($product['final_price']); ?></span>
                                    <?php if ($discount_percent > 0): ?>
                                    <span
                                        class="original-price"><?php echo formatPrice($product['unit_price']); ?></span>
                                    <span class="discount-percent">-<?php echo $discount_percent; ?>%</span>
                                    <?php endif; ?>
                                </div>

                                <div class="product-meta">
                                    <div class="seller-name">
                                        👤 <?php echo htmlspecialchars($product['seller_name'] ?: SITE_NAME); ?>
                                    </div>
                                    <div class="stock-status">
                                        <?php
                                                $stock_class = 'out-of-stock';
                                                $stock_text = 'Hết hàng';
                                                if ($current_stock > 10) {
                                                    $stock_class = 'in-stock';
                                                    $stock_text = 'Còn hàng';
                                                } elseif ($current_stock > 0) {
                                                    $stock_class = 'low-stock';
                                                    $stock_text = 'Sắp hết';
                                                }
                                                ?>
                                        <span class="stock-dot <?php echo $stock_class; ?>"></span>
                                        <?php echo $stock_text; ?>
                                    </div>
                                </div>

                                <div class="product-actions">
                                    <button class="action-btn btn-cart"
                                        onclick="event.stopPropagation(); addToCart(<?php echo $product['id']; ?>)"
                                        <?php echo $current_stock <= 0 ? 'disabled' : ''; ?>>
                                        <?php echo $current_stock > 0 ? 'Thêm vào giỏ' : 'Hết hàng'; ?>
                                    </button>
                                    <button class="action-btn btn-wishlist"
                                        onclick="event.stopPropagation(); toggleWishlist(<?php echo $product['id']; ?>)">
                                        ♡
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Products List View -->
                <div class="products-list <?php echo $view === 'list' ? 'active' : ''; ?>">
                    <?php if (!empty($products)): ?>
                    <?php foreach ($products as $product): ?>
                    <?php
                            $product_image = getProductImage($product, $pdo);
                            $discount_percent = getDiscountPercentage($product);
                            $current_stock = intval($product['current_stock']);
                            ?>
                    <div class="product-list-item" onclick="navigateToProduct(<?php echo $product['id']; ?>)">
                        <div class="product-list-image">
                            <?php if ($product_image): ?>
                            <img src="<?php echo htmlspecialchars($product_image); ?>"
                                alt="<?php echo htmlspecialchars($product['name']); ?>"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="product-placeholder" style="display: none;">📦</div>
                            <?php else: ?>
                            <div class="product-placeholder">📦</div>
                            <?php endif; ?>

                            <div class="product-badges">
                                <?php if ($product['featured']): ?>
                                <span class="product-badge badge-featured">Nổi bật</span>
                                <?php endif; ?>
                                <?php if ($discount_percent > 0): ?>
                                <span class="product-badge badge-sale">-<?php echo $discount_percent; ?>%</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="product-list-content">
                            <div class="product-list-header">
                                <h3 class="product-list-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                                <?php if (!empty($product['brand_name'])): ?>
                                <div class="product-list-brand">Thương hiệu:
                                    <?php echo htmlspecialchars($product['brand_name']); ?></div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($product['description']) && trim($product['description']) !== ''): ?>
                            <div class="product-list-description">
                                <?php echo htmlspecialchars(truncateText($product['description'], 200)); ?>
                            </div>
                            <?php endif; ?>

                            <div class="product-list-meta">
                                <div class="product-list-price">
                                    <span
                                        class="current-price"><?php echo formatPrice($product['final_price']); ?></span>
                                    <?php if ($discount_percent > 0): ?>
                                    <span
                                        class="original-price"><?php echo formatPrice($product['unit_price']); ?></span>
                                    <span class="discount-percent">-<?php echo $discount_percent; ?>%</span>
                                    <?php endif; ?>
                                </div>

                                <div class="product-list-actions">
                                    <button class="action-btn btn-cart"
                                        onclick="event.stopPropagation(); addToCart(<?php echo $product['id']; ?>)"
                                        <?php echo $current_stock <= 0 ? 'disabled' : ''; ?>>
                                        <?php echo $current_stock > 0 ? 'Thêm vào giỏ' : 'Hết hàng'; ?>
                                    </button>
                                    <button class="action-btn btn-wishlist"
                                        onclick="event.stopPropagation(); toggleWishlist(<?php echo $product['id']; ?>)">
                                        ♡
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Empty State -->
                <?php if (empty($products)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🔍</div>
                    <h3 class="empty-state-title">Không tìm thấy sản phẩm nào</h3>
                    <p class="empty-state-description">
                        Hãy thử thay đổi từ khóa tìm kiếm hoặc điều chỉnh bộ lọc để tìm thấy sản phẩm phù hợp
                    </p>
                    <button class="empty-state-action" onclick="resetFilters()">Xóa tất cả bộ lọc</button>
                </div>
                <?php endif; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $page - 1; ?>"
                        class="pagination-btn">‹ Trước</a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <a href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $i; ?>"
                        class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo buildQueryString(['page']); ?>&page=<?php echo $page + 1; ?>"
                        class="pagination-btn">Tiếp ›</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
    if (file_exists('footer.php')) {
        include 'footer.php';
    } else {
        echo '<div style="padding: 20px; background: #f8f9fa; text-align: center;">Footer not found</div>';
    }
    ?>

    <script>
    // Navigation functions
    function navigateToProduct(productId) {
        window.location.href = `product-detail.php?id=${productId}`;
    }

    // View switching
    function switchView(viewType) {
        const gridView = document.querySelector('.products-grid-view');
        const listView = document.querySelector('.products-list');
        const gridBtn = document.querySelector('.view-btn:first-child');
        const listBtn = document.querySelector('.view-btn:last-child');

        if (viewType === 'grid') {
            gridView.classList.add('active');
            listView.classList.remove('active');
            gridBtn.classList.add('active');
            listBtn.classList.remove('active');
        } else {
            gridView.classList.remove('active');
            listView.classList.add('active');
            gridBtn.classList.remove('active');
            listBtn.classList.add('active');
        }

        // Update URL
        const url = new URL(window.location);
        url.searchParams.set('view', viewType);
        window.history.replaceState({}, '', url);

        // Save preference
        localStorage.setItem('productsViewType', viewType);
    }

    // Sort and limit functions
    function changeSort(sortValue) {
        const url = new URL(window.location);
        url.searchParams.set('sort', sortValue);
        url.searchParams.delete('page'); // Reset to first page
        window.location.href = url.toString();
    }

    function changeLimit(limitValue) {
        const url = new URL(window.location);
        url.searchParams.set('limit', limitValue);
        url.searchParams.delete('page'); // Reset to first page
        window.location.href = url.toString();
    }

    // Filter functions
    function resetFilters() {
        window.location.href = 'products.php';
    }

    function removeFilter(filterName) {
        const url = new URL(window.location);

        if (Array.isArray(filterName)) {
            filterName.forEach(name => url.searchParams.delete(name));
        } else {
            url.searchParams.delete(filterName);
        }

        url.searchParams.delete('page'); // Reset to first page
        window.location.href = url.toString();
    }

    // Add to cart function
    function addToCart(productId) {
        const button = event.target;
        const originalText = button.textContent;

        // Show loading state
        button.disabled = true;
        button.textContent = 'Đang thêm...';

        // AJAX call to add product to cart
        fetch('add-to-cart.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    product_id: productId,
                    quantity: 1
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update cart count in header
                    updateCartCount();

                    // Show success message
                    showNotification('Đã thêm sản phẩm vào giỏ hàng!', 'success');

                    // Reset button
                    button.disabled = false;
                    button.textContent = originalText;
                } else {
                    showNotification(data.message || 'Có lỗi xảy ra, vui lòng thử lại!', 'error');
                    button.disabled = false;
                    button.textContent = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Có lỗi xảy ra, vui lòng thử lại!', 'error');
                button.disabled = false;
                button.textContent = originalText;
            });
    }

    // Toggle wishlist function
    function toggleWishlist(productId) {
        const button = event.target;
        const isActive = button.classList.contains('active');

        // AJAX call to toggle wishlist
        fetch('toggle-wishlist.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    product_id: productId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.added) {
                        button.classList.add('active');
                        button.textContent = '♥';
                        showNotification('Đã thêm vào danh sách yêu thích!', 'success');
                    } else {
                        button.classList.remove('active');
                        button.textContent = '♡';
                        showNotification('Đã xóa khỏi danh sách yêu thích!', 'info');
                    }
                } else {
                    showNotification(data.message || 'Có lỗi xảy ra!', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Có lỗi xảy ra!', 'error');
            });
    }

    // Auto-submit form on filter change
    document.querySelectorAll('select[name="category_id"], select[name="brand_id"]').forEach(select => {
        select.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    });

    document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    });

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Restore view preference
        const savedViewType = localStorage.getItem('productsViewType');
        if (savedViewType && savedViewType !== '<?php echo $view; ?>') {
            switchView(savedViewType);
        }

        // Add smooth animations
        const cards = document.querySelectorAll('.product-card, .product-list-item');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.05}s`;
            card.style.animation = 'fadeInUp 0.6s ease-out forwards';
        });
    });

    // CSS animation for fadeInUp
    const style = document.createElement('style');
    style.textContent = `
            @keyframes fadeInUp {
                from {
                    transform: translateY(20px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
        `;
    document.head.appendChild(style);

    // Helper functions for notifications
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.textContent = message;

        document.body.appendChild(notification);

        // Auto remove after 3 seconds
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    function updateCartCount() {
        // This function should be implemented to update cart count in header
        // You can make an AJAX call to get current cart count
    }

    // Price range inputs validation
    document.querySelectorAll('.price-input').forEach(input => {
        input.addEventListener('change', function() {
            const minPrice = document.querySelector('input[name="min_price"]').value;
            const maxPrice = document.querySelector('input[name="max_price"]').value;

            if (minPrice && maxPrice && parseFloat(minPrice) > parseFloat(maxPrice)) {
                showNotification('Giá tối thiểu không thể lớn hơn giá tối đa!', 'error');
                this.value = '';
            }
        });
    });
    </script>
    <!-- JavaScript Files -->
    <script src="asset/js/global.js"></script>
    <script src="asset/js/components.js"></script>
</body>

</html>