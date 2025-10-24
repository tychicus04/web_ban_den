<?php
session_start();
require_once 'config.php';

$error = '';

if ($_POST) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = 'Vui lòng nhập đầy đủ thông tin';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, name, email, password, user_type FROM users WHERE email = ? AND banned = 0");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_type'] = $user['user_type'];

                if ($user['user_type'] == 'customer') {
                    header('Location: index.php');
                } else if ($user['user_type'] == 'seller') {
                    header('Location: dashboard.php');
                } else {
                    header('Location: admin.php');
                }
                exit;
            } else {
                $error = 'Email hoặc mật khẩu không đúng';
            }
        } catch (PDOException $e) {
            $error = 'Lỗi hệ thống, vui lòng thử lại sau';
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

        <form method="POST" action="">
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
</body>

</html>