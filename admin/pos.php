<?php
/**
 * Admin Pos Page
 *
 * @refactored Uses centralized admin_init.php for authentication and helpers
 */

// Initialize admin page with authentication and admin info
require_once __DIR__ . '/../includes/admin_init.php';
$admin = initAdminPage(true, true);
$db = getDB();

try {
    $stmt = $db->prepare("
        SELECT u.*, s.id as staff_id, r.name as role_name
        FROM users u 
        LEFT JOIN staff s ON u.id = s.user_id
        LEFT JOIN roles r ON s.role_id = r.id
        WHERE u.id = ? LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        session_destroy();
        header('Location: login.php?error=user_not_found');
        exit;
    }
} catch (PDOException $e) {
    error_log("Admin fetch error: " . $e->getMessage());
    header('Location: login.php?error=database_error');
    exit;
}

// Get business settings
function getBusinessSetting($db, $type, $default = '') {
    try {
        $stmt = $db->prepare("SELECT value FROM business_settings WHERE type = ? LIMIT 1");
        $stmt->execute([$type]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['value'] : $default;
    } catch (PDOException $e) {
        error_log("Business setting error: " . $e->getMessage());
        return $default;
    }
}

 else {
        return '$' . number_format((float)$amount, 2, '.', ',');
    }
}

/**
 * Lấy địa chỉ của người dùng
 */
function getUserAddresses($db, $user_id) {
    try {
        $stmt = $db->prepare("
            SELECT a.*, 
                c.name as city_name, 
                s.name as state_name, 
                co.name as country_name
            FROM addresses a
            LEFT JOIN cities c ON a.city_id = c.id
            LEFT JOIN states s ON a.state_id = s.id
            LEFT JOIN countries co ON a.country_id = co.id
            WHERE a.user_id = ?
            ORDER BY a.set_default DESC, a.id DESC
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching user addresses: " . $e->getMessage());
        return [];
    }
}

// Initialize POS cart if not exists
if (!isset($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
}

// Initialize selected customer if not exists
if (!isset($_SESSION['pos_customer'])) {
    $_SESSION['pos_customer'] = null;
}

// XỬ LÝ AJAX REQUESTS
// Kiểm tra nếu là AJAX request và xử lý
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Xóa bất kỳ output nào đã được tạo
    ob_end_clean();
    // Bắt đầu buffer mới để đảm bảo không có output nào được xuất ra trước JSON
    ob_start();
    
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    try {
        switch ($_POST['action']) {
            case 'add_to_cart':
                $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
                $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
                $variation_id = isset($_POST['variation_id']) ? (int)$_POST['variation_id'] : 0;
                
                if ($product_id <= 0 || $quantity <= 0) {
                    throw new Exception('Dữ liệu không hợp lệ');
                }
                
                // Get product info
                $stmt = $db->prepare("
                    SELECT p.*, 
                           u.file_name as product_image,
                           t.name as translated_name
                    FROM products p
                    LEFT JOIN uploads u ON p.thumbnail_img = u.id
                    LEFT JOIN product_translations t ON p.id = t.product_id AND t.lang = 'vi'
                    WHERE p.id = ? AND p.published = 1
                    LIMIT 1
                ");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$product) {
                    throw new Exception('Không tìm thấy sản phẩm');
                }
                
                // Check stock
                if ($product['variant_product'] == 0) {
                    if ($product['current_stock'] < $quantity) {
                        throw new Exception('Sản phẩm không đủ số lượng trong kho');
                    }
                } else if ($variation_id > 0) {
                    // Check variant stock
                    $stmt = $db->prepare("
                        SELECT * FROM product_stocks 
                        WHERE product_id = ? AND id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$product_id, $variation_id]);
                    $variant = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$variant) {
                        throw new Exception('Không tìm thấy biến thể sản phẩm');
                    }
                    
                    if ($variant['qty'] < $quantity) {
                        throw new Exception('Biến thể sản phẩm không đủ số lượng trong kho');
                    }
                    
                    // Add variant info to product
                    $product['variation_id'] = $variation_id;
                    $product['variation'] = $variant['variant'];
                    $product['variation_price'] = $variant['price'];
                }
                
                // Calculate product price
                $price = $product['variant_product'] == 1 && isset($product['variation_price']) 
                         ? $product['variation_price'] 
                         : $product['unit_price'];
                
                // Apply discount if applicable
                if ($product['discount'] > 0) {
                    if ($product['discount_type'] == 'percent') {
                        $price = $price - ($price * $product['discount'] / 100);
                    } else {
                        $price = $price - $product['discount'];
                    }
                }
                
                // Create cart item key (product_id or product_id-variation_id)
                $cart_item_key = $product['variant_product'] == 1 && isset($product['variation_id'])
                                ? $product_id . '-' . $product['variation_id']
                                : $product_id;
                
                // Add to cart
                if (isset($_SESSION['pos_cart'][$cart_item_key])) {
                    // Update quantity if item already in cart
                    $_SESSION['pos_cart'][$cart_item_key]['quantity'] += $quantity;
                } else {
                    // Add new item to cart
                    $_SESSION['pos_cart'][$cart_item_key] = [
                        'product_id' => $product_id,
                        'name' => isset($product['translated_name']) && !empty($product['translated_name']) 
                               ? $product['translated_name'] 
                               : $product['name'],
                        'price' => $price,
                        'quantity' => $quantity,
                        'image' => $product['product_image'] ?? null,
                        'variation_id' => $variation_id > 0 ? $variation_id : null,
                        'variation' => $variation_id > 0 ? $product['variation'] : null,
                        'tax' => $product['tax'] ?? 0,
                        'tax_type' => $product['tax_type'] ?? null,
                        'shipping_cost' => $product['shipping_cost'] ?? 0,
                        'product_referral_code' => null,
                    ];
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Đã thêm sản phẩm vào giỏ hàng',
                    'cart' => $_SESSION['pos_cart']
                ]);
                break;
                
            case 'remove_from_cart':
                $cart_item_key = isset($_POST['cart_item_key']) ? $_POST['cart_item_key'] : '';
                
                if (empty($cart_item_key)) {
                    throw new Exception('Dữ liệu không hợp lệ');
                }
                
                if (isset($_SESSION['pos_cart'][$cart_item_key])) {
                    unset($_SESSION['pos_cart'][$cart_item_key]);
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Đã xóa sản phẩm khỏi giỏ hàng',
                        'cart' => $_SESSION['pos_cart']
                    ]);
                } else {
                    throw new Exception('Không tìm thấy sản phẩm trong giỏ hàng');
                }
                break;
                
            case 'update_cart_quantity':
                $cart_item_key = isset($_POST['cart_item_key']) ? $_POST['cart_item_key'] : '';
                $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
                
                if (empty($cart_item_key) || $quantity <= 0) {
                    throw new Exception('Dữ liệu không hợp lệ');
                }
                
                if (isset($_SESSION['pos_cart'][$cart_item_key])) {
                    // Check if it's a variation or simple product
                    if (strpos($cart_item_key, '-') !== false) {
                        list($product_id, $variation_id) = explode('-', $cart_item_key);
                        
                        // Check variant stock
                        $stmt = $db->prepare("
                            SELECT * FROM product_stocks 
                            WHERE product_id = ? AND id = ?
                            LIMIT 1
                        ");
                        $stmt->execute([$product_id, $variation_id]);
                        $variant = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$variant) {
                            throw new Exception('Không tìm thấy biến thể sản phẩm');
                        }
                        
                        if ($variant['qty'] < $quantity) {
                            throw new Exception('Biến thể sản phẩm không đủ số lượng trong kho');
                        }
                    } else {
                        // Check product stock
                        $product_id = $cart_item_key;
                        $stmt = $db->prepare("SELECT current_stock FROM products WHERE id = ? LIMIT 1");
                        $stmt->execute([$product_id]);
                        $product = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$product) {
                            throw new Exception('Không tìm thấy sản phẩm');
                        }
                        
                        if ($product['current_stock'] < $quantity) {
                            throw new Exception('Sản phẩm không đủ số lượng trong kho');
                        }
                    }
                    
                    // Update quantity
                    $_SESSION['pos_cart'][$cart_item_key]['quantity'] = $quantity;
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Đã cập nhật số lượng sản phẩm',
                        'cart' => $_SESSION['pos_cart']
                    ]);
                } else {
                    throw new Exception('Không tìm thấy sản phẩm trong giỏ hàng');
                }
                break;
                
            case 'clear_cart':
                $_SESSION['pos_cart'] = [];
                echo json_encode([
                    'success' => true, 
                    'message' => 'Đã xóa toàn bộ giỏ hàng',
                    'cart' => $_SESSION['pos_cart']
                ]);
                break;
                
            case 'select_customer':
                $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
                
                if ($customer_id <= 0) {
                    $_SESSION['pos_customer'] = null;
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Đã xóa khách hàng đã chọn',
                        'customer' => null
                    ]);
                    break;
                }
                
                // Lấy thông tin khách hàng
                $stmt = $db->prepare("
                    SELECT id, name, email, phone, avatar, avatar_original, address, country, state, city, postal_code
                    FROM users
                    WHERE id = ? AND user_type = 'customer'
                    LIMIT 1
                ");
                $stmt->execute([$customer_id]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$customer) {
                    throw new Exception('Không tìm thấy khách hàng');
                }
                
                // Lấy địa chỉ của khách hàng
                $addresses = getUserAddresses($db, $customer_id);
                $customer['addresses'] = $addresses;
                
                $_SESSION['pos_customer'] = $customer;
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Đã chọn khách hàng',
                    'customer' => $customer
                ]);
                break;
                
            case 'create_order':
                // Check if cart is empty
                if (empty($_SESSION['pos_cart'])) {
                    throw new Exception('Giỏ hàng đang trống');
                }
                
                $payment_type = isset($_POST['payment_type']) ? $_POST['payment_type'] : '';
                $payment_status = isset($_POST['payment_status']) ? $_POST['payment_status'] : 'unpaid';
                $shipping_address_id = isset($_POST['shipping_address_id']) ? (int)$_POST['shipping_address_id'] : 0;
                $shipping_type = isset($_POST['shipping_type']) ? $_POST['shipping_type'] : 'home_delivery';
                $additional_info = isset($_POST['additional_info']) ? $_POST['additional_info'] : '';
                $seller_id = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;
                
                // Kiểm tra nếu chọn giao hàng tận nơi và không có địa chỉ
                if ($shipping_type === 'home_delivery' && isset($_SESSION['pos_customer']) && 
                    $_SESSION['pos_customer'] && $shipping_address_id <= 0) {
                    throw new Exception('Vui lòng chọn địa chỉ giao hàng');
                }
                
                // Start transaction
                $db->beginTransaction();
                
                try {
                    // Generate unique code for order
                    $order_code = 'ORD-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                    
                    // Get shipping address if address ID is provided
                    $shipping_address = null;
                    if ($shipping_address_id > 0) {
                        $stmt = $db->prepare("
                            SELECT a.*, c.name as city_name, s.name as state_name, co.name as country_name
                            FROM addresses a
                            LEFT JOIN cities c ON a.city_id = c.id
                            LEFT JOIN states s ON a.state_id = s.id
                            LEFT JOIN countries co ON a.country_id = co.id
                            WHERE a.id = ?
                            LIMIT 1
                        ");
                        $stmt->execute([$shipping_address_id]);
                        $address = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($address) {
                            $shipping_address = json_encode($address);
                        }
                    }
                    
                    // Calculate order totals
                    $subtotal = 0;
                    $tax = 0;
                    $shipping = 0;
                    
                    foreach ($_SESSION['pos_cart'] as $item) {
                        $item_total = $item['price'] * $item['quantity'];
                        $subtotal += $item_total;
                        
                        // Calculate tax if applicable
                        if (isset($item['tax']) && $item['tax'] > 0) {
                            if ($item['tax_type'] == 'percent') {
                                $tax += $item_total * ($item['tax'] / 100);
                            } else {
                                $tax += $item['tax'] * $item['quantity'];
                            }
                        }
                        
                        // Add shipping cost if applicable
                        if (isset($item['shipping_cost']) && $item['shipping_cost'] > 0) {
                            $shipping += $item['shipping_cost'];
                        }
                    }
                    
                    $grand_total = $subtotal + $tax + $shipping;
                    
                    // Create combined order
                    $stmt = $db->prepare("
                        INSERT INTO combined_orders 
                        (user_id, shipping_address, grand_total, created_at, updated_at)
                        VALUES (?, ?, ?, NOW(), NOW())
                    ");
                    $user_id = isset($_SESSION['pos_customer']) && $_SESSION['pos_customer'] 
                              ? $_SESSION['pos_customer']['id'] 
                              : null;
                    $stmt->execute([$user_id, $shipping_address, $grand_total]);
                    $combined_order_id = $db->lastInsertId();
                    
                    // Get the seller ID (from shop or default to admin)
                    $actual_seller_id = 1; // Default to admin
                    if ($seller_id > 0) {
                        $seller_stmt = $db->prepare("SELECT user_id FROM shops WHERE id = ? LIMIT 1");
                        $seller_stmt->execute([$seller_id]);
                        $seller_result = $seller_stmt->fetch(PDO::FETCH_ASSOC);
                        if ($seller_result) {
                            $actual_seller_id = $seller_result['user_id'];
                        }
                    }
                    
                    // Create order
                    $stmt = $db->prepare("
                        INSERT INTO orders 
                        (combined_order_id, user_id, seller_id, shipping_address, additional_info, 
                        shipping_type, order_from, pickup_point_id, delivery_status, payment_type,
                        payment_status, payment_details, grand_total, code, date, viewed, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $combined_order_id,
                        $user_id,
                        $actual_seller_id,
                        $shipping_address,
                        $additional_info,
                        $shipping_type,
                        'pos',
                        0, // no pickup point for now
                        'pending',
                        $payment_type,
                        $payment_status,
                        null, // payment details
                        $grand_total,
                        $order_code,
                        time(),
                        1 // viewed
                    ]);
                    $order_id = $db->lastInsertId();
                    
                    // Add order details
                    foreach ($_SESSION['pos_cart'] as $item) {
                        $stmt = $db->prepare("
                            INSERT INTO order_details 
                            (order_id, seller_id, product_id, variation, price, tax, shipping_cost, quantity, 
                            payment_status, delivery_status, shipping_type, product_referral_code, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ");
                        
                        $variation = null;
                        if ($item['variation_id'] && $item['variation']) {
                            $variation = json_encode(['id' => $item['variation_id'], 'name' => $item['variation']]);
                        }
                        
                        $item_tax = 0;
                        if (isset($item['tax']) && $item['tax'] > 0) {
                            if ($item['tax_type'] == 'percent') {
                                $item_tax = $item['price'] * ($item['tax'] / 100);
                            } else {
                                $item_tax = $item['tax'];
                            }
                        }
                        
                        $stmt->execute([
                            $order_id,
                            $actual_seller_id,
                            $item['product_id'],
                            $variation,
                            $item['price'],
                            $item_tax,
                            $item['shipping_cost'] ?? 0,
                            $item['quantity'],
                            $payment_status,
                            'pending',
                            $shipping_type,
                            $item['product_referral_code'] ?? null
                        ]);
                        
                        // Update product stock
                        if (isset($item['variation_id']) && $item['variation_id']) {
                            // Update variant stock
                            $stmt = $db->prepare("
                                UPDATE product_stocks
                                SET qty = qty - ?
                                WHERE id = ? AND product_id = ?
                            ");
                            $stmt->execute([$item['quantity'], $item['variation_id'], $item['product_id']]);
                        } else {
                            // Update product stock
                            $stmt = $db->prepare("
                                UPDATE products
                                SET current_stock = current_stock - ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$item['quantity'], $item['product_id']]);
                        }
                        
                        // Update num_of_sale
                        $stmt = $db->prepare("
                            UPDATE products
                            SET num_of_sale = num_of_sale + ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$item['quantity'], $item['product_id']]);
                    }
                    
                    // Commit transaction
                    $db->commit();
                    
                    // Clear cart
                    $_SESSION['pos_cart'] = [];
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Đã tạo đơn hàng thành công',
                        'order_id' => $order_id,
                        'order_code' => $order_code
                    ]);
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                break;
                
            case 'get_product_variations':
                $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
                
                if ($product_id <= 0) {
                    throw new Exception('Dữ liệu không hợp lệ');
                }
                
                // Get product info
                $stmt = $db->prepare("
                    SELECT variant_product, attributes, choice_options, colors, variations 
                    FROM products 
                    WHERE id = ? LIMIT 1
                ");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$product || $product['variant_product'] == 0) {
                    echo json_encode(['success' => true, 'variations' => []]);
                    break;
                }
                
                // Get variations
                $stmt = $db->prepare("
                    SELECT id, variant, price, qty, image
                    FROM product_stocks
                    WHERE product_id = ?
                ");
                $stmt->execute([$product_id]);
                $variations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get variation images
                foreach ($variations as &$variation) {
                    if ($variation['image']) {
                        $stmt = $db->prepare("SELECT file_name FROM uploads WHERE id = ? LIMIT 1");
                        $stmt->execute([$variation['image']]);
                        $image = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($image) {
                            $variation['image_url'] = $image['file_name'];
                        }
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'variations' => $variations,
                    'product_info' => [
                        'attributes' => json_decode($product['attributes'] ?? '[]', true),
                        'choice_options' => json_decode($product['choice_options'] ?? '[]', true),
                        'colors' => json_decode($product['colors'] ?? '[]', true)
                    ]
                ]);
                break;
                
            case 'search_products':
                $search_term = isset($_POST['search']) ? $_POST['search'] : '';
                $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
                $shop_id = isset($_POST['shop_id']) ? (int)$_POST['shop_id'] : 0; // Get shop ID from request
                $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
                $per_page = 24;
                
                $params = [];
                $where_clauses = ["p.published = 1"];
                
                if (!empty($search_term)) {
                    $where_clauses[] = "(p.name LIKE ? OR t.name LIKE ? OR p.tags LIKE ?)";
                    $search_pattern = "%{$search_term}%";
                    $params[] = $search_pattern;
                    $params[] = $search_pattern;
                    $params[] = $search_pattern;
                }
                
                if ($category_id > 0) {
                    $where_clauses[] = "p.category_id = ?";
                    $params[] = $category_id;
                }
                
                // Filter by shop if selected
                if ($shop_id > 0) {
                    $where_clauses[] = "p.user_id = (SELECT user_id FROM shops WHERE id = ?)";
                    $params[] = $shop_id;
                }
                
                $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
                
                // Count total products
                $count_sql = "
                    SELECT COUNT(*) as count
                    FROM products p
                    LEFT JOIN product_translations t ON p.id = t.product_id AND t.lang = 'vi'
                    $where_sql
                ";
                
                $stmt = $db->prepare($count_sql);
                $stmt->execute($params);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $total_products = $result ? (int)$result['count'] : 0;
                
                // Calculate total pages
                $total_pages = ceil($total_products / $per_page);
                $offset = ($page - 1) * $per_page;
                
                // Get products with pagination
                $products_sql = "
                    SELECT p.*, 
                           u.file_name as product_image,
                           t.name as translated_name,
                           c.name as category_name
                    FROM products p
                    LEFT JOIN uploads u ON p.thumbnail_img = u.id
                    LEFT JOIN product_translations t ON p.id = t.product_id AND t.lang = 'vi'
                    LEFT JOIN categories c ON p.category_id = c.id
                    $where_sql
                    ORDER BY p.created_at DESC
                    LIMIT $per_page OFFSET $offset
                ";
                
                $stmt = $db->prepare($products_sql);
                $stmt->execute($params);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Process products for display
                foreach ($products as &$product) {
                    // Set display name (translated if available)
                    $product['display_name'] = !empty($product['translated_name']) 
                                             ? $product['translated_name'] 
                                             : $product['name'];
                    
                    // Calculate display price
                    $product['display_price'] = $product['unit_price'];
                    if ($product['discount'] > 0) {
                        if ($product['discount_type'] == 'percent') {
                            $product['display_price'] = $product['unit_price'] - ($product['unit_price'] * $product['discount'] / 100);
                        } else {
                            $product['display_price'] = $product['unit_price'] - $product['discount'];
                        }
                    }
                    
                    // Format price
                    $product['formatted_price'] = formatCurrency($product['display_price']);
                    $product['formatted_original_price'] = formatCurrency($product['unit_price']);
                    
                    // Check stock status
                    $product['stock_status'] = $product['current_stock'] > 0 ? 'in_stock' : 'out_of_stock';
                }
                
                echo json_encode([
                    'success' => true,
                    'products' => $products,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => $total_pages,
                        'total_products' => $total_products
                    ]
                ]);
                break;
                
            case 'get_categories':
                $stmt = $db->prepare("
                    SELECT c.*, t.name as translated_name, COUNT(p.id) as product_count
                    FROM categories c
                    LEFT JOIN category_translations t ON c.id = t.category_id AND t.lang = 'vi'
                    LEFT JOIN products p ON c.id = p.category_id AND p.published = 1
                    WHERE c.parent_id = 0
                    GROUP BY c.id
                    ORDER BY c.order_level ASC
                ");
                $stmt->execute();
                $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get subcategories
                foreach ($categories as &$category) {
                    $stmt = $db->prepare("
                        SELECT c.*, t.name as translated_name, COUNT(p.id) as product_count
                        FROM categories c
                        LEFT JOIN category_translations t ON c.id = t.category_id AND t.lang = 'vi'
                        LEFT JOIN products p ON c.id = p.category_id AND p.published = 1
                        WHERE c.parent_id = ?
                        GROUP BY c.id
                        ORDER BY c.order_level ASC
                    ");
                    $stmt->execute([$category['id']]);
                    $category['subcategories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Set display name (translated if available)
                    $category['display_name'] = !empty($category['translated_name']) 
                                              ? $category['translated_name'] 
                                              : $category['name'];
                    
                    // Process subcategories
                    foreach ($category['subcategories'] as &$subcategory) {
                        $subcategory['display_name'] = !empty($subcategory['translated_name']) 
                                                     ? $subcategory['translated_name'] 
                                                     : $subcategory['name'];
                    }
                }
                
                echo json_encode(['success' => true, 'categories' => $categories]);
                break;
                case 'get_customer_addresses':
                    $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
                    
                    if ($customer_id <= 0) {
                        throw new Exception('ID khách hàng không hợp lệ');
                    }
                    
                    // Get addresses
                    $addresses = getUserAddresses($db, $customer_id);
                    
                    echo json_encode([
                        'success' => true,
                        'addresses' => $addresses
                    ]);
                    break;
                    
                case 'add_customer_address':
                    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
                    $address = isset($_POST['address']) ? $_POST['address'] : '';
                    $country_id = isset($_POST['country_id']) ? (int)$_POST['country_id'] : 0;
                    $state_id = isset($_POST['state_id']) ? (int)$_POST['state_id'] : 0;
                    $city_id = isset($_POST['city_id']) ? (int)$_POST['city_id'] : 0;
                    $postal_code = isset($_POST['postal_code']) ? $_POST['postal_code'] : '';
                    $phone = isset($_POST['phone']) ? $_POST['phone'] : '';
                    $set_default = isset($_POST['set_default']) ? (int)$_POST['set_default'] : 0;
                    
                    if ($user_id <= 0 || empty($address) || $country_id <= 0 || $state_id <= 0) {
                        throw new Exception('Vui lòng điền đầy đủ thông tin bắt buộc');
                    }
                    
                    // If setting as default, update existing addresses to not be default
                    if ($set_default) {
                        $stmt = $db->prepare("UPDATE addresses SET set_default = 0 WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                    }
                    
                    // Insert new address
                    $stmt = $db->prepare("
                        INSERT INTO addresses 
                        (user_id, address, country_id, state_id, city_id, postal_code, phone, set_default, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    
                    $stmt->execute([
                        $user_id,
                        $address,
                        $country_id,
                        $state_id,
                        $city_id,
                        $postal_code,
                        $phone,
                        $set_default
                    ]);
                    
                    $address_id = $db->lastInsertId();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Địa chỉ đã được thêm thành công',
                        'address_id' => $address_id
                    ]);
                    break;
                    
                case 'get_countries':
                    $stmt = $db->prepare("
                        SELECT id, code, name
                        FROM countries
                        WHERE status = 1
                        ORDER BY name ASC
                    ");
                    $stmt->execute();
                    $countries = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'success' => true,
                        'countries' => $countries
                    ]);
                    break;
                    
                case 'get_states':
                    $country_id = isset($_POST['country_id']) ? (int)$_POST['country_id'] : 0;
                    
                    if ($country_id <= 0) {
                        throw new Exception('ID quốc gia không hợp lệ');
                    }
                    
                    $stmt = $db->prepare("
                        SELECT id, name
                        FROM states
                        WHERE country_id = ? AND status = 1
                        ORDER BY name ASC
                    ");
                    $stmt->execute([$country_id]);
                    $states = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'success' => true,
                        'states' => $states
                    ]);
                    break;
                    
                case 'get_cities':
                    $state_id = isset($_POST['state_id']) ? (int)$_POST['state_id'] : 0;
                    
                    if ($state_id <= 0) {
                        throw new Exception('ID tỉnh/thành phố không hợp lệ');
                    }
                    
                    $stmt = $db->prepare("
                        SELECT id, name
                        FROM cities
                        WHERE state_id = ? AND status = 1
                        ORDER BY name ASC
                    ");
                    $stmt->execute([$state_id]);
                    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'success' => true,
                        'cities' => $cities
                    ]);
                    break;
            default:
                echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ']);
        }
    } catch (Exception $e) {
        error_log("AJAX action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    // Kết thúc xử lý AJAX
    ob_end_flush();
    exit;
}

// Nếu không phải AJAX request, tiếp tục với giao diện trang
$site_name = getBusinessSetting($db, 'site_name', 'Active E-Commerce');
// Initialize variables that might be used in templates
$current_cart = isset($_SESSION['pos_cart']) ? $_SESSION['pos_cart'] : [];
$current_customer = isset($_SESSION['pos_customer']) ? $_SESSION['pos_customer'] : null;

// Calculate cart totals
$cart_total = 0;
$cart_tax = 0;
$cart_shipping = 0;
$cart_discount = 0;
$cart_item_count = 0;

// Ensure current_cart is an array before processing
if (isset($current_cart) && is_array($current_cart)) {
    foreach ($current_cart as $item) {
        // Validate item structure
        if (!is_array($item)) continue;
        
        // Get price and quantity with defaults if not set
        $item_price = isset($item['price']) ? floatval($item['price']) : 0;
        $item_quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
        
        $item_total = $item_price * $item_quantity;
        $cart_total += $item_total;
        $cart_item_count += $item_quantity;
        
        // Calculate tax if applicable (with safe checks)
        if (isset($item['tax']) && is_numeric($item['tax']) && $item['tax'] > 0) {
            if (isset($item['tax_type']) && $item['tax_type'] == 'percent') {
                $cart_tax += $item_total * ($item['tax'] / 100);
            } else {
                $cart_tax += $item['tax'] * $item_quantity;
            }
        }
        
        // Add shipping cost if applicable (with safe checks)
        if (isset($item['shipping_cost']) && is_numeric($item['shipping_cost']) && $item['shipping_cost'] > 0) {
            $cart_shipping += $item['shipping_cost'];
        }
    }
}

$cart_grand_total = $cart_total + $cart_tax + $cart_shipping - $cart_discount;

// Get featured categories
try {
    $stmt = $db->prepare("
        SELECT c.*, t.name as translated_name
        FROM categories c
        LEFT JOIN category_translations t ON c.id = t.category_id AND t.lang = 'vi'
        WHERE c.featured = 1
        ORDER BY c.order_level ASC
        LIMIT 10
    ");
    $stmt->execute();
    $featured_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process categories for display
    foreach ($featured_categories as &$category) {
        $category['display_name'] = !empty($category['translated_name']) 
                                  ? $category['translated_name'] 
                                  : $category['name'];
    }
} catch (PDOException $e) {
    error_log("Categories fetch error: " . $e->getMessage());
    $featured_categories = [];
}

// Get recent products
try {
    $stmt = $db->prepare("
        SELECT p.*, 
               u.file_name as product_image,
               t.name as translated_name
        FROM products p
        LEFT JOIN uploads u ON p.thumbnail_img = u.id
        LEFT JOIN product_translations t ON p.id = t.product_id AND t.lang = 'vi'
        WHERE p.published = 1
        ORDER BY p.created_at DESC
        LIMIT 24
    ");
    $stmt->execute();
    $recent_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process products for display
    foreach ($recent_products as &$product) {
        // Set display name (translated if available)
        $product['display_name'] = !empty($product['translated_name']) 
                                 ? $product['translated_name'] 
                                 : $product['name'];
        
        // Calculate display price
        $product['display_price'] = $product['unit_price'];
        if ($product['discount'] > 0) {
            if ($product['discount_type'] == 'percent') {
                $product['display_price'] = $product['unit_price'] - ($product['unit_price'] * $product['discount'] / 100);
            } else {
                $product['display_price'] = $product['unit_price'] - $product['discount'];
            }
        }
        
        // Format price
        $product['formatted_price'] = formatCurrency($product['display_price']);
        $product['formatted_original_price'] = formatCurrency($product['unit_price']);
        
        // Check stock status
        $product['stock_status'] = $product['current_stock'] > 0 ? 'in_stock' : 'out_of_stock';
    }
} catch (PDOException $e) {
    error_log("Products fetch error: " . $e->getMessage());
    $recent_products = [];
}

// Clean any output buffering to start HTML fresh
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - Quản lý bán hàng - Admin <?php echo safe_echo($site_name); ?></title>
    <meta name="description" content="POS - Quản lý bán hàng - Admin <?php echo safe_echo($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
   <link rel="stylesheet" href="pos.css">

</head>
    <link rel="stylesheet" href="../asset/css/pages/admin-pos.css">
<body>
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">A</div>
                <h1 class="sidebar-title">Admin Panel</h1>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tổng quan</div>
                    <div class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="analytics.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Phân tích</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Bán hàng</div>
                    <div class="nav-item">
                        <a href="orders.php" class="nav-link">
                            <span class="nav-icon">📦</span>
                            <span class="nav-text">Đơn hàng</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="pos.php" class="nav-link active">
                            <span class="nav-icon">🛒</span>
                            <span class="nav-text">POS</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="products.php" class="nav-link">
                            <span class="nav-icon">🛍️</span>
                            <span class="nav-text">Sản phẩm</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="categories.php" class="nav-link">
                            <span class="nav-icon">📂</span>
                            <span class="nav-text">Danh mục</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="brands.php" class="nav-link">
                            <span class="nav-icon">🏷️</span>
                            <span class="nav-text">Thương hiệu</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Khách hàng</div>
                    <div class="nav-item">
                        <a href="users.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Người dùng</span>
                        </a>
                    </div>
                    <div class="nav-item">
                    <div class="nav-item">
                        <a href="reviews.php" class="nav-link">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Đánh giá</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Hệ thống</div>
                    <div class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <span class="nav-icon">⚙️</span>
                            <span class="nav-text">Cài đặt</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="staff.php" class="nav-link">
                            <span class="nav-icon">👨‍💼</span>
                            <span class="nav-text">Nhân viên</span>
                        </a>
                    </div>
                </div>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle sidebar">
                        ☰
                    </button>
                    <nav class="breadcrumb" aria-label="Breadcrumb">
                        <div class="breadcrumb-item">
                            <a href="dashboard.php">Admin</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span>POS</span>
                        </div>
                    </nav>
                </div>
                
                <div class="header-right">
                    <div class="user-menu">
                        <button class="user-button" id="user-menu-button">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr(get_value($admin, 'name', 'A'), 0, 2)); ?>
                            </div>
                            <div class="user-info">
                                <div class="user-name"><?php echo safe_echo(get_value($admin, 'name', 'Admin')); ?></div>
                                <div class="user-role"><?php echo safe_echo(get_value($admin, 'role_name', 'Administrator')); ?></div>
                            </div>
                            <span>▼</span>
                        </button>
                    </div>
                </div>
            </header>
            
            <!-- POS Container -->
            <div class="pos-container">
                <!-- Products Area -->
                <div class="products-area" id="products-area">
                    <!-- POS Search -->
                    <div class="pos-search-container">
                        <div class="search-row">
                            <div class="search-input-container">
                                <span class="search-icon">🔍</span>
                                <input type="text" class="search-input" id="product-search" placeholder="Tìm kiếm sản phẩm theo tên, mã...">
                            </div>
                            <button class="barcode-button" id="barcode-scan-btn">
                                <span>📷</span>
                                <span>Quét mã</span>
                            </button>
                        </div>
                        
                        <!-- Shop Selection Section -->
                        <div class="shop-selection" style="margin-top: 15px; display: flex; gap: 10px; align-items: center;">
                            <label for="shop-select" style="font-weight: 500; min-width: 120px;">Chọn cửa hàng:</label>
                            <select id="shop-select" class="form-control" style="flex-grow: 1;">
                                <option value="0">Tất cả cửa hàng</option>
                                <?php
                                // Get shops from database
                                try {
                                    $shop_stmt = $db->prepare("
                                        SELECT s.id, s.name, u.name as owner_name
                                        FROM shops s
                                        JOIN users u ON s.user_id = u.id
                                        WHERE s.verification_status = 1
                                        ORDER BY s.name ASC
                                    ");
                                    $shop_stmt->execute();
                                    $shops = $shop_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    foreach ($shops as $shop) {
                                        echo '<option value="' . $shop['id'] . '">' . safe_echo($shop['name']) . ' (' . safe_echo($shop['owner_name']) . ')</option>';
                                    }
                                } catch (PDOException $e) {
                                    error_log("Shop fetch error: " . $e->getMessage());
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Categories Row -->
                    <div class="categories-row" id="categories-container">
                        <div class="category-card active" data-category-id="0">
                            <div class="category-icon">🏠</div>
                            <div class="category-name">Tất cả</div>
                        </div>
                        
                        <?php foreach ($featured_categories as $category): ?>
                            <div class="category-card" data-category-id="<?php echo (int)$category['id']; ?>">
                                <div class="category-icon"><?php echo !empty($category['icon']) ? safe_echo($category['icon']) : '📂'; ?></div>
                                <div class="category-name"><?php echo safe_echo($category['display_name']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Products Grid -->
                    <div class="products-grid" id="products-grid">
                        <?php foreach ($recent_products as $product): ?>
                            <div class="product-card" data-product-id="<?php echo (int)$product['id']; ?>">
                                <img src="<?php echo !empty($product['product_image']) ? '../' . safe_echo($product['product_image']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="180" height="140" viewBox="0 0 180 140"><rect width="180" height="140" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="24" fill="%236b7280">No Image</text></svg>'; ?>" alt="<?php echo safe_echo($product['display_name']); ?>" class="product-image">
                                <div class="product-details">
                                    <div class="product-name"><?php echo safe_echo($product['display_name']); ?></div>
                                    <div class="product-price">
                                        <?php echo formatCurrency($product['display_price']); ?>
                                        <?php if ($product['display_price'] < $product['unit_price']): ?>
                                            <span class="product-original-price"><?php echo formatCurrency($product['unit_price']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($product['current_stock'] <= 0): ?>
                                    <span class="product-badge out-of-stock">Hết hàng</span>
                                <?php elseif ($product['current_stock'] <= 5): ?>
                                    <span class="product-badge low-stock">Sắp hết</span>
                                <?php elseif ($product['discount'] > 0): ?>
                                    <span class="product-badge sale">Sale</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Cart Area -->
                <div class="cart-area" id="cart-area">
                    <div class="cart-header">
                        <div class="cart-title">
                            <span>Giỏ hàng</span>
                            <span class="cart-badge"><?php echo $cart_item_count; ?></span>
                        </div>
                        <button class="clear-cart-btn" id="clear-cart-btn">
                            <span>🗑️</span>
                            <span>Xóa tất cả</span>
                        </button>
                    </div>
                    
                    <div class="cart-items" id="cart-items">
                        <?php if (empty($current_cart)): ?>
                            <div class="cart-empty" id="cart-empty">
                                <div class="cart-empty-icon">🛒</div>
                                <div class="cart-empty-text">Giỏ hàng trống</div>
                                <div class="cart-empty-subtext">Thêm sản phẩm bằng cách nhấp vào sản phẩm ở danh sách bên trái</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($current_cart as $key => $item): ?>
                                <div class="cart-item" data-item-key="<?php echo safe_echo($key); ?>">
                                    <img src="<?php echo !empty($item['image']) ? '../' . safe_echo($item['image']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 60 60"><rect width="60" height="60" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="12" fill="%236b7280">No Image</text></svg>'; ?>" alt="<?php echo safe_echo($item['name']); ?>" class="cart-item-image">
                                    <div class="cart-item-details">
                                        <div class="cart-item-name"><?php echo safe_echo($item['name']); ?></div>
                                        <?php if (!empty($item['variation'])): ?>
                                            <div class="cart-item-variant"><?php echo safe_echo($item['variation']); ?></div>
                                        <?php endif; ?>
                                        <div class="cart-item-price"><?php echo formatCurrency($item['price']); ?></div>
                                        <div class="cart-item-actions">
                                            <div class="cart-item-quantity">
                                                <button class="quantity-btn quantity-decrease">-</button>
                                                <input type="number" class="quantity-input" value="<?php echo (int)$item['quantity']; ?>" min="1" max="100">
                                                <button class="quantity-btn quantity-increase">+</button>
                                            </div>
                                        </div>
                                    </div>
                                    <button class="remove-item-btn">🗑️</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="cart-footer">
                        <div class="customer-select" id="customer-select">
                            <div class="customer-select-label">Khách hàng</div>
                            <div class="customer-select-value">
                                <?php if (isset($current_customer) && $current_customer): ?>
                                    <div class="customer-avatar-small">
                                        <?php echo strtoupper(substr($current_customer['name'], 0, 1)); ?>
                                    </div>
                                    <span><?php echo safe_echo($current_customer['name']); ?></span>
                                <?php else: ?>
                                    <span>👤</span>
                                    <span>Chọn khách hàng</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="cart-subtotal">
                            <span>Tạm tính</span>
                            <span id="cart-subtotal"><?php echo formatCurrency($cart_total); ?></span>
                        </div>
                        
                        <?php if ($cart_tax > 0): ?>
                            <div class="cart-tax">
                                <span>Thuế</span>
                                <span id="cart-tax"><?php echo formatCurrency($cart_tax); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($cart_shipping > 0): ?>
                            <div class="cart-shipping">
                                <span>Phí vận chuyển</span>
                                <span id="cart-shipping"><?php echo formatCurrency($cart_shipping); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($cart_discount > 0): ?>
                            <div class="cart-discount">
                                <span>Giảm giá</span>
                                <span id="cart-discount">-<?php echo formatCurrency($cart_discount); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <div class="cart-total">
                            <span>Tổng thanh toán</span>
                            <span id="cart-total"><?php echo formatCurrency($cart_grand_total); ?></span>
                        </div>
                        
                        <div class="cart-actions">
                            <button class="cart-action-button button-primary" id="checkout-btn" <?php echo empty($current_cart) ? 'disabled' : ''; ?>>
                                <span>💳</span>
                                <span>Thanh toán</span>
                            </button>
                            <button class="cart-action-button button-secondary" id="address-btn" <?php echo empty($current_cart) ? 'disabled' : ''; ?>>
        <span>🏠</span>
        <span>Địa chỉ giao hàng</span>
    </button>
                            <button class="cart-action-button button-secondary" id="hold-order-btn" <?php echo empty($current_cart) ? 'disabled' : ''; ?>>
                                <span>⏱️</span>
                                <span>Giữ đơn hàng</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Mobile Cart Toggle -->
                <div class="mobile-cart-toggle" id="mobile-cart-toggle" style="display: none;">
                    <span>🛒</span>
                    <div class="mobile-cart-count" id="mobile-cart-count"><?php echo $cart_item_count; ?></div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Customer Select Modal -->
    <div class="modal-backdrop" id="customer-modal">
        <div class="modal large">
            <div class="modal-header">
                <h2 class="modal-title">Chọn khách hàng</h2>
                <button class="modal-close" id="customer-modal-close">×</button>
            </div>
            <div class="modal-body">
                <div class="customer-filter" style="margin-bottom: 15px;">
                    <input type="text" class="form-control" id="customer-filter" placeholder="Lọc khách hàng..." style="width: 100%;">
                </div>
                <div class="customer-list-container" style="max-height: 500px; overflow-y: auto;">
                    <table class="customer-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="padding: 10px; text-align: left; border-bottom: 1px solid var(--border); background-color: var(--gray-50);">ID</th>
                                <th style="padding: 10px; text-align: left; border-bottom: 1px solid var(--border); background-color: var(--gray-50);">Tên khách hàng</th>
                                <th style="padding: 10px; text-align: left; border-bottom: 1px solid var(--border); background-color: var(--gray-50);">Email</th>
                                <th style="padding: 10px; text-align: left; border-bottom: 1px solid var(--border); background-color: var(--gray-50);">Số điện thoại</th>
                                <th style="padding: 10px; text-align: center; border-bottom: 1px solid var(--border); background-color: var(--gray-50);">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody id="customer-list">
                            <?php
                            // Get customers from database
                            try {
                                $stmt = $db->prepare("
                                    SELECT id, name, email, phone, avatar, avatar_original
                                    FROM users
                                    WHERE user_type = 'customer'
                                    ORDER BY name ASC
                                    LIMIT 100
                                ");
                                $stmt->execute();
                                $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($customers as $customer) {
                                    echo '<tr class="customer-row" data-customer-id="' . $customer['id'] . '" style="border-bottom: 1px solid var(--border-light);">';
                                    echo '<td style="padding: 10px;">' . $customer['id'] . '</td>';
                                    echo '<td style="padding: 10px;">' . safe_echo($customer['name']) . '</td>';
                                    echo '<td style="padding: 10px;">' . safe_echo($customer['email']) . '</td>';
                                    echo '<td style="padding: 10px;">' . safe_echo($customer['phone']) . '</td>';
                                    echo '<td style="padding: 10px; text-align: center;"><button class="btn btn-sm button-primary select-customer-btn" data-id="' . $customer['id'] . '">Chọn</button></td>';
                                    echo '</tr>';
                                }
                            } catch (PDOException $e) {
                                error_log("Customer fetch error: " . $e->getMessage());
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn button-secondary" id="customer-modal-cancel">Đóng</button>
            </div>
        </div>
    </div>
    
    <!-- Checkout Modal -->
    <div class="modal-backdrop" id="checkout-modal">
        <div class="modal large">
            <div class="modal-header">
                <h2 class="modal-title">Thanh toán</h2>
                <button class="modal-close" id="checkout-modal-close">×</button>
            </div>
            <div class="modal-body">
                <div class="checkout-section">
                    <h3 class="checkout-section-title">Thông tin khách hàng</h3>
                    <div id="checkout-customer-info">
                        <?php if (isset($current_customer) && $current_customer): ?>
                            <div style="display: flex; align-items: center; gap: var(--space-3); margin-bottom: var(--space-4);">
                                <div class="customer-avatar-medium">
                                    <?php echo strtoupper(substr($current_customer['name'], 0, 1)); ?>
                                </div>
                                <div class="customer-info">
                                    <div class="customer-name"><?php echo safe_echo($current_customer['name']); ?></div>
                                    <div class="customer-email"><?php echo safe_echo($current_customer['email']); ?> | <?php echo safe_echo($current_customer['phone']); ?></div>
                                </div>
                                <button class="btn button-secondary" id="change-customer-btn">Thay đổi</button>
                            </div>
                            
                            <div class="form-group">
                                <label for="shipping-type" class="form-label">Phương thức giao hàng</label>
                                <select id="shipping-type" class="form-control">
                                    <option value="home_delivery">Giao hàng tận nơi</option>
                                    <option value="pickup_point">Nhận tại cửa hàng</option>
                                </select>
                            </div>
                            
                            <div id="address-section" style="margin-top: 15px; display: none;">
                                <?php 
                                $addresses = isset($current_customer['addresses']) ? $current_customer['addresses'] : [];
                                if (!empty($addresses)): 
                                ?>
                                    <h4 style="margin-bottom: var(--space-3);">Chọn địa chỉ giao hàng</h4>
                                    <div class="address-list" id="address-list">
                                        <?php foreach ($addresses as $address): ?>
                                            <div class="address-card <?php echo ($address['set_default'] == 1) ? 'selected' : ''; ?>" 
                                                data-address-id="<?php echo (int)$address['id']; ?>">
                                                <div class="address-type">
                                                    <?php echo $address['set_default'] ? 'Mặc định' : 'Địa chỉ'; ?>
                                                </div>
                                                <div class="address-text">
                                                    <?php 
                                                        $addressParts = [];
                                                        if (!empty($address['address'])) $addressParts[] = $address['address'];
                                                        if (!empty($address['city_name'])) $addressParts[] = $address['city_name'];
                                                        if (!empty($address['state_name'])) $addressParts[] = $address['state_name'];
                                                        if (!empty($address['country_name'])) $addressParts[] = $address['country_name'];
                                                        if (!empty($address['postal_code'])) $addressParts[] = $address['postal_code'];
                                                        echo safe_echo(implode(', ', $addressParts));
                                                    ?>
                                                </div>
                                                <?php if (!empty($address['phone'])): ?>
                                                <div class="address-phone">
                                                    SĐT: <?php echo safe_echo($address['phone']); ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning">
                                        Khách hàng chưa có địa chỉ giao hàng nào. Vui lòng nhập thông tin giao hàng bên dưới.
                                    </div>
                                    <div class="form-group">
                                        <label for="manual-address" class="form-label">Địa chỉ giao hàng</label>
                                        <textarea id="manual-address" class="form-control" rows="3" placeholder="Nhập địa chỉ giao hàng..."></textarea>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group">
                                <label for="additional-info" class="form-label">Ghi chú đơn hàng</label>
                                <textarea id="additional-info" class="form-control" rows="3" placeholder="Thông tin thêm về đơn hàng..."></textarea>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; margin-bottom: var(--space-4);">
                                <p>Chưa có khách hàng nào được chọn</p>
                                <button class="btn button-primary" id="select-customer-btn" style="margin-top: var(--space-3);">Chọn khách hàng</button>
                            </div>
                            
                            <div class="form-group">
                                <label for="shipping-type" class="form-label">Phương thức giao hàng</label>
                                <select id="shipping-type" class="form-control">
                                    <option value="home_delivery">Giao hàng tận nơi</option>
                                    <option value="pickup_point">Nhận tại cửa hàng</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="additional-info" class="form-label">Ghi chú đơn hàng</label>
                                <textarea id="additional-info" class="form-control" rows="3" placeholder="Thông tin thêm về đơn hàng..."></textarea>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="checkout-section">
                    <h3 class="checkout-section-title">Phương thức thanh toán</h3>
                    <div class="payment-methods" id="payment-methods">
                        <div class="payment-method-option selected" data-payment="cash">
                            <input type="radio" name="payment-method" id="payment-cash" class="payment-method-radio" checked>
                            <div class="payment-method-label">
                                <div class="payment-method-name">Tiền mặt</div>
                                <div class="payment-method-description">Thanh toán bằng tiền mặt khi nhận hàng</div>
                            </div>
                            <div class="payment-method-icon">💵</div>
                        </div>
                        
                        <div class="payment-method-option" data-payment="card">
                            <input type="radio" name="payment-method" id="payment-card" class="payment-method-radio">
                            <div class="payment-method-label">
                                <div class="payment-method-name">Thẻ tín dụng/ghi nợ</div>
                                <div class="payment-method-description">Thanh toán bằng thẻ tín dụng hoặc thẻ ghi nợ</div>
                            </div>
                            <div class="payment-method-icon">💳</div>
                        </div>
                        
                        <div class="payment-method-option" data-payment="bank_transfer">
                            <input type="radio" name="payment-method" id="payment-bank" class="payment-method-radio">
                            <div class="payment-method-label">
                                <div class="payment-method-name">Chuyển khoản ngân hàng</div>
                                <div class="payment-method-description">Thanh toán bằng chuyển khoản ngân hàng</div>
                            </div>
                            <div class="payment-method-icon">🏦</div>
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-top: var(--space-4);">
                        <label for="payment-status" class="form-label">Trạng thái thanh toán</label>
                        <select id="payment-status" class="form-control">
                            <option value="paid">Đã thanh toán</option>
                            <option value="unpaid">Chưa thanh toán</option>
                        </select>
                    </div>
                </div>
                
                <div class="checkout-section">
                    <h3 class="checkout-section-title">Tổng quan đơn hàng</h3>
                    <div class="checkout-summary">
                        <div class="checkout-summary-row">
                            <span>Tạm tính</span>
                            <span id="checkout-subtotal"><?php echo formatCurrency($cart_total); ?></span>
                        </div>
                        
                        <?php if ($cart_tax > 0): ?>
                            <div class="checkout-summary-row">
                                <span>Thuế</span>
                                <span id="checkout-tax"><?php echo formatCurrency($cart_tax); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($cart_shipping > 0): ?>
                            <div class="checkout-summary-row">
                                <span>Phí vận chuyển</span>
                                <span id="checkout-shipping"><?php echo formatCurrency($cart_shipping); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($cart_discount > 0): ?>
                            <div class="checkout-summary-row">
                                <span>Giảm giá</span>
                                <span id="checkout-discount">-<?php echo formatCurrency($cart_discount); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <div class="checkout-summary-row total">
                            <span>Tổng thanh toán</span>
                            <span id="checkout-total"><?php echo formatCurrency($cart_grand_total); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn button-secondary" id="checkout-modal-cancel">Hủy</button>
                <button class="btn button-primary" id="place-order-btn">Hoàn tất đơn hàng</button>
            </div>
        </div>
    </div>
    
    <!-- Barcode Scanner Modal -->
    <div class="modal-backdrop" id="barcode-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Quét mã vạch</h2>
                <button class="modal-close" id="barcode-modal-close">×</button>
            </div>
            <div class="modal-body">
                <div class="barcode-scanner-container">
                    <video id="barcode-scanner-video" width="100%" height="100%"></video>
                    <div class="barcode-scanner-overlay">
                        <div class="barcode-scanner-line"></div>
                        <div class="barcode-scanner-text">Đưa mã vạch vào khung hình</div>
                    </div>
                </div>
                
                <div class="barcode-manual-input">
                    <div class="form-group">
                        <label for="barcode-input" class="form-label">Hoặc nhập mã vạch thủ công</label>
                        <div style="display: flex; gap: var(--space-2);">
                            <input type="text" id="barcode-input" class="form-control" placeholder="Nhập mã vạch...">
                            <button class="btn button-primary" id="barcode-input-btn">Tìm kiếm</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn button-secondary" id="barcode-modal-cancel">Hủy</button>
            </div>
        </div>
    </div>
    
    <!-- Product Detail Modal -->
    <div class="modal-backdrop" id="product-modal">
        <div class="modal large">
            <div class="modal-header">
                <h2 class="modal-title">Chi tiết sản phẩm</h2>
                <button class="modal-close" id="product-modal-close">×</button>
            </div>
            <div class="modal-body">
                <div id="product-detail-content">
                    <!-- Product details will be loaded via JavaScript -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn button-secondary" id="product-modal-cancel">Hủy</button>
                <button class="btn button-primary" id="add-to-cart-btn">Thêm vào giỏ hàng</button>
            </div>
        </div>
    </div>
    
    <!-- Order Success Modal -->
    <div class="modal-backdrop" id="success-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Đơn hàng thành công</h2>
                <button class="modal-close" id="success-modal-close">×</button>
            </div>
            <div class="modal-body">
                <div class="order-success-container">
                    <div class="success-icon">✅</div>
                    <h2 class="success-title">Đơn hàng đã được tạo thành công!</h2>
                    <p class="success-message">Đơn hàng của bạn đã được xử lý và đang chờ xác nhận.</p>
                    
                    <div class="order-info" id="success-order-info">
                        <!-- Order info will be loaded via JavaScript -->
                    </div>
                    
                    <div class="success-actions">
                        <button class="btn button-secondary" id="print-receipt-btn">
                            <span>🖨️</span>
                            <span>In hóa đơn</span>
                        </button>
                        <button class="btn button-primary" id="new-order-btn">
                            <span>🛒</span>
                            <span>Tạo đơn mới</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
<!-- 2. Add the address selection modal -->
<!-- Add this right before the closing </body> tag -->
<div class="modal-backdrop" id="address-modal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title">Địa chỉ giao hàng</h2>
            <button class="modal-close" id="address-modal-close">×</button>
        </div>
        <div class="modal-body">
            <div id="address-list-container">
                <!-- Existing addresses will be loaded here -->
                <div class="address-loading">Đang tải địa chỉ...</div>
            </div>
            <div class="add-address-btn-container">
                <button id="add-new-address-btn" class="btn">
                    <span class="add-icon">+</span>
                    <span>Thêm địa chỉ mới</span>
                </button>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn button-secondary" id="address-modal-cancel">Đóng</button>
            <button class="btn button-primary" id="address-modal-confirm">Xác nhận</button>
        </div>
    </div>
</div>

<!-- 3. Add the new address form modal -->
<div class="modal-backdrop" id="new-address-modal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title">Thêm địa chỉ mới</h2>
            <button class="modal-close" id="new-address-modal-close">×</button>
        </div>
        <div class="modal-body">
            <form id="new-address-form">
                <div class="form-group">
                    <label for="address" class="form-label">Địa chỉ</label>
                    <input type="text" id="address" name="address" class="form-control" required placeholder="Ví dụ: 123 Đường Lê Lợi">
                </div>
                <div class="form-group">
                    <label for="postal_code" class="form-label">Mã bưu điện</label>
                    <input type="text" id="postal_code" name="postal_code" class="form-control" placeholder="Ví dụ: 70000">
                </div>
                <div class="form-row">
                    <div class="form-group col-half">
                        <label for="country_id" class="form-label">Quốc gia</label>
                        <select id="country_id" name="country_id" class="form-control" required>
                            <option value="">Chọn quốc gia</option>
                            <!-- Countries will be loaded dynamically -->
                        </select>
                    </div>
                    <div class="form-group col-half">
                        <label for="state_id" class="form-label">Tỉnh/Thành phố</label>
                        <select id="state_id" name="state_id" class="form-control" required>
                            <option value="">Chọn tỉnh/thành phố</option>
                            <!-- States will be loaded dynamically -->
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-half">
                        <label for="city_id" class="form-label">Quận/Huyện</label>
                        <select id="city_id" name="city_id" class="form-control">
                            <option value="">Chọn quận/huyện</option>
                            <!-- Cities will be loaded dynamically -->
                        </select>
                    </div>
                    <div class="form-group col-half">
                        <label for="phone" class="form-label">Số điện thoại</label>
                        <input type="text" id="phone" name="phone" class="form-control" placeholder="Số điện thoại liên hệ">
                    </div>
                </div>
                <div class="form-group">
                    <div class="checkbox">
                        <input type="checkbox" id="set_default" name="set_default" value="1">
                        <label for="set_default">Đặt làm địa chỉ mặc định</label>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn button-secondary" id="new-address-modal-cancel">Hủy</button>
            <button class="btn button-primary" id="save-address-btn">Lưu địa chỉ</button>
        </div>
    </div>
</div>
<script>
// ===== Address related functions =====
let customerAddresses = [];
let selectedAddressForDelivery = null;

// Address button click event
document.addEventListener('DOMContentLoaded', function() {
    const addressBtn = document.getElementById('address-btn');
    if (addressBtn) {
        addressBtn.addEventListener('click', function() {
            openAddressModal();
        });
    }
    
    // Modal close events
    document.getElementById('address-modal-close').addEventListener('click', () => closeModal(document.getElementById('address-modal')));
    document.getElementById('address-modal-cancel').addEventListener('click', () => closeModal(document.getElementById('address-modal')));
    document.getElementById('address-modal-confirm').addEventListener('click', confirmAddressSelection);
    
    document.getElementById('new-address-modal-close').addEventListener('click', () => closeModal(document.getElementById('new-address-modal')));
    document.getElementById('new-address-modal-cancel').addEventListener('click', () => closeModal(document.getElementById('new-address-modal')));
    
    // Add new address button
    document.getElementById('add-new-address-btn').addEventListener('click', openNewAddressModal);
    document.getElementById('save-address-btn').addEventListener('click', saveNewAddress);
    
    // Load location data for the form
    loadCountries();
    
    // Add event listeners for location dropdowns
    document.getElementById('country_id').addEventListener('change', function() {
        loadStates(this.value);
    });
    
    document.getElementById('state_id').addEventListener('change', function() {
        loadCities(this.value);
    });
});

// Open address selection modal
async function openAddressModal() {
    const addressModal = document.getElementById('address-modal');
    openModal(addressModal);
    
    // Load customer addresses
    await loadCustomerAddresses();
    
    // Display addresses in the modal
    renderAddressList();
}

// Load customer addresses
async function loadCustomerAddresses() {
    try {
        const customer = <?php echo isset($_SESSION['pos_customer']) ? json_encode($_SESSION['pos_customer']) : 'null'; ?>;
        
        if (!customer || !customer.id) {
            document.getElementById('address-list-container').innerHTML = `
                <div class="alert alert-info">
                    Vui lòng chọn khách hàng trước khi thêm địa chỉ giao hàng.
                </div>
            `;
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'get_customer_addresses');
        formData.append('token', csrfToken);
        formData.append('customer_id', customer.id);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            customerAddresses = result.addresses || [];
            
            // Set selected address if there's a default or take the first one
            if (!selectedAddressForDelivery && customerAddresses.length > 0) {
                const defaultAddress = customerAddresses.find(addr => addr.set_default == 1);
                selectedAddressForDelivery = defaultAddress ? defaultAddress.id : customerAddresses[0].id;
            }
        } else {
            throw new Error(result.message || 'Failed to load addresses');
        }
    } catch (error) {
        console.error('Error loading addresses:', error);
        document.getElementById('address-list-container').innerHTML = `
            <div class="alert alert-error">
                Lỗi khi tải địa chỉ: ${error.message}
            </div>
        `;
    }
}

// Render the address list
function renderAddressList() {
    const container = document.getElementById('address-list-container');
    
    if (!customerAddresses || customerAddresses.length === 0) {
        container.innerHTML = `
            <div class="alert alert-info">
                Khách hàng chưa có địa chỉ nào. Vui lòng thêm địa chỉ mới.
            </div>
        `;
        return;
    }
    
    let addressesHtml = '';
    
    customerAddresses.forEach(address => {
        const isSelected = selectedAddressForDelivery == address.id;
        const isDefault = address.set_default == 1;
        
        // Build address text
        const addressParts = [];
        if (address.address) addressParts.push(address.address);
        if (address.city_name) addressParts.push(address.city_name);
        if (address.state_name) addressParts.push(address.state_name);
        if (address.country_name) addressParts.push(address.country_name);
        
        addressesHtml += `
            <div class="address-item ${isSelected ? 'selected' : ''}" data-address-id="${address.id}">
                <div class="address-content">
                    <input type="radio" name="delivery_address" class="address-radio" ${isSelected ? 'checked' : ''}>
                    <div class="address-details">
                        <div class="address-line">${addressParts.join(', ')}</div>
                        <div class="address-line">Mã bưu điện: ${address.postal_code || 'N/A'}</div>
                        ${address.phone ? `<div class="address-line">SĐT: ${address.phone}</div>` : ''}
                        <div class="address-meta">
                            ${isDefault ? '<span class="address-default-badge">Mặc định</span>' : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = addressesHtml;
    
    // Add click event for address selection
    document.querySelectorAll('.address-item').forEach(item => {
        item.addEventListener('click', function() {
            const addressId = this.dataset.addressId;
            document.querySelectorAll('.address-item').forEach(el => {
                el.classList.remove('selected');
                el.querySelector('.address-radio').checked = false;
            });
            this.classList.add('selected');
            this.querySelector('.address-radio').checked = true;
            selectedAddressForDelivery = addressId;
        });
    });
}

// Confirm address selection
function confirmAddressSelection() {
    if (selectedAddressForDelivery) {
        // Update the selected address ID for checkout
        selectedAddressId = selectedAddressForDelivery;
        
        // Find the address details
        const selectedAddress = customerAddresses.find(addr => addr.id == selectedAddressForDelivery);
        
        if (selectedAddress) {
            // Update the displayed address in the checkout modal if it's open
            updateSelectedAddressDisplay(selectedAddress);
            
            showNotification('Đã chọn địa chỉ giao hàng', 'success');
        }
    }
    
    closeModal(document.getElementById('address-modal'));
}

// Update the displayed address in the checkout modal
function updateSelectedAddressDisplay(address) {
    // This will update the address display in the checkout modal if it's open
    const addressCards = document.querySelectorAll('.address-card');
    if (addressCards.length > 0) {
        addressCards.forEach(card => {
            if (card.dataset.addressId == address.id) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        });
    }
}

// Open new address modal
function openNewAddressModal() {
    closeModal(document.getElementById('address-modal'));
    openModal(document.getElementById('new-address-modal'));
}

// Save new address
async function saveNewAddress() {
    try {
        const saveBtn = document.getElementById('save-address-btn');
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="loading-spinner"></span> Đang lưu...';
        
        // Get customer ID
        const customer = <?php echo isset($_SESSION['pos_customer']) ? json_encode($_SESSION['pos_customer']) : 'null'; ?>;
        
        if (!customer || !customer.id) {
            throw new Error('Vui lòng chọn khách hàng trước khi thêm địa chỉ');
        }
        
        // Get form data
        const form = document.getElementById('new-address-form');
        const formData = new FormData();
        
        formData.append('action', 'add_customer_address');
        formData.append('token', csrfToken);
        formData.append('user_id', customer.id);
        formData.append('address', form.address.value);
        formData.append('country_id', form.country_id.value);
        formData.append('state_id', form.state_id.value);
        formData.append('city_id', form.city_id.value || 0);
        formData.append('postal_code', form.postal_code.value || '');
        formData.append('phone', form.phone.value || '');
        formData.append('set_default', form.set_default.checked ? 1 : 0);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Failed to save address');
        }
        
        // Reset form
        form.reset();
        
        // Close new address modal
        closeModal(document.getElementById('new-address-modal'));
        
        // Reload and open address modal
        showNotification('Địa chỉ đã được lưu thành công', 'success');
        setTimeout(() => {
            openAddressModal();
        }, 500);
        
    } catch (error) {
        console.error('Error saving address:', error);
        showNotification('Lỗi khi lưu địa chỉ: ' + error.message, 'error');
    } finally {
        const saveBtn = document.getElementById('save-address-btn');
        saveBtn.disabled = false;
        saveBtn.innerHTML = 'Lưu địa chỉ';
    }
}

// Load countries for the form
async function loadCountries() {
    try {
        const select = document.getElementById('country_id');
        
        // Clear current options except the first one
        while (select.options.length > 1) {
            select.remove(1);
        }
        
        // Add loading option
        const loadingOption = document.createElement('option');
        loadingOption.text = 'Đang tải...';
        loadingOption.disabled = true;
        select.add(loadingOption);
        
        const formData = new FormData();
        formData.append('action', 'get_countries');
        formData.append('token', csrfToken);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Failed to load countries');
        }
        
        // Remove loading option
        select.remove(select.options.length - 1);
        
        // Add countries to select
        result.countries.forEach(country => {
            const option = document.createElement('option');
            option.value = country.id;
            option.text = country.name;
            select.add(option);
        });
        
    } catch (error) {
        console.error('Error loading countries:', error);
        const select = document.getElementById('country_id');
        while (select.options.length > 1) {
            select.remove(1);
        }
        const errorOption = document.createElement('option');
        errorOption.text = 'Lỗi khi tải dữ liệu';
        errorOption.disabled = true;
        select.add(errorOption);
    }
}

// Load states based on country
async function loadStates(countryId) {
    try {
        const select = document.getElementById('state_id');
        
        // Clear current options except the first one
        while (select.options.length > 1) {
            select.remove(1);
        }
        
        if (!countryId) return;
        
        // Add loading option
        const loadingOption = document.createElement('option');
        loadingOption.text = 'Đang tải...';
        loadingOption.disabled = true;
        select.add(loadingOption);
        
        const formData = new FormData();
        formData.append('action', 'get_states');
        formData.append('token', csrfToken);
        formData.append('country_id', countryId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Failed to load states');
        }
        
        // Remove loading option
        select.remove(select.options.length - 1);
        
        // Add states to select
        result.states.forEach(state => {
            const option = document.createElement('option');
            option.value = state.id;
            option.text = state.name;
            select.add(option);
        });
        
    } catch (error) {
        console.error('Error loading states:', error);
        const select = document.getElementById('state_id');
        while (select.options.length > 1) {
            select.remove(1);
        }
        const errorOption = document.createElement('option');
        errorOption.text = 'Lỗi khi tải dữ liệu';
        errorOption.disabled = true;
        select.add(errorOption);
    }
}

// Load cities based on state
async function loadCities(stateId) {
    try {
        const select = document.getElementById('city_id');
        
        // Clear current options except the first one
        while (select.options.length > 1) {
            select.remove(1);
        }
        
        if (!stateId) return;
        
        // Add loading option
        const loadingOption = document.createElement('option');
        loadingOption.text = 'Đang tải...';
        loadingOption.disabled = true;
        select.add(loadingOption);
        
        const formData = new FormData();
        formData.append('action', 'get_cities');
        formData.append('token', csrfToken);
        formData.append('state_id', stateId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Failed to load cities');
        }
        
        // Remove loading option
        select.remove(select.options.length - 1);
        
        // Add cities to select
        result.cities.forEach(city => {
            const option = document.createElement('option');
            option.value = city.id;
            option.text = city.name;
            select.add(option);
        });
        
    } catch (error) {
        console.error('Error loading cities:', error);
        const select = document.getElementById('city_id');
        while (select.options.length > 1) {
            select.remove(1);
        }
        const errorOption = document.createElement('option');
        errorOption.text = 'Lỗi khi tải dữ liệu';
        errorOption.disabled = true;
        select.add(errorOption);
    }
}
</script>
    <script>
        // DOM Elements
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const productsGrid = document.getElementById('products-grid');
        const productSearch = document.getElementById('product-search');
        const categoriesContainer = document.getElementById('categories-container');
        const cartArea = document.getElementById('cart-area');
        const cartItems = document.getElementById('cart-items');
        const cartEmpty = document.getElementById('cart-empty');
        const clearCartBtn = document.getElementById('clear-cart-btn');
        const checkoutBtn = document.getElementById('checkout-btn');
        const holdOrderBtn = document.getElementById('hold-order-btn');
        const mobileCartToggle = document.getElementById('mobile-cart-toggle');
        const mobileCartCount = document.getElementById('mobile-cart-count');
        
        // Customer Modal Elements
        const customerSelect = document.getElementById('customer-select');
        const customerModal = document.getElementById('customer-modal');
        const customerFilter = document.getElementById('customer-filter');
        const customerList = document.getElementById('customer-list');
        const customerModalClose = document.getElementById('customer-modal-close');
        const customerModalCancel = document.getElementById('customer-modal-cancel');
        
        // Checkout Modal Elements
        const checkoutModal = document.getElementById('checkout-modal');
        const checkoutModalClose = document.getElementById('checkout-modal-close');
        const checkoutModalCancel = document.getElementById('checkout-modal-cancel');
        const placeOrderBtn = document.getElementById('place-order-btn');
        const paymentMethods = document.querySelectorAll('.payment-method-option');
        const paymentRadios = document.querySelectorAll('.payment-method-radio');
        const shippingTypeSelect = document.getElementById('shipping-type');
        const addressSection = document.getElementById('address-section');
        
        // Barcode Scanner Modal Elements
        const barcodeModal = document.getElementById('barcode-modal');
        const barcodeScanBtn = document.getElementById('barcode-scan-btn');
        const barcodeModalClose = document.getElementById('barcode-modal-close');
        const barcodeModalCancel = document.getElementById('barcode-modal-cancel');
        const barcodeScannerVideo = document.getElementById('barcode-scanner-video');
        const barcodeInput = document.getElementById('barcode-input');
        const barcodeInputBtn = document.getElementById('barcode-input-btn');
        
        // Product Detail Modal Elements
        const productModal = document.getElementById('product-modal');
        const productDetailContent = document.getElementById('product-detail-content');
        const productModalClose = document.getElementById('product-modal-close');
        const productModalCancel = document.getElementById('product-modal-cancel');
        const addToCartBtn = document.getElementById('add-to-cart-btn');
        
        // Success Modal Elements
        const successModal = document.getElementById('success-modal');
        const successOrderInfo = document.getElementById('success-order-info');
        const successModalClose = document.getElementById('success-modal-close');
        const printReceiptBtn = document.getElementById('print-receipt-btn');
        const newOrderBtn = document.getElementById('new-order-btn');
        
        // Global Variables
        let currentProductId = null;
        let currentVariationId = null;
        let currentQuantity = 1;
        let currentPage = 1;
        let totalPages = 1;
        let currentCategoryId = 0;
        let currentSearchTerm = '';
        let selectedAddressId = 0;
        let selectedPaymentMethod = 'cash';
        let selectedShopId = 0; // Add selected shop ID variable
        
        // CSRF Token
        const csrfToken = '<?php echo $_SESSION['admin_token']; ?>';
        
        // Add event listener for beforeunload to warn users if they're navigating away 
        // while there are items in the cart (except after successful order)
        window.addEventListener('beforeunload', function(e) {
            // Get the cart from session storage
            const cartItems = document.querySelectorAll('.cart-item');
            
            // If there are items in cart and order wasn't successful, show warning
            if (cartItems.length > 0 && !window.orderSuccessful) {
                // Cancel the event
                e.preventDefault();
                // Chrome requires returnValue to be set
                e.returnValue = 'Bạn có đơn hàng chưa hoàn thành. Bạn có chắc chắn muốn rời khỏi trang?';
                return e.returnValue;
            }
        });
        
        // Initialize order success flag
        window.orderSuccessful = false;
        
        // Sidebar toggle functionality
        sidebarToggle.addEventListener('click', function() {
            if (window.innerWidth > 1024) {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            } else {
                sidebar.classList.toggle('open');
            }
        });
        
        // Restore sidebar state
        if (window.innerWidth > 1024 && localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
        }
        
        // Mobile cart toggle
        function updateMobileCartToggle() {
            if (window.innerWidth <= 1200) {
                mobileCartToggle.style.display = 'flex';
            } else {
                mobileCartToggle.style.display = 'none';
                cartArea.classList.remove('open');
            }
        }
        
        mobileCartToggle.addEventListener('click', function() {
            cartArea.classList.toggle('open');
        });
        
        window.addEventListener('resize', updateMobileCartToggle);
        updateMobileCartToggle();
        
        // Close sidebar on mobile when clicking outside
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 1024 && 
                !sidebar.contains(e.target) && 
                !sidebarToggle.contains(e.target) &&
                sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
            
            if (window.innerWidth <= 1200 &&
                !cartArea.contains(e.target) &&
                !mobileCartToggle.contains(e.target) &&
                cartArea.classList.contains('open')) {
                cartArea.classList.remove('open');
            }
        });
        
        // Handle Shipping Type changes to show/hide address section
        if (shippingTypeSelect) {
            shippingTypeSelect.addEventListener('change', function() {
                if (this.value === 'home_delivery') {
                    // Show address section for home delivery
                    if (addressSection) {
                        addressSection.style.display = 'block';
                    }
                } else {
                    // Hide address section for pickup
                    if (addressSection) {
                        addressSection.style.display = 'none';
                    }
                    // Reset selected address
                    selectedAddressId = 0;
                }
            });
        }
        
        // Category Selection
        categoriesContainer.addEventListener('click', function(e) {
            const categoryCard = e.target.closest('.category-card');
            if (!categoryCard) return;
            
            const categoryId = categoryCard.dataset.categoryId;
            if (categoryId == currentCategoryId) return;
            
            // Update active class
            document.querySelectorAll('.category-card').forEach(card => {
                card.classList.remove('active');
            });
            categoryCard.classList.add('active');
            
            // Update current category
            currentCategoryId = categoryId;
            currentPage = 1;
            
            // Load products
            loadProducts();
        });
        
        // Product Search
        let searchTimeout;
        productSearch.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                currentSearchTerm = productSearch.value.trim();
                currentPage = 1;
                loadProducts();
            }, 500);
        });
        
        // Load Products Function
        async function loadProducts() {
            try {
                // Show loading state
                productsGrid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: var(--space-8);"><div style="font-size: 24px; margin-bottom: var(--space-4);">⌛</div><p>Đang tải sản phẩm...</p></div>';
                
                const formData = new FormData();
                formData.append('action', 'search_products');
                formData.append('token', csrfToken);
                formData.append('search', currentSearchTerm);
                formData.append('category_id', currentCategoryId);
                formData.append('page', currentPage);
                formData.append('shop_id', selectedShopId); // Add shop ID to query
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                // Check if response is OK
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'Failed to load products');
                }
                
                // Update pagination info
                totalPages = result.pagination.total_pages;
                
                // Update products grid
                if (result.products.length === 0) {
                    productsGrid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: var(--space-8);"><div style="font-size: 48px; margin-bottom: var(--space-4);">🔍</div><p>Không tìm thấy sản phẩm nào phù hợp</p></div>';
                    return;
                }
                
                let productsHTML = '';
                result.products.forEach(product => {
                    const stockBadge = product.current_stock <= 0 
                        ? '<span class="product-badge out-of-stock">Hết hàng</span>'
                        : (product.current_stock <= 5 
                            ? '<span class="product-badge low-stock">Sắp hết</span>' 
                            : (product.discount > 0 
                                ? '<span class="product-badge sale">Sale</span>' 
                                : ''));
                    
                    const discountPrice = product.display_price < product.unit_price 
                        ? `<span class="product-original-price">${product.formatted_original_price}</span>` 
                        : '';
                    
                    productsHTML += `
                        <div class="product-card" data-product-id="${product.id}">
                            <img src="${product.product_image ? '../' + product.product_image : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="180" height="140" viewBox="0 0 180 140"><rect width="180" height="140" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="24" fill="%236b7280">No Image</text></svg>'}" alt="${product.display_name}" class="product-image">
                            <div class="product-details">
                                <div class="product-name">${product.display_name}</div>
                                <div class="product-price">
                                    ${product.formatted_price}
                                    ${discountPrice}
                                </div>
                            </div>
                            ${stockBadge}
                        </div>
                    `;
                });
                
                productsGrid.innerHTML = productsHTML;
                
                // Add event listeners to product cards
                document.querySelectorAll('.product-card').forEach(card => {
                    card.addEventListener('click', function() {
                        const productId = this.dataset.productId;
                        openProductDetail(productId);
                    });
                });
                
            } catch (error) {
                console.error('Error loading products:', error);
                showNotification('Lỗi khi tải sản phẩm: ' + error.message, 'error');
                productsGrid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: var(--space-8);"><div style="font-size: 48px; margin-bottom: var(--space-4);">❌</div><p>Đã xảy ra lỗi khi tải sản phẩm</p></div>';
            }
        }
        
        // Open Product Detail
        async function openProductDetail(productId) {
            try {
                // Show loading state
                productDetailContent.innerHTML = '<div style="text-align: center; padding: var(--space-8);"><div style="font-size: 24px; margin-bottom: var(--space-4);">⌛</div><p>Đang tải thông tin sản phẩm...</p></div>';
                
                // Show modal
                openModal(productModal);
                
                // Set current product ID
                currentProductId = productId;
                currentVariationId = null;
                currentQuantity = 1;
                
                // Get product details
                const formData = new FormData();
                formData.append('action', 'get_product_variations');
                formData.append('token', csrfToken);
                formData.append('product_id', productId);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'Failed to load product details');
                }
                
                // Find product in the grid
                const productCard = document.querySelector(`.product-card[data-product-id="${productId}"]`);
                if (!productCard) {
                    throw new Error('Product not found');
                }
                
                const productName = productCard.querySelector('.product-name').textContent;
                const productPrice = productCard.querySelector('.product-price').textContent;
                const productImage = productCard.querySelector('.product-image').src;
                const inStock = !productCard.querySelector('.product-badge.out-of-stock');
                
                // Render product details
                let stockStatus = '';
                if (inStock) {
                    if (productCard.querySelector('.product-badge.low-stock')) {
                        stockStatus = '<div class="product-detail-stock low-stock">Sắp hết hàng</div>';
                    } else {
                        stockStatus = '<div class="product-detail-stock in-stock">Còn hàng</div>';
                    }
                } else {
                    stockStatus = '<div class="product-detail-stock out-of-stock">Hết hàng</div>';
                }
                
                let variationsHTML = '';
                if (result.variations && result.variations.length > 0) {
                    variationsHTML = `
                        <div class="product-variations">
                            <div class="variation-section">
                                <div class="variation-title">Biến thể</div>
                                <div class="variation-options">
                                    ${result.variations.map(variation => `
                                        <div class="variation-option ${variation.qty <= 0 ? 'disabled' : ''}" data-variation-id="${variation.id}" data-qty="${variation.qty}">
                                            ${variation.variant}
                                            ${variation.qty <= 0 ? ' (Hết hàng)' : ''}
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                const productDetailHTML = `
                    <div class="product-detail-container">
                        <img src="${productImage}" alt="${productName}" class="product-detail-image">
                        <div class="product-detail-info">
                            <h3 class="product-detail-name">${productName}</h3>
                            <div class="product-detail-price">${productPrice}</div>
                            ${stockStatus}
                            ${variationsHTML}
                            <div class="product-quantity">
                                <div class="quantity-label">Số lượng</div>
                                <div class="quantity-control">
                                    <button class="quantity-btn-lg" id="product-quantity-decrease">-</button>
                                    <input type="number" id="product-quantity-input" class="quantity-input-lg" value="1" min="1" max="100">
                                    <button class="quantity-btn-lg" id="product-quantity-increase">+</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                productDetailContent.innerHTML = productDetailHTML;
                
                // Disable add to cart button if out of stock
                addToCartBtn.disabled = !inStock;
                
                // Add event listeners to variation options
                document.querySelectorAll('.variation-option').forEach(option => {
                    if (!option.classList.contains('disabled')) {
                        option.addEventListener('click', function() {
                            document.querySelectorAll('.variation-option').forEach(opt => {
                                opt.classList.remove('selected');
                            });
                            this.classList.add('selected');
                            currentVariationId = this.dataset.variationId;
                        });
                    }
                });
                
            // Add event listeners to quantity controls
                const quantityInput = document.getElementById('product-quantity-input');
                const decreaseBtn = document.getElementById('product-quantity-decrease');
                const increaseBtn = document.getElementById('product-quantity-increase');
                
                if (quantityInput) {
                    quantityInput.addEventListener('change', function() {
                        let value = parseInt(this.value);
                        if (isNaN(value) || value < 1) value = 1;
                        if (value > 100) value = 100;
                        this.value = value;
                        currentQuantity = value;
                    });
                }
                
                if (decreaseBtn && quantityInput) {
                    decreaseBtn.addEventListener('click', function() {
                        let value = parseInt(quantityInput.value);
                        if (value > 1) {
                            value--;
                            quantityInput.value = value;
                            currentQuantity = value;
                        }
                    });
                }
                
                if (increaseBtn && quantityInput) {
                    increaseBtn.addEventListener('click', function() {
                        let value = parseInt(quantityInput.value);
                        if (value < 100) {
                            value++;
                            quantityInput.value = value;
                            currentQuantity = value;
                        }
                    });
                }
                
            } catch (error) {
                console.error('Error opening product detail:', error);
                showNotification('Lỗi khi tải thông tin sản phẩm: ' + error.message, 'error');
                productDetailContent.innerHTML = '<div style="text-align: center; padding: var(--space-8);"><div style="font-size: 48px; margin-bottom: var(--space-4);">❌</div><p>Đã xảy ra lỗi khi tải thông tin sản phẩm</p></div>';
            }
        }
        
        // Add to Cart
        addToCartBtn.addEventListener('click', async function() {
            if (!currentProductId) {
                showNotification('Vui lòng chọn sản phẩm', 'error');
                return;
            }
            
            try {
                // Show loading state
                this.disabled = true;
                this.innerHTML = '<span class="loading-spinner"></span> Đang xử lý...';
                
                const formData = new FormData();
                formData.append('action', 'add_to_cart');
                formData.append('token', csrfToken);
                formData.append('product_id', currentProductId);
                formData.append('quantity', currentQuantity);
                if (currentVariationId) {
                    formData.append('variation_id', currentVariationId);
                }
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'Failed to add product to cart');
                }
                
                // Close modal
                closeModal(productModal);
                
                // Update cart
                updateCart(result.cart);
                
                // Show notification
                showNotification('Đã thêm sản phẩm vào giỏ hàng', 'success');
                
            } catch (error) {
                console.error('Error adding to cart:', error);
                showNotification('Lỗi khi thêm sản phẩm vào giỏ hàng: ' + error.message, 'error');
            } finally {
                // Reset button state
                this.disabled = false;
                this.innerHTML = 'Thêm vào giỏ hàng';
            }
        });
        
        // Clear Cart
        clearCartBtn.addEventListener('click', async function() {
            try {
                const formData = new FormData();
                formData.append('action', 'clear_cart');
                formData.append('token', csrfToken);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'Failed to clear cart');
                }
                
                // Update cart
                updateCart(result.cart);
                
                // Show notification
                showNotification('Đã xóa toàn bộ giỏ hàng', 'success');
                
            } catch (error) {
                console.error('Error clearing cart:', error);
                showNotification('Lỗi khi xóa giỏ hàng: ' + error.message, 'error');
            }
        });
        
        // Update Cart Function
        function updateCart(cart) {
            // Calculate totals
            let subtotal = 0;
            let tax = 0;
            let shipping = 0;
            let discount = 0;
            let itemCount = 0;
            
            // Update cart items
            if (!cart || Object.keys(cart).length === 0) {
                cartItems.innerHTML = `
                    <div class="cart-empty" id="cart-empty">
                        <div class="cart-empty-icon">🛒</div>
                        <div class="cart-empty-text">Giỏ hàng trống</div>
                        <div class="cart-empty-subtext">Thêm sản phẩm bằng cách nhấp vào sản phẩm ở danh sách bên trái</div>
                    </div>
                `;
                checkoutBtn.disabled = true;
                holdOrderBtn.disabled = true;
            } else {
                let cartItemsHTML = '';
                
                for (const [key, item] of Object.entries(cart)) {
                    // Skip if item is not a valid object or missing required properties
                    if (!item || typeof item !== 'object') continue;
                    
                    // Use default values for missing properties
                    const price = item.price || 0;
                    const quantity = item.quantity || 0;
                    
                    const itemTotal = price * quantity;
                    subtotal += itemTotal;
                    itemCount += quantity;
                    
                    // Calculate tax safely
                    if (item.tax && parseFloat(item.tax) > 0) {
                        if (item.tax_type === 'percent') {
                            tax += itemTotal * (parseFloat(item.tax) / 100);
                        } else {
                            tax += parseFloat(item.tax) * quantity;
                        }
                    }
                    
                    // Add shipping cost safely
                    if (item.shipping_cost && parseFloat(item.shipping_cost) > 0) {
                        shipping += parseFloat(item.shipping_cost);
                    }
                    
                    // Generate HTML for cart item
                    const itemImage = item.image ? '../' + item.image : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 60 60"><rect width="60" height="60" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="12" fill="%236b7280">No Image</text></svg>';
                    const itemName = item.name || 'Sản phẩm không xác định';
                    const itemVariation = item.variation ? `<div class="cart-item-variant">${item.variation}</div>` : '';
                    
                    cartItemsHTML += `
                        <div class="cart-item" data-item-key="${key}">
                            <img src="${itemImage}" alt="${itemName}" class="cart-item-image">
                            <div class="cart-item-details">
                                <div class="cart-item-name">${itemName}</div>
                                ${itemVariation}
                                <div class="cart-item-price">${formatCurrency(price)}</div>
                                <div class="cart-item-actions">
                                    <div class="cart-item-quantity">
                                        <button class="quantity-btn quantity-decrease">-</button>
                                        <input type="number" class="quantity-input" value="${quantity}" min="1" max="100">
                                        <button class="quantity-btn quantity-increase">+</button>
                                    </div>
                                </div>
                            </div>
                            <button class="remove-item-btn">🗑️</button>
                        </div>
                    `;
                }
                
                cartItems.innerHTML = cartItemsHTML;
                checkoutBtn.disabled = false;
                holdOrderBtn.disabled = false;
                
                // Add event listeners to cart items
                document.querySelectorAll('.cart-item').forEach(item => {
                    const itemKey = item.dataset.itemKey;
                    const quantityInput = item.querySelector('.quantity-input');
                    const decreaseBtn = item.querySelector('.quantity-decrease');
                    const increaseBtn = item.querySelector('.quantity-increase');
                    const removeBtn = item.querySelector('.remove-item-btn');
                    
                    quantityInput.addEventListener('change', function() {
                        updateCartItemQuantity(itemKey, this.value);
                    });
                    
                    decreaseBtn.addEventListener('click', function() {
                        const currentValue = parseInt(quantityInput.value);
                        if (currentValue > 1) {
                            updateCartItemQuantity(itemKey, currentValue - 1);
                        }
                    });
                    
                    increaseBtn.addEventListener('click', function() {
                        const currentValue = parseInt(quantityInput.value);
                        updateCartItemQuantity(itemKey, currentValue + 1);
                    });
                    
                    removeBtn.addEventListener('click', function() {
                        removeCartItem(itemKey);
                    });
                });
            }
            
            // Update cart summary
            const grandTotal = subtotal + tax + shipping - discount;
            
            document.getElementById('cart-subtotal').textContent = formatCurrency(subtotal);
            
            if (document.getElementById('cart-tax')) {
                document.getElementById('cart-tax').textContent = formatCurrency(tax);
            }
            
            if (document.getElementById('cart-shipping')) {
                document.getElementById('cart-shipping').textContent = formatCurrency(shipping);
            }
            
            if (document.getElementById('cart-discount')) {
                document.getElementById('cart-discount').textContent = '-' + formatCurrency(discount);
            }
            
            document.getElementById('cart-total').textContent = formatCurrency(grandTotal);
            
            // Update checkout modal summary if open
            if (document.getElementById('checkout-subtotal')) {
                document.getElementById('checkout-subtotal').textContent = formatCurrency(subtotal);
            }
            
            if (document.getElementById('checkout-tax')) {
                document.getElementById('checkout-tax').textContent = formatCurrency(tax);
            }
            
            if (document.getElementById('checkout-shipping')) {
                document.getElementById('checkout-shipping').textContent = formatCurrency(shipping);
            }
            
            if (document.getElementById('checkout-discount')) {
                document.getElementById('checkout-discount').textContent = '-' + formatCurrency(discount);
            }
            
            if (document.getElementById('checkout-total')) {
                document.getElementById('checkout-total').textContent = formatCurrency(grandTotal);
            }
            
            // Update cart badge
            const cartBadge = document.querySelector('.cart-badge');
            cartBadge.textContent = itemCount;
            
            // Update mobile cart badge
            mobileCartCount.textContent = itemCount;
        }
        
        // Update Cart Item Quantity
        async function updateCartItemQuantity(itemKey, quantity) {
            try {
                const formData = new FormData();
                formData.append('action', 'update_cart_quantity');
                formData.append('token', csrfToken);
                formData.append('cart_item_key', itemKey);
                formData.append('quantity', quantity);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'Failed to update cart item quantity');
                }
                
                // Update cart
                updateCart(result.cart);
                
            } catch (error) {
                console.error('Error updating cart item quantity:', error);
                showNotification('Lỗi khi cập nhật số lượng: ' + error.message, 'error');
            }
        }
        
        // Remove Cart Item
        async function removeCartItem(itemKey) {
            try {
                const formData = new FormData();
                formData.append('action', 'remove_from_cart');
                formData.append('token', csrfToken);
                formData.append('cart_item_key', itemKey);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'Failed to remove cart item');
                }
                
                // Update cart
                updateCart(result.cart);
                
                // Show notification
                showNotification('Đã xóa sản phẩm khỏi giỏ hàng', 'success');
                
            } catch (error) {
                console.error('Error removing cart item:', error);
                showNotification('Lỗi khi xóa sản phẩm: ' + error.message, 'error');
            }
        }
        
        // Customer Selection
        customerSelect.addEventListener('click', function() {
            openModal(customerModal);
        });
        
        // Filter customers
        if (customerFilter) {
            customerFilter.addEventListener('input', function() {
                const filterValue = this.value.toLowerCase();
                const rows = document.querySelectorAll('.customer-row');
                
                rows.forEach(row => {
                    const name = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                    const email = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
                    const phone = row.querySelector('td:nth-child(4)').textContent.toLowerCase();
                    
                    if (name.includes(filterValue) || email.includes(filterValue) || phone.includes(filterValue)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }
        
        // Select customer from list
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('select-customer-btn')) {
                const customerId = e.target.dataset.id;
                selectCustomer(customerId);
            }
        });
        
        // Select customer on row click
        document.querySelectorAll('.customer-row').forEach(row => {
            row.addEventListener('click', function(e) {
                // Don't trigger if clicking on the button (already handled)
                if (!e.target.classList.contains('select-customer-btn')) {
                    const customerId = this.dataset.customerId;
                    selectCustomer(customerId);
                }
            });
        });
        
        // Select Customer
        async function selectCustomer(customerId) {
            try {
                const formData = new FormData();
                formData.append('action', 'select_customer');
                formData.append('token', csrfToken);
                formData.append('customer_id', customerId);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'Failed to select customer');
                }
                
                // Update customer select
                const customer = result.customer;
                if (customer) {
                    customerSelect.querySelector('.customer-select-value').innerHTML = `
                        <div class="customer-avatar-small">
                            ${customer.name ? customer.name.charAt(0).toUpperCase() : 'U'}
                        </div>
                        <span>${customer.name || 'Khách hàng'}</span>
                    `;
                } else {
                    customerSelect.querySelector('.customer-select-value').innerHTML = `
                        <span>👤</span>
                        <span>Chọn khách hàng</span>
                    `;
                }
                
                // Close modal
                closeModal(customerModal);
                
                // Show notification
                showNotification('Đã chọn khách hàng', 'success');
                
                // Update checkout modal if open
                if (checkoutModal.classList.contains('active')) {
                    updateCheckoutCustomerInfo();
                    
                    // Trigger shipping type change to update address section visibility
                    if (shippingTypeSelect) {
                        const event = new Event('change');
                        shippingTypeSelect.dispatchEvent(event);
                    }
                }
                
            } catch (error) {
                console.error('Error selecting customer:', error);
                showNotification('Lỗi khi chọn khách hàng: ' + error.message, 'error');
            }
        }
        
        // Update Checkout Customer Info
        function updateCheckoutCustomerInfo() {
            // Function to update customer info in checkout modal
            const customerInfoSection = document.getElementById('checkout-customer-info');
            const customer = <?php echo isset($_SESSION['pos_customer']) ? json_encode($_SESSION['pos_customer']) : 'null'; ?>;
            
            if (customerInfoSection && customer) {
                // Create HTML for customer info section
                let infoHTML = `
                    <div style="display: flex; align-items: center; gap: var(--space-3); margin-bottom: var(--space-4);">
                        <div class="customer-avatar-medium">
                            ${customer.name ? customer.name.charAt(0).toUpperCase() : 'U'}
                        </div>
                        <div class="customer-info">
                            <div class="customer-name">${customer.name || 'Khách hàng'}</div>
                            <div class="customer-email">${customer.email || ''} | ${customer.phone || ''}</div>
                        </div>
                        <button class="btn button-secondary" id="change-customer-btn">Thay đổi</button>
                    </div>
                    
                    <div class="form-group">
                        <label for="shipping-type" class="form-label">Phương thức giao hàng</label>
                        <select id="shipping-type" class="form-control">
                            <option value="home_delivery">Giao hàng tận nơi</option>
                            <option value="pickup_point">Nhận tại cửa hàng</option>
                        </select>
                    </div>
                `;
                
                // Add address section if customer has addresses
                if (customer.addresses && customer.addresses.length > 0) {
                    infoHTML += `
                        <div id="address-section" style="margin-top: 15px; display: none;">
                            <h4 style="margin-bottom: var(--space-3);">Chọn địa chỉ giao hàng</h4>
                            <div class="address-list" id="address-list">
                    `;
                    
                    // Add each address
                    customer.addresses.forEach(address => {
                        const isDefault = address.set_default == 1;
                        // Set the first address as selected
                        if (isDefault) {
                            selectedAddressId = address.id;
                        }
                        
                        // Build address text
                        const addressParts = [];
                        if (address.address) addressParts.push(address.address);
                        if (address.city_name) addressParts.push(address.city_name);
                        if (address.state_name) addressParts.push(address.state_name);
                        if (address.country_name) addressParts.push(address.country_name);
                        if (address.postal_code) addressParts.push(address.postal_code);
                        
                        infoHTML += `
                            <div class="address-card ${isDefault ? 'selected' : ''}" data-address-id="${address.id}">
                                <div class="address-type">
                                    ${isDefault ? 'Mặc định' : 'Địa chỉ'}
                                </div>
                                <div class="address-text">
                                    ${addressParts.join(', ')}
                                </div>
                                ${address.phone ? `<div class="address-phone">SĐT: ${address.phone}</div>` : ''}
                            </div>
                        `;
                    });
                    
                    infoHTML += `
                            </div>
                        </div>
                    `;
                } else {
                    // No addresses, show manual input
                    infoHTML += `
                        <div id="address-section" style="margin-top: 15px; display: none;">
                            <div class="alert alert-warning">
                                Khách hàng chưa có địa chỉ giao hàng nào. Vui lòng nhập thông tin giao hàng bên dưới.
                            </div>
                            <div class="form-group">
                                <label for="manual-address" class="form-label">Địa chỉ giao hàng</label>
                                <textarea id="manual-address" class="form-control" rows="3" placeholder="Nhập địa chỉ giao hàng..."></textarea>
                            </div>
                        </div>
                    `;
                }
                
                // Add notes section
                infoHTML += `
                    <div class="form-group">
                        <label for="additional-info" class="form-label">Ghi chú đơn hàng</label>
                        <textarea id="additional-info" class="form-control" rows="3" placeholder="Thông tin thêm về đơn hàng..."></textarea>
                    </div>
                `;
                
                // Update the checkout customer info section
                customerInfoSection.innerHTML = infoHTML;
                
                // Re-bind event listeners
                const shippingType = document.getElementById('shipping-type');
                const addressSection = document.getElementById('address-section');
                
                if (shippingType && addressSection) {
                    shippingType.addEventListener('change', function() {
                        addressSection.style.display = this.value === 'home_delivery' ? 'block' : 'none';
                        
                        // Reset selected address when switching to pickup
                        if (this.value !== 'home_delivery') {
                            selectedAddressId = 0;
                            document.querySelectorAll('.address-card').forEach(card => {
                                card.classList.remove('selected');
                            });
                        }
                    });
                    
                    // Initially show address section for home delivery
                    if (shippingType.value === 'home_delivery') {
                        addressSection.style.display = 'block';
                    }
                }
                
                // Add change customer button handler
                const changeCustomerBtn = document.getElementById('change-customer-btn');
                if (changeCustomerBtn) {
                    changeCustomerBtn.addEventListener('click', function() {
                        closeModal(checkoutModal);
                        openModal(customerModal);
                    });
                }
                
                // Add address selection handlers
                document.querySelectorAll('.address-card').forEach(card => {
                    card.addEventListener('click', function() {
                        document.querySelectorAll('.address-card').forEach(c => {
                            c.classList.remove('selected');
                        });
                        this.classList.add('selected');
                        selectedAddressId = this.dataset.addressId;
                    });
                });
            }
        }
        
        // Checkout Button
        checkoutBtn.addEventListener('click', function() {
            // Check if cart exists and has items
            const cart = <?php echo json_encode(isset($_SESSION['pos_cart']) ? $_SESSION['pos_cart'] : []); ?>;
            if (!cart || Object.keys(cart).length === 0) {
                showNotification('Giỏ hàng đang trống', 'error');
                return;
            }
            
            openModal(checkoutModal);
            updateCheckoutCustomerInfo();
            
            // Trigger shipping type change to update address section visibility
            if (shippingTypeSelect) {
                const event = new Event('change');
                shippingTypeSelect.dispatchEvent(event);
            }
        });
        
        // Payment Method Selection
        paymentMethods.forEach(method => {
            method.addEventListener('click', function() {
                const paymentMethod = this.dataset.payment;
                const radio = this.querySelector('.payment-method-radio');
                
                // Update selected state
                paymentMethods.forEach(m => m.classList.remove('selected'));
                this.classList.add('selected');
                
                // Update radio state
                paymentRadios.forEach(r => r.checked = false);
                radio.checked = true;
                
                // Update selected payment method
                selectedPaymentMethod = paymentMethod;
            });
        });
        
        // Place Order
        placeOrderBtn.addEventListener('click', async function() {
            try {
                // Show loading state
                this.disabled = true;
                this.innerHTML = '<span class="loading-spinner"></span> Đang xử lý...';
                
                // Get shipping type
                const shippingTypeElement = document.getElementById('shipping-type');
                const shippingType = shippingTypeElement ? shippingTypeElement.value : 'home_delivery';
                
                // Validate address selection if home delivery
                if (shippingType === 'home_delivery') {
                    const customer = <?php echo isset($_SESSION['pos_customer']) ? json_encode($_SESSION['pos_customer']) : 'null'; ?>;
                    
                    if (customer && customer.addresses && customer.addresses.length > 0 && !selectedAddressId) {
                        throw new Error('Vui lòng chọn địa chỉ giao hàng');
                    }
                }
                
                const formData = new FormData();
                formData.append('action', 'create_order');
                formData.append('token', csrfToken);
                formData.append('payment_type', selectedPaymentMethod);
                
                const paymentStatusElement = document.getElementById('payment-status');
                if (paymentStatusElement) {
                    formData.append('payment_status', paymentStatusElement.value);
                } else {
                    formData.append('payment_status', 'unpaid');
                }
                
                formData.append('shipping_type', shippingType);
                
                if (selectedAddressId) {
                    formData.append('shipping_address_id', selectedAddressId);
                }
                
                const additionalInfoElement = document.getElementById('additional-info');
                if (additionalInfoElement) {
                    formData.append('additional_info', additionalInfoElement.value);
                }
                
                // Add manual address if needed
                const manualAddressElement = document.getElementById('manual-address');
                if (manualAddressElement && manualAddressElement.value.trim()) {
                    formData.append('manual_address', manualAddressElement.value.trim());
                }
                
                // Add shop ID to the order
                formData.append('seller_id', selectedShopId);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'Failed to create order');
                }
                
           // Close modal
                closeModal(checkoutModal);
                
                // Show success modal
                openSuccessModal(result.order_id, result.order_code);
                
                // Set a flag to indicate a successful order
                window.orderSuccessful = true;
                
            } catch (error) {
                console.error('Error creating order:', error);
                showNotification('Lỗi khi tạo đơn hàng: ' + error.message, 'error');
            } finally {
                // Reset button state
                this.disabled = false;
                this.innerHTML = 'Hoàn tất đơn hàng';
            }
        });
        
        // Open Success Modal
        function openSuccessModal(orderId, orderCode) {
            const customer = <?php echo isset($_SESSION['pos_customer']) ? json_encode($_SESSION['pos_customer']) : 'null'; ?>;
            const customerName = customer ? customer.name : 'Khách vãng lai';
            const paymentMethodText = selectedPaymentMethod === 'cash' 
                ? 'Tiền mặt' 
                : (selectedPaymentMethod === 'card' 
                    ? 'Thẻ tín dụng/ghi nợ' 
                    : 'Chuyển khoản ngân hàng');
                    
            const paymentStatusElement = document.getElementById('payment-status');
            const paymentStatusText = paymentStatusElement && paymentStatusElement.value === 'paid' 
                ? 'Đã thanh toán' 
                : 'Chưa thanh toán';
            
            const checkoutTotalElement = document.getElementById('checkout-total');
            const totalAmount = checkoutTotalElement ? checkoutTotalElement.textContent : '0₫';
            
            // Get selected shop name
            let shopName = "Cửa hàng chính";
            const shopSelect = document.getElementById('shop-select');
            if (shopSelect && shopSelect.selectedIndex >= 0) {
                shopName = shopSelect.options[shopSelect.selectedIndex].text;
            }
            
            // Get shipping info
            const shippingTypeElement = document.getElementById('shipping-type');
            const shippingTypeText = shippingTypeElement && shippingTypeElement.value === 'pickup_point' 
                ? 'Nhận tại cửa hàng' 
                : 'Giao hàng tận nơi';
            
            // Build success modal content
            successOrderInfo.innerHTML = `
                <div class="order-info-row">
                    <div class="order-info-label">Mã đơn hàng:</div>
                    <div>${orderCode || 'N/A'}</div>
                </div>
                <div class="order-info-row">
                    <div class="order-info-label">Khách hàng:</div>
                    <div>${customerName}</div>
                </div>
                <div class="order-info-row">
                    <div class="order-info-label">Cửa hàng:</div>
                    <div>${shopName}</div>
                </div>
                <div class="order-info-row">
                    <div class="order-info-label">Phương thức giao hàng:</div>
                    <div>${shippingTypeText}</div>
                </div>
                <div class="order-info-row">
                    <div class="order-info-label">Phương thức thanh toán:</div>
                    <div>${paymentMethodText}</div>
                </div>
                <div class="order-info-row">
                    <div class="order-info-label">Trạng thái thanh toán:</div>
                    <div>${paymentStatusText}</div>
                </div>
                <div class="order-info-row">
                    <div class="order-info-label">Tổng thanh toán:</div>
                    <div>${totalAmount}</div>
                </div>
            `;
            
            openModal(successModal);
        }
        
        // Print Receipt
        printReceiptBtn.addEventListener('click', function() {
            // Placeholder for printing functionality
            showNotification('Đang in hóa đơn...', 'info');
            setTimeout(() => {
                showNotification('Đã gửi hóa đơn đến máy in', 'success');
            }, 1000);
        });
        
        // New Order
        newOrderBtn.addEventListener('click', function() {
            closeModal(successModal);
            // Reload the page to reset everything
            window.location.reload();
        });
        
        // Close success modal with page refresh
        successModalClose.addEventListener('click', function() {
            closeModal(successModal);
            // Reload the page to reset everything
            window.location.reload();
        });
        
        // Barcode Scanner
        barcodeScanBtn.addEventListener('click', function() {
            openModal(barcodeModal);
            // Placeholder for barcode scanner functionality
        });
        
        // Barcode Manual Input
        barcodeInputBtn.addEventListener('click', function() {
            const barcode = barcodeInput.value.trim();
            if (!barcode) {
                showNotification('Vui lòng nhập mã vạch', 'error');
                return;
            }
            
            // Placeholder for barcode search functionality
            showNotification('Đang tìm kiếm sản phẩm với mã vạch: ' + barcode, 'info');
            closeModal(barcodeModal);
        });
        
        // Modal Functions
        function openModal(modal) {
            modal.classList.add('active');
            setTimeout(() => {
                modal.querySelector('.modal').classList.add('active');
            }, 10);
        }
        
        function closeModal(modal) {
            modal.querySelector('.modal').classList.remove('active');
            setTimeout(() => {
                modal.classList.remove('active');
            }, 300);
        }
        
        // Close modals on button click
        document.querySelectorAll('.modal-close, #customer-modal-cancel, #checkout-modal-cancel, #barcode-modal-cancel, #product-modal-cancel, #success-modal-close').forEach(btn => {
            btn.addEventListener('click', function() {
                const modal = this.closest('.modal-backdrop');
                closeModal(modal);
            });
        });
        
        // Close modals on backdrop click
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
            backdrop.addEventListener('click', function(e) {
                if (e.target === backdrop) {
                    closeModal(backdrop);
                }
            });
        });
        
        // Format Currency
        ).format(amount);
        }
        
        // Show Notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 10);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 3000);
        }
        
        // Address Selection in Checkout
        document.addEventListener('click', function(e) {
            const addressCard = e.target.closest('.address-card');
            if (addressCard && checkoutModal.classList.contains('active')) {
                document.querySelectorAll('.address-card').forEach(card => {
                    card.classList.remove('selected');
                });
                addressCard.classList.add('selected');
                selectedAddressId = addressCard.dataset.addressId;
            }
        });
        
        // Change customer button in checkout modal
        document.addEventListener('click', function(e) {
            if (e.target.id === 'change-customer-btn' || e.target.id === 'select-customer-btn') {
                closeModal(checkoutModal);
                openModal(customerModal);
            }
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Add click handlers for existing cart items
            document.querySelectorAll('.cart-item').forEach(item => {
                const itemKey = item.dataset.itemKey;
                const quantityInput = item.querySelector('.quantity-input');
                const decreaseBtn = item.querySelector('.quantity-decrease');
                const increaseBtn = item.querySelector('.quantity-increase');
                const removeBtn = item.querySelector('.remove-item-btn');
                
                quantityInput.addEventListener('change', function() {
                    updateCartItemQuantity(itemKey, this.value);
                });
                
                decreaseBtn.addEventListener('click', function() {
                    const currentValue = parseInt(quantityInput.value);
                    if (currentValue > 1) {
                        updateCartItemQuantity(itemKey, currentValue - 1);
                    }
                });
                
                increaseBtn.addEventListener('click', function() {
                    const currentValue = parseInt(quantityInput.value);
                    updateCartItemQuantity(itemKey, currentValue + 1);
                });
                
                removeBtn.addEventListener('click', function() {
                    removeCartItem(itemKey);
                });
            });
            
            // Add handlers for existing product cards
            document.querySelectorAll('.product-card').forEach(card => {
                card.addEventListener('click', function() {
                    const productId = this.dataset.productId;
                    openProductDetail(productId);
                });
            });
            
            // Add shop selection handler
            const shopSelect = document.getElementById('shop-select');
            if (shopSelect) {
                shopSelect.addEventListener('change', function() {
                    selectedShopId = parseInt(this.value) || 0;
                    loadProducts(); // Reload products based on selected shop
                });
            }
            
            // Initialize shipping type change handler if exists
            const shippingType = document.getElementById('shipping-type');
            const addressSection = document.getElementById('address-section');
            
            if (shippingType && addressSection) {
                shippingType.addEventListener('change', function() {
                    addressSection.style.display = this.value === 'home_delivery' ? 'block' : 'none';
                });
            }
        });
    </script>
</body>
</html>