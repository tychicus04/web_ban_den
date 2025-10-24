<?php
// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Define menu items with role-based access
$menu_items = [
    [
        'id' => 'dashboard',
        'title' => 'Bảng điều khiển',
        'icon' => '🏠',
        'url' => 'dashboard.php',
        'roles' => ['admin', 'staff'],
        'badge' => null
    ],
    [
        'id' => 'products',
        'title' => 'Sản phẩm',
        'icon' => '📦',
        'url' => 'products/index.php',
        'roles' => ['admin', 'staff'],
        'badge' => null,
        'submenu' => [
            ['title' => 'Tất cả sản phẩm', 'url' => 'products/index.php', 'icon' => '📋'],
            ['title' => 'Thêm sản phẩm', 'url' => 'products/create.php', 'icon' => '➕'],
            ['title' => 'Danh mục', 'url' => 'categories/index.php', 'icon' => '📂'],
            ['title' => 'Thương hiệu', 'url' => 'brands/index.php', 'icon' => '🏷️'],
            ['title' => 'Thuộc tính', 'url' => 'attributes/index.php', 'icon' => '🏗️']
        ]
    ],
    [
        'id' => 'orders',
        'title' => 'Đơn hàng',
        'icon' => '🛒',
        'url' => 'orders/index.php',
        'roles' => ['admin', 'staff'],
        'badge' => isset($stats['pending_orders']) ? $stats['pending_orders'] : null,
        'submenu' => [
            ['title' => 'Tất cả đơn hàng', 'url' => 'orders/index.php', 'icon' => '📋'],
            ['title' => 'Đang chờ', 'url' => 'orders/index.php?status=pending', 'icon' => '⏳'],
            ['title' => 'Đang xử lý', 'url' => 'orders/index.php?status=processing', 'icon' => '⚙️'],
            ['title' => 'Đang giao', 'url' => 'orders/index.php?status=shipping', 'icon' => '🚚'],
            ['title' => 'Hoàn thành', 'url' => 'orders/index.php?status=delivered', 'icon' => '✅'],
            ['title' => 'Đã hủy', 'url' => 'orders/index.php?status=cancelled', 'icon' => '❌']
        ]
    ],
    [
        'id' => 'customers',
        'title' => 'Khách hàng',
        'icon' => '👥',
        'url' => 'users/index.php',
        'roles' => ['admin', 'staff'],
        'badge' => null,
        'submenu' => [
            ['title' => 'Tất cả khách hàng', 'url' => 'users/index.php', 'icon' => '👥'],
            ['title' => 'Nhóm khách hàng', 'url' => 'user-groups/index.php', 'icon' => '👨‍👩‍👧‍👦'],
            ['title' => 'Đánh giá', 'url' => 'reviews/index.php', 'icon' => '⭐'],
            ['title' => 'Wishlist', 'url' => 'wishlists/index.php', 'icon' => '💖']
        ]
    ],
    [
        'id' => 'sellers',
        'title' => 'Người bán',
        'icon' => '🏪',
        'url' => 'sellers/index.php',
        'roles' => ['admin', 'staff'],
        'badge' => isset($stats['pending_sellers']) && $stats['pending_sellers'] > 0 ? $stats['pending_sellers'] : null,
        'submenu' => [
            ['title' => 'Tất cả người bán', 'url' => 'sellers/index.php', 'icon' => '🏪'],
            ['title' => 'Đăng ký chờ duyệt', 'url' => 'sellers/pending.php', 'icon' => '⏳'],
            ['title' => 'Seller đã xác minh', 'url' => 'sellers/verified.php', 'icon' => '✅'],
            ['title' => 'Seller bị khóa', 'url' => 'sellers/banned.php', 'icon' => '🚫'],
            ['title' => 'Gói seller', 'url' => 'seller-packages/index.php', 'icon' => '📦'],
            ['title' => 'Hoa hồng seller', 'url' => 'seller-commissions/index.php', 'icon' => '💰'],
            ['title' => 'Thanh toán seller', 'url' => 'seller-payments/index.php', 'icon' => '💳'],
            ['title' => 'Báo cáo seller', 'url' => 'seller-reports/index.php', 'icon' => '📊']
        ]
    ],
    [
        'id' => 'marketing',
        'title' => 'Marketing',
        'icon' => '📢',
        'url' => 'marketing/index.php',
        'roles' => ['admin'],
        'badge' => null,
        'submenu' => [
            ['title' => 'Flash Deals', 'url' => 'flash-deals/index.php', 'icon' => '⚡'],
            ['title' => 'Coupons', 'url' => 'coupons/index.php', 'icon' => '🎫'],
            ['title' => 'Banners', 'url' => 'banners/index.php', 'icon' => '🖼️'],
            ['title' => 'Newsletter', 'url' => 'newsletter/index.php', 'icon' => '📧'],
            ['title' => 'SEO', 'url' => 'seo/index.php', 'icon' => '🔍']
        ]
    ],
    [
        'id' => 'sales',
        'title' => 'Bán hàng',
        'icon' => '💰',
        'url' => 'sales/index.php',
        'roles' => ['admin', 'staff'],
        'badge' => null,
        'submenu' => [
            ['title' => 'Thống kê bán hàng', 'url' => 'sales/index.php', 'icon' => '📊'],
            ['title' => 'Hoa hồng', 'url' => 'commissions/index.php', 'icon' => '💵'],
            ['title' => 'Thanh toán', 'url' => 'payments/index.php', 'icon' => '💳'],
            ['title' => 'Hoàn tiền', 'url' => 'refunds/index.php', 'icon' => '↩️']
        ]
    ],
    [
        'id' => 'shipping',
        'title' => 'Vận chuyển',
        'icon' => '🚚',
        'url' => 'shipping/index.php',
        'roles' => ['admin', 'staff'],
        'badge' => null,
        'submenu' => [
            ['title' => 'Nhà vận chuyển', 'url' => 'carriers/index.php', 'icon' => '🏢'],
            ['title' => 'Phương thức giao hàng', 'url' => 'shipping-methods/index.php', 'icon' => '📦'],
            ['title' => 'Khu vực giao hàng', 'url' => 'shipping-zones/index.php', 'icon' => '🗺️'],
            ['title' => 'Phí vận chuyển', 'url' => 'shipping-rates/index.php', 'icon' => '💰']
        ]
    ],
    [
        'id' => 'reports',
        'title' => 'Báo cáo',
        'icon' => '📊',
        'url' => 'reports/index.php',
        'roles' => ['admin'],
        'badge' => null,
        'submenu' => [
            ['title' => 'Báo cáo tổng quan', 'url' => 'reports/index.php', 'icon' => '📋'],
            ['title' => 'Báo cáo doanh số', 'url' => 'reports/sales.php', 'icon' => '💰'],
            ['title' => 'Báo cáo sản phẩm', 'url' => 'reports/products.php', 'icon' => '📦'],
            ['title' => 'Báo cáo khách hàng', 'url' => 'reports/customers.php', 'icon' => '👥'],
            ['title' => 'Báo cáo kho hàng', 'url' => 'reports/inventory.php', 'icon' => '📊']
        ]
    ],
    [
        'id' => 'staff',
        'title' => 'Nhân viên',
        'icon' => '👨‍💼',
        'url' => 'staff/index.php',
        'roles' => ['admin'],
        'badge' => null,
        'submenu' => [
            ['title' => 'Quản lý nhân viên', 'url' => 'staff/index.php', 'icon' => '👥'],
            ['title' => 'Vai trò & Quyền', 'url' => 'roles/index.php', 'icon' => '🔐'],
            ['title' => 'Phân quyền', 'url' => 'permissions/index.php', 'icon' => '🛡️']
        ]
    ],
    [
        'id' => 'system',
        'title' => 'Hệ thống',
        'icon' => '⚙️',
        'url' => 'settings/index.php',
        'roles' => ['admin'],
        'badge' => null,
        'submenu' => [
            ['title' => 'Cài đặt chung', 'url' => 'settings/index.php', 'icon' => '⚙️'],
            ['title' => 'Cài đặt email', 'url' => 'settings/email.php', 'icon' => '📧'],
            ['title' => 'Cài đặt thanh toán', 'url' => 'settings/payment.php', 'icon' => '💳'],
            ['title' => 'Cài đặt SMS', 'url' => 'settings/sms.php', 'icon' => '📱'],
            ['title' => 'Backup & Restore', 'url' => 'settings/backup.php', 'icon' => '💾'],
            ['title' => 'Logs', 'url' => 'settings/logs.php', 'icon' => '📋']
        ]
    ]
];

// Function to check if user has access to menu item
function hasAccess($item_roles, $user_role) {
    return in_array($user_role, $item_roles);
}

// Function to check if menu item is active
function isActive($item_id, $current_page, $current_dir, $submenu = null) {
    // Check main menu
    if ($item_id === $current_page || $item_id === $current_dir) {
        return true;
    }
    
    // Check submenu
    if ($submenu) {
        foreach ($submenu as $sub_item) {
            $sub_url_parts = explode('/', $sub_item['url']);
            $sub_page = basename(end($sub_url_parts), '.php');
            $sub_dir = count($sub_url_parts) > 1 ? $sub_url_parts[0] : '';
            
            if ($sub_page === $current_page || $sub_dir === $current_dir) {
                return true;
            }
        }
    }
    
    return false;
}
?>

<style>
    /* Sidebar Styles */
    .admin-sidebar {
        width: 280px;
        background: var(--surface);
        border-right: 1px solid var(--border);
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        overflow-y: auto;
        overflow-x: hidden;
        transition: var(--transition-normal);
        z-index: 40;
        box-shadow: var(--shadow-lg);
    }
    
    .admin-sidebar.collapsed {
        width: 80px;
    }
    
    .admin-sidebar::-webkit-scrollbar {
        width: 6px;
    }
    
    .admin-sidebar::-webkit-scrollbar-track {
        background: var(--gray-100);
    }
    
    .admin-sidebar::-webkit-scrollbar-thumb {
        background: var(--gray-300);
        border-radius: var(--rounded-full);
    }
    
    .admin-sidebar::-webkit-scrollbar-thumb:hover {
        background: var(--gray-400);
    }
    
    /* Sidebar Header */
    .sidebar-header {
        padding: var(--space-6);
        border-bottom: 1px solid var(--border-light);
        background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        color: var(--white);
        position: relative;
        overflow: hidden;
    }
    
    .sidebar-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg width="40" height="40" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg"><g fill="rgba(255,255,255,0.1)" fill-opacity="0.4"><path d="m0 40l40-40h-40z"/><path d="m0 0h40l-40 40z"/></g></svg>');
        opacity: 0.3;
    }
    
    .sidebar-logo {
        display: flex;
        align-items: center;
        gap: var(--space-3);
        text-decoration: none;
        color: inherit;
        position: relative;
        z-index: 1;
        transition: var(--transition-normal);
    }
    
    .admin-sidebar.collapsed .sidebar-logo .logo-text {
        display: none;
    }
    
    .logo-icon {
        width: 48px;
        height: 48px;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        border-radius: var(--rounded-xl);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: var(--text-2xl);
        font-weight: var(--font-bold);
        flex-shrink: 0;
        border: 1px solid rgba(255, 255, 255, 0.3);
    }
    
    .logo-text {
        display: flex;
        flex-direction: column;
        transition: var(--transition-normal);
    }
    
    .logo-title {
        font-family: var(--font-heading);
        font-size: var(--text-xl);
        font-weight: var(--font-bold);
        line-height: 1.2;
    }
    
    .logo-subtitle {
        font-size: var(--text-xs);
        opacity: 0.8;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    /* Sidebar Navigation */
    .sidebar-nav {
        padding: var(--space-4) 0;
    }
    
    .nav-section {
        margin-bottom: var(--space-6);
    }
    
    .nav-section-title {
        padding: 0 var(--space-6) var(--space-3);
        font-size: var(--text-xs);
        font-weight: var(--font-bold);
        color: var(--text-tertiary);
        text-transform: uppercase;
        letter-spacing: 1px;
        transition: var(--transition-normal);
    }
    
    .admin-sidebar.collapsed .nav-section-title {
        opacity: 0;
        padding-left: var(--space-3);
    }
    
    .nav-menu {
        list-style: none;
    }
    
    .nav-item {
        margin-bottom: var(--space-1);
    }
    
    .nav-link {
        display: flex;
        align-items: center;
        gap: var(--space-3);
        padding: var(--space-3) var(--space-6);
        color: var(--text-primary);
        text-decoration: none;
        font-weight: var(--font-medium);
        font-size: var(--text-sm);
        transition: var(--transition-normal);
        position: relative;
        border-radius: 0;
        margin: 0 var(--space-3);
        border-radius: var(--rounded-lg);
    }
    
    .nav-link:hover {
        background: var(--gray-100);
        color: var(--primary);
        transform: translateX(4px);
    }
    
    .nav-link.active {
        background: linear-gradient(135deg, rgba(30, 64, 175, 0.1) 0%, rgba(124, 58, 237, 0.1) 100%);
        color: var(--primary);
        font-weight: var(--font-semibold);
        border-left: 3px solid var(--primary);
        padding-left: calc(var(--space-6) - 3px);
    }
    
    .nav-link.active::before {
        content: '';
        position: absolute;
        right: var(--space-3);
        top: 50%;
        transform: translateY(-50%);
        width: 6px;
        height: 6px;
        background: var(--primary);
        border-radius: var(--rounded-full);
    }
    
    .nav-icon {
        font-size: var(--text-lg);
        flex-shrink: 0;
        width: 24px;
        text-align: center;
    }
    
    .nav-text {
        flex: 1;
        transition: var(--transition-normal);
    }
    
    .admin-sidebar.collapsed .nav-text {
        opacity: 0;
        transform: translateX(-10px);
    }
    
    .nav-badge {
        background: var(--secondary);
        color: var(--white);
        font-size: var(--text-xs);
        font-weight: var(--font-bold);
        padding: var(--space-1) var(--space-2);
        border-radius: var(--rounded-full);
        min-width: 20px;
        text-align: center;
        transition: var(--transition-normal);
    }
    
    .admin-sidebar.collapsed .nav-badge {
        opacity: 0;
        transform: scale(0);
    }
    
    .nav-arrow {
        font-size: var(--text-sm);
        transition: var(--transition-normal);
        color: var(--text-tertiary);
    }
    
    .nav-item.has-submenu .nav-link:hover .nav-arrow,
    .nav-item.has-submenu.open .nav-arrow {
        transform: rotate(90deg);
    }
    
    .admin-sidebar.collapsed .nav-arrow {
        display: none;
    }
    
    /* Submenu */
    .nav-submenu {
        list-style: none;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
        background: var(--gray-50);
        margin: var(--space-2) var(--space-3) 0;
        border-radius: var(--rounded-lg);
    }
    
    .nav-item.open .nav-submenu {
        max-height: 500px;
        padding: var(--space-2) 0;
    }
    
    .nav-submenu .nav-link {
        padding: var(--space-2) var(--space-4);
        margin: 0 var(--space-2);
        font-size: var(--text-xs);
        color: var(--text-secondary);
        border-left: none;
    }
    
    .nav-submenu .nav-link:hover {
        background: var(--white);
        color: var(--primary);
        transform: translateX(2px);
    }
    
    .nav-submenu .nav-link.active {
        background: var(--white);
        color: var(--primary);
        font-weight: var(--font-semibold);
        border-left: 2px solid var(--primary);
        padding-left: calc(var(--space-4) - 2px);
    }
    
    .admin-sidebar.collapsed .nav-submenu {
        display: none;
    }
    
    /* Sidebar Footer */
    .sidebar-footer {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: var(--space-4) var(--space-6);
        border-top: 1px solid var(--border-light);
        background: var(--gray-50);
    }
    
    .sidebar-user {
        display: flex;
        align-items: center;
        gap: var(--space-3);
        padding: var(--space-3);
        border-radius: var(--rounded-lg);
        background: var(--surface);
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--border-light);
        transition: var(--transition-normal);
    }
    
    .sidebar-user:hover {
        box-shadow: var(--shadow-md);
    }
    
    .user-avatar {
        width: 36px;
        height: 36px;
        border-radius: var(--rounded-full);
        background: var(--primary-gradient);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--white);
        font-weight: var(--font-bold);
        font-size: var(--text-sm);
        flex-shrink: 0;
    }
    
    .user-info {
        flex: 1;
        min-width: 0;
        transition: var(--transition-normal);
    }
    
    .admin-sidebar.collapsed .user-info {
        opacity: 0;
        transform: translateX(-10px);
    }
    
    .user-name {
        font-weight: var(--font-semibold);
        font-size: var(--text-sm);
        color: var(--text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .user-role {
        font-size: var(--text-xs);
        color: var(--text-secondary);
        text-transform: capitalize;
    }
    
    .sidebar-version {
        text-align: center;
        padding-top: var(--space-3);
        font-size: var(--text-xs);
        color: var(--text-tertiary);
        transition: var(--transition-normal);
    }
    
    .admin-sidebar.collapsed .sidebar-version {
        opacity: 0;
    }
    
    /* Tooltip for collapsed sidebar */
    .nav-tooltip {
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        background: var(--gray-900);
        color: var(--white);
        padding: var(--space-2) var(--space-3);
        border-radius: var(--rounded-lg);
        font-size: var(--text-sm);
        font-weight: var(--font-medium);
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        transition: var(--transition-normal);
        z-index: 50;
        margin-left: var(--space-2);
        box-shadow: var(--shadow-lg);
    }
    
    .nav-tooltip::before {
        content: '';
        position: absolute;
        top: 50%;
        left: -4px;
        transform: translateY(-50%);
        border: 4px solid transparent;
        border-right-color: var(--gray-900);
    }
    
    .admin-sidebar.collapsed .nav-link:hover .nav-tooltip {
        opacity: 1;
        visibility: visible;
    }
    
    /* Mobile responsiveness */
    @media (max-width: 1024px) {
        .admin-sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }
        
        .admin-sidebar.mobile-open {
            transform: translateX(0);
        }
        
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 35;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition-normal);
        }
        
        .sidebar-overlay.show {
            opacity: 1;
            visibility: visible;
        }
    }
    
    /* Focus styles for accessibility */
    .nav-link:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }
    
    /* Reduced motion support */
    @media (prefers-reduced-motion: reduce) {
        .admin-sidebar,
        .nav-link,
        .nav-submenu,
        .nav-arrow {
            transition: none !important;
        }
    }
</style>

<aside class="admin-sidebar" id="admin-sidebar">
    <!-- Sidebar Header -->
    <div class="sidebar-header">
        <a href="dashboard.php" class="sidebar-logo">
            <div class="logo-icon">🛒</div>
            <div class="logo-text">
                <div class="logo-title"><?php echo htmlspecialchars($site_name ?? 'CarousellVN'); ?></div>
                <div class="logo-subtitle">Admin Panel</div>
            </div>
        </a>
    </div>
    
    <!-- Sidebar Navigation -->
    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">Chính</div>
            <ul class="nav-menu">
                <?php foreach ($menu_items as $item): ?>
                    <?php if (hasAccess($item['roles'], $admin_role ?? 'staff')): ?>
                        <li class="nav-item <?php echo !empty($item['submenu']) ? 'has-submenu' : ''; ?> <?php echo isActive($item['id'], $current_page, $current_dir, $item['submenu'] ?? null) ? 'open' : ''; ?>">
                            <a href="<?php echo $item['url']; ?>" 
                               class="nav-link <?php echo isActive($item['id'], $current_page, $current_dir, $item['submenu'] ?? null) ? 'active' : ''; ?>"
                               <?php if (!empty($item['submenu'])): ?>
                                   onclick="toggleSubmenu(event, this)"
                               <?php endif; ?>>
                                <span class="nav-icon"><?php echo $item['icon']; ?></span>
                                <span class="nav-text"><?php echo htmlspecialchars($item['title']); ?></span>
                                <?php if ($item['badge']): ?>
                                    <span class="nav-badge"><?php echo $item['badge']; ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item['submenu'])): ?>
                                    <span class="nav-arrow">▶</span>
                                <?php endif; ?>
                                <div class="nav-tooltip"><?php echo htmlspecialchars($item['title']); ?></div>
                            </a>
                            
                            <?php if (!empty($item['submenu'])): ?>
                                <ul class="nav-submenu">
                                    <?php foreach ($item['submenu'] as $sub_item): ?>
                                        <li>
                                            <?php 
                                            $sub_url_parts = explode('/', $sub_item['url']);
                                            $sub_page = basename(end($sub_url_parts), '.php');
                                            $sub_dir = count($sub_url_parts) > 1 ? $sub_url_parts[0] : '';
                                            $sub_active = ($sub_page === $current_page || $sub_dir === $current_dir);
                                            ?>
                                            <a href="<?php echo $sub_item['url']; ?>" 
                                               class="nav-link <?php echo $sub_active ? 'active' : ''; ?>">
                                                <span class="nav-icon"><?php echo $sub_item['icon']; ?></span>
                                                <span class="nav-text"><?php echo htmlspecialchars($sub_item['title']); ?></span>
                                                <div class="nav-tooltip"><?php echo htmlspecialchars($sub_item['title']); ?></div>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </nav>
    
    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar">
                <?php if (isset($admin_avatar) && $admin_avatar && file_exists($admin_avatar)): ?>
                    <img src="<?php echo htmlspecialchars($admin_avatar); ?>" alt="Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <?php echo strtoupper(substr($admin_name ?? 'A', 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($admin_name ?? 'Admin'); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($admin_role ?? 'Administrator'); ?></div>
            </div>
        </div>
        
        <div class="sidebar-version">
            v3.0 Enhanced
        </div>
    </div>
</aside>

<!-- Mobile Overlay -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeMobileSidebar()"></div>

<script>
    // Submenu toggle functionality
    function toggleSubmenu(event, element) {
        if (window.innerWidth > 1024) {
            const navItem = element.closest('.nav-item');
            const isCollapsed = document.querySelector('.admin-sidebar').classList.contains('collapsed');
            
            if (!isCollapsed && navItem.classList.contains('has-submenu')) {
                event.preventDefault();
                navItem.classList.toggle('open');
                
                // Close other open submenus
                const otherOpenItems = document.querySelectorAll('.nav-item.open');
                otherOpenItems.forEach(item => {
                    if (item !== navItem) {
                        item.classList.remove('open');
                    }
                });
            }
        }
    }

    // Mobile sidebar functionality
    function toggleMobileSidebar() {
        const sidebar = document.getElementById('admin-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        
        sidebar.classList.toggle('mobile-open');
        overlay.classList.toggle('show');
    }

    function closeMobileSidebar() {
        const sidebar = document.getElementById('admin-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('show');
    }

    // Handle mobile menu toggle from main dashboard
    if (typeof window.toggleSidebar === 'undefined') {
        window.toggleSidebar = function() {
            if (window.innerWidth <= 1024) {
                toggleMobileSidebar();
            }
        };
    }

    // Auto-close mobile sidebar when clicking on nav links
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 1024 && !this.closest('.nav-item').classList.contains('has-submenu')) {
                closeMobileSidebar();
            }
        });
    });

    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 1024) {
            closeMobileSidebar();
        }
    });

    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        // ESC to close mobile sidebar
        if (e.key === 'Escape' && window.innerWidth <= 1024) {
            closeMobileSidebar();
        }
        
        // Alt + M to toggle mobile sidebar
        if (e.altKey && e.key === 'm') {
            e.preventDefault();
            if (window.innerWidth <= 1024) {
                toggleMobileSidebar();
            }
        }
    });

    // Auto-open submenu for active items on page load
    document.addEventListener('DOMContentLoaded', function() {
        const activeNavItem = document.querySelector('.nav-item .nav-link.active');
        if (activeNavItem) {
            const parentNavItem = activeNavItem.closest('.nav-item.has-submenu');
            if (parentNavItem) {
                parentNavItem.classList.add('open');
            }
        }
    });

    // Smooth scrolling for sidebar navigation
    function scrollToActiveItem() {
        const activeItem = document.querySelector('.nav-link.active');
        if (activeItem) {
            activeItem.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }
    }

    // Call scroll to active item after a short delay
    setTimeout(scrollToActiveItem, 500);

    console.log('🎛️ Admin Sidebar loaded successfully');
</script>