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

$errors = [];
$success = '';

// Handle form submission
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'add_product') {
    // Validation
    $name = trim($_POST['name'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $brand_id = !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null;
    $description = trim($_POST['description'] ?? '');
    $unit_price = (float)($_POST['unit_price'] ?? 0);
    $discount = (float)($_POST['discount'] ?? 0);
    $discount_type = $_POST['discount_type'] ?? 'amount';
    $unit = trim($_POST['unit'] ?? 'cái');
    $weight = (float)($_POST['weight'] ?? 0);
    $current_stock = (int)($_POST['current_stock'] ?? 0);
    $min_qty = (int)($_POST['min_qty'] ?? 1);
    $low_stock_quantity = (int)($_POST['low_stock_quantity'] ?? 5);
    $tags = trim($_POST['tags'] ?? '');
    $shipping_type = $_POST['shipping_type'] ?? 'flat_rate';
    $shipping_cost = (float)($_POST['shipping_cost'] ?? 0);
    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $published = isset($_POST['published']) ? 1 : 0;
    $featured = isset($_POST['featured']) ? 1 : 0;

    // Basic validation
    if (empty($name)) {
        $errors[] = 'Tên sản phẩm không được để trống';
    }
    if ($category_id <= 0) {
        $errors[] = 'Vui lòng chọn danh mục sản phẩm';
    }
    if ($unit_price <= 0) {
        $errors[] = 'Giá sản phẩm phải lớn hơn 0';
    }
    if ($current_stock < 0) {
        $errors[] = 'Số lượng tồn kho không được âm';
    }
    if ($min_qty <= 0) {
        $errors[] = 'Số lượng tối thiểu phải lớn hơn 0';
    }
    if ($discount < 0) {
        $errors[] = 'Giảm giá không được âm';
    }
    if ($discount_type === 'percent' && $discount > 100) {
        $errors[] = 'Giảm giá theo phần trăm không được vượt quá 100%';
    }

    // Handle image uploads
    $uploaded_images = [];
    $thumbnail_id = null;

    if (empty($errors) && isset($_FILES['images'])) {
        $upload_dir = '../uploads/all/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5MB

        foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                $file_type = $_FILES['images']['type'][$key];
                $file_size = $_FILES['images']['size'][$key];
                $original_name = $_FILES['images']['name'][$key];

                if (!in_array($file_type, $allowed_types)) {
                    $errors[] = "File {$original_name} không đúng định dạng. Chỉ chấp nhận JPG, PNG, GIF.";
                    continue;
                }

                if ($file_size > $max_size) {
                    $errors[] = "File {$original_name} vượt quá kích thước cho phép (5MB).";
                    continue;
                }

                // Generate unique filename
                $extension = pathinfo($original_name, PATHINFO_EXTENSION);
                $new_filename = uniqid('product_' . $seller_id . '_') . '.' . $extension;
                $file_path = $upload_dir . $new_filename;

                if (move_uploaded_file($tmp_name, $file_path)) {
                    try {
                        // Save to uploads table
                        $stmt = $pdo->prepare("
                            INSERT INTO uploads (file_original_name, file_name, user_id, file_size, extension, type, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, 'image', NOW(), NOW())
                        ");
                        $stmt->execute([
                            $original_name,
                            $new_filename,
                            $seller_id,
                            $file_size,
                            $extension
                        ]);
                        
                        $upload_id = $pdo->lastInsertId();
                        $uploaded_images[] = $upload_id;
                        
                        // Set first image as thumbnail
                        if ($thumbnail_id === null) {
                            $thumbnail_id = $upload_id;
                        }
                    } catch (PDOException $e) {
                        $errors[] = "Lỗi lưu thông tin file {$original_name}";
                        unlink($file_path); // Delete uploaded file on error
                    }
                } else {
                    $errors[] = "Không thể upload file {$original_name}";
                }
            }
        }
    }

    // Create product if no errors
    if (empty($errors)) {
        try {
            // Generate slug
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
            $slug = preg_replace('/-+/', '-', $slug);
            $slug = trim($slug, '-');
            
            // Ensure unique slug
            $original_slug = $slug;
            $counter = 1;
            do {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE slug = ?");
                $stmt->execute([$slug]);
                $exists = $stmt->fetchColumn() > 0;
                
                if ($exists) {
                    $slug = $original_slug . '-' . $counter;
                    $counter++;
                }
            } while ($exists);

            // Set meta fields if empty
            if (empty($meta_title)) {
                $meta_title = $name;
            }
            if (empty($meta_description)) {
                $meta_description = substr(strip_tags($description), 0, 160);
            }

            // Insert product
            $stmt = $pdo->prepare("
                INSERT INTO products (
                    name, added_by, user_id, category_id, brand_id, photos, thumbnail_img,
                    description, unit_price, discount, discount_type, unit, weight,
                    current_stock, min_qty, low_stock_quantity, tags, slug,
                    shipping_type, shipping_cost, meta_title, meta_description,
                    published, featured, approved, created_at, updated_at
                ) VALUES (
                    ?, 'seller', ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, 1, NOW(), NOW()
                )
            ");
            
            $photos_json = !empty($uploaded_images) ? json_encode($uploaded_images) : null;
            
            $stmt->execute([
                $name, $seller_id, $category_id, $brand_id, $photos_json, $thumbnail_id,
                $description, $unit_price, $discount, $discount_type, $unit, $weight,
                $current_stock, $min_qty, $low_stock_quantity, $tags, $slug,
                $shipping_type, $shipping_cost, $meta_title, $meta_description,
                $published, $featured
            ]);

            $product_id = $pdo->lastInsertId();
            $success = "Sản phẩm đã được thêm thành công!";
            
            // Redirect to edit page or products list
            header("Location: products.php?success=added&id={$product_id}");
            exit;

        } catch (PDOException $e) {
            $errors[] = "Lỗi tạo sản phẩm: " . $e->getMessage();
        }
    }
}

// Get categories for dropdown
try {
    $stmt = $pdo->prepare("SELECT id, name, parent_id FROM categories WHERE parent_id = 0 ORDER BY name");
    $stmt->execute();
    $main_categories = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT id, name, parent_id FROM categories WHERE parent_id > 0 ORDER BY parent_id, name");
    $stmt->execute();
    $sub_categories = $stmt->fetchAll();
    
    // Group subcategories by parent
    $categories_grouped = [];
    foreach ($main_categories as $cat) {
        $categories_grouped[$cat['id']] = [
            'info' => $cat,
            'children' => []
        ];
    }
    foreach ($sub_categories as $sub) {
        if (isset($categories_grouped[$sub['parent_id']])) {
            $categories_grouped[$sub['parent_id']]['children'][] = $sub;
        }
    }
} catch (PDOException $e) {
    $categories_grouped = [];
}

// Get brands for dropdown
try {
    $stmt = $pdo->prepare("SELECT id, name FROM brands ORDER BY name");
    $stmt->execute();
    $brands = $stmt->fetchAll();
} catch (PDOException $e) {
    $brands = [];
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thêm sản phẩm mới - TikTok Shop Seller</title>
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
        padding: 16px 24px;
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

    .breadcrumb a:hover {
        text-decoration: underline;
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

    .content-wrapper {
        padding: 24px;
        max-width: 1200px;
        margin: 0 auto;
    }

    /* Form Container */
    .form-container {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    .form-header {
        padding: 24px;
        border-bottom: 1px solid #e5e7eb;
        background: #f8f9fa;
    }

    .form-title {
        font-size: 20px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 8px;
    }

    .form-subtitle {
        color: #6b7280;
        font-size: 14px;
    }

    /* Tabs */
    .form-tabs {
        display: flex;
        border-bottom: 1px solid #e5e7eb;
    }

    .tab-btn {
        padding: 16px 24px;
        background: none;
        border: none;
        font-size: 14px;
        font-weight: 500;
        color: #6b7280;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        transition: all 0.3s ease;
    }

    .tab-btn.active {
        color: #ff0050;
        border-bottom-color: #ff0050;
        background: #fef2f2;
    }

    .tab-btn:hover {
        color: #ff0050;
        background: #f9fafb;
    }

    /* Form Content */
    .form-content {
        padding: 32px;
    }

    .tab-pane {
        display: none;
    }

    .tab-pane.active {
        display: block;
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-bottom: 24px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .form-group.full-width {
        grid-column: 1 / -1;
    }

    .form-label {
        font-size: 14px;
        font-weight: 500;
        color: #374151;
    }

    .form-label.required::after {
        content: '*';
        color: #ef4444;
        margin-left: 4px;
    }

    .form-input,
    .form-select,
    .form-textarea {
        padding: 12px 16px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        transition: border-color 0.3s ease;
        background: white;
    }

    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
        outline: none;
        border-color: #ff0050;
        box-shadow: 0 0 0 3px rgba(255, 0, 80, 0.1);
    }

    .form-textarea {
        min-height: 120px;
        resize: vertical;
    }

    .form-help {
        font-size: 12px;
        color: #9ca3af;
    }

    /* Image Upload */
    .image-upload-area {
        border: 2px dashed #d1d5db;
        border-radius: 12px;
        padding: 32px;
        text-align: center;
        background: #f9fafb;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .image-upload-area:hover {
        border-color: #ff0050;
        background: #fef2f2;
    }

    .image-upload-area.dragover {
        border-color: #ff0050;
        background: #fef2f2;
    }

    .upload-icon {
        font-size: 48px;
        color: #9ca3af;
        margin-bottom: 16px;
    }

    .upload-text {
        font-size: 16px;
        font-weight: 500;
        color: #374151;
        margin-bottom: 8px;
    }

    .upload-help {
        font-size: 14px;
        color: #6b7280;
    }

    .image-preview {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 16px;
        margin-top: 16px;
    }

    .preview-item {
        position: relative;
        border-radius: 8px;
        overflow: hidden;
        border: 1px solid #e5e7eb;
    }

    .preview-image {
        width: 100%;
        height: 120px;
        object-fit: cover;
    }

    .preview-remove {
        position: absolute;
        top: 4px;
        right: 4px;
        background: rgba(0, 0, 0, 0.7);
        color: white;
        border: none;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        cursor: pointer;
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .preview-remove:hover {
        background: #ef4444;
    }

    /* Checkbox and Radio */
    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .checkbox-input {
        width: 18px;
        height: 18px;
        accent-color: #ff0050;
    }

    .radio-group {
        display: flex;
        gap: 16px;
    }

    .radio-item {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .radio-input {
        width: 16px;
        height: 16px;
        accent-color: #ff0050;
    }

    /* Buttons */
    .btn {
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .btn-primary {
        background: #ff0050;
        color: white;
    }

    .btn-primary:hover {
        background: #cc0040;
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: #f3f4f6;
        color: #4b5563;
        border: 1px solid #d1d5db;
    }

    .btn-secondary:hover {
        background: #e5e7eb;
    }

    .btn-outline {
        background: transparent;
        color: #ff0050;
        border: 1px solid #ff0050;
    }

    .btn-outline:hover {
        background: #ff0050;
        color: white;
    }

    .form-actions {
        display: flex;
        gap: 12px;
        padding-top: 24px;
        border-top: 1px solid #e5e7eb;
        justify-content: flex-end;
    }

    /* Alerts */
    .alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        font-size: 14px;
    }

    .alert-error {
        background: #fef2f2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }

    .alert-success {
        background: #f0fdf4;
        color: #166534;
        border: 1px solid #bbf7d0;
    }

    .alert ul {
        margin: 0;
        padding-left: 20px;
    }

    .alert li {
        margin-bottom: 4px;
    }

    /* Price Input with Currency */
    .price-input-group {
        position: relative;
    }

    .price-input {
        padding-right: 40px;
    }

    .currency-symbol {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        font-weight: 500;
    }

    /* Discount Group */
    .discount-group {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 12px;
        align-items: end;
    }

    /* Shipping Options */
    .shipping-options {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .shipping-option {
        padding: 16px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .shipping-option:hover {
        border-color: #ff0050;
    }

    .shipping-option.selected {
        border-color: #ff0050;
        background: #fef2f2;
    }

    .shipping-option-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 8px;
    }

    .shipping-option-title {
        font-weight: 500;
        color: #374151;
    }

    .shipping-option-desc {
        font-size: 12px;
        color: #6b7280;
    }

    /* Loading State */
    .loading {
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

    /* Responsive */
    @media (max-width: 768px) {
        .main-content {
            margin-left: 0;
        }

        .mobile-menu-btn {
            display: block;
        }

        .content-wrapper {
            padding: 16px;
        }

        .form-content {
            padding: 20px;
        }

        .form-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }

        .form-tabs {
            overflow-x: auto;
        }

        .tab-btn {
            padding: 12px 16px;
            white-space: nowrap;
        }

        .form-actions {
            flex-direction: column;
        }

        .discount-group {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .image-preview {
            grid-template-columns: repeat(2, 1fr);
        }

        .radio-group {
            flex-direction: column;
            gap: 8px;
        }
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
                        <h1>Thêm sản phẩm mới</h1>
                        <div class="breadcrumb">
                            <a href="dashboard.php">Dashboard</a>
                            <span>›</span>
                            <a href="products.php">Sản phẩm</a>
                            <span>›</span>
                            <span>Thêm mới</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-wrapper">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>❌ Có lỗi xảy ra:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>✅ <?php echo htmlspecialchars($success); ?></strong>
                </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="productForm">
                    <input type="hidden" name="action" value="add_product">

                    <div class="form-container">
                        <div class="form-header">
                            <h2 class="form-title">Thông tin sản phẩm</h2>
                            <p class="form-subtitle">Điền đầy đủ thông tin để tạo sản phẩm mới</p>
                        </div>

                        <div class="form-tabs">
                            <button type="button" class="tab-btn active" onclick="showTab('basic')">
                                📝 Thông tin cơ bản
                            </button>
                            <button type="button" class="tab-btn" onclick="showTab('images')">
                                🖼️ Hình ảnh
                            </button>
                            <button type="button" class="tab-btn" onclick="showTab('pricing')">
                                💰 Giá & Khuyến mãi
                            </button>
                            <button type="button" class="tab-btn" onclick="showTab('inventory')">
                                📦 Kho hàng
                            </button>
                            <button type="button" class="tab-btn" onclick="showTab('shipping')">
                                🚚 Vận chuyển
                            </button>
                            <button type="button" class="tab-btn" onclick="showTab('seo')">
                                🔍 SEO & Hiển thị
                            </button>
                        </div>

                        <div class="form-content">
                            <!-- Basic Information Tab -->
                            <div id="basic" class="tab-pane active">
                                <div class="form-grid">
                                    <div class="form-group full-width">
                                        <label class="form-label required">Tên sản phẩm</label>
                                        <input type="text" name="name" class="form-input"
                                            placeholder="Nhập tên sản phẩm..."
                                            value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                                        <div class="form-help">Tên sản phẩm nên rõ ràng, dễ hiểu và có từ khóa</div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Danh mục</label>
                                        <select name="category_id" class="form-select" required>
                                            <option value="">Chọn danh mục</option>
                                            <?php foreach ($categories_grouped as $parent_id => $cat_data): ?>
                                            <option value="<?php echo $parent_id; ?>"
                                                <?php echo (($_POST['category_id'] ?? 0) == $parent_id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat_data['info']['name']); ?>
                                            </option>
                                            <?php foreach ($cat_data['children'] as $child): ?>
                                            <option value="<?php echo $child['id']; ?>"
                                                <?php echo (($_POST['category_id'] ?? 0) == $child['id']) ? 'selected' : ''; ?>>
                                                &nbsp;&nbsp;└ <?php echo htmlspecialchars($child['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Thương hiệu</label>
                                        <select name="brand_id" class="form-select">
                                            <option value="">Chọn thương hiệu (tùy chọn)</option>
                                            <?php foreach ($brands as $brand): ?>
                                            <option value="<?php echo $brand['id']; ?>"
                                                <?php echo (($_POST['brand_id'] ?? 0) == $brand['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($brand['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group full-width">
                                        <label class="form-label">Mô tả sản phẩm</label>
                                        <textarea name="description" class="form-textarea"
                                            placeholder="Mô tả chi tiết về sản phẩm..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                                        <div class="form-help">Mô tả chi tiết sẽ giúp khách hàng hiểu rõ hơn về sản phẩm
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Đơn vị tính</label>
                                        <input type="text" name="unit" class="form-input"
                                            placeholder="cái, chiếc, bộ..."
                                            value="<?php echo htmlspecialchars($_POST['unit'] ?? 'cái'); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Trọng lượng (gram)</label>
                                        <input type="number" name="weight" class="form-input" placeholder="0"
                                            step="0.01" min="0"
                                            value="<?php echo htmlspecialchars($_POST['weight'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group full-width">
                                        <label class="form-label">Tags (từ khóa)</label>
                                        <input type="text" name="tags" class="form-input"
                                            placeholder="áo, thời trang, nam, cotton..."
                                            value="<?php echo htmlspecialchars($_POST['tags'] ?? ''); ?>">
                                        <div class="form-help">Các từ khóa cách nhau bởi dấu phẩy, giúp khách hàng tìm
                                            thấy sản phẩm dễ hơn</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Images Tab -->
                            <div id="images" class="tab-pane">
                                <div class="form-group">
                                    <label class="form-label">Hình ảnh sản phẩm</label>
                                    <div class="image-upload-area"
                                        onclick="document.getElementById('imageInput').click()">
                                        <div class="upload-icon">📷</div>
                                        <div class="upload-text">Kéo thả hoặc click để chọn ảnh</div>
                                        <div class="upload-help">
                                            Chấp nhận JPG, PNG, GIF. Tối đa 5MB mỗi ảnh.<br>
                                            Ảnh đầu tiên sẽ làm ảnh đại diện.
                                        </div>
                                    </div>
                                    <input type="file" id="imageInput" name="images[]" multiple accept="image/*"
                                        style="display: none;">
                                    <div id="imagePreview" class="image-preview"></div>
                                </div>
                            </div>

                            <!-- Pricing Tab -->
                            <div id="pricing" class="tab-pane">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label required">Giá bán</label>
                                        <div class="price-input-group">
                                            <input type="number" name="unit_price" class="form-input price-input"
                                                placeholder="0" step="1000" min="0" required
                                                value="<?php echo htmlspecialchars($_POST['unit_price'] ?? ''); ?>">
                                            <span class="currency-symbol">đ</span>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Giảm giá</label>
                                        <div class="discount-group">
                                            <input type="number" name="discount" class="form-input" placeholder="0"
                                                min="0" step="0.01"
                                                value="<?php echo htmlspecialchars($_POST['discount'] ?? ''); ?>">
                                            <select name="discount_type" class="form-select">
                                                <option value="amount"
                                                    <?php echo (($_POST['discount_type'] ?? 'amount') === 'amount') ? 'selected' : ''; ?>>
                                                    VNĐ</option>
                                                <option value="percent"
                                                    <?php echo (($_POST['discount_type'] ?? 'amount') === 'percent') ? 'selected' : ''; ?>>
                                                    %</option>
                                            </select>
                                        </div>
                                        <div class="form-help">Để trống nếu không có giảm giá</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Inventory Tab -->
                            <div id="inventory" class="tab-pane">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label required">Số lượng tồn kho</label>
                                        <input type="number" name="current_stock" class="form-input" placeholder="0"
                                            min="0" required
                                            value="<?php echo htmlspecialchars($_POST['current_stock'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Số lượng tối thiểu</label>
                                        <input type="number" name="min_qty" class="form-input" placeholder="1" min="1"
                                            value="<?php echo htmlspecialchars($_POST['min_qty'] ?? '1'); ?>">
                                        <div class="form-help">Số lượng tối thiểu khách hàng phải mua</div>
                                    </div>

                                    <div class="form-group full-width">
                                        <label class="form-label">Cảnh báo hết hàng</label>
                                        <input type="number" name="low_stock_quantity" class="form-input"
                                            placeholder="5" min="0"
                                            value="<?php echo htmlspecialchars($_POST['low_stock_quantity'] ?? '5'); ?>">
                                        <div class="form-help">Hệ thống sẽ cảnh báo khi tồn kho dưới mức này</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Shipping Tab -->
                            <div id="shipping" class="tab-pane">
                                <div class="form-group">
                                    <label class="form-label">Phương thức vận chuyển</label>
                                    <div class="shipping-options">
                                        <div class="shipping-option selected" onclick="selectShipping('flat_rate')">
                                            <div class="shipping-option-header">
                                                <input type="radio" name="shipping_type" value="flat_rate"
                                                    class="radio-input" checked>
                                                <span class="shipping-option-title">Phí cố định</span>
                                            </div>
                                            <div class="shipping-option-desc">Áp dụng mức phí vận chuyển cố định</div>
                                        </div>

                                        <div class="shipping-option" onclick="selectShipping('free')">
                                            <div class="shipping-option-header">
                                                <input type="radio" name="shipping_type" value="free"
                                                    class="radio-input">
                                                <span class="shipping-option-title">Miễn phí vận chuyển</span>
                                            </div>
                                            <div class="shipping-option-desc">Không thu phí vận chuyển</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group" id="shippingCostGroup">
                                    <label class="form-label">Phí vận chuyển</label>
                                    <div class="price-input-group">
                                        <input type="number" name="shipping_cost" class="form-input price-input"
                                            placeholder="0" min="0" step="1000"
                                            value="<?php echo htmlspecialchars($_POST['shipping_cost'] ?? ''); ?>">
                                        <span class="currency-symbol">đ</span>
                                    </div>
                                </div>
                            </div>

                            <!-- SEO & Display Tab -->
                            <div id="seo" class="tab-pane">
                                <div class="form-grid">
                                    <div class="form-group full-width">
                                        <label class="form-label">Tiêu đề SEO</label>
                                        <input type="text" name="meta_title" class="form-input"
                                            placeholder="Để trống sẽ sử dụng tên sản phẩm"
                                            value="<?php echo htmlspecialchars($_POST['meta_title'] ?? ''); ?>">
                                        <div class="form-help">Nên từ 50-60 ký tự</div>
                                    </div>

                                    <div class="form-group full-width">
                                        <label class="form-label">Mô tả SEO</label>
                                        <textarea name="meta_description" class="form-textarea"
                                            placeholder="Mô tả ngắn gọn cho công cụ tìm kiếm..."><?php echo htmlspecialchars($_POST['meta_description'] ?? ''); ?></textarea>
                                        <div class="form-help">Nên từ 150-160 ký tự</div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Trạng thái hiển thị</label>
                                        <div class="checkbox-group">
                                            <input type="checkbox" name="published" id="published"
                                                class="checkbox-input"
                                                <?php echo isset($_POST['published']) ? 'checked' : ''; ?>>
                                            <label for="published">Hiển thị ngay sau khi tạo</label>
                                        </div>
                                        <div class="form-help">Nếu không chọn, sản phẩm sẽ ở trạng thái ẩn</div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Sản phẩm nổi bật</label>
                                        <div class="checkbox-group">
                                            <input type="checkbox" name="featured" id="featured" class="checkbox-input"
                                                <?php echo isset($_POST['featured']) ? 'checked' : ''; ?>>
                                            <label for="featured">Đánh dấu là sản phẩm nổi bật</label>
                                        </div>
                                        <div class="form-help">Sản phẩm nổi bật sẽ hiển thị ở vị trí ưu tiên</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="form-actions">
                                <a href="products.php" class="btn btn-secondary">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" />
                                    </svg>
                                    Hủy
                                </a>
                                <button type="button" class="btn btn-outline" onclick="saveDraft()">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                        <path
                                            d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z" />
                                    </svg>
                                    Lưu nháp
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                                    </svg>
                                    Tạo sản phẩm
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Tab switching
    function showTab(tabName) {
        // Hide all tabs
        document.querySelectorAll('.tab-pane').forEach(pane => {
            pane.classList.remove('active');
        });
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });

        // Show selected tab
        document.getElementById(tabName).classList.add('active');
        event.target.classList.add('active');
    }

    // Image upload handling
    let selectedFiles = [];

    document.getElementById('imageInput').addEventListener('change', function(e) {
        handleFiles(e.target.files);
    });

    // Drag and drop
    const uploadArea = document.querySelector('.image-upload-area');

    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });

    function handleFiles(files) {
        const preview = document.getElementById('imagePreview');

        Array.from(files).forEach((file, index) => {
            if (!file.type.startsWith('image/')) {
                alert('Chỉ chấp nhận file ảnh!');
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                alert(`File ${file.name} quá lớn! Tối đa 5MB.`);
                return;
            }

            selectedFiles.push(file);

            const reader = new FileReader();
            reader.onload = function(e) {
                const previewItem = document.createElement('div');
                previewItem.className = 'preview-item';
                previewItem.innerHTML = `
                        <img src="${e.target.result}" alt="Preview" class="preview-image">
                        <button type="button" class="preview-remove" onclick="removeImage(${selectedFiles.length - 1})">×</button>
                    `;
                preview.appendChild(previewItem);
            };
            reader.readAsDataURL(file);
        });

        updateFileInput();
    }

    function removeImage(index) {
        selectedFiles.splice(index, 1);
        updatePreview();
        updateFileInput();
    }

    function updatePreview() {
        const preview = document.getElementById('imagePreview');
        preview.innerHTML = '';

        selectedFiles.forEach((file, index) => {
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewItem = document.createElement('div');
                previewItem.className = 'preview-item';
                previewItem.innerHTML = `
                        <img src="${e.target.result}" alt="Preview" class="preview-image">
                        <button type="button" class="preview-remove" onclick="removeImage(${index})">×</button>
                    `;
                preview.appendChild(previewItem);
            };
            reader.readAsDataURL(file);
        });
    }

    function updateFileInput() {
        const dt = new DataTransfer();
        selectedFiles.forEach(file => dt.items.add(file));
        document.getElementById('imageInput').files = dt.files;
    }

    // Shipping type selection
    function selectShipping(type) {
        document.querySelectorAll('.shipping-option').forEach(option => {
            option.classList.remove('selected');
        });

        event.currentTarget.classList.add('selected');
        document.querySelector(`input[value="${type}"]`).checked = true;

        const costGroup = document.getElementById('shippingCostGroup');
        if (type === 'free') {
            costGroup.style.display = 'none';
            document.querySelector('input[name="shipping_cost"]').value = '0';
        } else {
            costGroup.style.display = 'block';
        }
    }

    // Form validation
    document.getElementById('productForm').addEventListener('submit', function(e) {
        const requiredFields = document.querySelectorAll('[required]');
        let isValid = true;

        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                field.style.borderColor = '#ef4444';
                isValid = false;
            } else {
                field.style.borderColor = '#d1d5db';
            }
        });

        if (!isValid) {
            e.preventDefault();
            alert('Vui lòng điền đầy đủ các trường bắt buộc!');
            return;
        }

        // Show loading state
        const submitBtn = document.querySelector('button[type="submit"]');
        submitBtn.classList.add('loading');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span>Đang tạo sản phẩm...</span>';
    });

    // Save draft function
    function saveDraft() {
        const form = document.getElementById('productForm');
        const formData = new FormData(form);
        formData.set('action', 'save_draft');

        // Here you would implement draft saving functionality
        alert('Tính năng lưu nháp sẽ được phát triển trong phiên bản tiếp theo!');
    }

    // Auto-generate slug from product name
    document.querySelector('input[name="name"]').addEventListener('input', function(e) {
        // This would generate a slug, but since we don't have a slug field in the form,
        // we'll just log it for demonstration
        const slug = e.target.value
            .toLowerCase()
            .replace(/[^a-z0-9 -]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .trim();
        console.log('Generated slug:', slug);
    });

    // Auto-resize textarea
    document.querySelectorAll('.form-textarea').forEach(textarea => {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    });

    // Number input formatting
    document.querySelectorAll('input[type="number"]').forEach(input => {
        input.addEventListener('blur', function() {
            if (this.value && this.step === '1000') {
                // Format price inputs
                this.value = Math.round(parseFloat(this.value));
            }
        });
    });

    // Sidebar toggle for mobile
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            sidebar.classList.toggle('open');
        }
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        // Set default shipping cost visibility
        const shippingType = document.querySelector('input[name="shipping_type"]:checked').value;
        if (shippingType === 'free') {
            document.getElementById('shippingCostGroup').style.display = 'none';
        }
    });
    </script>
</body>

</html>