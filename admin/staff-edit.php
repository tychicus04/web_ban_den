<?php
/**
 * Admin Staff Edit Page
 *
 * @refactored Uses centralized admin_init.php for authentication and helpers
 */

// Initialize admin page with authentication and admin info
require_once __DIR__ . '/../includes/admin_init.php';
$admin = initAdminPage(true, true);
$db = getDB();

// Get all roles for dropdown
$roles = [];
try {
    $stmt = $db->query("SELECT id, name FROM roles ORDER BY name ASC");
    $roles = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Roles fetch error: " . $e->getMessage());
}

// Initialize variables
$staff_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$success_message = '';
$staff = [
    'id' => 0,
    'user_id' => 0,
    'name' => '',
    'email' => '',
    'phone' => '',
    'role_id' => 0,
    'password' => '',
    'confirm_password' => '',
];

// If editing existing staff
if ($staff_id > 0) {
    try {
        $stmt = $db->prepare("
            SELECT u.*, s.id as staff_id, s.role_id
            FROM users u 
            LEFT JOIN staff s ON u.id = s.user_id
            WHERE s.id = ? LIMIT 1
        ");
        $stmt->execute([$staff_id]);
        $staff_data = $stmt->fetch();
        
        if ($staff_data) {
            $staff = [
                'id' => $staff_data['staff_id'],
                'user_id' => $staff_data['id'],
                'name' => $staff_data['name'],
                'email' => $staff_data['email'],
                'phone' => $staff_data['phone'],
                'role_id' => $staff_data['role_id'],
                'password' => '',
                'confirm_password' => '',
            ];
        } else {
            header('Location: staff.php?error=staff_not_found');
            exit;
        }
    } catch (PDOException $e) {
        error_log("Staff fetch error: " . $e->getMessage());
        header('Location: staff.php?error=database_error');
        exit;
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        $errors[] = 'Invalid CSRF token';
    } else {
        // Get form data
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role_id = (int)($_POST['role_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate input
        if (empty($name)) {
            $errors[] = 'Tên nhân viên không được để trống';
        }
        
        if (empty($email)) {
            $errors[] = 'Email không được để trống';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email không đúng định dạng';
        } else {
            // Check if email is already in use (except for current user)
            $email_check_sql = "SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1";
            $stmt = $db->prepare($email_check_sql);
            $stmt->execute([$email, $staff['user_id']]);
            if ($stmt->fetch()) {
                $errors[] = 'Email đã được sử dụng bởi tài khoản khác';
            }
        }
        
        if ($role_id <= 0) {
            $errors[] = 'Vui lòng chọn vai trò cho nhân viên';
        }
        
        // Password validation for new staff or when changing password
        if ($staff['id'] == 0 || !empty($password)) {
            if (empty($password)) {
                $errors[] = 'Mật khẩu không được để trống';
            } elseif (strlen($password) < 6) {
                $errors[] = 'Mật khẩu phải có ít nhất 6 ký tự';
            } elseif ($password !== $confirm_password) {
                $errors[] = 'Xác nhận mật khẩu không khớp';
            }
        }
        
        // If no errors, save the data
        if (empty($errors)) {
            try {
                // Begin transaction
                $db->beginTransaction();
                
                if ($staff['id'] == 0) {
                    // Create new user
                    $stmt = $db->prepare("
                        INSERT INTO users (name, email, phone, password, user_type, created_at, updated_at)
                        VALUES (?, ?, ?, ?, 'admin', NOW(), NOW())
                    ");
                    $stmt->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
                    $user_id = $db->lastInsertId();
                    
                    // Create staff record
                    $stmt = $db->prepare("
                        INSERT INTO staff (user_id, role_id, created_at, updated_at)
                        VALUES (?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$user_id, $role_id]);
                    
                    // Assign role
                    $stmt = $db->prepare("
                        INSERT INTO model_has_roles (role_id, model_type, model_id)
                        VALUES (?, 'App\\Models\\User', ?)
                    ");
                    $stmt->execute([$role_id, $user_id]);
                    
                    $success_message = 'Thêm nhân viên mới thành công';
                } else {
                    // Update existing user
                    if (!empty($password)) {
                        // Update with password
                        $stmt = $db->prepare("
                            UPDATE users 
                            SET name = ?, email = ?, phone = ?, password = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $staff['user_id']]);
                    } else {
                        // Update without password
                        $stmt = $db->prepare("
                            UPDATE users 
                            SET name = ?, email = ?, phone = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$name, $email, $phone, $staff['user_id']]);
                    }
                    
                    // Update staff record
                    $stmt = $db->prepare("
                        UPDATE staff 
                        SET role_id = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$role_id, $staff['id']]);
                    
                    // Update role assignment
                    $stmt = $db->prepare("
                        DELETE FROM model_has_roles 
                        WHERE model_id = ? AND model_type = 'App\\Models\\User'
                    ");
                    $stmt->execute([$staff['user_id']]);
                    
                    $stmt = $db->prepare("
                        INSERT INTO model_has_roles (role_id, model_type, model_id)
                        VALUES (?, 'App\\Models\\User', ?)
                    ");
                    $stmt->execute([$role_id, $staff['user_id']]);
                    
                    $success_message = 'Cập nhật thông tin nhân viên thành công';
                }
                
                $db->commit();
                
                // Redirect after successful save for new staff
                if ($staff['id'] == 0) {
                    header('Location: staff.php?success=' . urlencode($success_message));
                    exit;
                }
                
            } catch (PDOException $e) {
                $db->rollBack();
                error_log("Staff save error: " . $e->getMessage());
                $errors[] = 'Lỗi cơ sở dữ liệu: ' . $e->getMessage();
            }
        }
        
        // Update staff array with form values for redisplay in case of errors
        $staff['name'] = $name;
        $staff['email'] = $email;
        $staff['phone'] = $phone;
        $staff['role_id'] = $role_id;
        $staff['password'] = $password;
        $staff['confirm_password'] = $confirm_password;
    }
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
$page_title = $staff_id > 0 ? 'Chỉnh sửa nhân viên' : 'Thêm nhân viên mới';
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
    
    <link rel="stylesheet" href="../asset/css/pages/admin-sidebar.css">
    <link rel="stylesheet" href="../asset/css/pages/admin-staff-edit.css">
</head>

<body>
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <?php require_once __DIR__ . '/sidebar.php'; ?>
        
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
                            <a href="staff.php">Nhân viên</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span><?php echo $page_title; ?></span>
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
                                <div class="user-role"><?php echo htmlspecialchars($admin['role_name'] ?? 'Administrator'); ?></div>
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
                    <h1 class="page-title"><?php echo $page_title; ?></h1>
                    <p class="page-subtitle">
                        <?php echo $staff['id'] > 0 
                            ? 'Chỉnh sửa thông tin và phân quyền cho nhân viên'
                            : 'Thêm tài khoản nhân viên mới và cấp quyền truy cập hệ thống'; ?>
                    </p>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <div class="error-container">
                        <div class="error-title">Đã xảy ra lỗi:</div>
                        <ul class="error-list">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success_message)): ?>
                    <div class="success-container">
                        <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
                    </div>
                <?php endif; ?>
                
                <!-- Staff Form -->
                <form method="POST" action="" class="form-container">
                    <input type="hidden" name="token" value="<?php echo $_SESSION['admin_token']; ?>">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name" class="form-label">Tên nhân viên <span style="color: var(--danger);">*</span></label>
                            <input 
                                type="text" 
                                id="name" 
                                name="name" 
                                class="form-control" 
                                value="<?php echo htmlspecialchars($staff['name']); ?>" 
                                required
                                autofocus
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="email" class="form-label">Email <span style="color: var(--danger);">*</span></label>
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                class="form-control" 
                                value="<?php echo htmlspecialchars($staff['email']); ?>" 
                                required
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="phone" class="form-label">Số điện thoại</label>
                            <input 
                                type="tel" 
                                id="phone" 
                                name="phone" 
                                class="form-control" 
                                value="<?php echo htmlspecialchars($staff['phone']); ?>"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="role_id" class="form-label">Vai trò <span style="color: var(--danger);">*</span></label>
                            <select id="role_id" name="role_id" class="form-control form-select" required>
                                <option value="">-- Chọn vai trò --</option>
                                <?php foreach ($roles as $role): ?>
                                    <option 
                                        value="<?php echo $role['id']; ?>" 
                                        <?php echo $staff['role_id'] == $role['id'] ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-help">
                                Vai trò quyết định quyền hạn và chức năng mà nhân viên có thể sử dụng trong hệ thống
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="password" class="form-label">
                                <?php echo $staff['id'] > 0 ? 'Mật khẩu mới' : 'Mật khẩu'; ?> 
                                <?php echo $staff['id'] > 0 ? '' : '<span style="color: var(--danger);">*</span>'; ?>
                            </label>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-control" 
                                <?php echo $staff['id'] > 0 ? '' : 'required'; ?>
                                autocomplete="new-password"
                            >
                            <div class="form-help">
                                <?php echo $staff['id'] > 0 
                                    ? 'Để trống nếu không muốn thay đổi mật khẩu' 
                                    : 'Mật khẩu phải có ít nhất 6 ký tự'; ?>
                            </div>
                            
                            <div class="password-strength" id="password-strength" style="display: none;">
                                <div class="strength-meter">
                                    <div class="strength-meter-fill" id="strength-meter-fill"></div>
                                </div>
                                <div class="strength-text">
                                    <div class="strength-label" id="strength-label"></div>
                                    <div class="strength-requirements">6+ ký tự</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password" class="form-label">
                                <?php echo $staff['id'] > 0 ? 'Xác nhận mật khẩu mới' : 'Xác nhận mật khẩu'; ?> 
                                <?php echo $staff['id'] > 0 ? '' : '<span style="color: var(--danger);">*</span>'; ?>
                            </label>
                            <input 
                                type="password" 
                                id="confirm_password" 
                                name="confirm_password" 
                                class="form-control" 
                                <?php echo $staff['id'] > 0 ? '' : 'required'; ?>
                            >
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <div>
                            <a href="staff.php" class="btn btn-secondary">
                                <span>↩</span>
                                <span>Quay lại</span>
                            </a>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary btn-lg">
                                <span>💾</span>
                                <span>
                                    <?php echo $staff['id'] > 0 ? 'Cập nhật nhân viên' : 'Thêm nhân viên mới'; ?>
                                </span>
                            </button>
                        </div>
                    </div>
                </form>
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
        
        // Password strength meter
        const passwordInput = document.getElementById('password');
        const strengthMeter = document.getElementById('password-strength');
        const strengthFill = document.getElementById('strength-meter-fill');
        const strengthLabel = document.getElementById('strength-label');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            
            if (password.length > 0) {
                strengthMeter.style.display = 'block';
                
                // Calculate password strength
                let strength = 0;
                
                // Length check
                if (password.length >= 6) {
                    strength += 1;
                }
                if (password.length >= 10) {
                    strength += 1;
                }
                
                // Character type checks
                if (/[A-Z]/.test(password)) {
                    strength += 1;
                }
                if (/[a-z]/.test(password)) {
                    strength += 1;
                }
                if (/[0-9]/.test(password)) {
                    strength += 1;
                }
                if (/[^A-Za-z0-9]/.test(password)) {
                    strength += 1;
                }
                
                // Update UI based on strength
                if (strength <= 2) {
                    strengthFill.className = 'strength-meter-fill weak';
                    strengthLabel.className = 'strength-label weak';
                    strengthLabel.textContent = 'Yếu';
                } else if (strength <= 4) {
                    strengthFill.className = 'strength-meter-fill medium';
                    strengthLabel.className = 'strength-label medium';
                    strengthLabel.textContent = 'Trung bình';
                } else if (strength <= 5) {
                    strengthFill.className = 'strength-meter-fill strong';
                    strengthLabel.className = 'strength-label strong';
                    strengthLabel.textContent = 'Mạnh';
                } else {
                    strengthFill.className = 'strength-meter-fill very-strong';
                    strengthLabel.className = 'strength-label very-strong';
                    strengthLabel.textContent = 'Rất mạnh';
                }
            } else {
                strengthMeter.style.display = 'none';
            }
        });
        
        // Check if passwords match
        const confirmInput = document.getElementById('confirm_password');
        
        function checkPasswordMatch() {
            if (passwordInput.value !== confirmInput.value) {
                confirmInput.setCustomValidity('Mật khẩu không khớp');
            } else {
                confirmInput.setCustomValidity('');
            }
        }
        
        passwordInput.addEventListener('change', checkPasswordMatch);
        confirmInput.addEventListener('keyup', checkPasswordMatch);
        
        // Form validation
        const form = document.querySelector('form');
        
        form.addEventListener('submit', function(e) {
            let isValid = true;
            
            // Validate name
            const nameInput = document.getElementById('name');
            if (nameInput.value.trim() === '') {
                isValid = false;
                nameInput.setCustomValidity('Vui lòng nhập tên nhân viên');
            } else {
                nameInput.setCustomValidity('');
            }
            
            // Validate email
            const emailInput = document.getElementById('email');
            if (emailInput.value.trim() === '') {
                isValid = false;
                emailInput.setCustomValidity('Vui lòng nhập email');
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value)) {
                isValid = false;
                emailInput.setCustomValidity('Email không đúng định dạng');
            } else {
                emailInput.setCustomValidity('');
            }
            
            // Validate role
            const roleInput = document.getElementById('role_id');
            if (roleInput.value === '') {
                isValid = false;
                roleInput.setCustomValidity('Vui lòng chọn vai trò');
            } else {
                roleInput.setCustomValidity('');
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? 'var(--success)' : type === 'error' ? 'var(--danger)' : 'var(--primary)'};
                color: white;
                padding: var(--space-4) var(--space-5);
                border-radius: var(--rounded-lg);
                box-shadow: var(--shadow-xl);
                z-index: 9999;
                transform: translateX(400px);
                transition: transform 0.3s ease;
                max-width: 350px;
                font-weight: var(--font-medium);
            `;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
                setTimeout(() => {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }
        
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
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            <?php if (!empty($success_message)): ?>
                showNotification('<?php echo addslashes($success_message); ?>', 'success');
            <?php endif; ?>
        });
    </script>
</body>
</html>