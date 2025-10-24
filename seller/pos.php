<?php
require_once '../config.php';
session_start();

// Check if seller is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'seller') {
    header('Location: login.php');
    exit;
}

$seller_id = $_SESSION['user_id'];
$seller_name = $_SESSION['user_name'];

// Handle POS transaction
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'process_sale') {
    $cart_items = json_decode($_POST['cart_items'] ?? '[]', true);
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $amount_paid = (float) ($_POST['amount_paid'] ?? 0);
    $discount_amount = (float) ($_POST['discount_amount'] ?? 0);
    $coupon_code = trim($_POST['coupon_code'] ?? '');

    try {
        $pdo->beginTransaction();

        if (empty($cart_items)) {
            throw new Exception("Giỏ hàng trống!");
        }

        $total_amount = 0;
        $order_details = [];

        // Validate and calculate total
        foreach ($cart_items as $item) {
            $product_id = (int) $item['id'];
            $quantity = (int) $item['quantity'];
            $price = (float) $item['price'];

            if ($quantity <= 0)
                continue;

            // Check product exists and belongs to seller
            $product_stmt = $pdo->prepare("
                SELECT id, name, unit_price, current_stock 
                FROM products 
                WHERE id = ? AND user_id = ? AND published = 1
            ");
            $product_stmt->execute([$product_id, $seller_id]);
            $product = $product_stmt->fetch();

            if (!$product) {
                throw new Exception("Sản phẩm không tồn tại: " . $item['name']);
            }

            if ($product['current_stock'] < $quantity) {
                throw new Exception("Không đủ hàng: " . $product['name'] . " (Còn: " . $product['current_stock'] . ")");
            }

            $item_total = $price * $quantity;
            $total_amount += $item_total;

            $order_details[] = [
                'product_id' => $product_id,
                'quantity' => $quantity,
                'price' => $price,
                'total' => $item_total
            ];
        }

        // Apply discount
        $final_amount = $total_amount - $discount_amount;

        if ($payment_method === 'cash' && $amount_paid < $final_amount) {
            throw new Exception("Số tiền thanh toán không đủ!");
        }

        // Create customer if provided
        $customer_id = null;
        if (!empty($customer_phone)) {
            $customer_stmt = $pdo->prepare("
                SELECT id FROM users 
                WHERE phone = ? AND user_type = 'customer'
            ");
            $customer_stmt->execute([$customer_phone]);
            $existing_customer = $customer_stmt->fetch();

            if ($existing_customer) {
                $customer_id = $existing_customer['id'];
            } else if (!empty($customer_name)) {
                // Create new customer
                $create_customer = $pdo->prepare("
                    INSERT INTO users (name, phone, user_type, created_at) 
                    VALUES (?, ?, 'customer', NOW())
                ");
                $create_customer->execute([$customer_name, $customer_phone]);
                $customer_id = $pdo->lastInsertId();
            }
        }

        // Create order
        $order_code = 'POS' . date('YmdHis') . rand(100, 999);
        $order_stmt = $pdo->prepare("
            INSERT INTO orders (
                user_id, seller_id, order_from, delivery_status, payment_type, 
                payment_status, grand_total, code, date, created_at, updated_at
            ) VALUES (?, ?, 'pos', 'delivered', ?, 'paid', ?, ?, ?, NOW(), NOW())
        ");

        $order_stmt->execute([
            $customer_id,
            $seller_id,
            $payment_method,
            $final_amount,
            $order_code,
            time()
        ]);
        $order_id = $pdo->lastInsertId();

        // Create order details and update stock
        foreach ($order_details as $detail) {
            // Insert order detail
            $detail_stmt = $pdo->prepare("
                INSERT INTO order_details (
                    order_id, seller_id, product_id, price, quantity, 
                    payment_status, delivery_status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, 'paid', 'delivered', NOW(), NOW())
            ");
            $detail_stmt->execute([
                $order_id,
                $seller_id,
                $detail['product_id'],
                $detail['price'],
                $detail['quantity']
            ]);

            // Update product stock
            $stock_stmt = $pdo->prepare("
                UPDATE products 
                SET current_stock = current_stock - ?, num_of_sale = num_of_sale + ?
                WHERE id = ?
            ");
            $stock_stmt->execute([
                $detail['quantity'],
                $detail['quantity'],
                $detail['product_id']
            ]);
        }

        // Record coupon usage if applicable
        if (!empty($coupon_code) && $discount_amount > 0) {
            $coupon_stmt = $pdo->prepare("
                SELECT id FROM coupons 
                WHERE code = ? AND user_id = ? AND status = 1
            ");
            $coupon_stmt->execute([$coupon_code, $seller_id]);
            $coupon = $coupon_stmt->fetch();

            if ($coupon && $customer_id) {
                $usage_stmt = $pdo->prepare("
                    INSERT INTO coupon_usages (user_id, coupon_id, created_at) 
                    VALUES (?, ?, NOW())
                ");
                $usage_stmt->execute([$customer_id, $coupon['id']]);
            }
        }

        $pdo->commit();

        $change = ($payment_method === 'cash') ? $amount_paid - $final_amount : 0;

        $success = json_encode([
            'order_id' => $order_id,
            'order_code' => $order_code,
            'total' => $total_amount,
            'discount' => $discount_amount,
            'final_amount' => $final_amount,
            'amount_paid' => $amount_paid,
            'change' => $change,
            'payment_method' => $payment_method,
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'items' => $cart_items
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Có lỗi xảy ra khi xử lý giao dịch: " . $e->getMessage();
    }
}

try {
    // Get seller's products for POS
    $products_stmt = $pdo->prepare("
        SELECT 
            p.id, p.name, p.unit_price, p.current_stock, p.barcode,
            p.thumbnail_img, thumb.file_name as thumbnail_file,
            c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN uploads thumb ON p.thumbnail_img = thumb.id AND thumb.deleted_at IS NULL
        WHERE p.user_id = ? AND p.published = 1 AND p.current_stock > 0
        ORDER BY p.name ASC
    ");
    $products_stmt->execute([$seller_id]);
    $products = $products_stmt->fetchAll();

    // Get categories for filtering
    $categories_stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.name
        FROM categories c
        INNER JOIN products p ON c.id = p.category_id
        WHERE p.user_id = ? AND p.published = 1 AND p.current_stock > 0
        ORDER BY c.name ASC
    ");
    $categories_stmt->execute([$seller_id]);
    $categories = $categories_stmt->fetchAll();

    // Get seller's active coupons
    $coupons_stmt = $pdo->prepare("
        SELECT code, discount, discount_type, 
               JSON_EXTRACT(details, '$.min_buy') as min_buy
        FROM coupons 
        WHERE user_id = ? AND status = 1 
        AND (start_date IS NULL OR start_date <= UNIX_TIMESTAMP())
        AND (end_date IS NULL OR end_date >= UNIX_TIMESTAMP())
        ORDER BY discount DESC
        LIMIT 10
    ");
    $coupons_stmt->execute([$seller_id]);
    $coupons = $coupons_stmt->fetchAll();

    // Get today's sales summary
    $today_sales_stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as total_orders,
            COALESCE(SUM(o.grand_total), 0) as total_sales,
            COALESCE(SUM(od.quantity), 0) as total_items
        FROM orders o
        LEFT JOIN order_details od ON o.id = od.order_id
        WHERE o.seller_id = ? AND DATE(o.created_at) = CURDATE()
        AND o.payment_status = 'paid'
    ");
    $today_sales_stmt->execute([$seller_id]);
    $today_sales = $today_sales_stmt->fetch();

} catch (PDOException $e) {
    $products = [];
    $categories = [];
    $coupons = [];
    $today_sales = ['total_orders' => 0, 'total_sales' => 0, 'total_items' => 0];
    $error = "Có lỗi xảy ra khi tải dữ liệu POS.";
}

function formatCurrency($amount)
{
    return number_format($amount, 0, ',', '.') . 'đ';
}

function getProductImage($product)
{
    if (!empty($product['thumbnail_file'])) {
        return '../' . $product['thumbnail_file'];
    }
    return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iODAiIGhlaWdodD0iODAiIHZpZXdCb3g9IjAgMCA4MCA4MCIgZmlsbD0ibm9uZSI+PHJlY3Qgd2lkdGg9IjgwIiBoZWlnaHQ9IjgwIiBmaWxsPSIjZjNmNGY2Ii8+PHBhdGggZD0iTTMyIDMyaDE2djE2SDMyek0yNCAyNGgyNHYyNEgyNHoiIGZpbGw9IiNkOWRjZTAiLz48L3N2Zz4=';
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Bán hàng - TikTok Shop Seller</title>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #f8fafc;
        color: #333;
        line-height: 1.6;
        overflow-x: hidden;
    }

    .layout {
        display: flex;
        min-height: 100vh;
    }

    .main-content {
        flex: 1;
        margin-left: 280px;
        min-height: 100vh;
    }

    .top-header {
        background: white;
        padding: 12px 24px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 50;
    }

    .header-left {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .header-left h1 {
        font-size: 24px;
        color: #1f2937;
        font-weight: 600;
    }

    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        color: #6b7280;
        margin-top: 4px;
    }

    .breadcrumb a {
        color: #ff0050;
        text-decoration: none;
    }

    .mobile-menu-btn {
        display: none;
        background: none;
        border: none;
        cursor: pointer;
        color: #4b5563;
        padding: 8px;
        border-radius: 6px;
        transition: all 0.3s ease;
    }

    .mobile-menu-btn:hover {
        background: #f3f4f6;
        color: #ff0050;
    }

    .pos-header-info {
        display: flex;
        align-items: center;
        gap: 20px;
        font-size: 14px;
    }

    .pos-info-item {
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    .pos-info-value {
        font-weight: 600;
        color: #1f2937;
    }

    .pos-info-label {
        color: #6b7280;
        font-size: 12px;
    }

    /* POS Layout */
    .pos-container {
        display: grid;
        grid-template-columns: 1fr 400px;
        height: calc(100vh - 73px);
    }

    /* Products Section */
    .products-section {
        background: white;
        display: flex;
        flex-direction: column;
        border-right: 1px solid #e5e7eb;
    }

    .products-header {
        padding: 16px 20px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        gap: 12px;
        align-items: center;
    }

    .search-box {
        flex: 1;
        position: relative;
    }

    .search-input {
        width: 100%;
        padding: 10px 40px 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        transition: border-color 0.3s ease;
    }

    .search-input:focus {
        outline: none;
        border-color: #ff0050;
    }

    .search-icon {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #6b7280;
    }

    .category-filter {
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        background: white;
        min-width: 150px;
    }

    .barcode-input {
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        min-width: 120px;
        background: #f8fafc;
    }

    .products-grid {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
        gap: 12px;
        align-content: start;
    }

    .product-card {
        background: white;
        border: 2px solid #e5e7eb;
        border-radius: 12px;
        padding: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .product-card:hover {
        border-color: #ff0050;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(255, 0, 80, 0.1);
    }

    .product-card.out-of-stock {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .product-card.out-of-stock::after {
        content: 'Hết hàng';
        position: absolute;
        top: 8px;
        right: 8px;
        background: #ef4444;
        color: white;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 4px;
        font-weight: 500;
    }

    .product-image {
        width: 60px;
        height: 60px;
        border-radius: 8px;
        object-fit: cover;
        margin: 0 auto 8px;
        display: block;
        border: 1px solid #e5e7eb;
    }

    .product-name {
        font-size: 13px;
        font-weight: 500;
        color: #1f2937;
        margin-bottom: 4px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        line-height: 1.3;
    }

    .product-price {
        font-size: 14px;
        font-weight: 600;
        color: #ff0050;
        margin-bottom: 4px;
    }

    .product-stock {
        font-size: 11px;
        color: #6b7280;
    }

    /* Cart Section */
    .cart-section {
        background: white;
        display: flex;
        flex-direction: column;
    }

    .cart-header {
        padding: 16px 20px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: between;
        align-items: center;
    }

    .cart-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
    }

    .clear-cart-btn {
        background: #ef4444;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .clear-cart-btn:hover {
        background: #dc2626;
    }

    .cart-items {
        flex: 1;
        overflow-y: auto;
        padding: 16px 20px;
        min-height: 200px;
    }

    .cart-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 0;
        border-bottom: 1px solid #f3f4f6;
    }

    .cart-item:last-child {
        border-bottom: none;
    }

    .cart-item-image {
        width: 40px;
        height: 40px;
        border-radius: 6px;
        object-fit: cover;
        border: 1px solid #e5e7eb;
    }

    .cart-item-info {
        flex: 1;
    }

    .cart-item-name {
        font-size: 14px;
        font-weight: 500;
        color: #1f2937;
        margin-bottom: 2px;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .cart-item-price {
        font-size: 13px;
        color: #6b7280;
    }

    .cart-item-controls {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .qty-btn {
        width: 28px;
        height: 28px;
        border: 1px solid #d1d5db;
        background: white;
        border-radius: 6px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .qty-btn:hover {
        background: #f3f4f6;
    }

    .qty-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .qty-input {
        width: 50px;
        text-align: center;
        padding: 4px;
        border: 1px solid #d1d5db;
        border-radius: 4px;
        font-size: 14px;
    }

    .remove-btn {
        background: #ef4444;
        color: white;
        border: none;
        width: 24px;
        height: 24px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .remove-btn:hover {
        background: #dc2626;
    }

    /* Cart Summary */
    .cart-summary {
        border-top: 1px solid #e5e7eb;
        padding: 16px 20px;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
        font-size: 14px;
    }

    .summary-row.total {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
        border-top: 1px solid #e5e7eb;
        padding-top: 8px;
        margin-top: 8px;
    }

    .discount-input {
        display: flex;
        gap: 8px;
        margin-bottom: 12px;
    }

    .discount-field {
        flex: 1;
        padding: 8px 10px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 13px;
    }

    .discount-btn {
        padding: 8px 12px;
        background: #10b981;
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
    }

    .discount-btn:hover {
        background: #059669;
    }

    /* Payment Section */
    .payment-section {
        border-top: 1px solid #e5e7eb;
        padding: 16px 20px;
    }

    .payment-title {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 12px;
    }

    .payment-methods {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
        margin-bottom: 12px;
    }

    .payment-method {
        padding: 10px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 13px;
        font-weight: 500;
    }

    .payment-method.active {
        border-color: #ff0050;
        background: #fef2f2;
        color: #ff0050;
    }

    .payment-method:hover {
        border-color: #ff0050;
    }

    .customer-info {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-bottom: 12px;
    }

    .customer-input {
        padding: 8px 10px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 13px;
    }

    .cash-input {
        width: 100%;
        padding: 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        text-align: center;
        margin-bottom: 8px;
    }

    .cash-input:focus {
        outline: none;
        border-color: #ff0050;
    }

    .quick-amounts {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 6px;
        margin-bottom: 12px;
    }

    .quick-amount {
        padding: 8px;
        border: 1px solid #d1d5db;
        background: white;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        text-align: center;
        transition: all 0.3s ease;
    }

    .quick-amount:hover {
        background: #f3f4f6;
    }

    .change-info {
        background: #f8fafc;
        padding: 12px;
        border-radius: 8px;
        margin-bottom: 12px;
        display: none;
    }

    .change-info.show {
        display: block;
    }

    .change-amount {
        font-size: 18px;
        font-weight: 700;
        color: #10b981;
        text-align: center;
    }

    .checkout-btn {
        width: 100%;
        padding: 14px;
        background: #ff0050;
        color: white;
        border: none;
        border-radius: 10px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-bottom: 8px;
    }

    .checkout-btn:hover:not(:disabled) {
        background: #cc0040;
        transform: translateY(-1px);
    }

    .checkout-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }

    .empty-cart {
        text-align: center;
        padding: 40px 20px;
        color: #6b7280;
    }

    .empty-cart-icon {
        font-size: 48px;
        margin-bottom: 12px;
        opacity: 0.5;
    }

    /* Receipt Modal */
    .modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .modal.show {
        opacity: 1;
        visibility: visible;
    }

    .modal-content {
        background: white;
        border-radius: 16px;
        padding: 24px;
        max-width: 400px;
        width: 90%;
        transform: scale(0.9);
        transition: transform 0.3s ease;
        max-height: 80vh;
        overflow-y: auto;
    }

    .modal.show .modal-content {
        transform: scale(1);
    }

    .receipt {
        font-family: 'Courier New', monospace;
        line-height: 1.4;
    }

    .receipt-header {
        text-align: center;
        border-bottom: 2px solid #333;
        padding-bottom: 12px;
        margin-bottom: 16px;
    }

    .receipt-title {
        font-size: 18px;
        font-weight: bold;
        margin-bottom: 4px;
    }

    .receipt-subtitle {
        font-size: 12px;
        color: #666;
    }

    .receipt-info {
        margin-bottom: 16px;
        font-size: 12px;
    }

    .receipt-items {
        margin-bottom: 16px;
    }

    .receipt-item {
        display: flex;
        justify-content: space-between;
        margin-bottom: 4px;
        font-size: 12px;
    }

    .receipt-total {
        border-top: 1px solid #333;
        padding-top: 8px;
        font-weight: bold;
    }

    .receipt-footer {
        text-align: center;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid #333;
        font-size: 11px;
        color: #666;
    }

    .modal-actions {
        display: flex;
        gap: 12px;
        margin-top: 20px;
    }

    .btn {
        padding: 10px 16px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.3s ease;
        flex: 1;
        text-align: center;
    }

    .btn-primary {
        background: #ff0050;
        color: white;
    }

    .btn-primary:hover {
        background: #cc0040;
    }

    .btn-secondary {
        background: #f3f4f6;
        color: #4b5563;
        border: 1px solid #d1d5db;
    }

    .btn-secondary:hover {
        background: #e5e7eb;
    }

    /* Alert */
    .alert {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 16px;
        border-radius: 8px;
        font-size: 14px;
        z-index: 1001;
        transform: translateX(100%);
        transition: transform 0.3s ease;
    }

    .alert.show {
        transform: translateX(0);
    }

    .alert-success {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
    }

    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .pos-container {
            grid-template-columns: 1fr 350px;
        }

        .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        }
    }

    @media (max-width: 768px) {
        .main-content {
            margin-left: 0;
        }

        .mobile-menu-btn {
            display: block;
        }

        .pos-container {
            grid-template-columns: 1fr;
            grid-template-rows: 1fr auto;
        }

        .cart-section {
            max-height: 50vh;
        }

        .products-header {
            flex-direction: column;
            gap: 8px;
        }

        .search-box {
            order: 1;
        }

        .category-filter,
        .barcode-input {
            min-width: auto;
        }

        .pos-header-info {
            display: none;
        }

        .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 8px;
            padding: 12px;
        }

        .product-card {
            padding: 8px;
        }

        .product-image {
            width: 50px;
            height: 50px;
        }

        .payment-methods {
            grid-template-columns: 1fr;
        }

        .customer-info {
            grid-template-columns: 1fr;
        }

        .quick-amounts {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 480px) {
        .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        }
    }

    /* Loading Animation */
    .loading {
        position: relative;
        opacity: 0.6;
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
        border: 2px solid #f3f4f6;
        border-top: 2px solid #ff0050;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* Hide scrollbar for webkit browsers */
    .products-grid::-webkit-scrollbar,
    .cart-items::-webkit-scrollbar {
        width: 6px;
    }

    .products-grid::-webkit-scrollbar-track,
    .cart-items::-webkit-scrollbar-track {
        background: #f1f1f1;
    }

    .products-grid::-webkit-scrollbar-thumb,
    .cart-items::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 3px;
    }

    .products-grid::-webkit-scrollbar-thumb:hover,
    .cart-items::-webkit-scrollbar-thumb:hover {
        background: #a1a1a1;
    }
    </style>
</head>

<body>
    <div class="layout">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="top-header">
                <div class="header-left">
                    <button class="mobile-menu-btn" onclick="toggleSidebar()">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" />
                        </svg>
                    </button>
                    <div>
                        <h1>POS Bán hàng</h1>
                        <div class="breadcrumb">
                            <a href="dashboard.php">Dashboard</a>
                            <span>›</span>
                            <span>POS</span>
                        </div>
                    </div>
                </div>
                <div class="pos-header-info">
                    <div class="pos-info-item">
                        <div class="pos-info-value"><?php echo $today_sales['total_orders']; ?></div>
                        <div class="pos-info-label">Đơn hôm nay</div>
                    </div>
                    <div class="pos-info-item">
                        <div class="pos-info-value"><?php echo formatCurrency($today_sales['total_sales']); ?></div>
                        <div class="pos-info-label">Doanh thu hôm nay</div>
                    </div>
                    <div class="pos-info-item">
                        <div class="pos-info-value"><?php echo date('H:i:s'); ?></div>
                        <div class="pos-info-label">Thời gian</div>
                    </div>
                </div>
            </div>

            <div class="pos-container">
                <!-- Products Section -->
                <div class="products-section">
                    <div class="products-header">
                        <div class="search-box">
                            <input type="text" class="search-input" id="productSearch"
                                placeholder="Tìm sản phẩm theo tên...">
                            <div class="search-icon">🔍</div>
                        </div>
                        <select class="category-filter" id="categoryFilter">
                            <option value="">Tất cả danh mục</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>">
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="barcode-input" id="barcodeInput" placeholder="Quét mã vạch...">
                    </div>

                    <div class="products-grid" id="productsGrid">
                        <?php foreach ($products as $product): ?>
                        <div class="product-card" data-id="<?php echo $product['id']; ?>"
                            data-name="<?php echo htmlspecialchars($product['name']); ?>"
                            data-price="<?php echo $product['unit_price']; ?>"
                            data-stock="<?php echo $product['current_stock']; ?>"
                            data-category="<?php echo $product['category_id'] ?? ''; ?>"
                            data-barcode="<?php echo $product['barcode'] ?? ''; ?>" onclick="addToCart(this)">
                            <img src="<?php echo getProductImage($product); ?>"
                                alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-image">
                            <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                            <div class="product-price"><?php echo formatCurrency($product['unit_price']); ?></div>
                            <div class="product-stock">Còn: <?php echo $product['current_stock']; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Cart Section -->
                <div class="cart-section">
                    <div class="cart-header">
                        <div class="cart-title">Giỏ hàng</div>
                        <button class="clear-cart-btn" onclick="clearCart()">Xóa tất cả</button>
                    </div>

                    <div class="cart-items" id="cartItems">
                        <div class="empty-cart">
                            <div class="empty-cart-icon">🛒</div>
                            <div>Giỏ hàng trống</div>
                            <div style="font-size: 12px; margin-top: 4px;">Chọn sản phẩm để bắt đầu</div>
                        </div>
                    </div>

                    <div class="cart-summary">
                        <div class="discount-input">
                            <input type="text" class="discount-field" id="couponCode" placeholder="Mã giảm giá...">
                            <button class="discount-btn" onclick="applyCoupon()">Áp dụng</button>
                        </div>

                        <div class="summary-row">
                            <span>Tạm tính:</span>
                            <span id="subtotal">0đ</span>
                        </div>
                        <div class="summary-row">
                            <span>Giảm giá:</span>
                            <span id="discount">0đ</span>
                        </div>
                        <div class="summary-row total">
                            <span>Tổng cộng:</span>
                            <span id="total">0đ</span>
                        </div>
                    </div>

                    <div class="payment-section">
                        <div class="payment-title">Thanh toán</div>

                        <div class="payment-methods">
                            <div class="payment-method active" data-method="cash">
                                💵 Tiền mặt
                            </div>
                            <div class="payment-method" data-method="card">
                                💳 Thẻ
                            </div>
                            <div class="payment-method" data-method="momo">
                                📱 MoMo
                            </div>
                            <div class="payment-method" data-method="banking">
                                🏦 Banking
                            </div>
                        </div>

                        <div class="customer-info">
                            <input type="text" class="customer-input" id="customerName" placeholder="Tên khách hàng">
                            <input type="text" class="customer-input" id="customerPhone" placeholder="Số điện thoại">
                        </div>

                        <div id="cashPayment">
                            <input type="number" class="cash-input" id="amountPaid" placeholder="Số tiền khách đưa..."
                                min="0">

                            <div class="quick-amounts">
                                <button class="quick-amount" onclick="setQuickAmount(50000)">50K</button>
                                <button class="quick-amount" onclick="setQuickAmount(100000)">100K</button>
                                <button class="quick-amount" onclick="setQuickAmount(200000)">200K</button>
                                <button class="quick-amount" onclick="setQuickAmount(500000)">500K</button>
                                <button class="quick-amount" onclick="setExactAmount()">Vừa đủ</button>
                                <button class="quick-amount" onclick="clearAmount()">Xóa</button>
                            </div>

                            <div class="change-info" id="changeInfo">
                                <div style="text-align: center; margin-bottom: 4px; font-size: 12px; color: #6b7280;">
                                    Tiền thối lại
                                </div>
                                <div class="change-amount" id="changeAmount">0đ</div>
                            </div>
                        </div>

                        <button class="checkout-btn" id="checkoutBtn" onclick="processPayment()" disabled>
                            Thanh toán
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div id="receiptModal" class="modal">
        <div class="modal-content">
            <div class="receipt" id="receiptContent">
                <!-- Receipt content will be generated here -->
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeReceipt()">Đóng</button>
                <button class="btn btn-primary" onclick="printReceipt()">In hóa đơn</button>
                <button class="btn btn-primary" onclick="newTransaction()">Giao dịch mới</button>
            </div>
        </div>
    </div>

    <!-- Hidden Form -->
    <form id="saleForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="process_sale">
        <input type="hidden" name="cart_items" id="cartItemsData">
        <input type="hidden" name="customer_name" id="customerNameData">
        <input type="hidden" name="customer_phone" id="customerPhoneData">
        <input type="hidden" name="payment_method" id="paymentMethodData">
        <input type="hidden" name="amount_paid" id="amountPaidData">
        <input type="hidden" name="discount_amount" id="discountAmountData">
        <input type="hidden" name="coupon_code" id="couponCodeData">
    </form>

    <script>
    // Global variables
    let cart = [];
    let products = <?php echo json_encode($products); ?>;
    let coupons = <?php echo json_encode($coupons); ?>;
    let currentPaymentMethod = 'cash';
    let currentDiscount = 0;
    let appliedCoupon = '';

    // Initialize POS
    document.addEventListener('DOMContentLoaded', function() {
        updateCartDisplay();
        updateClock();
        setInterval(updateClock, 1000);

        // Auto-focus barcode input
        document.getElementById('barcodeInput').focus();

        // Search functionality
        document.getElementById('productSearch').addEventListener('input', filterProducts);
        document.getElementById('categoryFilter').addEventListener('change', filterProducts);
        document.getElementById('barcodeInput').addEventListener('keypress', handleBarcodeScan);

        // Payment method selection
        document.querySelectorAll('.payment-method').forEach(method => {
            method.addEventListener('click', function() {
                selectPaymentMethod(this.dataset.method);
            });
        });

        // Amount paid calculation
        document.getElementById('amountPaid').addEventListener('input', calculateChange);

        // Show success message if transaction completed
        <?php if (isset($success)): ?>
        showSuccessReceipt(<?php echo $success; ?>);
        <?php endif; ?>

        // Show error message
        <?php if (isset($error)): ?>
        showAlert('<?php echo addslashes($error); ?>', 'error');
        <?php endif; ?>
    });

    // Product filtering
    function filterProducts() {
        const searchTerm = document.getElementById('productSearch').value.toLowerCase();
        const categoryId = document.getElementById('categoryFilter').value;
        const productCards = document.querySelectorAll('.product-card');

        productCards.forEach(card => {
            const name = card.dataset.name.toLowerCase();
            const category = card.dataset.category;

            const matchesSearch = name.includes(searchTerm);
            const matchesCategory = !categoryId || category === categoryId;

            if (matchesSearch && matchesCategory) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    }

    // Barcode scanning
    function handleBarcodeScan(e) {
        if (e.key === 'Enter') {
            const barcode = e.target.value.trim();
            if (barcode) {
                const product = products.find(p => p.barcode === barcode);
                if (product) {
                    addToCartById(product.id);
                    e.target.value = '';
                    showAlert('Đã thêm sản phẩm: ' + product.name, 'success');
                } else {
                    showAlert('Không tìm thấy sản phẩm có mã: ' + barcode, 'error');
                }
            }
        }
    }

    // Add to cart
    function addToCart(element) {
        const productId = parseInt(element.dataset.id);
        const productName = element.dataset.name;
        const productPrice = parseFloat(element.dataset.price);
        const productStock = parseInt(element.dataset.stock);

        if (productStock <= 0) {
            showAlert('Sản phẩm đã hết hàng!', 'error');
            return;
        }

        addToCartById(productId);
    }

    function addToCartById(productId) {
        const product = products.find(p => p.id == productId);
        if (!product) return;

        const existingItem = cart.find(item => item.id === productId);

        if (existingItem) {
            if (existingItem.quantity < product.current_stock) {
                existingItem.quantity++;
            } else {
                showAlert('Không đủ hàng trong kho!', 'error');
                return;
            }
        } else {
            cart.push({
                id: productId,
                name: product.name,
                price: product.unit_price,
                quantity: 1,
                stock: product.current_stock,
                image: getProductImageSrc(product)
            });
        }

        updateCartDisplay();

        // Vibration feedback on mobile
        if (navigator.vibrate) {
            navigator.vibrate(50);
        }
    }

    function getProductImageSrc(product) {
        if (product.thumbnail_file) {
            return '../' + product.thumbnail_file;
        }
        return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgZmlsbD0ibm9uZSI+PHJlY3Qgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBmaWxsPSIjZjNmNGY2Ii8+PHBhdGggZD0iTTE2IDE2aDh2OGgtOHoiIGZpbGw9IiNkOWRjZTAiLz48L3N2Zz4=';
    }

    // Update cart display
    function updateCartDisplay() {
        const cartItemsContainer = document.getElementById('cartItems');
        const checkoutBtn = document.getElementById('checkoutBtn');

        if (cart.length === 0) {
            cartItemsContainer.innerHTML = `
                <div class="empty-cart">
                    <div class="empty-cart-icon">🛒</div>
                    <div>Giỏ hàng trống</div>
                    <div style="font-size: 12px; margin-top: 4px;">Chọn sản phẩm để bắt đầu</div>
                </div>
            `;
            checkoutBtn.disabled = true;
        } else {
            let cartHTML = '';
            cart.forEach((item, index) => {
                cartHTML += `
                    <div class="cart-item">
                        <img src="${item.image}" alt="${item.name}" class="cart-item-image">
                        <div class="cart-item-info">
                            <div class="cart-item-name">${item.name}</div>
                            <div class="cart-item-price">${formatCurrency(item.price)}</div>
                        </div>
                        <div class="cart-item-controls">
                            <button class="qty-btn" onclick="updateQuantity(${index}, -1)" ${item.quantity <= 1 ? 'disabled' : ''}>-</button>
                            <input type="number" class="qty-input" value="${item.quantity}" min="1" max="${item.stock}" 
                                   onchange="setQuantity(${index}, this.value)">
                            <button class="qty-btn" onclick="updateQuantity(${index}, 1)" ${item.quantity >= item.stock ? 'disabled' : ''}>+</button>
                            <button class="remove-btn" onclick="removeFromCart(${index})">×</button>
                        </div>
                    </div>
                `;
            });
            cartItemsContainer.innerHTML = cartHTML;
            checkoutBtn.disabled = false;
        }

        updateSummary();
    }

    // Cart operations
    function updateQuantity(index, change) {
        const item = cart[index];
        const newQuantity = item.quantity + change;

        if (newQuantity >= 1 && newQuantity <= item.stock) {
            item.quantity = newQuantity;
            updateCartDisplay();
        }
    }

    function setQuantity(index, quantity) {
        const item = cart[index];
        const qty = parseInt(quantity);

        if (qty >= 1 && qty <= item.stock) {
            item.quantity = qty;
            updateCartDisplay();
        }
    }

    function removeFromCart(index) {
        cart.splice(index, 1);
        updateCartDisplay();
    }

    function clearCart() {
        if (cart.length > 0 && confirm('Xóa tất cả sản phẩm trong giỏ hàng?')) {
            cart = [];
            currentDiscount = 0;
            appliedCoupon = '';
            document.getElementById('couponCode').value = '';
            updateCartDisplay();
        }
    }

    // Summary calculations
    function updateSummary() {
        const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        const total = subtotal - currentDiscount;

        document.getElementById('subtotal').textContent = formatCurrency(subtotal);
        document.getElementById('discount').textContent = formatCurrency(currentDiscount);
        document.getElementById('total').textContent = formatCurrency(total);

        calculateChange();
    }

    // Coupon application
    function applyCoupon() {
        const couponCode = document.getElementById('couponCode').value.trim().toUpperCase();
        if (!couponCode) return;

        const coupon = coupons.find(c => c.code === couponCode);
        if (!coupon) {
            showAlert('Mã giảm giá không hợp lệ!', 'error');
            return;
        }

        const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        const minBuy = parseFloat(coupon.min_buy) || 0;

        if (subtotal < minBuy) {
            showAlert(`Đơn hàng tối thiểu ${formatCurrency(minBuy)} để sử dụng mã này!`, 'error');
            return;
        }

        if (coupon.discount_type === 'percent') {
            currentDiscount = subtotal * (coupon.discount / 100);
        } else {
            currentDiscount = Math.min(coupon.discount, subtotal);
        }

        appliedCoupon = couponCode;
        updateSummary();
        showAlert(`Đã áp dụng mã giảm giá: ${couponCode}`, 'success');
    }

    // Payment methods
    function selectPaymentMethod(method) {
        currentPaymentMethod = method;

        document.querySelectorAll('.payment-method').forEach(el => {
            el.classList.remove('active');
        });
        document.querySelector(`[data-method="${method}"]`).classList.add('active');

        const cashPayment = document.getElementById('cashPayment');
        if (method === 'cash') {
            cashPayment.style.display = 'block';
        } else {
            cashPayment.style.display = 'none';
            document.getElementById('changeInfo').classList.remove('show');
        }
    }

    // Cash payment helpers
    function setQuickAmount(amount) {
        document.getElementById('amountPaid').value = amount;
        calculateChange();
    }

    function setExactAmount() {
        const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0) - currentDiscount;
        document.getElementById('amountPaid').value = total;
        calculateChange();
    }

    function clearAmount() {
        document.getElementById('amountPaid').value = '';
        document.getElementById('changeInfo').classList.remove('show');
    }

    function calculateChange() {
        const amountPaid = parseFloat(document.getElementById('amountPaid').value) || 0;
        const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0) - currentDiscount;
        const change = amountPaid - total;

        if (currentPaymentMethod === 'cash' && amountPaid > 0) {
            document.getElementById('changeAmount').textContent = formatCurrency(Math.max(0, change));
            if (change >= 0) {
                document.getElementById('changeInfo').classList.add('show');
            } else {
                document.getElementById('changeInfo').classList.remove('show');
            }
        }
    }

    // Process payment
    function processPayment() {
        if (cart.length === 0) {
            showAlert('Giỏ hàng trống!', 'error');
            return;
        }

        const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0) - currentDiscount;
        const amountPaid = parseFloat(document.getElementById('amountPaid').value) || 0;

        if (currentPaymentMethod === 'cash' && amountPaid < total) {
            showAlert('Số tiền thanh toán không đủ!', 'error');
            return;
        }

        // Prepare form data
        document.getElementById('cartItemsData').value = JSON.stringify(cart);
        document.getElementById('customerNameData').value = document.getElementById('customerName').value;
        document.getElementById('customerPhoneData').value = document.getElementById('customerPhone').value;
        document.getElementById('paymentMethodData').value = currentPaymentMethod;
        document.getElementById('amountPaidData').value = currentPaymentMethod === 'cash' ? amountPaid : total;
        document.getElementById('discountAmountData').value = currentDiscount;
        document.getElementById('couponCodeData').value = appliedCoupon;

        // Show loading
        const checkoutBtn = document.getElementById('checkoutBtn');
        checkoutBtn.disabled = true;
        checkoutBtn.textContent = 'Đang xử lý...';

        // Submit form
        document.getElementById('saleForm').submit();
    }

    // Receipt functions
    function showSuccessReceipt(receiptData) {
        const receipt = typeof receiptData === 'string' ? JSON.parse(receiptData) : receiptData;

        const receiptHTML = `
            <div class="receipt-header">
                <div class="receipt-title"><?php echo htmlspecialchars($seller_name); ?></div>
                <div class="receipt-subtitle">HÓA ĐƠN BÁN HÀNG</div>
            </div>
            
            <div class="receipt-info">
                <div>Mã đơn: ${receipt.order_code}</div>
                <div>Thời gian: ${new Date().toLocaleString('vi-VN')}</div>
                ${receipt.customer_name ? `<div>Khách hàng: ${receipt.customer_name}</div>` : ''}
                ${receipt.customer_phone ? `<div>SĐT: ${receipt.customer_phone}</div>` : ''}
                <div>Thu ngân: <?php echo htmlspecialchars($seller_name); ?></div>
            </div>
            
            <div class="receipt-items">
                <div style="border-bottom: 1px solid #333; padding-bottom: 4px; margin-bottom: 8px; font-weight: bold;">
                    <div style="display: flex; justify-content: space-between;">
                        <span>Sản phẩm</span>
                        <span>Thành tiền</span>
                    </div>
                </div>
                ${receipt.items.map(item => `
                    <div class="receipt-item">
                        <div style="flex: 1;">
                            <div>${item.name}</div>
                            <div style="font-size: 10px; color: #666;">
                                ${formatCurrency(item.price)} x ${item.quantity}
                            </div>
                        </div>
                        <div>${formatCurrency(item.price * item.quantity)}</div>
                    </div>
                `).join('')}
            </div>
            
            <div class="receipt-total">
                <div class="receipt-item">
                    <span>Tạm tính:</span>
                    <span>${formatCurrency(receipt.total)}</span>
                </div>
                ${receipt.discount > 0 ? `
                    <div class="receipt-item">
                        <span>Giảm giá:</span>
                        <span>-${formatCurrency(receipt.discount)}</span>
                    </div>
                ` : ''}
                <div class="receipt-item" style="font-size: 14px; border-top: 1px solid #333; padding-top: 4px;">
                    <span>Tổng cộng:</span>
                    <span>${formatCurrency(receipt.final_amount)}</span>
                </div>
                <div class="receipt-item">
                    <span>Thanh toán (${getPaymentMethodText(receipt.payment_method)}):</span>
                    <span>${formatCurrency(receipt.amount_paid)}</span>
                </div>
                ${receipt.change > 0 ? `
                    <div class="receipt-item">
                        <span>Tiền thối:</span>
                        <span>${formatCurrency(receipt.change)}</span>
                    </div>
                ` : ''}
            </div>
            
            <div class="receipt-footer">
                <div>Cảm ơn quý khách đã mua hàng!</div>
                <div>Hẹn gặp lại!</div>
            </div>
        `;

        document.getElementById('receiptContent').innerHTML = receiptHTML;
        document.getElementById('receiptModal').classList.add('show');
    }

    function getPaymentMethodText(method) {
        const methods = {
            'cash': 'Tiền mặt',
            'card': 'Thẻ',
            'momo': 'MoMo',
            'banking': 'Banking'
        };
        return methods[method] || method;
    }

    function printReceipt() {
        const receiptContent = document.getElementById('receiptContent').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>Hóa đơn</title>
                    <style>
                        body { font-family: 'Courier New', monospace; margin: 20px; }
                        .receipt { max-width: 300px; margin: 0 auto; }
                    </style>
                </head>
                <body>
                    <div class="receipt">${receiptContent}</div>
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    }

    function closeReceipt() {
        document.getElementById('receiptModal').classList.remove('show');
    }

    function newTransaction() {
        cart = [];
        currentDiscount = 0;
        appliedCoupon = '';
        document.getElementById('couponCode').value = '';
        document.getElementById('customerName').value = '';
        document.getElementById('customerPhone').value = '';
        document.getElementById('amountPaid').value = '';
        selectPaymentMethod('cash');
        updateCartDisplay();
        closeReceipt();

        // Focus back to barcode input
        document.getElementById('barcodeInput').focus();
    }

    // Utility functions
    function formatCurrency(amount) {
        return new Intl.NumberFormat('vi-VN').format(amount) + 'đ';
    }

    function showAlert(message, type = 'success') {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.textContent = message;
        document.body.appendChild(alertDiv);

        setTimeout(() => alertDiv.classList.add('show'), 100);
        setTimeout(() => {
            alertDiv.classList.remove('show');
            setTimeout(() => document.body.removeChild(alertDiv), 300);
        }, 3000);
    }

    function updateClock() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('vi-VN');
        const clockElement = document.querySelector('.pos-info-value');
        if (clockElement && clockElement.textContent.includes(':')) {
            clockElement.textContent = timeString;
        }
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // F1 - Focus search
        if (e.key === 'F1') {
            e.preventDefault();
            document.getElementById('productSearch').focus();
        }

        // F2 - Focus barcode
        if (e.key === 'F2') {
            e.preventDefault();
            document.getElementById('barcodeInput').focus();
        }

        // F9 - Process payment
        if (e.key === 'F9' && cart.length > 0) {
            e.preventDefault();
            processPayment();
        }

        // Escape - Clear/cancel
        if (e.key === 'Escape') {
            if (document.getElementById('receiptModal').classList.contains('show')) {
                closeReceipt();
            } else {
                clearCart();
            }
        }
    });

    // Sidebar toggle for mobile
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            sidebar.classList.toggle('open');
        }
    }

    // Touch gestures for mobile
    if ('ontouchstart' in window) {
        let touchStartX = 0;
        let touchStartY = 0;

        document.addEventListener('touchstart', function(e) {
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
        });

        document.addEventListener('touchend', function(e) {
            const touchEndX = e.changedTouches[0].clientX;
            const touchEndY = e.changedTouches[0].clientY;
            const deltaX = touchEndX - touchStartX;
            const deltaY = touchEndY - touchStartY;

            // Swipe right to clear cart (if in cart area)
            if (deltaX > 100 && Math.abs(deltaY) < 50) {
                const cartSection = document.querySelector('.cart-section');
                if (cartSection.contains(e.target)) {
                    clearCart();
                }
            }
        });
    }
    </script>
</body>

</html>