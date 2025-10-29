<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Include database config
require_once '../config.php';

$db = getDBConnection();

// Authentication check
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

// Session timeout check (8 hours)
$session_timeout = 8 * 60 * 60; // 8 hours
if (isset($_SESSION['admin_login_time']) && (time() - $_SESSION['admin_login_time']) > $session_timeout) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}

// CSRF token validation
if (!isset($_SESSION['admin_token'])) {
    $_SESSION['admin_token'] = bin2hex(random_bytes(32));
}

// Get admin info
$admin = null;
try {
    $stmt = $db->prepare("
        SELECT u.*, s.id as staff_id, r.name as role_name
        FROM users u 
        LEFT JOIN staff s ON u.id = s.user_id
        LEFT JOIN roles r ON s.role_id = r.id
        WHERE u.id = ? LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch();
    
    if (!$admin) {
        session_destroy();
        header('Location: login.php?error=user_not_found');
        exit;
    }
} catch (PDOException $e) {
    error_log("Admin fetch error: " . $e->getMessage());
    header('Location: login.php?error=database_error');
    exit;
}

// Get business settings
function getBusinessSetting($db, $type, $default = '') {
    try {
        $stmt = $db->prepare("SELECT value FROM business_settings WHERE type = ? LIMIT 1");
        $stmt->execute([$type]);
        $result = $stmt->fetch();
        return $result ? $result['value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

// Pagination setup
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;
$offset = ($page - 1) * $per_page;

// Search/filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$seller_id = isset($_GET['seller_id']) ? intval($_GET['seller_id']) : 0;
$rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$status = isset($_GET['status']) ? intval($_GET['status']) : -1; // -1 = all, 0 = pending, 1 = approved

// Handle review actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    if (!isset($_POST['review_id']) || !is_numeric($_POST['review_id'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid review ID']);
        exit;
    }
    
    $review_id = intval($_POST['review_id']);
    
    try {
        switch ($_POST['action']) {
            case 'approve_review':
                $stmt = $db->prepare("UPDATE reviews SET status = 1 WHERE id = ?");
                $stmt->execute([$review_id]);
                echo json_encode(['success' => true, 'message' => 'Đánh giá đã được duyệt']);
                break;
                
            case 'reject_review':
                $stmt = $db->prepare("UPDATE reviews SET status = 0 WHERE id = ?");
                $stmt->execute([$review_id]);
                echo json_encode(['success' => true, 'message' => 'Đã từ chối đánh giá']);
                break;
                
            case 'delete_review':
                $stmt = $db->prepare("DELETE FROM reviews WHERE id = ?");
                $stmt->execute([$review_id]);
                echo json_encode(['success' => true, 'message' => 'Đánh giá đã được xóa']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("Review action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Build the query based on filters
$params = [];
$query = "
    SELECT r.*,
           p.name as product_name, p.thumbnail_img as product_thumbnail, p.unit_price as product_price,
           u.name as user_name, u.email as user_email,
           seller.name as shop_name, seller.id as shop_id
    FROM reviews r
    JOIN products p ON r.product_id = p.id
    JOIN users u ON r.user_id = u.id
    LEFT JOIN users seller ON p.user_id = seller.id
    WHERE 1=1
";

if (!empty($search)) {
    $query .= " AND (r.comment LIKE ? OR p.name LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    array_push($params, $search_param, $search_param, $search_param, $search_param);
}

if ($product_id > 0) {
    $query .= " AND r.product_id = ?";
    $params[] = $product_id;
}

if ($user_id > 0) {
    $query .= " AND r.user_id = ?";
    $params[] = $user_id;
}

if ($seller_id > 0) {
    $query .= " AND p.user_id = ?";
    $params[] = $seller_id;
}

if ($rating > 0) {
    $query .= " AND r.rating = ?";
    $params[] = $rating;
}

if ($status >= 0) {
    $query .= " AND r.status = ?";
    $params[] = $status;
}

// Count total records for pagination
$count_query = str_replace("r.*,\n           p.name as product_name, p.thumbnail_img as product_thumbnail, p.unit_price as product_price,\n           u.name as user_name, u.email as user_email,\n           seller.name as shop_name, seller.id as shop_id", "COUNT(*) as total", $query);
$stmt = $db->prepare($count_query);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'] ?? 0;
$total_pages = ceil($total_records / $per_page);

// Order by most recent first
$query .= " ORDER BY r.created_at DESC";

// Add pagination
$query .= " LIMIT $per_page OFFSET $offset";

// Fetch reviews
try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $reviews = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Review fetch error: " . $e->getMessage());
    $reviews = [];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');

// Currency format function
function formatCurrency($amount, $currency = 'VND') {
    if ($currency === 'VND') {
        return number_format($amount, 0, ',', '.') . '₫';
    } else {
        return '$' . number_format($amount, 2, '.', ',');
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý đánh giá - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý đánh giá - Admin <?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-reviews.css">
</head>

<body>
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">A</div>
                <h1 class="sidebar-title">Admin Panel</h1>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tổng quan</div>
                    <div class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="analytics.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Phân tích</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Bán hàng</div>
                    <div class="nav-item">
                        <a href="orders.php" class="nav-link">
                            <span class="nav-icon">📦</span>
                            <span class="nav-text">Đơn hàng</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="products.php" class="nav-link">
                            <span class="nav-icon">🛍️</span>
                            <span class="nav-text">Sản phẩm</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="categories.php" class="nav-link">
                            <span class="nav-icon">📂</span>
                            <span class="nav-text">Danh mục</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="brands.php" class="nav-link">
                            <span class="nav-icon">🏷️</span>
                            <span class="nav-text">Thương hiệu</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Khách hàng</div>
                    <div class="nav-item">
                        <a href="users.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Người dùng</span>
                        </a>
                    </div>   
                    <div class="nav-item">
                        <a href="sellers.php" class="nav-link">
                            <span class="nav-icon">🏪</span>
                            <span class="nav-text">Cửa hàng</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="reviews.php" class="nav-link active">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Đánh giá</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="contacts.php" class="nav-link">
                            <span class="nav-icon">💬</span>
                            <span class="nav-text">Liên hệ</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Marketing</div>
                    <div class="nav-item">
                        <a href="coupons.php" class="nav-link">
                            <span class="nav-icon">🎫</span>
                            <span class="nav-text">Mã giảm giá</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="flash-deals.php" class="nav-link">
                            <span class="nav-icon">⚡</span>
                            <span class="nav-text">Flash Deals</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="banners.php" class="nav-link">
                            <span class="nav-icon">🖼️</span>
                            <span class="nav-text">Banner</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Hệ thống</div>
                    <div class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <span class="nav-icon">⚙️</span>
                            <span class="nav-text">Cài đặt</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="staff.php" class="nav-link">
                            <span class="nav-icon">👨‍💼</span>
                            <span class="nav-text">Nhân viên</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="backups.php" class="nav-link">
                            <span class="nav-icon">💾</span>
                            <span class="nav-text">Sao lưu</span>
                        </a>
                    </div>
                </div>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle sidebar">
                        ☰
                    </button>
                    <nav class="breadcrumb" aria-label="Breadcrumb">
                        <div class="breadcrumb-item">
                            <a href="dashboard.php">Admin</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span>Đánh giá</span>
                        </div>
                    </nav>
                </div>
                
                <div class="header-right">
                    <div class="user-menu">
                        <button class="user-button">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($admin['name'] ?? 'A', 0, 2)); ?>
                            </div>
                            <div class="user-info">
                                <div class="user-name"><?php echo htmlspecialchars($admin['name'] ?? 'Admin'); ?></div>
                                <div class="user-role"><?php echo htmlspecialchars($admin['role_name'] ?? 'Administrator'); ?></div>
                            </div>
                            <span>▼</span>
                        </button>
                    </div>
                </div>
            </header>
            
            <!-- Content -->
            <div class="content">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="page-title-wrapper">
                        <h1 class="page-title">Quản lý đánh giá</h1>
                        <p class="page-subtitle">Quản lý tất cả đánh giá từ khách hàng</p>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="filter-section">
                    <form action="" method="GET" class="filter-form">
                        <div class="form-group">
                            <label for="search" class="form-label">Tìm kiếm</label>
                            <input type="text" id="search" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Tìm theo nội dung, sản phẩm, người dùng...">
                        </div>
                        
                        <div class="form-group">
                            <label for="rating" class="form-label">Đánh giá</label>
                            <select id="rating" name="rating" class="form-control">
                                <option value="0" <?php echo $rating === 0 ? 'selected' : ''; ?>>Tất cả đánh giá</option>
                                <option value="5" <?php echo $rating === 5 ? 'selected' : ''; ?>>5 sao</option>
                                <option value="4" <?php echo $rating === 4 ? 'selected' : ''; ?>>4 sao</option>
                                <option value="3" <?php echo $rating === 3 ? 'selected' : ''; ?>>3 sao</option>
                                <option value="2" <?php echo $rating === 2 ? 'selected' : ''; ?>>2 sao</option>
                                <option value="1" <?php echo $rating === 1 ? 'selected' : ''; ?>>1 sao</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="status" class="form-label">Trạng thái</label>
                            <select id="status" name="status" class="form-control">
                                <option value="-1" <?php echo $status === -1 ? 'selected' : ''; ?>>Tất cả trạng thái</option>
                                <option value="1" <?php echo $status === 1 ? 'selected' : ''; ?>>Đã duyệt</option>
                                <option value="0" <?php echo $status === 0 ? 'selected' : ''; ?>>Chưa duyệt/Từ chối</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="per_page" class="form-label">Hiển thị</label>
                            <select id="per_page" name="per_page" class="form-control">
                                <option value="20" <?php echo $per_page === 20 ? 'selected' : ''; ?>>20 đánh giá</option>
                                <option value="50" <?php echo $per_page === 50 ? 'selected' : ''; ?>>50 đánh giá</option>
                                <option value="100" <?php echo $per_page === 100 ? 'selected' : ''; ?>>100 đánh giá</option>
                            </select>
                        </div>
                        
                        <div class="form-group" style="display: flex; align-items: flex-end;">
                            <button type="submit" class="btn btn-primary">
                                <span>🔍</span>
                                <span>Tìm kiếm</span>
                            </button>
                            
                            <a href="reviews.php" class="btn btn-secondary" style="margin-left: 10px;">
                                <span>🔄</span>
                                <span>Đặt lại</span>
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Reviews Table -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Danh sách đánh giá (<?php echo $total_records; ?>)</h2>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reviews)): ?>
                            <div style="text-align: center; padding: var(--space-10) 0; color: var(--text-tertiary);">
                                Không tìm thấy đánh giá nào
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Sản phẩm</th>
                                            <th>Người dùng</th>
                                            <th>Đánh giá</th>
                                            <th>Nội dung</th>
                                            <th>Cửa hàng</th>
                                            <th>Ngày tạo</th>
                                            <th>Trạng thái</th>
                                            <th>Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reviews as $review): ?>
                                            <tr>
                                                <td>#<?php echo $review['id']; ?></td>
                                                <td>
                                                    <div class="product-info">
                                                        <?php if (isset($review['product_thumbnail']) && $review['product_thumbnail']): ?>
                                                            <img src="<?php echo htmlspecialchars($review['product_thumbnail']); ?>" alt="Product thumbnail" class="product-image">
                                                        <?php else: ?>
                                                            <div class="product-image">🖼️</div>
                                                        <?php endif; ?>
                                                        <div class="product-details">
                                                            <div class="product-name"><?php echo htmlspecialchars($review['product_name'] ?? ''); ?></div>
                                                            <div class="product-price"><?php echo formatCurrency($review['product_price'] ?? 0); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="user-info-box">
                                                        <div class="user-image"><?php echo strtoupper(substr($review['user_name'] ?? 'U', 0, 2)); ?></div>
                                                        <div class="user-details">
                                                            <div class="user-name-text"><?php echo htmlspecialchars($review['user_name'] ?? ''); ?></div>
                                                            <div class="user-email"><?php echo htmlspecialchars($review['user_email'] ?? ''); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="rating-stars">
                                                        <?php 
                                                        $rating = $review['rating'] ?? 0;
                                                        for ($i = 1; $i <= 5; $i++) {
                                                            echo $i <= $rating ? '★' : '☆';
                                                        }
                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="review-content">
                                                        <?php 
                                                        $comment = $review['comment'] ?? '';
                                                        echo htmlspecialchars(mb_substr($comment, 0, 50)) . (mb_strlen($comment) > 50 ? '...' : '');
                                                        ?>
                                                    </div>
                                                    <div class="photos-preview">
                                                        <?php if (isset($review['photos']) && $review['photos']): ?>
                                                            <?php 
                                                            $photos = json_decode($review['photos'], true);
                                                            if (is_array($photos)):
                                                                foreach (array_slice($photos, 0, 3) as $photo):
                                                            ?>
                                                                <img src="<?php echo htmlspecialchars($photo); ?>" alt="Review photo" class="photo-thumbnail" onclick="viewReviewDetails(<?php echo $review['id']; ?>)">
                                                            <?php 
                                                                endforeach;
                                                                if (count($photos) > 3):
                                                            ?>
                                                                <div class="photo-thumbnail" style="display: flex; align-items: center; justify-content: center; background: var(--gray-200);">+<?php echo count($photos) - 3; ?></div>
                                                            <?php
                                                                endif;
                                                            endif;
                                                            ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (isset($review['shop_name']) && $review['shop_name']): ?>
                                                        <a href="shop-details.php?id=<?php echo $review['shop_id']; ?>"><?php echo htmlspecialchars($review['shop_name']); ?></a>
                                                    <?php else: ?>
                                                        <span style="color: var(--text-tertiary);">Không có</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo isset($review['created_at']) ? date('d/m/Y H:i', strtotime($review['created_at'])) : ''; ?></td>
                                                <td>
                                                    <?php if (isset($review['status']) && $review['status'] == 1): ?>
                                                        <span class="status-badge approved">Đã duyệt</span>
                                                    <?php else: ?>
                                                        <span class="status-badge rejected">Chưa duyệt</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-secondary btn-sm" onclick="viewReviewDetails(<?php echo $review['id']; ?>)">
                                                            <span>👁️</span>
                                                        </button>
                                                        
                                                        <?php if (isset($review['status']) && $review['status'] == 0): ?>
                                                            <button class="btn btn-success btn-sm" onclick="approveReview(<?php echo $review['id']; ?>)">
                                                                <span>✅</span>
                                                            </button>
                                                        <?php else: ?>
                                                            <button class="btn btn-warning btn-sm" onclick="rejectReview(<?php echo $review['id']; ?>)">
                                                                <span>❌</span>
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <button class="btn btn-danger btn-sm" onclick="deleteReview(<?php echo $review['id']; ?>)">
                                                            <span>🗑️</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <div class="pagination">
                                    <?php
                                    // Previous page link
                                    if ($page > 1) {
                                        echo '<a href="?page=' . ($page - 1) . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&rating=' . $rating . '&status=' . $status . '&product_id=' . $product_id . '&user_id=' . $user_id . '&seller_id=' . $seller_id . '" class="pagination-link">«</a>';
                                    }
                                    
                                    // Page numbers
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if ($start_page > 1) {
                                        echo '<a href="?page=1&per_page=' . $per_page . '&search=' . urlencode($search) . '&rating=' . $rating . '&status=' . $status . '&product_id=' . $product_id . '&user_id=' . $user_id . '&seller_id=' . $seller_id . '" class="pagination-link">1</a>';
                                        if ($start_page > 2) {
                                            echo '<span class="pagination-ellipsis">…</span>';
                                        }
                                    }
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++) {
                                        echo '<a href="?page=' . $i . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&rating=' . $rating . '&status=' . $status . '&product_id=' . $product_id . '&user_id=' . $user_id . '&seller_id=' . $seller_id . '" class="pagination-link' . ($i == $page ? ' active' : '') . '">' . $i . '</a>';
                                    }
                                    
                                    if ($end_page < $total_pages) {
                                        if ($end_page < $total_pages - 1) {
                                            echo '<span class="pagination-ellipsis">…</span>';
                                        }
                                        echo '<a href="?page=' . $total_pages . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&rating=' . $rating . '&status=' . $status . '&product_id=' . $product_id . '&user_id=' . $user_id . '&seller_id=' . $seller_id . '" class="pagination-link">' . $total_pages . '</a>';
                                    }
                                    
                                    // Next page link
                                    if ($page < $total_pages) {
                                        echo '<a href="?page=' . ($page + 1) . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&rating=' . $rating . '&status=' . $status . '&product_id=' . $product_id . '&user_id=' . $user_id . '&seller_id=' . $seller_id . '" class="pagination-link">»</a>';
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Review Details Modal -->
    <div id="reviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Chi tiết đánh giá</h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalContent">
                <!-- Content will be loaded dynamically -->
                <div style="text-align: center; padding: var(--space-6);">
                    <p>Đang tải...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Đóng</button>
                <div id="reviewActions" class="btn-group"></div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle functionality
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        
        sidebarToggle.addEventListener('click', function() {
            if (window.innerWidth > 1024) {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            } else {
                sidebar.classList.toggle('open');
            }
        });
        
        // Restore sidebar state
        if (window.innerWidth > 1024 && localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
        }
        
        // Close sidebar on mobile when clicking outside
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 1024 && 
                !sidebar.contains(e.target) && 
                !sidebarToggle.contains(e.target) &&
                sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
        });
        
        // Modal functionality
        const modal = document.getElementById('reviewModal');
        const modalContent = document.getElementById('modalContent');
        const reviewActions = document.getElementById('reviewActions');
        
        function viewReviewDetails(reviewId) {
            // Here we would usually fetch the review details from the server
            // For now, we'll simulate it with the data we already have on the page
            const reviewRow = document.querySelector(`tr td:first-child:contains("#${reviewId}")`).parentNode;
            if (!reviewRow) return;
            
            const productInfo = reviewRow.querySelector('.product-info').cloneNode(true);
            const userInfo = reviewRow.querySelector('.user-info-box').cloneNode(true);
            const rating = reviewRow.querySelector('.rating-stars').cloneNode(true);
            const content = reviewRow.querySelector('.review-content').textContent.trim();
            const date = reviewRow.cells[6].textContent.trim();
            const status = reviewRow.cells[7].querySelector('.status-badge').textContent.trim();
            
            // Get shop info
            const shopCell = reviewRow.cells[5];
            let shopInfo = 'Không có';
            if (shopCell.querySelector('a')) {
                shopInfo = shopCell.querySelector('a').textContent.trim();
            }
            
            // Build modal content
            let modalHtml = `
                <div style="margin-bottom: 20px;">
                    <h4 style="margin-bottom: 10px;">Thông tin sản phẩm</h4>
                    <div style="display: flex; align-items: flex-start; gap: 15px;">
                        <div style="flex: 1;">${productInfo.outerHTML}</div>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h4 style="margin-bottom: 10px;">Thông tin người dùng</h4>
                    <div>${userInfo.outerHTML}</div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h4 style="margin-bottom: 10px;">Đánh giá</h4>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div>${rating.outerHTML}</div>
                        <div>(${date})</div>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h4 style="margin-bottom: 10px;">Nội dung đánh giá</h4>
                    <div style="background: var(--gray-50); padding: 15px; border-radius: 8px; white-space: pre-wrap;">${content}</div>
                </div>
            `;
            
            // Add photos if available
            const photoPreview = reviewRow.querySelector('.photos-preview');
            if (photoPreview && photoPreview.children.length > 0) {
                modalHtml += `
                    <div style="margin-bottom: 20px;">
                        <h4 style="margin-bottom: 10px;">Hình ảnh</h4>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                `;
                
                Array.from(photoPreview.children).forEach(photo => {
                    if (photo.tagName === 'IMG') {
                        modalHtml += `<img src="${photo.src}" alt="Review photo" style="width: 100px; height: 100px; object-fit: cover; border-radius: 8px;">`;
                    }
                });
                
                modalHtml += `
                        </div>
                    </div>
                `;
            }
            
            // Add shop info
            modalHtml += `
                <div style="margin-bottom: 20px;">
                    <h4 style="margin-bottom: 10px;">Cửa hàng</h4>
                    <div>${shopInfo}</div>
                </div>
                
                <div>
                    <h4 style="margin-bottom: 10px;">Trạng thái</h4>
                    <div>
                        <span class="status-badge ${status.toLowerCase() === 'đã duyệt' ? 'approved' : 'rejected'}">${status}</span>
                    </div>
                </div>
            `;
            
            modalContent.innerHTML = modalHtml;
            
            // Set action buttons
            reviewActions.innerHTML = '';
            
            if (status.toLowerCase() === 'đã duyệt') {
                reviewActions.innerHTML += `
                    <button class="btn btn-warning" onclick="rejectReview(${reviewId})">
                        <span>❌</span>
                        <span>Từ chối</span>
                    </button>
                `;
            } else {
                reviewActions.innerHTML += `
                    <button class="btn btn-success" onclick="approveReview(${reviewId})">
                        <span>✅</span>
                        <span>Duyệt</span>
                    </button>
                `;
            }
            
            reviewActions.innerHTML += `
                <button class="btn btn-danger" onclick="deleteReview(${reviewId})">
                    <span>🗑️</span>
                    <span>Xóa</span>
                </button>
            `;
            
            modal.style.display = 'block';
        }
        
        function closeModal() {
            modal.style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });
        
        // Review actions
        async function approveReview(reviewId) {
            if (!confirm('Bạn có chắc chắn muốn duyệt đánh giá này?')) {
                return;
            }
            
            const success = await makeRequest('approve_review', { review_id: reviewId });
            if (success) {
                closeModal();
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function rejectReview(reviewId) {
            if (!confirm('Bạn có chắc chắn muốn từ chối đánh giá này?')) {
                return;
            }
            
            const success = await makeRequest('reject_review', { review_id: reviewId });
            if (success) {
                closeModal();
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function deleteReview(reviewId) {
            if (!confirm('Bạn có chắc chắn muốn xóa đánh giá này? Hành động này không thể hoàn tác.')) {
                return;
            }
            
            const success = await makeRequest('delete_review', { review_id: reviewId });
            if (success) {
                closeModal();
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // AJAX helper function
        async function makeRequest(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
            
            Object.keys(data).forEach(key => {
                formData.append(key, data[key]);
            });
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                    return true;
                } else {
                    showNotification(result.message, 'error');
                    return false;
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
                return false;
            }
        }
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
                setTimeout(() => {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }
        
        // Helper function for finding parent tr
        Element.prototype.contains = function(text) {
            return this.textContent.includes(text);
        };
        
        // Form submission for filters
        document.getElementById('per_page').addEventListener('change', function() {
            this.form.submit();
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Reviews Management - Initializing...');
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Reviews Management - Ready!');
        });
    </script>
</body>
</html>