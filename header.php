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
            <form action="search.php" method="GET" class="search-form">
                <input type="text" name="q" class="search-input"
                    placeholder="Tìm kiếm sản phẩm/nhà cung cấp"
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