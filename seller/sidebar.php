<?php
// sidebar.php - Component sidebar cho seller
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>

<style>
/* Sidebar CSS - Gộp chung với PHP */
.sidebar {
    width: 280px;
    height: 100vh;
    background: #ffffff;
    border-right: 1px solid #e5e7eb;
    position: fixed;
    left: 0;
    top: 0;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
}

.sidebar-header {
    padding: 20px;
    border-bottom: 1px solid #e5e7eb;
}

.logo {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
}

.logo-image {
    width: 200px;
    height: 140px;
    border-radius: 8px;
    object-fit: contain;
    flex-shrink: 0;
    background: #f8f9fa;
    padding: 4px;
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.btn {
    padding: 8px 16px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
    text-align: center;
    flex: 1;
    transition: all 0.3s ease;
}

.btn-primary {
    background: #10b981;
    color: white;
}

.btn-primary:hover {
    background: #059669;
}

.btn-secondary {
    background: #3b82f6;
    color: white;
}

.btn-secondary:hover {
    background: #2563eb;
}

.sidebar-content {
    padding: 20px 0;
}

.search-box {
    position: relative;
    margin: 0 20px 20px 20px;
}

.search-input {
    width: 100%;
    padding: 8px 12px 8px 36px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    background: #f9fafb;
}

.search-input:focus {
    outline: none;
    border-color: #ff0050;
    background: white;
}

.search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
}

.nav-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.nav-item {
    margin-bottom: 2px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    color: #4b5563;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
    position: relative;
}

.nav-link:hover {
    background: #f3f4f6;
    color: #ff0050;
}

.nav-item.active .nav-link {
    background: #fef2f2;
    color: #ff0050;
    border-right: 3px solid #ff0050;
}

.nav-link svg {
    flex-shrink: 0;
}

.has-submenu .submenu-arrow {
    margin-left: auto;
    transition: transform 0.3s ease;
}

.has-submenu.open .submenu-arrow {
    transform: rotate(180deg);
}

.submenu {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
    background: #f9fafb;
}

.has-submenu.open .submenu {
    max-height: 200px;
}

.submenu li {
    margin: 0;
}

.submenu a {
    display: block;
    padding: 8px 20px 8px 52px;
    color: #6b7280;
    text-decoration: none;
    font-size: 13px;
    transition: all 0.3s ease;
}

.submenu a:hover {
    background: #f3f4f6;
    color: #ff0050;
}

@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }

    .sidebar.open {
        transform: translateX(0);
    }
}
</style>

<div class="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <img src="../logo.png" alt="Logo" class="logo-image">
        </div>

        <div class="action-buttons">
            <a href="deposit.php" class="btn btn-primary">Nạp tiền</a>
            <a href="withdraw.php" class="btn btn-secondary">Rút tiền</a>
        </div>
    </div>

    <div class="sidebar-content">
        <div class="search-box">
            <input type="text" placeholder="Tìm kiếm trong menu" class="search-input">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                <path
                    d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
            </svg>
        </div>

        <nav class="sidebar-nav">
            <ul class="nav-list">
                <li class="nav-item <?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">
                    <a href="dashboard.php" class="nav-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z" />
                        </svg>
                        <span>Bảng điều khiển</span>
                    </a>
                </li>

                <li class="nav-item <?php echo $current_page == 'info' ? 'active' : ''; ?>">
                    <a href="info.php" class="nav-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                        </svg>
                        <span>Tin nhận</span>
                    </a>
                </li>

                <li
                    class="nav-item has-submenu <?php echo in_array($current_page, ['products', 'add-product', 'categories']) ? 'active' : ''; ?>">
                    <a href="#" class="nav-link" onclick="toggleSubmenu(this)">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M19 7h-3V6c0-1.1-.9-2-2-2H10c-1.1 0-2 .9-2 2v1H5c-1.1 0-2 .9-2 2v11c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V9c0-1.1-.9-2-2-2zM10 6h4v1h-4V6zm9 15H5V9h14v12z" />
                        </svg>
                        <span>Các sản phẩm</span>
                        <svg class="submenu-arrow" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z" />
                        </svg>
                    </a>
                    <ul class="submenu">
                        <li><a href="products.php">Tất cả sản phẩm</a></li>
                        <li><a href="add-product.php">Thêm sản phẩm mới</a></li>
                        <li><a href="product-list.php">Chọn sản phẩm bán nhanh</a></li>
                    </ul>
                </li>

                <li class="nav-item has-submenu">
                    <a href="#" class="nav-link" onclick="toggleSubmenu(this)">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M20 6h-2.18c.11-.31.18-.65.18-1a2.996 2.996 0 0 0-5.5-1.65l-.5.67-.5-.68C10.96 2.54 10.05 2 9 2 7.34 2 6 3.34 6 5c0 .35.07.69.18 1H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2z" />
                        </svg>
                        <span>Package</span>
                        <svg class="submenu-arrow" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z" />
                        </svg>
                    </a>
                    <ul class="submenu">
                        <li><a href="packages.php">Tất cả gói</a></li>

                    </ul>
                </li>

                <li class="nav-item">
                    <a href="purchase.php" class="nav-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M19 7h-3V6c0-1.1-.9-2-2-2H10c-1.1 0-2 .9-2 2v1H5c-1.1 0-2 .9-2 2v11c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V9c0-1.1-.9-2-2-2z" />
                        </svg>
                        <span>Phiếu mua hàng</span>
                    </a>
                </li>

                <li class="nav-item has-submenu">
                    <a href="#" class="nav-link" onclick="toggleSubmenu(this)">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M7 4V2c0-.55-.45-1-1-1s-1 .45-1 1v2H3c-.55 0-1 .45-1 1s.45 1 1 1h2v2c0 .55.45 1 1 1s1-.45 1-1V6h2c.55 0 1-.45 1-1s-.45-1-1-1H7zM12 9c.55 0 1-.45 1-1V7c0-.55-.45-1-1-1s-1 .45-1 1v1c0 .55.45 1 1 1z" />
                        </svg>
                        <span>Hệ thống POS</span>
                        <svg class="submenu-arrow" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z" />
                        </svg>
                    </a>
                    <ul class="submenu">
                        <li><a href="pos.php">POS System</a></li>
                        <li><a href="pos-settings.php">Cài đặt</a></li>
                    </ul>
                </li>

                <li class="nav-item <?php echo $current_page == 'orders' ? 'active' : ''; ?>">
                    <a href="orders.php" class="nav-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z" />
                        </svg>
                        <span>Đơn hàng</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="store-settings.php" class="nav-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z" />
                        </svg>
                        <span>Cài đặt cửa hàng</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="finance.php" class="nav-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z" />
                        </svg>
                        <span>Lịch sử thanh toán</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="withdraw.php" class="nav-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M5 6h14l-1-1H6l-1 1zM6 8v10h12V8H6zm6 9l-4-4h3V9h2v4h3l-4 4z" />
                        </svg>
                        <span>Rút tiền</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="query-products.php" class="nav-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
                        </svg>
                        <span>Truy vấn sản phẩm</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="support.php" class="nav-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M21 6h-2l-9-4-9 4v7c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V6z" />
                        </svg>
                        <span>Hỗ trợ nhanh</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</div>

<script>
// Sidebar JavaScript - Gộp chung với PHP
function toggleSubmenu(element) {
    event.preventDefault(); // Ngăn không cho link reload trang
    const navItem = element.parentElement;
    navItem.classList.toggle('open');
}

// Search functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('.search-input');
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const navItems = document.querySelectorAll('.nav-item');

            navItems.forEach(item => {
                const text = item.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }
});

// Mobile sidebar toggle
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.classList.toggle('open');
    }
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(e) {
    const sidebar = document.querySelector('.sidebar');
    const isMobile = window.innerWidth <= 768;

    if (isMobile && sidebar && !sidebar.contains(e.target) && !e.target.closest('.mobile-menu-btn')) {
        sidebar.classList.remove('open');
    }
});

// Handle window resize
window.addEventListener('resize', function() {
    const sidebar = document.querySelector('.sidebar');
    if (window.innerWidth > 768 && sidebar) {
        sidebar.classList.remove('open');
    }
});
</script>