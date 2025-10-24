<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Include database config
require_once '../config.php';

$db = getDBConnection();

// Authentication check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Session timeout check (8 hours)
$session_timeout = 8 * 60 * 60; // 8 hours
if (isset($_SESSION['admin_login_time']) && (time() - $_SESSION['admin_login_time']) > $session_timeout) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}

// CSRF token validation
if (!isset($_SESSION['admin_token'])) {
    $_SESSION['admin_token'] = bin2hex(random_bytes(32));
}

// Get admin info
$admin = null;
try {
    $stmt = $db->prepare("
        SELECT u.*, s.id as staff_id, r.name as role_name
        FROM users u 
        LEFT JOIN staff s ON u.id = s.user_id
        LEFT JOIN roles r ON s.role_id = r.id
        WHERE u.id = ? LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch();
    
    if (!$admin) {
        session_destroy();
        header('Location: login.php?error=user_not_found');
        exit;
    }
} catch (PDOException $e) {
    error_log("Admin fetch error: " . $e->getMessage());
    header('Location: login.php?error=database_error');
    exit;
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
    
    <style>
        :root {
            /* Enhanced Color System */
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --accent-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --warm-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --cool-gradient: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
            --danger-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            
            /* Core Colors */
            --primary: #667eea;
            --primary-dark: #4c63d2;
            --primary-light: #8fa1f5;
            --secondary: #f5576c;
            --secondary-dark: #e23954;
            --secondary-light: #f7849a;
            --accent: #4facfe;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --danger: #ef4444;
            
            /* Neutral Palette */
            --white: #ffffff;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            
            /* Semantic Colors */
            --text-primary: var(--gray-900);
            --text-secondary: var(--gray-600);
            --text-tertiary: var(--gray-500);
            --text-inverse: var(--white);
            --background: var(--gray-50);
            --surface: var(--white);
            --border: var(--gray-200);
            --border-light: var(--gray-100);
            
            /* Sidebar */
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            
            /* Enhanced Shadows */
            --shadow-xs: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-2xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            
            /* Spacing Scale */
            --space-1: 0.25rem;
            --space-2: 0.5rem;
            --space-3: 0.75rem;
            --space-4: 1rem;
            --space-5: 1.25rem;
            --space-6: 1.5rem;
            --space-8: 2rem;
            --space-10: 2.5rem;
            --space-12: 3rem;
            --space-16: 4rem;
            --space-20: 5rem;
            --space-24: 6rem;
            --space-32: 8rem;
            
            /* Typography Scale */
            --text-xs: 0.75rem;
            --text-sm: 0.875rem;
            --text-base: 1rem;
            --text-lg: 1.125rem;
            --text-xl: 1.25rem;
            --text-2xl: 1.5rem;
            --text-3xl: 1.875rem;
            --text-4xl: 2.25rem;
            
            /* Font Weights */
            --font-normal: 400;
            --font-medium: 500;
            --font-semibold: 600;
            --font-bold: 700;
            --font-extrabold: 800;
            --font-black: 900;
            
            /* Border Radius */
            --rounded: 0.25rem;
            --rounded-md: 0.375rem;
            --rounded-lg: 0.5rem;
            --rounded-xl: 0.75rem;
            --rounded-2xl: 1rem;
            --rounded-3xl: 1.5rem;
            --rounded-full: 9999px;
            
            /* Transitions */
            --transition-all: all 150ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-fast: all 100ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-normal: all 200ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: all 300ms cubic-bezier(0.4, 0, 0.2, 1);
            
            /* Font Families */
            --font-sans: 'Inter', 'Poppins', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-heading: 'Poppins', system-ui, sans-serif;
        }
        
        /* CSS Reset & Base Styles */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        html {
            scroll-behavior: smooth;
            text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        body {
            font-family: var(--font-sans);
            font-size: var(--text-base);
            line-height: 1.5;
            color: var(--text-primary);
            background-color: var(--background);
            overflow-x: hidden;
        }
        
        /* Layout */
        .dashboard-layout {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--gray-900) 0%, var(--gray-800) 100%);
            color: var(--white);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1000;
            transform: translateX(0);
            transition: var(--transition-normal);
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
        }
        
        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
            transform: translateX(0);
        }
        
        .sidebar-header {
            padding: var(--space-6);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .sidebar-logo {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            border-radius: var(--rounded-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: var(--font-bold);
            font-size: var(--text-lg);
            flex-shrink: 0;
        }
        
        .sidebar-title {
            font-family: var(--font-heading);
            font-size: var(--text-xl);
            font-weight: var(--font-bold);
            white-space: nowrap;
            transition: var(--transition-normal);
        }
        
        .sidebar.collapsed .sidebar-title {
            opacity: 0;
            transform: translateX(-20px);
        }
        
        .sidebar-nav {
            padding: var(--space-4) 0;
        }
        
        .nav-section {
            margin-bottom: var(--space-6);
        }
        
        .nav-section-title {
            padding: 0 var(--space-6);
            margin-bottom: var(--space-3);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.6);
            white-space: nowrap;
            transition: var(--transition-normal);
        }
        
        .sidebar.collapsed .nav-section-title {
            opacity: 0;
        }
        
        .nav-item {
            margin-bottom: var(--space-1);
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: var(--space-3) var(--space-6);
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-weight: var(--font-medium);
            transition: var(--transition-normal);
            position: relative;
        }
        
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
            transform: translateX(4px);
        }
        
        .nav-link.active {
            background: rgba(102, 126, 234, 0.2);
            color: var(--white);
            border-right: 3px solid var(--primary);
        }
        
        .nav-icon {
            font-size: var(--text-lg);
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }
        
        .nav-text {
            white-space: nowrap;
            transition: var(--transition-normal);
        }
        
        .sidebar.collapsed .nav-text {
            opacity: 0;
            transform: translateX(-20px);
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: var(--transition-normal);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar.collapsed + .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }
        
        /* Header */
        .header {
            background: var(--surface);
            padding: var(--space-4) var(--space-6);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }
        
        .sidebar-toggle {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: var(--text-xl);
            cursor: pointer;
            padding: var(--space-2);
            border-radius: var(--rounded);
            transition: var(--transition-normal);
        }
        
        .sidebar-toggle:hover {
            background: var(--gray-100);
            color: var(--text-primary);
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            font-size: var(--text-sm);
            color: var(--text-secondary);
        }
        
        .breadcrumb-item {
            display: flex;
            align-items: center;
            gap: var(--space-2);
        }
        
        .breadcrumb-separator {
            color: var(--text-tertiary);
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }
        
        .user-menu {
            position: relative;
        }
        
        .user-button {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            background: none;
            border: none;
            cursor: pointer;
            padding: var(--space-2);
            border-radius: var(--rounded-lg);
            transition: var(--transition-normal);
        }
        
        .user-button:hover {
            background: var(--gray-100);
        }
        
        .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--primary-gradient);
            border-radius: var(--rounded-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: var(--font-bold);
            font-size: var(--text-sm);
        }
        
        .user-info {
            text-align: left;
        }
        
        .user-name {
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .user-role {
            font-size: var(--text-xs);
            color: var(--text-secondary);
        }
        
        /* Content Area */
        .content {
            flex: 1;
            padding: var(--space-6);
        }
        
        .page-header {
            margin-bottom: var(--space-8);
        }
        
        .page-title {
            font-family: var(--font-heading);
            font-size: var(--text-3xl);
            font-weight: var(--font-bold);
            color: var(--text-primary);
            margin-bottom: var(--space-2);
        }
        
        .page-subtitle {
            color: var(--text-secondary);
            font-size: var(--text-base);
        }
        
        /* Form */
        .form-container {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            padding: var(--space-6);
            margin-bottom: var(--space-8);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-6);
        }
        
        .form-group {
            margin-bottom: var(--space-5);
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-label {
            display: block;
            font-weight: var(--font-medium);
            margin-bottom: var(--space-2);
            color: var(--text-primary);
        }
        
        .form-control {
            width: 100%;
            padding: var(--space-3) var(--space-4);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            font-size: var(--text-base);
            background: var(--gray-50);
            transition: var(--transition-normal);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1rem;
            padding-right: 2.5rem;
        }
        
        .form-help {
            margin-top: var(--space-2);
            font-size: var(--text-sm);
            color: var(--text-tertiary);
        }
        
        .form-error {
            color: var(--danger);
            font-size: var(--text-sm);
            margin-top: var(--space-2);
        }
        
        /* Form errors */
        .error-container {
            background: rgba(239, 68, 68, 0.1);
            border-left: 4px solid var(--danger);
            padding: var(--space-4);
            margin-bottom: var(--space-6);
            border-radius: var(--rounded-lg);
        }
        
        .error-title {
            font-weight: var(--font-semibold);
            color: var(--danger);
            margin-bottom: var(--space-2);
        }
        
        .error-list {
            margin: 0;
            padding-left: var(--space-5);
            color: var(--text-primary);
        }
        
        .error-list li {
            margin-bottom: var(--space-1);
        }
        
        /* Success message */
        .success-container {
            background: rgba(16, 185, 129, 0.1);
            border-left: 4px solid var(--success);
            padding: var(--space-4);
            margin-bottom: var(--space-6);
            border-radius: var(--rounded-lg);
        }
        
        .success-message {
            color: #065f46;
            font-weight: var(--font-medium);
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            padding: var(--space-3) var(--space-4);
            border: none;
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition-normal);
            white-space: nowrap;
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            color: var(--white);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-secondary {
            background: var(--surface);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }
        
        .btn-secondary:hover {
            background: var(--gray-50);
            border-color: var(--primary);
        }
        
        .btn-lg {
            padding: var(--space-4) var(--space-6);
            font-size: var(--text-base);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }
        
        /* Action buttons */
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: var(--space-6);
            border-top: 1px solid var(--border-light);
            margin-top: var(--space-6);
        }
        
        /* Password strength meter */
        .password-strength {
            margin-top: var(--space-2);
        }
        
        .strength-meter {
            height: 4px;
            background: var(--gray-200);
            border-radius: var(--rounded-full);
            margin-bottom: var(--space-1);
            overflow: hidden;
        }
        
        .strength-meter-fill {
            height: 100%;
            border-radius: var(--rounded-full);
            transition: var(--transition-normal);
        }
        
        .strength-meter-fill.weak {
            width: 25%;
            background: var(--danger);
        }
        
        .strength-meter-fill.medium {
            width: 50%;
            background: var(--warning);
        }
        
        .strength-meter-fill.strong {
            width: 75%;
            background: var(--accent);
        }
        
        .strength-meter-fill.very-strong {
            width: 100%;
            background: var(--success);
        }
        
        .strength-text {
            font-size: var(--text-xs);
            display: flex;
            justify-content: space-between;
        }
        
        .strength-label {
            font-weight: var(--font-medium);
        }
        
        .strength-label.weak {
            color: var(--danger);
        }
        
        .strength-label.medium {
            color: var(--warning);
        }
        
        .strength-label.strong {
            color: var(--accent);
        }
        
        .strength-label.very-strong {
            color: var(--success);
        }
        
        /* Mobile Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .content {
                padding: var(--space-4);
            }
            
            .form-actions {
                flex-direction: column;
                gap: var(--space-4);
            }
            
            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Accessibility */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
        
        *:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }
    </style>
</head>

<body>
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">A</div>
                <h1 class="sidebar-title">Admin Panel</h1>
            </div>
            
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tổng quan</div>
                    <div class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <span class="nav-icon">📊</span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="analytics.php" class="nav-link">
                            <span class="nav-icon">📈</span>
                            <span class="nav-text">Phân tích</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Bán hàng</div>
                    <div class="nav-item">
                        <a href="orders.php" class="nav-link">
                            <span class="nav-icon">📦</span>
                            <span class="nav-text">Đơn hàng</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="products.php" class="nav-link">
                            <span class="nav-icon">🛍️</span>
                            <span class="nav-text">Sản phẩm</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="categories.php" class="nav-link">
                            <span class="nav-icon">📂</span>
                            <span class="nav-text">Danh mục</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="brands.php" class="nav-link">
                            <span class="nav-icon">🏷️</span>
                            <span class="nav-text">Thương hiệu</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Khách hàng</div>
                    <div class="nav-item">
                        <a href="users.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Người dùng</span>
                        </a>
                    </div>   
                    <div class="nav-item">
                        <a href="sellers.php" class="nav-link">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Người Bán</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="reviews.php" class="nav-link">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Đánh giá</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="contacts.php" class="nav-link">
                            <span class="nav-icon">💬</span>
                            <span class="nav-text">Liên hệ</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Marketing</div>
                    <div class="nav-item">
                        <a href="coupons.php" class="nav-link">
                            <span class="nav-icon">🎫</span>
                            <span class="nav-text">Mã giảm giá</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="flash-deals.php" class="nav-link">
                            <span class="nav-icon">⚡</span>
                            <span class="nav-text">Flash Deals</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="banners.php" class="nav-link">
                            <span class="nav-icon">🖼️</span>
                            <span class="nav-text">Banner</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Hệ thống</div>
                    <div class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <span class="nav-icon">⚙️</span>
                            <span class="nav-text">Cài đặt</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="staff.php" class="nav-link active">
                            <span class="nav-icon">👨‍💼</span>
                            <span class="nav-text">Nhân viên</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="backups.php" class="nav-link">
                            <span class="nav-icon">💾</span>
                            <span class="nav-text">Sao lưu</span>
                        </a>
                    </div>
                </div>
            </nav>
        </aside>
        
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