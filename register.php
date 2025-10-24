<?php
session_start();
require_once 'config.php';

$error = '';
$success = '';

if ($_POST) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $referral_code = trim($_POST['referral_code']);
    $user_type = 'customer'; // Mặc định là customer

    if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'Vui lòng nhập đầy đủ thông tin';
    } elseif ($password !== $confirm_password) {
        $error = 'Mật khẩu xác nhận không khớp';
    } elseif (strlen($password) < 6) {
        $error = 'Mật khẩu phải có ít nhất 6 ký tự';
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

                    $success = 'Đăng ký thành công! Đang chuyển hướng...';

                    // Auto login sau khi đăng ký
                    $_SESSION['user_id'] = $pdo->lastInsertId();
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_type'] = $user_type;

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
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký - TikTok Shop</title>
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
        padding: 20px 0;
    }

    .register-container {
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

    .register-btn {
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

    .register-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(255, 0, 80, 0.4);
    }

    .register-btn:active {
        transform: translateY(0);
    }

    .register-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s;
    }

    .register-btn:hover::before {
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
        .register-container {
            margin: 20px;
            padding: 30px 25px;
        }

        .logo {
            width: 240px;
            height: 160px;
        }
    }
    </style>
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
</body>

</html>