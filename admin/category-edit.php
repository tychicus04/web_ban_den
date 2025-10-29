<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Include database config
require_once '../config.php';

$db = getDBConnection();

// Authentication check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Session timeout check (8 hours)
$session_timeout = 8 * 60 * 60; // 8 hours
if (isset($_SESSION['admin_login_time']) && (time() - $_SESSION['admin_login_time']) > $session_timeout) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}

// CSRF token validation
if (!isset($_SESSION['admin_token'])) {
    $_SESSION['admin_token'] = bin2hex(random_bytes(32));
}

// Get admin info
$admin = null;
try {
    $stmt = $db->prepare("
        SELECT u.*, s.id as staff_id, r.name as role_name
        FROM users u 
        LEFT JOIN staff s ON u.id = s.user_id
        LEFT JOIN roles r ON s.role_id = r.id
        WHERE u.id = ? LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch();
    
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
        $result = $stmt->fetch();
        return $result ? $result['value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

// Helper functions
function generateSlug($string) {
    $string = trim($string);
    $string = mb_strtolower($string, 'UTF-8');
    
    // Vietnamese characters replacement
    $vietnamese = [
        'à', 'á', 'ạ', 'ả', 'ã', 'â', 'ầ', 'ấ', 'ậ', 'ẩ', 'ẫ', 'ă', 'ằ', 'ắ', 'ặ', 'ẳ', 'ẵ',
        'è', 'é', 'ẹ', 'ẻ', 'ẽ', 'ê', 'ề', 'ế', 'ệ', 'ể', 'ễ',
        'ì', 'í', 'ị', 'ỉ', 'ĩ',
        'ò', 'ó', 'ọ', 'ỏ', 'õ', 'ô', 'ồ', 'ố', 'ộ', 'ổ', 'ỗ', 'ơ', 'ờ', 'ớ', 'ợ', 'ở', 'ỡ',
        'ù', 'ú', 'ụ', 'ủ', 'ũ', 'ư', 'ừ', 'ứ', 'ự', 'ử', 'ữ',
        'ỳ', 'ý', 'ỵ', 'ỷ', 'ỹ',
        'đ'
    ];
    
    $ascii = [
        'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a',
        'e', 'e', 'e', 'e', 'e', 'e', 'e', 'e', 'e', 'e', 'e',
        'i', 'i', 'i', 'i', 'i',
        'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o', 'o',
        'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u', 'u',
        'y', 'y', 'y', 'y', 'y',
        'd'
    ];
    
    $string = str_replace($vietnamese, $ascii, $string);
    $string = preg_replace('/[^a-z0-9\s-]/', '', $string);
    $string = preg_replace('/[\s-]+/', '-', $string);
    $string = trim($string, '-');
    
    return $string;
}

function uploadImage($file, $upload_dir = '../uploads/categories/') {
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('Định dạng file không được hỗ trợ');
    }
    
    if ($file['size'] > $max_size) {
        throw new Exception('File quá lớn (tối đa 5MB)');
    }
    
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $file_extension;
    $filepath = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return 'uploads/categories/' . $filename;
    } else {
        throw new Exception('Không thể upload file');
    }
}

// Determine mode (create or edit)
$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $category_id > 0;

// Initialize category data
$category = [
    'id' => 0,
    'name' => '',
    'parent_id' => 0,
    'level' => 0,
    'order_level' => 0,
    'commision_rate' => 0.00,
    'banner' => null,
    'icon' => null,
    'cover_image' => null,
    'featured' => 0,
    'top' => 0,
    'digital' => 0,
    'slug' => '',
    'meta_title' => '',
    'meta_description' => ''
];

$category_translations = [];
$uploads = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Validate input
        $name = trim($_POST['name'] ?? '');
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        $order_level = (int)($_POST['order_level'] ?? 0);
        $commision_rate = (float)($_POST['commision_rate'] ?? 0);
        $featured = isset($_POST['featured']) ? 1 : 0;
        $top = isset($_POST['top']) ? 1 : 0;
        $digital = isset($_POST['digital']) ? 1 : 0;
        $slug = trim($_POST['slug'] ?? '');
        $meta_title = trim($_POST['meta_title'] ?? '');
        $meta_description = trim($_POST['meta_description'] ?? '');
        
        // Validate required fields
        if (empty($name)) {
            throw new Exception('Tên danh mục là bắt buộc');
        }
        
        // Auto-generate slug if empty
        if (empty($slug)) {
            $slug = generateSlug($name);
        } else {
            $slug = generateSlug($slug);
        }
        
        // Validate slug uniqueness
        $slug_check_sql = "SELECT id FROM categories WHERE slug = ?";
        $slug_params = [$slug];
        if ($is_edit) {
            $slug_check_sql .= " AND id != ?";
            $slug_params[] = $category_id;
        }
        $stmt = $db->prepare($slug_check_sql);
        $stmt->execute($slug_params);
        if ($stmt->fetch()) {
            $slug .= '-' . time();
        }
        
        // Calculate level based on parent
        $level = 0;
        if ($parent_id > 0) {
            $stmt = $db->prepare("SELECT level FROM categories WHERE id = ?");
            $stmt->execute([$parent_id]);
            $parent = $stmt->fetch();
            if ($parent) {
                $level = $parent['level'] + 1;
            }
        }
        
        // Validate parent (prevent circular reference)
        if ($parent_id > 0 && $is_edit) {
            $stmt = $db->prepare("
                WITH RECURSIVE category_tree AS (
                    SELECT id, parent_id FROM categories WHERE id = ?
                    UNION ALL
                    SELECT c.id, c.parent_id FROM categories c
                    INNER JOIN category_tree ct ON c.parent_id = ct.id
                )
                SELECT COUNT(*) as count FROM category_tree WHERE id = ?
            ");
            $stmt->execute([$category_id, $parent_id]);
            if ($stmt->fetch()['count'] > 0) {
                throw new Exception('Không thể chọn danh mục con làm danh mục cha');
            }
        }
        
        // Handle file uploads
        $upload_fields = ['banner', 'icon', 'cover_image'];
        $uploaded_files = [];
        
        foreach ($upload_fields as $field) {
            if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                try {
                    $file_path = uploadImage($_FILES[$field]);
                    
                    // Insert into uploads table
                    $stmt = $db->prepare("
                        INSERT INTO uploads (file_original_name, file_name, user_id, file_size, extension, type, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $file_info = pathinfo($_FILES[$field]['name']);
                    $stmt->execute([
                        $_FILES[$field]['name'],
                        $file_path,
                        $_SESSION['user_id'],
                        $_FILES[$field]['size'],
                        $file_info['extension'] ?? '',
                        'image'
                    ]);
                    
                    $uploaded_files[$field] = $db->lastInsertId();
                } catch (Exception $e) {
                    throw new Exception("Lỗi upload {$field}: " . $e->getMessage());
                }
            }
        }
        
        if ($is_edit) {
            // Update category
            $update_fields = [
                'name = ?', 'parent_id = ?', 'level = ?', 'order_level = ?', 'commision_rate = ?',
                'featured = ?', 'top = ?', 'digital = ?', 'slug = ?', 'meta_title = ?', 'meta_description = ?',
                'updated_at = NOW()'
            ];
            $update_params = [$name, $parent_id, $level, $order_level, $commision_rate, $featured, $top, $digital, $slug, $meta_title, $meta_description];
            
            // Add upload fields if files were uploaded
            foreach ($upload_fields as $field) {
                if (isset($uploaded_files[$field])) {
                    $update_fields[] = "{$field} = ?";
                    $update_params[] = $uploaded_files[$field];
                }
            }
            
            $update_params[] = $category_id;
            
            $sql = "UPDATE categories SET " . implode(', ', $update_fields) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($update_params);
            
        } else {
            // Create new category
            $stmt = $db->prepare("
                INSERT INTO categories (name, parent_id, level, order_level, commision_rate, banner, icon, cover_image, featured, top, digital, slug, meta_title, meta_description, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $name, $parent_id, $level, $order_level, $commision_rate,
                $uploaded_files['banner'] ?? null,
                $uploaded_files['icon'] ?? null,
                $uploaded_files['cover_image'] ?? null,
                $featured, $top, $digital, $slug, $meta_title, $meta_description
            ]);
            
            $category_id = $db->lastInsertId();
        }
        
        // Handle translations
        if (isset($_POST['translations']) && is_array($_POST['translations'])) {
            // Delete existing translations
            if ($is_edit) {
                $stmt = $db->prepare("DELETE FROM category_translations WHERE category_id = ?");
                $stmt->execute([$category_id]);
            }
            
            // Insert new translations
            $stmt = $db->prepare("
                INSERT INTO category_translations (category_id, name, lang, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
            ");
            
            foreach ($_POST['translations'] as $lang => $translation) {
                $trans_name = trim($translation['name'] ?? '');
                if (!empty($trans_name)) {
                    $stmt->execute([$category_id, $trans_name, $lang]);
                }
            }
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => $is_edit ? 'Cập nhật danh mục thành công' : 'Tạo danh mục thành công',
            'category_id' => $category_id,
            'redirect' => 'categories.php'
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Load category data for editing
if ($is_edit) {
    try {
        $stmt = $db->prepare("
            SELECT c.*, 
                   u_banner.file_name as banner_url,
                   u_icon.file_name as icon_url,
                   u_cover.file_name as cover_url
            FROM categories c
            LEFT JOIN uploads u_banner ON c.banner = u_banner.id
            LEFT JOIN uploads u_icon ON c.icon = u_icon.id
            LEFT JOIN uploads u_cover ON c.cover_image = u_cover.id
            WHERE c.id = ?
        ");
        $stmt->execute([$category_id]);
        $category = $stmt->fetch();
        
        if (!$category) {
            header('Location: categories.php?error=category_not_found');
            exit;
        }
        
        // Load translations
        $stmt = $db->prepare("SELECT lang, name FROM category_translations WHERE category_id = ?");
        $stmt->execute([$category_id]);
        $translations = $stmt->fetchAll();
        
        foreach ($translations as $trans) {
            $category_translations[$trans['lang']] = ['name' => $trans['name']];
        }
        
    } catch (PDOException $e) {
        error_log("Category load error: " . $e->getMessage());
        header('Location: categories.php?error=database_error');
        exit;
    }
}

// Get parent categories for dropdown
$parent_categories = [];
try {
    $sql = "SELECT id, name, level FROM categories";
    if ($is_edit) {
        $sql .= " WHERE id != ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$category_id]);
    } else {
        $stmt = $db->query($sql);
    }
    $parent_categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Parent categories load error: " . $e->getMessage());
}

// Get supported languages
$languages = [
    'vi' => 'Tiếng Việt',
    'en' => 'English',
    'zh' => '中文',
    'ja' => '日本語',
    'ko' => '한국어'
];

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
$page_title = $is_edit ? 'Chỉnh sửa danh mục' : 'Thêm danh mục mới';
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="<?php echo $page_title; ?> - Admin <?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-category-edit.css">
</head>

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
                        <a href="products.php" class="nav-link">
                            <span class="nav-icon">🛍️</span>
                            <span class="nav-text">Sản phẩm</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="categories.php" class="nav-link active">
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
                    <div class="nav-item">
                        <a href="contacts.php" class="nav-link">
                            <span class="nav-icon">💬</span>
                            <span class="nav-text">Liên hệ</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Marketing</div>
                    <div class="nav-item">
                        <a href="coupons.php" class="nav-link">
                            <span class="nav-icon">🎫</span>
                            <span class="nav-text">Mã giảm giá</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="flash-deals.php" class="nav-link">
                            <span class="nav-icon">⚡</span>
                            <span class="nav-text">Flash Deals</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="banners.php" class="nav-link">
                            <span class="nav-icon">🖼️</span>
                            <span class="nav-text">Banner</span>
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
                    <div class="nav-item">
                        <a href="backups.php" class="nav-link">
                            <span class="nav-icon">💾</span>
                            <span class="nav-text">Sao lưu</span>
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
                            <a href="categories.php">Danh mục</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span><?php echo $is_edit ? 'Chỉnh sửa' : 'Thêm mới'; ?></span>
                        </div>
                    </nav>
                </div>
                
                <div class="header-right">
                    <div class="user-menu">
                        <button class="user-button">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($admin['name'] ?? 'A', 0, 2)); ?>
                            </div>
                            <div class="user-info">
                                <div class="user-name"><?php echo htmlspecialchars($admin['name'] ?? 'Admin'); ?></div>
                                <div class="user-role"><?php echo htmlspecialchars($_SESSION['admin_role'] ?? 'Administrator'); ?></div>
                            </div>
                            <span>▼</span>
                        </button>
                    </div>
                </div>
            </header>
            
            <!-- Content -->
            <div class="content">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title"><?php echo $page_title; ?></h1>
                    <div class="page-actions">
                        <a href="categories.php" class="btn btn-secondary">
                            <span>←</span>
                            <span>Quay lại</span>
                        </a>
                    </div>
                </div>
                
                <!-- Form -->
                <form id="category-form" enctype="multipart/form-data">
                    <input type="hidden" name="token" value="<?php echo $_SESSION['admin_token']; ?>">
                    
                    <div class="form-container">
                        <!-- Basic Information -->
                        <div class="form-section">
                            <h2 class="form-section-title">
                                <span>📝</span>
                                <span>Thông tin cơ bản</span>
                            </h2>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="name" class="form-label required">Tên danh mục</label>
                                    <input 
                                        type="text" 
                                        id="name" 
                                        name="name" 
                                        class="form-control" 
                                        value="<?php echo htmlspecialchars($category['name']); ?>"
                                        required
                                        autocomplete="off"
                                    >
                                    <div class="form-help">Tên hiển thị của danh mục</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="parent_id" class="form-label">Danh mục cha</label>
                                    <select id="parent_id" name="parent_id" class="form-control">
                                        <option value="0">-- Danh mục gốc --</option>
                                        <?php foreach ($parent_categories as $parent): ?>
                                            <option value="<?php echo $parent['id']; ?>" <?php echo $parent['id'] == $category['parent_id'] ? 'selected' : ''; ?>>
                                                <?php echo str_repeat('&nbsp;&nbsp;', $parent['level']) . htmlspecialchars($parent['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-help">Để trống nếu là danh mục gốc</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="order_level" class="form-label">Thứ tự sắp xếp</label>
                                    <input 
                                        type="number" 
                                        id="order_level" 
                                        name="order_level" 
                                        class="form-control" 
                                        value="<?php echo $category['order_level']; ?>"
                                        min="0"
                                    >
                                    <div class="form-help">Số nhỏ hơn sẽ hiển thị trước</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="commision_rate" class="form-label">Tỷ lệ hoa hồng (%)</label>
                                    <input 
                                        type="number" 
                                        id="commision_rate" 
                                        name="commision_rate" 
                                        class="form-control" 
                                        value="<?php echo $category['commision_rate']; ?>"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                    >
                                    <div class="form-help">Tỷ lệ hoa hồng cho người bán</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Settings -->
                        <div class="form-section">
                            <h2 class="form-section-title">
                                <span>⚙️</span>
                                <span>Cài đặt</span>
                            </h2>
                            
                            <div class="form-grid">
                                <div class="checkbox-group">
                                    <label class="switch">
                                        <input type="checkbox" name="featured" <?php echo $category['featured'] ? 'checked' : ''; ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <label class="checkbox-label">Danh mục nổi bật</label>
                                </div>
                                
                                <div class="checkbox-group">
                                    <label class="switch">
                                        <input type="checkbox" name="top" <?php echo $category['top'] ? 'checked' : ''; ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <label class="checkbox-label">Danh mục top</label>
                                </div>
                                
                                <div class="checkbox-group">
                                    <label class="switch">
                                        <input type="checkbox" name="digital" <?php echo $category['digital'] ? 'checked' : ''; ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <label class="checkbox-label">Sản phẩm số</label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Images -->
                        <div class="form-section">
                            <h2 class="form-section-title">
                                <span>🖼️</span>
                                <span>Hình ảnh</span>
                            </h2>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="icon" class="form-label">Icon danh mục</label>
                                    <div class="file-upload">
                                        <input type="file" id="icon" name="icon" accept="image/*">
                                        <label for="icon" class="file-upload-label">
                                            <span>📁</span>
                                            <span>Chọn icon (50x50px)</span>
                                        </label>
                                    </div>
                                    <?php if (!empty($category['icon_url'])): ?>
                                        <div class="file-preview">
                                            <img src="../<?php echo htmlspecialchars($category['icon_url']); ?>" alt="Current icon">
                                        </div>
                                    <?php endif; ?>
                                    <div class="form-help">Định dạng: JPG, PNG, GIF. Tối đa 5MB</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="banner" class="form-label">Banner danh mục</label>
                                    <div class="file-upload">
                                        <input type="file" id="banner" name="banner" accept="image/*">
                                        <label for="banner" class="file-upload-label">
                                            <span>🖼️</span>
                                            <span>Chọn banner (1200x300px)</span>
                                        </label>
                                    </div>
                                    <?php if (!empty($category['banner_url'])): ?>
                                        <div class="file-preview">
                                            <img src="../<?php echo htmlspecialchars($category['banner_url']); ?>" alt="Current banner">
                                        </div>
                                    <?php endif; ?>
                                    <div class="form-help">Banner hiển thị trên trang danh mục</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="cover_image" class="form-label">Ảnh bìa</label>
                                    <div class="file-upload">
                                        <input type="file" id="cover_image" name="cover_image" accept="image/*">
                                        <label for="cover_image" class="file-upload-label">
                                            <span>🎨</span>
                                            <span>Chọn ảnh bìa (800x600px)</span>
                                        </label>
                                    </div>
                                    <?php if (!empty($category['cover_url'])): ?>
                                        <div class="file-preview">
                                            <img src="../<?php echo htmlspecialchars($category['cover_url']); ?>" alt="Current cover">
                                        </div>
                                    <?php endif; ?>
                                    <div class="form-help">Ảnh bìa cho card danh mục</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- SEO -->
                        <div class="form-section">
                            <h2 class="form-section-title">
                                <span>🔍</span>
                                <span>SEO & URL</span>
                            </h2>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="slug" class="form-label">URL thân thiện (Slug)</label>
                                    <input 
                                        type="text" 
                                        id="slug" 
                                        name="slug" 
                                        class="form-control" 
                                        value="<?php echo htmlspecialchars($category['slug']); ?>"
                                        pattern="[a-z0-9\-]+"
                                    >
                                    <div class="form-help">Để trống để tự động tạo từ tên danh mục</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="meta_title" class="form-label">Meta Title</label>
                                    <input 
                                        type="text" 
                                        id="meta_title" 
                                        name="meta_title" 
                                        class="form-control" 
                                        value="<?php echo htmlspecialchars($category['meta_title']); ?>"
                                        maxlength="60"
                                    >
                                    <div class="form-help">Tiêu đề SEO (tối đa 60 ký tự)</div>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="meta_description" class="form-label">Meta Description</label>
                                    <textarea 
                                        id="meta_description" 
                                        name="meta_description" 
                                        class="form-control" 
                                        rows="3"
                                        maxlength="160"
                                    ><?php echo htmlspecialchars($category['meta_description']); ?></textarea>
                                    <div class="form-help">Mô tả SEO (tối đa 160 ký tự)</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Translations -->
                        <div class="form-section">
                            <h2 class="form-section-title">
                                <span>🌐</span>
                                <span>Đa ngôn ngữ</span>
                            </h2>
                            
                            <div class="tabs">
                                <?php foreach ($languages as $lang_code => $lang_name): ?>
                                    <button type="button" class="tab-button <?php echo $lang_code === 'vi' ? 'active' : ''; ?>" data-tab="<?php echo $lang_code; ?>">
                                        <?php echo $lang_name; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php foreach ($languages as $lang_code => $lang_name): ?>
                                <div class="tab-content <?php echo $lang_code === 'vi' ? 'active' : ''; ?>" id="tab-<?php echo $lang_code; ?>">
                                    <div class="form-group">
                                        <label for="trans_name_<?php echo $lang_code; ?>" class="form-label">Tên danh mục (<?php echo $lang_name; ?>)</label>
                                        <input 
                                            type="text" 
                                            id="trans_name_<?php echo $lang_code; ?>" 
                                            name="translations[<?php echo $lang_code; ?>][name]" 
                                            class="form-control" 
                                            value="<?php echo htmlspecialchars($category_translations[$lang_code]['name'] ?? ''); ?>"
                                        >
                                        <div class="form-help">Tên danh mục bằng <?php echo $lang_name; ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="form-section">
                            <div style="display: flex; gap: var(--space-3); justify-content: flex-end;">
                                <a href="categories.php" class="btn btn-secondary">
                                    <span>✕</span>
                                    <span>Hủy</span>
                                </a>
                                <button type="submit" class="btn btn-primary" id="submit-btn">
                                    <span><?php echo $is_edit ? '💾' : '➕'; ?></span>
                                    <span><?php echo $is_edit ? 'Cập nhật' : 'Tạo danh mục'; ?></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        // Sidebar toggle functionality
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        
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
        
        // Close sidebar on mobile when clicking outside
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
                const tabId = this.dataset.tab;
                
                // Remove active class from all tabs
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));
                
                // Add active class to current tab
                this.classList.add('active');
                document.getElementById('tab-' + tabId).classList.add('active');
            });
        });
        
        // Auto-generate slug from name
        const nameInput = document.getElementById('name');
        const slugInput = document.getElementById('slug');
        
        nameInput.addEventListener('input', function() {
            if (!slugInput.value || slugInput.dataset.autoGenerated === 'true') {
                const slug = generateSlug(this.value);
                slugInput.value = slug;
                slugInput.dataset.autoGenerated = 'true';
            }
        });
        
        slugInput.addEventListener('input', function() {
            if (this.value) {
                this.dataset.autoGenerated = 'false';
            }
        });
        
        function generateSlug(str) {
            return str
                .toLowerCase()
                .trim()
                .replace(/[àáạảãâầấậẩẫăằắặẳẵ]/g, 'a')
                .replace(/[èéẹẻẽêềếệểễ]/g, 'e')
                .replace(/[ìíịỉĩ]/g, 'i')
                .replace(/[òóọỏõôồốộổỗơờớợởỡ]/g, 'o')
                .replace(/[ùúụủũưừứựửữ]/g, 'u')
                .replace(/[ỳýỵỷỹ]/g, 'y')
                .replace(/đ/g, 'd')
                .replace(/[^a-z0-9\s-]/g, '')
                .replace(/[\s-]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }
        
        // File upload preview
        function setupFilePreview(inputId) {
            const input = document.getElementById(inputId);
            const label = input.nextElementSibling;
            
            input.addEventListener('change', function() {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        // Create or update preview
                        let preview = input.parentNode.parentNode.querySelector('.file-preview');
                        if (!preview) {
                            preview = document.createElement('div');
                            preview.className = 'file-preview';
                            input.parentNode.parentNode.appendChild(preview);
                        }
                        
                        preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                        
                        // Update label
                        label.innerHTML = `
                            <span>✅</span>
                            <span>Đã chọn: ${file.name}</span>
                        `;
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
        
        // Setup file previews
        setupFilePreview('icon');
        setupFilePreview('banner');
        setupFilePreview('cover_image');
        
        // Form validation
        function validateForm() {
            const form = document.getElementById('category-form');
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                field.classList.remove('error');
                
                if (!field.value.trim()) {
                    field.classList.add('error');
                    isValid = false;
                }
            });
            
            // Validate slug format
            const slugInput = document.getElementById('slug');
            if (slugInput.value && !/^[a-z0-9\-]+$/.test(slugInput.value)) {
                slugInput.classList.add('error');
                showNotification('Slug chỉ được chứa chữ thường, số và dấu gạch ngang', 'error');
                isValid = false;
            } else {
                slugInput.classList.remove('error');
            }
            
            return isValid;
        }
        
        // Form submission
        const form = document.getElementById('category-form');
        const submitBtn = document.getElementById('submit-btn');
        
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (!validateForm()) {
                showNotification('Vui lòng kiểm tra lại thông tin đã nhập', 'error');
                return;
            }
            
            // Show loading
            submitBtn.classList.add('btn-loading');
            submitBtn.disabled = true;
            
            try {
                const formData = new FormData(form);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                    
                    // Redirect after delay
                    setTimeout(() => {
                        if (result.redirect) {
                            window.location.href = result.redirect;
                        } else {
                            window.location.reload();
                        }
                    }, 1500);
                } else {
                    showNotification(result.message, 'error');
                }
                
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
            } finally {
                // Hide loading
                submitBtn.classList.remove('btn-loading');
                submitBtn.disabled = false;
            }
        });
        
        // Character counter for meta fields
        function setupCharacterCounter(inputId, maxLength) {
            const input = document.getElementById(inputId);
            const help = input.nextElementSibling;
            
            function updateCounter() {
                const remaining = maxLength - input.value.length;
                const color = remaining < 10 ? 'var(--danger)' : remaining < 20 ? 'var(--warning)' : 'var(--text-tertiary)';
                help.innerHTML = help.innerHTML.replace(/\(\d+\/\d+\)/, '') + ` (${input.value.length}/${maxLength})`;
                help.style.color = color;
            }
            
            input.addEventListener('input', updateCounter);
            updateCounter();
        }
        
        setupCharacterCounter('meta_title', 60);
        setupCharacterCounter('meta_description', 160);
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? 'var(--success)' : type === 'error' ? 'var(--danger)' : 'var(--primary)'};
                color: white;
                padding: var(--space-4) var(--space-5);
                border-radius: var(--rounded-lg);
                box-shadow: var(--shadow-xl);
                z-index: 9999;
                transform: translateX(400px);
                transition: transform 0.3s ease;
                max-width: 350px;
                font-weight: var(--font-medium);
            `;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
                setTimeout(() => {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }
        
        // Responsive handling
        function handleResponsive() {
            const isDesktop = window.innerWidth > 1024;
            
            if (isDesktop && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
            
            if (!isDesktop && sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
            }
        }
        
        window.addEventListener('resize', handleResponsive);
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Category Edit - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Category Edit - Ready!');
            console.log('📝 Mode:', <?php echo $is_edit ? '"Edit"' : '"Create"'; ?>);
            <?php if ($is_edit): ?>
            console.log('🆔 Category ID:', <?php echo $category_id; ?>);
            <?php endif; ?>
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                form.dispatchEvent(new Event('submit'));
            }
            
            // Escape to go back
            if (e.key === 'Escape') {
                if (confirm('Bạn có chắc chắn muốn hủy? Dữ liệu chưa lưu sẽ bị mất.')) {
                    window.location.href = 'categories.php';
                }
            }
        });
        
        // Warn before leaving with unsaved changes
        let formChanged = false;
        
        form.addEventListener('input', function() {
            formChanged = true;
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        
        form.addEventListener('submit', function() {
            formChanged = false;
        });
    </script>
</body>
</html>