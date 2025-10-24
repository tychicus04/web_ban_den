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

// Currency format function
function formatCurrency($amount, $currency = 'VND') {
    if ($currency === 'VND') {
        return number_format($amount, 0, ',', '.') . '₫';
    } else {
        return '$' . number_format($amount, 2, '.', ',');
    }
}

// Handle form submissions via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    try {
        switch ($_POST['action']) {
            case 'add_package':
                $name = $_POST['name'] ?? '';
                $amount = floatval($_POST['amount'] ?? 0);
                $product_upload_limit = intval($_POST['product_upload_limit'] ?? 0);
                $duration = intval($_POST['duration'] ?? 0);
                $logo = $_POST['logo'] ?? '';
                
                // Validation
                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Tên gói không được để trống']);
                    break;
                }
                
                // Insert new package
                $stmt = $db->prepare("
                    INSERT INTO seller_packages (name, amount, product_upload_limit, logo, duration, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$name, $amount, $product_upload_limit, $logo, $duration]);
                
                echo json_encode(['success' => true, 'message' => 'Đã thêm gói người bán mới']);
                break;
                
            case 'update_package':
                $id = intval($_POST['id'] ?? 0);
                $name = $_POST['name'] ?? '';
                $amount = floatval($_POST['amount'] ?? 0);
                $product_upload_limit = intval($_POST['product_upload_limit'] ?? 0);
                $duration = intval($_POST['duration'] ?? 0);
                $logo = $_POST['logo'] ?? '';
                
                // Validation
                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Tên gói không được để trống']);
                    break;
                }
                
                // Update package
                $stmt = $db->prepare("
                    UPDATE seller_packages 
                    SET name = ?, amount = ?, product_upload_limit = ?, logo = ?, duration = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$name, $amount, $product_upload_limit, $logo, $duration, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Đã cập nhật gói người bán']);
                break;
                
            case 'delete_package':
                $id = intval($_POST['id'] ?? 0);
                
                // Check if package is in use
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM sellers WHERE seller_package_id = ?");
                $stmt->execute([$id]);
                $count = $stmt->fetch()['count'];
                
                if ($count > 0) {
                    echo json_encode(['success' => false, 'message' => 'Không thể xóa gói đang được sử dụng bởi ' . $count . ' người bán']);
                    break;
                }
                
                // Delete package
                $stmt = $db->prepare("DELETE FROM seller_packages WHERE id = ?");
                $stmt->execute([$id]);
                
                // Delete translations
                $stmt = $db->prepare("DELETE FROM seller_package_translations WHERE seller_package_id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Đã xóa gói người bán']);
                break;
                
            case 'upload_logo':
                if (!isset($_FILES['logo'])) {
                    echo json_encode(['success' => false, 'message' => 'Không có file nào được chọn']);
                    break;
                }
                
                $file = $_FILES['logo'];
                $fileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                // Validate file type
                $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
                if (!in_array($fileType, $allowedTypes)) {
                    echo json_encode(['success' => false, 'message' => 'Chỉ cho phép file JPG, JPEG, PNG & GIF']);
                    break;
                }
                
                // Validate file size (2MB)
                if ($file['size'] > 2 * 1024 * 1024) {
                    echo json_encode(['success' => false, 'message' => 'Kích thước file không được vượt quá 2MB']);
                    break;
                }
                
                // Generate unique filename
                $fileName = 'package_' . time() . '_' . uniqid() . '.' . $fileType;
                $uploadPath = '../uploads/seller_packages/' . $fileName;
                
                // Create directory if not exists
                if (!file_exists('../uploads/seller_packages/')) {
                    mkdir('../uploads/seller_packages/', 0777, true);
                }
                
                // Upload file
                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    // Insert into uploads table
                    $stmt = $db->prepare("
                        INSERT INTO uploads (file_original_name, file_name, user_id, file_size, extension, type, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, 'image', NOW(), NOW())
                    ");
                    $stmt->execute([$file['name'], $fileName, $_SESSION['user_id'], $file['size'], $fileType]);
                    
                    $uploadId = $db->lastInsertId();
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Đã tải lên logo thành công',
                        'file_id' => $uploadId,
                        'file_path' => $uploadPath
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Có lỗi xảy ra khi tải lên logo']);
                }
                break;
                
            case 'get_package':
                $id = intval($_POST['id'] ?? 0);
                
                $stmt = $db->prepare("SELECT * FROM seller_packages WHERE id = ?");
                $stmt->execute([$id]);
                $package = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$package) {
                    echo json_encode(['success' => false, 'message' => 'Không tìm thấy gói']);
                    break;
                }
                
                echo json_encode(['success' => true, 'package' => $package]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ']);
        }
    } catch (PDOException $e) {
        error_log("Package action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()]);
    }
    exit;
}

// Get all packages
$packages = [];
try {
    // Count total packages
    $stmt = $db->query("SELECT COUNT(*) as total FROM seller_packages");
    $total_packages = $stmt->fetch()['total'];
    
    // Get packages with subscription stats
    $sql = "
        SELECT sp.*,
               COUNT(DISTINCT s.user_id) as active_sellers,
               COUNT(DISTINCT spp.id) as total_payments,
               SUM(spp.amount) as total_revenue
        FROM seller_packages sp
        LEFT JOIN sellers s ON sp.id = s.seller_package_id
        LEFT JOIN seller_package_payments spp ON sp.id = spp.seller_package_id
        GROUP BY sp.id
        ORDER BY sp.created_at DESC
    ";
    
    $stmt = $db->query($sql);
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Packages fetch error: " . $e->getMessage());
    $packages = [];
}

// Package statistics
$stats = [];
try {
    // Total packages
    $stats['total'] = $total_packages;
    
    // Total sellers with packages
    $stmt = $db->query("SELECT COUNT(DISTINCT user_id) as count FROM sellers WHERE seller_package_id IS NOT NULL");
    $stats['subscribed_sellers'] = $stmt->fetch()['count'];
    
    // Total revenue from packages
    $stmt = $db->query("SELECT SUM(amount) as total FROM seller_package_payments");
    $stats['total_revenue'] = $stmt->fetch()['total'] ?? 0;
    
    // Packages payments this month
    $currentMonth = date('Y-m-01');
    $stmt = $db->prepare("
        SELECT COUNT(*) as count, SUM(amount) as total 
        FROM seller_package_payments 
        WHERE created_at >= ?
    ");
    $stmt->execute([$currentMonth]);
    $monthly = $stmt->fetch();
    $stats['monthly_payments'] = $monthly['count'] ?? 0;
    $stats['monthly_revenue'] = $monthly['total'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Package stats error: " . $e->getMessage());
    $stats = ['total' => 0, 'subscribed_sellers' => 0, 'total_revenue' => 0, 'monthly_payments' => 0, 'monthly_revenue' => 0];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý gói người bán - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý gói người bán - Admin <?php echo htmlspecialchars($site_name); ?>">
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
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-8);
        }
        
        .stat-card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            padding: var(--space-5);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            transition: var(--transition-normal);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-3);
        }
        
        .stat-title {
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            color: var(--text-secondary);
        }
        
        .stat-icon {
            font-size: var(--text-xl);
            color: var(--primary);
        }
        
        .stat-value {
            font-family: var(--font-heading);
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            color: var(--text-primary);
        }
        
        .stat-card.orange::before {
            background: var(--warning-gradient);
        }
        
        .stat-card.green::before {
            background: var(--success-gradient);
        }
        
        .stat-card.purple::before {
            background: var(--secondary-gradient);
        }
        
        .stat-card.blue::before {
            background: var(--accent-gradient);
        }
        
        /* Toolbar */
        .toolbar {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            padding: var(--space-5);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            margin-bottom: var(--space-6);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .toolbar-left {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .toolbar-right {
            display: flex;
            align-items: center;
            gap: var(--space-3);
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
        
        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }
        
        .btn-danger:hover {
            background: var(--secondary-dark);
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: var(--success);
            color: var(--white);
        }
        
        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
        }
        
        .btn-warning {
            background: var(--warning);
            color: var(--white);
        }
        
        .btn-warning:hover {
            background: #d97706;
            transform: translateY(-1px);
        }
        
        .btn-sm {
            padding: var(--space-2) var(--space-3);
            font-size: var(--text-xs);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }
        
        /* Package Cards */
        .package-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: var(--space-6);
            margin-bottom: var(--space-8);
        }
        
        .package-card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-light);
            overflow: hidden;
            transition: var(--transition-normal);
            position: relative;
            display: flex;
            flex-direction: column;
        }
        
        .package-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }
        
        .package-header {
            position: relative;
            padding: var(--space-5);
            background: var(--primary-gradient);
            color: var(--white);
            text-align: center;
        }
        
        .package-name {
            font-size: var(--text-xl);
            font-weight: var(--font-bold);
            margin-bottom: var(--space-2);
        }
        
        .package-price {
            font-size: var(--text-3xl);
            font-weight: var(--font-bold);
        }
        
        .package-duration {
            margin-top: var(--space-1);
            font-size: var(--text-sm);
            opacity: 0.8;
        }
        
        .package-logo {
            width: 60px;
            height: 60px;
            border-radius: var(--rounded-lg);
            position: absolute;
            top: var(--space-5);
            left: var(--space-5);
            object-fit: cover;
            background: var(--white);
            padding: var(--space-1);
            box-shadow: var(--shadow-md);
        }
        
        .package-body {
            padding: var(--space-5);
            flex-grow: 1;
        }
        
        .package-feature {
            display: flex;
            align-items: center;
            margin-bottom: var(--space-3);
            gap: var(--space-3);
        }
        
        .package-feature-icon {
            color: var(--success);
            font-size: var(--text-lg);
        }
        
        .package-feature-text {
            font-size: var(--text-sm);
        }
        
        .package-stats {
            margin-top: var(--space-4);
            padding-top: var(--space-4);
            border-top: 1px solid var(--border-light);
        }
        
        .package-stat {
            display: flex;
            justify-content: space-between;
            font-size: var(--text-sm);
            margin-bottom: var(--space-2);
        }
        
        .package-stat-label {
            color: var(--text-secondary);
        }
        
        .package-stat-value {
            font-weight: var(--font-semibold);
        }
        
        .package-footer {
            padding: var(--space-4);
            background: var(--gray-50);
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            gap: var(--space-2);
        }
        
        /* Form styles */
        .form-group {
            margin-bottom: var(--space-4);
        }
        
        .form-label {
            display: block;
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            margin-bottom: var(--space-2);
            color: var(--text-secondary);
        }
        
        .form-control {
            width: 100%;
            padding: var(--space-3) var(--space-4);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            transition: var(--transition-normal);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-control:disabled {
            background-color: var(--gray-100);
            cursor: not-allowed;
        }
        
        .form-hint {
            margin-top: var(--space-1);
            font-size: var(--text-xs);
            color: var(--text-tertiary);
        }
        
        .form-error {
            margin-top: var(--space-1);
            font-size: var(--text-xs);
            color: var(--danger);
        }
        
        /* Modal */
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1050;
            opacity: 0;
            transition: opacity 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }
        
        .modal-backdrop.show {
            opacity: 1;
            pointer-events: auto;
        }
        
        .modal-content {
            background-color: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-xl);
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }
        
        .modal-backdrop.show .modal-content {
            transform: translateY(0);
        }
        
        .modal-header {
            padding: var(--space-4) var(--space-6);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-title {
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: var(--text-xl);
            cursor: pointer;
            color: var(--text-secondary);
            transition: var(--transition-normal);
        }
        
        .modal-close:hover {
            color: var(--text-primary);
        }
        
        .modal-body {
            padding: var(--space-6);
        }
        
        .modal-footer {
            padding: var(--space-4) var(--space-6);
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: var(--space-3);
        }
        
        /* Notification system */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--primary);
            color: white;
            padding: var(--space-4) var(--space-5);
            border-radius: var(--rounded-lg);
            box-shadow: var(--shadow-xl);
            z-index: 9999;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            max-width: 350px;
            font-weight: var(--font-medium);
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification.success {
            background: var(--success);
        }
        
        .notification.error {
            background: var(--danger);
        }
        
        .notification.warning {
            background: var(--warning);
        }
        
        /* File upload */
        .file-upload {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        
        .file-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: var(--space-6);
            border: 2px dashed var(--border);
            border-radius: var(--rounded-lg);
            cursor: pointer;
            transition: var(--transition-normal);
        }
        
        .file-upload-label:hover {
            border-color: var(--primary);
            background-color: rgba(102, 126, 234, 0.05);
        }
        
        .file-upload-icon {
            font-size: var(--text-3xl);
            margin-bottom: var(--space-3);
            color: var(--text-tertiary);
        }
        
        .file-upload-text {
            font-size: var(--text-sm);
            color: var(--text-secondary);
        }
        
        .file-upload-input {
            position: absolute;
            width: 0.1px;
            height: 0.1px;
            opacity: 0;
            overflow: hidden;
            z-index: -1;
        }
        
        .file-preview {
            margin-top: var(--space-4);
            display: flex;
            align-items: center;
            gap: var(--space-4);
        }
        
        .file-preview-image {
            width: 60px;
            height: 60px;
            border-radius: var(--rounded-lg);
            object-fit: cover;
            border: 1px solid var(--border);
        }
        
        .file-preview-info {
            flex: 1;
        }
        
        .file-preview-name {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            margin-bottom: var(--space-1);
        }
        
        .file-preview-size {
            font-size: var(--text-xs);
            color: var(--text-tertiary);
        }
        
        .file-preview-remove {
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            font-size: var(--text-xl);
            transition: var(--transition-normal);
        }
        
        .file-preview-remove:hover {
            transform: scale(1.1);
        }
        
        /* Loading */
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--border);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
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
            
            .package-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .content {
                padding: var(--space-4);
            }
            
            .toolbar {
                flex-direction: column;
                gap: var(--space-4);
                align-items: stretch;
            }
            
            .toolbar-left,
            .toolbar-right {
                flex-direction: column;
                align-items: stretch;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
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
                            <span class="nav-icon">🏪</span>
                            <span class="nav-text">Cửa hàng</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="seller-package.php" class="nav-link active">
                            <span class="nav-icon">📦</span>
                            <span class="nav-text">Gói người bán</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="reviews.php" class="nav-link">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Đánh giá</span>
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
                        <a href="staff.php" class="nav-link">
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
                            <a href="sellers.php">Cửa hàng</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span>Gói người bán</span>
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
                                <div class="user-role"><?php echo htmlspecialchars($_SESSION['admin_role'] ?? 'Administrator'); ?></div>
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
                    <h1 class="page-title">Quản lý gói người bán</h1>
                    <p class="page-subtitle">Tạo và quản lý các gói dịch vụ cho người bán trên sàn thương mại điện tử</p>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Tổng gói</div>
                            <div class="stat-icon">📦</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    </div>
                    
                    <div class="stat-card green">
                        <div class="stat-header">
                            <div class="stat-title">Người bán đăng ký</div>
                            <div class="stat-icon">👥</div>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['subscribed_sellers']); ?></div>
                    </div>
                    
                    <div class="stat-card blue">
                        <div class="stat-header">
                            <div class="stat-title">Doanh thu gói</div>
                            <div class="stat-icon">💰</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($stats['total_revenue']); ?></div>
                    </div>
                    
                    <div class="stat-card orange">
                        <div class="stat-header">
                            <div class="stat-title">Doanh thu tháng này</div>
                            <div class="stat-icon">📅</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($stats['monthly_revenue']); ?></div>
                    </div>
                </div>
                
                <!-- Toolbar -->
                <div class="toolbar">
                    <div class="toolbar-left">
                        <span>Quản lý các gói dịch vụ cho người bán</span>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn btn-primary" onclick="openAddModal()">
                            <span>➕</span>
                            <span>Thêm gói mới</span>
                        </button>
                    </div>
                </div>
                
                <!-- Package Grid -->
                <div class="package-grid">
                    <?php foreach ($packages as $package): ?>
                        <div class="package-card" data-package-id="<?php echo $package['id']; ?>">
                            <div class="package-header">
                                <?php if ($package['logo']): ?>
                                    <img src="<?php echo htmlspecialchars($package['logo']); ?>" alt="Package logo" class="package-logo">
                                <?php endif; ?>
                                <h3 class="package-name"><?php echo htmlspecialchars($package['name']); ?></h3>
                                <div class="package-price"><?php echo formatCurrency($package['amount']); ?></div>
                                <div class="package-duration"><?php echo $package['duration']; ?> ngày</div>
                            </div>
                            <div class="package-body">
                                <div class="package-feature">
                                    <span class="package-feature-icon">✅</span>
                                    <span class="package-feature-text"><?php echo number_format($package['product_upload_limit']); ?> sản phẩm tối đa</span>
                                </div>
                                <div class="package-feature">
                                    <span class="package-feature-icon">✅</span>
                                    <span class="package-feature-text">Hiển thị trên trang chủ</span>
                                </div>
                                <div class="package-feature">
                                    <span class="package-feature-icon">✅</span>
                                    <span class="package-feature-text">Hỗ trợ đa thiết bị</span>
                                </div>
                                <div class="package-feature">
                                    <span class="package-feature-icon">✅</span>
                                    <span class="package-feature-text">Hỗ trợ kỹ thuật 24/7</span>
                                </div>
                                
                                <div class="package-stats">
                                    <div class="package-stat">
                                        <span class="package-stat-label">Người bán đã đăng ký:</span>
                                        <span class="package-stat-value"><?php echo number_format($package['active_sellers'] ?? 0); ?></span>
                                    </div>
                                    <div class="package-stat">
                                        <span class="package-stat-label">Tổng thanh toán:</span>
                                        <span class="package-stat-value"><?php echo number_format($package['total_payments'] ?? 0); ?></span>
                                    </div>
                                    <div class="package-stat">
                                        <span class="package-stat-label">Doanh thu:</span>
                                        <span class="package-stat-value"><?php echo formatCurrency($package['total_revenue'] ?? 0); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="package-footer">
                                <button class="btn btn-secondary btn-sm" onclick="editPackage(<?php echo $package['id']; ?>)">
                                    <span>✏️</span>
                                    <span>Chỉnh sửa</span>
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="deletePackage(<?php echo $package['id']; ?>)">
                                    <span>🗑️</span>
                                    <span>Xóa</span>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (count($packages) === 0): ?>
                        <div style="grid-column: 1 / -1; text-align: center; padding: var(--space-8); background: var(--surface); border-radius: var(--rounded-xl); border: 1px dashed var(--border);">
                            <div style="font-size: var(--text-xl); margin-bottom: var(--space-4); color: var(--text-secondary);">
                                Chưa có gói người bán nào
                            </div>
                            <button class="btn btn-primary" onclick="openAddModal()">
                                <span>➕</span>
                                <span>Thêm gói mới</span>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Add/Edit Package Modal -->
    <div class="modal-backdrop" id="package-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modal-title">Thêm gói người bán</h2>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            <div class="modal-body">
                <form id="package-form">
                    <input type="hidden" id="package-id" value="">
                    <input type="hidden" id="package-logo" value="">
                    
                    <div class="form-group">
                        <label class="form-label" for="package-name">Tên gói <span style="color: red">*</span></label>
                        <input type="text" class="form-control" id="package-name" placeholder="Nhập tên gói" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="package-amount">Giá <span style="color: red">*</span></label>
                        <input type="number" class="form-control" id="package-amount" placeholder="Nhập giá gói" required min="0" step="1000">
                        <div class="form-hint">Giá sẽ được hiển thị theo định dạng tiền tệ mặc định</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="package-product-limit">Giới hạn sản phẩm <span style="color: red">*</span></label>
                        <input type="number" class="form-control" id="package-product-limit" placeholder="Nhập số lượng sản phẩm tối đa" required min="1">
                        <div class="form-hint">Số lượng sản phẩm tối đa người bán có thể đăng</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="package-duration">Thời hạn (ngày) <span style="color: red">*</span></label>
                        <input type="number" class="form-control" id="package-duration" placeholder="Nhập thời hạn gói (ngày)" required min="1">
                        <div class="form-hint">Thời gian gói dịch vụ có hiệu lực</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Logo</label>
                        <div class="file-upload">
                            <label class="file-upload-label" for="logo-upload">
                                <span class="file-upload-icon">📷</span>
                                <span class="file-upload-text">Kéo thả hoặc nhấp để tải lên logo</span>
                            </label>
                            <input type="file" id="logo-upload" class="file-upload-input" accept="image/*">
                        </div>
                        <div class="file-preview" id="logo-preview" style="display: none;">
                            <img src="" alt="Logo preview" class="file-preview-image" id="logo-preview-image">
                            <div class="file-preview-info">
                                <div class="file-preview-name" id="logo-preview-name"></div>
                                <div class="file-preview-size" id="logo-preview-size"></div>
                            </div>
                            <button type="button" class="file-preview-remove" onclick="removeLogo()">×</button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Hủy</button>
                <button class="btn btn-primary" onclick="savePackage()" id="save-button">Lưu</button>
            </div>
        </div>
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
        
        // Modal functionality
        const packageModal = document.getElementById('package-modal');
        const modalTitle = document.getElementById('modal-title');
        const packageForm = document.getElementById('package-form');
        const packageId = document.getElementById('package-id');
        const packageName = document.getElementById('package-name');
        const packageAmount = document.getElementById('package-amount');
        const packageProductLimit = document.getElementById('package-product-limit');
        const packageDuration = document.getElementById('package-duration');
        const packageLogo = document.getElementById('package-logo');
        const logoUpload = document.getElementById('logo-upload');
        const logoPreview = document.getElementById('logo-preview');
        const logoPreviewImage = document.getElementById('logo-preview-image');
        const logoPreviewName = document.getElementById('logo-preview-name');
        const logoPreviewSize = document.getElementById('logo-preview-size');
        const saveButton = document.getElementById('save-button');
        
        function openAddModal() {
            modalTitle.textContent = 'Thêm gói người bán';
            packageId.value = '';
            packageName.value = '';
            packageAmount.value = '';
            packageProductLimit.value = '';
            packageDuration.value = '';
            packageLogo.value = '';
            logoPreview.style.display = 'none';
            logoPreviewImage.src = '';
            packageModal.classList.add('show');
            saveButton.textContent = 'Thêm gói';
        }
        
        async function editPackage(id) {
            modalTitle.textContent = 'Chỉnh sửa gói người bán';
            saveButton.textContent = 'Cập nhật';
            
            try {
                const formData = new FormData();
                formData.append('action', 'get_package');
                formData.append('id', id);
                formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const package = result.package;
                    
                    packageId.value = package.id;
                    packageName.value = package.name;
                    packageAmount.value = package.amount;
                    packageProductLimit.value = package.product_upload_limit;
                    packageDuration.value = package.duration;
                    packageLogo.value = package.logo;
                    
                    if (package.logo) {
                        logoPreviewImage.src = package.logo;
                        logoPreviewName.textContent = 'Current logo';
                        logoPreviewSize.textContent = 'Click to change';
                        logoPreview.style.display = 'flex';
                    } else {
                        logoPreview.style.display = 'none';
                    }
                    
                    packageModal.classList.add('show');
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
            }
        }
        
        function closeModal() {
            packageModal.classList.remove('show');
        }
        
        // Close modal when clicking on backdrop
        packageModal.addEventListener('click', function(e) {
            if (e.target === packageModal) {
                closeModal();
            }
        });
        
        // Handle logo upload
        logoUpload.addEventListener('change', function(e) {
            const file = e.target.files[0];
            
            if (file) {
                // Check file type
                const fileType = file.type;
                if (!fileType.startsWith('image/')) {
                    showNotification('Vui lòng chọn file hình ảnh', 'error');
                    return;
                }
                
                // Check file size (2MB)
                if (file.size > 2 * 1024 * 1024) {
                    showNotification('Kích thước file không được vượt quá 2MB', 'error');
                    return;
                }
                
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    logoPreviewImage.src = e.target.result;
                    logoPreviewName.textContent = file.name;
                    logoPreviewSize.textContent = formatFileSize(file.size);
                    logoPreview.style.display = 'flex';
                };
                reader.readAsDataURL(file);
                
                // Upload logo
                uploadLogo(file);
            }
        });
        
        function formatFileSize(size) {
            if (size < 1024) {
                return size + ' bytes';
            } else if (size < 1024 * 1024) {
                return (size / 1024).toFixed(2) + ' KB';
            } else {
                return (size / (1024 * 1024)).toFixed(2) + ' MB';
            }
        }
        
        function removeLogo() {
            logoUpload.value = '';
            packageLogo.value = '';
            logoPreview.style.display = 'none';
            logoPreviewImage.src = '';
        }
        
        async function uploadLogo(file) {
            try {
                const formData = new FormData();
                formData.append('action', 'upload_logo');
                formData.append('logo', file);
                formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    packageLogo.value = result.file_path;
                    showNotification('Logo đã được tải lên', 'success');
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra khi tải lên logo: ' + error.message, 'error');
            }
        }
        
        async function savePackage() {
            // Validate form
            if (!packageName.value.trim()) {
                showNotification('Vui lòng nhập tên gói', 'error');
                packageName.focus();
                return;
            }
            
            if (!packageAmount.value || packageAmount.value < 0) {
                showNotification('Vui lòng nhập giá gói hợp lệ', 'error');
                packageAmount.focus();
                return;
            }
            
            if (!packageProductLimit.value || packageProductLimit.value < 1) {
                showNotification('Vui lòng nhập giới hạn sản phẩm hợp lệ', 'error');
                packageProductLimit.focus();
                return;
            }
            
            if (!packageDuration.value || packageDuration.value < 1) {
                showNotification('Vui lòng nhập thời hạn gói hợp lệ', 'error');
                packageDuration.focus();
                return;
            }
            
            // Save package
            try {
                const formData = new FormData();
                formData.append('action', packageId.value ? 'update_package' : 'add_package');
                formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
                
                if (packageId.value) {
                    formData.append('id', packageId.value);
                }
                
                formData.append('name', packageName.value);
                formData.append('amount', packageAmount.value);
                formData.append('product_upload_limit', packageProductLimit.value);
                formData.append('duration', packageDuration.value);
                formData.append('logo', packageLogo.value);
                
                // Disable save button
                saveButton.disabled = true;
                saveButton.innerHTML = '<span class="loading"></span> Đang xử lý';
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                    closeModal();
                    
                    // Reload page after 1 second
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showNotification(result.message, 'error');
                    saveButton.disabled = false;
                    saveButton.textContent = packageId.value ? 'Cập nhật' : 'Thêm gói';
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
                saveButton.disabled = false;
                saveButton.textContent = packageId.value ? 'Cập nhật' : 'Thêm gói';
            }
        }
        
        async function deletePackage(id) {
            if (!confirm('Bạn có chắc chắn muốn xóa gói này? Hành động này không thể hoàn tác.')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'delete_package');
                formData.append('id', id);
                formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                    
                    // Remove package card
                    document.querySelector(`.package-card[data-package-id="${id}"]`).remove();
                    
                    // Reload page if no packages left
                    if (document.querySelectorAll('.package-card').length === 0) {
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    }
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
            }
        }
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // Hide and remove notification after 3 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Seller Package Management - Initializing...');
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Seller Package Management - Ready!');
        });
    </script>
</body>
</html>