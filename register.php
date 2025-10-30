<?php
session_start();
require_once 'config.php';
require_once 'csrf.php';

$error = '';
$success = '';

if ($_POST) {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Phiên làm việc không hợp lệ. Vui lòng thử lại.';
    } else {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $referral_code = trim($_POST['referral_code']);
    $user_type = 'customer'; // Mặc định là customer
    $passwordValidation = validatePasswordStrength($password);

    if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'Vui lòng nhập đầy đủ thông tin';
    } elseif ($password !== $confirm_password) {
        $error = 'Mật khẩu xác nhận không khớp';
    } elseif (strlen($password) < 6) {
        $error = 'Mật khẩu phải có ít nhất 6 ký tự';
    } elseif (!$passwordValidation['isValid']) {
        $error = $passwordValidation['message'];
    } else {
        try {
            // Kiểm tra email đã tồn tại chưa
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $error = 'Email này đã được sử dụng';
            } else {
                // Kiểm tra mã giới thiệu nếu có
                $referred_by = null;
                if (!empty($referral_code)) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE referral_code = ?");
                    $stmt->execute([$referral_code]);
                    $referrer = $stmt->fetch();
                    if ($referrer) {
                        $referred_by = $referrer['id'];
                    } else {
                        $error = 'Mã giới thiệu không hợp lệ';
                    }
                }

                if (empty($error)) {
                    // Tạo mã giới thiệu cho user mới
                    $new_referral_code = strtoupper(substr(md5($email . time()), 0, 8));

                    // Tạo tài khoản mới với đầy đủ thông tin
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, user_type, referred_by, referral_code, invite_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $email, $hashed_password, $user_type, $referred_by, $new_referral_code, '']);

                    // Regenerate session ID to prevent session fixation
                    session_regenerate_id(true);

                    $success = 'Đăng ký thành công! Đang chuyển hướng...';

                    // Auto login sau khi đăng ký
                    $_SESSION['user_id'] = $pdo->lastInsertId();
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_type'] = $user_type;
                    $_SESSION['login_time'] = time();

                    echo '<script>
                        setTimeout(function() {
                            window.location.href = "index.php";
                        }, 2000);
                    </script>';
                }
            }
        } catch (PDOException $e) {
            $error = 'Lỗi hệ thống, vui lòng thử lại sau';
        }
    }
    } // Close CSRF validation else
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký - TikTok Shop</title>

    <!-- Global CSS -->
    <link rel="stylesheet" href="asset/css/global.css">

    <!-- Page-specific CSS -->
    <link rel="stylesheet" href="asset/css/pages/register.css">
</head>

<body>
    <div class="register-container">
        <div class="logo-container">
            <img src="logo.png" alt="TikTok Shop" class="logo">
            <div class="app-subtitle">Tạo tài khoản mới</div>
        </div>

        <?php if ($error): ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <?php echo csrfTokenField(); ?>
            <div class="form-group">
                <label class="form-label">Họ và tên</label>
                <input type="text" name="name" class="form-input" placeholder="Nhập họ và tên"
                    value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" placeholder="Nhập địa chỉ email"
                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Mã giới thiệu (không bắt buộc)</label>
                <input type="text" name="referral_code" class="form-input" placeholder="Nhập mã giới thiệu nếu có"
                    value="<?php echo isset($_POST['referral_code']) ? htmlspecialchars($_POST['referral_code']) : ''; ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Mật khẩu</label>
                <input type="password" name="password" class="form-input"
                    placeholder="Nhập mật khẩu (tối thiểu 6 ký tự)" required>
            </div>

            <div class="form-group">
                <label class="form-label">Xác nhận mật khẩu</label>
                <input type="password" name="confirm_password" class="form-input" placeholder="Nhập lại mật khẩu"
                    required>
            </div>

            <button type="submit" class="register-btn">
                Đăng ký
            </button>
        </form>

        <div class="footer-links">
            <a href="login.php">Đã có tài khoản? Đăng nhập</a>
        </div>
    </div>
    <!-- JavaScript Files -->
    <script src="asset/js/global.js"></script>
    <script src="asset/js/components.js"></script>
</body>

</html>