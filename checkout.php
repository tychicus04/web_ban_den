<?php
session_start();
require_once 'config.php';

// Set page-specific variables
$current_page = 'checkout';
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

// Check if cart is empty in database
try {
    $cart_check_stmt = $pdo->prepare("SELECT COUNT(*) FROM carts WHERE user_id = ? AND status = 1");
    $cart_check_stmt->execute([$user_id]);
    $cart_count = $cart_check_stmt->fetchColumn();
    
    if ($cart_count == 0) {
        header('Location: cart.php?error=empty_cart');
        exit;
    }
} catch (PDOException $e) {
    header('Location: cart.php?error=database_error');
    exit;
}

// Get user's saved addresses
try {
    $stmt = $pdo->prepare("SELECT * FROM addresses WHERE user_id = ? ORDER BY set_default DESC, created_at DESC");
    $stmt->execute([$user_id]);
    $saved_addresses = $stmt->fetchAll();
} catch (PDOException $e) {
    $saved_addresses = [];
}

// Get user's default address
$default_address = null;
foreach ($saved_addresses as $address) {
    if ($address['set_default'] == 1) {
        $default_address = $address;
        break;
    }
}

// Get cart items with product details from database
$cart_items = [];
$cart_total = 0;
$shipping_cost = 0;
$tax_amount = 0;
$total_discount = 0;
$coupon_discount = 0;
$applied_coupon = null;

try {
    $stmt = $pdo->prepare("SELECT c.*, p.name as product_name, p.unit_price, p.discount, p.discount_type,
                          p.tax, p.tax_type, p.shipping_cost as product_shipping_cost, p.weight,
                          p.thumbnail_img, p.stock_visibility_state, p.current_stock, p.slug,
                          u.name as seller_name, thumb.file_name as thumbnail_file
                          FROM carts c
                          LEFT JOIN products p ON c.product_id = p.id
                          LEFT JOIN users u ON p.user_id = u.id
                          LEFT JOIN uploads thumb ON p.thumbnail_img = thumb.id AND thumb.deleted_at IS NULL
                          WHERE c.user_id = ? AND c.status = 1 AND p.published = 1 AND p.approved = 1
                          ORDER BY c.created_at DESC");
    $stmt->execute([$user_id]);
    $cart_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($cart_rows as $row) {
        // Use cart price if set, otherwise use product unit_price
        $price = $row['price'] ?: $row['unit_price'];
        
        // Apply discount if exists
        if ($row['discount'] > 0) {
            if ($row['discount_type'] === 'percent') {
                $discounted_price = $price - (($price * $row['discount']) / 100);
            } else {
                $discounted_price = $price - $row['discount'];
            }
            $price = max(0, $discounted_price);
            
            // Calculate discount amount
            $item_discount = ($row['price'] ?: $row['unit_price']) - $price;
            $total_discount += $item_discount * $row['quantity'];
        }
        
        $item_total = $price * $row['quantity'];
        $cart_total += $item_total;
        
        // Add shipping cost
        $shipping_cost += $row['shipping_cost'] * $row['quantity'];
        
        // Add tax
        $tax_amount += $row['tax'] * $row['quantity'];
        
        $cart_items[] = [
            'cart_id' => $row['id'],
            'product' => [
                'id' => $row['product_id'],
                'name' => $row['product_name'],
                'unit_price' => $row['unit_price'],
                'discount' => $row['discount'],
                'discount_type' => $row['discount_type'],
                'tax' => $row['tax'],
                'tax_type' => $row['tax_type'],
                'shipping_cost' => $row['product_shipping_cost'],
                'current_stock' => $row['current_stock'],
                'seller_name' => $row['seller_name'],
                'thumbnail_file' => $row['thumbnail_file'],
                'user_id' => $row['owner_id'] ?: 1 // seller_id
            ],
            'quantity' => $row['quantity'],
            'price' => $price,
            'total' => $item_total,
            'variation' => $row['variation']
        ];
        
        // Get applied coupon info (first one found)
        if ($row['coupon_applied'] && $row['coupon_code'] && !$applied_coupon) {
            try {
                $coupon_stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ? AND status = 1");
                $coupon_stmt->execute([$row['coupon_code']]);
                $applied_coupon = $coupon_stmt->fetch();
            } catch (PDOException $e) {
                // Handle error silently
            }
        }
    }
    
    // Apply coupon discount if exists
    if ($applied_coupon && $cart_total > 0) {
        if ($applied_coupon['discount_type'] === 'percent') {
            $coupon_discount = ($cart_total * $applied_coupon['discount']) / 100;
        } else {
            $coupon_discount = $applied_coupon['discount'];
        }
        $coupon_discount = min($coupon_discount, $cart_total); // Don't exceed cart total
    }
    
} catch (PDOException $e) {
    $cart_items = [];
    error_log("Checkout cart error: " . $e->getMessage());
}

// Calculate grand total
$grand_total = $cart_total + $shipping_cost + $tax_amount - $total_discount - $coupon_discount;
$grand_total = max(0, $grand_total);

// Handle form submission
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['place_order']) || isset($_POST['force_submit']))) {
    error_log("Checkout form submitted");
    error_log("POST data: " . print_r($_POST, true));
    
    try {
        // Validate form data
        $shipping_name = trim($_POST['shipping_name'] ?? '');
        $shipping_phone = trim($_POST['shipping_phone'] ?? '');
        $shipping_address = trim($_POST['shipping_address'] ?? '');
        $shipping_city = trim($_POST['shipping_city'] ?? '');
        $shipping_state = trim($_POST['shipping_state'] ?? '');
        $shipping_postal_code = trim($_POST['shipping_postal_code'] ?? '');
        $payment_method = $_POST['payment_method'] ?? '';
        $order_notes = trim($_POST['order_notes'] ?? '');
        
        error_log("Validation data: name=$shipping_name, phone=$shipping_phone, payment=$payment_method");
        
        // Basic validation
        if (empty($shipping_name) || empty($shipping_phone) || empty($shipping_address) || 
            empty($shipping_city) || empty($payment_method)) {
            throw new Exception('Vui lòng điền đầy đủ thông tin bắt buộc.');
        }
        
        if (empty($cart_items)) {
            throw new Exception('Giỏ hàng trống.');
        }
        
        error_log("Validation passed, cart items: " . count($cart_items));
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Create shipping address string
        $full_shipping_address = json_encode([
            'name' => $shipping_name,
            'phone' => $shipping_phone,
            'address' => $shipping_address,
            'city' => $shipping_city,
            'state' => $shipping_state,
            'postal_code' => $shipping_postal_code
        ]);
        
        // Create combined order
        $stmt = $pdo->prepare("INSERT INTO combined_orders (user_id, shipping_address, grand_total, created_at, updated_at) 
                              VALUES (?, ?, ?, NOW(), NOW())");
        $stmt->execute([$user_id, $full_shipping_address, $grand_total]);
        $combined_order_id = $pdo->lastInsertId();
        
        // Group items by seller
        $sellers_orders = [];
        foreach ($cart_items as $item) {
            $seller_id = $item['product']['user_id'];
            if (!isset($sellers_orders[$seller_id])) {
                $sellers_orders[$seller_id] = [];
            }
            $sellers_orders[$seller_id][] = $item;
        }
        
        // Create orders for each seller
        foreach ($sellers_orders as $seller_id => $seller_items) {
            $seller_total = array_sum(array_column($seller_items, 'total'));
            
            // Create order
            $stmt = $pdo->prepare("INSERT INTO orders (combined_order_id, user_id, seller_id, shipping_address, 
                                  additional_info, shipping_type, payment_type, payment_status, grand_total, 
                                  coupon_discount, date, created_at, updated_at) 
                                  VALUES (?, ?, ?, ?, ?, 'flat_rate', ?, 'unpaid', ?, ?, ?, NOW(), NOW())");
            $stmt->execute([$combined_order_id, $user_id, $seller_id, $full_shipping_address, 
                           $order_notes, $payment_method, $seller_total, $coupon_discount, time()]);
            $order_id = $pdo->lastInsertId();
            
            // Create order details
            foreach ($seller_items as $item) {
                $product = $item['product'];
                $stmt = $pdo->prepare("INSERT INTO order_details (order_id, seller_id, product_id, 
                                      variation, price, tax, shipping_cost, quantity, created_at, updated_at) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([
                    $order_id, 
                    $seller_id, 
                    $product['id'], 
                    $item['variation'] ?: '[]',
                    $item['price'], 
                    $product['tax'] ?: 0, 
                    $product['shipping_cost'] ?: 0, 
                    $item['quantity']
                ]);
                
                // Update product stock
                $stmt = $pdo->prepare("UPDATE products SET current_stock = current_stock - ?, 
                                      num_of_sale = num_of_sale + ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['quantity'], $product['id']]);
            }
        }
        
        // Clear cart from database
        $stmt = $pdo->prepare("DELETE FROM carts WHERE user_id = ? AND status = 1");
        $stmt->execute([$user_id]);
        
        // Commit transaction
        $pdo->commit();
        
        // Redirect to success page
        if (file_exists('order-success.php')) {
            header("Location: order-success.php?order_id=" . $combined_order_id);
        } else {
            // Fallback if order-success.php doesn't exist
            $success_message = "Đặt hàng thành công! Mã đơn hàng: #" . $combined_order_id;
            error_log("Order created successfully: " . $combined_order_id);
        }
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = $e->getMessage();
        error_log("Checkout error: " . $e->getMessage());
    }
}

// Function to get product image
function getProductImage($product, $pdo = null) {
    if (!empty($product['thumbnail_file'])) {
        return $product['thumbnail_file'];
    }
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
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thanh toán - TikTok Shop</title>
    <meta name="description"
        content="Hoàn tất đơn hàng của bạn tại TikTok Shop với thanh toán an toàn và giao hàng nhanh chóng.">
    <!-- CSS Files -->
    <link rel="stylesheet" href="asset/css/global.css">
    <link rel="stylesheet" href="asset/css/components.css">
    <link rel="stylesheet" href="asset/css/base.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">

    <style>
    body {
        background: #f8f9fa;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    .checkout-container {
        max-width: 1200px;
        margin: 20px auto;
        padding: 0 20px;
        display: grid;
        grid-template-columns: 1fr 400px;
        gap: 30px;
    }

    .checkout-form {
        background: white;
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        height: fit-content;
    }

    .order-summary {
        background: white;
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        height: fit-content;
        position: sticky;
        top: 20px;
    }

    .section-title {
        font-size: 24px;
        font-weight: 700;
        color: #333;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 3px solid #ff0050;
        position: relative;
    }

    .section-title::before {
        content: '';
        position: absolute;
        bottom: -3px;
        left: 0;
        width: 50px;
        height: 3px;
        background: linear-gradient(45deg, #ff0050, #ff4081);
        border-radius: 2px;
    }

    .form-group {
        margin-bottom: 25px;
    }

    .form-label {
        display: block;
        font-size: 14px;
        font-weight: 600;
        color: #333;
        margin-bottom: 8px;
    }

    .form-label.required::after {
        content: ' *';
        color: #ff0050;
        font-weight: bold;
    }

    .form-input,
    .form-select,
    .form-textarea {
        width: 100%;
        padding: 15px 20px;
        border: 1px solid #e1e5e9;
        border-radius: 12px;
        font-size: 16px;
        transition: all 0.3s ease;
        box-sizing: border-box;
        background: #f8f9fa;
    }

    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
        outline: none;
        border-color: #ff0050;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(255, 0, 80, 0.1);
    }

    .form-textarea {
        resize: vertical;
        min-height: 100px;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    .saved-addresses {
        margin-bottom: 25px;
    }

    .address-option {
        background: #f8f9fa;
        border: 2px solid #e1e5e9;
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 15px;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
    }

    .address-option:hover {
        border-color: #ff0050;
        background: #fff5f8;
        transform: translateY(-2px);
    }

    .address-option.selected {
        border-color: #ff0050;
        background: #fff5f8;
        box-shadow: 0 5px 15px rgba(255, 0, 80, 0.2);
    }

    .address-option input[type="radio"] {
        margin-right: 15px;
        transform: scale(1.2);
    }

    .payment-methods {
        display: grid;
        gap: 15px;
    }

    .payment-option {
        background: #f8f9fa;
        border: 2px solid #e1e5e9;
        border-radius: 15px;
        padding: 20px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .payment-option:hover {
        border-color: #ff0050;
        background: #fff5f8;
        transform: translateY(-2px);
    }

    .payment-option.selected {
        border-color: #ff0050;
        background: #fff5f8;
        box-shadow: 0 5px 15px rgba(255, 0, 80, 0.2);
    }

    .payment-option input[type="radio"] {
        margin: 0;
        transform: scale(1.2);
    }

    .payment-icon {
        font-size: 32px;
        width: 50px;
        text-align: center;
    }

    .payment-info {
        flex: 1;
    }

    .payment-name {
        font-weight: 600;
        color: #333;
        margin-bottom: 5px;
        font-size: 16px;
    }

    .payment-desc {
        font-size: 13px;
        color: #666;
    }

    .cart-item {
        display: flex;
        gap: 20px;
        padding: 20px 0;
        border-bottom: 1px solid #f0f0f0;
    }

    .cart-item:last-child {
        border-bottom: none;
    }

    .item-image {
        width: 70px;
        height: 70px;
        border-radius: 12px;
        overflow: hidden;
        background: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .item-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .item-placeholder {
        font-size: 24px;
        color: #ccc;
    }

    .item-details {
        flex: 1;
    }

    .item-name {
        font-size: 15px;
        font-weight: 600;
        color: #333;
        margin-bottom: 5px;
        line-height: 1.4;
    }

    .item-seller {
        font-size: 13px;
        color: #666;
        margin-bottom: 10px;
    }

    .item-price-qty {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .item-price {
        font-size: 16px;
        font-weight: 700;
        color: #ff0050;
    }

    .item-qty {
        font-size: 13px;
        color: #666;
        background: #f8f9fa;
        padding: 4px 8px;
        border-radius: 6px;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        font-size: 15px;
    }

    .summary-row.total {
        border-top: 2px solid #f0f0f0;
        margin-top: 15px;
        padding-top: 20px;
        font-size: 20px;
        font-weight: 700;
        color: #333;
    }

    .summary-label {
        color: #666;
    }

    .summary-value {
        font-weight: 600;
        color: #333;
    }

    .summary-value.total-price {
        color: #ff0050;
        font-size: 24px;
        font-weight: 700;
    }

    .summary-discount {
        color: #28a745;
    }

    .place-order-btn {
        width: 100%;
        background: linear-gradient(45deg, #ff0050, #ff4081);
        color: white;
        border: none;
        padding: 18px;
        border-radius: 15px;
        font-size: 18px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-top: 25px;
        position: relative;
        overflow: hidden;
    }

    .place-order-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 35px rgba(255, 0, 80, 0.4);
    }

    .place-order-btn:active {
        transform: translateY(-1px);
    }

    .place-order-btn:disabled {
        background: #ccc;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    .place-order-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s;
    }

    .place-order-btn:hover::before {
        left: 100%;
    }

    .error-message {
        background: rgba(255, 59, 48, 0.1);
        border: 1px solid rgba(255, 59, 48, 0.3);
        border-radius: 12px;
        padding: 15px 20px;
        color: #ff3b30;
        margin-bottom: 25px;
        border-left: 4px solid #ff3b30;
    }

    .success-message {
        background: rgba(52, 199, 89, 0.1);
        border: 1px solid rgba(52, 199, 89, 0.3);
        border-radius: 12px;
        padding: 15px 20px;
        color: #34c759;
        margin-bottom: 25px;
        border-left: 4px solid #34c759;
    }

    .breadcrumb {
        max-width: 1200px;
        margin: 20px auto 0;
        padding: 0 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 14px;
        color: #666;
    }

    .breadcrumb a {
        color: #ff0050;
        text-decoration: none;
        font-weight: 500;
    }

    .breadcrumb a:hover {
        text-decoration: underline;
    }

    .security-badge {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #e8f5e8;
        padding: 15px;
        border-radius: 10px;
        margin-top: 20px;
        font-size: 13px;
        color: #2d7d32;
    }

    .security-icon {
        font-size: 18px;
    }

    .coupon-info {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
        color: #856404;
    }

    .coupon-code {
        font-weight: 700;
        color: #ff0050;
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
        .checkout-container {
            grid-template-columns: 1fr;
            gap: 20px;
            margin: 10px auto;
            padding: 0 15px;
        }

        .checkout-form,
        .order-summary {
            padding: 20px;
            border-radius: 15px;
        }

        .form-row {
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .order-summary {
            order: -1;
            position: static;
        }

        .breadcrumb {
            padding: 0 15px;
        }

        .section-title {
            font-size: 20px;
        }
    }

    @media (max-width: 480px) {

        .checkout-form,
        .order-summary {
            padding: 15px;
            border-radius: 12px;
        }

        .cart-item {
            padding: 15px 0;
        }

        .item-image {
            width: 60px;
            height: 60px;
        }

        .item-name {
            font-size: 14px;
        }

        .place-order-btn {
            padding: 15px;
            font-size: 16px;
        }
    }

    /* Loading animation */
    .loading {
        opacity: 0.7;
        pointer-events: none;
    }

    .loading::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 20px;
        height: 20px;
        margin: -10px 0 0 -10px;
        border: 2px solid #f3f3f3;
        border-top: 2px solid #ff0050;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }
    </style>
</head>

<body>
    <?php if (file_exists('header.php')) include 'header.php'; ?>

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="index.php">🏠 Trang chủ</a>
        <span>›</span>
        <a href="cart.php">🛒 Giỏ hàng</a>
        <span>›</span>
        <span>💳 Thanh toán</span>
    </div>

    <?php if (isset($_GET['debug'])): ?>
    <div style="background: #f0f0f0; padding: 20px; margin: 20px; border-radius: 8px;">
        <h3>Debug Information</h3>
        <p><strong>Session User ID:</strong> <?php echo $_SESSION['user_id'] ?? 'Not set'; ?></p>
        <p><strong>Cart Items Count:</strong> <?php echo count($cart_items); ?></p>
        <p><strong>Cart Total:</strong> <?php echo number_format($grand_total, 0, ',', '.'); ?>đ</p>
        <p><strong>POST Method:</strong> <?php echo $_SERVER['REQUEST_METHOD']; ?></p>
        <p><strong>Form Submitted:</strong> <?php echo isset($_POST['place_order']) ? 'Yes' : 'No'; ?></p>
        <?php if ($error_message): ?>
        <p><strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?></p>
        <?php endif; ?>
        <?php if ($success_message): ?>
        <p><strong>Success:</strong> <?php echo htmlspecialchars($success_message); ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($cart_items)): ?>
    <div style="text-align: center; padding: 60px 20px;">
        <div style="font-size: 64px; margin-bottom: 20px;">🛒</div>
        <h3>Giỏ hàng trống</h3>
        <p>Vui lòng thêm sản phẩm vào giỏ hàng trước khi thanh toán.</p>
        <a href="index.php"
            style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: #ff0050; color: white; text-decoration: none; border-radius: 8px;">Tiếp
            tục mua sắm</a>
    </div>
    <?php else: ?>

    <div class="checkout-container">
        <!-- Checkout Form -->
        <div class="checkout-form">
            <?php if ($error_message): ?>
            <div class="error-message">
                ❌ <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
            <div class="success-message">
                ✅ <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="checkoutForm">
                <!-- Shipping Information -->
                <div class="form-section">
                    <h2 class="section-title">📍 Thông tin giao hàng</h2>

                    <?php if (!empty($saved_addresses)): ?>
                    <div class="saved-addresses">
                        <label class="form-label">Chọn địa chỉ đã lưu:</label>
                        <?php foreach ($saved_addresses as $address): ?>
                        <div class="address-option" onclick="selectAddress(this)">
                            <input type="radio" name="saved_address" value="<?php echo $address['id']; ?>"
                                <?php echo $address['set_default'] ? 'checked' : ''; ?>
                                onchange="fillAddressForm(<?php echo htmlspecialchars(json_encode($address)); ?>)">
                            <div>
                                <strong><?php echo htmlspecialchars($address['address']); ?></strong><br>
                                <small>📞 <?php echo htmlspecialchars($address['phone']); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <div class="address-option" onclick="selectAddress(this)">
                            <input type="radio" name="saved_address" value="new" onchange="clearAddressForm()">
                            <div><strong>➕ Sử dụng địa chỉ mới</strong></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Họ và tên</label>
                            <input type="text" name="shipping_name" class="form-input"
                                value="<?php echo htmlspecialchars($user_name); ?>" required
                                placeholder="Nhập họ và tên">
                        </div>
                        <div class="form-group">
                            <label class="form-label required">Số điện thoại</label>
                            <input type="tel" name="shipping_phone" class="form-input"
                                value="<?php echo htmlspecialchars($default_address['phone'] ?? ''); ?>" required
                                placeholder="Nhập số điện thoại">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Địa chỉ</label>
                        <textarea name="shipping_address" class="form-textarea" required
                            placeholder="Nhập địa chỉ chi tiết"><?php echo htmlspecialchars($default_address['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Thành phố</label>
                            <input type="text" name="shipping_city" class="form-input"
                                value="<?php echo htmlspecialchars($default_address['city'] ?? ''); ?>" required
                                placeholder="Thành phố">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tỉnh/Thành phố</label>
                            <input type="text" name="shipping_state" class="form-input"
                                value="<?php echo htmlspecialchars($default_address['state'] ?? ''); ?>"
                                placeholder="Tỉnh/Thành phố">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Mã bưu điện</label>
                        <input type="text" name="shipping_postal_code" class="form-input"
                            value="<?php echo htmlspecialchars($default_address['postal_code'] ?? ''); ?>"
                            placeholder="Mã bưu điện">
                    </div>
                </div>

                <!-- Payment Method -->
                <div class="form-section">
                    <h2 class="section-title">💳 Phương thức thanh toán</h2>

                    <div class="payment-methods">
                        <div class="payment-option" onclick="selectPayment(this)">
                            <input type="radio" name="payment_method" value="cod" checked>
                            <span class="payment-icon">💵</span>
                            <div class="payment-info">
                                <div class="payment-name">Thanh toán khi nhận hàng (COD)</div>
                                <div class="payment-desc">Thanh toán bằng tiền mặt khi nhận hàng</div>
                            </div>
                        </div>

                        <div class="payment-option" onclick="selectPayment(this)">
                            <input type="radio" name="payment_method" value="bank_transfer">
                            <span class="payment-icon">🏦</span>
                            <div class="payment-info">
                                <div class="payment-name">Chuyển khoản ngân hàng</div>
                                <div class="payment-desc">Chuyển khoản qua ngân hàng hoặc ví điện tử</div>
                            </div>
                        </div>

                        <div class="payment-option" onclick="selectPayment(this)">
                            <input type="radio" name="payment_method" value="momo">
                            <span class="payment-icon">📱</span>
                            <div class="payment-info">
                                <div class="payment-name">Ví MoMo</div>
                                <div class="payment-desc">Thanh toán qua ví điện tử MoMo</div>
                            </div>
                        </div>

                        <div class="payment-option" onclick="selectPayment(this)">
                            <input type="radio" name="payment_method" value="zalopay">
                            <span class="payment-icon">💳</span>
                            <div class="payment-info">
                                <div class="payment-name">ZaloPay</div>
                                <div class="payment-desc">Thanh toán qua ví điện tử ZaloPay</div>
                            </div>
                        </div>
                    </div>

                    <div class="security-badge">
                        <span class="security-icon">🔒</span>
                        Giao dịch được bảo mật với công nghệ mã hóa SSL 256-bit
                    </div>
                </div>

                <!-- Order Notes -->
                <div class="form-section">
                    <h2 class="section-title">📝 Ghi chú đơn hàng</h2>
                    <div class="form-group">
                        <label class="form-label">Ghi chú (tùy chọn)</label>
                        <textarea name="order_notes" class="form-textarea"
                            placeholder="Ghi chú về đơn hàng, ví dụ: thời gian giao hàng mong muốn, hướng dẫn giao hàng..."></textarea>
                    </div>
                </div>
            </form>
        </div>

        <!-- Order Summary -->
        <div class="order-summary">
            <h2 class="section-title">📄 Tóm tắt đơn hàng</h2>

            <?php if ($applied_coupon): ?>
            <div class="coupon-info">
                🎟️ Đang áp dụng mã: <span
                    class="coupon-code"><?php echo htmlspecialchars($applied_coupon['code']); ?></span><br>
                Giảm
                <?php echo $applied_coupon['discount_type'] === 'percent' ? $applied_coupon['discount'] . '%' : number_format($applied_coupon['discount'], 0, ',', '.') . 'đ'; ?>
            </div>
            <?php endif; ?>

            <div class="cart-items">
                <?php foreach ($cart_items as $item): ?>
                <?php $product_image = getProductImage($item['product'], $pdo); ?>
                <div class="cart-item">
                    <div class="item-image">
                        <?php if ($product_image): ?>
                        <img src="<?php echo htmlspecialchars($product_image); ?>"
                            alt="<?php echo htmlspecialchars($item['product']['name']); ?>"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="item-placeholder" style="display: none;">📦</div>
                        <?php else: ?>
                        <div class="item-placeholder">📦</div>
                        <?php endif; ?>
                    </div>
                    <div class="item-details">
                        <div class="item-name"><?php echo htmlspecialchars($item['product']['name']); ?></div>
                        <div class="item-seller">Bán bởi:
                            <?php echo htmlspecialchars($item['product']['seller_name'] ?: 'TikTok Shop'); ?></div>
                        <div class="item-price-qty">
                            <span class="item-price"><?php echo number_format($item['total'], 0, ',', '.'); ?>đ</span>
                            <span class="item-qty">x<?php echo $item['quantity']; ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="order-total">
                <div class="summary-row">
                    <span class="summary-label">Tạm tính:</span>
                    <span class="summary-value"><?php echo number_format($cart_total, 0, ',', '.'); ?>đ</span>
                </div>

                <?php if ($total_discount > 0): ?>
                <div class="summary-row">
                    <span class="summary-label">Giảm giá sản phẩm:</span>
                    <span
                        class="summary-value summary-discount">-<?php echo number_format($total_discount, 0, ',', '.'); ?>đ</span>
                </div>
                <?php endif; ?>

                <?php if ($coupon_discount > 0): ?>
                <div class="summary-row">
                    <span class="summary-label">Giảm giá coupon:</span>
                    <span
                        class="summary-value summary-discount">-<?php echo number_format($coupon_discount, 0, ',', '.'); ?>đ</span>
                </div>
                <?php endif; ?>

                <div class="summary-row">
                    <span class="summary-label">Phí vận chuyển:</span>
                    <span
                        class="summary-value"><?php echo $shipping_cost > 0 ? number_format($shipping_cost, 0, ',', '.') . 'đ' : 'Miễn phí 🚚'; ?></span>
                </div>

                <?php if ($tax_amount > 0): ?>
                <div class="summary-row">
                    <span class="summary-label">Thuế:</span>
                    <span class="summary-value"><?php echo number_format($tax_amount, 0, ',', '.'); ?>đ</span>
                </div>
                <?php endif; ?>

                <div class="summary-row total">
                    <span class="summary-label">Tổng cộng:</span>
                    <span
                        class="summary-value total-price"><?php echo number_format($grand_total, 0, ',', '.'); ?>đ</span>
                </div>
            </div>

            <button type="submit" name="place_order" form="checkoutForm" class="place-order-btn">
                <span id="orderBtnText">🛒 Đặt hàng (<?php echo number_format($grand_total, 0, ',', '.'); ?>đ)</span>
                <span id="orderBtnLoading" style="display: none;">⏳ Đang xử lý...</span>
            </button>

            <div style="text-align: center; margin-top: 20px; font-size: 12px; color: #666; line-height: 1.5;">
                Bằng cách đặt hàng, bạn đồng ý với <br>
                <a href="#" style="color: #ff0050;">Điều khoản sử dụng</a> và
                <a href="#" style="color: #ff0050;">Chính sách bảo mật</a> của chúng tôi
            </div>
        </div>
    </div>

    <?php endif; ?>

    <?php if (file_exists('footer.php')) include 'footer.php'; ?>

    <script>
    function selectAddress(element) {
        document.querySelectorAll('.address-option').forEach(option => {
            option.classList.remove('selected');
        });
        element.classList.add('selected');
    }

    function selectPayment(element) {
        document.querySelectorAll('.payment-option').forEach(option => {
            option.classList.remove('selected');
        });
        element.classList.add('selected');

        const radio = element.querySelector('input[type="radio"]');
        radio.checked = true;
    }

    function fillAddressForm(address) {
        if (address) {
            document.querySelector('input[name="shipping_phone"]').value = address.phone || '';
            document.querySelector('textarea[name="shipping_address"]').value = address.address || '';
            document.querySelector('input[name="shipping_postal_code"]').value = address.postal_code || '';
        }
    }

    function clearAddressForm() {
        document.querySelector('input[name="shipping_phone"]').value = '';
        document.querySelector('textarea[name="shipping_address"]').value = '';
        document.querySelector('input[name="shipping_postal_code"]').value = '';
    }

    document.getElementById('checkoutForm').addEventListener('submit', function(e) {
        const submitBtn = document.querySelector('.place-order-btn');
        const btnText = document.getElementById('orderBtnText');
        const btnLoading = document.getElementById('orderBtnLoading');

        // Show loading state
        submitBtn.disabled = true;
        btnText.style.display = 'none';
        btnLoading.style.display = 'inline';

        const requiredFields = this.querySelectorAll('input[required], textarea[required]');
        let isValid = true;
        let errorMessage = '';

        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                field.style.borderColor = '#ff3b30';
                isValid = false;
                if (!errorMessage) {
                    errorMessage = 'Vui lòng điền đầy đủ thông tin bắt buộc';
                }
            } else {
                field.style.borderColor = '#e1e5e9';
            }
        });

        const paymentMethod = this.querySelector('input[name="payment_method"]:checked');
        if (!paymentMethod) {
            isValid = false;
            errorMessage = 'Vui lòng chọn phương thức thanh toán';
        }

        if (!isValid) {
            e.preventDefault();
            alert(errorMessage);
            // Reset button state
            submitBtn.disabled = false;
            btnText.style.display = 'inline';
            btnLoading.style.display = 'none';
            return false;
        }

        // If validation passes, allow form to submit
        console.log('Form validation passed, submitting...');
    });

    document.addEventListener('DOMContentLoaded', function() {
        const defaultPayment = document.querySelector('input[name="payment_method"]:checked');
        if (defaultPayment) {
            defaultPayment.closest('.payment-option').classList.add('selected');
        }

        const defaultAddress = document.querySelector('input[name="saved_address"]:checked');
        if (defaultAddress) {
            defaultAddress.closest('.address-option').classList.add('selected');
        }

        const phoneInput = document.querySelector('input[name="shipping_phone"]');
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length > 0) {
                    if (value.startsWith('84')) {
                        value = '+' + value;
                    } else if (!value.startsWith('0')) {
                        value = '0' + value;
                    }
                }
                e.target.value = value;
            });
        }

        const inputs = document.querySelectorAll('.form-input, .form-textarea');
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                if (this.hasAttribute('required') && !this.value.trim()) {
                    this.style.borderColor = '#ff3b30';
                } else {
                    this.style.borderColor = '#e1e5e9';
                }
            });

            input.addEventListener('input', function() {
                if (this.style.borderColor === 'rgb(255, 59, 48)' && this.value.trim()) {
                    this.style.borderColor = '#e1e5e9';
                }
            });
        });
    });
    </script>
    <!-- JavaScript Files -->
    <script src="asset/js/global.js"></script>
    <script src="asset/js/components.js"></script>
</body>

</html>