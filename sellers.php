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
$current_page = 'sellers';
$require_login = false; // Allow guests to view sellers

// Get user info if logged in
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : null;
$user_type = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : null;

// Pagination parameters
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : ITEMS_PER_PAGE;
$limit = in_array($limit, [12, 24, 48]) ? $limit : ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

// Filter parameters
$category_id = isset($_GET['category_id']) ? (int) $_GET['category_id'] : '';
$seller_type = isset($_GET['seller_type']) ? $_GET['seller_type'] : 'all';
$location = isset($_GET['location']) ? trim($_GET['location']) : '';

// Sort parameters
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'rating_high';
$valid_sorts = ['rating_high', 'rating_low', 'products_high', 'products_low', 'newest', 'oldest', 'name_asc', 'name_desc'];
$sort = in_array($sort, $valid_sorts) ? $sort : 'rating_high';

// View type
$view = isset($_GET['view']) ? $_GET['view'] : 'grid';
$view = in_array($view, ['grid', 'list']) ? $view : 'grid';

// Build WHERE clause for sellers
$where_conditions = ["u.user_type = 'seller'", "u.banned = 0"];
$params = [];

if ($category_id !== '') {
    $where_conditions[] = "EXISTS (SELECT 1 FROM products p WHERE p.user_id = u.id AND p.category_id = :category_id AND p.published = 1 AND p.approved = 1)";
    $params[':category_id'] = $category_id;
}

if (!empty($location)) {
    $where_conditions[] = "(u.city LIKE :location OR u.state LIKE :location OR u.country LIKE :location)";
    $params[':location'] = "%$location%";
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Build HAVING clause for seller type
$having_conditions = [];
switch ($seller_type) {
    case 'verified':
        $having_conditions[] = "verified_seller = 1";
        break;
    case 'top_rated':
        $having_conditions[] = "avg_rating >= 4.5";
        break;
    case 'new':
        $having_conditions[] = "DATEDIFF(NOW(), u.created_at) <= 30";
        break;
    case 'active':
        $having_conditions[] = "total_products >= 10";
        break;
}

$having_clause = !empty($having_conditions) ? 'HAVING ' . implode(' AND ', $having_conditions) : '';

// Build ORDER BY clause
$order_by = match ($sort) {
    'rating_high' => 'avg_rating DESC, total_products DESC',
    'rating_low' => 'avg_rating ASC, total_products DESC',
    'products_high' => 'total_products DESC, avg_rating DESC',
    'products_low' => 'total_products ASC, avg_rating DESC',
    'newest' => 'u.created_at DESC',
    'oldest' => 'u.created_at ASC',
    'name_asc' => 'u.name ASC',
    'name_desc' => 'u.name DESC',
    default => 'avg_rating DESC, total_products DESC'
};

// Get sellers with pagination
try {
    $stmt = $pdo->prepare("
        SELECT u.*, 
               COUNT(DISTINCT p.id) as total_products,
               COUNT(DISTINCT CASE WHEN p.featured = 1 THEN p.id END) as featured_products,
               COALESCE(AVG(r.rating), 0) as avg_rating,
               COUNT(DISTINCT r.id) as total_reviews,
               SUM(p.num_of_sale) as total_sales,
               MAX(p.created_at) as last_product_date,
               avatar.file_name as avatar_file,
               CASE 
                   WHEN COUNT(DISTINCT p.id) >= 50 AND COALESCE(AVG(r.rating), 0) >= 4.5 THEN 1 
                   ELSE 0 
               END as verified_seller
        FROM users u 
        LEFT JOIN products p ON u.id = p.user_id AND p.published = 1 AND p.approved = 1
        LEFT JOIN reviews r ON p.id = r.product_id AND r.status = 1
        LEFT JOIN uploads avatar ON u.avatar = avatar.id AND avatar.deleted_at IS NULL
        $where_clause
        GROUP BY u.id
        $having_clause
        ORDER BY $order_by
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $sellers = $stmt->fetchAll();

    // Get total count for pagination
    $count_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT u.id) 
        FROM users u 
        LEFT JOIN products p ON u.id = p.user_id AND p.published = 1 AND p.approved = 1
        LEFT JOIN reviews r ON p.id = r.product_id AND r.status = 1
        $where_clause
        GROUP BY u.id
        $having_clause
    ");
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_sellers = $count_stmt->rowCount();
    $total_pages = ceil($total_sellers / $limit);
} catch (PDOException $e) {
    error_log("Database Error in sellers.php: " . $e->getMessage());
    $sellers = [];
    $total_sellers = 0;
    $total_pages = 1;
}

// Get categories for sidebar
try {
    $stmt = $pdo->prepare("
        SELECT c.*, COUNT(DISTINCT u.id) as seller_count 
        FROM categories c 
        INNER JOIN products p ON c.id = p.category_id AND p.published = 1 AND p.approved = 1
        INNER JOIN users u ON p.user_id = u.id AND u.user_type = 'seller' AND u.banned = 0
        GROUP BY c.id 
        HAVING seller_count > 0 
        ORDER BY c.name ASC
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    $categories = [];
}

// Get seller type counts
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT u.id) as total_sellers,
            COUNT(DISTINCT CASE WHEN (COUNT(DISTINCT p.id) >= 50 AND COALESCE(AVG(r.rating), 0) >= 4.5) THEN u.id END) as verified_sellers,
            COUNT(DISTINCT CASE WHEN COALESCE(AVG(r.rating), 0) >= 4.5 THEN u.id END) as top_rated_sellers,
            COUNT(DISTINCT CASE WHEN DATEDIFF(NOW(), u.created_at) <= 30 THEN u.id END) as new_sellers,
            COUNT(DISTINCT CASE WHEN COUNT(DISTINCT p.id) >= 10 THEN u.id END) as active_sellers
        FROM users u 
        LEFT JOIN products p ON u.id = p.user_id AND p.published = 1 AND p.approved = 1
        LEFT JOIN reviews r ON p.id = r.product_id AND r.status = 1
        WHERE u.user_type = 'seller' AND u.banned = 0
        GROUP BY u.id
    ");
    $stats_stmt->execute();
    $seller_stats = $stats_stmt->fetchAll();

    // Count totals
    $seller_counts = [
        'total_sellers' => count($seller_stats),
        'verified_sellers' => 0,
        'top_rated_sellers' => 0,
        'new_sellers' => 0,
        'active_sellers' => 0
    ];

    // Simple count query for each type
    $simple_stats = $pdo->query("
        SELECT 
            COUNT(DISTINCT u.id) as total_sellers
        FROM users u 
        WHERE u.user_type = 'seller' AND u.banned = 0
    ")->fetch();

    $seller_counts['total_sellers'] = $simple_stats['total_sellers'];

} catch (PDOException $e) {
    $seller_counts = [
        'total_sellers' => 0,
        'verified_sellers' => 0,
        'top_rated_sellers' => 0,
        'new_sellers' => 0,
        'active_sellers' => 0
    ];
}

// Function to get seller avatar
function getSellerAvatar($seller)
{
    if (!empty($seller['avatar_file'])) {
        return $seller['avatar_file'];
    }
    return '';
}

// Function to format join date
function getJoinTime($date)
{
    $join_date = new DateTime($date);
    $now = new DateTime();
    $diff = $now->diff($join_date);

    if ($diff->y > 0) {
        return $diff->y . ' năm';
    } elseif ($diff->m > 0) {
        return $diff->m . ' tháng';
    } elseif ($diff->d > 0) {
        return $diff->d . ' ngày';
    } else {
        return 'Hôm nay';
    }
}

// Function to build query string for pagination
function buildQueryString($exclude = [])
{
    $params = [];

    if (!empty($_GET['category_id'])) {
        $params['category_id'] = $_GET['category_id'];
    }
    if (!empty($_GET['seller_type'])) {
        $params['seller_type'] = $_GET['seller_type'];
    }
    if (!empty($_GET['location'])) {
        $params['location'] = $_GET['location'];
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
    <title>Đối tác bán hàng - <?php echo SITE_NAME; ?></title>
    <meta name="description"
        content="Khám phá các đối tác bán hàng uy tín tại <?php echo SITE_NAME; ?>. <?php echo SITE_DESCRIPTION; ?>">
    <meta name="keywords" content="đối tác, seller, bán hàng, <?php echo SITE_KEYWORDS; ?>">
    <link rel="stylesheet" href="asset/base.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <style>
    /* Additional styles for sellers page - based on products.php */

    /* Main Layout */
    .sellers-layout {
        display: flex;
        gap: 30px;
        align-items: flex-start;
    }

    /* Seller Types Sidebar */
    .sellers-sidebar {
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

    .sellers-sidebar::-webkit-scrollbar {
        width: 6px;
    }

    .sellers-sidebar::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 3px;
    }

    .sellers-sidebar::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 3px;
    }

    .sellers-sidebar::-webkit-scrollbar-thumb:hover {
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

    .sellers-list,
    .categories-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .seller-item,
    .category-item {
        margin-bottom: 8px;
    }

    .seller-link,
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

    .seller-link:hover,
    .category-link:hover {
        background: #f8f9fa;
        color: #1877f2;
        transform: translateX(5px);
    }

    .seller-link.active,
    .category-link.active {
        background: #e3f2fd;
        color: #1877f2;
        font-weight: 600;
    }

    .seller-name,
    .category-name {
        flex: 1;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .seller-icon {
        font-size: 16px;
    }

    .seller-count,
    .category-count {
        background: #f8f9fa;
        color: #666;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }

    .seller-link.active .seller-count,
    .category-link.active .category-count {
        background: #1877f2;
        color: white;
    }

    /* Location Search */
    .location-search {
        position: relative;
        margin-bottom: 20px;
    }

    .location-input {
        width: 100%;
        padding: 10px 15px;
        border: 2px solid #e1e8ed;
        border-radius: 8px;
        font-size: 14px;
        outline: none;
        transition: border-color 0.3s;
    }

    .location-input:focus {
        border-color: #1877f2;
    }

    /* Sellers Content */
    .sellers-content {
        flex: 1;
        min-width: 0;
    }

    /* Sellers Header */
    .sellers-header {
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

    .sellers-title {
        font-size: 20px;
        font-weight: 600;
        color: #333;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .sellers-controls {
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

    /* Sellers Grid */
    .sellers-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 40px;
    }

    .seller-card {
        background: white;
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        transition: all 0.3s;
        cursor: pointer;
        position: relative;
        border: 2px solid transparent;
        text-align: center;
    }

    .seller-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 12px 30px rgba(24, 119, 242, 0.15);
        border-color: #1877f2;
    }

    .seller-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        margin: 0 auto 15px;
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        position: relative;
        border: 3px solid #f8f9fa;
    }

    .seller-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
    }

    .seller-avatar-placeholder {
        font-size: 32px;
        color: #666;
    }

    .seller-badges {
        position: absolute;
        top: 15px;
        right: 15px;
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .seller-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        backdrop-filter: blur(10px);
    }

    .badge-verified {
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
    }

    .badge-top {
        background: linear-gradient(135deg, #ffc107, #fd7e14);
        color: white;
    }

    .badge-new {
        background: linear-gradient(135deg, #007bff, #6610f2);
        color: white;
    }

    .seller-info {
        margin-bottom: 20px;
    }

    .seller-name {
        font-size: 18px;
        font-weight: 700;
        color: #333;
        margin-bottom: 8px;
    }

    .seller-location {
        font-size: 14px;
        color: #666;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
    }

    .seller-rating {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-bottom: 15px;
    }

    .rating-stars {
        color: #ffc107;
        font-size: 16px;
    }

    .rating-text {
        font-size: 14px;
        color: #333;
        font-weight: 600;
    }

    .rating-count {
        font-size: 12px;
        color: #666;
    }

    .seller-stats {
        display: flex;
        justify-content: space-around;
        margin-bottom: 20px;
        padding: 15px 0;
        border-top: 1px solid #f8f9fa;
        border-bottom: 1px solid #f8f9fa;
    }

    .stat-item {
        text-align: center;
    }

    .stat-number {
        font-size: 16px;
        font-weight: 700;
        color: #1877f2;
        display: block;
    }

    .stat-label {
        font-size: 12px;
        color: #666;
        margin-top: 2px;
    }

    .seller-join-date {
        font-size: 12px;
        color: #999;
        margin-bottom: 15px;
    }

    .seller-actions {
        display: flex;
        gap: 10px;
    }

    .action-btn {
        flex: 1;
        padding: 10px 16px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-visit {
        background: #1877f2;
        color: white;
    }

    .btn-visit:hover {
        background: #166fe5;
        transform: translateY(-2px);
    }

    .btn-follow {
        background: #f8f9fa;
        color: #666;
        border: 1px solid #e1e8ed;
    }

    .btn-follow:hover {
        background: #e9ecef;
        color: #333;
    }

    .btn-follow.active {
        background: #28a745;
        color: white;
        border-color: #28a745;
    }

    /* Sellers List View */
    .sellers-list-view {
        display: none;
    }

    .sellers-list-view.active {
        display: block;
    }

    .seller-list-item {
        background: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 20px;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        display: flex;
        gap: 25px;
        transition: all 0.3s;
        cursor: pointer;
        border: 2px solid transparent;
    }

    .seller-list-item:hover {
        transform: translateX(10px);
        box-shadow: 0 8px 25px rgba(24, 119, 242, 0.15);
        border-color: #1877f2;
    }

    .seller-list-avatar {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
        position: relative;
        border: 3px solid #f8f9fa;
    }

    .seller-list-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
    }

    .seller-list-content {
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .seller-list-header {
        margin-bottom: 15px;
    }

    .seller-list-name {
        font-size: 22px;
        font-weight: 700;
        color: #333;
        margin-bottom: 8px;
    }

    .seller-list-location {
        font-size: 14px;
        color: #666;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .seller-list-stats {
        display: flex;
        gap: 30px;
        margin-bottom: 15px;
    }

    .seller-list-stat {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
    }

    .seller-list-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: auto;
    }

    .seller-list-rating {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .seller-list-actions {
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
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
    }

    .empty-state-action:hover {
        background: #166fe5;
        transform: translateY(-2px);
    }

    /* Responsive Design */
    @media (max-width: 1200px) {
        .sellers-sidebar {
            width: 250px;
        }

        .sellers-grid {
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        }
    }

    @media (max-width: 992px) {
        .sellers-layout {
            flex-direction: column;
            gap: 20px;
        }

        .sellers-sidebar {
            width: 100%;
            position: static;
            max-height: none;
        }

        .sidebar-section {
            margin-bottom: 20px;
        }

        .sellers-list,
        .categories-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }

        .seller-link,
        .category-link {
            text-align: center;
        }
    }

    @media (max-width: 768px) {
        .sellers-header {
            flex-direction: column;
            align-items: stretch;
            gap: 15px;
        }

        .sellers-controls {
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

        .sellers-grid {
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }

        .seller-list-item {
            flex-direction: column;
            text-align: center;
        }

        .seller-list-avatar {
            width: 80px;
            height: 80px;
            margin: 0 auto;
        }

        .seller-list-stats {
            justify-content: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .seller-list-meta {
            flex-direction: column;
            gap: 15px;
            align-items: center;
        }

        .sellers-list,
        .categories-list {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .sellers-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .seller-card {
            padding: 20px;
        }

        .seller-stats {
            flex-direction: column;
            gap: 10px;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        <div class="sellers-layout">
            <!-- Sellers Sidebar -->
            <div class="sellers-sidebar">
                <h3 class="sidebar-title">
                    👥 Sellers & Partners
                </h3>

                <!-- Location Search -->
                <div class="sidebar-section">
                    <div class="section-title">Tìm theo khu vực</div>
                    <form method="GET" action="">
                        <div class="location-search">
                            <input type="text" name="location" placeholder="Nhập tỉnh thành..." class="location-input"
                                value="<?php echo htmlspecialchars($location); ?>" onchange="this.form.submit()">
                            <?php if ($category_id): ?>
                            <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
                            <?php endif; ?>
                            <?php if ($seller_type !== 'all'): ?>
                            <input type="hidden" name="seller_type" value="<?php echo $seller_type; ?>">
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Seller Types Section -->
                <div class="sidebar-section">
                    <div class="section-title">Loại đối tác</div>
                    <ul class="sellers-list">
                        <li class="seller-item">
                            <a href="sellers.php"
                                class="seller-link <?php echo $seller_type === 'all' ? 'active' : ''; ?>">
                                <span class="seller-name">
                                    <span class="seller-icon">👥</span>
                                    Tất cả Seller
                                </span>
                                <span
                                    class="seller-count"><?php echo number_format($seller_counts['total_sellers']); ?></span>
                            </a>
                        </li>
                        <li class="seller-item">
                            <a href="sellers.php?seller_type=verified"
                                class="seller-link <?php echo $seller_type === 'verified' ? 'active' : ''; ?>">
                                <span class="seller-name">
                                    <span class="seller-icon">✅</span>
                                    Đã xác minh
                                </span>
                                <span
                                    class="seller-count"><?php echo number_format($seller_counts['verified_sellers']); ?></span>
                            </a>
                        </li>
                        <li class="seller-item">
                            <a href="sellers.php?seller_type=top_rated"
                                class="seller-link <?php echo $seller_type === 'top_rated' ? 'active' : ''; ?>">
                                <span class="seller-name">
                                    <span class="seller-icon">⭐</span>
                                    Đánh giá cao
                                </span>
                                <span
                                    class="seller-count"><?php echo number_format($seller_counts['top_rated_sellers']); ?></span>
                            </a>
                        </li>
                        <li class="seller-item">
                            <a href="sellers.php?seller_type=new"
                                class="seller-link <?php echo $seller_type === 'new' ? 'active' : ''; ?>">
                                <span class="seller-name">
                                    <span class="seller-icon">🆕</span>
                                    Seller mới
                                </span>
                                <span
                                    class="seller-count"><?php echo number_format($seller_counts['new_sellers']); ?></span>
                            </a>
                        </li>
                        <li class="seller-item">
                            <a href="sellers.php?seller_type=active"
                                class="seller-link <?php echo $seller_type === 'active' ? 'active' : ''; ?>">
                                <span class="seller-name">
                                    <span class="seller-icon">🔥</span>
                                    Seller tích cực
                                </span>
                                <span
                                    class="seller-count"><?php echo number_format($seller_counts['active_sellers']); ?></span>
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Categories Section -->
                <div class="sidebar-section">
                    <div class="section-title">Danh mục bán hàng</div>
                    <ul class="categories-list">
                        <?php foreach ($categories as $category): ?>
                        <li class="category-item">
                            <a href="sellers.php?category_id=<?php echo $category['id']; ?>"
                                class="category-link <?php echo $category_id == $category['id'] ? 'active' : ''; ?>">
                                <span class="category-name"><?php echo htmlspecialchars($category['name']); ?></span>
                                <span
                                    class="category-count"><?php echo number_format($category['seller_count']); ?></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- Sellers Content -->
            <div class="sellers-content">
                <!-- Sellers Header -->
                <div class="sellers-header">
                    <div>
                        <h1 class="sellers-title">
                            <?php
                            $icons = [
                                'all' => '👥',
                                'verified' => '✅',
                                'top_rated' => '⭐',
                                'new' => '🆕',
                                'active' => '🔥'
                            ];

                            $titles = [
                                'all' => 'Tất cả Seller',
                                'verified' => 'Seller đã xác minh',
                                'top_rated' => 'Seller đánh giá cao',
                                'new' => 'Seller mới',
                                'active' => 'Seller tích cực'
                            ];

                            echo $icons[$seller_type] . ' ' . $titles[$seller_type];

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
                            Hiển thị <?php echo count($sellers); ?> / <?php echo number_format($total_sellers); ?>
                            seller
                            • Trang <?php echo $page; ?> / <?php echo $total_pages; ?>
                        </div>
                    </div>

                    <div class="sellers-controls">
                        <div class="sort-controls">
                            <label>Sắp xếp:</label>
                            <select name="sort" class="sort-select" onchange="changeSort(this.value)">
                                <option value="rating_high" <?php echo $sort === 'rating_high' ? 'selected' : ''; ?>>
                                    Đánh
                                    giá cao nhất</option>
                                <option value="rating_low" <?php echo $sort === 'rating_low' ? 'selected' : ''; ?>>Đánh
                                    giá thấp nhất</option>
                                <option value="products_high"
                                    <?php echo $sort === 'products_high' ? 'selected' : ''; ?>>
                                    Nhiều sản phẩm nhất</option>
                                <option value="products_low" <?php echo $sort === 'products_low' ? 'selected' : ''; ?>>
                                    Ít
                                    sản phẩm nhất</option>
                                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Mới nhất
                                </option>
                                <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Cũ nhất
                                </option>
                                <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Tên A → Z
                                </option>
                                <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Tên Z →
                                    A
                                </option>
                            </select>

                            <label>Hiển thị:</label>
                            <select name="limit" class="limit-select" onchange="changeLimit(this.value)">
                                <option value="12" <?php echo $limit == 12 ? 'selected' : ''; ?>>12 seller</option>
                                <option value="24" <?php echo $limit == 24 ? 'selected' : ''; ?>>24 seller</option>
                                <option value="48" <?php echo $limit == 48 ? 'selected' : ''; ?>>48 seller</option>
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

                <!-- Sellers Grid View -->
                <div class="sellers-grid-view <?php echo $view === 'grid' ? 'active' : ''; ?>">
                    <?php if (!empty($sellers)): ?>
                    <div class="sellers-grid">
                        <?php foreach ($sellers as $seller): ?>
                        <?php
                                $seller_avatar = getSellerAvatar($seller);
                                $avg_rating = round($seller['avg_rating'], 1);
                                $is_verified = $seller['verified_seller'];
                                $is_new = (time() - strtotime($seller['created_at'])) < (30 * 24 * 60 * 60); // 30 days
                                $is_top_rated = $avg_rating >= 4.5;
                                ?>
                        <div class="seller-card" onclick="visitSeller(<?php echo $seller['id']; ?>)">
                            <div class="seller-avatar">
                                <?php if ($seller_avatar): ?>
                                <img src="<?php echo htmlspecialchars($seller_avatar); ?>"
                                    alt="<?php echo htmlspecialchars($seller['name']); ?>"
                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="seller-avatar-placeholder" style="display: none;">👤</div>
                                <?php else: ?>
                                <div class="seller-avatar-placeholder">👤</div>
                                <?php endif; ?>
                            </div>

                            <div class="seller-badges">
                                <?php if ($is_verified): ?>
                                <span class="seller-badge badge-verified">✅ Xác minh</span>
                                <?php endif; ?>
                                <?php if ($is_top_rated): ?>
                                <span class="seller-badge badge-top">⭐ Top</span>
                                <?php endif; ?>
                                <?php if ($is_new): ?>
                                <span class="seller-badge badge-new">🆕 Mới</span>
                                <?php endif; ?>
                            </div>

                            <div class="seller-info">
                                <h3 class="seller-name"><?php echo htmlspecialchars($seller['name']); ?></h3>

                                <?php if (!empty($seller['city']) || !empty($seller['state'])): ?>
                                <div class="seller-location">
                                    📍
                                    <?php echo htmlspecialchars(($seller['city'] ? $seller['city'] . ', ' : '') . $seller['state']); ?>
                                </div>
                                <?php endif; ?>

                                <?php if ($seller['total_reviews'] > 0): ?>
                                <div class="seller-rating">
                                    <span class="rating-stars">
                                        <?php
                                                    $full_stars = floor($avg_rating);
                                                    for ($i = 1; $i <= 5; $i++) {
                                                        echo $i <= $full_stars ? '★' : '☆';
                                                    }
                                                    ?>
                                    </span>
                                    <span class="rating-text"><?php echo $avg_rating; ?></span>
                                    <span class="rating-count">(<?php echo $seller['total_reviews']; ?>)</span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="seller-stats">
                                <div class="stat-item">
                                    <span
                                        class="stat-number"><?php echo number_format($seller['total_products']); ?></span>
                                    <span class="stat-label">Sản phẩm</span>
                                </div>
                                <div class="stat-item">
                                    <span
                                        class="stat-number"><?php echo number_format($seller['total_sales'] ?: 0); ?></span>
                                    <span class="stat-label">Đã bán</span>
                                </div>
                                <div class="stat-item">
                                    <span
                                        class="stat-number"><?php echo number_format($seller['featured_products']); ?></span>
                                    <span class="stat-label">Nổi bật</span>
                                </div>
                            </div>

                            <div class="seller-join-date">
                                Tham gia <?php echo getJoinTime($seller['created_at']); ?> trước
                            </div>

                            <div class="seller-actions">
                                <button class="action-btn btn-visit"
                                    onclick="event.stopPropagation(); visitSeller(<?php echo $seller['id']; ?>)">
                                    Xem Shop
                                </button>
                                <button class="action-btn btn-follow"
                                    onclick="event.stopPropagation(); toggleFollow(<?php echo $seller['id']; ?>)">
                                    Follow
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sellers List View -->
                <div class="sellers-list-view <?php echo $view === 'list' ? 'active' : ''; ?>">
                    <?php if (!empty($sellers)): ?>
                    <?php foreach ($sellers as $seller): ?>
                    <?php
                            $seller_avatar = getSellerAvatar($seller);
                            $avg_rating = round($seller['avg_rating'], 1);
                            ?>
                    <div class="seller-list-item" onclick="visitSeller(<?php echo $seller['id']; ?>)">
                        <div class="seller-list-avatar">
                            <?php if ($seller_avatar): ?>
                            <img src="<?php echo htmlspecialchars($seller_avatar); ?>"
                                alt="<?php echo htmlspecialchars($seller['name']); ?>"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="seller-avatar-placeholder" style="display: none;">👤</div>
                            <?php else: ?>
                            <div class="seller-avatar-placeholder">👤</div>
                            <?php endif; ?>
                        </div>

                        <div class="seller-list-content">
                            <div class="seller-list-header">
                                <h3 class="seller-list-name"><?php echo htmlspecialchars($seller['name']); ?></h3>
                                <?php if (!empty($seller['city']) || !empty($seller['state'])): ?>
                                <div class="seller-list-location">
                                    📍
                                    <?php echo htmlspecialchars(($seller['city'] ? $seller['city'] . ', ' : '') . $seller['state']); ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="seller-list-stats">
                                <div class="seller-list-stat">
                                    <span>📦</span>
                                    <span><?php echo number_format($seller['total_products']); ?> sản phẩm</span>
                                </div>
                                <div class="seller-list-stat">
                                    <span>🛒</span>
                                    <span><?php echo number_format($seller['total_sales'] ?: 0); ?> đã bán</span>
                                </div>
                                <div class="seller-list-stat">
                                    <span>⭐</span>
                                    <span><?php echo number_format($seller['featured_products']); ?> nổi bật</span>
                                </div>
                                <div class="seller-list-stat">
                                    <span>📅</span>
                                    <span>Tham gia <?php echo getJoinTime($seller['created_at']); ?></span>
                                </div>
                            </div>

                            <div class="seller-list-meta">
                                <?php if ($seller['total_reviews'] > 0): ?>
                                <div class="seller-list-rating">
                                    <span class="rating-stars">
                                        <?php
                                                    $full_stars = floor($avg_rating);
                                                    for ($i = 1; $i <= 5; $i++) {
                                                        echo $i <= $full_stars ? '★' : '☆';
                                                    }
                                                    ?>
                                    </span>
                                    <span class="rating-text"><?php echo $avg_rating; ?></span>
                                    <span class="rating-count">(<?php echo $seller['total_reviews']; ?>)</span>
                                </div>
                                <?php endif; ?>

                                <div class="seller-list-actions">
                                    <button class="action-btn btn-visit"
                                        onclick="event.stopPropagation(); visitSeller(<?php echo $seller['id']; ?>)">
                                        Xem Shop
                                    </button>
                                    <button class="action-btn btn-follow"
                                        onclick="event.stopPropagation(); toggleFollow(<?php echo $seller['id']; ?>)">
                                        Follow
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Empty State -->
                <?php if (empty($sellers)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">👥</div>
                    <h3 class="empty-state-title">Không tìm thấy seller nào</h3>
                    <p class="empty-state-description">
                        Hiện tại không có seller nào phù hợp với bộ lọc của bạn.<br>
                        Hãy thử thay đổi bộ lọc hoặc tìm kiếm theo từ khóa khác.
                    </p>
                    <button class="empty-state-action" onclick="clearFilters()">Xem tất cả seller</button>
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
    function visitSeller(sellerId) {
        window.location.href = `seller-profile.php?id=${sellerId}`;
    }

    // View switching
    function switchView(viewType) {
        const gridView = document.querySelector('.sellers-grid-view');
        const listView = document.querySelector('.sellers-list-view');
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
        localStorage.setItem('sellersViewType', viewType);
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
        window.location.href = 'sellers.php';
    }

    // Follow seller function
    function toggleFollow(sellerId) {
        const button = event.target;
        const isFollowing = button.classList.contains('active');

        fetch('toggle-follow.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    seller_id: sellerId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.following) {
                        button.classList.add('active');
                        button.textContent = 'Đang follow';
                        showNotification('Đã follow seller!', 'success');
                    } else {
                        button.classList.remove('active');
                        button.textContent = 'Follow';
                        showNotification('Đã unfollow seller!', 'info');
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
        const savedViewType = localStorage.getItem('sellersViewType');
        if (savedViewType && savedViewType !== '<?php echo $view; ?>') {
            switchView(savedViewType);
        }

        // Add smooth animations
        const cards = document.querySelectorAll('.seller-card, .seller-list-item');
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
</body>

</html>