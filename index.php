<?php
session_start();
require_once 'config.php';

// Set page-specific variables
$current_page = 'index';
$require_login = true; // Set to false if you want to allow guests

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get user info
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_type = $_SESSION['user_type'];

// Pagination parameters
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 30; // Products per page
$offset = ($page - 1) * $limit;

// Get featured products with images
try {
    $stmt = $pdo->prepare("SELECT p.*, u.name as seller_name, 
                          thumb.file_name as thumbnail_file,
                          COALESCE(p.current_stock, 0) as stock_quantity
                          FROM products p 
                          LEFT JOIN users u ON p.user_id = u.id 
                          LEFT JOIN uploads thumb ON p.thumbnail_img = thumb.id AND thumb.deleted_at IS NULL
                          WHERE p.published = 1 AND p.approved = 1 AND p.featured = 1
                          ORDER BY p.created_at DESC LIMIT 6");
    $stmt->execute();
    $featured_products = $stmt->fetchAll();
} catch (PDOException $e) {
    $featured_products = [];
}

// Get all products for "All Products" section
try {
    $stmt = $pdo->prepare("SELECT p.*, u.name as seller_name, 
                          thumb.file_name as thumbnail_file,
                          COALESCE(p.current_stock, 0) as stock_quantity
                          FROM products p 
                          LEFT JOIN users u ON p.user_id = u.id 
                          LEFT JOIN uploads thumb ON p.thumbnail_img = thumb.id AND thumb.deleted_at IS NULL
                          WHERE p.published = 1 AND p.approved = 1 
                          ORDER BY p.created_at DESC 
                          LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $all_products = $stmt->fetchAll();

    // Get total count for pagination
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE p.published = 1 AND p.approved = 1");
    $count_stmt->execute();
    $total_products = $count_stmt->fetchColumn();
    $total_pages = ceil($total_products / $limit);
} catch (PDOException $e) {
    $all_products = [];
    $total_products = 0;
    $total_pages = 1;
}

// Get categories
try {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE featured = 1 ORDER BY order_level LIMIT 15");
    $stmt->execute();
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    $categories = [];
}



// Function to get product image
function getProductImage($product, $pdo = null)
{
    // Priority: thumbnail from JOIN
    if (!empty($product['thumbnail_file'])) {
        return $product['thumbnail_file'];
    }
    // Backup: get first image from photos JSON
    elseif (!empty($product['photos']) && $pdo) {
        $photos_json = json_decode($product['photos'], true);
        if (is_array($photos_json) && !empty($photos_json)) {
            try {
                $first_photo_id = $photos_json[0];
                $stmt_img = $pdo->prepare("SELECT file_name FROM uploads WHERE id = ? AND deleted_at IS NULL");
                $stmt_img->execute([$first_photo_id]);
                $img_result = $stmt_img->fetch();
                if ($img_result) {
                    return $img_result['file_name'];
                }
            } catch (PDOException $e) {
                // Ignore error
            }
        }
    }
    return '';
}

// Function to calculate discounted price
function getDiscountedPrice($product)
{
    $price = $product['unit_price'];
    if ($product['discount'] > 0) {
        if ($product['discount_type'] === 'percent') {
            $price = $price - (($price * $product['discount']) / 100);
        } else {
            $price = $price - $product['discount'];
        }
    }
    return max(0, $price);
}

// Function to format discount percentage
function getDiscountPercentage($product)
{
    if ($product['discount'] <= 0) return 0;
    
    if ($product['discount_type'] === 'percent') {
        return $product['discount'];
    } else {
        return round(($product['discount'] / $product['unit_price']) * 100);
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TikTok Shop - Nền tảng thương mại điện tử hàng đầu Việt Nam</title>
    <meta name="description"
        content="TikTok Shop - Mua sắm online với hàng triệu sản phẩm chính hãng, giá tốt nhất. Miễn phí vận chuyển, thanh toán an toàn, đổi trả dễ dàng.">
    <meta name="keywords" content="mua sắm online, thương mại điện tử, sản phẩm chính hãng, giá rẻ">
    <?php require_once 'csrf.php'; echo csrfTokenMeta(); ?>
    <link rel="stylesheet" href="asset/base.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">

    <!-- Additional styles for enhanced UI -->

</head>

<body>
    <?php include 'header.php'; ?>

    <!-- Hero Section - Enhanced Banners -->
    <section class="hero-banners">
        <div class="banner-container">
            <!-- Banner 1 - Main -->
            <div class="banner-item banner-main">
                <div class="banner-content">
                    <h2>FLASH SALE</h2>
                    <h3>Giảm đến 70%</h3>
                    <p>Thời trang, điện tử, gia dụng</p>
                    <button class="banner-btn" onclick="scrollToSection('featured')">Mua ngay</button>
                </div>
                <div class="banner-graphic">🛒</div>
            </div>

            <!-- Banner 2 - Freeship -->
            <div class="banner-item banner-secondary">
                <div class="banner-content">
                    <h2>FREESHIP</h2>
                    <h3>Miễn phí vận chuyển</h3>
                    <p>Đơn từ 99K</p>
                    <button class="banner-btn" onclick="window.location.href='#'">Khám phá</button>
                </div>
                <div class="banner-graphic">🚚</div>
            </div>

            <!-- Banner 3 - Voucher -->
            <div class="banner-item banner-tertiary">
                <div class="banner-content">
                    <h2>VOUCHER</h2>
                    <h3>Ưu đãi độc quyền</h3>
                    <p>Dành cho thành viên mới</p>
                    <button class="banner-btn" onclick="window.location.href='#'">Nhận ngay</button>
                </div>
                <div class="banner-graphic">🎁</div>
            </div>
        </div>
    </section>

    <!-- Categories Section -->
    <section class="section" id="featured">
        <div class="section-header">
            <h2 class="section-title">Mua sắm theo danh mục</h2>
            <div class="slider-controls">
                <button class="slider-btn prev-btn" onclick="slideCategories('prev')">‹</button>
                <button class="slider-btn next-btn" onclick="slideCategories('next')">›</button>
            </div>
        </div>

        <div class="categories-slider-container">
            <div class="categories-slider" id="categoriesSlider">
                <?php if (!empty($categories)): ?>
                <?php
                    // Icon mapping for categories
                    $category_icon_map = [
                        'thời trang nữ' => '👗', 'thời trang nam' => '👔', 'điện thoại' => '📱',
                        'máy tính' => '💻', 'laptop' => '💻', 'gia dụng' => '🏠', 'sức khỏe' => '💄',
                        'làm đẹp' => '💄', 'thể thao' => '⚽', 'sách' => '📚', 'đồ chơi' => '🧸',
                        'ô tô' => '🚗', 'xe máy' => '🏍️', 'mẹ và bé' => '👶', 'thú cưng' => '🐕',
                        'nhà cửa' => '🏡', 'văn phòng' => '✏️', 'thực phẩm' => '🍎', 'đồng hồ' => '⌚',
                        'giày dép' => '👟', 'túi ví' => '👜', 'điện tử' => '🔌', 'camera' => '📷',
                        'phụ kiện' => '📦', 'bánh kẹo' => '🍰', 'y tế' => '🏥'
                    ];

                    foreach ($categories as $category):
                        $category_name_lower = strtolower($category['name']);
                        $icon = '📦'; // Default icon
                
                        // Find appropriate icon
                        foreach ($category_icon_map as $keyword => $mapped_icon) {
                            if (strpos($category_name_lower, $keyword) !== false) {
                                $icon = $mapped_icon;
                                break;
                            }
                        }
                        ?>
                <div class="category-slide-item" onclick="navigateToCategory(<?php echo $category['id']; ?>)">
                    <div class="category-icon-wrapper">
                        <span class="category-icon"><?php echo $icon; ?></span>
                    </div>
                    <div class="category-name"><?php echo htmlspecialchars($category['name']); ?></div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <!-- Default categories if database is empty -->
                <?php
                    $default_categories = [
                        ['icon' => '👗', 'name' => 'Thời trang nữ', 'id' => 1],
                        ['icon' => '👔', 'name' => 'Thời trang nam', 'id' => 2],
                        ['icon' => '📱', 'name' => 'Điện thoại & Phụ kiện', 'id' => 3],
                        ['icon' => '💻', 'name' => 'Máy tính & Laptop', 'id' => 4],
                        ['icon' => '🏠', 'name' => 'Gia dụng', 'id' => 5],
                        ['icon' => '💄', 'name' => 'Sức khỏe & Làm đẹp', 'id' => 6],
                        ['icon' => '⚽', 'name' => 'Thể thao & Du lịch', 'id' => 7],
                        ['icon' => '📚', 'name' => 'Sách & Văn phòng phẩm', 'id' => 8],
                        ['icon' => '🧸', 'name' => 'Đồ chơi & Mẹ bé', 'id' => 9],
                        ['icon' => '🚗', 'name' => 'Ô tô & Xe máy', 'id' => 10],
                        ['icon' => '🎮', 'name' => 'Điện tử & Gaming', 'id' => 11],
                        ['icon' => '🍎', 'name' => 'Thực phẩm & Đồ uống', 'id' => 12]
                    ];

                    foreach ($default_categories as $category): ?>
                <div class="category-slide-item" onclick="navigateToCategory(<?php echo $category['id']; ?>)">
                    <div class="category-icon-wrapper">
                        <span class="category-icon"><?php echo $category['icon']; ?></span>
                    </div>
                    <div class="category-name"><?php echo $category['name']; ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>



    <!-- Featured Products Section -->
    <section class="section" id="featured">
        <div class="section-header">
            <h2 class="section-title">⭐ Sản phẩm nổi bật</h2>
            <a href="featured.php" class="view-all">Xem thêm ></a>
        </div>

        <?php if (!empty($featured_products)): ?>
        <div class="products-grid">
            <?php foreach ($featured_products as $product): ?>
            <?php 
                $product_image = getProductImage($product, $pdo); 
                $discounted_price = getDiscountedPrice($product);
                $discount_percentage = getDiscountPercentage($product);
            ?>
            <div class="product-card" onclick="navigateToProduct(<?php echo $product['id']; ?>)">
                <div class="product-image">
                    <?php if ($product_image): ?>
                    <img src="<?php echo htmlspecialchars($product_image); ?>"
                        alt="<?php echo htmlspecialchars($product['name']); ?>"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="product-placeholder" style="display: none;">📦</div>
                    <?php else: ?>
                    <div class="product-placeholder">📦</div>
                    <?php endif; ?>

                    <div class="product-badge">⭐ Nổi bật</div>

                    <?php if ($discount_percentage > 0): ?>
                    <div class="product-discount-badge">-<?php echo round($discount_percentage); ?>%</div>
                    <?php endif; ?>
                </div>
                <div class="product-info">
                    <div class="product-price">
                        <?php if ($product['discount'] > 0): ?>
                        <span
                            class="product-original-price"><?php echo number_format($product['unit_price'], 0, ',', '.'); ?>đ</span>
                        <?php endif; ?>
                        <?php echo number_format($discounted_price, 0, ',', '.'); ?>đ
                    </div>
                    <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                    <div class="product-seller">Bán bởi:
                        <?php echo htmlspecialchars($product['seller_name'] ?: 'TikTok Shop'); ?></div>

                    <?php if ($product['stock_quantity'] > 0): ?>
                    <div class="product-stock <?php echo $product['stock_quantity'] < 10 ? 'low-stock' : ''; ?>">
                        📦 Còn <?php echo $product['stock_quantity']; ?> sản phẩm
                    </div>
                    <?php elseif ($product['stock_quantity'] == 0): ?>
                    <div class="product-stock out-of-stock">❌ Hết hàng</div>
                    <?php endif; ?>

                    <button class="add-to-cart-btn"
                        onclick="event.stopPropagation(); addToCart(<?php echo $product['id']; ?>)"
                        <?php echo $product['stock_quantity'] == 0 ? 'disabled' : ''; ?>>
                        <?php echo $product['stock_quantity'] == 0 ? 'Hết hàng' : 'Thêm vào giỏ'; ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <!-- Sample products if database is empty -->
        <div class="products-grid">
            <?php
                $sample_products = [
                    ['id' => 1, 'name' => 'Áo khoác nam Cardigan', 'price' => 850000, 'original_price' => 1200000],
                    ['id' => 2, 'name' => 'Trang phục hóa trang', 'price' => 650000, 'original_price' => 900000],
                    ['id' => 3, 'name' => 'Quần yoga nữ', 'price' => 390000, 'original_price' => 550000],
                    ['id' => 4, 'name' => 'Bông tai vàng', 'price' => 160000, 'original_price' => 220000],
                    ['id' => 5, 'name' => 'Túi xách nâu', 'price' => 550000, 'original_price' => 750000],
                    ['id' => 6, 'name' => 'Chuột không dây', 'price' => 290000, 'original_price' => 390000]
                ];

                foreach ($sample_products as $product): ?>
            <div class="product-card" onclick="navigateToProduct(<?php echo $product['id']; ?>)">
                <div class="product-image">
                    <div class="product-placeholder">📦</div>
                    <div class="product-badge">⭐ Nổi bật</div>
                    <div class="product-discount-badge">
                        -<?php echo round((($product['original_price'] - $product['price']) / $product['original_price']) * 100); ?>%
                    </div>
                </div>
                <div class="product-info">
                    <div class="product-price">
                        <span
                            class="product-original-price"><?php echo number_format($product['original_price'], 0, ',', '.'); ?>đ</span>
                        <?php echo number_format($product['price'], 0, ',', '.'); ?>đ
                    </div>
                    <div class="product-name"><?php echo $product['name']; ?></div>
                    <div class="product-seller">Bán bởi: TikTok Shop</div>
                    <div class="product-stock">📦 Còn 15 sản phẩm</div>
                    <button class="add-to-cart-btn"
                        onclick="event.stopPropagation(); addToCart(<?php echo $product['id']; ?>)">
                        Thêm vào giỏ
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- All Products Section -->
    <section class="section">
        <div class="section-header">
            <h2 class="section-title">🛍️ Tất cả sản phẩm</h2>
            <div class="section-meta">
                <span>Trang <?php echo $page; ?> / <?php echo $total_pages; ?> (<?php echo $total_products; ?> sản
                    phẩm)</span>
            </div>
        </div>

        <?php if (!empty($all_products)): ?>
        <div class="products-grid">
            <?php foreach ($all_products as $product): ?>
            <?php 
                $product_image = getProductImage($product, $pdo); 
                $discounted_price = getDiscountedPrice($product);
                $discount_percentage = getDiscountPercentage($product);
            ?>
            <div class="product-card" onclick="navigateToProduct(<?php echo $product['id']; ?>)">
                <div class="product-image">
                    <?php if ($product_image): ?>
                    <img src="<?php echo htmlspecialchars($product_image); ?>"
                        alt="<?php echo htmlspecialchars($product['name']); ?>"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="product-placeholder" style="display: none;">📦</div>
                    <?php else: ?>
                    <div class="product-placeholder">📦</div>
                    <?php endif; ?>

                    <?php if ($product['featured']): ?>
                    <div class="product-badge">⭐ Nổi bật</div>
                    <?php endif; ?>

                    <?php if ($discount_percentage > 0): ?>
                    <div class="product-discount-badge">-<?php echo round($discount_percentage); ?>%</div>
                    <?php endif; ?>
                </div>
                <div class="product-info">
                    <div class="product-price">
                        <?php if ($product['discount'] > 0): ?>
                        <span
                            class="product-original-price"><?php echo number_format($product['unit_price'], 0, ',', '.'); ?>đ</span>
                        <?php endif; ?>
                        <?php echo number_format($discounted_price, 0, ',', '.'); ?>đ
                    </div>
                    <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                    <div class="product-seller">Bán bởi:
                        <?php echo htmlspecialchars($product['seller_name'] ?: 'TikTok Shop'); ?></div>

                    <?php if ($product['stock_quantity'] > 0): ?>
                    <div class="product-stock <?php echo $product['stock_quantity'] < 10 ? 'low-stock' : ''; ?>">
                        📦 Còn <?php echo $product['stock_quantity']; ?> sản phẩm
                    </div>
                    <?php elseif ($product['stock_quantity'] == 0): ?>
                    <div class="product-stock out-of-stock">❌ Hết hàng</div>
                    <?php endif; ?>

                    <button class="add-to-cart-btn"
                        onclick="event.stopPropagation(); addToCart(<?php echo $product['id']; ?>)"
                        <?php echo $product['stock_quantity'] == 0 ? 'disabled' : ''; ?>>
                        <?php echo $product['stock_quantity'] == 0 ? 'Hết hàng' : 'Thêm vào giỏ'; ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>" class="pagination-btn">‹ Trước</a>
            <?php endif; ?>

            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
            <a href="?page=<?php echo $i; ?>"
                class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page + 1; ?>" class="pagination-btn">Tiếp ›</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="no-products">
            <div style="text-align: center; padding: 60px 20px; color: #666;">
                <div style="font-size: 64px; margin-bottom: 20px;">📦</div>
                <h3>Chưa có sản phẩm nào</h3>
                <p>Hệ thống đang được cập nhật, vui lòng quay lại sau!</p>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <button class="quick-action-btn" onclick="scrollToTop()" title="Lên đầu trang">↑</button>
        <button class="quick-action-btn" onclick="window.location.href='cart.php'" title="Giỏ hàng">🛒</button>
        <button class="quick-action-btn" onclick="window.location.href='profile.php'" title="Tài khoản">👤</button>
    </div>

    <?php include 'footer.php'; ?>

    <script>
    // Navigation functions
    function navigateToProduct(productId) {
        window.location.href = `product-detail.php?id=${productId}`;
    }

    function navigateToCategory(categoryId) {
        window.location.href = `category.php?id=${categoryId}`;
    }

    function scrollToSection(sectionId) {
        const section = document.getElementById(sectionId);
        if (section) {
            section.scrollIntoView({
                behavior: 'smooth'
            });
        }
    }

    function scrollToTop() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }

    // Categories slider functionality
    let currentSlide = 0;
    const itemsPerView = {
        mobile: 2,
        tablet: 4,
        desktop: 6
    };

    function getItemsPerView() {
        if (window.innerWidth <= 480) return itemsPerView.mobile;
        if (window.innerWidth <= 768) return itemsPerView.tablet;
        return itemsPerView.desktop;
    }

    function slideCategories(direction) {
        const slider = document.getElementById('categoriesSlider');
        if (!slider) return;

        const items = slider.children;
        const totalItems = items.length;
        const itemsPerScreen = getItemsPerView();
        const maxSlide = Math.max(0, totalItems - itemsPerScreen);

        if (direction === 'next') {
            currentSlide = Math.min(currentSlide + 1, maxSlide);
        } else {
            currentSlide = Math.max(currentSlide - 1, 0);
        }

        const translateX = -(currentSlide * (100 / itemsPerScreen));
        slider.style.transform = `translateX(${translateX}%)`;

        updateSliderButtons(maxSlide);
    }

    function updateSliderButtons(maxSlide) {
        const prevBtn = document.querySelector('.prev-btn');
        const nextBtn = document.querySelector('.next-btn');

        if (prevBtn && nextBtn) {
            prevBtn.disabled = currentSlide === 0;
            nextBtn.disabled = currentSlide >= maxSlide;

            prevBtn.style.opacity = prevBtn.disabled ? '0.5' : '1';
            nextBtn.style.opacity = nextBtn.disabled ? '0.5' : '1';
        }
    }

    // Auto-slide functionality
    let autoSlideInterval;

    function startAutoSlide() {
        const slider = document.getElementById('categoriesSlider');
        if (!slider) return;

        autoSlideInterval = setInterval(() => {
            const totalItems = slider.children.length;
            const itemsPerScreen = getItemsPerView();
            const maxSlide = Math.max(0, totalItems - itemsPerScreen);

            if (currentSlide >= maxSlide) {
                currentSlide = 0;
            } else {
                currentSlide++;
            }

            const translateX = -(currentSlide * (100 / itemsPerScreen));
            slider.style.transform = `translateX(${translateX}%)`;
            updateSliderButtons(maxSlide);
        }, 4000);
    }

    function stopAutoSlide() {
        if (autoSlideInterval) {
            clearInterval(autoSlideInterval);
        }
    }

    // Notification system
    function showNotification(message, type = 'success') {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.notification');
        existingNotifications.forEach(notification => notification.remove());

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
                <div class="notification-content">
                    <span class="notification-icon">${type === 'success' ? '✅' : '❌'}</span>
                    <span class="notification-message">${message}</span>
                    <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
                </div>
            `;

        // Add styles if not exists
        if (!document.querySelector('#notification-styles')) {
            const styles = document.createElement('style');
            styles.id = 'notification-styles';
            styles.textContent = `
                    .notification {
                        position: fixed;
                        top: 20px;
                        right: 20px;
                        background: white;
                        border-radius: 12px;
                        padding: 15px 20px;
                        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
                        z-index: 10000;
                        transform: translateX(100%);
                        transition: all 0.3s ease;
                        max-width: 350px;
                        border-left: 4px solid #28a745;
                    }
                    .notification-error {
                        border-left-color: #dc3545;
                    }
                    .notification-content {
                        display: flex;
                        align-items: center;
                        gap: 10px;
                    }
                    .notification-icon {
                        font-size: 16px;
                        flex-shrink: 0;
                    }
                    .notification-message {
                        color: #333;
                        font-size: 14px;
                        font-weight: 500;
                        flex: 1;
                    }
                    .notification-close {
                        background: none;
                        border: none;
                        font-size: 18px;
                        color: #999;
                        cursor: pointer;
                        padding: 0;
                        width: 20px;
                        height: 20px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    .notification-close:hover {
                        color: #333;
                    }
                    .notification.show {
                        transform: translateX(0);
                    }
                `;
            document.head.appendChild(styles);
        }

        // Add to page
        document.body.appendChild(notification);

        // Show notification
        setTimeout(() => {
            notification.classList.add('show');
        }, 100);

        // Auto hide after 5 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.remove();
                    }
                }, 300);
            }
        }, 5000);
    }

    // Cart count update function
    function updateCartCount() {
        fetch('get-cart-count.php')
            .then(response => response.json())
            .then(data => {
                const cartCountElements = document.querySelectorAll('.cart-count');
                if (cartCountElements.length > 0 && data.success) {
                    cartCountElements.forEach(element => {
                        element.textContent = data.count;
                        element.style.display = data.count > 0 ? 'inline-block' : 'none';
                    });
                }
            })
            .catch(error => {
                console.error('Error updating cart count:', error);
            });
    }

    // Add to cart function
    function addToCart(productId) {
        const button = event.target;
        const originalText = button.textContent;

        // Show loading state
        button.disabled = true;
        button.textContent = 'Đang thêm...';
        button.classList.add('loading');

        // Get CSRF token from meta tag
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        // AJAX call to add product to cart
        fetch('add-to-cart.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    product_id: productId,
                    quantity: 1,
                    csrf_token: csrfToken
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update cart count in header
                    updateCartCount();

                    // Show success message
                    showNotification(data.message || 'Đã thêm sản phẩm vào giỏ hàng!', 'success');

                    // Reset button
                    button.disabled = false;
                    button.textContent = '✓ Đã thêm';
                    button.classList.remove('loading');

                    // Reset button text after 2 seconds
                    setTimeout(() => {
                        button.textContent = originalText;
                    }, 2000);
                } else {
                    showNotification(data.message || 'Có lỗi xảy ra, vui lòng thử lại!', 'error');
                    button.disabled = false;
                    button.textContent = originalText;
                    button.classList.remove('loading');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Có lỗi xảy ra, vui lòng thử lại!', 'error');
                button.disabled = false;
                button.textContent = originalText;
                button.classList.remove('loading');
            });
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize slider
        const slider = document.getElementById('categoriesSlider');
        if (slider) {
            const totalItems = slider.children.length;
            const itemsPerScreen = getItemsPerView();
            const maxSlide = Math.max(0, totalItems - itemsPerScreen);
            updateSliderButtons(maxSlide);

            // Start auto slide
            startAutoSlide();

            // Pause auto slide on hover
            const sliderContainer = document.querySelector('.categories-slider-container');
            if (sliderContainer) {
                sliderContainer.addEventListener('mouseenter', stopAutoSlide);
                sliderContainer.addEventListener('mouseleave', startAutoSlide);

                // Touch/swipe support for mobile
                let startX = 0;
                let currentX = 0;
                let isSwipping = false;

                sliderContainer.addEventListener('touchstart', (e) => {
                    startX = e.touches[0].clientX;
                    isSwipping = true;
                    stopAutoSlide();
                });

                sliderContainer.addEventListener('touchmove', (e) => {
                    if (!isSwipping) return;
                    currentX = e.touches[0].clientX;
                });

                sliderContainer.addEventListener('touchend', () => {
                    if (!isSwipping) return;
                    isSwipping = false;

                    const diffX = startX - currentX;
                    const threshold = 50;

                    if (Math.abs(diffX) > threshold) {
                        if (diffX > 0) {
                            slideCategories('next');
                        } else {
                            slideCategories('prev');
                        }
                    }

                    startAutoSlide();
                });
            }

            // Handle window resize
            window.addEventListener('resize', () => {
                const newItemsPerScreen = getItemsPerView();
                const newMaxSlide = Math.max(0, totalItems - newItemsPerScreen);

                // Reset slide if current position is invalid
                if (currentSlide > newMaxSlide) {
                    currentSlide = newMaxSlide;
                    const translateX = -(currentSlide * (100 / newItemsPerScreen));
                    slider.style.transform = `translateX(${translateX}%)`;
                }

                updateSliderButtons(newMaxSlide);
            });
        }

        // Update cart count on page load
        updateCartCount();

        // Show/hide quick actions based on scroll
        let lastScrollTop = 0;
        const quickActions = document.querySelector('.quick-actions');

        window.addEventListener('scroll', () => {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;

            if (scrollTop > lastScrollTop && scrollTop > 200) {
                // Scrolling down
                if (quickActions) quickActions.style.transform = 'translateX(100px)';
            } else {
                // Scrolling up
                if (quickActions) quickActions.style.transform = 'translateX(0)';
            }

            lastScrollTop = scrollTop;
        });
    });

    // Lazy loading for images
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.classList.remove('lazy');
                    imageObserver.unobserve(img);
                }
            });
        });

        // Apply to images that have data-src attribute
        document.querySelectorAll('img[data-src]').forEach(img => {
            imageObserver.observe(img);
        });
    }
    </script>
</body>

</html>