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
$current_page = 'deals';
$require_login = false; // Allow guests to view deals

// Get user info if logged in
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : null;
$user_type = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : null;

// Pagination parameters
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : ITEMS_PER_PAGE;
$limit = in_array($limit, [12, 24, 48]) ? $limit : ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

// Filter parameters - simplified for sidebar navigation
$category_id = isset($_GET['category_id']) ? (int) $_GET['category_id'] : '';
$deal_type = isset($_GET['deal_type']) ? $_GET['deal_type'] : 'all';

// Sort parameters
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'discount_high';
$valid_sorts = ['discount_high', 'discount_low', 'price_low', 'price_high', 'newest', 'ending_soon', 'popular'];
$sort = in_array($sort, $valid_sorts) ? $sort : 'discount_high';

// View type
$view = isset($_GET['view']) ? $_GET['view'] : 'grid';
$view = in_array($view, ['grid', 'list']) ? $view : 'grid';

// Build WHERE clause for deals
$where_conditions = [
    "p.published = 1",
    "p.approved = 1",
    "p.discount > 0"
];
$params = [];

if ($category_id !== '') {
    $where_conditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $category_id;
}

// Deal type filter
if ($deal_type !== 'all') {
    switch ($deal_type) {
        case 'flash':
            $where_conditions[] = "(p.discount_end_date IS NOT NULL AND p.discount_end_date > UNIX_TIMESTAMP())";
            break;
        case 'featured':
            $where_conditions[] = "p.featured = 1";
            break;
        case 'today':
            $where_conditions[] = "p.todays_deal = 1";
            break;
        case 'high_discount':
            $where_conditions[] = "((p.discount_type = 'percent' AND p.discount >= 50) OR (p.discount_type = 'amount' AND (p.discount / p.unit_price * 100) >= 50))";
            break;
    }
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Build ORDER BY clause
$order_by = match ($sort) {
    'discount_high' => 'discount_percent DESC, p.created_at DESC',
    'discount_low' => 'discount_percent ASC, p.created_at DESC',
    'price_low' => 'final_price ASC',
    'price_high' => 'final_price DESC',
    'newest' => 'p.created_at DESC',
    'ending_soon' => 'p.discount_end_date ASC, discount_percent DESC',
    'popular' => 'p.num_of_sale DESC, discount_percent DESC',
    default => 'discount_percent DESC, p.created_at DESC'
};

// Get deals with pagination
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
               END) as final_price,
               (CASE 
                   WHEN p.discount_type = 'percent' THEN p.discount
                   WHEN p.discount_type = 'amount' THEN ROUND((p.discount / p.unit_price) * 100, 2)
                   ELSE 0 
               END) as discount_percent
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
    $deals = $stmt->fetchAll();

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
    $total_deals = $count_stmt->fetchColumn();
    $total_pages = ceil($total_deals / $limit);
} catch (PDOException $e) {
    error_log("Database Error in deals.php: " . $e->getMessage());
    $deals = [];
    $total_deals = 0;
    $total_pages = 1;
}

// Get categories for sidebar
try {
    $stmt = $pdo->prepare("
        SELECT c.*, COUNT(p.id) as deal_count 
        FROM categories c 
        LEFT JOIN products p ON c.id = p.category_id AND p.published = 1 AND p.approved = 1 AND p.discount > 0
        GROUP BY c.id 
        HAVING deal_count > 0 
        ORDER BY c.name ASC
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll();

    // Get total deals count for "All Deals" link
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE published = 1 AND approved = 1 AND discount > 0");
    $total_stmt->execute();
    $all_deals_count = $total_stmt->fetchColumn();
} catch (PDOException $e) {
    $categories = [];
    $all_deals_count = 0;
}

// Get deal type counts
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_deals,
            COUNT(CASE WHEN featured = 1 THEN 1 END) as featured_deals,
            COUNT(CASE WHEN todays_deal = 1 THEN 1 END) as today_deals,
            COUNT(CASE WHEN discount_end_date IS NOT NULL AND discount_end_date > UNIX_TIMESTAMP() THEN 1 END) as flash_deals,
            COUNT(CASE WHEN ((discount_type = 'percent' AND discount >= 50) OR (discount_type = 'amount' AND (discount / unit_price * 100) >= 50)) THEN 1 END) as high_discount_deals
        FROM products 
        WHERE published = 1 AND approved = 1 AND discount > 0
    ");
    $stats_stmt->execute();
    $deal_stats = $stats_stmt->fetch();
} catch (PDOException $e) {
    $deal_stats = [
        'total_deals' => 0,
        'featured_deals' => 0,
        'today_deals' => 0,
        'flash_deals' => 0,
        'high_discount_deals' => 0
    ];
}

// Function to get product image
function getProductImage($product, $pdo)
{
    if (!empty($product['thumbnail_file'])) {
        return $product['thumbnail_file'];
    } elseif (!empty($product['photos'])) {
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

// Function to get countdown text
function getCountdownText($end_timestamp)
{
    if (!$end_timestamp)
        return '';

    $now = time();
    $diff = $end_timestamp - $now;

    if ($diff <= 0)
        return 'Đã hết hạn';

    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    $minutes = floor(($diff % 3600) / 60);

    if ($days > 0) {
        return $days . ' ngày';
    } elseif ($hours > 0) {
        return $hours . ' giờ';
    } else {
        return $minutes . ' phút';
    }
}

// Function to build query string for pagination
function buildQueryString($exclude = [])
{
    $params = [];

    if (!empty($_GET['category_id'])) {
        $params['category_id'] = $_GET['category_id'];
    }
    if (!empty($_GET['deal_type'])) {
        $params['deal_type'] = $_GET['deal_type'];
    }
    if (!empty($_GET['sort'])) {
        $params['sort'] = $_GET['sort'];
    }
    if (!empty($_GET['limit'])) {
        $params['limit'] = $_GET['limit'];
    }
    if (!empty($_GET['view'])) {
        $params['view'] = $_GET['view'];
    }

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
    <title>Khuyến mãi - <?php echo SITE_NAME; ?></title>
    <meta name="description"
        content="Khuyến mãi hấp dẫn tại <?php echo SITE_NAME; ?>. Flash Sale, giảm giá lên đến 70%. <?php echo SITE_DESCRIPTION; ?>">
    <meta name="keywords" content="khuyến mãi, giảm giá, flash sale, deals, <?php echo SITE_KEYWORDS; ?>">
    <!-- CSS Files -->
    <link rel="stylesheet" href="asset/css/global.css">
    <link rel="stylesheet" href="asset/css/components.css">
    <link rel="stylesheet" href="asset/css/base.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <style>
    /* Additional styles for deals page - based on products.php */

    /* Main Layout */
    .deals-layout {
        display: flex;
        gap: 30px;
        align-items: flex-start;
    }

    /* Deal Types Sidebar */
    .deals-sidebar {
        width: 280px;
        background: white;
        border-radius: 15px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        padding: 25px;
        position: sticky;
        top: 20px;
        max-height: calc(100vh - 40px);
        overflow-y: auto;
        flex-shrink: 0;
    }

    .deals-sidebar::-webkit-scrollbar {
        width: 6px;
    }

    .deals-sidebar::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 3px;
    }

    .deals-sidebar::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 3px;
    }

    .deals-sidebar::-webkit-scrollbar-thumb:hover {
        background: #999;
    }

    .sidebar-title {
        font-size: 18px;
        font-weight: 700;
        color: #333;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f8f9fa;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .sidebar-section {
        margin-bottom: 30px;
    }

    .sidebar-section:last-child {
        margin-bottom: 0;
    }

    .section-title {
        font-size: 14px;
        font-weight: 600;
        color: #666;
        margin-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .deals-list,
    .categories-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .deal-item,
    .category-item {
        margin-bottom: 8px;
    }

    .deal-link,
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

    .deal-link:hover,
    .category-link:hover {
        background: #f8f9fa;
        color: #ff6b35;
        transform: translateX(5px);
    }

    .deal-link.active,
    .category-link.active {
        background: #fff3e0;
        color: #ff6b35;
        font-weight: 600;
    }

    .deal-name,
    .category-name {
        flex: 1;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .deal-icon {
        font-size: 16px;
    }

    .deal-count,
    .category-count {
        background: #f8f9fa;
        color: #666;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }

    .deal-link.active .deal-count,
    .category-link.active .category-count {
        background: #ff6b35;
        color: white;
    }

    /* Products Content */
    .deals-content {
        flex: 1;
        min-width: 0;
    }

    /* Products Header */
    .deals-header {
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

    .deals-title {
        font-size: 20px;
        font-weight: 600;
        color: #333;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .deals-controls {
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
        border-color: #ff6b35;
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
        background: #ff6b35;
        color: white;
        border-color: #ff6b35;
    }

    .view-btn:hover:not(.active) {
        border-color: #ff6b35;
        color: #ff6b35;
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
        border: 2px solid transparent;
    }

    .product-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(255, 107, 53, 0.2);
        border-color: #ff6b35;
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
        padding: 6px 12px;
        border-radius: 15px;
        font-size: 12px;
        font-weight: 700;
        backdrop-filter: blur(10px);
        text-align: center;
        min-width: 60px;
    }

    .badge-discount {
        background: linear-gradient(135deg, #ff6b35, #f7931e);
        color: white;
        font-size: 14px;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {

        0%,
        100% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.05);
        }
    }

    .badge-flash {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: white;
    }

    .badge-featured {
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
    }

    .badge-today {
        background: linear-gradient(135deg, #007bff, #6610f2);
        color: white;
    }

    .product-countdown {
        position: absolute;
        top: 10px;
        right: 10px;
        background: rgba(220, 53, 69, 0.9);
        color: white;
        padding: 6px 10px;
        border-radius: 15px;
        font-size: 11px;
        font-weight: 600;
        backdrop-filter: blur(10px);
        z-index: 2;
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
        flex-wrap: wrap;
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
        background: linear-gradient(135deg, #ff6b35, #f7931e);
        color: white;
    }

    .btn-cart:hover {
        background: linear-gradient(135deg, #f7931e, #ff6b35);
        transform: translateY(-2px);
    }

    .btn-cart:disabled {
        background: #ccc;
        cursor: not-allowed;
        transform: none;
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
        border: 2px solid transparent;
    }

    .product-list-item:hover {
        transform: translateX(10px);
        box-shadow: 0 5px 20px rgba(255, 107, 53, 0.15);
        border-color: #ff6b35;
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
        background: linear-gradient(135deg, #ff6b35, #f7931e);
        color: white;
        padding: 12px 24px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
    }

    .empty-state-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(255, 107, 53, 0.3);
    }

    /* Responsive Design */
    @media (max-width: 1200px) {
        .deals-sidebar {
            width: 250px;
        }
    }

    @media (max-width: 992px) {
        .deals-layout {
            flex-direction: column;
            gap: 20px;
        }

        .deals-sidebar {
            width: 100%;
            position: static;
            max-height: none;
        }

        .sidebar-section {
            margin-bottom: 20px;
        }

        .deals-list,
        .categories-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }

        .deal-link,
        .category-link {
            text-align: center;
        }
    }

    @media (max-width: 768px) {
        .deals-header {
            flex-direction: column;
            align-items: stretch;
            gap: 15px;
        }

        .deals-controls {
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

        .deals-list,
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
        <div class="deals-layout">
            <!-- Deals Sidebar -->
            <div class="deals-sidebar">
                <h3 class="sidebar-title">
                    🔥 Deals & Offers
                </h3>

                <!-- Deal Types Section -->
                <div class="sidebar-section">
                    <div class="section-title">Loại khuyến mãi</div>
                    <ul class="deals-list">
                        <li class="deal-item">
                            <a href="deals.php" class="deal-link <?php echo $deal_type === 'all' ? 'active' : ''; ?>">
                                <span class="deal-name">
                                    <span class="deal-icon">🛍️</span>
                                    Tất cả Deal
                                </span>
                                <span class="deal-count"><?php echo number_format($deal_stats['total_deals']); ?></span>
                            </a>
                        </li>
                        <li class="deal-item">
                            <a href="deals.php?deal_type=flash"
                                class="deal-link <?php echo $deal_type === 'flash' ? 'active' : ''; ?>">
                                <span class="deal-name">
                                    <span class="deal-icon">⚡</span>
                                    Flash Sale
                                </span>
                                <span class="deal-count"><?php echo number_format($deal_stats['flash_deals']); ?></span>
                            </a>
                        </li>
                        <li class="deal-item">
                            <a href="deals.php?deal_type=featured"
                                class="deal-link <?php echo $deal_type === 'featured' ? 'active' : ''; ?>">
                                <span class="deal-name">
                                    <span class="deal-icon">⭐</span>
                                    Deal nổi bật
                                </span>
                                <span
                                    class="deal-count"><?php echo number_format($deal_stats['featured_deals']); ?></span>
                            </a>
                        </li>
                        <li class="deal-item">
                            <a href="deals.php?deal_type=today"
                                class="deal-link <?php echo $deal_type === 'today' ? 'active' : ''; ?>">
                                <span class="deal-name">
                                    <span class="deal-icon">📅</span>
                                    Deal hôm nay
                                </span>
                                <span class="deal-count"><?php echo number_format($deal_stats['today_deals']); ?></span>
                            </a>
                        </li>
                        <li class="deal-item">
                            <a href="deals.php?deal_type=high_discount"
                                class="deal-link <?php echo $deal_type === 'high_discount' ? 'active' : ''; ?>">
                                <span class="deal-name">
                                    <span class="deal-icon">💥</span>
                                    Giảm sốc 50%+
                                </span>
                                <span
                                    class="deal-count"><?php echo number_format($deal_stats['high_discount_deals']); ?></span>
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Categories Section -->
                <div class="sidebar-section">
                    <div class="section-title">Danh mục khuyến mãi</div>
                    <ul class="categories-list">
                        <?php foreach ($categories as $category): ?>
                        <li class="category-item">
                            <a href="deals.php?category_id=<?php echo $category['id']; ?>"
                                class="category-link <?php echo $category_id == $category['id'] ? 'active' : ''; ?>">
                                <span class="category-name"><?php echo htmlspecialchars($category['name']); ?></span>
                                <span
                                    class="category-count"><?php echo number_format($category['deal_count']); ?></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- Deals Content -->
            <div class="deals-content">
                <!-- Deals Header -->
                <div class="deals-header">
                    <div>
                        <h1 class="deals-title">
                            <?php
                            $icons = [
                                'all' => '🛍️',
                                'flash' => '⚡',
                                'featured' => '⭐',
                                'today' => '📅',
                                'high_discount' => '💥'
                            ];

                            $titles = [
                                'all' => 'Tất cả Deal',
                                'flash' => 'Flash Sale',
                                'featured' => 'Deal nổi bật',
                                'today' => 'Deal hôm nay',
                                'high_discount' => 'Giảm sốc 50%+'
                            ];

                            echo $icons[$deal_type] . ' ' . $titles[$deal_type];

                            if ($category_id && !empty($categories)) {
                                $selected_category = array_filter($categories, function ($cat) use ($category_id) {
                                    return $cat['id'] == $category_id;
                                });
                                $selected_category = reset($selected_category);
                                if ($selected_category && isset($selected_category['name'])) {
                                    echo ' - ' . htmlspecialchars($selected_category['name']);
                                }
                            }
                            ?>
                        </h1>
                        <div class="results-info">
                            Hiển thị <?php echo count($deals); ?> / <?php echo number_format($total_deals); ?> sản phẩm
                            • Trang <?php echo $page; ?> / <?php echo $total_pages; ?>
                        </div>
                    </div>

                    <div class="deals-controls">
                        <div class="sort-controls">
                            <label>Sắp xếp:</label>
                            <select name="sort" class="sort-select" onchange="changeSort(this.value)">
                                <option value="discount_high"
                                    <?php echo $sort === 'discount_high' ? 'selected' : ''; ?>>
                                    Giảm nhiều nhất</option>
                                <option value="discount_low" <?php echo $sort === 'discount_low' ? 'selected' : ''; ?>>
                                    Giảm ít nhất</option>
                                <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Giá
                                    thấp
                                    → cao</option>
                                <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Giá
                                    cao
                                    → thấp</option>
                                <option value="ending_soon" <?php echo $sort === 'ending_soon' ? 'selected' : ''; ?>>Sắp
                                    hết hạn</option>
                                <option value="popular" <?php echo $sort === 'popular' ? 'selected' : ''; ?>>Bán chạy
                                </option>
                                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Mới nhất
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
                    <?php if (!empty($deals)): ?>
                    <div class="products-grid">
                        <?php foreach ($deals as $product): ?>
                        <?php
                                $product_image = getProductImage($product, $pdo);
                                $discount_percent = round($product['discount_percent']);
                                $is_flash = !empty($product['discount_end_date']) && $product['discount_end_date'] > time();
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
                                    <span class="product-badge badge-discount">-<?php echo $discount_percent; ?>%</span>
                                    <?php if ($is_flash): ?>
                                    <span class="product-badge badge-flash">Flash Sale</span>
                                    <?php endif; ?>
                                    <?php if ($product['featured']): ?>
                                    <span class="product-badge badge-featured">Nổi bật</span>
                                    <?php endif; ?>
                                    <?php if ($product['todays_deal']): ?>
                                    <span class="product-badge badge-today">Deal hôm nay</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($is_flash): ?>
                                <div class="product-countdown">
                                    ⏰ <?php echo getCountdownText($product['discount_end_date']); ?>
                                </div>
                                <?php endif; ?>
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
                                    <span
                                        class="original-price"><?php echo formatPrice($product['unit_price']); ?></span>
                                    <span class="discount-percent">-<?php echo $discount_percent; ?>%</span>
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
                    <?php if (!empty($deals)): ?>
                    <?php foreach ($deals as $product): ?>
                    <?php
                            $product_image = getProductImage($product, $pdo);
                            $discount_percent = round($product['discount_percent']);
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
                                <span class="product-badge badge-discount">-<?php echo $discount_percent; ?>%</span>
                            </div>
                        </div>

                        <div class="product-list-content">
                            <div class="product-list-header">
                                <h3 class="product-list-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                                <?php if (!empty($product['brand_name'])): ?>
                                <div class="product-list-brand">Thương hiệu:
                                    <?php echo htmlspecialchars($product['brand_name']); ?>
                                </div>
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
                                    <span
                                        class="original-price"><?php echo formatPrice($product['unit_price']); ?></span>
                                    <span class="discount-percent">-<?php echo $discount_percent; ?>%</span>
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
                <?php if (empty($deals)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🔍</div>
                    <h3 class="empty-state-title">Không có deal nào phù hợp</h3>
                    <p class="empty-state-description">
                        Hiện tại không có sản phẩm khuyến mãi nào phù hợp với bộ lọc của bạn.<br>
                        Hãy thử thay đổi bộ lọc hoặc quay lại sau để không bỏ lỡ deal hot!
                    </p>
                    <button class="empty-state-action" onclick="clearFilters()">Xem tất cả deal</button>
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
        localStorage.setItem('dealsViewType', viewType);
    }

    // Sort and limit functions
    function changeSort(sortValue) {
        const url = new URL(window.location);
        url.searchParams.set('sort', sortValue);
        url.searchParams.delete('page');
        window.location.href = url.toString();
    }

    function changeLimit(limitValue) {
        const url = new URL(window.location);
        url.searchParams.set('limit', limitValue);
        url.searchParams.delete('page');
        window.location.href = url.toString();
    }

    // Clear filters
    function clearFilters() {
        window.location.href = 'deals.php';
    }

    // Add to cart function
    function addToCart(productId) {
        const button = event.target;
        const originalText = button.textContent;

        button.disabled = true;
        button.textContent = 'Đang thêm...';

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
                    updateCartCount();
                    showNotification('Đã thêm sản phẩm vào giỏ hàng!', 'success');
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

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Restore view preference
        const savedViewType = localStorage.getItem('dealsViewType');
        if (savedViewType && savedViewType !== '<?php echo $view; ?>') {
            switchView(savedViewType);
        }

        // Add smooth animations
        const cards = document.querySelectorAll('.product-card, .product-list-item');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.05}s`;
            card.style.animation = 'slideInUp 0.6s ease-out forwards';
        });
    });

    // Helper functions
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.textContent = message;

        document.body.appendChild(notification);

        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    function updateCartCount() {
        // Implementation for updating cart count in header
    }

    // CSS animation for slideInUp
    const style = document.createElement('style');
    style.textContent = `
            @keyframes slideInUp {
                from {
                    transform: translateY(30px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
        `;
    document.head.appendChild(style);
    </script>
    <!-- JavaScript Files -->
    <script src="asset/js/global.js"></script>
    <script src="asset/js/components.js"></script>
</body>

</html>