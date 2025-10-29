<?php
/**
 * Admin Staff Page
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
            case 'delete_staff':
                $staff_id = (int)$_POST['staff_id'];
                
                // Get user_id from staff record
                $stmt = $db->prepare("SELECT user_id FROM staff WHERE id = ?");
                $stmt->execute([$staff_id]);
                $staff = $stmt->fetch();
                
                if (!$staff) {
                    echo json_encode(['success' => false, 'message' => 'Nhân viên không tồn tại']);
                    exit;
                }
                
                $user_id = $staff['user_id'];
                
                // Check if deleting own account
                if ($user_id == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Bạn không thể xóa tài khoản của chính mình']);
                    exit;
                }
                
                // Begin transaction
                $db->beginTransaction();
                
                // Delete staff record
                $stmt = $db->prepare("DELETE FROM staff WHERE id = ?");
                $stmt->execute([$staff_id]);
                
                // Update user record (change user_type to customer)
                $stmt = $db->prepare("UPDATE users SET user_type = 'customer' WHERE id = ?");
                $stmt->execute([$user_id]);
                
                // Delete role associations
                $stmt = $db->prepare("DELETE FROM model_has_roles WHERE model_id = ? AND model_type = 'App\\Models\\User'");
                $stmt->execute([$user_id]);
                
                $db->commit();
                
                echo json_encode(['success' => true, 'message' => 'Nhân viên đã được xóa thành công']);
                break;
                
            case 'toggle_status':
                $user_id = (int)$_POST['user_id'];
                
                // Check if toggling own account
                if ($user_id == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Bạn không thể vô hiệu hóa tài khoản của chính mình']);
                    exit;
                }
                
                // Toggle banned status
                $stmt = $db->prepare("UPDATE users SET banned = 1 - banned WHERE id = ? AND user_type = 'admin'");
                $stmt->execute([$user_id]);
                
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật trạng thái nhân viên']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Staff action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Pagination and filtering
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$where_conditions = ["u.user_type = 'admin'"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($role_filter)) {
    $where_conditions[] = 'r.id = ?';
    $params[] = $role_filter;
}

if (!empty($status_filter)) {
    if ($status_filter === 'active') {
        $where_conditions[] = 'u.banned = 0';
    } else {
        $where_conditions[] = 'u.banned = 1';
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Valid sort columns
$valid_sorts = ['id', 'name', 'email', 'created_at', 'updated_at'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'created_at';
}

$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Get staff with pagination
$staff_members = [];
$total_staff = 0;

try {
    // Count total staff
    $count_sql = "
        SELECT COUNT(*) as total
        FROM users u
        LEFT JOIN staff s ON u.id = s.user_id
        LEFT JOIN roles r ON s.role_id = r.id
        WHERE $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_staff = $stmt->fetch()['total'];
    
    // Get staff members
    $sql = "
        SELECT u.*, s.id as staff_id, r.id as role_id, r.name as role_name,
               (SELECT COUNT(*) FROM model_has_permissions WHERE model_id = u.id AND model_type = 'App\\\\Models\\\\User') as permission_count
        FROM users u
        LEFT JOIN staff s ON u.id = s.user_id
        LEFT JOIN model_has_roles mhr ON u.id = mhr.model_id AND mhr.model_type = 'App\\\\Models\\\\User'
        LEFT JOIN roles r ON mhr.role_id = r.id
        WHERE $where_clause
        ORDER BY u.$sort $order
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $staff_members = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Staff fetch error: " . $e->getMessage());
    $staff_members = [];
}

// Calculate pagination
$total_pages = ceil($total_staff / $per_page);
$start_item = $offset + 1;
$end_item = min($offset + $per_page, $total_staff);

// Get roles for filter
$roles = [];
try {
    $stmt = $db->query("SELECT id, name FROM roles ORDER BY name ASC");
    $roles = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Roles fetch error: " . $e->getMessage());
}

// Staff statistics
$stats = [];
try {
    // Total staff
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'admin'");
    $stats['total'] = $stmt->fetch()['count'];
    
    // Active staff
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'admin' AND banned = 0");
    $stats['active'] = $stmt->fetch()['count'];
    
    // Inactive staff
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_type = 'admin' AND banned = 1");
    $stats['inactive'] = $stmt->fetch()['count'];
    
    // Staff by role
    $stmt = $db->query("
        SELECT r.name, COUNT(*) as count
        FROM users u
        JOIN model_has_roles mhr ON u.id = mhr.model_id AND mhr.model_type = 'App\\Models\\User'
        JOIN roles r ON mhr.role_id = r.id
        WHERE u.user_type = 'admin'
        GROUP BY r.name
    ");
    $stats['by_role'] = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Staff stats error: " . $e->getMessage());
    $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'by_role' => []];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý nhân viên - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý nhân viên - Admin <?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-staff.css">
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
                        <a href="staff.php" class="nav-link active">
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
                            <span>Nhân viên</span>
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
                    <h1 class="page-title">Quản lý nhân viên</h1>
                    <p class="page-subtitle">Quản lý tài khoản nhân viên và phân quyền truy cập hệ thống</p>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Tổng nhân viên</div>
                            <div class="stat-icon">👥</div>
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
                            <div class="stat-title">Không hoạt động</div>
                            <div class="stat-icon">❌</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['inactive']); ?></div>
                    </div>
                    
                    <?php if (!empty($stats['by_role'])): ?>
                        <?php foreach ($stats['by_role'] as $role_stat): ?>
                            <div class="stat-card">
                                <div class="stat-header">
                                    <div class="stat-title"><?php echo htmlspecialchars($role_stat['name']); ?></div>
                                    <div class="stat-icon">👨‍💼</div>
                                </div>
                                <div class="stat-value"><?php echo number_format($role_stat['count']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
                                    placeholder="Tìm kiếm nhân viên..."
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    id="search-input"
                                >
                            </div>
                        </div>
                        <div class="toolbar-right">
                            <a href="staff-edit.php" class="btn btn-primary">
                                <span>➕</span>
                                <span>Thêm nhân viên mới</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="toolbar-row">
                        <div class="toolbar-left">
                            <select class="filter-select" id="role-filter">
                                <option value="">Tất cả vai trò</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>" <?php echo $role_filter == $role['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select class="filter-select" id="status-filter">
                                <option value="">Tất cả trạng thái</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Không hoạt động</option>
                            </select>
                        </div>
                        <div class="toolbar-right">
                            <a href="roles.php" class="btn btn-secondary">
                                <span>🔐</span>
                                <span>Quản lý vai trò</span>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Role List (Optional) -->
                <?php if (!empty($roles)): ?>
                    <div class="role-list">
                        <?php foreach ($roles as $role): ?>
                            <div class="role-item">
                                <span><?php echo htmlspecialchars($role['name']); ?></span>
                                <?php
                                // Count staff with this role
                                $role_count = 0;
                                foreach ($stats['by_role'] as $role_stat) {
                                    if ($role_stat['name'] === $role['name']) {
                                        $role_count = $role_stat['count'];
                                        break;
                                    }
                                }
                                ?>
                                <span class="role-item-count"><?php echo $role_count; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Staff Table -->
                <div class="table-container" style="margin-top: var(--space-6);">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="sortable <?php echo $sort === 'id' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="id">
                                    ID
                                </th>
                                <th>Nhân viên</th>
                                <th>Liên hệ</th>
                                <th>Vai trò</th>
                                <th>Quyền hạn</th>
                                <th>Trạng thái</th>
                                <th class="sortable <?php echo $sort === 'created_at' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="created_at">
                                    Ngày tạo
                                </th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staff_members)): ?>
                                <tr>
                                    <td colspan="8" class="empty-state">
                                        <div class="empty-icon">👨‍💼</div>
                                        <div class="empty-title">Chưa có nhân viên nào</div>
                                        <div class="empty-description">Hãy thêm nhân viên đầu tiên của bạn</div>
                                        <a href="staff-edit.php" class="btn btn-primary">
                                            <span>➕</span>
                                            <span>Thêm nhân viên mới</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($staff_members as $staff): ?>
                                    <tr>
                                        <td><?php echo $staff['id']; ?></td>
                                        <td>
                                            <div class="staff-info">
                                                <div class="staff-avatar">
                                                    <?php echo strtoupper(substr($staff['name'] ?? 'A', 0, 2)); ?>
                                                </div>
                                                <div class="staff-details">
                                                    <div class="staff-name"><?php echo htmlspecialchars($staff['name']); ?></div>
                                                    <div class="staff-meta">
                                                        <?php if ($staff['id'] === $_SESSION['user_id']): ?>
                                                            <span class="status-badge active">Đang đăng nhập</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <div><?php echo htmlspecialchars($staff['email']); ?></div>
                                                <?php if (!empty($staff['phone'])): ?>
                                                    <div style="color: var(--text-tertiary); font-size: var(--text-xs);">
                                                        <?php echo htmlspecialchars($staff['phone']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($staff['role_name'])): ?>
                                                <span class="role-pill"><?php echo htmlspecialchars($staff['role_name']); ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--text-tertiary);">Chưa gán vai trò</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="permission-count">
                                                <span class="permission-badge"><?php echo $staff['permission_count']; ?></span>
                                                <span>quyền</span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($staff['id'] !== $_SESSION['user_id']): ?>
                                                <label class="switch">
                                                    <input type="checkbox" onchange="toggleStaffStatus(<?php echo $staff['id']; ?>)" <?php echo $staff['banned'] ? '' : 'checked'; ?>>
                                                    <span class="slider"></span>
                                                </label>
                                            <?php else: ?>
                                                <span class="status-badge active">Hoạt động</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo date('d/m/Y', strtotime($staff['created_at'])); ?>
                                            <br>
                                            <small style="color: var(--text-tertiary);">
                                                <?php echo date('H:i', strtotime($staff['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="staff-edit.php?id=<?php echo $staff['id']; ?>" class="action-btn edit" title="Chỉnh sửa">
                                                    ✏️
                                                </a>
                                                <a href="staff-permissions.php?id=<?php echo $staff['id']; ?>" class="action-btn permission" title="Phân quyền">
                                                    🔐
                                                </a>
                                                <?php if ($staff['id'] !== $_SESSION['user_id']): ?>
                                                    <button class="action-btn delete" onclick="deleteStaff(<?php echo $staff['staff_id']; ?>)" title="Xóa">
                                                        🗑️
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_staff > 0): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Hiển thị <?php echo number_format($start_item); ?>-<?php echo number_format($end_item); ?> trong tổng số <?php echo number_format($total_staff); ?> nhân viên
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
        document.getElementById('role-filter').addEventListener('change', updateFilters);
        document.getElementById('status-filter').addEventListener('change', updateFilters);
        
        function updateFilters() {
            const params = new URLSearchParams();
            
            const search = searchInput.value.trim();
            if (search) params.set('search', search);
            
            const role = document.getElementById('role-filter').value;
            if (role) params.set('role', role);
            
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
        
        // Staff actions
        async function deleteStaff(staffId) {
            if (!confirm('Bạn có chắc chắn muốn xóa nhân viên này?')) {
                return;
            }
            
            const success = await makeRequest('delete_staff', { staff_id: staffId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function toggleStaffStatus(userId) {
            const success = await makeRequest('toggle_status', { user_id: userId });
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
            console.log('🚀 Staff Management - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Staff Management - Ready!');
            console.log('👥 Staff count:', <?php echo $total_staff; ?>);
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
            
            // Ctrl/Cmd + N for new staff
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'staff-edit.php';
            }
        });
    </script>
</body>
</html>