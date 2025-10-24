<?php
session_start();
require_once 'config.php';

// Set page-specific variables
$current_page = 'profile';
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

// Handle form submissions
$message = '';
$message_type = '';

// Update profile information
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'update_profile':
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ?, 
                                     city = ?, state = ?, country = ?, postal_code = ? WHERE id = ?");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['email'],
                    $_POST['phone'],
                    $_POST['address'],
                    $_POST['city'],
                    $_POST['state'],
                    $_POST['country'],
                    $_POST['postal_code'],
                    $user_id
                ]);
                $message = 'Cập nhật thông tin thành công!';
                $message_type = 'success';
                $_SESSION['user_name'] = $_POST['name']; // Update session
                break;

            case 'change_password':
                $current_password = $_POST['current_password'];
                $new_password = $_POST['new_password'];
                $confirm_password = $_POST['confirm_password'];

                // Verify current password
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();

                if (!password_verify($current_password, $user['password'])) {
                    $message = 'Mật khẩu hiện tại không đúng!';
                    $message_type = 'error';
                } elseif ($new_password !== $confirm_password) {
                    $message = 'Mật khẩu xác nhận không khớp!';
                    $message_type = 'error';
                } elseif (strlen($new_password) < 6) {
                    $message = 'Mật khẩu mới phải có ít nhất 6 ký tự!';
                    $message_type = 'error';
                } else {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashed_password, $user_id]);
                    $message = 'Đổi mật khẩu thành công!';
                    $message_type = 'success';
                }
                break;

            case 'add_address':
                // Get or create default state_id
                $state_id = 1; // Default state ID
                if (!empty($_POST['state'])) {
                    // Try to find existing state or create new one
                    $state_check = $pdo->prepare("SELECT id FROM states WHERE name = ? LIMIT 1");
                    $state_check->execute([$_POST['state']]);
                    $existing_state = $state_check->fetch();

                    if ($existing_state) {
                        $state_id = $existing_state['id'];
                    }
                }

                // Get or create default country_id
                $country_id = 1; // Default country ID
                if (!empty($_POST['country'])) {
                    $country_check = $pdo->prepare("SELECT id FROM countries WHERE name = ? LIMIT 1");
                    $country_check->execute([$_POST['country']]);
                    $existing_country = $country_check->fetch();

                    if ($existing_country) {
                        $country_id = $existing_country['id'];
                    }
                }

                // Get or create default city_id
                $city_id = null;
                if (!empty($_POST['city'])) {
                    $city_check = $pdo->prepare("SELECT id FROM cities WHERE name = ? LIMIT 1");
                    $city_check->execute([$_POST['city']]);
                    $existing_city = $city_check->fetch();

                    if ($existing_city) {
                        $city_id = $existing_city['id'];
                    }
                }

                // If set as default, unset other defaults first
                if (isset($_POST['set_default'])) {
                    $stmt = $pdo->prepare("UPDATE addresses SET set_default = 0 WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                }

                $stmt = $pdo->prepare("INSERT INTO addresses (user_id, address, country_id, state_id, city_id, 
                                     postal_code, phone, set_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $user_id,
                    $_POST['address'],
                    $country_id,
                    $state_id, // Always provide a valid state_id
                    $city_id,
                    $_POST['postal_code'],
                    $_POST['phone'],
                    isset($_POST['set_default']) ? 1 : 0
                ]);

                $message = 'Thêm địa chỉ thành công!';
                $message_type = 'success';
                break;

            case 'delete_address':
                $address_id = $_POST['address_id'];

                // Verify address belongs to user
                $verify_stmt = $pdo->prepare("SELECT id FROM addresses WHERE id = ? AND user_id = ?");
                $verify_stmt->execute([$address_id, $user_id]);

                if ($verify_stmt->fetch()) {
                    $delete_stmt = $pdo->prepare("DELETE FROM addresses WHERE id = ? AND user_id = ?");
                    $delete_stmt->execute([$address_id, $user_id]);
                    $message = 'Xóa địa chỉ thành công!';
                    $message_type = 'success';
                } else {
                    $message = 'Không tìm thấy địa chỉ!';
                    $message_type = 'error';
                }
                break;
        }
    } catch (PDOException $e) {
        $message = 'Có lỗi xảy ra: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Get user information
try {
    $stmt = $pdo->prepare("SELECT u.*, up.file_name as avatar_file FROM users u 
                          LEFT JOIN uploads up ON u.avatar = up.id AND up.deleted_at IS NULL 
                          WHERE u.id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    $user = null;
}

// Get user addresses
try {
    $stmt = $pdo->prepare("SELECT * FROM addresses WHERE user_id = ? ORDER BY set_default DESC, created_at DESC");
    $stmt->execute([$user_id]);
    $addresses = $stmt->fetchAll();
} catch (PDOException $e) {
    $addresses = [];
}

// Get recent orders
try {
    $stmt = $pdo->prepare("SELECT o.*, COUNT(od.id) as item_count FROM orders o 
                          LEFT JOIN order_details od ON o.id = od.order_id 
                          WHERE o.user_id = ? 
                          GROUP BY o.id 
                          ORDER BY o.created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $recent_orders = $stmt->fetchAll();
} catch (PDOException $e) {
    $recent_orders = [];
}

// Get order statistics
try {
    $stmt = $pdo->prepare("SELECT 
                          COUNT(*) as total_orders,
                          SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) as completed_orders,
                          SUM(CASE WHEN delivery_status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                          SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_orders
                          FROM orders WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $order_stats = $stmt->fetch();
} catch (PDOException $e) {
    $order_stats = ['total_orders' => 0, 'completed_orders' => 0, 'pending_orders' => 0, 'unpaid_orders' => 0];
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hồ sơ cá nhân - TikTok Shop</title>
    <meta name="description" content="Quản lý thông tin cá nhân, địa chỉ giao hàng và lịch sử đơn hàng">
    <link rel="stylesheet" href="asset/base.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <style>
    .profile-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        display: grid;
        grid-template-columns: 300px 1fr;
        gap: 20px;
    }

    .profile-sidebar {
        background: white;
        border-radius: 12px;
        padding: 20px;
        height: fit-content;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .profile-avatar {
        text-align: center;
        margin-bottom: 20px;
    }

    .avatar-wrapper {
        position: relative;
        display: inline-block;
        margin-bottom: 15px;
    }

    .avatar-img {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #fe2c55;
    }

    .avatar-placeholder {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 40px;
        border: 3px solid #fe2c55;
    }

    .avatar-upload {
        position: absolute;
        bottom: 0;
        right: 0;
        background: #fe2c55;
        color: white;
        border: none;
        border-radius: 50%;
        width: 30px;
        height: 30px;
        cursor: pointer;
        font-size: 12px;
    }

    .user-info h3 {
        margin: 0 0 5px 0;
        color: #333;
    }

    .user-info p {
        margin: 0;
        color: #666;
        font-size: 14px;
    }

    .profile-nav {
        list-style: none;
        padding: 0;
        margin: 20px 0 0 0;
    }

    .profile-nav li {
        margin-bottom: 8px;
    }

    .profile-nav a {
        display: block;
        padding: 12px 15px;
        text-decoration: none;
        color: #333;
        border-radius: 8px;
        transition: all 0.3s;
    }

    .profile-nav a:hover,
    .profile-nav a.active {
        background: #fe2c55;
        color: white;
    }

    .profile-content {
        background: white;
        border-radius: 12px;
        padding: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .profile-section {
        display: none;
    }

    .profile-section.active {
        display: block;
    }

    .section-title {
        font-size: 24px;
        color: #333;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #fe2c55;
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group.full-width {
        grid-column: 1 / -1;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        color: #333;
        font-weight: 500;
    }

    .form-group input,
    .form-group textarea,
    .form-group select {
        width: 100%;
        padding: 12px;
        border: 2px solid #e0e6ed;
        border-radius: 8px;
        font-size: 14px;
        transition: border-color 0.3s;
    }

    .form-group input:focus,
    .form-group textarea:focus,
    .form-group select:focus {
        outline: none;
        border-color: #fe2c55;
    }

    .btn {
        background: #fe2c55;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        transition: background 0.3s;
    }

    .btn:hover {
        background: #e91e63;
    }

    .btn-secondary {
        background: #6c757d;
    }

    .btn-secondary:hover {
        background: #5a6268;
    }

    .btn-danger {
        background: #dc3545;
    }

    .btn-danger:hover {
        background: #c82333;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 12px;
        text-align: center;
    }

    .stat-number {
        font-size: 32px;
        font-weight: bold;
        color: #fe2c55;
        margin-bottom: 5px;
    }

    .stat-label {
        color: #666;
        font-size: 14px;
    }

    .address-list {
        display: grid;
        gap: 15px;
    }

    .address-card {
        border: 2px solid #e0e6ed;
        border-radius: 12px;
        padding: 20px;
        position: relative;
    }

    .address-card.default {
        border-color: #fe2c55;
        background: #fff5f7;
    }

    .address-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background: #fe2c55;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
    }

    .address-actions {
        margin-top: 15px;
        display: flex;
        gap: 10px;
    }

    .address-actions button {
        font-size: 12px;
        padding: 6px 12px;
    }

    .order-list {
        display: grid;
        gap: 15px;
    }

    .order-card {
        border: 2px solid #e0e6ed;
        border-radius: 12px;
        padding: 20px;
    }

    .order-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .order-id {
        font-weight: bold;
        color: #333;
    }

    .order-status {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .status-pending {
        background: #fff3cd;
        color: #856404;
    }

    .status-delivered {
        background: #d1edff;
        color: #084298;
    }

    .status-cancelled {
        background: #f8d7da;
        color: #721c24;
    }

    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .alert-success {
        background: #d1edff;
        color: #084298;
        border: 1px solid #b6d7ff;
    }

    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c2c7;
    }

    @media (max-width: 768px) {
        .profile-container {
            grid-template-columns: 1fr;
            padding: 10px;
        }

        .form-grid {
            grid-template-columns: 1fr;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .address-actions {
            flex-direction: column;
        }
    }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>

    <div class="profile-container">
        <!-- Sidebar -->
        <aside class="profile-sidebar">
            <div class="profile-avatar">
                <div class="avatar-wrapper">
                    <?php if ($user && $user['avatar_file']): ?>
                    <img src="<?php echo htmlspecialchars($user['avatar_file']); ?>" alt="Avatar" class="avatar-img">
                    <?php else: ?>
                    <div class="avatar-placeholder">👤</div>
                    <?php endif; ?>
                    <button class="avatar-upload" onclick="document.getElementById('avatar-upload').click()">📷</button>
                    <input type="file" id="avatar-upload" style="display: none;" accept="image/*">
                </div>
                <div class="user-info">
                    <h3><?php echo htmlspecialchars($user['name'] ?? 'Người dùng'); ?></h3>
                    <p><?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
                    <?php if ($user['balance'] > 0): ?>
                    <p>Số dư: <strong><?php echo number_format($user['balance'], 0, ',', '.'); ?>đ</strong></p>
                    <?php endif; ?>
                </div>
            </div>

            <nav>
                <ul class="profile-nav">
                    <li><a href="#" onclick="showSection('overview')" class="nav-link active">Tổng quan</a></li>
                    <li><a href="#" onclick="showSection('personal')" class="nav-link">Thông tin cá nhân</a></li>
                    <li><a href="#" onclick="showSection('addresses')" class="nav-link">Địa chỉ giao hàng</a></li>
                    <li><a href="#" onclick="showSection('orders')" class="nav-link">Đơn hàng của tôi</a></li>
                    <li><a href="#" onclick="showSection('password')" class="nav-link">Đổi mật khẩu</a></li>
                    <li><a href="logout.php" style="color: #dc3545;">Đăng xuất</a></li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="profile-content">
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <!-- Overview Section -->
            <section id="overview" class="profile-section active">
                <h2 class="section-title">Tổng quan tài khoản</h2>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $order_stats['total_orders']; ?></div>
                        <div class="stat-label">Tổng đơn hàng</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $order_stats['completed_orders']; ?></div>
                        <div class="stat-label">Đã hoàn thành</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $order_stats['pending_orders']; ?></div>
                        <div class="stat-label">Đang xử lý</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($user['balance'] ?? 0, 0, ',', '.'); ?>đ</div>
                        <div class="stat-label">Số dư ví</div>
                    </div>
                </div>

                <h3>Đơn hàng gần đây</h3>
                <?php if (!empty($recent_orders)): ?>
                <div class="order-list">
                    <?php foreach ($recent_orders as $order): ?>
                    <div class="order-card">
                        <div class="order-header">
                            <span class="order-id">#<?php echo $order['id']; ?></span>
                            <span class="order-status status-<?php echo $order['delivery_status']; ?>">
                                <?php
                                        $status_map = [
                                            'pending' => 'Đang xử lý',
                                            'delivered' => 'Đã giao',
                                            'cancelled' => 'Đã hủy'
                                        ];
                                        echo $status_map[$order['delivery_status']] ?? $order['delivery_status'];
                                        ?>
                            </span>
                        </div>
                        <p>Số lượng: <?php echo $order['item_count']; ?> sản phẩm</p>
                        <p>Tổng tiền: <strong><?php echo number_format($order['grand_total'], 0, ',', '.'); ?>đ</strong>
                        </p>
                        <p>Ngày đặt: <?php echo date('d/m/Y H:i', $order['date']); ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p>Bạn chưa có đơn hàng nào.</p>
                <?php endif; ?>
            </section>

            <!-- Personal Information Section -->
            <section id="personal" class="profile-section">
                <h2 class="section-title">Thông tin cá nhân</h2>

                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name">Họ và tên *</label>
                            <input type="text" id="name" name="name"
                                value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email"
                                value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="phone">Số điện thoại</label>
                            <input type="text" id="phone" name="phone"
                                value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="postal_code">Mã bưu điện</label>
                            <input type="text" id="postal_code" name="postal_code"
                                value="<?php echo htmlspecialchars($user['postal_code'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="city">Thành phố</label>
                            <input type="text" id="city" name="city"
                                value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="state">Tỉnh/Thành</label>
                            <input type="text" id="state" name="state"
                                value="<?php echo htmlspecialchars($user['state'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="country">Quốc gia</label>
                            <input type="text" id="country" name="country"
                                value="<?php echo htmlspecialchars($user['country'] ?? ''); ?>">
                        </div>

                        <div class="form-group full-width">
                            <label for="address">Địa chỉ chi tiết</label>
                            <textarea id="address" name="address"
                                rows="3"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <button type="submit" class="btn">Cập nhật thông tin</button>
                </form>
            </section>

            <!-- Addresses Section -->
            <section id="addresses" class="profile-section">
                <h2 class="section-title">Địa chỉ giao hàng</h2>

                <button class="btn" onclick="showAddressForm()" style="margin-bottom: 20px;">+ Thêm địa chỉ mới</button>

                <div id="address-form"
                    style="display: none; margin-bottom: 30px; padding: 20px; border: 2px solid #e0e6ed; border-radius: 12px;">
                    <h3>Thêm địa chỉ mới</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_address">

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="addr_phone">Số điện thoại *</label>
                                <input type="text" id="addr_phone" name="phone" required>
                            </div>

                            <div class="form-group">
                                <label for="addr_postal_code">Mã bưu điện</label>
                                <input type="text" id="addr_postal_code" name="postal_code">
                            </div>

                            <div class="form-group">
                                <label for="addr_city">Thành phố</label>
                                <input type="text" id="addr_city" name="city">
                            </div>

                            <div class="form-group">
                                <label for="addr_state">Tỉnh/Thành</label>
                                <input type="text" id="addr_state" name="state">
                            </div>

                            <div class="form-group">
                                <label for="addr_country">Quốc gia</label>
                                <input type="text" id="addr_country" name="country" value="Việt Nam">
                            </div>

                            <div class="form-group full-width">
                                <label for="addr_address">Địa chỉ chi tiết *</label>
                                <textarea id="addr_address" name="address" rows="3" required
                                    placeholder="Số nhà, tên đường, phường/xã..."></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label>
                                    <input type="checkbox" name="set_default"> Đặt làm địa chỉ mặc định
                                </label>
                            </div>
                        </div>

                        <button type="submit" class="btn">Thêm địa chỉ</button>
                        <button type="button" class="btn btn-secondary" onclick="hideAddressForm()">Hủy</button>
                    </form>
                </div>

                <?php if (!empty($addresses)): ?>
                <div class="address-list">
                    <?php foreach ($addresses as $address): ?>
                    <div class="address-card <?php echo $address['set_default'] ? 'default' : ''; ?>">
                        <?php if ($address['set_default']): ?>
                        <span class="address-badge">Mặc định</span>
                        <?php endif; ?>

                        <p><strong>Điện thoại:</strong> <?php echo htmlspecialchars($address['phone']); ?></p>
                        <p><strong>Địa chỉ:</strong> <?php echo htmlspecialchars($address['address']); ?></p>
                        <?php if ($address['postal_code']): ?>
                        <p><strong>Mã bưu điện:</strong> <?php echo htmlspecialchars($address['postal_code']); ?></p>
                        <?php endif; ?>

                        <div class="address-actions">
                            <button class="btn btn-secondary"
                                onclick="editAddress(<?php echo $address['id']; ?>)">Sửa</button>
                            <button class="btn btn-danger"
                                onclick="deleteAddress(<?php echo $address['id']; ?>)">Xóa</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p>Bạn chưa có địa chỉ giao hàng nào.</p>
                <?php endif; ?>
            </section>

            <!-- Orders Section -->
            <section id="orders" class="profile-section">
                <h2 class="section-title">Đơn hàng của tôi</h2>

                <div style="margin-bottom: 20px;">
                    <button class="btn" onclick="loadOrders('all')">Tất cả</button>
                    <button class="btn btn-secondary" onclick="loadOrders('pending')">Đang xử lý</button>
                    <button class="btn btn-secondary" onclick="loadOrders('delivered')">Đã giao</button>
                    <button class="btn btn-secondary" onclick="loadOrders('cancelled')">Đã hủy</button>
                </div>

                <div id="orders-container">
                    <!-- Orders will be loaded here via AJAX -->
                    <p>Đang tải đơn hàng...</p>
                </div>
            </section>

            <!-- Password Section -->
            <section id="password" class="profile-section">
                <h2 class="section-title">Đổi mật khẩu</h2>

                <form method="POST" style="max-width: 500px;">
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-group">
                        <label for="current_password">Mật khẩu hiện tại *</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password">Mật khẩu mới *</label>
                        <input type="password" id="new_password" name="new_password" required minlength="6">
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Xác nhận mật khẩu mới *</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                    </div>

                    <button type="submit" class="btn">Đổi mật khẩu</button>
                </form>
            </section>
        </main>
    </div>

    <?php include 'footer.php'; ?>

    <script>
    function showSection(sectionId) {
        // Hide all sections
        document.querySelectorAll('.profile-section').forEach(section => {
            section.classList.remove('active');
        });

        // Show selected section
        document.getElementById(sectionId).classList.add('active');

        // Update navigation
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.remove('active');
        });
        event.target.classList.add('active');
    }

    function showAddressForm() {
        document.getElementById('address-form').style.display = 'block';
    }

    function hideAddressForm() {
        document.getElementById('address-form').style.display = 'none';
        // Reset form
        document.querySelector('#address-form form').reset();
    }

    function editAddress(addressId) {
        // This would open an edit form - for now just show alert
        alert('Tính năng sửa địa chỉ đang được phát triển!');
    }

    function deleteAddress(addressId) {
        if (confirm('Bạn có chắc muốn xóa địa chỉ này?')) {
            // Create a form to submit the delete request
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                    <input type="hidden" name="action" value="delete_address">
                    <input type="hidden" name="address_id" value="${addressId}">
                `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    function loadOrders(status = 'all') {
        const container = document.getElementById('orders-container');
        container.innerHTML = '<p>Đang tải...</p>';

        // Update button states
        document.querySelectorAll('#orders button').forEach(btn => {
            btn.classList.remove('btn');
            btn.classList.add('btn', 'btn-secondary');
        });
        event.target.classList.remove('btn-secondary');
        event.target.classList.add('btn');

        // For now, just show a message since we don't have load-orders.php
        setTimeout(() => {
            container.innerHTML = '<p>Tính năng load đơn hàng đang được phát triển!</p>';
        }, 1000);
    }

    // Avatar upload functionality
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('avatar-upload').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // For now, just show alert since we don't have upload-avatar.php
                alert('Tính năng upload avatar đang được phát triển!');
            }
        });
    });
    </script>
</body>

</html>