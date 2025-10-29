<?php
/**
 * Admin Flash Deals Page
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
            case 'delete_flash_deal':
                $deal_id = (int)$_POST['deal_id'];
                // First delete associated products
                $stmt = $db->prepare("DELETE FROM flash_deal_products WHERE flash_deal_id = ?");
                $stmt->execute([$deal_id]);
                // Then delete translations
                $stmt = $db->prepare("DELETE FROM flash_deal_translations WHERE flash_deal_id = ?");
                $stmt->execute([$deal_id]);
                // Finally delete the flash deal
                $stmt = $db->prepare("DELETE FROM flash_deals WHERE id = ?");
                $stmt->execute([$deal_id]);
                echo json_encode(['success' => true, 'message' => 'Flash deal đã được xóa']);
                break;
                
            case 'toggle_status':
                $deal_id = (int)$_POST['deal_id'];
                $stmt = $db->prepare("UPDATE flash_deals SET status = 1 - status WHERE id = ?");
                $stmt->execute([$deal_id]);
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật trạng thái']);
                break;
                
            case 'toggle_featured':
                $deal_id = (int)$_POST['deal_id'];
                
                // First set all flash deals to not featured
                $stmt = $db->prepare("UPDATE flash_deals SET featured = 0");
                $stmt->execute();
                
                // Then set the selected one as featured
                $stmt = $db->prepare("UPDATE flash_deals SET featured = 1 WHERE id = ?");
                $stmt->execute([$deal_id]);
                
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật trạng thái nổi bật']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("Flash deals action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Pagination and filtering
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'start_date';
$order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$where_conditions = ['1=1'];
$params = [];

if (!empty($search)) {
    $where_conditions[] = 'title LIKE ?';
    $params[] = "%$search%";
}

if (!empty($status_filter)) {
    switch ($status_filter) {
        case 'active':
            $where_conditions[] = 'status = 1';
            break;
        case 'inactive':
            $where_conditions[] = 'status = 0';
            break;
        case 'featured':
            $where_conditions[] = 'featured = 1';
            break;
        case 'upcoming':
            $where_conditions[] = 'start_date > ?';
            $params[] = time();
            break;
        case 'ongoing':
            $where_conditions[] = 'start_date <= ? AND end_date >= ?';
            $params[] = time();
            $params[] = time();
            break;
        case 'expired':
            $where_conditions[] = 'end_date < ?';
            $params[] = time();
            break;
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Valid sort columns
$valid_sorts = ['id', 'title', 'start_date', 'end_date', 'status', 'featured', 'created_at'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'created_at';
}

$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Get flash deals with pagination
$flash_deals = [];
$total_deals = 0;

try {
    // Count total flash deals
    $count_sql = "SELECT COUNT(*) as total FROM flash_deals WHERE $where_clause";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_deals = $stmt->fetch()['total'];
    
    // Get flash deals
    $sql = "
        SELECT fd.*, 
               (SELECT COUNT(*) FROM flash_deal_products WHERE flash_deal_id = fd.id) as product_count,
               CASE 
                   WHEN fd.start_date > ? THEN 'upcoming'
                   WHEN fd.start_date <= ? AND fd.end_date >= ? THEN 'ongoing'
                   ELSE 'expired'
               END as deal_status
        FROM flash_deals fd
        WHERE $where_clause
        ORDER BY fd.$sort $order
        LIMIT $per_page OFFSET $offset
    ";
    
    // Add the current time to parameters three times for the CASE statement
    $current_time = time();
    $all_params = array_merge([$current_time, $current_time, $current_time], $params);
    
    $stmt = $db->prepare($sql);
    $stmt->execute($all_params);
    $flash_deals = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Flash deals fetch error: " . $e->getMessage());
    $flash_deals = [];
}

// Calculate pagination
$total_pages = ceil($total_deals / $per_page);
$start_item = $offset + 1;
$end_item = min($offset + $per_page, $total_deals);

// Flash deal statistics
$stats = [];
try {
    // Total flash deals
    $stmt = $db->query("SELECT COUNT(*) as count FROM flash_deals");
    $stats['total'] = $stmt->fetch()['count'];
    
    // Active flash deals
    $stmt = $db->query("SELECT COUNT(*) as count FROM flash_deals WHERE status = 1");
    $stats['active'] = $stmt->fetch()['count'];
    
    // Featured flash deals
    $stmt = $db->query("SELECT COUNT(*) as count FROM flash_deals WHERE featured = 1");
    $stats['featured'] = $stmt->fetch()['count'];
    
    // Upcoming flash deals
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM flash_deals WHERE start_date > ?");
    $stmt->execute([time()]);
    $stats['upcoming'] = $stmt->fetch()['count'];
    
    // Ongoing flash deals
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM flash_deals WHERE start_date <= ? AND end_date >= ?");
    $stmt->execute([time(), time()]);
    $stats['ongoing'] = $stmt->fetch()['count'];
    
    // Expired flash deals
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM flash_deals WHERE end_date < ?");
    $stmt->execute([time()]);
    $stats['expired'] = $stmt->fetch()['count'];
    
} catch (PDOException $e) {
    error_log("Flash deal stats error: " . $e->getMessage());
    $stats = ['total' => 0, 'active' => 0, 'featured' => 0, 'upcoming' => 0, 'ongoing' => 0, 'expired' => 0];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Flash Deal - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý Flash Deal - Admin <?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-flash-deals.css">
    <link rel="stylesheet" href="../asset/css/pages/admin-sidebar.css">
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
                            <a href="marketing.php">Marketing</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span>Flash Deals</span>
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
                    <h1 class="page-title">Quản lý Flash Deals</h1>
                    <p class="page-subtitle">Tạo và quản lý các chương trình khuyến mãi flash deal</p>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Tổng số Flash Deals</div>
                            <div class="stat-icon">⚡</div>
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
                            <div class="stat-title">Sắp diễn ra</div>
                            <div class="stat-icon">⏳</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['upcoming']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Đang diễn ra</div>
                            <div class="stat-icon">🔥</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['ongoing']); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Đã kết thúc</div>
                            <div class="stat-icon">🏁</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['expired']); ?></div>
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
                                    placeholder="Tìm kiếm flash deal..."
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    id="search-input"
                                >
                            </div>
                        </div>
                        <div class="toolbar-right">
                            <a href="flash-deal-edit.php" class="btn btn-primary">
                                <span>➕</span>
                                <span>Tạo Flash Deal mới</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="toolbar-row">
                        <div class="toolbar-left">
                            <select class="filter-select" id="status-filter">
                                <option value="">Tất cả trạng thái</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Không hoạt động</option>
                                <option value="featured" <?php echo $status_filter === 'featured' ? 'selected' : ''; ?>>Nổi bật</option>
                                <option value="upcoming" <?php echo $status_filter === 'upcoming' ? 'selected' : ''; ?>>Sắp diễn ra</option>
                                <option value="ongoing" <?php echo $status_filter === 'ongoing' ? 'selected' : ''; ?>>Đang diễn ra</option>
                                <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Đã kết thúc</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Flash Deals Table -->
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="sortable <?php echo $sort === 'id' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="id">
                                    ID
                                </th>
                                <th>Flash Deal</th>
                                <th class="sortable <?php echo $sort === 'start_date' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="start_date">
                                    Thời gian
                                </th>
                                <th>Số sản phẩm</th>
                                <th>Trạng thái</th>
                                <th class="sortable <?php echo $sort === 'created_at' ? 'sorted ' . strtolower($order) : ''; ?>" data-sort="created_at">
                                    Ngày tạo
                                </th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($flash_deals)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: var(--space-8);">
                                        <div style="color: var(--text-tertiary);">
                                            <div style="font-size: var(--text-xl); margin-bottom: var(--space-4);">⚡</div>
                                            <div style="font-weight: var(--font-medium);">Chưa có Flash Deal nào</div>
                                            <div style="font-size: var(--text-sm); margin-top: var(--space-2);">Hãy tạo flash deal đầu tiên của bạn</div>
                                            <div style="margin-top: var(--space-6);">
                                                <a href="flash-deal-edit.php" class="btn btn-primary">
                                                    <span>➕</span>
                                                    <span>Tạo Flash Deal mới</span>
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($flash_deals as $deal): ?>
                                    <?php
                                    // Calculate progress for ongoing deals
                                    $progress = 0;
                                    $now = time();
                                    if ($deal['deal_status'] === 'ongoing') {
                                        $total_duration = $deal['end_date'] - $deal['start_date'];
                                        $elapsed = $now - $deal['start_date'];
                                        $progress = min(100, round(($elapsed / $total_duration) * 100));
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="deal-id">#<?php echo $deal['id']; ?></span>
                                        </td>
                                        <td>
                                            <div class="deal-info">
                                                <img 
                                                    src="<?php echo !empty($deal['banner']) && file_exists('../' . $deal['banner']) ? '../' . htmlspecialchars($deal['banner']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60" viewBox="0 0 120 60"><rect width="120" height="60" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="24" fill="%236b7280">⚡</text></svg>'; ?>" 
                                                    alt="<?php echo htmlspecialchars($deal['title']); ?>"
                                                    class="deal-banner"
                                                    loading="lazy"
                                                >
                                                <div class="deal-details">
                                                    <div class="deal-title"><?php echo htmlspecialchars($deal['title']); ?></div>
                                                    <div class="deal-meta">
                                                        <?php if ($deal['background_color']): ?>
                                                            <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background-color: <?php echo htmlspecialchars($deal['background_color']); ?>"></span>
                                                        <?php endif; ?>
                                                        <?php if ($deal['featured']): ?>
                                                            <span class="status-badge featured">Nổi bật</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="date-range">
                                                <div class="date-item">
                                                    <span class="date-label">Bắt đầu:</span>
                                                    <span class="date-value"><?php echo formatDate($deal['start_date']); ?></span>
                                                </div>
                                                <div class="date-item">
                                                    <span class="date-label">Kết thúc:</span>
                                                    <span class="date-value"><?php echo formatDate($deal['end_date']); ?></span>
                                                </div>
                                                
                                                <?php if ($deal['deal_status'] === 'upcoming'): ?>
                                                    <div class="progress-container">
                                                        <div class="progress-bar upcoming"></div>
                                                    </div>
                                                <?php elseif ($deal['deal_status'] === 'ongoing'): ?>
                                                    <div class="progress-container">
                                                        <div class="progress-bar ongoing" style="width: <?php echo $progress; ?>%"></div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="progress-container">
                                                        <div class="progress-bar expired"></div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="product-count">
                                                <span class="product-count-value"><?php echo number_format($deal['product_count']); ?></span>
                                                <span>sản phẩm</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; flex-direction: column; gap: var(--space-1);">
                                                <?php if ($deal['status']): ?>
                                                    <span class="status-badge active">Hoạt động</span>
                                                <?php else: ?>
                                                    <span class="status-badge inactive">Không hoạt động</span>
                                                <?php endif; ?>
                                                
                                                <span class="status-badge <?php echo $deal['deal_status']; ?>">
                                                    <?php 
                                                    switch ($deal['deal_status']) {
                                                        case 'upcoming':
                                                            echo 'Sắp diễn ra';
                                                            break;
                                                        case 'ongoing':
                                                            echo 'Đang diễn ra';
                                                            break;
                                                        case 'expired':
                                                            echo 'Đã kết thúc';
                                                            break;
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo date('d/m/Y', strtotime($deal['created_at'])); ?>
                                            <br>
                                            <small style="color: var(--text-tertiary);">
                                                <?php echo date('H:i', strtotime($deal['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn edit" onclick="editDeal(<?php echo $deal['id']; ?>)" title="Chỉnh sửa">
                                                    ✏️
                                                </button>
                                                <button class="action-btn feature <?php echo $deal['featured'] ? 'active' : ''; ?>" onclick="toggleFeatured(<?php echo $deal['id']; ?>)" title="<?php echo $deal['featured'] ? 'Bỏ nổi bật' : 'Đặt nổi bật'; ?>">
                                                    <?php echo $deal['featured'] ? '⭐' : '☆'; ?>
                                                </button>
                                                <button class="action-btn delete" onclick="deleteDeal(<?php echo $deal['id']; ?>)" title="Xóa">
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
                <?php if ($total_deals > 0): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Hiển thị <?php echo number_format($start_item); ?>-<?php echo number_format($end_item); ?> trong tổng số <?php echo number_format($total_deals); ?> flash deals
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
        document.getElementById('status-filter').addEventListener('change', updateFilters);
        
        function updateFilters() {
            const params = new URLSearchParams();
            
            const search = searchInput.value.trim();
            if (search) params.set('search', search);
            
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
        
        // Flash deal actions
        function editDeal(dealId) {
            window.location.href = `flash-deal-edit.php?id=${dealId}`;
        }
        
        async function deleteDeal(dealId) {
            if (!confirm('Bạn có chắc chắn muốn xóa flash deal này?')) {
                return;
            }
            
            const success = await makeRequest('delete_flash_deal', { deal_id: dealId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function toggleFeatured(dealId) {
            if (!confirm('Chỉ có thể có một Flash Deal nổi bật tại một thời điểm. Bạn có muốn tiếp tục?')) {
                return;
            }
            
            const success = await makeRequest('toggle_featured', { deal_id: dealId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function toggleStatus(dealId) {
            const success = await makeRequest('toggle_status', { deal_id: dealId });
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
            console.log('🚀 Flash Deals Management - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Flash Deals Management - Ready!');
            console.log('📊 Flash Deals count:', <?php echo $total_deals; ?>);
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
            
            // Ctrl/Cmd + N for new flash deal
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'flash-deal-edit.php';
            }
        });
    </script>
</body>
</html>