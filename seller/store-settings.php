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

// Messages
$success_message = '';
$error_message = '';

// Get seller current balance
try {
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$seller_id]);
    $seller_balance = $stmt->fetchColumn() ?? 0;
} catch (PDOException $e) {
    $seller_balance = 0;
}

// Get seller information
try {
    $stmt = $pdo->prepare("
        SELECT u.*, up.file_name as avatar_file
        FROM users u 
        LEFT JOIN uploads up ON u.avatar = up.id AND up.deleted_at IS NULL
        WHERE u.id = ?
    ");
    $stmt->execute([$seller_id]);
    $seller_info = $stmt->fetch();
    
    if (!$seller_info) {
        $error_message = "Không thể tải thông tin seller.";
        $seller_info = [];
    }
} catch (PDOException $e) {
    $error_message = "Có lỗi khi tải dữ liệu.";
    $seller_info = [];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Handle basic info update
    if (isset($_POST['update_basic_info'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $postal_code = trim($_POST['postal_code'] ?? '');
        
        // Basic validation
        if (empty($name)) {
            $error_message = "Tên không được để trống.";
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Email không hợp lệ.";
        } else {
            try {
                // Check if email already exists (excluding current user)
                if (!empty($email)) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $seller_id]);
                    if ($stmt->fetch()) {
                        $error_message = "Email này đã được sử dụng bởi người dùng khác.";
                    }
                }
                
                if (empty($error_message)) {
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET name = ?, email = ?, phone = ?, address = ?, 
                            city = ?, state = ?, country = ?, postal_code = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $name, $email, $phone, $address, 
                        $city, $state, $country, $postal_code, 
                        $seller_id
                    ]);
                    
                    // Update session name if changed
                    $_SESSION['user_name'] = $name;
                    $seller_name = $name;
                    
                    $success_message = "Cập nhật thông tin cơ bản thành công!";
                    
                    // Refresh seller info
                    $stmt = $pdo->prepare("
                        SELECT u.*, up.file_name as avatar_file
                        FROM users u 
                        LEFT JOIN uploads up ON u.avatar = up.id AND up.deleted_at IS NULL
                        WHERE u.id = ?
                    ");
                    $stmt->execute([$seller_id]);
                    $seller_info = $stmt->fetch();
                }
            } catch (PDOException $e) {
                $error_message = "Có lỗi xảy ra khi cập nhật thông tin.";
                error_log("Update basic info error: " . $e->getMessage());
            }
        }
    }
    
    // Handle avatar upload
    elseif (isset($_POST['update_avatar'])) {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/avatars/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                $error_message = "Chỉ cho phép upload file: JPG, JPEG, PNG, GIF.";
            } elseif ($_FILES['avatar']['size'] > 5 * 1024 * 1024) { // 5MB
                $error_message = "File quá lớn. Kích thước tối đa 5MB.";
            } else {
                try {
                    $filename = 'avatar_' . $seller_id . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['avatar']['tmp_name'], $upload_path)) {
                        // Insert into uploads table
                        $stmt = $pdo->prepare("
                            INSERT INTO uploads (file_original_name, file_name, user_id, file_size, extension, type) 
                            VALUES (?, ?, ?, ?, ?, 'image')
                        ");
                        $stmt->execute([
                            $_FILES['avatar']['name'],
                            'uploads/avatars/' . $filename,
                            $seller_id,
                            $_FILES['avatar']['size'],
                            $file_extension
                        ]);
                        
                        $upload_id = $pdo->lastInsertId();
                        
                        // Update user avatar
                        $stmt = $pdo->prepare("UPDATE users SET avatar = ?, avatar_original = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$upload_id, 'uploads/avatars/' . $filename, $seller_id]);
                        
                        $success_message = "Cập nhật avatar thành công!";
                        
                        // Refresh seller info
                        $stmt = $pdo->prepare("
                            SELECT u.*, up.file_name as avatar_file
                            FROM users u 
                            LEFT JOIN uploads up ON u.avatar = up.id AND up.deleted_at IS NULL
                            WHERE u.id = ?
                        ");
                        $stmt->execute([$seller_id]);
                        $seller_info = $stmt->fetch();
                    } else {
                        $error_message = "Không thể upload file. Vui lòng thử lại.";
                    }
                } catch (PDOException $e) {
                    $error_message = "Có lỗi xảy ra khi cập nhật avatar.";
                    error_log("Update avatar error: " . $e->getMessage());
                }
            }
        } else {
            $error_message = "Vui lòng chọn file avatar để upload.";
        }
    }
    
    // Handle password change
    elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_message = "Vui lòng điền đầy đủ thông tin mật khẩu.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "Mật khẩu mới và xác nhận mật khẩu không khớp.";
        } elseif (strlen($new_password) < 6) {
            $error_message = "Mật khẩu mới phải có ít nhất 6 ký tự.";
        } else {
            try {
                // Verify current password
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$seller_id]);
                $stored_password = $stmt->fetchColumn();
                
                if (!password_verify($current_password, $stored_password)) {
                    $error_message = "Mật khẩu hiện tại không đúng.";
                } else {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$hashed_password, $seller_id]);
                    
                    $success_message = "Đổi mật khẩu thành công!";
                }
            } catch (PDOException $e) {
                $error_message = "Có lỗi xảy ra khi đổi mật khẩu.";
                error_log("Change password error: " . $e->getMessage());
            }
        }
    }
}

// Get available countries
$countries = [
    'VN' => 'Việt Nam',
    'US' => 'United States',
    'CN' => 'China',
    'JP' => 'Japan',
    'KR' => 'South Korea',
    'TH' => 'Thailand',
    'SG' => 'Singapore',
    'MY' => 'Malaysia',
    'ID' => 'Indonesia',
    'PH' => 'Philippines'
];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cài đặt cửa hàng - TikTok Shop Seller</title>
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

    .header-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .user-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        background: linear-gradient(45deg, #ff0050, #ff4d6d);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 16px;
        overflow: hidden;
    }

    .user-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .user-details h3 {
        font-size: 14px;
        font-weight: 600;
        color: #1f2937;
        margin: 0;
    }

    .user-balance {
        font-size: 12px;
        color: #10b981;
        font-weight: 500;
    }

    .content-wrapper {
        padding: 24px;
    }

    /* Settings Navigation */
    .settings-nav {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        margin-bottom: 24px;
        overflow: hidden;
    }

    .nav-tabs {
        display: flex;
        background: #f9fafb;
        border-bottom: 1px solid #e5e7eb;
    }

    .nav-tab {
        padding: 16px 24px;
        background: none;
        border: none;
        font-size: 14px;
        font-weight: 500;
        color: #6b7280;
        cursor: pointer;
        transition: all 0.3s ease;
        border-bottom: 3px solid transparent;
    }

    .nav-tab.active {
        color: #ff0050;
        background: white;
        border-bottom-color: #ff0050;
    }

    .nav-tab:hover {
        color: #ff0050;
        background: white;
    }

    /* Settings Content */
    .settings-content {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    .tab-content {
        display: none;
        padding: 24px;
    }

    .tab-content.active {
        display: block;
    }

    .section-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 8px;
    }

    .section-subtitle {
        font-size: 14px;
        color: #6b7280;
        margin-bottom: 24px;
    }

    /* Form Styles */
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group.full-width {
        grid-column: 1 / -1;
    }

    .form-label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #374151;
        margin-bottom: 8px;
    }

    .form-input {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .form-input:focus {
        outline: none;
        border-color: #ff0050;
        box-shadow: 0 0 0 3px rgba(255, 0, 80, 0.1);
    }

    .form-select {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        background: white;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .form-select:focus {
        outline: none;
        border-color: #ff0050;
        box-shadow: 0 0 0 3px rgba(255, 0, 80, 0.1);
    }

    .form-textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        resize: vertical;
        min-height: 100px;
        transition: all 0.3s ease;
    }

    .form-textarea:focus {
        outline: none;
        border-color: #ff0050;
        box-shadow: 0 0 0 3px rgba(255, 0, 80, 0.1);
    }

    .form-file {
        width: 100%;
        padding: 12px 16px;
        border: 2px dashed #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s ease;
        background: #f9fafb;
        text-align: center;
    }

    .form-file:hover {
        border-color: #ff0050;
        background: #fef2f2;
    }

    .form-note {
        font-size: 12px;
        color: #6b7280;
        margin-top: 4px;
    }

    /* Avatar Upload */
    .avatar-upload {
        display: flex;
        align-items: center;
        gap: 20px;
        padding: 20px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #f9fafb;
        margin-bottom: 24px;
    }

    .current-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        overflow: hidden;
        background: linear-gradient(45deg, #ff0050, #ff4d6d);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 32px;
        font-weight: 600;
    }

    .current-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .avatar-info h3 {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 4px;
    }

    .avatar-info p {
        font-size: 14px;
        color: #6b7280;
        margin-bottom: 12px;
    }

    .avatar-actions {
        display: flex;
        gap: 12px;
    }

    /* Buttons */
    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        text-align: center;
        transition: all 0.3s ease;
    }

    .btn-primary {
        background: #ff0050;
        color: white;
    }

    .btn-primary:hover {
        background: #dc2626;
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: #6b7280;
        color: white;
    }

    .btn-secondary:hover {
        background: #4b5563;
    }

    .btn-outline {
        background: transparent;
        color: #6b7280;
        border: 1px solid #d1d5db;
    }

    .btn-outline:hover {
        background: #f9fafb;
        border-color: #9ca3af;
    }

    .btn-small {
        padding: 8px 16px;
        font-size: 12px;
    }

    .form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        padding-top: 20px;
        border-top: 1px solid #e5e7eb;
        margin-top: 24px;
    }

    /* Messages */
    .message {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
        font-weight: 500;
    }

    .message.success {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #34d399;
    }

    .message.error {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #f87171;
    }

    /* Security Settings */
    .security-item {
        padding: 20px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        margin-bottom: 16px;
        background: #f9fafb;
    }

    .security-header {
        display: flex;
        justify-content: between;
        align-items: center;
        margin-bottom: 8px;
    }

    .security-title {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
    }

    .security-description {
        font-size: 14px;
        color: #6b7280;
        margin-bottom: 16px;
    }

    .password-requirements {
        background: #fffbeb;
        border: 1px solid #fbbf24;
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 16px;
    }

    .password-requirements h4 {
        font-size: 14px;
        font-weight: 600;
        color: #92400e;
        margin-bottom: 8px;
    }

    .password-requirements ul {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .password-requirements li {
        font-size: 12px;
        color: #92400e;
        padding: 2px 0;
        position: relative;
        padding-left: 16px;
    }

    .password-requirements li::before {
        content: '•';
        position: absolute;
        left: 0;
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .form-grid {
            grid-template-columns: 1fr;
        }
    }

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

        .nav-tabs {
            flex-direction: column;
        }

        .nav-tab {
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            border-right: none;
        }

        .nav-tab.active {
            border-bottom: 1px solid #e5e7eb;
            border-left: 3px solid #ff0050;
        }

        .avatar-upload {
            flex-direction: column;
            text-align: center;
        }

        .avatar-actions {
            justify-content: center;
        }

        .form-actions {
            flex-direction: column;
        }

        .user-details {
            display: none;
        }

        .tab-content {
            padding: 16px;
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
                    <h1>Cài đặt cửa hàng</h1>
                </div>
                <div class="header-right">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php if (!empty($seller_info['avatar_file'])): ?>
                            <img src="../<?php echo htmlspecialchars($seller_info['avatar_file']); ?>" alt="Avatar" />
                            <?php else: ?>
                            <?php echo strtoupper(substr($seller_name, 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-details">
                            <h3><?php echo htmlspecialchars($seller_name); ?></h3>
                            <div class="user-balance">Số dư: <?php echo number_format($seller_balance, 0, ',', '.'); ?>đ
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-wrapper">
                <!-- Messages -->
                <?php if ($success_message): ?>
                <div class="message success"><?php echo $success_message; ?></div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="message error"><?php echo $error_message; ?></div>
                <?php endif; ?>

                <!-- Settings Navigation -->
                <div class="settings-nav">
                    <div class="nav-tabs">
                        <button class="nav-tab active" onclick="showTab('basic-info')">Thông tin cơ bản</button>
                        <button class="nav-tab" onclick="showTab('avatar')">Avatar & Hình ảnh</button>
                        <button class="nav-tab" onclick="showTab('security')">Bảo mật</button>
                        <button class="nav-tab" onclick="showTab('notifications')">Thông báo</button>
                    </div>
                </div>

                <!-- Settings Content -->
                <div class="settings-content">
                    <!-- Basic Info Tab -->
                    <div id="basic-info" class="tab-content active">
                        <h2 class="section-title">Thông tin cơ bản</h2>
                        <p class="section-subtitle">Cập nhật thông tin cơ bản của cửa hàng và thông tin liên hệ</p>

                        <form method="POST">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Tên cửa hàng *</label>
                                    <input type="text" name="name" class="form-input"
                                        value="<?php echo htmlspecialchars($seller_info['name'] ?? ''); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Email liên hệ</label>
                                    <input type="email" name="email" class="form-input"
                                        value="<?php echo htmlspecialchars($seller_info['email'] ?? ''); ?>">
                                    <div class="form-note">Email này sẽ được hiển thị cho khách hàng</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Số điện thoại</label>
                                    <input type="tel" name="phone" class="form-input"
                                        value="<?php echo htmlspecialchars($seller_info['phone'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Quốc gia</label>
                                    <select name="country" class="form-select">
                                        <option value="">Chọn quốc gia</option>
                                        <?php foreach ($countries as $code => $name): ?>
                                        <option value="<?php echo $code; ?>"
                                            <?php echo ($seller_info['country'] ?? '') === $code ? 'selected' : ''; ?>>
                                            <?php echo $name; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Tỉnh/Thành phố</label>
                                    <input type="text" name="state" class="form-input"
                                        value="<?php echo htmlspecialchars($seller_info['state'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Quận/Huyện</label>
                                    <input type="text" name="city" class="form-input"
                                        value="<?php echo htmlspecialchars($seller_info['city'] ?? ''); ?>">
                                </div>

                                <div class="form-group full-width">
                                    <label class="form-label">Địa chỉ cửa hàng</label>
                                    <input type="text" name="address" class="form-input"
                                        value="<?php echo htmlspecialchars($seller_info['address'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Mã bưu điện</label>
                                    <input type="text" name="postal_code" class="form-input"
                                        value="<?php echo htmlspecialchars($seller_info['postal_code'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" name="update_basic_info" class="btn btn-primary">
                                    Lưu thay đổi
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Avatar Tab -->
                    <div id="avatar" class="tab-content">
                        <h2 class="section-title">Avatar & Hình ảnh</h2>
                        <p class="section-subtitle">Cập nhật avatar và hình ảnh đại diện cho cửa hàng</p>

                        <div class="avatar-upload">
                            <div class="current-avatar">
                                <?php if (!empty($seller_info['avatar_file'])): ?>
                                <img src="../<?php echo htmlspecialchars($seller_info['avatar_file']); ?>"
                                    alt="Current Avatar" />
                                <?php else: ?>
                                <?php echo strtoupper(substr($seller_name, 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="avatar-info">
                                <h3>Avatar cửa hàng</h3>
                                <p>Hình ảnh này sẽ được hiển thị trên trang cửa hàng và trong các đơn hàng</p>
                                <div class="avatar-actions">
                                    <button class="btn btn-outline btn-small" onclick="triggerFileUpload()">
                                        Thay đổi Avatar
                                    </button>
                                    <?php if (!empty($seller_info['avatar_file'])): ?>
                                    <button class="btn btn-secondary btn-small" onclick="removeAvatar()">
                                        Xóa Avatar
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <form method="POST" enctype="multipart/form-data" id="avatarForm">
                            <div class="form-group">
                                <label class="form-label">Chọn file avatar</label>
                                <input type="file" name="avatar" id="avatarInput" class="form-file"
                                    accept="image/jpeg,image/jpg,image/png,image/gif" style="display: none;"
                                    onchange="previewAvatar(this)">
                                <div class="form-note">
                                    Định dạng: JPG, JPEG, PNG, GIF. Kích thước tối đa: 5MB.
                                    Khuyến nghị: 400x400px hoặc tỉ lệ vuông.
                                </div>
                            </div>

                            <div id="avatarPreview" style="display: none; margin-top: 16px;">
                                <h4 style="margin-bottom: 8px;">Xem trước:</h4>
                                <img id="previewImage"
                                    style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb;">
                            </div>

                            <div class="form-actions">
                                <button type="submit" name="update_avatar" class="btn btn-primary">
                                    Cập nhật Avatar
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Security Tab -->
                    <div id="security" class="tab-content">
                        <h2 class="section-title">Bảo mật tài khoản</h2>
                        <p class="section-subtitle">Quản lý mật khẩu và các cài đặt bảo mật</p>

                        <div class="security-item">
                            <div class="security-header">
                                <h3 class="security-title">Đổi mật khẩu</h3>
                            </div>
                            <p class="security-description">
                                Cập nhật mật khẩu của bạn để bảo vệ tài khoản khỏi truy cập trái phép
                            </p>

                            <div class="password-requirements">
                                <h4>Yêu cầu mật khẩu:</h4>
                                <ul>
                                    <li>Ít nhất 6 ký tự</li>
                                    <li>Nên sử dụng kết hợp chữ hoa, chữ thường và số</li>
                                    <li>Tránh sử dụng thông tin cá nhân dễ đoán</li>
                                    <li>Không sử dụng mật khẩu đã từng bị lộ</li>
                                </ul>
                            </div>

                            <form method="POST">
                                <div class="form-group">
                                    <label class="form-label">Mật khẩu hiện tại *</label>
                                    <input type="password" name="current_password" class="form-input" required>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Mật khẩu mới *</label>
                                        <input type="password" name="new_password" class="form-input" minlength="6"
                                            required>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Xác nhận mật khẩu mới *</label>
                                        <input type="password" name="confirm_password" class="form-input" minlength="6"
                                            required>
                                    </div>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" name="change_password" class="btn btn-primary">
                                        Đổi mật khẩu
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="security-item">
                            <div class="security-header">
                                <h3 class="security-title">Hoạt động đăng nhập</h3>
                            </div>
                            <p class="security-description">
                                Theo dõi các phiên đăng nhập gần đây của tài khoản
                            </p>
                            <div
                                style="padding: 12px; background: #f3f4f6; border-radius: 6px; font-size: 14px; color: #6b7280;">
                                Đăng nhập lần cuối: <?php echo date('d/m/Y H:i', time()); ?> (Phiên hiện tại)
                            </div>
                        </div>
                    </div>

                    <!-- Notifications Tab -->
                    <div id="notifications" class="tab-content">
                        <h2 class="section-title">Cài đặt thông báo</h2>
                        <p class="section-subtitle">Quản lý các thông báo và cảnh báo từ hệ thống</p>

                        <div class="security-item">
                            <div class="security-header">
                                <h3 class="security-title">Thông báo đơn hàng</h3>
                            </div>
                            <p class="security-description">
                                Nhận thông báo khi có đơn hàng mới, thay đổi trạng thái đơn hàng
                            </p>
                            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" checked style="width: 16px; height: 16px;">
                                    <span style="font-size: 14px;">Email</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" checked style="width: 16px; height: 16px;">
                                    <span style="font-size: 14px;">Push notification</span>
                                </label>
                            </div>
                        </div>

                        <div class="security-item">
                            <div class="security-header">
                                <h3 class="security-title">Thông báo sản phẩm</h3>
                            </div>
                            <p class="security-description">
                                Nhận cảnh báo khi sản phẩm hết hàng, cần cập nhật thông tin
                            </p>
                            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" checked style="width: 16px; height: 16px;">
                                    <span style="font-size: 14px;">Cảnh báo hết hàng</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" style="width: 16px; height: 16px;">
                                    <span style="font-size: 14px;">Thông báo khuyến mãi</span>
                                </label>
                            </div>
                        </div>

                        <div class="security-item">
                            <div class="security-header">
                                <h3 class="security-title">Báo cáo và thống kê</h3>
                            </div>
                            <p class="security-description">
                                Nhận báo cáo định kỳ về doanh số, hiệu suất cửa hàng
                            </p>
                            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" checked style="width: 16px; height: 16px;">
                                    <span style="font-size: 14px;">Báo cáo tuần</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" style="width: 16px; height: 16px;">
                                    <span style="font-size: 14px;">Báo cáo tháng</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-primary">
                                Lưu cài đặt thông báo
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Tab navigation
    function showTab(tabId) {
        // Hide all tab contents
        const tabContents = document.querySelectorAll('.tab-content');
        tabContents.forEach(content => content.classList.remove('active'));

        // Remove active class from all tabs
        const tabs = document.querySelectorAll('.nav-tab');
        tabs.forEach(tab => tab.classList.remove('active'));

        // Show selected tab content
        document.getElementById(tabId).classList.add('active');

        // Add active class to clicked tab
        event.target.classList.add('active');
    }

    // Avatar upload functions
    function triggerFileUpload() {
        document.getElementById('avatarInput').click();
    }

    function previewAvatar(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('previewImage').src = e.target.result;
                document.getElementById('avatarPreview').style.display = 'block';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function removeAvatar() {
        if (confirm('Bạn có chắc muốn xóa avatar hiện tại?')) {
            // Create form to remove avatar
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="remove_avatar" value="1">';
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Password validation
    document.addEventListener('DOMContentLoaded', function() {
        const newPasswordInput = document.querySelector('input[name="new_password"]');
        const confirmPasswordInput = document.querySelector('input[name="confirm_password"]');

        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', function() {
                if (this.value !== newPasswordInput.value) {
                    this.setCustomValidity('Mật khẩu xác nhận không khớp');
                } else {
                    this.setCustomValidity('');
                }
            });
        }

        // File upload validation
        const avatarInput = document.getElementById('avatarInput');
        if (avatarInput) {
            avatarInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    // Check file size (5MB)
                    if (file.size > 5 * 1024 * 1024) {
                        alert('File quá lớn. Kích thước tối đa 5MB.');
                        this.value = '';
                        return;
                    }

                    // Check file type
                    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                    if (!allowedTypes.includes(file.type)) {
                        alert('Định dạng file không được hỗ trợ. Chỉ cho phép: JPG, JPEG, PNG, GIF.');
                        this.value = '';
                        return;
                    }
                }
            });
        }
    });
    </script>
</body>

</html>