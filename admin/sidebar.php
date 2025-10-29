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
        'id' => 'analytics',
        'title' => 'Thống kê',
        'icon' => '�',
        'url' => 'analytics.php',
        'roles' => ['admin'],
        'badge' => null
    ],
    [
        'id' => 'products',
        'title' => 'Sản phẩm',
        'icon' => '📦',
        'url' => 'products.php',
        'roles' => ['admin', 'staff'],
        'badge' => null
    ],
    [
        'id' => 'categories',
        'title' => 'Danh mục',
        'icon' => '�',
        'url' => 'categories.php',
        'roles' => ['admin', 'staff'],
        'badge' => null
    ],
    [
        'id' => 'brands',
        'title' => 'Thương hiệu',
        'icon' => '🏷️',
        'url' => 'brands.php',
        'roles' => ['admin'],
        'badge' => null
    ],
    [
        'id' => 'orders',
        'title' => 'Đơn hàng',
        'icon' => '�',
        'url' => 'orders.php',
        'roles' => ['admin', 'staff'],
        'badge' => null
    ],
    [
        'id' => 'users',
        'title' => 'Khách hàng',
        'icon' => '�',
        'url' => 'users.php',
        'roles' => ['admin', 'staff'],
        'badge' => null
    ],
    [
        'id' => 'reviews',
        'title' => 'Đánh giá',
        'icon' => '⭐',
        'url' => 'reviews.php',
        'roles' => ['admin', 'staff'],
        'badge' => null
    ],
    [
        'id' => 'flash-deals',
        'title' => 'Flash Deals',
        'icon' => '⚡',
        'url' => 'flash-deals.php',
        'roles' => ['admin'],
        'badge' => null
    ],
    [
        'id' => 'coupons',
        'title' => 'Mã giảm giá',
        'icon' => '🎫',
        'url' => 'coupons.php',
        'roles' => ['admin'],
        'badge' => null
    ],
    [
        'id' => 'banners',
        'title' => 'Banners',
        'icon' => '�️',
        'url' => 'banners.php',
        'roles' => ['admin'],
        'badge' => null
    ],
    [
        'id' => 'contacts',
        'title' => 'Liên hệ',
        'icon' => '�',
        'url' => 'contacts.php',
        'roles' => ['admin', 'staff'],
        'badge' => null
    ],
    [
        'id' => 'staff',
        'title' => 'Nhân viên',
        'icon' => '👨‍💼',
        'url' => 'staff.php',
        'roles' => ['admin'],
        'badge' => null
    ],
    [
        'id' => 'pos',
        'title' => 'POS',
        'icon' => '�',
        'url' => 'pos.php',
        'roles' => ['admin', 'staff'],
        'badge' => null
    ],
    [
        'id' => 'backups',
        'title' => 'Sao lưu',
        'icon' => '💾',
        'url' => 'backups.php',
        'roles' => ['admin'],
        'badge' => null
    ],
    [
        'id' => 'settings',
        'title' => 'Cài đặt',
        'icon' => '⚙️',
        'url' => 'settings.php',
        'roles' => ['admin'],
        'badge' => null
    ]
];

// Function to check if user has access to menu item
function hasAccess($item_roles, $user_role) {
    return in_array($user_role, $item_roles);
}

// Function to check if menu item is active
function isActive($item_id, $current_page) {
    return $item_id === $current_page;
}
?>

<aside class="sidebar" id="sidebar">
    <!-- Sidebar Header -->
    <div class="sidebar-header">
        <a href="dashboard.php" class="sidebar-logo">
            <div class="logo-icon">🛒</div>
            <div class="logo-text">
                <div class="logo-title"><?php echo htmlspecialchars($site_name ?? 'TikTokOnes'); ?></div>
                <div class="logo-subtitle">Admin Panel</div>
            </div>
        </a>
    </div>
    
    <!-- Sidebar Navigation -->
    <nav class="sidebar-nav">
        <ul class="nav-menu">
            <?php foreach ($menu_items as $item): ?>
                <?php if (hasAccess($item['roles'], $admin_role ?? 'staff')): ?>
                    <li class="nav-item">
                        <a href="<?php echo $item['url']; ?>" 
                           class="nav-link <?php echo isActive($item['id'], $current_page) ? 'active' : ''; ?>">
                            <span class="nav-text"><?php echo htmlspecialchars($item['title']); ?></span>
                            <?php if ($item['badge']): ?>
                                <span class="nav-badge"><?php echo $item['badge']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </nav>
    
    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar">
                <?php if (isset($admin_avatar) && $admin_avatar && file_exists($admin_avatar)): ?>
                    <img src="<?php echo htmlspecialchars($admin_avatar); ?>" alt="Avatar">
                <?php else: ?>
                    <?php echo strtoupper(substr($admin_name ?? 'A', 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($admin_name ?? 'Admin'); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($admin_role ?? 'Administrator'); ?></div>
            </div>
        </div>
    </div>
</aside>

<!-- Mobile Overlay -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeMobileSidebar()"></div>

<script>
// Mobile sidebar functionality
function toggleMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    
    if (sidebar && overlay) {
        sidebar.classList.toggle('mobile-open');
        overlay.classList.toggle('show');
    }
}

function closeMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    
    if (sidebar && overlay) {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('show');
    }
}

// Handle mobile menu toggle
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
        if (window.innerWidth <= 1024) {
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
});

console.log('🎛️ Admin Sidebar loaded successfully');
</script>