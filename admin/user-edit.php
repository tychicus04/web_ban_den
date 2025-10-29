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

// Get user ID from URL
$user_id = (int)($_GET['id'] ?? 0);
$is_new = $user_id === 0;

if (!$is_new && $user_id <= 0) {
    header('Location: users.php?error=invalid_id');
    exit;
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

// Currency format function
function formatCurrency($amount, $currency = 'VND') {
    if ($currency === 'VND') {
        return number_format($amount, 0, ',', '.') . '₫';
    } else {
        return '$' . number_format($amount, 2, '.', ',');
    }
}

// Handle form submission
$errors = [];
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        $errors[] = 'Invalid CSRF token';
    } else {
        $action = $_POST['action'] ?? '';
        
        try {
            switch ($action) {
                case 'save_user':
                    // Validate input
                    $name = trim($_POST['name'] ?? '');
                    $email = trim($_POST['email'] ?? '');
                    $phone = trim($_POST['phone'] ?? '');
                    $user_type = $_POST['user_type'] ?? 'customer';
                    $banned = isset($_POST['banned']) ? 1 : 0;
                    $address = trim($_POST['address'] ?? '');
                    $country = trim($_POST['country'] ?? '');
                    $state = trim($_POST['state'] ?? '');
                    $city = trim($_POST['city'] ?? '');
                    $postal_code = trim($_POST['postal_code'] ?? '');
                    
                    if (empty($name)) $errors[] = 'Tên không được để trống';
                    if (empty($email)) $errors[] = 'Email không được để trống';
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email không hợp lệ';
                    if (!in_array($user_type, ['customer', 'seller', 'admin'])) $errors[] = 'Loại người dùng không hợp lệ';
                    
                    // Check if email exists for other users
                    if (!$is_new) {
                        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                        $stmt->execute([$email, $user_id]);
                        if ($stmt->fetch()) $errors[] = 'Email đã tồn tại';
                    } else {
                        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        if ($stmt->fetch()) $errors[] = 'Email đã tồn tại';
                    }
                    
                    if (empty($errors)) {
                        if ($is_new) {
                            // Create new user
                            $password = password_hash('123456', PASSWORD_DEFAULT); // Default password
                            $stmt = $db->prepare("
                                INSERT INTO users (name, email, phone, user_type, banned, address, country, state, city, postal_code, password, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            $stmt->execute([$name, $email, $phone, $user_type, $banned, $address, $country, $state, $city, $postal_code, $password]);
                            $user_id = $db->lastInsertId();
                            $success_message = 'Tạo người dùng thành công! Mật khẩu mặc định: 123456';
                        } else {
                            // Update existing user
                            $stmt = $db->prepare("
                                UPDATE users 
                                SET name = ?, email = ?, phone = ?, user_type = ?, banned = ?, 
                                    address = ?, country = ?, state = ?, city = ?, postal_code = ?, updated_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->execute([$name, $email, $phone, $user_type, $banned, $address, $country, $state, $city, $postal_code, $user_id]);
                            $success_message = 'Cập nhật thông tin thành công!';
                        }
                    }
                    break;
                    
                case 'reset_password':
                    if (!$is_new) {
                        $new_password = trim($_POST['new_password'] ?? '');
                        if (strlen($new_password) < 6) {
                            $errors[] = 'Mật khẩu phải có ít nhất 6 ký tự';
                        } else {
                            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                            $stmt->execute([$hashed_password, $user_id]);
                            $success_message = 'Đặt lại mật khẩu thành công!';
                        }
                    }
                    break;
                    
                case 'update_balance':
                    if (!$is_new) {
                        $balance_action = $_POST['balance_action'] ?? '';
                        $amount = (float)($_POST['amount'] ?? 0);
                        $note = trim($_POST['note'] ?? '');
                        
                        if ($amount <= 0) {
                            $errors[] = 'Số tiền phải lớn hơn 0';
                        } else {
                            if ($balance_action === 'add') {
                                $stmt = $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                                $stmt->execute([$amount, $user_id]);
                                $success_message = 'Cộng tiền thành công!';
                            } elseif ($balance_action === 'subtract') {
                                $stmt = $db->prepare("UPDATE users SET balance = GREATEST(0, balance - ?) WHERE id = ?");
                                $stmt->execute([$amount, $user_id]);
                                $success_message = 'Trừ tiền thành công!';
                            } elseif ($balance_action === 'set') {
                                $stmt = $db->prepare("UPDATE users SET balance = ? WHERE id = ?");
                                $stmt->execute([$amount, $user_id]);
                                $success_message = 'Thiết lập số dư thành công!';
                            }
                            
                            // Log wallet transaction
                            if (!empty($note)) {
                                $stmt = $db->prepare("
                                    INSERT INTO wallets (user_id, amount, payment_method, payment_details, approval, created_at)
                                    VALUES (?, ?, 'admin_adjustment', ?, 1, NOW())
                                ");
                                $stmt->execute([$user_id, $balance_action === 'subtract' ? -$amount : $amount, $note]);
                            }
                        }
                    }
                    break;
                    
                case 'send_verification':
                    if (!$is_new) {
                        // Set email as verified
                        $stmt = $db->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?");
                        $stmt->execute([$user_id]);
                        $success_message = 'Đã xác thực email thành công!';
                    }
                    break;
            }
        } catch (PDOException $e) {
            error_log("User edit error: " . $e->getMessage());
            $errors[] = 'Lỗi cơ sở dữ liệu';
        }
    }
}

// Get user data
$user = null;
$seller_info = null;
$shop_info = null;
$user_orders = [];
$user_stats = [];

if (!$is_new) {
    try {
        // Get user basic info
        $stmt = $db->prepare("
            SELECT u.*,
                   NULL as seller_id, 'active' as verification_status, 0 as seller_rating, 0 as num_of_reviews, 0 as num_of_sale,
                   u.name as shop_name, u.avatar as shop_logo, u.address as shop_address, u.phone as shop_phone
            FROM users u
            WHERE u.id = ?
        ");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!$user) {
            header('Location: users.php?error=user_not_found');
            exit;
        }
        
        // Get user statistics
        $stmt = $db->prepare("
            SELECT 
                COUNT(DISTINCT o.id) as total_orders,
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.grand_total ELSE 0 END), 0) as total_spent,
                COUNT(DISTINCT CASE WHEN o.delivery_status = 'delivered' THEN o.id END) as completed_orders,
                COUNT(DISTINCT r.id) as total_reviews,
                COALESCE(AVG(r.rating), 0) as avg_rating_given
            FROM users u
            LEFT JOIN orders o ON u.id = o.user_id
            LEFT JOIN reviews r ON u.id = r.user_id
            WHERE u.id = ?
        ");
        $stmt->execute([$user_id]);
        $user_stats = $stmt->fetch();
        
        // Get recent orders
        $stmt = $db->prepare("
            SELECT o.*, 
                   COALESCE(SUM(od.quantity), 0) as total_items
            FROM orders o
            LEFT JOIN order_details od ON o.id = od.order_id
            WHERE o.user_id = ?
            GROUP BY o.id
            ORDER BY o.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$user_id]);
        $user_orders = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("User data fetch error: " . $e->getMessage());
        $errors[] = 'Không thể tải thông tin người dùng';
    }
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
$page_title = $is_new ? 'Thêm người dùng mới' : 'Chỉnh sửa người dùng';
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
    
    <link rel="stylesheet" href="../asset/css/pages/admin-user-edit.css">
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
                        <a href="users.php" class="nav-link active">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Người dùng</span>
                        </a>
                    </div>   
                    <div class="nav-item">
                        <a href="sellers.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Người Bán</span>
                        </a>
                    </div>
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
                            <a href="users.php">Người dùng</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span><?php echo $is_new ? 'Thêm mới' : 'Chỉnh sửa'; ?></span>
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
                    <div class="page-header-left">
                        <h1 class="page-title"><?php echo $page_title; ?></h1>
                        <p class="page-subtitle">
                            <?php echo $is_new ? 'Tạo tài khoản người dùng mới' : 'Chỉnh sửa thông tin và cài đặt người dùng'; ?>
                        </p>
                    </div>
                    <div class="page-actions">
                        <a href="users.php" class="btn btn-secondary">
                            <span>←</span>
                            <span>Quay lại</span>
                        </a>
                    </div>
                </div>
                
                <!-- Alert Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <strong>Có lỗi xảy ra:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                
                <!-- User Profile Card (for existing users) -->
                <?php if (!$is_new && $user): ?>
                    <div class="user-profile-card">
                        <div class="user-profile-header">
                            <div class="user-profile-avatar">
                                <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                            </div>
                            <div class="user-profile-info">
                                <div class="user-profile-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                <div class="user-profile-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                <div class="user-profile-badges">
                                    <span class="status-badge <?php echo $user['user_type']; ?>">
                                        <?php echo ucfirst($user['user_type']); ?>
                                    </span>
                                    <?php if ($user['banned']): ?>
                                        <span class="status-badge banned">Bị cấm</span>
                                    <?php else: ?>
                                        <span class="status-badge active">Hoạt động</span>
                                    <?php endif; ?>
                                    <?php if ($user['email_verified_at']): ?>
                                        <span class="status-badge verified">Đã xác thực</span>
                                    <?php else: ?>
                                        <span class="status-badge unverified">Chưa xác thực</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- User Stats -->
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo formatCurrency($user['balance']); ?></div>
                                <div class="stat-label">Số dư ví</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo number_format($user_stats['total_orders'] ?? 0); ?></div>
                                <div class="stat-label">Tổng đơn hàng</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo formatCurrency($user_stats['total_spent'] ?? 0); ?></div>
                                <div class="stat-label">Tổng chi tiêu</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo number_format($user_stats['total_reviews'] ?? 0); ?></div>
                                <div class="stat-label">Đánh giá đã viết</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Form Container -->
                <div class="form-container">
                    <!-- Form Tabs -->
                    <div class="form-tabs">
                        <button class="form-tab active" onclick="switchTab('basic')">
                            👤 Thông tin cơ bản
                        </button>
                        <?php if (!$is_new): ?>
                            <button class="form-tab" onclick="switchTab('security')">
                                🔒 Bảo mật
                            </button>
                            <button class="form-tab" onclick="switchTab('wallet')">
                                💰 Ví tiền
                            </button>
                            <button class="form-tab" onclick="switchTab('orders')">
                                📦 Đơn hàng
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-content">
                        <!-- Basic Information Tab -->
                        <div class="form-section active" id="basic-tab">
                            <form method="POST" id="basic-form">
                                <input type="hidden" name="token" value="<?php echo $_SESSION['admin_token']; ?>">
                                <input type="hidden" name="action" value="save_user">
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label" for="name">Tên đầy đủ *</label>
                                        <input 
                                            type="text" 
                                            class="form-input" 
                                            id="name" 
                                            name="name" 
                                            value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>"
                                            required
                                        >
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="email">Email *</label>
                                        <input 
                                            type="email" 
                                            class="form-input" 
                                            id="email" 
                                            name="email" 
                                            value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                                            required
                                        >
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="phone">Số điện thoại</label>
                                        <input 
                                            type="tel" 
                                            class="form-input" 
                                            id="phone" 
                                            name="phone" 
                                            value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                        >
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="user_type">Loại người dùng *</label>
                                        <select class="form-input form-select" id="user_type" name="user_type">
                                            <option value="customer" <?php echo ($user['user_type'] ?? 'customer') === 'customer' ? 'selected' : ''; ?>>
                                                Khách hàng
                                            </option>
                                            <option value="seller" <?php echo ($user['user_type'] ?? '') === 'seller' ? 'selected' : ''; ?>>
                                                Người bán
                                            </option>
                                            <option value="admin" <?php echo ($user['user_type'] ?? '') === 'admin' ? 'selected' : ''; ?>>
                                                Quản trị viên
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="address">Địa chỉ</label>
                                    <textarea 
                                        class="form-input form-textarea" 
                                        id="address" 
                                        name="address" 
                                        rows="3"
                                    ><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label" for="country">Quốc gia</label>
                                        <input 
                                            type="text" 
                                            class="form-input" 
                                            id="country" 
                                            name="country" 
                                            value="<?php echo htmlspecialchars($user['country'] ?? 'Vietnam'); ?>"
                                        >
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="state">Tỉnh/Thành phố</label>
                                        <input 
                                            type="text" 
                                            class="form-input" 
                                            id="state" 
                                            name="state" 
                                            value="<?php echo htmlspecialchars($user['state'] ?? ''); ?>"
                                        >
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="city">Quận/Huyện</label>
                                        <input 
                                            type="text" 
                                            class="form-input" 
                                            id="city" 
                                            name="city" 
                                            value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>"
                                        >
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="postal_code">Mã bưu điện</label>
                                        <input 
                                            type="text" 
                                            class="form-input" 
                                            id="postal_code" 
                                            name="postal_code" 
                                            value="<?php echo htmlspecialchars($user['postal_code'] ?? ''); ?>"
                                        >
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="form-checkbox">
                                        <input 
                                            type="checkbox" 
                                            id="banned" 
                                            name="banned" 
                                            value="1"
                                            <?php echo ($user['banned'] ?? 0) ? 'checked' : ''; ?>
                                        >
                                        <label for="banned">Cấm người dùng này</label>
                                    </div>
                                    <div class="form-help">
                                        Người dùng bị cấm sẽ không thể đăng nhập vào hệ thống
                                    </div>
                                </div>
                                
                                <div style="display: flex; gap: var(--space-3); margin-top: var(--space-6);">
                                    <button type="submit" class="btn btn-primary">
                                        <span>💾</span>
                                        <span><?php echo $is_new ? 'Tạo người dùng' : 'Lưu thay đổi'; ?></span>
                                    </button>
                                    <a href="users.php" class="btn btn-secondary">
                                        <span>✕</span>
                                        <span>Hủy</span>
                                    </a>
                                </div>
                            </form>
                        </div>
                        
                        <?php if (!$is_new): ?>
                            <!-- Security Tab -->
                            <div class="form-section" id="security-tab">
                                <h3 style="margin-bottom: var(--space-5);">Bảo mật tài khoản</h3>
                                
                                <!-- Reset Password Form -->
                                <form method="POST" style="margin-bottom: var(--space-8);">
                                    <input type="hidden" name="token" value="<?php echo $_SESSION['admin_token']; ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    
                                    <h4 style="margin-bottom: var(--space-4);">Đặt lại mật khẩu</h4>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="new_password">Mật khẩu mới</label>
                                        <input 
                                            type="password" 
                                            class="form-input" 
                                            id="new_password" 
                                            name="new_password" 
                                            minlength="6"
                                            placeholder="Nhập mật khẩu mới (tối thiểu 6 ký tự)"
                                        >
                                        <div class="form-help">
                                            Mật khẩu mới sẽ được gửi đến email của người dùng
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-warning">
                                        <span>🔑</span>
                                        <span>Đặt lại mật khẩu</span>
                                    </button>
                                </form>
                                
                                <!-- Email Verification -->
                                <form method="POST">
                                    <input type="hidden" name="token" value="<?php echo $_SESSION['admin_token']; ?>">
                                    <input type="hidden" name="action" value="send_verification">
                                    
                                    <h4 style="margin-bottom: var(--space-4);">Xác thực email</h4>
                                    
                                    <p style="margin-bottom: var(--space-4); color: var(--text-secondary);">
                                        Trạng thái: 
                                        <?php if ($user['email_verified_at']): ?>
                                            <span class="status-badge verified">Đã xác thực</span>
                                            <span style="color: var(--text-tertiary);">
                                                (<?php echo date('d/m/Y H:i', strtotime($user['email_verified_at'])); ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge unverified">Chưa xác thực</span>
                                        <?php endif; ?>
                                    </p>
                                    
                                    <?php if (!$user['email_verified_at']): ?>
                                        <button type="submit" class="btn btn-success">
                                            <span>✅</span>
                                            <span>Đánh dấu đã xác thực</span>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                            
                            <!-- Wallet Tab -->
                            <div class="form-section" id="wallet-tab">
                                <h3 style="margin-bottom: var(--space-5);">Quản lý ví tiền</h3>
                                
                                <div style="background: var(--gray-50); padding: var(--space-5); border-radius: var(--rounded-lg); margin-bottom: var(--space-6);">
                                    <h4 style="margin-bottom: var(--space-2);">Số dư hiện tại</h4>
                                    <div style="font-size: var(--text-3xl); font-weight: var(--font-bold); color: var(--primary);">
                                        <?php echo formatCurrency($user['balance']); ?>
                                    </div>
                                </div>
                                
                                <form method="POST">
                                    <input type="hidden" name="token" value="<?php echo $_SESSION['admin_token']; ?>">
                                    <input type="hidden" name="action" value="update_balance">
                                    
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="form-label" for="balance_action">Thao tác</label>
                                            <select class="form-input form-select" id="balance_action" name="balance_action" required>
                                                <option value="">Chọn thao tác</option>
                                                <option value="add">Cộng tiền</option>
                                                <option value="subtract">Trừ tiền</option>
                                                <option value="set">Thiết lập số dư</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label" for="amount">Số tiền (VND)</label>
                                            <input 
                                                type="number" 
                                                class="form-input" 
                                                id="amount" 
                                                name="amount" 
                                                min="0" 
                                                step="1000"
                                                placeholder="0"
                                                required
                                            >
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="note">Ghi chú</label>
                                        <textarea 
                                            class="form-input form-textarea" 
                                            id="note" 
                                            name="note" 
                                            rows="3"
                                            placeholder="Lý do điều chỉnh số dư..."
                                        ></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">
                                        <span>💰</span>
                                        <span>Cập nhật số dư</span>
                                    </button>
                                </form>
                            </div>
                            
                            <!-- Orders Tab -->
                            <div class="form-section" id="orders-tab">
                                <h3 style="margin-bottom: var(--space-5);">Lịch sử đơn hàng</h3>
                                
                                <?php if (!empty($user_orders)): ?>
                                    <div class="orders-table">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Mã đơn</th>
                                                    <th>Ngày đặt</th>
                                                    <th>Số lượng</th>
                                                    <th>Tổng tiền</th>
                                                    <th>Thanh toán</th>
                                                    <th>Giao hàng</th>
                                                    <th>Thao tác</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($user_orders as $order): ?>
                                                    <tr>
                                                        <td>
                                                            <strong>#<?php echo $order['id']; ?></strong>
                                                        </td>
                                                        <td>
                                                            <?php echo date('d/m/Y', strtotime($order['created_at'])); ?>
                                                            <br>
                                                            <small style="color: var(--text-tertiary);">
                                                                <?php echo date('H:i', strtotime($order['created_at'])); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <?php echo number_format($order['total_items']); ?> sản phẩm
                                                        </td>
                                                        <td>
                                                            <strong><?php echo formatCurrency($order['grand_total']); ?></strong>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $payment_class = '';
                                                            switch ($order['payment_status']) {
                                                                case 'paid':
                                                                    $payment_class = 'active';
                                                                    break;
                                                                case 'unpaid':
                                                                    $payment_class = 'banned';
                                                                    break;
                                                                default:
                                                                    $payment_class = 'unverified';
                                                            }
                                                            ?>
                                                            <span class="status-badge <?php echo $payment_class; ?>">
                                                                <?php echo ucfirst($order['payment_status']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $delivery_class = '';
                                                            switch ($order['delivery_status']) {
                                                                case 'delivered':
                                                                    $delivery_class = 'active';
                                                                    break;
                                                                case 'pending':
                                                                    $delivery_class = 'unverified';
                                                                    break;
                                                                case 'cancelled':
                                                                    $delivery_class = 'banned';
                                                                    break;
                                                                default:
                                                                    $delivery_class = 'unverified';
                                                            }
                                                            ?>
                                                            <span class="status-badge <?php echo $delivery_class; ?>">
                                                                <?php echo ucfirst($order['delivery_status']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <a href="order-detail.php?id=<?php echo $order['id']; ?>" 
                                                               class="btn btn-secondary" 
                                                               style="padding: var(--space-1) var(--space-2); font-size: var(--text-xs);">
                                                                Xem
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div style="margin-top: var(--space-4);">
                                        <a href="orders.php?user_id=<?php echo $user['id']; ?>" class="btn btn-secondary">
                                            <span>📦</span>
                                            <span>Xem tất cả đơn hàng</span>
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div style="text-align: center; padding: var(--space-8); color: var(--text-tertiary);">
                                        <div style="font-size: 48px; margin-bottom: var(--space-4);">📦</div>
                                        <p>Người dùng chưa có đơn hàng nào</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
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
        
        // Tab switching functionality
        function switchTab(tabName) {
            // Hide all sections
            document.querySelectorAll('.form-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.form-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected section
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to selected tab
            event.target.classList.add('active');
        }
        
        // Form validation
        document.getElementById('basic-form').addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();
            
            if (!name) {
                alert('Vui lòng nhập tên đầy đủ');
                e.preventDefault();
                return;
            }
            
            if (!email) {
                alert('Vui lòng nhập email');
                e.preventDefault();
                return;
            }
            
            if (!isValidEmail(email)) {
                alert('Email không hợp lệ');
                e.preventDefault();
                return;
            }
        });
        
        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }
        
        // Auto-hide alerts
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        if (alert.parentNode) {
                            alert.parentNode.removeChild(alert);
                        }
                    }, 300);
                }, 5000);
            });
        });
        
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
            console.log('🚀 User Edit - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ User Edit - Ready!');
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const activeTab = document.querySelector('.form-section.active');
                const form = activeTab.querySelector('form');
                if (form) {
                    form.submit();
                }
            }
            
            // Escape to go back
            if (e.key === 'Escape') {
                window.location.href = 'users.php';
            }
        });
        
        // Format currency input
        const amountInput = document.getElementById('amount');
        if (amountInput) {
            amountInput.addEventListener('input', function(e) {
                // Remove all non-digits
                let value = e.target.value.replace(/\D/g, '');
                
                // Store raw value in data attribute
                e.target.dataset.rawValue = value;
                
                // Format for display
                if (value) {
                    const formatted = parseInt(value).toLocaleString('vi-VN');
                    e.target.value = formatted;
                } else {
                    e.target.value = '';
                }
            });
            
            // Before form submit, set raw value
            amountInput.closest('form').addEventListener('submit', function(e) {
                const rawValue = amountInput.dataset.rawValue || '';
                amountInput.value = rawValue;
            });
            
            // When input loses focus, ensure proper formatting
            amountInput.addEventListener('blur', function(e) {
                const rawValue = e.target.dataset.rawValue || '';
                if (rawValue) {
                    const formatted = parseInt(rawValue).toLocaleString('vi-VN');
                    e.target.value = formatted;
                }
            });
            
            // When input gets focus, show raw number for easier editing
            amountInput.addEventListener('focus', function(e) {
                const rawValue = e.target.dataset.rawValue || '';
                if (rawValue) {
                    e.target.value = rawValue;
                }
            });
        }
        
        // Confirmation for destructive actions
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const action = form.querySelector('input[name="action"]')?.value;
                
                if (action === 'reset_password') {
                    if (!confirm('Bạn có chắc chắn muốn đặt lại mật khẩu? Người dùng sẽ phải sử dụng mật khẩu mới để đăng nhập.')) {
                        e.preventDefault();
                    }
                } else if (action === 'update_balance') {
                    const balanceAction = form.querySelector('select[name="balance_action"]').value;
                    const amount = form.querySelector('input[name="amount"]').value;
                    
                    if (!confirm(`Bạn có chắc chắn muốn ${balanceAction === 'add' ? 'cộng' : balanceAction === 'subtract' ? 'trừ' : 'thiết lập'} ${parseInt(amount).toLocaleString('vi-VN')}₫?`)) {
                        e.preventDefault();
                    }
                }
            });
        });
    </script>
</body>
</html>