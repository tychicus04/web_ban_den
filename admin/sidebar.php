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

    <link rel="stylesheet" href="../asset/css/pages/admin-sidebar.css">

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