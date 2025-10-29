<?php
session_start();
require_once 'config.php';
require_once 'csrf.php';

$error = '';
$success = '';

// Show logout message if exists
if (isset($_GET['message']) && $_GET['message'] === 'logged_out') {
    $success = 'Đã đăng xuất thành công!';
}

// Rate limiting - max 5 attempts per 15 minutes
$login_attempts = $_SESSION['login_attempts'] ?? 0;
$last_attempt_time = $_SESSION['last_attempt_time'] ?? 0;
$max_attempts = 5;
$lockout_time = 15 * 60; // 15 minutes

if ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) {
    $remaining_time = $lockout_time - (time() - $last_attempt_time);
    $remaining_minutes = ceil($remaining_time / 60);
    $error = "Tài khoản tạm khóa do đăng nhập sai quá nhiều. Vui lòng thử lại sau {$remaining_minutes} phút.";
}

if ($_POST) {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Phiên làm việc không hợp lệ. Vui lòng thử lại.';
    } elseif ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) {
        $remaining_time = $lockout_time - (time() - $last_attempt_time);
        $remaining_minutes = ceil($remaining_time / 60);
        $error = "Tài khoản tạm khóa do đăng nhập sai quá nhiều. Vui lòng thử lại sau {$remaining_minutes} phút.";
    } else {
        $email = trim($_POST['email']);
        $password = $_POST['password'];

        if (empty($email) || empty($password)) {
            $error = 'Vui lòng nhập đầy đủ thông tin';
            $login_attempts++;
            $_SESSION['login_attempts'] = $login_attempts;
            $_SESSION['last_attempt_time'] = time();
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, name, email, password, user_type FROM users WHERE email = ? AND banned = 0");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    // Regenerate session ID to prevent session fixation
                    session_regenerate_id(true);

                    // Reset login attempts
                    unset($_SESSION['login_attempts']);
                    unset($_SESSION['last_attempt_time']);

                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['login_time'] = time();

                    if ($user['user_type'] == 'admin') {
                        header('Location: admin/dashboard.php');
                    } else {
                        // Customer or any other user type goes to homepage
                        header('Location: index.php');
                    }
                    exit;
                } else {
                    $error = 'Email hoặc mật khẩu không đúng';
                    $login_attempts++;
                    $_SESSION['login_attempts'] = $login_attempts;
                    $_SESSION['last_attempt_time'] = time();
                }
            } catch (PDOException $e) {
                $error = 'Lỗi hệ thống, vui lòng thử lại sau';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - TikTok Shop</title>

    <!-- Global CSS -->
    <link rel="stylesheet" href="asset/css/global.css">

    <!-- Page-specific CSS -->
    <link rel="stylesheet" href="asset/css/pages/login.css">
</head>

<body>
    <div class="login-container">
        <div class="logo-container">
            <img src="logo.png" alt="TikTok Shop" class="logo">

            <div class="app-subtitle">Đăng nhập vào tài khoản của bạn</div>
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
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" placeholder="Nhập email của bạn"
                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Mật khẩu</label>
                <input type="password" name="password" class="form-input" placeholder="Nhập mật khẩu" required>
            </div>

            <button type="submit" class="login-btn">
                Đăng nhập
            </button>
        </form>

        <div class="footer-links">
            <a href="forgot-password.php">Quên mật khẩu?</a>
            <a href="register.php">Đăng ký</a>
        </div>
    </div>
    <!-- JavaScript Files -->
    <script src="asset/js/global.js"></script>
    <script src="asset/js/components.js"></script>
</body>

</html>