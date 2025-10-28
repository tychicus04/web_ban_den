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
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #ffffff;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .login-container {
        background: #ffffff;
        border: 1px solid #e1e5e9;
        border-radius: 20px;
        padding: 40px;
        width: 100%;
        max-width: 400px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
    }

    .logo-container {
        text-align: center;
        margin-bottom: 30px;
    }

    .logo {
        width: 280px;
        height: 200px;
        border-radius: 20px;
        margin-bottom: 0px;

    }

    .app-name {
        color: #000;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .app-subtitle {
        color: #666;
        font-size: 14px;
        font-weight: 400;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-label {
        display: block;
        color: #333;
        font-size: 14px;
        font-weight: 500;
        margin-bottom: 8px;
    }

    .form-input {
        width: 100%;
        padding: 15px 20px;
        background: #f8f9fa;
        border: 1px solid #e1e5e9;
        border-radius: 12px;
        color: #333;
        font-size: 16px;
        transition: all 0.3s ease;
    }

    .form-input:focus {
        outline: none;
        border-color: #ff0050;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(255, 0, 80, 0.1);
    }

    .form-input::placeholder {
        color: #999;
    }

    .login-btn {
        width: 100%;
        padding: 15px;
        background: linear-gradient(45deg, #ff0050, #ff4081);
        border: none;
        border-radius: 12px;
        color: white;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .login-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(255, 0, 80, 0.4);
    }

    .login-btn:active {
        transform: translateY(0);
    }

    .login-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s;
    }

    .login-btn:hover::before {
        left: 100%;
    }

    .error-message {
        background: rgba(255, 59, 48, 0.1);
        border: 1px solid rgba(255, 59, 48, 0.3);
        border-radius: 8px;
        padding: 12px 16px;
        color: #ff3b30;
        font-size: 14px;
        margin-bottom: 20px;
        text-align: center;
    }

    .success-message {
        background: rgba(52, 199, 89, 0.1);
        border: 1px solid rgba(52, 199, 89, 0.3);
        border-radius: 8px;
        padding: 12px 16px;
        color: #34c759;
        font-size: 14px;
        margin-bottom: 20px;
        text-align: center;
    }

    .footer-links {
        text-align: center;
        margin-top: 25px;
        padding-top: 20px;
        border-top: 1px solid #e1e5e9;
    }

    .footer-links a {
        color: #666;
        text-decoration: none;
        font-size: 14px;
        margin: 0 15px;
        transition: color 0.3s ease;
    }

    .footer-links a:hover {
        color: #ff0050;
    }

    @media (max-width: 480px) {
        .login-container {
            margin: 20px;
            padding: 30px 25px;
        }

        .app-name {
            font-size: 24px;
        }
    }
    </style>
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