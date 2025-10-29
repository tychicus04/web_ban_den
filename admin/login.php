<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

session_start();

require_once '../config.php';

$db = getDBConnection();

// Redirect if already logged in as admin
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
    header('Location: dashboard.php');
    exit;
}

$error_message = '';
$success_message = '';
$login_attempts = $_SESSION['admin_login_attempts'] ?? 0;
$last_attempt_time = $_SESSION['admin_last_attempt'] ?? 0;

// Rate limiting - max 5 attempts per 15 minutes
$max_attempts = 5;
$lockout_time = 15 * 60; // 15 minutes

if ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) {
    $remaining_time = $lockout_time - (time() - $last_attempt_time);
    $remaining_minutes = ceil($remaining_time / 60);
    $error_message = "Tài khoản đã bị khóa do quá nhiều lần đăng nhập sai. Vui lòng thử lại sau {$remaining_minutes} phút.";
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_login') {
    // Check rate limiting
    if ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) {
        $remaining_time = $lockout_time - (time() - $last_attempt_time);
        $remaining_minutes = ceil($remaining_time / 60);
        $error_message = "Tài khoản đã bị khóa do quá nhiều lần đăng nhập sai. Vui lòng thử lại sau {$remaining_minutes} phút.";
    } else {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['remember_me']);
        
        // Basic validation
        if (!$email) {
            $error_message = 'Vui lòng nhập email hợp lệ.';
        } elseif (empty($password)) {
            $error_message = 'Vui lòng nhập mật khẩu.';
        } elseif (strlen($password) < 6) {
            $error_message = 'Mật khẩu phải có ít nhất 6 ký tự.';
        } else {
            try {
                // Check if user exists and is admin
                $stmt = $db->prepare("
                    SELECT u.*, s.id as staff_id, r.name as role_name
                    FROM users u 
                    LEFT JOIN staff s ON u.id = s.user_id
                    LEFT JOIN roles r ON s.role_id = r.id
                    WHERE u.email = ? AND u.user_type = 'admin' AND u.banned = 0 
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $admin = $stmt->fetch();
                
                if ($admin && password_verify($password, $admin['password'])) {
                    // Successful login
                    
                    // Reset login attempts
                    unset($_SESSION['admin_login_attempts']);
                    unset($_SESSION['admin_last_attempt']);
                    
                    // Set session variables
                    $_SESSION['user_id'] = $admin['id'];
                    $_SESSION['user_type'] = 'admin';
                    $_SESSION['admin_name'] = $admin['name'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['admin_role'] = $admin['role_name'] ?? 'Administrator';
                    $_SESSION['staff_id'] = $admin['staff_id'];
                    $_SESSION['admin_login_time'] = time();
                    
                    // Security token for CSRF protection
                    $_SESSION['admin_token'] = bin2hex(random_bytes(32));
                    
                    // Set remember me cookie if requested
                    if ($remember_me) {
                        $remember_token = bin2hex(random_bytes(32));
                        setcookie('admin_remember_token', $remember_token, time() + (30 * 24 * 60 * 60), '/', '', true, true); // 30 days
                        
                        // Store remember token in database
                        $stmt = $db->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                        $stmt->execute([$remember_token, $admin['id']]);
                    }
                    
                    // Log successful login
                    error_log("Admin login successful: " . $admin['email'] . " at " . date('Y-m-d H:i:s'));
                    
                    // Update last login time
                    $stmt = $db->prepare("UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$admin['id']]);
                    
                    $success_message = 'Đăng nhập thành công! Đang chuyển hướng...';
                    
                    // Redirect after 1 second
                    header("Refresh: 1; url=dashboard.php");
                } else {
                    // Failed login
                    $_SESSION['admin_login_attempts'] = $login_attempts + 1;
                    $_SESSION['admin_last_attempt'] = time();
                    
                    // Log failed login attempt
                    error_log("Admin login failed: " . $email . " at " . date('Y-m-d H:i:s'));
                    
                    $remaining_attempts = $max_attempts - ($_SESSION['admin_login_attempts']);
                    if ($remaining_attempts > 0) {
                        $error_message = "Email hoặc mật khẩu không đúng. Còn lại {$remaining_attempts} lần thử.";
                    } else {
                        $error_message = "Quá nhiều lần đăng nhập sai. Tài khoản đã bị khóa 15 phút.";
                    }
                }
            } catch (PDOException $e) {
                error_log("Admin login database error: " . $e->getMessage());
                $error_message = 'Lỗi hệ thống. Vui lòng thử lại sau.';
            }
        }
    }
}

// Check remember me cookie
if (!isset($_SESSION['user_id']) && isset($_COOKIE['admin_remember_token'])) {
    try {
        $stmt = $db->prepare("
            SELECT u.*, s.id as staff_id, r.name as role_name
            FROM users u 
            LEFT JOIN staff s ON u.id = s.user_id
            LEFT JOIN roles r ON s.role_id = r.id
            WHERE u.remember_token = ? AND u.user_type = 'admin' AND u.banned = 0 
            LIMIT 1
        ");
        $stmt->execute([$_COOKIE['admin_remember_token']]);
        $admin = $stmt->fetch();
        
        if ($admin) {
            // Auto login
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['user_type'] = 'admin';
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_role'] = $admin['role_name'] ?? 'Administrator';
            $_SESSION['staff_id'] = $admin['staff_id'];
            $_SESSION['admin_login_time'] = time();
            $_SESSION['admin_token'] = bin2hex(random_bytes(32));
            
            header('Location: dashboard.php');
            exit;
        } else {
            // Invalid remember token, clear cookie
            setcookie('admin_remember_token', '', time() - 3600, '/', '', true, true);
        }
    } catch (PDOException $e) {
        error_log("Remember token check error: " . $e->getMessage());
    }
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

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Đăng nhập quản trị viên <?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-login.css">
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Header -->
            <div class="login-header">
                <a href="../index.php" class="logo" aria-label="<?php echo htmlspecialchars($site_name); ?> trang chủ">
                    <div>
                        <div class="logo-icon">A</div>
                        <h1 class="login-title">Admin Panel</h1>
                        <p class="login-subtitle">Đăng nhập quản trị viên</p>
                    </div>
                </a>
            </div>

            <!-- Alert Messages -->
            <?php if ($error_message): ?>
                <div class="alert alert-error" role="alert" aria-live="polite">
                    <span>⚠️</span>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="alert alert-success" role="alert" aria-live="polite">
                    <span>✅</span>
                    <span><?php echo htmlspecialchars($success_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form class="login-form" method="POST" action="" autocomplete="on" novalidate>
                <input type="hidden" name="action" value="admin_login">
                
                <!-- Email Field -->
                <div class="form-group">
                    <label for="email" class="form-label required">Email quản trị viên</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-input"
                        placeholder="admin@example.com"
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        required
                        autocomplete="email"
                        autofocus
                        aria-describedby="email-help"
                        <?php echo ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) ? 'disabled' : ''; ?>
                    >
                </div>

                <!-- Password Field -->
                <div class="form-group">
                    <label for="password" class="form-label required">Mật khẩu</label>
                    <div class="password-group">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-input"
                            placeholder="Nhập mật khẩu"
                            required
                            autocomplete="current-password"
                            minlength="6"
                            aria-describedby="password-help"
                            <?php echo ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) ? 'disabled' : ''; ?>
                        >
                        <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Hiển thị/ẩn mật khẩu">
                            👁️
                        </button>
                    </div>
                </div>

                <!-- Remember Me -->
                <div class="checkbox-group">
                    <input 
                        type="checkbox" 
                        id="remember_me" 
                        name="remember_me" 
                        class="checkbox"
                        <?php echo isset($_POST['remember_me']) ? 'checked' : ''; ?>
                        <?php echo ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) ? 'disabled' : ''; ?>
                    >
                    <label for="remember_me" class="checkbox-label">
                        Ghi nhớ đăng nhập (30 ngày)
                    </label>
                </div>

                <!-- Submit Button -->
                <button 
                    type="submit" 
                    class="submit-btn"
                    id="login-btn"
                    <?php echo ($login_attempts >= $max_attempts && (time() - $last_attempt_time) < $lockout_time) ? 'disabled' : ''; ?>
                >
                    <span id="btn-text">Đăng nhập</span>
                </button>
            </form>

            <!-- Security Notice -->
            <div class="security-notice">
                <strong>🔒 Bảo mật:</strong> Phiên đăng nhập sẽ hết hạn sau 8 tiếng không hoạt động.
                <br>Chỉ sử dụng thiết bị đáng tin cậy để đăng nhập.
            </div>

            <!-- Footer -->
            <div class="login-footer">
                <a href="../index.php" class="footer-link">← Quay về trang chủ</a>
            </div>
        </div>
    </div>

    <script>
        // Enhanced password toggle functionality
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleBtn = document.querySelector('.password-toggle');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleBtn.innerHTML = '🙈';
                toggleBtn.setAttribute('aria-label', 'Ẩn mật khẩu');
            } else {
                passwordField.type = 'password';
                toggleBtn.innerHTML = '👁️';
                toggleBtn.setAttribute('aria-label', 'Hiển thị mật khẩu');
            }
        }

        // Enhanced form submission with loading state
        document.querySelector('.login-form').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('login-btn');
            const btnText = document.getElementById('btn-text');
            const originalText = btnText.textContent;
            
            // Validate form
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            
            if (!email) {
                e.preventDefault();
                showAlert('Vui lòng nhập email.', 'error');
                document.getElementById('email').focus();
                return;
            }
            
            if (!password) {
                e.preventDefault();
                showAlert('Vui lòng nhập mật khẩu.', 'error');
                document.getElementById('password').focus();
                return;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                showAlert('Mật khẩu phải có ít nhất 6 ký tự.', 'error');
                document.getElementById('password').focus();
                return;
            }
            
            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                showAlert('Vui lòng nhập email hợp lệ.', 'error');
                document.getElementById('email').focus();
                return;
            }
            
            // Show loading state
            submitBtn.disabled = true;
            btnText.innerHTML = '<div class="loading"></div>Đang đăng nhập...';
            
            // Re-enable button after timeout (fallback)
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.disabled = false;
                    btnText.textContent = originalText;
                }
            }, 10000);
        });

        // Enhanced alert system
        function showAlert(message, type = 'error') {
            // Remove existing alerts
            const existingAlerts = document.querySelectorAll('.alert');
            existingAlerts.forEach(alert => alert.remove());
            
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.setAttribute('role', 'alert');
            alertDiv.setAttribute('aria-live', 'polite');
            
            const icon = type === 'error' ? '⚠️' : type === 'success' ? '✅' : '💡';
            alertDiv.innerHTML = `
                <span>${icon}</span>
                <span>${message}</span>
            `;
            
            const form = document.querySelector('.login-form');
            form.parentNode.insertBefore(alertDiv, form);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Auto-focus management
        document.addEventListener('DOMContentLoaded', function() {
            const emailField = document.getElementById('email');
            const passwordField = document.getElementById('password');
            
            // Focus email field if empty, otherwise focus password
            if (emailField.value.trim() === '') {
                emailField.focus();
            } else {
                passwordField.focus();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt + Enter to submit form
            if (e.altKey && e.key === 'Enter') {
                e.preventDefault();
                document.querySelector('.login-form').dispatchEvent(new Event('submit'));
            }
            
            // Escape to clear form
            if (e.key === 'Escape') {
                document.getElementById('email').value = '';
                document.getElementById('password').value = '';
                document.getElementById('remember_me').checked = false;
                document.getElementById('email').focus();
            }
        });

        // Enhanced form validation
        function setupFormValidation() {
            const emailField = document.getElementById('email');
            const passwordField = document.getElementById('password');
            
            emailField.addEventListener('blur', function() {
                const email = this.value.trim();
                if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    this.style.borderColor = 'var(--error)';
                    this.setAttribute('aria-invalid', 'true');
                } else {
                    this.style.borderColor = '';
                    this.setAttribute('aria-invalid', 'false');
                }
            });
            
            passwordField.addEventListener('input', function() {
                if (this.value.length > 0 && this.value.length < 6) {
                    this.style.borderColor = 'var(--warning)';
                } else {
                    this.style.borderColor = '';
                }
            });
        }

        // Security features
        function initSecurityFeatures() {
            // Disable right-click context menu
            document.addEventListener('contextmenu', function(e) {
                e.preventDefault();
            });
            
            // Disable F12 and other developer tools shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.key === 'F12' || 
                    (e.ctrlKey && e.shiftKey && e.key === 'I') ||
                    (e.ctrlKey && e.shiftKey && e.key === 'C') ||
                    (e.ctrlKey && e.shiftKey && e.key === 'J') ||
                    (e.ctrlKey && e.key === 'U')) {
                    e.preventDefault();
                    return false;
                }
            });
            
            // Clear sensitive data on page unload
            window.addEventListener('beforeunload', function() {
                document.getElementById('password').value = '';
                if (sessionStorage) {
                    sessionStorage.clear();
                }
            });
        }

        // Session timeout warning
        function initSessionTimeout() {
            let sessionTimeout;
            const timeoutDuration = 30 * 60 * 1000; // 30 minutes
            
            function resetSessionTimeout() {
                clearTimeout(sessionTimeout);
                sessionTimeout = setTimeout(() => {
                    showAlert('Phiên làm việc sắp hết hạn. Vui lòng đăng nhập lại.', 'warning');
                }, timeoutDuration);
            }
            
            // Reset timeout on user activity
            ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
                document.addEventListener(event, resetSessionTimeout, { passive: true });
            });
            
            resetSessionTimeout();
        }

        // Performance monitoring
        function initPerformanceMonitoring() {
            if ('performance' in window) {
                window.addEventListener('load', function() {
                    setTimeout(() => {
                        const perfData = performance.getEntriesByType('navigation')[0];
                        const loadTime = perfData.loadEventEnd - perfData.loadEventStart;
                        
                        if (loadTime > 2000) {
                            console.warn('Login page load time is high:', loadTime + 'ms');
                        }
                    }, 1000);
                });
            }
        }

        // Initialize all features
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🔐 Admin Login System - Initializing...');
            
            setupFormValidation();
            initSecurityFeatures();
            initSessionTimeout();
            initPerformanceMonitoring();
            
            // Add visual feedback for form interactions
            document.querySelectorAll('.form-input').forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'translateY(-2px)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = '';
                });
            });

            // Auto-redirect if success message is shown
            <?php if ($success_message): ?>
                setTimeout(() => {
                    window.location.href = 'dashboard.php';
                }, 2000);
            <?php endif; ?>

            console.log('✅ Admin Login System - Ready!');
            console.log('🔒 Security features enabled | 📊 Performance monitoring active');
        });

        // Error handling
        window.addEventListener('error', function(e) {
            console.error('JavaScript error:', e.error);
            showAlert('Đã xảy ra lỗi không mong muốn. Vui lòng thử lại.', 'error');
        });

        window.addEventListener('unhandledrejection', function(e) {
            console.error('Unhandled promise rejection:', e.reason);
            showAlert('Đã xảy ra lỗi kết nối. Vui lòng kiểm tra mạng và thử lại.', 'error');
        });
    </script>
</body>
</html>