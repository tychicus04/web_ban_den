<?php
/**
 * Admin Product Edit Page
 *
 * @refactored Uses centralized admin_init.php for authentication and helpers
 */

// Initialize admin page with authentication and admin info
require_once __DIR__ . '/../includes/admin_init.php';
$admin = initAdminPage(true, true);
$db = getDB();

// Get product ID
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$product = null;
$product_images = [];
$is_edit = $product_id > 0;

// Handle AJAX image upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    try {
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Không có file được upload hoặc có lỗi xảy ra');
        }
        
        $file = $_FILES['image'];
        $uploadDir = '../uploads/all/';
        
        // Create directory if not exists
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('Chỉ chấp nhận file ảnh (JPG, PNG, GIF, WEBP)');
        }
        
        // Validate file size (5MB max)
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception('File ảnh không được vượt quá 5MB');
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'product_' . time() . '_' . rand(1000, 9999) . '.' . $extension;
        $filePath = $uploadDir . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception('Không thể lưu file');
        }
        
        // Insert into uploads table
        $stmt = $db->prepare("
            INSERT INTO uploads (file_original_name, file_name, user_id, file_size, extension, type) 
            VALUES (?, ?, ?, ?, ?, 'image')
        ");
        $stmt->execute([
            $file['name'],
            'uploads/all/' . $filename,
            $_SESSION['user_id'],
            $file['size'],
            $extension
        ]);
        
        $uploadId = $db->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'upload_id' => $uploadId,
            'filename' => $filename,
            'url' => 'uploads/all/' . $filename,
            'message' => 'Upload ảnh thành công'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX delete image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_image') {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    try {
        $uploadId = (int)$_POST['upload_id'];
        
        // Get file info
        $stmt = $db->prepare("SELECT file_name FROM uploads WHERE id = ?");
        $stmt->execute([$uploadId]);
        $upload = $stmt->fetch();
        
        if ($upload) {
            // Delete physical file
            $filePath = '../' . $upload['file_name'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            // Delete from database
            $stmt = $db->prepare("DELETE FROM uploads WHERE id = ?");
            $stmt->execute([$uploadId]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Xóa ảnh thành công']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// If editing, get product data and images
if ($is_edit) {
    try {
        $stmt = $db->prepare("
            SELECT p.*,
                   c.name as category_name,
                   u_thumb.file_name as thumbnail_url
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN uploads u_thumb ON p.thumbnail_img = u_thumb.id
            WHERE p.id = ? LIMIT 1
        ");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        
        if (!$product) {
            header('Location: products.php?error=product_not_found');
            exit;
        }
        
        // Get product gallery images
        if (!empty($product['photos'])) {
            $imageIds = explode(',', $product['photos']);
            $imageIds = array_filter(array_map('intval', $imageIds));
            
            if (!empty($imageIds)) {
                $placeholders = str_repeat('?,', count($imageIds) - 1) . '?';
                $stmt = $db->prepare("SELECT id, file_name, file_original_name FROM uploads WHERE id IN ($placeholders)");
                $stmt->execute($imageIds);
                $product_images = $stmt->fetchAll();
            }
        }
        
    } catch (PDOException $e) {
        error_log("Product fetch error: " . $e->getMessage());
        header('Location: products.php?error=database_error');
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_product') {
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        $error = 'Invalid CSRF token';
    } else {
        try {
            // Validate required fields
            $name = trim($_POST['name'] ?? '');
            $category_id = (int)($_POST['category_id'] ?? 0);
            $unit_price = (float)($_POST['unit_price'] ?? 0);
            $current_stock = (int)($_POST['current_stock'] ?? 0);
            
            if (empty($name)) {
                throw new Exception('Tên sản phẩm không được để trống');
            }
            if ($category_id <= 0) {
                throw new Exception('Vui lòng chọn danh mục');
            }
            if ($unit_price <= 0) {
                throw new Exception('Giá sản phẩm phải lớn hơn 0');
            }
            
            // Handle images
            $thumbnail_img = null;
            $photos = '';
            
            // Handle thumbnail
            if (!empty($_POST['thumbnail_img'])) {
                $thumbnail_img = (int)$_POST['thumbnail_img'];
            }
            
            // Handle gallery images
            if (!empty($_POST['gallery_images'])) {
                $galleryIds = array_filter(array_map('intval', explode(',', $_POST['gallery_images'])));
                $photos = implode(',', $galleryIds);
            }
            
            // Prepare data for insert/update
            $data = [
                'name' => $name,
                'category_id' => $category_id,
                'unit_price' => $unit_price,
                'current_stock' => $current_stock,
                'min_qty' => (int)($_POST['min_qty'] ?? 1),
                'low_stock_quantity' => (int)($_POST['low_stock_quantity'] ?? 10),
                'unit' => trim($_POST['unit'] ?? ''),
                'tags' => trim($_POST['tags'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'weight' => (float)($_POST['weight'] ?? 0),
                'published' => isset($_POST['published']) ? 1 : 0,
                'approved' => isset($_POST['approved']) ? 1 : 0,
                'featured' => isset($_POST['featured']) ? 1 : 0,
                'todays_deal' => isset($_POST['todays_deal']) ? 1 : 0,
                'cash_on_delivery' => isset($_POST['cash_on_delivery']) ? 1 : 0,
                'refundable' => isset($_POST['refundable']) ? 1 : 0,
                'digital' => isset($_POST['digital']) ? 1 : 0,
                'discount' => (float)($_POST['discount'] ?? 0),
                'discount_type' => $_POST['discount_type'] ?? 'amount',
                'tax' => (float)($_POST['tax'] ?? 0),
                'tax_type' => $_POST['tax_type'] ?? 'percent',
                'shipping_type' => $_POST['shipping_type'] ?? 'flat_rate',
                'shipping_cost' => (float)($_POST['shipping_cost'] ?? 0),
                'meta_title' => trim($_POST['meta_title'] ?? ''),
                'meta_description' => trim($_POST['meta_description'] ?? ''),
                'video_provider' => $_POST['video_provider'] ?? '',
                'video_link' => trim($_POST['video_link'] ?? ''),
                'external_link' => trim($_POST['external_link'] ?? ''),
                'external_link_btn' => trim($_POST['external_link_btn'] ?? 'Buy Now'),
                'barcode' => trim($_POST['barcode'] ?? ''),
                'earn_point' => (float)($_POST['earn_point'] ?? 0),
                'thumbnail_img' => $thumbnail_img,
                'photos' => $photos,
            ];
            
            // Generate slug if new product
            if (!$is_edit) {
                $data['slug'] = generateSlug($name);
                $data['user_id'] = $_SESSION['user_id'];
                $data['added_by'] = 'admin';
            }
            
            // Handle discount dates
            if (!empty($_POST['discount_start_date'])) {
                $data['discount_start_date'] = strtotime($_POST['discount_start_date']);
            }
            if (!empty($_POST['discount_end_date'])) {
                $data['discount_end_date'] = strtotime($_POST['discount_end_date']);
            }
            
            if ($is_edit) {
                // Update existing product
                $set_clauses = [];
                $params = [];
                
                foreach ($data as $key => $value) {
                    $set_clauses[] = "$key = ?";
                    $params[] = $value;
                }
                
                $params[] = $product_id;
                
                $sql = "UPDATE products SET " . implode(', ', $set_clauses) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                
                $success = 'Cập nhật sản phẩm thành công';
                
            } else {
                // Insert new product
                $columns = array_keys($data);
                $placeholders = str_repeat('?,', count($data) - 1) . '?';
                
                $sql = "INSERT INTO products (" . implode(', ', $columns) . ") VALUES ($placeholders)";
                $stmt = $db->prepare($sql);
                $stmt->execute(array_values($data));
                
                $product_id = $db->lastInsertId();
                
                $success = 'Thêm sản phẩm thành công';
                
                // Redirect to edit page
                header("Location: product-edit.php?id=$product_id&success=" . urlencode($success));
                exit;
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log("Product save error: " . $e->getMessage());
            $error = 'Có lỗi xảy ra khi lưu sản phẩm';
        }
    }
}

// Get categories for dropdown
$categories = [];
try {
    $stmt = $db->query("SELECT id, name, parent_id FROM categories ORDER BY name ASC");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Categories fetch error: " . $e->getMessage());
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit ? 'Chỉnh sửa sản phẩm' : 'Thêm sản phẩm mới'; ?> - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="<?php echo $is_edit ? 'Chỉnh sửa sản phẩm' : 'Thêm sản phẩm mới'; ?> - Admin <?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../asset/css/pages/admin-sidebar.css">
    <link rel="stylesheet" href="../asset/css/pages/admin-product-edit.css">
</head>

<body>
    <div class="layout">
        <!-- Sidebar -->
        <?php require_once __DIR__ . '/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <button class="sidebar-toggle" id="sidebar-toggle">☰</button>
                    <nav class="breadcrumb">
                        <a href="dashboard.php">Admin</a>
                        <span class="breadcrumb-separator">›</span>
                        <a href="products.php">Sản phẩm</a>
                        <span class="breadcrumb-separator">›</span>
                        <span><?php echo $is_edit ? 'Chỉnh sửa' : 'Thêm mới'; ?></span>
                    </nav>
                </div>
                
                <div class="header-right">
                    <div class="user-menu">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($admin['name'] ?? 'A', 0, 2)); ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($admin['name'] ?? 'Admin'); ?></div>
                            <div class="user-role">Administrator</div>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- Content -->
            <div class="content">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <?php echo $is_edit ? 'Chỉnh sửa sản phẩm' : 'Thêm sản phẩm mới'; ?>
                        <?php if ($is_edit): ?>
                            <small style="font-size: 1.25rem; color: var(--gray-500); font-weight: 400;">
                                #<?php echo $product['id']; ?>
                            </small>
                        <?php endif; ?>
                    </h1>
                    <p class="page-subtitle">
                        <?php echo $is_edit ? 'Cập nhật thông tin sản phẩm' : 'Thêm sản phẩm mới vào hệ thống'; ?>
                    </p>
                </div>
                
                <!-- Alerts -->
                <?php if (isset($success)): ?>
                    <div class="alert alert-success">
                        <span>✅</span>
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-error">
                        <span>❌</span>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>
                
                <!-- Form Container -->
                <form method="POST" class="form-container" id="product-form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_product">
                    <input type="hidden" name="token" value="<?php echo $_SESSION['admin_token']; ?>">
                    
                    <!-- Tabs -->
                    <div class="tabs">
                        <button type="button" class="tab-button active" data-tab="basic">
                            <span>📝</span>
                            <span>Thông tin cơ bản</span>
                        </button>
                        <button type="button" class="tab-button" data-tab="images">
                            <span>🖼️</span>
                            <span>Hình ảnh</span>
                        </button>
                        <button type="button" class="tab-button" data-tab="details">
                            <span>📋</span>
                            <span>Chi tiết</span>
                        </button>
                        <button type="button" class="tab-button" data-tab="pricing">
                            <span>💰</span>
                            <span>Giá & Kho</span>
                        </button>
                        <button type="button" class="tab-button" data-tab="seo">
                            <span>🔍</span>
                            <span>SEO</span>
                        </button>
                    </div>
                    
                    <!-- Basic Information Tab -->
                    <div class="tab-content active" id="basic-tab">
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label for="name" class="form-label required">Tên sản phẩm</label>
                                <input type="text" id="name" name="name" class="form-input" 
                                       value="<?php echo htmlspecialchars($product['name'] ?? ''); ?>" required maxlength="200">
                                <div class="form-help">Tên sản phẩm sẽ hiển thị cho khách hàng</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="category_id" class="form-label required">Danh mục</label>
                                <select id="category_id" name="category_id" class="form-select" required>
                                    <option value="">Chọn danh mục</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" 
                                            <?php echo isset($product) && $product['category_id'] == $category['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="unit" class="form-label">Đơn vị</label>
                                <input type="text" id="unit" name="unit" class="form-input" 
                                       value="<?php echo htmlspecialchars($product['unit'] ?? ''); ?>"
                                       placeholder="VD: cái, kg, lít...">
                            </div>
                            
                            <div class="form-group">
                                <label for="barcode" class="form-label">Mã vạch</label>
                                <input type="text" id="barcode" name="barcode" class="form-input" 
                                       value="<?php echo htmlspecialchars($product['barcode'] ?? ''); ?>"
                                       placeholder="Mã vạch sản phẩm">
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="tags" class="form-label">Tags</label>
                                <input type="text" id="tags" name="tags" class="form-input" 
                                       value="<?php echo htmlspecialchars($product['tags'] ?? ''); ?>"
                                       placeholder="Phân cách bằng dấu phẩy">
                                <div class="form-help">Các từ khóa giúp khách hàng tìm thấy sản phẩm dễ dàng</div>
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="description" class="form-label">Mô tả sản phẩm</label>
                                <textarea id="description" name="description" class="form-textarea" rows="6"
                                          placeholder="Mô tả chi tiết về sản phẩm..."><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Images Tab -->
                    <div class="tab-content" id="images-tab">
                        <input type="hidden" id="thumbnail_img" name="thumbnail_img" value="<?php echo $product['thumbnail_img'] ?? ''; ?>">
                        <input type="hidden" id="gallery_images" name="gallery_images" value="<?php echo $product['photos'] ?? ''; ?>">
                        
                        <div class="form-group full-width">
                            <label class="form-label">Upload hình ảnh sản phẩm</label>
                            <div class="image-upload-area" id="image-upload-area">
                                <div class="upload-icon">📷</div>
                                <div class="upload-text">Kéo thả ảnh vào đây hoặc click để chọn</div>
                                <div class="upload-hint">Hỗ trợ: JPG, PNG, GIF, WEBP (tối đa 5MB)</div>
                                <input type="file" id="image-upload" accept="image/*" multiple>
                                <div class="upload-progress" id="upload-progress" style="display: none;">
                                    <div class="upload-progress-bar" id="upload-progress-bar"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label">Thư viện ảnh sản phẩm</label>
                            <div class="image-gallery" id="image-gallery">
                                <?php if ($is_edit && !empty($product_images)): ?>
                                    <?php foreach ($product_images as $image): ?>
                                        <div class="image-item <?php echo $product['thumbnail_img'] == $image['id'] ? 'thumbnail' : ''; ?>" data-upload-id="<?php echo $image['id']; ?>">
                                            <?php if ($product['thumbnail_img'] == $image['id']): ?>
                                                <div class="thumbnail-badge">Ảnh đại diện</div>
                                            <?php endif; ?>
                                            <img src="../<?php echo htmlspecialchars($image['file_name']); ?>" 
                                                 alt="<?php echo htmlspecialchars($image['file_original_name']); ?>" loading="lazy">
                                            <div class="image-overlay">
                                                <button type="button" class="image-action set-thumbnail" 
                                                        onclick="setThumbnail(<?php echo $image['id']; ?>)" title="Đặt làm ảnh đại diện">⭐</button>
                                                <button type="button" class="image-action delete" 
                                                        onclick="deleteImage(<?php echo $image['id']; ?>)" title="Xóa ảnh">🗑️</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-gallery" id="empty-gallery">
                                        Chưa có hình ảnh nào. Hãy upload ảnh đầu tiên!
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Details Tab -->
                    <div class="tab-content" id="details-tab">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="weight" class="form-label">Trọng lượng (kg)</label>
                                <input type="number" id="weight" name="weight" class="form-input" 
                                       value="<?php echo $product['weight'] ?? '0'; ?>" step="0.01" min="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="earn_point" class="form-label">Điểm thưởng</label>
                                <input type="number" id="earn_point" name="earn_point" class="form-input" 
                                       value="<?php echo $product['earn_point'] ?? '0'; ?>" step="0.01" min="0">
                                <div class="form-help">Điểm thưởng khách hàng nhận khi mua sản phẩm</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="video_provider" class="form-label">Nhà cung cấp video</label>
                                <select id="video_provider" name="video_provider" class="form-select">
                                    <option value="">Không có video</option>
                                    <option value="youtube" <?php echo isset($product) && $product['video_provider'] === 'youtube' ? 'selected' : ''; ?>>YouTube</option>
                                    <option value="vimeo" <?php echo isset($product) && $product['video_provider'] === 'vimeo' ? 'selected' : ''; ?>>Vimeo</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="video_link" class="form-label">Link video</label>
                                <input type="url" id="video_link" name="video_link" class="form-input" 
                                       value="<?php echo htmlspecialchars($product['video_link'] ?? ''); ?>" placeholder="https://...">
                            </div>
                            
                            <div class="form-group">
                                <label for="external_link" class="form-label">Link ngoài</label>
                                <input type="url" id="external_link" name="external_link" class="form-input" 
                                       value="<?php echo htmlspecialchars($product['external_link'] ?? ''); ?>" placeholder="https://...">
                            </div>
                            
                            <div class="form-group">
                                <label for="external_link_btn" class="form-label">Text button link ngoài</label>
                                <input type="text" id="external_link_btn" name="external_link_btn" class="form-input" 
                                       value="<?php echo htmlspecialchars($product['external_link_btn'] ?? 'Buy Now'); ?>">
                            </div>
                            
                            <div class="form-group full-width">
                                <label class="form-label">Cài đặt sản phẩm</label>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                                    <div class="form-checkbox">
                                        <input type="checkbox" id="published" name="published" 
                                               <?php echo isset($product) && $product['published'] ? 'checked' : ''; ?>>
                                        <label for="published">Xuất bản</label>
                                    </div>
                                    <div class="form-checkbox">
                                        <input type="checkbox" id="approved" name="approved" 
                                               <?php echo isset($product) && $product['approved'] ? 'checked' : ''; ?>>
                                        <label for="approved">Phê duyệt</label>
                                    </div>
                                    <div class="form-checkbox">
                                        <input type="checkbox" id="featured" name="featured" 
                                               <?php echo isset($product) && $product['featured'] ? 'checked' : ''; ?>>
                                        <label for="featured">Nổi bật</label>
                                    </div>
                                    <div class="form-checkbox">
                                        <input type="checkbox" id="todays_deal" name="todays_deal" 
                                               <?php echo isset($product) && $product['todays_deal'] ? 'checked' : ''; ?>>
                                        <label for="todays_deal">Deal hôm nay</label>
                                    </div>
                                    <div class="form-checkbox">
                                        <input type="checkbox" id="cash_on_delivery" name="cash_on_delivery" 
                                               <?php echo isset($product) && $product['cash_on_delivery'] ? 'checked' : ''; ?>>
                                        <label for="cash_on_delivery">COD</label>
                                    </div>
                                    <div class="form-checkbox">
                                        <input type="checkbox" id="refundable" name="refundable" 
                                               <?php echo isset($product) && $product['refundable'] ? 'checked' : ''; ?>>
                                        <label for="refundable">Có thể hoàn trả</label>
                                    </div>
                                    <div class="form-checkbox">
                                        <input type="checkbox" id="digital" name="digital" 
                                               <?php echo isset($product) && $product['digital'] ? 'checked' : ''; ?>>
                                        <label for="digital">Sản phẩm số</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pricing Tab -->
                    <div class="tab-content" id="pricing-tab">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="unit_price" class="form-label required">Giá bán (VNĐ)</label>
                                <input type="number" id="unit_price" name="unit_price" class="form-input" 
                                       value="<?php echo $product['unit_price'] ?? ''; ?>" step="1000" min="0" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="discount" class="form-label">Giảm giá</label>
                                <input type="number" id="discount" name="discount" class="form-input" 
                                       value="<?php echo $product['discount'] ?? '0'; ?>" step="0.01" min="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="discount_type" class="form-label">Loại giảm giá</label>
                                <select id="discount_type" name="discount_type" class="form-select">
                                    <option value="amount" <?php echo isset($product) && $product['discount_type'] === 'amount' ? 'selected' : ''; ?>>Theo số tiền</option>
                                    <option value="percent" <?php echo isset($product) && $product['discount_type'] === 'percent' ? 'selected' : ''; ?>>Theo phần trăm</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="discount_start_date" class="form-label">Ngày bắt đầu giảm giá</label>
                                <input type="datetime-local" id="discount_start_date" name="discount_start_date" class="form-input" 
                                       value="<?php echo isset($product) && $product['discount_start_date'] ? date('Y-m-d\TH:i', $product['discount_start_date']) : ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="discount_end_date" class="form-label">Ngày kết thúc giảm giá</label>
                                <input type="datetime-local" id="discount_end_date" name="discount_end_date" class="form-input" 
                                       value="<?php echo isset($product) && $product['discount_end_date'] ? date('Y-m-d\TH:i', $product['discount_end_date']) : ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="tax" class="form-label">Thuế</label>
                                <input type="number" id="tax" name="tax" class="form-input" 
                                       value="<?php echo $product['tax'] ?? '0'; ?>" step="0.01" min="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="tax_type" class="form-label">Loại thuế</label>
                                <select id="tax_type" name="tax_type" class="form-select">
                                    <option value="percent" <?php echo isset($product) && $product['tax_type'] === 'percent' ? 'selected' : ''; ?>>Phần trăm</option>
                                    <option value="amount" <?php echo isset($product) && $product['tax_type'] === 'amount' ? 'selected' : ''; ?>>Số tiền</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="current_stock" class="form-label">Số lượng tồn kho</label>
                                <input type="number" id="current_stock" name="current_stock" class="form-input" 
                                       value="<?php echo $product['current_stock'] ?? '0'; ?>" min="0">
                            </div>
                            
                            <div class="form-group">
                                <label for="min_qty" class="form-label">Số lượng tối thiểu</label>
                                <input type="number" id="min_qty" name="min_qty" class="form-input" 
                                       value="<?php echo $product['min_qty'] ?? '1'; ?>" min="1">
                            </div>
                            
                            <div class="form-group">
                                <label for="low_stock_quantity" class="form-label">Cảnh báo hết hàng</label>
                                <input type="number" id="low_stock_quantity" name="low_stock_quantity" class="form-input" 
                                       value="<?php echo $product['low_stock_quantity'] ?? '10'; ?>" min="0">
                                <div class="form-help">Cảnh báo khi số lượng tồn kho dưới mức này</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="shipping_type" class="form-label">Loại vận chuyển</label>
                                <select id="shipping_type" name="shipping_type" class="form-select">
                                    <option value="flat_rate" <?php echo isset($product) && $product['shipping_type'] === 'flat_rate' ? 'selected' : ''; ?>>Phí cố định</option>
                                    <option value="free" <?php echo isset($product) && $product['shipping_type'] === 'free' ? 'selected' : ''; ?>>Miễn phí</option>
                                    <option value="pickup_point" <?php echo isset($product) && $product['shipping_type'] === 'pickup_point' ? 'selected' : ''; ?>>Nhận tại điểm</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="shipping_cost" class="form-label">Phí vận chuyển (VNĐ)</label>
                                <input type="number" id="shipping_cost" name="shipping_cost" class="form-input" 
                                       value="<?php echo $product['shipping_cost'] ?? '0'; ?>" step="1000" min="0">
                            </div>
                        </div>
                    </div>
                    
                    <!-- SEO Tab -->
                    <div class="tab-content" id="seo-tab">
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label for="meta_title" class="form-label">Meta Title</label>
                                <input type="text" id="meta_title" name="meta_title" class="form-input" 
                                       value="<?php echo htmlspecialchars($product['meta_title'] ?? ''); ?>" maxlength="255">
                                <div class="form-help">Tiêu đề hiển thị trên search engines (tối đa 60 ký tự)</div>
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="meta_description" class="form-label">Meta Description</label>
                                <textarea id="meta_description" name="meta_description" class="form-textarea" rows="4" maxlength="500"
                                          placeholder="Mô tả ngắn gọn về sản phẩm cho search engines..."><?php echo htmlspecialchars($product['meta_description'] ?? ''); ?></textarea>
                                <div class="form-help">Mô tả hiển thị trên search engines (tối đa 160 ký tự)</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="products.php" class="btn btn-secondary">
                            <span>❌</span>
                            <span>Hủy bỏ</span>
                        </a>
                        
                        <button type="submit" class="btn btn-success" id="save-btn">
                            <span class="loading" id="save-loading" style="display: none;"></span>
                            <span id="save-text">
                                <span><?php echo $is_edit ? '💾' : '➕'; ?></span>
                                <span><?php echo $is_edit ? 'Cập nhật' : 'Thêm sản phẩm'; ?></span>
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        // Global variables
        const imageUploadArea = document.getElementById('image-upload-area');
        const imageUpload = document.getElementById('image-upload');
        const imageGallery = document.getElementById('image-gallery');
        const emptyGallery = document.getElementById('empty-gallery');
        const uploadProgress = document.getElementById('upload-progress');
        const uploadProgressBar = document.getElementById('upload-progress-bar');
        const thumbnailInput = document.getElementById('thumbnail_img');
        const galleryInput = document.getElementById('gallery_images');
        
        // Sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('open');
            });
        }
        
        // Close sidebar when clicking outside
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 1024 && 
                !sidebar.contains(e.target) && 
                !sidebarToggle.contains(e.target) &&
                sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
        });
        
        // Tab functionality
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', function() {
                const targetTab = this.dataset.tab;
                
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));
                
                this.classList.add('active');
                document.getElementById(targetTab + '-tab').classList.add('active');
            });
        });
        
        // Image Upload - Click to upload
        imageUploadArea.addEventListener('click', function(e) {
            if (e.target === imageUploadArea || e.target.closest('.upload-icon, .upload-text, .upload-hint')) {
                imageUpload.click();
            }
        });
        
        // Drag and drop functionality
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            imageUploadArea.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            imageUploadArea.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            imageUploadArea.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight() {
            imageUploadArea.classList.add('dragover');
        }
        
        function unhighlight() {
            imageUploadArea.classList.remove('dragover');
        }
        
        // Handle dropped files
        imageUploadArea.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }
        
        // Handle file input change
        imageUpload.addEventListener('change', function(e) {
            handleFiles(e.target.files);
        });
        
        // Handle file upload
        function handleFiles(files) {
            if (files.length === 0) return;
            
            imageUploadArea.classList.add('uploading');
            uploadProgress.style.display = 'block';
            
            let completed = 0;
            const total = files.length;
            
            for (let i = 0; i < files.length; i++) {
                uploadFile(files[i], () => {
                    completed++;
                    const progress = (completed / total) * 100;
                    uploadProgressBar.style.transform = `translateX(-${100 - progress}%)`;
                    
                    if (completed === total) {
                        setTimeout(() => {
                            imageUploadArea.classList.remove('uploading');
                            uploadProgress.style.display = 'none';
                            uploadProgressBar.style.transform = 'translateX(-100%)';
                        }, 500);
                    }
                });
            }
            
            imageUpload.value = '';
        }
        
        function uploadFile(file, callback) {
            if (!file.type.startsWith('image/')) {
                showNotification(`${file.name}: Chỉ chấp nhận file ảnh`, 'error');
                callback();
                return;
            }
            
            if (file.size > 5 * 1024 * 1024) {
                showNotification(`${file.name}: File không được vượt quá 5MB`, 'error');
                callback();
                return;
            }
            
            const formData = new FormData();
            formData.append('image', file);
            formData.append('action', 'upload_image');
            formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    addImageToGallery(data);
                    showNotification(`${file.name}: Upload thành công`, 'success');
                } else {
                    showNotification(`${file.name}: ${data.message}`, 'error');
                }
            })
            .catch(error => {
                console.error('Upload error:', error);
                showNotification(`${file.name}: Có lỗi xảy ra khi upload`, 'error');
            })
            .finally(() => {
                callback();
            });
        }
        
        function addImageToGallery(imageData) {
            if (emptyGallery) {
                emptyGallery.style.display = 'none';
            }
            
            const imageItem = document.createElement('div');
            imageItem.className = 'image-item';
            imageItem.dataset.uploadId = imageData.upload_id;
            
            imageItem.innerHTML = `
                <img src="../${imageData.url}" alt="${imageData.filename}" loading="lazy">
                <div class="image-overlay">
                    <button type="button" class="image-action set-thumbnail" 
                            onclick="setThumbnail(${imageData.upload_id})" title="Đặt làm ảnh đại diện">⭐</button>
                    <button type="button" class="image-action delete" 
                            onclick="deleteImage(${imageData.upload_id})" title="Xóa ảnh">🗑️</button>
                </div>
            `;
            
            imageGallery.appendChild(imageItem);
            updateGalleryInput();
            
            const existingImages = imageGallery.querySelectorAll('.image-item');
            if (existingImages.length === 1) {
                setThumbnail(imageData.upload_id);
            }
        }
        
        function setThumbnail(uploadId) {
            document.querySelectorAll('.thumbnail-badge').forEach(badge => badge.remove());
            document.querySelectorAll('.image-item').forEach(item => item.classList.remove('thumbnail'));
            
            const imageItem = document.querySelector(`[data-upload-id="${uploadId}"]`);
            if (imageItem) {
                imageItem.classList.add('thumbnail');
                
                const badge = document.createElement('div');
                badge.className = 'thumbnail-badge';
                badge.textContent = 'Ảnh đại diện';
                imageItem.appendChild(badge);
                
                thumbnailInput.value = uploadId;
                showNotification('Đã đặt làm ảnh đại diện', 'success');
            }
        }
        
        function deleteImage(uploadId) {
            if (!confirm('Bạn có chắc chắn muốn xóa ảnh này?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_image');
            formData.append('upload_id', uploadId);
            formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const imageItem = document.querySelector(`[data-upload-id="${uploadId}"]`);
                    if (imageItem) {
                        imageItem.remove();
                        updateGalleryInput();
                        
                        if (thumbnailInput.value == uploadId) {
                            thumbnailInput.value = '';
                            const firstImage = imageGallery.querySelector('.image-item');
                            if (firstImage) {
                                setThumbnail(firstImage.dataset.uploadId);
                            }
                        }
                        
                        const remainingImages = imageGallery.querySelectorAll('.image-item');
                        if (remainingImages.length === 0 && emptyGallery) {
                            emptyGallery.style.display = 'block';
                        }
                    }
                    showNotification(data.message, 'success');
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                showNotification('Có lỗi xảy ra khi xóa ảnh', 'error');
            });
        }
        
        function updateGalleryInput() {
            const imageItems = imageGallery.querySelectorAll('.image-item');
            const uploadIds = Array.from(imageItems).map(item => item.dataset.uploadId);
            galleryInput.value = uploadIds.join(',');
        }
        
        // Form validation
        const form = document.getElementById('product-form');
        const saveBtn = document.getElementById('save-btn');
        const saveLoading = document.getElementById('save-loading');
        const saveText = document.getElementById('save-text');
        
        form.addEventListener('submit', function(e) {
            saveBtn.disabled = true;
            saveLoading.style.display = 'inline-block';
            saveText.style.opacity = '0.6';
            
            const name = document.getElementById('name').value.trim();
            const categoryId = document.getElementById('category_id').value;
            const unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;
            
            if (!name) {
                e.preventDefault();
                showNotification('Vui lòng nhập tên sản phẩm', 'error');
                resetFormButton();
                return false;
            }
            
            if (!categoryId) {
                e.preventDefault();
                showNotification('Vui lòng chọn danh mục', 'error');
                resetFormButton();
                return false;
            }
            
            if (unitPrice <= 0) {
                e.preventDefault();
                showNotification('Giá sản phẩm phải lớn hơn 0', 'error');
                resetFormButton();
                return false;
            }
        });
        
        function resetFormButton() {
            saveBtn.disabled = false;
            saveLoading.style.display = 'none';
            saveText.style.opacity = '1';
        }
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 4000);
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateGalleryInput();
            
            <?php if (isset($success)): ?>
                setTimeout(() => {
                    showNotification('<?php echo addslashes($success); ?>', 'success');
                }, 500);
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                setTimeout(() => {
                    showNotification('<?php echo addslashes($error); ?>', 'error');
                }, 500);
            <?php endif; ?>
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                form.submit();
            }
        });
    </script>
</body>
</html>