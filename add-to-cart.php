<?php
session_start();
require_once 'config.php';
require_once 'csrf.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Vui lòng đăng nhập để thêm sản phẩm vào giỏ hàng!'
    ]);
    exit;
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Phương thức không được hỗ trợ!'
    ]);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate CSRF token
$csrf_token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrf_token)) {
    echo json_encode([
        'success' => false,
        'message' => 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang!'
    ]);
    exit;
}

// Validate input
if (!isset($input['product_id']) || !isset($input['quantity'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Thiếu thông tin sản phẩm!'
    ]);
    exit;
}

$user_id = $_SESSION['user_id'];
$product_id = (int) $input['product_id'];
$quantity = max(1, (int) $input['quantity']);
$variation = isset($input['variation']) ? json_encode($input['variation']) : null;

try {
    // Check if product exists and is available
    $stmt = $pdo->prepare("SELECT p.*, u.id as seller_id, u.name as seller_name 
                          FROM products p 
                          LEFT JOIN users u ON p.user_id = u.id 
                          WHERE p.id = ? AND p.published = 1 AND p.approved = 1");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        echo json_encode([
            'success' => false,
            'message' => 'Sản phẩm không tồn tại hoặc không khả dụng!'
        ]);
        exit;
    }

    // Check stock availability
    if ($product['current_stock'] > 0 && $quantity > $product['current_stock']) {
        echo json_encode([
            'success' => false,
            'message' => 'Số lượng yêu cầu vượt quá tồn kho!'
        ]);
        exit;
    }

    // Calculate price (considering discounts)
    $price = $product['unit_price'];
    if ($product['discount'] > 0) {
        if ($product['discount_type'] === 'percent') {
            $price = $price - (($price * $product['discount']) / 100);
        } else {
            $price = $price - $product['discount'];
        }
    }

    // Calculate tax
    $tax = 0;
    if ($product['tax'] > 0) {
        if ($product['tax_type'] === 'percent') {
            $tax = ($price * $product['tax']) / 100;
        } else {
            $tax = $product['tax'];
        }
    }

    // Calculate shipping cost
    $shipping_cost = $product['shipping_cost'];
    if ($product['is_quantity_multiplied']) {
        $shipping_cost = $shipping_cost * $quantity;
    }

    // Check if item already exists in cart
    $stmt = $pdo->prepare("SELECT * FROM carts WHERE user_id = ? AND product_id = ? AND variation = ?");
    $stmt->execute([$user_id, $product_id, $variation]);
    $existing_cart_item = $stmt->fetch();

    if ($existing_cart_item) {
        // Update existing cart item
        $new_quantity = $existing_cart_item['quantity'] + $quantity;

        // Check stock again for new total quantity
        if ($product['current_stock'] > 0 && $new_quantity > $product['current_stock']) {
            echo json_encode([
                'success' => false,
                'message' => 'Không thể thêm. Tổng số lượng sẽ vượt quá tồn kho!'
            ]);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE carts SET 
                              quantity = ?, 
                              price = ?, 
                              tax = ?, 
                              shipping_cost = ?,
                              updated_at = NOW()
                              WHERE id = ?");
        $stmt->execute([
            $new_quantity,
            $price,
            $tax,
            $shipping_cost,
            $existing_cart_item['id']
        ]);

        $message = 'Đã cập nhật số lượng sản phẩm trong giỏ hàng!';
    } else {
        // Add new cart item
        $stmt = $pdo->prepare("INSERT INTO carts 
                              (user_id, owner_id, product_id, variation, quantity, price, tax, shipping_cost, status, created_at, updated_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
        $stmt->execute([
            $user_id,
            $product['seller_id'], // owner_id is the seller
            $product_id,
            $variation,
            $quantity,
            $price,
            $tax,
            $shipping_cost
        ]);

        $message = 'Đã thêm sản phẩm vào giỏ hàng!';
    }

    // Get updated cart count
    $count_stmt = $pdo->prepare("SELECT SUM(quantity) as total_items FROM carts WHERE user_id = ? AND status = 1");
    $count_stmt->execute([$user_id]);
    $cart_count = $count_stmt->fetchColumn() ?: 0;

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => $message,
        'cart_count' => $cart_count,
        'product_name' => $product['name']
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Có lỗi xảy ra khi thêm sản phẩm vào giỏ hàng!'
    ]);

    // Log error for debugging (optional)
    error_log('Add to cart error: ' . $e->getMessage());
}
?>