<?php
// Include constants
require_once __DIR__ . '/constants.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not logged in (optional - can be controlled by $require_login variable)
if (isset($require_login) && $require_login && !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get user info if logged in
$user_id = $_SESSION['user_id'] ?? null;
$user_name = $_SESSION['user_name'] ?? 'Khách';
$user_type = $_SESSION['user_type'] ?? 'guest';

// Get cart count if user is logged in
if ($user_id) {
    try {
        require_once __DIR__ . '/config.php';
        $stmt = $pdo->prepare("SELECT SUM(quantity) as cart_count FROM carts WHERE user_id = ? AND status = 1");
        $stmt->execute([$user_id]);
        $cart_result = $stmt->fetch();
        $cart_count = $cart_result['cart_count'] ?? 0;
    } catch (PDOException $e) {
        $cart_count = 0;
    }
} else {
    $cart_count = 0;
}

// Set current page for navigation highlighting
$current_page = $current_page ?? basename($_SERVER['PHP_SELF'], '.php');
?>

<!-- Header -->
<header class="header">
    <div class="header-container">
        <a href="index.php" class="logo">
            <img src="logo.png" alt="<?php echo SITE_NAME; ?>" class="logo-img">
        </a>

        <div class="search-container">
            <form action="search.php" method="GET" style="display: flex; width: 100%;">
                <input type="text" name="q" class="search-input"
                    placeholder="Tìm kiếm thương hiệu/sản phẩm/nhà cung cấp"
                    value="<?php echo isset($_GET['q']) ? htmlspecialchars($_GET['q']) : ''; ?>">
                <button type="submit" class="search-btn">Tìm kiếm</button>
            </form>
        </div>

        <div class="user-section">
            <?php if ($user_id): ?>
            <span class="welcome-text">Xin chào, <strong><?php echo htmlspecialchars($user_name); ?></strong>!</span>
            <a href="cart.php" class="cart-link">
                🛒 Giỏ hàng
                <?php if ($cart_count > 0): ?>
                <span class="cart-badge"><?php echo $cart_count; ?></span>
                <?php endif; ?>
            </a>
            <div class="user-dropdown">
                <button class="user-dropdown-btn">👤</button>
                <div class="user-dropdown-menu">
                    <a href="profile.php" class="dropdown-item">Thông tin cá nhân</a>
                    <a href="orders.php" class="dropdown-item">Đơn hàng của tôi</a>
                    <a href="wishlist.php" class="dropdown-item">Yêu thích</a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item">Đăng xuất</a>
                </div>
            </div>
            <?php else: ?>
            <a href="login.php" class="user-links">Đăng nhập</a>
            <span>hoặc</span>
            <a href="register.php" class="user-links">Đăng ký</a>
            <?php endif; ?>

        </div>
    </div>
</header>

<!-- Navigation -->
<nav class="nav">
    <div class="nav-container">
        <?php foreach ($main_menu as $key => $item): ?>
        <a href="<?php echo $item['url']; ?>" class="nav-link <?php echo ($current_page === $key ||
                   ($key === 'categories' && $current_page === 'category') ||
                   ($key === 'products' && in_array($current_page, ['product-detail', 'products'])))
                   ? 'active' : ''; ?>">
            <?php echo $item['title']; ?>
        </a>
        <?php endforeach; ?>
    </div>
</nav>

<style>
/* Additional styles for header enhancements */
.welcome-text {
    font-size: 14px;
    color: #333;
}

.user-dropdown {
    position: relative;
}

.user-dropdown-btn {
    background: #f8f9fa;
    border: none;
    border-radius: 50%;
    width: 35px;
    height: 35px;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.user-dropdown-btn:hover {
    background: #e9ecef;
    transform: scale(1.05);
}

.user-dropdown-menu {
    position: absolute;
    top: 100%;
    right: 0;
    background: white;
    border-radius: 8px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    min-width: 180px;
    padding: 8px 0;
    z-index: 1000;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.3s ease;
    border: 1px solid #e1e8ed;
}

.user-dropdown:hover .user-dropdown-menu {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.dropdown-item {
    display: block;
    padding: 10px 16px;
    color: #333;
    text-decoration: none;
    transition: background 0.3s ease;
    font-size: 14px;
}

.dropdown-item:hover {
    background: #f8f9fa;
    color: #1877f2;
}

.dropdown-divider {
    height: 1px;
    background: #e1e8ed;
    margin: 8px 0;
}

.flag {
    width: 20px;
    height: 15px;
    background: linear-gradient(to bottom, #ff0000 33%, #ffff00 33%, #ffff00 66%, #ff0000 66%);
    border-radius: 2px;
    cursor: pointer;
    transition: transform 0.3s ease;
}

.flag:hover {
    transform: scale(1.1);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .welcome-text {
        display: none;
    }

    .user-section {
        gap: 10px;
    }

    .user-dropdown-menu {
        right: -20px;
    }
}
</style>

<script>
// Header JavaScript functionality
document.addEventListener('DOMContentLoaded', function() {
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const dropdown = document.querySelector('.user-dropdown');
        if (dropdown && !dropdown.contains(e.target)) {
            dropdown.classList.remove('show');
        }
    });

    // Search functionality
    const searchForm = document.querySelector('.search-container form');
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            const query = document.querySelector('.search-input').value.trim();
            if (!query) {
                e.preventDefault();
                alert('Vui lòng nhập từ khóa tìm kiếm');
            }
        });
    }

    // Auto-complete search (optional enhancement)
    const searchInput = document.querySelector('.search-input');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();

            if (query.length >= 2) {
                searchTimeout = setTimeout(() => {
                    // Implement search suggestions here
                    // fetchSearchSuggestions(query);
                }, 300);
            }
        });
    }

    // Smooth scroll for navigation links
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', function(e) {
            // Add smooth transition effect
            this.style.transform = 'scale(0.95)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 100);
        });
    });
});

// Global functions for cart updates
window.updateCartCount = function() {
    fetch('get-cart-count.php')
        .then(response => response.json())
        .then(data => {
            const cartBadge = document.querySelector('.cart-badge');
            const cartLink = document.querySelector('.cart-link');

            if (data.count > 0) {
                if (cartBadge) {
                    cartBadge.textContent = data.count;
                } else if (cartLink) {
                    cartLink.innerHTML += `<span class="cart-badge">${data.count}</span>`;
                }
            } else if (cartBadge) {
                cartBadge.remove();
            }
        })
        .catch(error => console.error('Error updating cart count:', error));
};

// Global notification function
window.showNotification = function(message, type = 'info') {
    // Remove existing notifications
    document.querySelectorAll('.notification').forEach(n => n.remove());

    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;

    // Add to page
    document.body.appendChild(notification);

    // Remove after 3 seconds
    setTimeout(() => {
        notification.remove();
    }, 3000);
};
</script>