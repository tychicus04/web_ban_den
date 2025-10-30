<?php
session_start();
require_once 'config.php';
require_once 'csrf.php';

// Set page-specific variables
$current_page = 'cart';
$require_login = true;

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get user info
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_type = $_SESSION['user_type'];

// Handle cart actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed');
    }
    try {
        switch ($_POST['action']) {
            case 'update_quantity':
                $cart_id = $_POST['cart_id'];
                $quantity = max(1, (int) $_POST['quantity']);

                $stmt = $pdo->prepare("UPDATE carts SET quantity = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$quantity, $cart_id, $user_id]);

                $message = 'Cập nhật số lượng thành công!';
                $message_type = 'success';
                break;

            case 'remove_item':
                $cart_id = $_POST['cart_id'];

                $stmt = $pdo->prepare("DELETE FROM carts WHERE id = ? AND user_id = ?");
                $stmt->execute([$cart_id, $user_id]);

                $message = 'Đã xóa sản phẩm khỏi giỏ hàng!';
                $message_type = 'success';
                break;

            case 'clear_cart':
                $stmt = $pdo->prepare("DELETE FROM carts WHERE user_id = ?");
                $stmt->execute([$user_id]);

                $message = 'Đã xóa tất cả sản phẩm khỏi giỏ hàng!';
                $message_type = 'success';
                break;

            case 'apply_coupon':
                $coupon_code = trim($_POST['coupon_code']);

                // Check if coupon exists and is valid
                $stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ? AND status = 1 AND 
                                     (start_date IS NULL OR start_date <= ?) AND 
                                     (end_date IS NULL OR end_date >= ?)");
                $current_timestamp = time();
                $stmt->execute([$coupon_code, $current_timestamp, $current_timestamp]);
                $coupon = $stmt->fetch();

                if ($coupon) {
                    // Update all cart items with coupon
                    $stmt = $pdo->prepare("UPDATE carts SET coupon_code = ?, coupon_applied = 1 WHERE user_id = ?");
                    $stmt->execute([$coupon_code, $user_id]);

                    $message = 'Áp dụng mã giảm giá thành công!';
                    $message_type = 'success';
                } else {
                    $message = 'Mã giảm giá không hợp lệ hoặc đã hết hạn!';
                    $message_type = 'error';
                }
                break;

            case 'remove_coupon':
                $stmt = $pdo->prepare("UPDATE carts SET coupon_code = NULL, coupon_applied = 0 WHERE user_id = ?");
                $stmt->execute([$user_id]);

                $message = 'Đã bỏ mã giảm giá!';
                $message_type = 'success';
                break;
        }
    } catch (PDOException $e) {
        $message = 'Có lỗi xảy ra: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Get cart items with product information
try {
    $stmt = $pdo->prepare("SELECT c.*, p.name as product_name, p.unit_price, p.discount, p.discount_type,
                          p.tax, p.tax_type, p.shipping_cost as product_shipping_cost, p.weight,
                          p.thumbnail_img, p.stock_visibility_state, p.current_stock, p.slug,
                          u.name as seller_name, thumb.file_name as thumbnail_file
                          FROM carts c
                          LEFT JOIN products p ON c.product_id = p.id
                          LEFT JOIN users u ON p.user_id = u.id
                          LEFT JOIN uploads thumb ON p.thumbnail_img = thumb.id AND thumb.deleted_at IS NULL
                          WHERE c.user_id = ? AND c.status = 1
                          ORDER BY c.created_at DESC");
    $stmt->execute([$user_id]);
    $cart_items = $stmt->fetchAll();
} catch (PDOException $e) {
    $cart_items = [];
}

// Calculate cart totals
$subtotal = 0;
$total_tax = 0;
$total_shipping = 0;
$total_discount = 0;
$total_weight = 0;
$applied_coupon = null;

foreach ($cart_items as $item) {
    $item_price = $item['price'] ?: $item['unit_price'];
    $item_subtotal = $item_price * $item['quantity'];

    $subtotal += $item_subtotal;
    $total_tax += $item['tax'] * $item['quantity'];
    $total_shipping += $item['shipping_cost'] * $item['quantity'];
    $total_weight += $item['weight'] * $item['quantity'];

    // Calculate product discount
    if ($item['discount'] > 0) {
        if ($item['discount_type'] === 'percent') {
            $item_discount = ($item_subtotal * $item['discount']) / 100;
        } else {
            $item_discount = $item['discount'] * $item['quantity'];
        }
        $total_discount += $item_discount;
    }

    // Get applied coupon info
    if ($item['coupon_applied'] && $item['coupon_code'] && !$applied_coupon) {
        try {
            $coupon_stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ?");
            $coupon_stmt->execute([$item['coupon_code']]);
            $applied_coupon = $coupon_stmt->fetch();
        } catch (PDOException $e) {
            // Handle error silently
        }
    }
}

// Apply coupon discount
$coupon_discount = 0;
if ($applied_coupon && $subtotal > 0) {
    if ($applied_coupon['discount_type'] === 'percent') {
        $coupon_discount = ($subtotal * $applied_coupon['discount']) / 100;
    } else {
        $coupon_discount = $applied_coupon['discount'];
    }
    $coupon_discount = min($coupon_discount, $subtotal); // Don't exceed subtotal
}

$grand_total = $subtotal + $total_tax + $total_shipping - $total_discount - $coupon_discount;
$grand_total = max(0, $grand_total); // Ensure non-negative

// Function to get product image
function getProductImage($item)
{
    if (!empty($item['thumbnail_file'])) {
        return $item['thumbnail_file'];
    }
    return '';
}

// Function to format variation display
function formatVariation($variation_json)
{
    if (empty($variation_json))
        return '';

    $variations = json_decode($variation_json, true);
    if (!is_array($variations))
        return '';

    $formatted = [];
    foreach ($variations as $key => $value) {
        $formatted[] = ucfirst($key) . ': ' . $value;
    }

    return implode(', ', $formatted);
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giỏ hàng - TikTok Shop</title>
    <meta name="description" content="Xem và quản lý sản phẩm trong giỏ hàng của bạn">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="asset/css/global.css">
    <link rel="stylesheet" href="asset/css/components.css">
    <link rel="stylesheet" href="asset/css/base.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="asset/css/pages/cart.css">
</head>

<body>
    <?php include 'header.php'; ?>

    <div class="cart-container">
        <!-- Page Header -->
        <header class="page-header">
            <h1 class="page-title">Giỏ hàng của bạn</h1>
            <div class="cart-stats">
                <span>📦 <?php echo count($cart_items); ?> sản phẩm</span>
                <span>⚖️ <?php echo number_format($total_weight, 2); ?>kg</span>
                <span>💰 <?php echo number_format($grand_total, 0, ',', '.'); ?>đ</span>
            </div>
        </header>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <span><?php echo $message_type === 'success' ? '✅' : '❌'; ?></span>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($cart_items)): ?>
        <div class="cart-content">
            <!-- Cart Items -->
            <section class="cart-items">
                <div class="section-title">
                    Sản phẩm trong giỏ hàng (<?php echo count($cart_items); ?>)
                </div>

                <div class="cart-actions">
                    <button class="btn btn-outline" onclick="selectAllItems()">
                        🗹 Chọn tất cả
                    </button>
                    <button class="btn btn-secondary" onclick="clearCart()">
                        🗑️ Xóa giỏ hàng
                    </button>
                </div>

                <?php foreach ($cart_items as $item): ?>
                <div class="cart-item" data-cart-id="<?php echo $item['id']; ?>">
                    <div class="item-image">
                        <?php $product_image = getProductImage($item); ?>
                        <?php if ($product_image): ?>
                        <img src="<?php echo htmlspecialchars($product_image); ?>"
                            alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="item-placeholder" style="display: none;">📦</div>
                        <?php else: ?>
                        <div class="item-placeholder">📦</div>
                        <?php endif; ?>
                    </div>

                    <div class="item-info">
                        <div class="item-name">
                            <?php echo htmlspecialchars($item['product_name']); ?>
                        </div>
                        <?php if ($item['variation']): ?>
                        <div class="item-variation">
                            <?php echo htmlspecialchars(formatVariation($item['variation'])); ?>
                        </div>
                        <?php endif; ?>
                        <div class="item-seller">
                            Bán bởi: <?php echo htmlspecialchars($item['seller_name'] ?: 'TikTok Shop'); ?>
                        </div>
                    </div>

                    <div class="item-price">
                        <?php if ($item['discount'] > 0): ?>
                        <div class="item-original-price">
                            <?php echo number_format($item['unit_price'], 0, ',', '.'); ?>đ
                        </div>
                        <?php endif; ?>
                        <div>
                            <?php
                                    $display_price = $item['price'] ?: $item['unit_price'];
                                    echo number_format($display_price, 0, ',', '.');
                                    ?>đ
                        </div>
                    </div>

                    <div class="quantity-control">
                        <button class="quantity-btn"
                            onclick="updateQuantity(<?php echo $item['id']; ?>, <?php echo $item['quantity'] - 1; ?>)"
                            <?php echo $item['quantity'] <= 1 ? 'disabled' : ''; ?>>
                            -
                        </button>
                        <input type="number" class="quantity-input" value="<?php echo $item['quantity']; ?>" min="1"
                            max="99" onchange="updateQuantity(<?php echo $item['id']; ?>, this.value)">
                        <button class="quantity-btn"
                            onclick="updateQuantity(<?php echo $item['id']; ?>, <?php echo $item['quantity'] + 1; ?>)">
                            +
                        </button>
                    </div>

                    <button class="remove-btn" onclick="removeItem(<?php echo $item['id']; ?>)" title="Xóa sản phẩm">
                        🗑️
                    </button>
                </div>
                <?php endforeach; ?>
            </section>

            <!-- Cart Summary -->
            <aside class="cart-summary">
                <h3 class="section-title">Tóm tắt đơn hàng</h3>

                <!-- Coupon Section -->
                <div class="coupon-section">
                    <?php if ($applied_coupon): ?>
                    <div class="applied-coupon">
                        <div class="coupon-info">
                            <strong>🎟️ <?php echo htmlspecialchars($applied_coupon['code']); ?></strong><br>
                            Giảm
                            <?php echo $applied_coupon['discount_type'] === 'percent' ? $applied_coupon['discount'] . '%' : number_format($applied_coupon['discount'], 0, ',', '.') . 'đ'; ?>
                        </div>
                        <button class="btn btn-secondary btn-remove-coupon" onclick="removeCoupon()">
                            Bỏ
                        </button>
                    </div>
                    <?php else: ?>
                    <form class="coupon-form" onsubmit="applyCoupon(event)">
                        <input type="text" class="coupon-input" name="coupon_code" placeholder="Nhập mã giảm giá"
                            id="coupon-input">
                        <button type="submit" class="btn">Áp dụng</button>
                    </form>
                    <small class="coupon-hint">💡 Nhập mã giảm giá để được ưu đãi thêm</small>
                    <?php endif; ?>
                </div>

                <!-- Order Summary -->
                <div class="order-summary">
                    <div class="summary-row">
                        <span class="summary-label">Tạm tính
                            (<?php echo array_sum(array_column($cart_items, 'quantity')); ?> sản phẩm)</span>
                        <span class="summary-value"><?php echo number_format($subtotal, 0, ',', '.'); ?>đ</span>
                    </div>

                    <?php if ($total_discount > 0): ?>
                    <div class="summary-row">
                        <span class="summary-label">Giảm giá sản phẩm</span>
                        <span
                            class="summary-value summary-discount">-<?php echo number_format($total_discount, 0, ',', '.'); ?>đ</span>
                    </div>
                    <?php endif; ?>

                    <?php if ($coupon_discount > 0): ?>
                    <div class="summary-row">
                        <span class="summary-label">Giảm giá coupon</span>
                        <span
                            class="summary-value summary-discount">-<?php echo number_format($coupon_discount, 0, ',', '.'); ?>đ</span>
                    </div>
                    <?php endif; ?>

                    <?php if ($total_shipping > 0): ?>
                    <div class="summary-row">
                        <span class="summary-label">Phí vận chuyển</span>
                        <span class="summary-value"><?php echo number_format($total_shipping, 0, ',', '.'); ?>đ</span>
                    </div>
                    <?php else: ?>
                    <div class="summary-row">
                        <span class="summary-label">Phí vận chuyển</span>
                        <span class="summary-value summary-discount">Miễn phí 🚚</span>
                    </div>
                    <?php endif; ?>

                    <?php if ($total_tax > 0): ?>
                    <div class="summary-row">
                        <span class="summary-label">Thuế</span>
                        <span class="summary-value"><?php echo number_format($total_tax, 0, ',', '.'); ?>đ</span>
                    </div>
                    <?php endif; ?>

                    <div class="summary-row">
                        <span class="summary-label">Tổng cộng</span>
                        <span class="summary-value"><?php echo number_format($grand_total, 0, ',', '.'); ?>đ</span>
                    </div>
                </div>

                <!-- Checkout Actions -->
                <div class="checkout-actions">
                    <a href="checkout.php" class="btn btn-full">
                        🛒 Tiến hành thanh toán
                    </a>
                    <a href="index.php" class="btn btn-outline btn-full">
                        ← Tiếp tục mua sắm
                    </a>
                </div>

                <div class="security-info">
                    <span>🔒</span>
                    <span>Thông tin thanh toán được bảo mật tuyệt đối</span>
                </div>
            </aside>
        </div>

        <?php else: ?>
        <!-- Empty Cart -->
        <div class="empty-cart">
            <div class="empty-cart-icon">🛒</div>
            <h3>Giỏ hàng của bạn đang trống</h3>
            <p>Hãy thêm sản phẩm vào giỏ hàng để bắt đầu mua sắm!</p>
            <a href="index.php" class="btn" style="margin-top: 20px;">
                🛍️ Khám phá sản phẩm
            </a>

            <!-- Suggested Products -->
            <div class="suggested-products">
                <h3>Sản phẩm bạn có thể thích</h3>
                <div class="products-grid">
                    <!-- Sample suggested products -->
                    <?php
                        try {
                            $suggested_stmt = $pdo->prepare("SELECT p.*, thumb.file_name as thumbnail_file 
                                                           FROM products p 
                                                           LEFT JOIN uploads thumb ON p.thumbnail_img = thumb.id AND thumb.deleted_at IS NULL
                                                           WHERE p.published = 1 AND p.approved = 1 AND p.featured = 1
                                                           ORDER BY RAND() LIMIT 4");
                            $suggested_stmt->execute();
                            $suggested_products = $suggested_stmt->fetchAll();

                            foreach ($suggested_products as $product):
                                ?>
                    <div class="product-card"
                        onclick="window.location.href='product-detail.php?id=<?php echo $product['id']; ?>'">
                        <div class="item-image" style="width: 100%; height: 150px; margin-bottom: 10px;">
                            <?php if ($product['thumbnail_file']): ?>
                            <img src="<?php echo htmlspecialchars($product['thumbnail_file']); ?>"
                                alt="<?php echo htmlspecialchars($product['name']); ?>">
                            <?php else: ?>
                            <div class="item-placeholder">📦</div>
                            <?php endif; ?>
                        </div>
                        <h4 style="margin-bottom: 10px; font-size: 14px;">
                            <?php echo htmlspecialchars($product['name']); ?>
                        </h4>
                        <div style="color: #fe2c55; font-weight: bold;">
                            <?php echo number_format($product['unit_price'], 0, ',', '.'); ?>đ
                        </div>
                    </div>
                    <?php
                            endforeach;
                        } catch (PDOException $e) {
                            // Handle error silently
                        }
                        ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php include 'footer.php'; ?>

    <script>
    function updateQuantity(cartId, newQuantity) {
        if (newQuantity < 1) return;

        const cartItem = document.querySelector(`[data-cart-id="${cartId}"]`);
        cartItem.classList.add('loading');

        const formData = new FormData();
        formData.append('action', 'update_quantity');
        formData.append('cart_id', cartId);
        formData.append('quantity', newQuantity);

        fetch('cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                cartItem.classList.remove('loading');
                showToast('Có lỗi xảy ra khi cập nhật số lượng!', 'error');
            });
    }

    function removeItem(cartId) {
        if (!confirm('Bạn có chắc muốn xóa sản phẩm này khỏi giỏ hàng?')) {
            return;
        }

        const cartItem = document.querySelector(`[data-cart-id="${cartId}"]`);
        cartItem.classList.add('loading');

        const formData = new FormData();
        formData.append('action', 'remove_item');
        formData.append('cart_id', cartId);

        fetch('cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                cartItem.classList.remove('loading');
                showToast('Có lỗi xảy ra khi xóa sản phẩm!', 'error');
            });
    }

    function clearCart() {
        if (!confirm('Bạn có chắc muốn xóa tất cả sản phẩm trong giỏ hàng?')) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'clear_cart');

        fetch('cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Có lỗi xảy ra khi xóa giỏ hàng!', 'error');
            });
    }

    function applyCoupon(event) {
        event.preventDefault();

        const couponInput = document.getElementById('coupon-input');
        const couponCode = couponInput.value.trim();

        if (!couponCode) {
            showToast('Vui lòng nhập mã giảm giá!', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'apply_coupon');
        formData.append('coupon_code', couponCode);

        fetch('cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Có lỗi xảy ra khi áp dụng mã giảm giá!', 'error');
            });
    }

    function removeCoupon() {
        const formData = new FormData();
        formData.append('action', 'remove_coupon');

        fetch('cart.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Có lỗi xảy ra khi bỏ mã giảm giá!', 'error');
            });
    }

    function selectAllItems() {
        // This would be used for multi-select functionality if implemented
        showToast('Tính năng chọn nhiều sản phẩm đang được phát triển!', 'info');
    }

    function showToast(message, type = 'success') {
        // Remove existing toast
        const existingToast = document.querySelector('.toast');
        if (existingToast) {
            existingToast.remove();
        }

        // Create new toast
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;

        document.body.appendChild(toast);

        // Show toast
        setTimeout(() => {
            toast.classList.add('show');
        }, 100);

        // Hide toast after 3 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 3000);
    }

    // Auto-save quantity changes with debounce
    let quantityTimeouts = {};

    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('quantity-input')) {
            const cartId = e.target.closest('.cart-item').dataset.cartId;
            const newQuantity = parseInt(e.target.value);

            // Clear existing timeout
            if (quantityTimeouts[cartId]) {
                clearTimeout(quantityTimeouts[cartId]);
            }

            // Set new timeout
            quantityTimeouts[cartId] = setTimeout(() => {
                updateQuantity(cartId, newQuantity);
            }, 1000);
        }
    });

    // Update cart count in header if function exists
    if (typeof updateCartCount === 'function') {
        updateCartCount();
    }
    </script>

    <!-- JavaScript Files -->
    <script src="asset/js/global.js"></script>
    <script src="asset/js/components.js"></script>
</body>

</html>