<?php
/**
 * Admin Users Page
 *
 * @refactored Uses centralized admin_init.php for authentication and helpers
 */

// Initialize admin page with authentication and admin info
require_once __DIR__ . '/../includes/admin_init.php';
$admin = initAdminPage(true, true);
$db = getDB();

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
            case 'ban_user':
                $user_id = (int)$_POST['user_id'];
                
                // Don't allow banning yourself
                if ($user_id == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Không thể cấm tài khoản của chính mình']);
                    break;
                }
                
                $stmt = $db->prepare("UPDATE users SET banned = 1 WHERE id = ?");
                $stmt->execute([$user_id]);
                echo json_encode(['success' => true, 'message' => 'Đã cấm người dùng thành công']);
                break;
                
            case 'unban_user':
                $user_id = (int)$_POST['user_id'];
                $stmt = $db->prepare("UPDATE users SET banned = 0 WHERE id = ?");
                $stmt->execute([$user_id]);
                echo json_encode(['success' => true, 'message' => 'Đã bỏ cấm người dùng thành công']);
                break;
                
            case 'delete_user':
                $user_id = (int)$_POST['user_id'];
                
                // Don't allow deleting yourself
                if ($user_id == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Không thể xóa tài khoản của chính mình']);
                    break;
                }
                
                // Begin transaction
                $db->beginTransaction();
                
                try {
                    // Check if user has orders
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    $has_orders = $stmt->fetch()['count'] > 0;
                    
                    if ($has_orders) {
                        // Soft delete - anonymize user data but keep records
                        $stmt = $db->prepare("
                            UPDATE users 
                            SET name = CONCAT('Deleted User ', id), 
                                email = CONCAT('deleted_', id, '@example.com'), 
                                phone = NULL, 
                                banned = 1, 
                                avatar = NULL, 
                                avatar_original = NULL
                            WHERE id = ?
                        ");
                        $stmt->execute([$user_id]);
                        
                        // Delete sensitive user data
                        $stmt = $db->prepare("DELETE FROM addresses WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        
                        // Remove from wishlist
                        $stmt = $db->prepare("DELETE FROM wishlists WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        
                    } else {
                        // Hard delete - complete removal
                        // Delete related records first
                        $tables = [
                            'addresses', 'carts', 'wishlists', 'club_points', 
                            'wallets', 'customer_package_payments', 'affiliate_logs',
                            'affiliate_users', 'conversations', 'reviews'
                        ];
                        
                        foreach ($tables as $table) {
                            $stmt = $db->prepare("DELETE FROM $table WHERE user_id = ?");
                            $stmt->execute([$user_id]);
                        }
                        
                        // Delete user
                        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                    }
                    
                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'Đã xóa người dùng thành công']);
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                break;
                
            case 'get_user':
                $user_id = (int)$_POST['user_id'];
                
                $stmt = $db->prepare("
                    SELECT u.*,
                           COALESCE(cu.id, 0) as is_affiliate,
                           COALESCE(cu.balance, 0) as affiliate_balance
                    FROM users u
                    LEFT JOIN affiliate_users cu ON u.id = cu.user_id
                    WHERE u.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    echo json_encode(['success' => false, 'message' => 'Không tìm thấy người dùng']);
                    break;
                }
                
                echo json_encode(['success' => true, 'user' => $user]);
                break;
                
            case 'update_user':
                $user_id = (int)$_POST['user_id'];
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $user_type = trim($_POST['user_type'] ?? 'customer');
                
                // Validation
                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Tên không được để trống']);
                    break;
                }
                
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Email không hợp lệ']);
                    break;
                }
                
                // Check if email is already used by another user
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $stmt->execute([$email, $user_id]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Email đã được sử dụng bởi người dùng khác']);
                    break;
                }
                
                // Begin transaction
                $db->beginTransaction();
                
                try {
                    // Update user
                    $stmt = $db->prepare("
                        UPDATE users 
                        SET name = ?, 
                            email = ?, 
                            phone = ?, 
                            user_type = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $email, $phone, $user_type, $user_id]);
                    
                    $db->commit();
                    echo json_encode(['success' => true, 'message' => 'Đã cập nhật thông tin người dùng']);
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                break;
                
            case 'bulk_ban':
                $user_ids = json_decode($_POST['user_ids'], true);
                if (is_array($user_ids) && !empty($user_ids)) {
                    // Remove current admin from the list
                    $user_ids = array_filter($user_ids, function($id) {
                        return $id != $_SESSION['user_id'];
                    });
                    
                    if (!empty($user_ids)) {
                        $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
                        $stmt = $db->prepare("UPDATE users SET banned = 1 WHERE id IN ($placeholders)");
                        $stmt->execute($user_ids);
                        echo json_encode(['success' => true, 'message' => 'Đã cấm ' . count($user_ids) . ' người dùng']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Không có người dùng nào để cấm']);
                    }
                }
                break;
                
            case 'bulk_unban':
                $user_ids = json_decode($_POST['user_ids'], true);
                if (is_array($user_ids) && !empty($user_ids)) {
                    $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
                    $stmt = $db->prepare("UPDATE users SET banned = 0 WHERE id IN ($placeholders)");
                    $stmt->execute($user_ids);
                    echo json_encode(['success' => true, 'message' => 'Đã bỏ cấm ' . count($user_ids) . ' người dùng']);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("Users action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Pagination and filtering
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$search = $_GET['search'] ?? '';
$user_type_filter = $_GET['user_type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$where_conditions = ['1=1']; // Start with a condition that's always true
$params = [];

if (!empty($search)) {
    $where_conditions[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.id = ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = $search;
}

if (!empty($user_type_filter)) {
    $where_conditions[] = 'u.user_type = ?';
    $params[] = $user_type_filter;
}

if (!empty($status_filter)) {
    switch ($status_filter) {
        case 'active':
            $where_conditions[] = 'u.banned = 0';
            break;
        case 'banned':
            $where_conditions[] = 'u.banned = 1';
            break;
        case 'new':
            // Users registered in the last 30 days
            $where_conditions[] = 'u.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
            break;
        case 'has_orders':
            $where_conditions[] = 'EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)';
            break;
        case 'no_orders':
            $where_conditions[] = 'NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)';
            break;
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Valid sort columns
$valid_sorts = ['u.id', 'u.name', 'u.email', 'u.user_type', 'u.created_at', 'u.balance', 'order_count'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'u.created_at';
}

$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Get users with pagination
$users = [];
$total_users = 0;

try {
    // Count total users
    $count_sql = "
        SELECT COUNT(*) as total
        FROM users u
        WHERE $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_users = $stmt->fetch()['total'];
    
    // Get users
    $sql = "
        SELECT u.*,
               (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) as order_count,
               (SELECT SUM(o.grand_total) FROM orders o WHERE o.user_id = u.id) as total_spent,
               COALESCE(cu.id, 0) as is_affiliate
        FROM users u
        LEFT JOIN affiliate_users cu ON u.id = cu.user_id
        WHERE $where_clause
        ORDER BY $sort $order
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Users fetch error: " . $e->getMessage());
    $users = [];
}

// Calculate pagination
$total_pages = ceil($total_users / $per_page);
$start_item = $offset + 1;
$end_item = min($offset + $per_page, $total_users);

// User statistics
$stats = [];
try {
    // Total users
    $stmt = $db->query("SELECT COUNT(*) as count FROM users");
    $stats['total'] = $stmt->fetch()['count'];
    
    // Customer users
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'customer'");
    $stats['customers'] = $stmt->fetch()['count'];
    
    // Admin/staff users
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type IN ('admin', 'staff')");
    $stats['admin_staff'] = $stmt->fetch()['count'];
    
    // Banned users
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE banned = 1");
    $stats['banned'] = $stmt->fetch()['count'];
    
    // New users in the last 30 days
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stats['new'] = $stmt->fetch()['count'];
    
    // Users with orders
    $stmt = $db->query("
        SELECT COUNT(DISTINCT u.id) as count 
        FROM users u 
        INNER JOIN orders o ON u.id = o.user_id
    ");
    $stats['with_orders'] = $stmt->fetch()['count'];
    
} catch (PDOException $e) {
    error_log("User stats error: " . $e->getMessage());
    $stats = ['total' => 0, 'customers' => 0, 'admin_staff' => 0, 'banned' => 0, 'new' => 0, 'with_orders' => 0];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý người dùng - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý người dùng - Admin <?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-sidebar.css">
    <link rel="stylesheet" href="../asset/css/pages/admin-users.css">
</head>

<body>
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <?php require_once __DIR__ . '/sidebar.php'; ?>
        
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
                            <span>Người dùng</span>
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
                    <h1 class="page-title">Quản lý người dùng</h1>
                    <p class="page-subtitle">Quản lý tất cả người dùng trên hệ thống</p>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Tổng người dùng</div>
                            <div class="stat-icon">👥</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    </div>
                    
                    <div class="stat-card green">
                        <div class="stat-header">
                            <div class="stat-title">Khách hàng</div>
                            <div class="stat-icon">🧑</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['customers']); ?></div>
                    </div>

                    <div class="stat-card purple">
                        <div class="stat-header">
                            <div class="stat-title">Admin & Nhân viên</div>
                            <div class="stat-icon">👨‍💼</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['admin_staff']); ?></div>
                    </div>
                    
                    <div class="stat-card orange">
                        <div class="stat-header">
                            <div class="stat-title">Người dùng mới</div>
                            <div class="stat-icon">🆕</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['new']); ?></div>
                    </div>
                    
                    <div class="stat-card green">
                        <div class="stat-header">
                            <div class="stat-title">Đã mua hàng</div>
                            <div class="stat-icon">🛒</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['with_orders']); ?></div>
                    </div>
                    
                    <div class="stat-card orange">
                        <div class="stat-header">
                            <div class="stat-title">Bị cấm</div>
                            <div class="stat-icon">🚫</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['banned']); ?></div>
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
                                    placeholder="Tìm kiếm người dùng..."
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    id="search-input"
                                >
                            </div>
                        </div>
                        <div class="toolbar-right">
                            <a href="add-user.php" class="btn btn-primary">
                                <span>➕</span>
                                <span>Thêm người dùng</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="toolbar-row">
                        <div class="toolbar-left">
                            <select class="filter-select" id="user-type-filter">
                                <option value="">Tất cả loại người dùng</option>
                                <option value="customer" <?php echo $user_type_filter === 'customer' ? 'selected' : ''; ?>>Khách hàng</option>
                                <option value="admin" <?php echo $user_type_filter === 'admin' ? 'selected' : ''; ?>>Quản trị viên</option>
                                <option value="staff" <?php echo $user_type_filter === 'staff' ? 'selected' : ''; ?>>Nhân viên</option>
                            </select>
                            
                            <select class="filter-select" id="status-filter">
                                <option value="">Tất cả trạng thái</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                                <option value="banned" <?php echo $status_filter === 'banned' ? 'selected' : ''; ?>>Bị cấm</option>
                                <option value="new" <?php echo $status_filter === 'new' ? 'selected' : ''; ?>>Người dùng mới</option>
                                <option value="has_orders" <?php echo $status_filter === 'has_orders' ? 'selected' : ''; ?>>Đã mua hàng</option>
                                <option value="no_orders" <?php echo $status_filter === 'no_orders' ? 'selected' : ''; ?>>Chưa mua hàng</option>
                            </select>
                        </div>
                        <div class="toolbar-right">
                            <button class="btn btn-secondary" onclick="exportUsers()">
                                <span>📤</span>
                                <span>Xuất file</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Bulk Actions -->
                <div class="bulk-actions" id="bulk-actions">
                    <span class="bulk-count" id="bulk-count">0 người dùng được chọn</span>
                    <button class="btn btn-warning btn-sm" onclick="bulkAction('ban')">
                        <span>🚫</span>
                        <span>Cấm</span>
                    </button>
                    <button class="btn btn-success btn-sm" onclick="bulkAction('unban')">
                        <span>✅</span>
                        <span>Bỏ cấm</span>
                    </button>
                </div>
                
                <!-- Users Table -->
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" class="checkbox" id="select-all">
                                </th>
                                <th class="sortable <?php echo $sort === 'u.id' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="u.id">
                                    ID
                                </th>
                                <th class="sortable <?php echo $sort === 'u.name' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="u.name">
                                    Tên người dùng
                                </th>
                                <th class="sortable <?php echo $sort === 'u.email' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="u.email">
                                    Email
                                </th>
                                <th>
                                    Số điện thoại
                                </th>
                                <th class="sortable <?php echo $sort === 'u.user_type' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="u.user_type">
                                    Loại
                                </th>
                                <th class="sortable <?php echo $sort === 'order_count' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="order_count">
                                    Đơn hàng
                                </th>
                                <th class="sortable <?php echo $sort === 'u.balance' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="u.balance">
                                    Số dư
                                </th>
                                <th>
                                    Trạng thái
                                </th>
                                <th class="sortable <?php echo $sort === 'u.created_at' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="u.created_at">
                                    Ngày đăng ký
                                </th>
                                <th>
                                    Thao tác
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr data-user-id="<?php echo $user['id']; ?>">
                                    <td>
                                        <input type="checkbox" class="checkbox user-checkbox" value="<?php echo $user['id']; ?>" <?php echo $user['id'] == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                    </td>
                                    <td>
                                        <span class="user-id">#<?php echo $user['id']; ?></span>
                                    </td>
                                    <td>
                                        <div class="user-info-cell">
                                            <div class="user-avatar-cell">
                                                <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                                            </div>
                                            <div class="user-details">
                                                <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                                <?php if ($user['is_affiliate']): ?>
                                                    <small style="color: var(--text-tertiary);">🔗 Affiliate</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($user['email']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?>
                                    </td>
                                    <td>
                                        <?php
                                        switch ($user['user_type']) {
                                            case 'admin':
                                                echo '<span class="status-badge admin">Admin</span>';
                                                break;
                                            case 'staff':
                                                echo '<span class="status-badge staff">Nhân viên</span>';
                                                break;
                                            default:
                                                echo '<span class="status-badge customer">Khách hàng</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format($user['order_count'] ?? 0); ?></strong>
                                        <?php if (!empty($user['total_spent'])): ?>
                                            <br>
                                            <small style="color: var(--text-tertiary);">
                                                <?php echo formatCurrency($user['total_spent']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo formatCurrency($user['balance'] ?? 0); ?>
                                    </td>
                                    <td>
                                        <?php if ($user['banned']): ?>
                                            <span class="status-badge banned">Bị cấm</span>
                                        <?php else: ?>
                                            <span class="status-badge active">Hoạt động</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo formatDate($user['created_at'], 'd/m/Y'); ?>
                                        <br>
                                        <small style="color: var(--text-tertiary);">
                                            <?php echo formatDate($user['created_at'], 'H:i'); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn view" onclick="viewUser(<?php echo $user['id']; ?>)" title="Xem chi tiết">
                                                👁️
                                            </button>
                                            
                                            <button class="action-btn edit" onclick="editUser(<?php echo $user['id']; ?>)" title="Chỉnh sửa">
                                                ✏️
                                            </button>
                                            
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <?php if ($user['banned']): ?>
                                                    <button class="action-btn unban" onclick="unbanUser(<?php echo $user['id']; ?>)" title="Bỏ cấm">
                                                        ✅
                                                    </button>
                                                <?php else: ?>
                                                    <button class="action-btn ban" onclick="banUser(<?php echo $user['id']; ?>)" title="Cấm">
                                                        🚫
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button class="action-btn delete" onclick="deleteUser(<?php echo $user['id']; ?>)" title="Xóa">
                                                    🗑️
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if (count($users) === 0): ?>
                                <tr>
                                    <td colspan="11" style="text-align: center; padding: var(--space-8);">
                                        <div style="font-size: var(--text-xl); margin-bottom: var(--space-4); color: var(--text-secondary);">
                                            Không tìm thấy người dùng
                                        </div>
                                        <a href="?<?php echo http_build_query(array_filter($_GET, function($k) { return !in_array($k, ['search', 'user_type', 'status']); }, ARRAY_FILTER_USE_KEY)); ?>" class="btn btn-secondary">
                                            <span>🔄</span>
                                            <span>Đặt lại bộ lọc</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="pagination">
                    <div class="pagination-info">
                        Hiển thị <?php echo number_format($start_item); ?>-<?php echo number_format($end_item); ?> trong tổng số <?php echo number_format($total_users); ?> người dùng
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
            </div>
        </main>
    </div>
    
    <!-- Edit User Modal -->
    <div class="modal-backdrop" id="edit-user-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modal-title">Chỉnh sửa người dùng</h2>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            <div class="modal-body">
                <form id="edit-user-form">
                    <input type="hidden" id="user-id" value="">
                    
                    <div class="form-group">
                        <label class="form-label" for="user-name">Tên người dùng <span style="color: red">*</span></label>
                        <input type="text" class="form-control" id="user-name" placeholder="Nhập tên người dùng" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="user-email">Email <span style="color: red">*</span></label>
                        <input type="email" class="form-control" id="user-email" placeholder="Nhập email" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="user-phone">Số điện thoại</label>
                        <input type="text" class="form-control" id="user-phone" placeholder="Nhập số điện thoại">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="user-type">Loại người dùng <span style="color: red">*</span></label>
                        <select class="form-control" id="user-type" required>
                            <option value="customer">Khách hàng</option>
                            <option value="staff">Nhân viên</option>
                            <option value="admin">Quản trị viên</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Hủy</button>
                <button class="btn btn-primary" onclick="saveUser()" id="save-button">Cập nhật</button>
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
        document.getElementById('user-type-filter').addEventListener('change', updateFilters);
        document.getElementById('status-filter').addEventListener('change', updateFilters);
        
        function updateFilters() {
            const params = new URLSearchParams();
            
            const search = searchInput.value.trim();
            if (search) params.set('search', search);
            
            const userType = document.getElementById('user-type-filter').value;
            if (userType) params.set('user_type', userType);
            
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
        
        // Select all functionality
        const selectAllCheckbox = document.getElementById('select-all');
        const userCheckboxes = document.querySelectorAll('.user-checkbox:not([disabled])');
        const bulkActions = document.getElementById('bulk-actions');
        const bulkCount = document.getElementById('bulk-count');
        
        selectAllCheckbox.addEventListener('change', function() {
            userCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });
        
        userCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateBulkActions();
                
                // Update select all checkbox
                const checkedCount = document.querySelectorAll('.user-checkbox:not([disabled]):checked').length;
                selectAllCheckbox.checked = checkedCount === userCheckboxes.length;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < userCheckboxes.length;
            });
        });
        
        function updateBulkActions() {
            const selectedUsers = document.querySelectorAll('.user-checkbox:not([disabled]):checked');
            const count = selectedUsers.length;
            
            if (count > 0) {
                bulkActions.classList.add('show');
                bulkCount.textContent = `${count} người dùng được chọn`;
            } else {
                bulkActions.classList.remove('show');
            }
        }
        
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
                    return result;
                } else {
                    showNotification(result.message, 'error');
                    return false;
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
                return false;
            }
        }
        
        // User actions
        function viewUser(userId) {
            window.location.href = `user-edit.php?id=${userId}`;
        }
        
        async function editUser(userId) {
            const result = await makeRequest('get_user', { user_id: userId });
            
            if (result) {
                const user = result.user;
                
                document.getElementById('user-id').value = user.id;
                document.getElementById('user-name').value = user.name;
                document.getElementById('user-email').value = user.email;
                document.getElementById('user-phone').value = user.phone || '';
                document.getElementById('user-type').value = user.user_type;
                
                document.getElementById('modal-title').textContent = 'Chỉnh sửa người dùng';
                document.getElementById('save-button').textContent = 'Cập nhật';
                document.getElementById('edit-user-modal').classList.add('show');
            }
        }
        
        async function saveUser() {
            const userId = document.getElementById('user-id').value;
            const name = document.getElementById('user-name').value.trim();
            const email = document.getElementById('user-email').value.trim();
            const phone = document.getElementById('user-phone').value.trim();
            const userType = document.getElementById('user-type').value;
            
            // Validation
            if (!name) {
                showNotification('Vui lòng nhập tên người dùng', 'error');
                return;
            }
            
            if (!email || !email.match(/^[\w-\.]+@([\w-]+\.)+[\w-]{2,4}$/)) {
                showNotification('Vui lòng nhập email hợp lệ', 'error');
                return;
            }
            
            // Save user
            const saveButton = document.getElementById('save-button');
            saveButton.disabled = true;
            saveButton.innerHTML = '<span class="loading"></span> Đang xử lý';
            
            const result = await makeRequest('update_user', {
                user_id: userId,
                name,
                email,
                phone,
                user_type: userType
            });
            
            if (result) {
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                saveButton.disabled = false;
                saveButton.textContent = 'Cập nhật';
            }
        }
        
        function closeModal() {
            document.getElementById('edit-user-modal').classList.remove('show');
        }
        
        // Close modal when clicking on backdrop
        document.getElementById('edit-user-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        async function banUser(userId) {
            if (!confirm('Bạn có chắc chắn muốn cấm người dùng này?')) {
                return;
            }
            
            const result = await makeRequest('ban_user', { user_id: userId });
            if (result) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function unbanUser(userId) {
            if (!confirm('Bạn có chắc chắn muốn bỏ cấm người dùng này?')) {
                return;
            }
            
            const result = await makeRequest('unban_user', { user_id: userId });
            if (result) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function deleteUser(userId) {
            if (!confirm('Bạn có chắc chắn muốn xóa người dùng này? Hành động này có thể không hoàn tác được.')) {
                return;
            }
            
            const result = await makeRequest('delete_user', { user_id: userId });
            if (result) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Bulk actions
        async function bulkAction(action) {
            let userIds = [];
            
            document.querySelectorAll('.user-checkbox:checked').forEach(checkbox => {
                userIds.push(checkbox.value);
            });
            
            if (userIds.length === 0) {
                showNotification('Vui lòng chọn ít nhất một người dùng', 'error');
                return;
            }
            
            let confirmMessage = '';
            switch (action) {
                case 'ban':
                    confirmMessage = `Bạn có chắc chắn muốn cấm ${userIds.length} người dùng đã chọn?`;
                    break;
                case 'unban':
                    confirmMessage = `Bạn có chắc chắn muốn bỏ cấm ${userIds.length} người dùng đã chọn?`;
                    break;
            }
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            let ajaxAction = '';
            
            switch (action) {
                case 'ban':
                    ajaxAction = 'bulk_ban';
                    break;
                case 'unban':
                    ajaxAction = 'bulk_unban';
                    break;
            }
            
            const result = await makeRequest(ajaxAction, { user_ids: JSON.stringify(userIds) });
            if (result) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        // Export users
        function exportUsers() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', '1');
            window.open('?' + params.toString(), '_blank');
        }
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // Hide and remove notification after 3 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }
        
        // Mobile responsive handling
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
            console.log('🚀 Users Management - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Users Management - Ready!');
            console.log('👥 User count:', <?php echo $total_users; ?>);
        });
    </script>
</body>
</html>