<?php
session_start();
require_once '../config.php';

$error = '';
$success = '';

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'seller') {
    header('Location: dashboard.php');
    exit;
}

// Handle logout success message
if (isset($_GET['message']) && $_GET['message'] == 'logout_success') {
    $success = 'Đăng xuất thành công!';
}

if ($_POST) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = 'Vui lòng nhập đầy đủ thông tin';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email không hợp lệ';
    } else {
        try {
            // Only allow sellers to login
            $stmt = $pdo->prepare("SELECT id, name, email, password, user_type, banned FROM users WHERE email = ? AND user_type = 'seller'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                if ($user['banned'] == 1) {
                    $error = 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ admin.';
                } elseif (password_verify($password, $user['password'])) {
                    // Login successful
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['seller_logged_in'] = true;

                    // Set remember me cookie if requested
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        setcookie('remember_seller', $token, time() + (86400 * 30), '/', '', false, true); // 30 days
                    }

                    // Update last login time
                    $updateStmt = $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$user['id']]);

                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Email hoặc mật khẩu không chính xác';
                }
            } else {
                $error = 'Tài khoản seller không tồn tại hoặc chưa được kích hoạt';
            }
        } catch (PDOException $e) {
            $error = 'Lỗi hệ thống, vui lòng thử lại sau';
            error_log("Seller login error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập Seller - TikTok Shop</title>
    <link rel="icon"
        href="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIiIGhlaWdodD0iMzIiIHZpZXdCb3g9IjAgMCAzMiAzMiIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTE2IDJDOC4yNjggMiAyIDguMjY4IDIgMTZTOC4yNjggMzAgMTYgMzBTMzAgMjMuNzMyIDMwIDE2UzIzLjczMiAyIDE2IDJaIiBmaWxsPSIjRkUyQzU1Ii8+CjxwYXRoIGQ9Ik0xMS43MzMgMTIuOEMxMS43MzMgMTEuNDc0NCAxMi44MDc0IDEwLjQgMTQuMTMzIDEwLjRDMTUuNDU4NiAxMC40IDE2LjUzMyAxMS40NzQ0IDE2LjUzMyAxMi44VjE2SDE5LjJDMjAuNTI1NiAxNiAyMS42IDE3LjA3NDQgMjEuNiAxOC40QzIxLjYgMTkuNzI1NiAyMC41MjU2IDIwLjggMTkuMiAyMC44SDE2LjUzM1YyNC4yNjY3QzE2LjUzMyAyNS4wNDA0IDE1LjkwNyAyNS42NjY3IDE1LjEzMyAyNS42NjY3QzE0LjM1OSAyNS42NjY3IDEzLjczMyAyNS4wNDA0IDEzLjczMyAyNC4yNjY3VjIwLjhIMTIuOEMxMS40NzQ0IDIwLjggMTAuNCAxOS43MjU2IDEwLjQgMTguNEMxMC40IDE3LjA3NDQgMTEuNDc0NCAxNiAxMi44IDE2SDEzLjczM1YxMi44WiIgZmlsbD0id2hpdGUiLz4KPC9zdmc+" />
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
        position: relative;
    }

    /* Background decoration for seller page */
    body::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, rgba(255, 0, 80, 0.02) 0%, rgba(255, 77, 109, 0.02) 100%);
        z-index: -1;
    }

    .login-container {
        background: #ffffff;
        border: 1px solid #e1e5e9;
        border-radius: 20px;
        padding: 40px;
        width: 100%;
        max-width: 400px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        position: relative;
    }

    .logo-container {
        text-align: center;
        margin-bottom: 30px;
    }

    .logo {
        width: 280px;
        height: 200px;
        border-radius: 20px;
        margin-bottom: 16px;
        object-fit: cover;
    }

    /* Fallback if logo.png doesn't exist */
    .logo-fallback {
        width: 64px;
        height: 64px;
        background: linear-gradient(45deg, #ff0050, #ff4081);
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
    }

    .seller-badge {
        display: inline-block;
        background: linear-gradient(45deg, #ff0050, #ff4081);
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-bottom: 16px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
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
        line-height: 1.4;
    }

    .form-group {
        margin-bottom: 20px;
        position: relative;
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

    .password-container {
        position: relative;
    }

    .password-toggle {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        cursor: pointer;
        color: #666;
        padding: 4px;
        border-radius: 4px;
        transition: color 0.3s ease;
    }

    .password-toggle:hover {
        color: #ff0050;
    }

    .remember-forgot {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        font-size: 14px;
    }

    .remember-me {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #666;
    }

    .remember-me input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: #ff0050;
    }

    .forgot-password {
        color: #ff0050;
        text-decoration: none;
        font-weight: 500;
        transition: color 0.3s ease;
    }

    .forgot-password:hover {
        color: #cc0040;
        text-decoration: underline;
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
        animation: slideIn 0.3s ease-out;
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
        animation: slideIn 0.3s ease-out;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .divider {
        display: flex;
        align-items: center;
        margin: 24px 0;
        color: #666;
        font-size: 14px;
    }

    .divider::before,
    .divider::after {
        content: '';
        flex: 1;
        height: 1px;
        background: #e1e5e9;
    }

    .divider span {
        padding: 0 16px;
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

    .register-link {
        text-align: center;
        margin-top: 16px;
        font-size: 14px;
        color: #666;
    }

    .register-link a {
        color: #ff0050;
        text-decoration: none;
        font-weight: 600;
    }

    .register-link a:hover {
        text-decoration: underline;
    }

    .seller-info {
        background: #f0f9ff;
        border: 1px solid #bee3f8;
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 20px;
        font-size: 13px;
        color: #2c5aa0;
        text-align: center;
    }

    @media (max-width: 480px) {
        .login-container {
            margin: 20px;
            padding: 30px 25px;
        }

        .app-name {
            font-size: 24px;
        }

        .logo {
            width: 240px;
            height: 160px;
        }

        .remember-forgot {
            flex-direction: column;
            gap: 12px;
            align-items: flex-start;
        }
    }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="logo-container">
            <span class="seller-badge">Seller Center</span>

            <!-- Try to load logo.png, fallback to icon if not found -->
            <img src="../logo.png" alt="TikTok Shop" class="logo"
                onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">

            <div class="logo-fallback" style="display: none;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="white">
                    <path
                        d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm1 15h-2v-6H9v-2h2V7h2v2h2v2h-2v6z" />
                </svg>
            </div>

            <h1 class="app-name">TikTok Shop</h1>
            <div class="app-subtitle">Đăng nhập vào Seller Center để quản lý cửa hàng</div>
        </div>

        <div class="seller-info">
            ℹ️ Trang đăng nhập dành riêng cho người bán. Nếu bạn là khách hàng, vui lòng <a href="../login.php"
                style="color: #2c5aa0; font-weight: 600;">đăng nhập tại đây</a>.
        </div>

        <?php if ($error): ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" placeholder="Nhập email seller của bạn"
                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Mật khẩu</label>
                <div class="password-container">
                    <input type="password" name="password" id="password" class="form-input" placeholder="Nhập mật khẩu"
                        required>
                    <button type="button" class="password-toggle" onclick="togglePassword()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" id="eyeIcon">
                            <path
                                d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="remember-forgot">
                <label class="remember-me">
                    <input type="checkbox" name="remember" id="remember">
                    <span>Ghi nhớ đăng nhập</span>
                </label>
                <a href="forgot-password.php" class="forgot-password">Quên mật khẩu?</a>
            </div>

            <button type="submit" class="login-btn">
                Đăng nhập Seller Center
            </button>
        </form>

        <div class="divider">
            <span>Hoặc</span>
        </div>

        <div class="register-link">
            Chưa có tài khoản seller? <a href="register.php">Đăng ký bán hàng</a>
        </div>

        <div class="footer-links">
            <a href="../index.php">Trang chủ</a>
            <a href="help.php">Hỗ trợ seller</a>
            <a href="terms.php">Điều khoản</a>
        </div>
    </div>

    <script>
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');

        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            eyeIcon.innerHTML =
                '<path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>';
        } else {
            passwordInput.type = 'password';
            eyeIcon.innerHTML =
                '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
        }
    }

    // Auto-focus on email input
    document.querySelector('input[name="email"]').focus();

    // Clear URL parameters after showing message
    if (window.location.search.includes('message=logout_success')) {
        setTimeout(() => {
            window.history.replaceState({}, document.title, window.location.pathname);
        }, 3000);
    }
    </script>
</body>

</html>