<?php
session_start();
require_once 'config.php';

// Set page-specific variables
$current_page = 'product-detail';
$require_login = false;

// Get user info if logged in
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : null;
$user_type = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : null;

// Get product ID from URL
$product_id = isset($_GET['id']) ? (int)  $_GET['id'] : 0;

if (!$product_id) {
    header('Location: index.php');
    exit;
}

try {
    // Get product details with seller info
    $stmt = $pdo->prepare("
        SELECT p.*, u.name as seller_name, u.avatar as seller_avatar,
               b.name as brand_name, c.name as category_name,
               thumb.file_name as thumbnail_file
        FROM products p 
        LEFT JOIN users u ON p.user_id = u.id 
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN uploads thumb ON p.thumbnail_img = thumb.id AND thumb.deleted_at IS NULL
        WHERE p.id = ? AND p.published = 1 AND p.approved = 1
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        header('Location: index.php');
        exit;
    }

    // Get product images
    $product_images = [];
    if (!empty($product['photos'])) {
        $photos_json = json_decode($product['photos'], true);
        if (is_array($photos_json)) {
            foreach ($photos_json as $photo_id) {
                $stmt_img = $pdo->prepare("SELECT file_name FROM uploads WHERE id = ? AND deleted_at IS NULL");
                $stmt_img->execute([$photo_id]);
                $img_result = $stmt_img->fetch();
                if ($img_result) {
                    $product_images[] = $img_result['file_name'];
                }
            }
        }
    }

    // Add thumbnail if not in photos
    if (!empty($product['thumbnail_file']) && !in_array($product['thumbnail_file'], $product_images)) {
        array_unshift($product_images, $product['thumbnail_file']);
    }

    // Get product variations
    $stmt = $pdo->prepare("
        SELECT * FROM product_stocks 
        WHERE product_id = ? 
        ORDER BY price ASC
    ");
    $stmt->execute([$product_id]);
    $variations = $stmt->fetchAll();

    // Get product reviews
    $stmt = $pdo->prepare("
        SELECT r.*, u.name as user_name, u.avatar as user_avatar
        FROM reviews r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.product_id = ? AND r.status = 1
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$product_id]);
    $reviews = $stmt->fetchAll();

    // Calculate average rating
    $stmt = $pdo->prepare("
        SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
        FROM reviews 
        WHERE product_id = ? AND status = 1
    ");
    $stmt->execute([$product_id]);
    $rating_info = $stmt->fetch();
    $avg_rating = round($rating_info['avg_rating'], 1);
    $total_reviews = $rating_info['total_reviews'];

    // Get related products
    $stmt = $pdo->prepare("
        SELECT p.*, u.name as seller_name, thumb.file_name as thumbnail_file
        FROM products p 
        LEFT JOIN users u ON p.user_id = u.id 
        LEFT JOIN uploads thumb ON p.thumbnail_img = thumb.id AND thumb.deleted_at IS NULL
        WHERE p.category_id = ? AND p.id != ? AND p.published = 1 AND p.approved = 1
        ORDER BY p.num_of_sale DESC
        LIMIT 12
    ");
    $stmt->execute([$product['category_id'], $product_id]);
    $related_products = $stmt->fetchAll();

    // Update view count (simple analytics)
    $stmt = $pdo->prepare("UPDATE products SET num_of_sale = num_of_sale WHERE id = ?");
    $stmt->execute([$product_id]);

} catch (PDOException $e) {
    header('Location: index.php');
    exit;
}

// Calculate final price after discount
$final_price = $product['unit_price'];
$discount_amount = 0;

if ($product['discount'] > 0) {
    if ($product['discount_type'] === 'percent') {
        $discount_amount = ($product['unit_price'] * $product['discount']) / 100;
    } else {
        $discount_amount = $product['discount'];
    }
    $final_price = $product['unit_price'] - $discount_amount;
}

// Format attributes and choices
$attributes = json_decode($product['attributes'], true) ?: [];
$choice_options = json_decode($product['choice_options'], true) ?: [];
$colors = json_decode($product['colors'], true) ?: [];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product['name']); ?> - TikTok Shop</title>
    <meta name="description"
        content="<?php echo htmlspecialchars(substr(strip_tags($product['description']), 0, 160)); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($product['tags']); ?>">

    <!-- Open Graph Meta Tags -->
    <meta property="og:title" content="<?php echo htmlspecialchars($product['name']); ?>">
    <meta property="og:description"
        content="<?php echo htmlspecialchars(substr(strip_tags($product['description']), 0, 160)); ?>">
    <meta property="og:image"
        content="<?php echo !empty($product_images[0]) ? htmlspecialchars($product_images[0]) : ''; ?>">
    <meta property="og:type" content="product">
    <meta property="product:price:amount" content="<?php echo $final_price; ?>">
    <meta property="product:price:currency" content="VND">

    <link rel="stylesheet" href="asset/base.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
    /* Product Detail Styles - Shopee Inspired */
    .product-container {
        max-width: 1200px;
        margin: 20px auto;
        padding: 0 15px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        overflow: hidden;
    }

    .product-main {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 40px;
        padding: 30px;
    }

    /* Product Gallery */
    .product-gallery {
        position: sticky;
        top: 100px;
        height: fit-content;
    }

    .main-image-container {
        position: relative;
        margin-bottom: 15px;
        border-radius: 8px;
        overflow: hidden;
        background: #f8f9fa;
    }

    .main-image {
        width: 100%;
        height: 450px;
        object-fit: cover;
        cursor: zoom-in;
        transition: transform 0.3s ease;
    }

    .main-image:hover {
        transform: scale(1.05);
    }

    .image-placeholder {
        width: 100%;
        height: 450px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 80px;
        color: #ccc;
        background: #f8f9fa;
    }

    .zoom-indicator {
        position: absolute;
        top: 15px;
        right: 15px;
        background: rgba(0, 0, 0, 0.7);
        color: white;
        padding: 8px 12px;
        border-radius: 20px;
        font-size: 12px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .thumbnail-list {
        display: flex;
        gap: 10px;
        overflow-x: auto;
        padding: 5px 0;
    }

    .thumbnail-item {
        flex-shrink: 0;
        width: 80px;
        height: 80px;
        border-radius: 6px;
        overflow: hidden;
        cursor: pointer;
        border: 2px solid transparent;
        transition: all 0.3s ease;
    }

    .thumbnail-item.active {
        border-color: #ee4d2d;
    }

    .thumbnail-item:hover {
        border-color: #ee4d2d;
        transform: translateY(-2px);
    }

    .thumbnail-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .thumbnail-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8f9fa;
        color: #ccc;
        font-size: 20px;
    }

    /* Product Info */
    .product-info {
        padding-left: 10px;
    }

    .product-category {
        color: #666;
        font-size: 14px;
        margin-bottom: 8px;
    }

    .product-title {
        font-size: 24px;
        font-weight: 600;
        color: #333;
        line-height: 1.4;
        margin-bottom: 15px;
    }

    .product-rating {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid #f0f0f0;
    }

    .rating-stars {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .star {
        color: #ffa500;
        font-size: 16px;
    }

    .star.empty {
        color: #e0e0e0;
    }

    .rating-text {
        color: #666;
        font-size: 14px;
    }

    .sold-count {
        color: #666;
        font-size: 14px;
        padding-left: 15px;
        border-left: 1px solid #e0e0e0;
    }

    /* Price Section */
    .price-section {
        background: #fafafa;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 25px;
    }

    .current-price {
        font-size: 30px;
        font-weight: 700;
        color: #ee4d2d;
        margin-right: 15px;
    }

    .original-price {
        font-size: 18px;
        color: #999;
        text-decoration: line-through;
        margin-right: 10px;
    }

    .discount-badge {
        background: #ee4d2d;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
    }

    /* Variations Section */
    .variation-section {
        margin-bottom: 25px;
    }

    .variation-title {
        font-size: 16px;
        font-weight: 600;
        color: #333;
        margin-bottom: 12px;
    }

    .variation-options {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .variation-option {
        padding: 8px 16px;
        border: 1px solid #e0e0e0;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 14px;
        background: white;
    }

    .variation-option:hover {
        border-color: #ee4d2d;
        color: #ee4d2d;
    }

    .variation-option.active {
        background: #ee4d2d;
        color: white;
        border-color: #ee4d2d;
    }

    .variation-option.disabled {
        background: #f5f5f5;
        color: #ccc;
        cursor: not-allowed;
    }

    .color-option {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: 2px solid #e0e0e0;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }

    .color-option.active {
        border-color: #ee4d2d;
        box-shadow: 0 0 0 2px rgba(238, 77, 45, 0.3);
    }

    /* Quantity Section */
    .quantity-section {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 30px;
        padding-bottom: 25px;
        border-bottom: 1px solid #f0f0f0;
    }

    .quantity-label {
        font-size: 16px;
        font-weight: 600;
        color: #333;
        min-width: 80px;
    }

    .quantity-controls {
        display: flex;
        align-items: center;
        border: 1px solid #e0e0e0;
        border-radius: 6px;
        overflow: hidden;
    }

    .quantity-btn {
        width: 40px;
        height: 40px;
        border: none;
        background: #f8f9fa;
        cursor: pointer;
        font-size: 18px;
        color: #666;
        transition: all 0.3s ease;
    }

    .quantity-btn:hover {
        background: #e9ecef;
        color: #ee4d2d;
    }

    .quantity-btn:disabled {
        background: #f5f5f5;
        color: #ccc;
        cursor: not-allowed;
    }

    .quantity-input {
        width: 60px;
        height: 40px;
        border: none;
        text-align: center;
        font-size: 16px;
        font-weight: 600;
        background: white;
    }

    .stock-info {
        color: #666;
        font-size: 14px;
    }

    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 15px;
        margin-bottom: 25px;
    }

    .add-to-cart-btn {
        flex: 1;
        padding: 15px 20px;
        border: 2px solid #ee4d2d;
        background: white;
        color: #ee4d2d;
        font-size: 16px;
        font-weight: 600;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .add-to-cart-btn:hover {
        background: #ee4d2d;
        color: white;
        transform: translateY(-1px);
    }

    .buy-now-btn {
        flex: 1;
        padding: 15px 20px;
        background: #ee4d2d;
        color: white;
        border: none;
        font-size: 16px;
        font-weight: 600;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .buy-now-btn:hover {
        background: #d73527;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(238, 77, 45, 0.3);
    }

    /* Additional Info */
    .additional-info {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }

    .info-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px;
        background: #f8f9fa;
        border-radius: 6px;
        font-size: 14px;
    }

    .info-icon {
        width: 20px;
        height: 20px;
        color: #ee4d2d;
    }

    /* Seller Info */
    .seller-info {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .seller-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 15px;
    }

    .seller-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: #ee4d2d;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        font-size: 18px;
    }

    .seller-name {
        font-size: 18px;
        font-weight: 600;
        color: #333;
    }

    .seller-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
        text-align: center;
    }

    .seller-stat {
        padding: 10px;
        background: white;
        border-radius: 6px;
    }

    .stat-value {
        font-size: 18px;
        font-weight: 600;
        color: #ee4d2d;
        display: block;
    }

    .stat-label {
        font-size: 12px;
        color: #666;
    }

    /* Product Details Tabs */
    .product-details {
        margin-top: 30px;
        border-top: 1px solid #e0e0e0;
    }

    .tabs-header {
        display: flex;
        border-bottom: 1px solid #e0e0e0;
        background: #f8f9fa;
    }

    .tab-btn {
        padding: 15px 25px;
        border: none;
        background: transparent;
        cursor: pointer;
        font-size: 16px;
        font-weight: 500;
        color: #666;
        transition: all 0.3s ease;
        border-bottom: 3px solid transparent;
    }

    .tab-btn.active {
        color: #ee4d2d;
        border-bottom-color: #ee4d2d;
        background: white;
    }

    .tab-content {
        padding: 30px;
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    /* Description Styles */
    .product-description {
        line-height: 1.8;
        color: #555;
    }

    .product-description img {
        max-width: 100%;
        height: auto;
        border-radius: 8px;
        margin: 15px 0;
    }

    .product-description h3 {
        color: #333;
        margin-top: 25px;
        margin-bottom: 15px;
    }

    .product-description ul {
        padding-left: 20px;
        margin: 15px 0;
    }

    .product-description li {
        margin-bottom: 8px;
    }

    /* Reviews Section */
    .reviews-summary {
        display: grid;
        grid-template-columns: 200px 1fr;
        gap: 30px;
        margin-bottom: 30px;
        padding-bottom: 25px;
        border-bottom: 1px solid #e0e0e0;
    }

    .rating-overview {
        text-align: center;
        padding: 20px;
        background: #fafafa;
        border-radius: 8px;
    }

    .avg-rating {
        font-size: 36px;
        font-weight: 700;
        color: #ee4d2d;
        margin-bottom: 5px;
    }

    .rating-breakdown {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .rating-row {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 14px;
    }

    .rating-bar {
        flex: 1;
        height: 8px;
        background: #e0e0e0;
        border-radius: 4px;
        overflow: hidden;
    }

    .rating-fill {
        height: 100%;
        background: #ffa500;
        transition: width 0.3s ease;
    }

    .review-item {
        padding: 20px 0;
        border-bottom: 1px solid #f0f0f0;
    }

    .review-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 12px;
    }

    .review-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #ee4d2d;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
    }

    .review-info h4 {
        font-size: 14px;
        font-weight: 600;
        color: #333;
        margin-bottom: 2px;
    }

    .review-info .review-date {
        font-size: 12px;
        color: #999;
    }

    .review-rating {
        margin-left: auto;
    }

    .review-content {
        line-height: 1.6;
        color: #555;
        margin-bottom: 10px;
    }

    .review-images {
        display: flex;
        gap: 10px;
        margin-top: 12px;
    }

    .review-image {
        width: 80px;
        height: 80px;
        border-radius: 6px;
        object-fit: cover;
        cursor: pointer;
        transition: transform 0.3s ease;
    }

    .review-image:hover {
        transform: scale(1.05);
    }

    /* Related Products */
    .related-products {
        margin: 40px 0;
        padding: 0 30px;
    }

    .related-title {
        font-size: 24px;
        font-weight: 600;
        color: #333;
        margin-bottom: 25px;
        text-align: center;
    }

    .related-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 15px;
    }

    .related-item {
        background: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .related-item:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .related-image {
        width: 100%;
        height: 140px;
        object-fit: cover;
    }

    .related-info {
        padding: 12px;
    }

    .related-name {
        font-size: 13px;
        color: #333;
        margin-bottom: 8px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .related-price {
        font-size: 14px;
        font-weight: 600;
        color: #ee4d2d;
    }

    /* Modal Styles */
    .modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.8);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 10000;
    }

    .modal.active {
        display: flex;
    }

    .modal-content {
        max-width: 90vw;
        max-height: 90vh;
        border-radius: 8px;
        overflow: hidden;
    }

    .modal-image {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .modal-close {
        position: absolute;
        top: 20px;
        right: 20px;
        background: rgba(0, 0, 0, 0.7);
        color: white;
        border: none;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 20px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .product-main {
            grid-template-columns: 1fr;
            gap: 20px;
            padding: 20px;
        }

        .product-gallery {
            position: static;
        }

        .main-image {
            height: 350px;
        }

        .image-placeholder {
            height: 350px;
        }

        .product-title {
            font-size: 20px;
        }

        .current-price {
            font-size: 24px;
        }

        .action-buttons {
            flex-direction: column;
        }

        .additional-info {
            grid-template-columns: 1fr;
        }

        .seller-stats {
            grid-template-columns: repeat(2, 1fr);
        }

        .reviews-summary {
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .related-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .tabs-header {
            overflow-x: auto;
        }

        .tab-btn {
            white-space: nowrap;
            min-width: 120px;
        }
    }

    @media (max-width: 480px) {
        .product-container {
            margin: 10px;
            border-radius: 0;
        }

        .main-image {
            height: 280px;
        }

        .image-placeholder {
            height: 280px;
        }

        .thumbnail-item {
            width: 60px;
            height: 60px;
        }

        .product-info {
            padding-left: 0;
        }

        .related-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    /* Loading States */
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
        border-top: 2px solid #ee4d2d;
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

    /* Wishlist Button */
    .wishlist-btn {
        position: absolute;
        top: 15px;
        left: 15px;
        width: 40px;
        height: 40px;
        background: rgba(255, 255, 255, 0.9);
        border: none;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #999;
        font-size: 18px;
        transition: all 0.3s ease;
    }

    .wishlist-btn:hover,
    .wishlist-btn.active {
        color: #ee4d2d;
        background: white;
        transform: scale(1.1);
    }

    /* Share Button */
    .share-btn {
        padding: 12px 20px;
        border: 1px solid #e0e0e0;
        background: white;
        color: #666;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
    }

    .share-btn:hover {
        border-color: #ee4d2d;
        color: #ee4d2d;
    }

    /* Breadcrumb */
    .breadcrumb {
        background: #f8f9fa;
        padding: 15px 30px;
        font-size: 14px;
        color: #666;
    }

    .breadcrumb a {
        color: #666;
        text-decoration: none;
    }

    .breadcrumb a:hover {
        color: #ee4d2d;
    }

    .breadcrumb-separator {
        margin: 0 8px;
        color: #ccc;
    }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="index.php">Trang chủ</a>
        <span class="breadcrumb-separator">></span>
        <a
            href="category.php?id=<?php echo $product['category_id']; ?>"><?php echo htmlspecialchars($product['category_name'] ?: 'Danh mục'); ?></a>
        <span class="breadcrumb-separator">></span>
        <span><?php echo htmlspecialchars($product['name']); ?></span>
    </div>

    <!-- Product Container -->
    <div class="product-container">
        <!-- Main Product Section -->
        <div class="prod    uct-main">
            <!-- Product Gall    ery -->
            <div class="produ    ct-gallery">
                <div cla ss="main-image-container">
                    <bu class="wishlist-btn" onclick="toggleWishlist(<?php echo $product_id; ?>)">
                        <i c lass="far fa-heart"></i>
                    </bu tton>

                    <?ph    p if (!empty($product_images)): ?>
                    <img id="mainImage" src="<?php echo htmlspecialchars($product_images[0]); ?>"
                        alt="<?php echo htmlspecialchars($product['name']); ?>" class="main-image"
                        onclick="openImageModal(this.src)">
                    <div class="zoom-indicator">
                        <i class="fas fa-search-plus"></i>
                        <span>Click để phóng to</span>
                    </div>
                    <?php         else: ?>
                    <div class="image-placeholder">📦</div>
                    <?php end        if; ?>
                </div>

                <?ph    p if (count($product_images) > 1): ?>
                <div class="thumbnail-list">
                    <?php foreach ($product_images as $index => $image): ?>
                    <div class="thumbnail-item <?php echo $index === 0 ? 'active' : ''; ?>"
                        onclick="changeMainImage('<?php echo htmlspecialchars($image); ?>', this)">
                        <img src="<?php echo htmlspecialchars($image); ?>" alt="Product image <?php echo $index + 1; ?>"
                            class="thumbnail-image">
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Product Info -->
            <div class="product-info">
                <div class="product-category">
                    <?php if ($product['brand_name']): ?>
                    <span><?php echo htmlspecialchars($product['brand_name']); ?></span> |
                    <?php endif; ?>
                    <span>
                        <?    php echo htmlspecialchars($product['category_name'] ?: 'Sản phẩm'); ?>
                    </span>
                </div>

                <h1 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h1>

                <div class="product-rating">
                    <div class="rating-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span class="star <?php echo $i <= $avg_rating ? '' : 'empty'; ?>">★</span>
                        <?php endfor; ?>
                        <span class="rating-text"><?php echo $avg_rating; ?>/5</span>
                        <span class="rating-text">(<?php echo $total_reviews; ?> đánh giá)</span>
                    </div>
                    <di class="sold-count">
                        <i class="fas fa-shopping-cart"></i>
                        <?php echo number_format($product['num_of_sale']); ?> đã bán
                    </di v>
                </div>

                <!-- Price Section -->
                <div class="price-section">
                    <span class="current-price">₫<?php echo number_format($final_price, 0, ',', '.'); ?></span>
                    <?php if ($discount_amount > 0): ?>
                    <span
                        class="original-price">₫<?php echo number_format($product['unit_price'], 0, ',', '.'); ?></span>
                    <sp class="discount-badge">
                        -<?php echo $product['discount_type'] === 'percent' ? $product['discount'] . '%' : number_format($product['discount'], 0, ',', '.') . 'đ'; ?>
                    </sp an>
                    <?php en        dif; ?>
                </div>

                <!-- Variati            ons -->
                <?php if (!e        mpty($choice_options)): ?>
                <?php fo        reach ($choice_options as $choice): ?>
                <div class="variation-section">
                    <div class="variation-title"><?php echo htmlspecialchars($choice['title']); ?></div>
                    <di class="variation-options">
                        <?php foreach ($choice['options'] as $option): ?>
                        <button class="variation-option"
                            onclick="selectVariation(this, '<?php echo $choice['attribute_id']; ?>')">
                            <?php echo htmlspecialchars($option); ?>
                        </button>
                        <?php endforeach; ?>
                    </di v>
                </div>
                <?php endforeach        ; ?>
                <?php en        dif; ?>

                <!--     Colors -->
                <?php if (!empty($colors)): ?>
                <div class="variation-section">
                    <div class="variation-title">Màu sắc</div>
                    <div class="variation-options">
                        <?php foreach ($colors as $color): ?>
                        <button class="color-option"
                            style="background-color: <?php echo htmlspecialchars($color['code']); ?>"
                            onclick="selectColor(this)" title="<?php echo htmlspecialchars($color['name']); ?>">
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Quantity -->
                <div class="quantity-section">
                    <span class="quantity-label">Số lượng</span>
                    <div class="quantity-controls">
                        <button class="quantity-btn" onclick="decreaseQuantity()">-</button>
                        <input type="number" id="quantity" class="quantity-input" value="1" min="1"
                            max="<?php echo $product['current_stock']; ?>">
                        <button class="quantity-btn" onclick="increaseQuantity()">+</button>
                    </div>
                    <span class="stock-info"><?php echo number_format($product['current_stock']); ?> sản phẩm có
                        sẵn</span>
                </div>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <button class="add-to-cart-btn" onclick="addToCart(<?php echo $product_id; ?>)">
                        <i class="fas fa-shopping-cart"></i>
                        Thêm vào giỏ hàng
                    </button>
                    <button class="buy-now-btn" onclick="buyNow(<?php echo $product_id; ?>)">
                        <i class="fas fa-bolt"></i>
                        Mua ngay
                    </button>
                </div>

                <!-- Additional Info -->
                <div class="additional-info">
                    <div class="info-item">
                        <i class="fas fa-shield-alt info-icon"></i>
                        <span>Bảo hành chính hãng</span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-undo-alt info-icon"></i>
                        <span>30 ngày đổi trả</span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-shipping-fast info-icon"></i>
                        <span>Miễn phí vận chuyển</span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-medal info-icon"></i>
                        <span>Hàng chính hãng 100%</span>
                    </div>
                </div>

                <!-- Share Button -->
                <button class="share-btn" onclick="shareProduct()">
                    <i class="fas fa-share-alt"></i>
                    Chia sẻ sản phẩm
                </button>

                <!-- Seller Info -->
                <div class="seller-info">
                    <div class="seller-header">
                        <div class="seller-avatar">
                            <?php echo strtoupper(substr($product['seller_name'] ?: 'TikTok Shop', 0, 2)); ?>
                        </div>
                        <div>
                            <div class="seller-name">
                                <?php echo htmlspecialchars($product['seller_name'] ?: 'TikTok Shop'); ?></div>
                            <div style="font-size: 14px; color: #666;">Người bán</div>
                        </div>
                    </div>
                    <div class="seller-stats">
                        <div class="seller-stat">
                            <span class="stat-value">4.8</span>
                            <span class="stat-label">Đánh giá</span>
                        </div>
                        <div class="seller-stat">
                            <span class="stat-value">98%</span>
                            <span class="stat-label">Phản hồi</span>
                        </div>
                        <div class="seller-stat">
                            <span class="stat-value">2h</span>
                            <span class="stat-label">Thời gian phản hồi</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Product Details Tabs -->
        <div class="prod    uct-details">
            <div class="tabs-header">
                <button class="tab-btn active" onclick="switchTab(this, 'description')">Mô tả sản phẩm</button>
                <button class="tab-btn" onclick="switchTab(this, 'reviews')">Đánh giá
                    (<?php echo $total_reviews; ?>)</button>
                <button class="tab-btn" onclick="switchTab(this, 'qa')">Hỏi & Đáp</button>
                <button class="tab-btn" onclick="switchTab(this, 'shipping')">Vận chuyển</button>
            </div>

            <!-- Description Tab -->
            <div id="description" class="tab-content active">
                <div class="product-description">
                    <?php if (!empty($product['description'])): ?>
                    <?php echo $product['description']; ?>
                    <?php else: ?>
                    <p>Chưa có mô tả chi tiết cho sản phẩm này.</p>
                    <?php endif; ?>

                    <!-- Product Specifications -->
                    <h3>Thông số kỹ thuật</h3>
                    <table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
                        <tr style="border-bottom: 1px solid #e0e0e0;">
                            <td style="padding: 12px 0; font-weight: 600; width: 30%;">Thương hiệu</td>
                            <td style="padding: 12px 0;">
                                <?php echo htmlspecialchars($product['brand_name'] ?: 'Không xác định'); ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e0e0e0;">
                            <td style="padding: 12px 0; font-weight: 600;">Danh mục</td>
                            <td style="padding: 12px 0;">
                                <?php echo htmlspecialchars($product['category_name'] ?: 'Không xác định'); ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e0e0e0;">
                            <td style="padding: 12px 0; font-weight: 600;">Trọng lượng</td>
                            <td style="padding: 12px 0;">
                                <?php echo $product['weight'] > 0 ? $product['weight'] . 'g' : 'Không xác định'; ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e0e0e0;">
                            <td style="padding: 12px 0; font-weight: 600;">Đơn vị</td>
                            <td style="padding: 12px 0;"><?php echo htmlspecialchars($product['unit'] ?: 'Cái'); ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e0e0e0;">
                            <td style="padding: 12px 0; font-weight: 600;">SKU</td>
                            <td style="padding: 12px 0;"><?php echo htmlspecialchars($product['slug']); ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Reviews Tab         -->
            <div id="reviews" cl ass="tab-content">
                <?php if ($t        otal_reviews > 0): ?>
                <div class="        reviews-summary">
                    <di class="rating-overview">
                        <div class="avg-rating"><?php echo $avg_rating; ?></div>
                        <div class="rating-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span class="star <?php echo $i <= $avg_rating ? '' : 'empty'; ?>">★</span>
                            <?php endfor; ?>
                        </div>
                        <div style="margin-top: 10px; color: #666;"><?php echo $total_reviews; ?> đánh giá</div>
                    </di v>

                    <div class="        rating-breakdown">
                        <?ph        p for ($i = 5; $i >= 1; $i--): ?>
                        <div class="rating-row">
                            <spa n><?php echo $i; ?> sao</spa>
                            <div class="rating-bar">
                                <div class="rating-fill" style="width: <?php echo rand(10, 90); ?>%"></div>
                            </div>
                            <spa n><?php echo rand(5, 50); ?></spa>
                        </div>
                        <?php en        dfor; ?>
                    </div>
                </div>

                <div cla ss="reviews-list">
                    <?ph            p foreach ($reviews as $review): ?>
                    <div cla ss="r
                                         class=" review-header">
                        <div class="review-avatar">
                            <?php echo strtoupper(substr($review['user_name'], 0, 1)); ?>
                        </div>
                        <div class="review-info">
                            < <div class="review-date"><?php echo date('d/m/Y', strtotime($review['created_at'])); ?>
                        </div>
                    </div>
                    <div class="review-rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span class="star <?php echo $i <= $review['rating'] ? '' : 'empty'; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="review-content"><?php echo htmlspecialchars($review['comment']); ?></div>
                <?php if (!empty($review['photos'])): ?>
                <div class="review-images">
                    <?php
                                        $review_photos = json_decode($review['photos'], true);
                                        if (is_array($review_photos)):
                                            foreach ($review_photos as $photo):
                                                ?>
                    <img src="<?php echo htmlspecialchars($photo); ?>" alt="Review image" class="review-image">
                    <?php
                                            endforeach;
                                        endif;
                                        ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: 40px; color: #666;">
            <i class="fas fa-star" style="font-size: 48px; color: #e0e0e0; margin-bottom: 15px;"></i>
            <p>Chưa có đánh giá nào cho sản phẩm này</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Q&A Tab -->
    <div id="qa" class="tab-content">
        <div style="text-align: center; padding: 40px; color: #666;">
            <i class="fas fa-question-circle" style="font-size: 48px; color: #e0e0e0; margin-bottom: 15px;"></i>
            <p>Chưa có câu hỏi nào cho sản phẩm này</p>
            <p>Hãy là người đầu tiên đặt câu hỏi!</p>
        </div>
    </div>

    <!-- Shipping Tab -->
    <div id="shipping" class="tab-content">
        <h3>Thông tin vận chuyển</h3>
        <div style="margin: 20px 0;">
            <div class="info-item" style="margin-bottom: 15px;">
                <i class="fas fa-truck info-icon"></i>
                <div>
                    <strong>Giao hàng tiêu chuẩn</strong>
                    <div style="color: #666; font-size: 14px;">2-3 ngày làm việc | Miễn phí cho đơn từ 99.000đ
                    </div>
                </div>
            </div>
            <div class="info-item" style="margin-bottom: 15px;">
                <i class="fas fa-shipping-fast info-icon"></i>
                <div>
                    <strong>Giao hàng nhanh</strong>
                    <div style="color: #666; font-size: 14px;">1-2 ngày làm việc | Phí: 30.000đ</div>
                </div>
            </div>
            <div class="info-item" style="margin-bottom: 15px;">
                <i class="fas fa-store info-icon"></i>
                <div>
                    <strong>Nhận tại cửa hàng</strong>
                    <div style="color: #666; font-size: 14px;">Miễn phí | Sẵn sàng sau 2 giờ</div>
                </div>
            </div>
        </div>

        <h3>Chính sách đổi trả</h3>
        <ul style="margin: 15px 0; padding-left: 20px;">
            <li>Đổi trả miễn phí trong 30 ngày</li>
            <li>Sản phẩm phải còn nguyên vẹn, chưa sử dụng</li>
            <li>Giữ nguyên bao bì và hóa đơn mua hàng</li>
            <li>Hoàn tiền 100% nếu sản phẩm lỗi từ nhà sản xuất</li>
        </ul>
    </div>
    </div>
    </div>

    <!--     Related Products -->
    <?php if (!empty($related_products)): ?>
    <div class="related-products">
        <h2 class="related-title">Sản phẩm liên quan</h2>
        <div class="related-grid">
            <?php foreach ($related_products as $related): ?>
            <div class="related-item" onclick="navigateToProduct(<?php echo $related['id']; ?>)">
                <?php if (!empty($related['thumbnail_file'])): ?>
                <img src="<?php echo htmlspecialchars($related['thumbnail_file']); ?>"
                    alt="<?php echo htmlspecialchars($related['name']); ?>" class="related-image">
                <?php else: ?>
                <div class="related-image"
                    style="background: #f8f9fa; display: flex; align-items: center; justify-content: center; color: #ccc; font-size: 24px;">
                    📦</div>
                <?php endif; ?>
                <div class="related-info">
                    <div class="related-name"><?php echo htmlspecialchars($related['name']); ?></div>
                    <div class="related-price">₫<?php echo number_format($related['unit_price'], 0, ',', '.'); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Image Modal -->
    <div id="imageModal" class="modal">
        <button class="modal-close" onclick="closeImageModal()">&times;</button>
        <div class="modal-content">
            <img id="modalImage" src="" alt="Product Image" class="modal-image">
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <script>
    // Global variables
    let selectedVariations = {};
    let currentQuantity = 1;
    let maxStock = <?php echo $product['current_stock']; ?>;

    // Image gallery functions
    function changeMainImage(imageSrc, thumbnail) {
        document.getElementById('mainImage').src = imageSrc;

        // Update active thumbnail
        document.querySelectorAll('.thumbnail-item').forEach(item => {
            item.classList.remove('active');
        });
        thumbnail.classList.add('active');
    }

    function openImageModal(imageSrc) {
        document.getElementById('modalImage').src = imageSrc;
        document.getElementById('imageModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeImageModal() {
        document.getElementById('imageModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }

    // Close modal when clicking outside
    document.getElementById('imageModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeImageModal();
        }
    });

    // Variation selection
    function selectVariation(button, attributeId) {
        // Remove active class from siblings
        button.parentElement.querySelectorAll('.variation-option').forEach(opt => {
            opt.classList.remove('active');
        });

        // Add active class to selected option
        button.classList.add('active');

        // Store selection
        selectedVariations[attributeId] = button.textContent.trim();

        // Update price if needed (would need backend integration)
        updatePriceByVariation();
    }

    function selectColor(button) {
        // Remove active class from siblings
        button.parentElement.querySelectorAll('.color-option').forEach(opt => {
            opt.classList.remove('active');
        });

        // Add active class to selected color
        button.classList.add('active');

        // Store selection
        selectedVariations['color'] = button.getAttribute('title');
    }

    function updatePriceByVariation() {
        // This would typically make an AJAX call to get updated pricing
        // For now, we'll just log the selections
        console.log('Selected variations:', selectedVariations);
    }

    // Quantity controls
    function decreaseQuantity() {
        const quantityInput = document.getElementById('quantity');
        const currentValue = parseInt(quantityInput.value);
        if (currentValue > 1) {
            quantityInput.value = currentValue - 1;
            currentQuantity = currentValue - 1;
        }
    }

    function increaseQuantity() {
        const quantityInput = document.getElementById('quantity');
        const currentValue = parseInt(quantityInput.value);
        if (currentValue < maxStock) {
            quantityInput.value = currentValue + 1;
            currentQuantity = currentValue + 1;
        }
    }

    // Quantity input validation
    document.getElementById('quantity').addEventListener('change', function() {
        let value = parseInt(this.value);
        if (isNaN(value) || value < 1) {
            value = 1;
        } else if (value > maxStock) {
            value = maxStock;
        }
        this.value = value;
        currentQuantity = value;
    });

    // Add to cart function
    function addToCart(productId) {
        const quantity = document.getElementById('quantity').value;
        const addToCartBtn = document.querySelector('.add-to-cart-btn');
        const originalText = addToCartBtn.innerHTML;

        // Show loading state
        addToCartBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang thêm...';
        addToCartBtn.disabled = true;

        // Prepare cart data
        const cartData = {
            product_id: productId,
            quantity: quantity,
            variations: selectedVariations
        };

        // AJAX call to add to cart
        fetch('api/add-to-cart.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(cartData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Đã thêm sản phẩm vào giỏ hàng!', 'success');
                    updateCartCount();
                } else {
                    showNotification(data.message || 'Có lỗi xảy ra, vui lòng thử lại!', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Có lỗi xảy ra, vui lòng thử lại!', 'error');
            })
            .finally(() => {
                // Reset button
                addToCartBtn.innerHTML = originalText;
                addToCartBtn.disabled = false;
            });
    }

    // Buy now function
    function buyNow(productId) {
        const quantity = document.getElementById('quantity').value;
        const buyNowBtn = document.querySelector('.buy-now-btn');
        const originalText = buyNowBtn.innerHTML;

        // Show loading state
        buyNowBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang xử lý...';
        buyNowBtn.disabled = true;

        // For now, add to cart and redirect to checkout
        const cartData = {
            product_id: productId,
            quantity: quantity,
            variations: selectedVariations,
            buy_now: true
        };

        fetch('api/add-to-cart.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(cartData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Redirect to checkout
                    window.location.href = 'checkout.php';
                } else {
                    showNotification(data.message || 'Có lỗi xảy ra, vui lòng thử lại!', 'error');
                    buyNowBtn.innerHTML = originalText;
                    buyNowBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Có lỗi xảy ra, vui lòng thử lại!', 'error');
                buyNowBtn.innerHTML = originalText;
                buyNowBtn.disabled = false;
            });
    }

    // Tab switching
    function switchTab(tabButton, tabId) {
        // Remove active class from all tab buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });

        // Add active class to clicked button
        tabButton.classList.add('active');

        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });

        // Show selected tab content
        document.getElementById(tabId).classList.add('active');
    }

    // Wishlist toggle
    function toggleWishlist(productId) {
        const wishlistBtn = document.querySelector('.wishlist-btn i');
        const isActive = wishlistBtn.classList.contains('fas');

        // Toggle icon
        if (isActive) {
            wishlistBtn.classList.remove('fas');
            wishlistBtn.classList.add('far');
            showNotification('Đã xóa khỏi danh sách yêu thích', 'info');
        } else {
            wishlistBtn.classList.remove('far');
            wishlistBtn.classList.add('fas');
            showNotification('Đã thêm vào danh sách yêu thích', 'success');
        }

        // AJAX call to update wishlist (would need backend implementation)
        fetch('api/toggle-wishlist.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    product_id: productId,
                    action: isActive ? 'remove' : 'add'
                })
            })
            .catch(error => console.error('Wishlist error:', error));
    }

    // Share product
    function shareProduct() {
        if (navigator.share) {
            navigator.share({
                title: '<?php echo htmlspecialchars($product['name']); ?>',
                text: 'Xem sản phẩm này trên TikTok Shop',
                url: window.location.href
            });
        } else {
            // Fallback to copy URL
            navigator.clipboard.writeText(window.location.href).then(() => {
                showNotification('Đã sao chép link sản phẩm!', 'success');
            });
        }
    }

    // Navigation to related product
    function navigateToProduct(productId) {
        window.location.href = `product-detail.php?id=${productId}`;
    }

    // Update cart count (placeholder function)
    function updateCartCount() {
        // This would typically fetch the current cart count from the server
        const cartBadge = document.querySelector('.cart-badge');
        if (cartBadge) {
            let currentCount = parseInt(cartBadge.textContent) || 0;
            cartBadge.textContent = currentCount + 1;
        }
    }

    // Notification system
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        `;

        // Add to page
        document.body.appendChild(notification);

        // Auto remove after 3 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => notification.remove(), 300);
            }
        }, 3000);
    }

    // Keyboard     shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeImageModal();
        }
    });

    // Initializ    e page
    document.addEventListener('DOMContentLoaded', function() {
        // Set initial quantity
        currentQuantity = 1;

        // Initialize any default selections
        console.log('Product detail page loaded');

        // Check if user is logged in for certain features
        <?php if (!$user_id): ?>
        // Add event listeners to show login prompts for non-logged in users
        document.querySelector('.add-to-cart-btn').addEventListener('click', function(e) {
            e.preventDefault();
            if (confirm(
                    'Bạn cần đăng nhập để thêm sản phẩm vào giỏ hàng. Chuyển đến trang đăng nhập?')) {
                window.location.href = 'login.php?redirect=' + encodeURIComponent(window.location.href);
            }
        });

        document.querySelector('.buy-now-btn').addEventListener('click', function(e) {
            e.preventDefault();
            if (confirm('Bạn cần đăng nhập để mua hàng. Chuyển đến trang đăng nhập?')) {
                window.location.href = 'login.php?redirect=' + encodeURIComponent(window.location.href);
            }
        });
        <?php endif; ?>
    });

    // Smooth scrolling for in-page navigation
    function scrollToReviews() {
        document.getElementById('reviews').scrollIntoView({
            behavior: 'smooth'
        });
        switchTab(document.querySelector('[onclick*="reviews"]'), 'reviews');
    }

    // Image lazy loading for better performance
    const images = document.querySelectorAll('img');
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                    observer.unobserve(img);
                }
            }
        });
    });

    images.forEach(img => {
        if (img.dataset.src) {
            imageObserver.observe(img);
        }
    });
    </script>
</body>

</html>