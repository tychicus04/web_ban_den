<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Include database config
require_once '../config.php';

$db = getDBConnection();

// Authentication check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    try {
        switch ($_POST['action']) {
            case 'delete_banner':
                $banner_id = (int)$_POST['banner_id'];
                
                // First, get the image path to delete the file
                $stmt = $db->prepare("SELECT file_name FROM uploads WHERE id = ?");
                $stmt->execute([$banner_id]);
                $banner = $stmt->fetch();
                
                if ($banner && !empty($banner['file_name']) && file_exists('../' . $banner['file_name'])) {
                    unlink('../' . $banner['file_name']);
                }
                
                // Then delete the record
                $stmt = $db->prepare("DELETE FROM uploads WHERE id = ? AND type = 'banner'");
                $stmt->execute([$banner_id]);
                
                echo json_encode(['success' => true, 'message' => 'Banner đã được xóa thành công']);
                break;
                
            case 'toggle_status':
                $banner_id = (int)$_POST['banner_id'];
                $stmt = $db->prepare("UPDATE business_settings SET value = IF(value = '1', '0', '1') WHERE id = ?");
                $stmt->execute([$banner_id]);
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật trạng thái banner']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("Banner action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Pagination and filtering
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';

// Get banner types
$banner_types = [
    'home_slider' => 'Home Slider',
    'home_banner1' => 'Home Banner 1',
    'home_banner2' => 'Home Banner 2',
    'home_banner3' => 'Home Banner 3',
    'home_banner4' => 'Home Banner 4',
    'category_banner' => 'Category Banner',
    'product_banner' => 'Product Banner',
    'flash_deal_banner' => 'Flash Deal Banner',
];

// Build WHERE clause
$where_conditions = ["u.type = 'banner'"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = '(u.file_original_name LIKE ? OR bs.type LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($type_filter)) {
    $where_conditions[] = 'bs.type = ?';
    $params[] = $type_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = 'bs.value = ?';
    $params[] = ($status_filter === 'active') ? '1' : '0';
}

$where_clause = implode(' AND ', $where_conditions);

// Valid sort columns
$valid_sorts = ['id', 'file_original_name', 'type', 'created_at', 'updated_at'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'created_at';
}

$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Get banners with pagination
$banners = [];
$total_banners = 0;

try {
    // Count total banners
    $count_sql = "
        SELECT COUNT(*) as total
        FROM uploads u
        LEFT JOIN business_settings bs ON bs.value LIKE CONCAT('%', u.id, '%') AND bs.type LIKE 'banner_%'
        WHERE $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_banners = $stmt->fetch()['total'];
    
    // Get banners
    $sql = "
        SELECT u.*, bs.type as banner_type, bs.value as banner_status, bs.id as setting_id
        FROM uploads u
        LEFT JOIN business_settings bs ON bs.value LIKE CONCAT('%', u.id, '%') AND bs.type LIKE 'banner_%'
        WHERE $where_clause
        ORDER BY u.$sort $order
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $banners = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Banners fetch error: " . $e->getMessage());
    $banners = [];
}

// Calculate pagination
$total_pages = ceil($total_banners / $per_page);
$start_item = $offset + 1;
$end_item = min($offset + $per_page, $total_banners);

// Banner statistics
$stats = [];
try {
    // Total banners
    $stmt = $db->query("SELECT COUNT(*) as count FROM uploads WHERE type = 'banner'");
    $stats['total'] = $stmt->fetch()['count'];
    
    // Active banners
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM business_settings 
        WHERE type LIKE 'banner_%' AND value = '1'
    ");
    $stats['active'] = $stmt->fetch()['count'];
    
    // Home banners
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM business_settings 
        WHERE type LIKE 'home_banner%'
    ");
    $stats['home'] = $stmt->fetch()['count'];
    
    // Slider banners
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM business_settings 
        WHERE type LIKE 'home_slider%'
    ");
    $stats['slider'] = $stmt->fetch()['count'];
    
    // Category banners
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM business_settings 
        WHERE type LIKE 'category_banner%'
    ");
    $stats['category'] = $stmt->fetch()['count'];
    
} catch (PDOException $e) {
    error_log("Banner stats error: " . $e->getMessage());
    $stats = ['total' => 0, 'active' => 0, 'home' => 0, 'slider' => 0, 'category' => 0];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Banner - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý Banner - Admin <?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-banners.css">
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
                    <div class="nav-item">
                        <a href="reviews.php" class="nav-link">
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
                        <a href="banners.php" class="nav-link active">
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
                            <a href="marketing.php">Marketing</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span>Banners</span>
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
                                <div class="user-role"><?php echo htmlspecialchars($_SESSION['admin_role'] ?? 'Administrator'); ?></div>
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
                    <h1 class="page-title">Quản lý Banner</h1>
                    <p class="page-subtitle">Tạo và quản lý các banner quảng cáo trên trang web</p>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Tổng số Banner</div>
                            <div class="stat-icon">🖼️</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Đang hoạt động</div>
                            <div class="stat-icon">✅</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['active']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Banner trang chủ</div>
                            <div class="stat-icon">🏠</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['home']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Slider</div>
                            <div class="stat-icon">🔄</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['slider']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Banner danh mục</div>
                            <div class="stat-icon">📂</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['category']); ?></div>
                    </div>
                </div>
                
                <!-- Toolbar -->
                <div class="toolbar">
                    <div class="toolbar-row">
                        <div class="toolbar-left">
                            <div class="search-box">
                                <span class="search-icon">🔍</span>
                                <input 
                                    type="search" 
                                    class="search-input" 
                                    placeholder="Tìm kiếm banner..."
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    id="search-input"
                                >
                            </div>
                        </div>
                        <div class="toolbar-right">
                            <a href="banner-edit.php" class="btn btn-primary">
                                <span>➕</span>
                                <span>Tạo Banner mới</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="toolbar-row">
                        <div class="toolbar-left">
                            <select class="filter-select" id="type-filter">
                                <option value="">Tất cả loại banner</option>
                                <?php foreach ($banner_types as $key => $value): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $type_filter === $key ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($value); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select class="filter-select" id="status-filter">
                                <option value="">Tất cả trạng thái</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Không hoạt động</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Banners Table -->
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="sortable <?php echo $sort === 'id' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="id">
                                    ID
                                </th>
                                <th>Banner</th>
                                <th>Loại</th>
                                <th>Kích thước</th>
                                <th>Trạng thái</th>
                                <th class="sortable <?php echo $sort === 'created_at' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="created_at">
                                    Ngày tạo
                                </th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($banners)): ?>
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        <div class="empty-icon">🖼️</div>
                                        <div class="empty-title">Chưa có Banner nào</div>
                                        <div class="empty-description">Hãy tạo banner đầu tiên của bạn</div>
                                        <a href="banner-edit.php" class="btn btn-primary">
                                            <span>➕</span>
                                            <span>Tạo Banner mới</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($banners as $banner): ?>
                                    <?php
                                    // Extract banner type for display
                                    $banner_type_key = str_replace('banner_', '', $banner['banner_type'] ?? '');
                                    $banner_type_display = $banner_types[$banner_type_key] ?? 'Không xác định';
                                    
                                    // Determine status badge class
                                    $status_class = 'inactive';
                                    if ($banner['banner_status'] == '1') {
                                        $status_class = 'active';
                                    }
                                    
                                    // Determine type badge class
                                    $type_class = 'home';
                                    if (strpos($banner_type_key, 'slider') !== false) {
                                        $type_class = 'slider';
                                    } elseif (strpos($banner_type_key, 'category') !== false) {
                                        $type_class = 'category';
                                    }
                                    
                                    // Get image dimensions if available
                                    $dimensions = '';
                                    if (!empty($banner['file_name']) && file_exists('../' . $banner['file_name'])) {
                                        $size = getimagesize('../' . $banner['file_name']);
                                        if ($size) {
                                            $dimensions = $size[0] . 'x' . $size[1];
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="banner-id">#<?php echo $banner['id']; ?></span>
                                        </td>
                                        <td>
                                            <div class="banner-preview">
                                                <img 
                                                    src="<?php echo !empty($banner['file_name']) && file_exists('../' . $banner['file_name']) ? '../' . htmlspecialchars($banner['file_name']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100" viewBox="0 0 200 100"><rect width="200" height="100" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="24" fill="%236b7280">🖼️</text></svg>'; ?>" 
                                                    alt="<?php echo htmlspecialchars($banner['file_original_name'] ?? 'Banner'); ?>"
                                                    class="banner-image"
                                                    loading="lazy"
                                                >
                                                <?php if (!empty($banner['external_link'])): ?>
                                                    <div class="banner-link" title="<?php echo htmlspecialchars($banner['external_link']); ?>">
                                                        <?php echo htmlspecialchars($banner['external_link']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $type_class; ?>">
                                                <?php echo htmlspecialchars($banner_type_display); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $dimensions ?: 'Không xác định'; ?>
                                            <br>
                                            <small style="color: var(--text-tertiary);">
                                                <?php echo $banner['file_size'] ? round($banner['file_size'] / 1024, 2) . ' KB' : ''; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $banner['banner_status'] == '1' ? 'Hoạt động' : 'Không hoạt động'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('d/m/Y', strtotime($banner['created_at'])); ?>
                                            <br>
                                            <small style="color: var(--text-tertiary);">
                                                <?php echo date('H:i', strtotime($banner['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn edit" onclick="editBanner(<?php echo $banner['id']; ?>)" title="Chỉnh sửa">
                                                    ✏️
                                                </button>
                                                <button class="action-btn delete" onclick="deleteBanner(<?php echo $banner['id']; ?>)" title="Xóa">
                                                    🗑️
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_banners > 0): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Hiển thị <?php echo number_format($start_item); ?>-<?php echo number_format($end_item); ?> trong tổng số <?php echo number_format($total_banners); ?> banner
                        </div>
                        
                        <div class="pagination-nav">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="pagination-btn">‹‹</a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-btn">‹</a>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                   class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-btn">›</a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="pagination-btn">››</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Banner Positions Preview -->
                <div class="section-header" style="margin-top: var(--space-8); margin-bottom: var(--space-4);">
                    <h2>Vị trí Banner trên trang web</h2>
                    <p style="color: var(--text-secondary); font-size: var(--text-sm);">Xem trước vị trí hiển thị của các banner trên trang web</p>
                </div>
                
                <div class="banner-grid">
                    <div class="banner-card">
                        <img src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='100%' height='150' viewBox='0 0 300 150'><rect width='300' height='150' fill='%23667eea'/><text x='50%' y='50%' text-anchor='middle' dy='.3em' font-family='Arial' font-size='24' fill='white'>Home Slider</text></svg>" class="banner-card-image">
                        <div class="banner-card-content">
                            <div class="banner-card-title">Slider trang chủ</div>
                            <div class="banner-card-meta">
                                <span>Kích thước: 1920x500</span>
                                <span class="status-badge slider">Slider</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="banner-card">
                        <img src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='100%' height='150' viewBox='0 0 300 150'><rect width='300' height='150' fill='%23f5576c'/><text x='50%' y='50%' text-anchor='middle' dy='.3em' font-family='Arial' font-size='24' fill='white'>Home Banner 1</text></svg>" class="banner-card-image">
                        <div class="banner-card-content">
                            <div class="banner-card-title">Banner trang chủ 1</div>
                            <div class="banner-card-meta">
                                <span>Kích thước: 380x190</span>
                                <span class="status-badge home">Home</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="banner-card">
                        <img src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='100%' height='150' viewBox='0 0 300 150'><rect width='300' height='150' fill='%234facfe'/><text x='50%' y='50%' text-anchor='middle' dy='.3em' font-family='Arial' font-size='24' fill='white'>Home Banner 2</text></svg>" class="banner-card-image">
                        <div class="banner-card-content">
                            <div class="banner-card-title">Banner trang chủ 2</div>
                            <div class="banner-card-meta">
                                <span>Kích thước: 380x190</span>
                                <span class="status-badge home">Home</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="banner-card">
                        <img src="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='100%' height='150' viewBox='0 0 300 150'><rect width='300' height='150' fill='%23f59e0b'/><text x='50%' y='50%' text-anchor='middle' dy='.3em' font-family='Arial' font-size='24' fill='white'>Category Banner</text></svg>" class="banner-card-image">
                        <div class="banner-card-content">
                            <div class="banner-card-title">Banner danh mục</div>
                            <div class="banner-card-meta">
                                <span>Kích thước: 1170x220</span>
                                <span class="status-badge category">Category</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
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
        
        // Search functionality
        const searchInput = document.getElementById('search-input');
        let searchTimeout;
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                updateFilters();
            }, 500);
        });
        
        // Filter functionality
        document.getElementById('type-filter').addEventListener('change', updateFilters);
        document.getElementById('status-filter').addEventListener('change', updateFilters);
        
        function updateFilters() {
            const params = new URLSearchParams();
            
            const search = searchInput.value.trim();
            if (search) params.set('search', search);
            
            const type = document.getElementById('type-filter').value;
            if (type) params.set('type', type);
            
            const status = document.getElementById('status-filter').value;
            if (status) params.set('status', status);
            
            // Preserve current sort
            const urlParams = new URLSearchParams(window.location.search);
            const currentSort = urlParams.get('sort');
            const currentOrder = urlParams.get('order');
            
            if (currentSort) params.set('sort', currentSort);
            if (currentOrder) params.set('order', currentOrder);
            
            params.set('page', '1'); // Reset to first page
            
            window.location.search = params.toString();
        }
        
        // Sorting functionality
        document.querySelectorAll('.sortable').forEach(th => {
            th.addEventListener('click', function() {
                const sortBy = this.dataset.sort;
                const urlParams = new URLSearchParams(window.location.search);
                const currentSort = urlParams.get('sort');
                const currentOrder = urlParams.get('order');
                
                let newOrder = 'DESC';
                if (currentSort === sortBy && currentOrder === 'DESC') {
                    newOrder = 'ASC';
                }
                
                urlParams.set('sort', sortBy);
                urlParams.set('order', newOrder);
                urlParams.set('page', '1');
                
                window.location.search = urlParams.toString();
            });
        });
        
        // AJAX helper function
        async function makeRequest(action, data) {
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
        
        // Banner actions
        function editBanner(bannerId) {
            window.location.href = `banner-edit.php?id=${bannerId}`;
        }
        
        async function deleteBanner(bannerId) {
            if (!confirm('Bạn có chắc chắn muốn xóa banner này?')) {
                return;
            }
            
            const success = await makeRequest('delete_banner', { banner_id: bannerId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function toggleStatus(bannerId) {
            const success = await makeRequest('toggle_status', { banner_id: bannerId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? 'var(--success)' : type === 'error' ? 'var(--danger)' : 'var(--primary)'};
                color: white;
                padding: var(--space-4) var(--space-5);
                border-radius: var(--rounded-lg);
                box-shadow: var(--shadow-xl);
                z-index: 9999;
                transform: translateX(400px);
                transition: transform 0.3s ease;
                max-width: 350px;
                font-weight: var(--font-medium);
            `;
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
        
        // Responsive handling
        function handleResponsive() {
            const isDesktop = window.innerWidth > 1024;
            
            if (isDesktop && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
            
            if (!isDesktop && sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
            }
        }
        
        window.addEventListener('resize', handleResponsive);
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Banner Management - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Banner Management - Ready!');
            console.log('📊 Banner count:', <?php echo $total_banners; ?>);
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
            }
            
            // Escape to blur search
            if (e.key === 'Escape' && document.activeElement === searchInput) {
                searchInput.blur();
            }
            
            // Ctrl/Cmd + N for new banner
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'banner-edit.php';
            }
        });
    </script>
</body>
</html>