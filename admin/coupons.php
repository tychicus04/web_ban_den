<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Include database config
require_once '../config.php';

$db = getDBConnection();

// Authentication check
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
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

// Pagination setup
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;
$offset = ($page - 1) * $per_page;

// Search/filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : '';
$status = isset($_GET['status']) && is_numeric($_GET['status']) ? intval($_GET['status']) : -1;
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';

// Get current timestamp
$current_time = time();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    try {
        switch ($_POST['action']) {
            case 'create_coupon':
                if (!validateCouponData($_POST)) {
                    echo json_encode(['success' => false, 'message' => 'Vui lòng điền đầy đủ thông tin mã giảm giá']);
                    break;
                }
                
                // Check if code already exists
                $stmt = $db->prepare("SELECT id FROM coupons WHERE code = ?");
                $stmt->execute([trim($_POST['code'])]);
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => false, 'message' => 'Mã giảm giá đã tồn tại']);
                    break;
                }
                
                $stmt = $db->prepare("
                    INSERT INTO coupons (
                        user_id, type, code, details, discount, discount_type, 
                        start_date, end_date, status, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                    )
                ");
                
                $startDate = strtotime($_POST['start_date']);
                $endDate = strtotime($_POST['end_date']);
                $details = prepareCouponDetails($_POST);
                
                $stmt->execute([
                    $_SESSION['user_id'],
                    $_POST['type'],
                    trim($_POST['code']),
                    json_encode($details),
                    floatval($_POST['discount']),
                    $_POST['discount_type'],
                    $startDate,
                    $endDate,
                    isset($_POST['status']) ? 1 : 0
                ]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Mã giảm giá đã được tạo thành công',
                    'coupon_id' => $db->lastInsertId()
                ]);
                break;
                
            case 'update_coupon':
                if (!isset($_POST['coupon_id']) || !is_numeric($_POST['coupon_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid coupon ID']);
                    break;
                }
                
                if (!validateCouponData($_POST)) {
                    echo json_encode(['success' => false, 'message' => 'Vui lòng điền đầy đủ thông tin mã giảm giá']);
                    break;
                }
                
                $coupon_id = intval($_POST['coupon_id']);
                
                // Check if code already exists for other coupons
                $stmt = $db->prepare("SELECT id FROM coupons WHERE code = ? AND id != ?");
                $stmt->execute([trim($_POST['code']), $coupon_id]);
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => false, 'message' => 'Mã giảm giá đã tồn tại']);
                    break;
                }
                
                $stmt = $db->prepare("
                    UPDATE coupons SET
                        type = ?,
                        code = ?,
                        details = ?,
                        discount = ?,
                        discount_type = ?,
                        start_date = ?,
                        end_date = ?,
                        status = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $startDate = strtotime($_POST['start_date']);
                $endDate = strtotime($_POST['end_date']);
                $details = prepareCouponDetails($_POST);
                
                $stmt->execute([
                    $_POST['type'],
                    trim($_POST['code']),
                    json_encode($details),
                    floatval($_POST['discount']),
                    $_POST['discount_type'],
                    $startDate,
                    $endDate,
                    isset($_POST['status']) ? 1 : 0,
                    $coupon_id
                ]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Mã giảm giá đã được cập nhật thành công'
                ]);
                break;
                
            case 'delete_coupon':
                if (!isset($_POST['coupon_id']) || !is_numeric($_POST['coupon_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid coupon ID']);
                    break;
                }
                
                $coupon_id = intval($_POST['coupon_id']);
                
                $stmt = $db->prepare("DELETE FROM coupons WHERE id = ?");
                $stmt->execute([$coupon_id]);
                
                // Also delete related coupon usages
                $stmt = $db->prepare("DELETE FROM coupon_usages WHERE coupon_id = ?");
                $stmt->execute([$coupon_id]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Mã giảm giá đã được xóa thành công'
                ]);
                break;
                
            case 'toggle_status':
                if (!isset($_POST['coupon_id']) || !is_numeric($_POST['coupon_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid coupon ID']);
                    break;
                }
                
                $coupon_id = intval($_POST['coupon_id']);
                $status = isset($_POST['status']) ? intval($_POST['status']) : 0;
                
                $stmt = $db->prepare("UPDATE coupons SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$status, $coupon_id]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => $status ? 'Mã giảm giá đã được kích hoạt' : 'Mã giảm giá đã bị vô hiệu hóa'
                ]);
                break;
                
            case 'get_coupon':
                if (!isset($_POST['coupon_id']) || !is_numeric($_POST['coupon_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid coupon ID']);
                    break;
                }
                
                $coupon_id = intval($_POST['coupon_id']);
                
                $stmt = $db->prepare("SELECT * FROM coupons WHERE id = ?");
                $stmt->execute([$coupon_id]);
                $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$coupon) {
                    echo json_encode(['success' => false, 'message' => 'Không tìm thấy mã giảm giá']);
                    break;
                }
                
                // Get usage count
                $stmt = $db->prepare("SELECT COUNT(*) as usage_count FROM coupon_usages WHERE coupon_id = ?");
                $stmt->execute([$coupon_id]);
                $usage = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $coupon['usage_count'] = $usage ? $usage['usage_count'] : 0;
                $coupon['details'] = json_decode($coupon['details'], true);
                
                echo json_encode([
                    'success' => true, 
                    'coupon' => $coupon
                ]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("Coupon action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Helper function to validate coupon data
function validateCouponData($data) {
    if (empty($data['code']) || 
        empty($data['discount']) || 
        empty($data['discount_type']) || 
        empty($data['type']) || 
        empty($data['start_date']) || 
        empty($data['end_date'])) {
        return false;
    }
    
    // Additional validation based on coupon type
    if ($data['type'] === 'product_base' && (!isset($data['product_ids']) || empty($data['product_ids']))) {
        return false;
    }
    
    if ($data['type'] === 'category_base' && (!isset($data['category_ids']) || empty($data['category_ids']))) {
        return false;
    }
    
    return true;
}

// Helper function to prepare coupon details based on type
function prepareCouponDetails($data) {
    $details = [];
    
    switch ($data['type']) {
        case 'cart_base':
            $details['min_buy'] = isset($data['min_buy']) ? floatval($data['min_buy']) : 0;
            $details['max_discount'] = isset($data['max_discount']) ? floatval($data['max_discount']) : 0;
            break;
            
        case 'product_base':
            $details['product_ids'] = isset($data['product_ids']) ? explode(',', $data['product_ids']) : [];
            break;
            
        case 'category_base':
            $details['category_ids'] = isset($data['category_ids']) ? explode(',', $data['category_ids']) : [];
            break;
            
        case 'user_base':
            $details['user_limit'] = isset($data['user_limit']) ? intval($data['user_limit']) : 0;
            $details['usage_limit_user'] = isset($data['usage_limit_user']) ? intval($data['usage_limit_user']) : 0;
            break;
    }
    
    return $details;
}

// Build the query based on filters
$params = [];
$query = "SELECT c.*, u.name as created_by FROM coupons c LEFT JOIN users u ON c.user_id = u.id WHERE 1=1";

if (!empty($search)) {
    $query .= " AND (c.code LIKE ? OR u.name LIKE ?)";
    $search_param = "%$search%";
    array_push($params, $search_param, $search_param);
}

if (!empty($type)) {
    $query .= " AND c.type = ?";
    $params[] = $type;
}

if ($status !== -1) {
    $query .= " AND c.status = ?";
    $params[] = $status;
}

// Count total records for pagination
$count_query = str_replace("c.*, u.name as created_by", "COUNT(*) as total", $query);
$stmt = $db->prepare($count_query);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'] ?? 0;
$total_pages = ceil($total_records / $per_page);

// Sorting
if ($sort === 'oldest') {
    $query .= " ORDER BY c.created_at ASC";
} elseif ($sort === 'code_asc') {
    $query .= " ORDER BY c.code ASC";
} elseif ($sort === 'code_desc') {
    $query .= " ORDER BY c.code DESC";
} else { // default: newest
    $query .= " ORDER BY c.created_at DESC";
}

// Add pagination
$query .= " LIMIT $per_page OFFSET $offset";

// Fetch coupons
try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $coupons = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Coupon fetch error: " . $e->getMessage());
    $coupons = [];
}

// Fetch product data for select options
$products = [];
try {
    $stmt = $db->prepare("SELECT id, name FROM products WHERE published = 1 ORDER BY name ASC LIMIT 100");
    $stmt->execute();
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Products fetch error: " . $e->getMessage());
}

// Fetch category data for select options
$categories = [];
try {
    $stmt = $db->prepare("SELECT id, name FROM categories ORDER BY name ASC");
    $stmt->execute();
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Categories fetch error: " . $e->getMessage());
}

$site_name = getBusinessSetting($db, 'site_name', 'Your Store');

// Format currency function
function formatCurrency($amount, $currency = 'VND') {
    if ($currency === 'VND') {
        return number_format($amount, 0, ',', '.') . '₫';
    } else {
        return '$' . number_format($amount, 2, '.', ',');
    }
}

// Format date function
function formatDate($timestamp) {
    return date('d/m/Y', $timestamp);
}

// Get coupon status text
function getCouponStatusText($coupon, $currentTime) {
    if ($coupon['status'] == 0) {
        return 'Vô hiệu';
    }
    
    if ($coupon['start_date'] > $currentTime) {
        return 'Sắp diễn ra';
    }
    
    if ($coupon['end_date'] < $currentTime) {
        return 'Đã hết hạn';
    }
    
    return 'Đang hoạt động';
}

// Get coupon status class
function getCouponStatusClass($coupon, $currentTime) {
    if ($coupon['status'] == 0) {
        return 'inactive';
    }
    
    if ($coupon['start_date'] > $currentTime) {
        return 'upcoming';
    }
    
    if ($coupon['end_date'] < $currentTime) {
        return 'expired';
    }
    
    return 'active';
}

// Get coupon type text
function getCouponTypeText($type) {
    switch ($type) {
        case 'cart_base':
            return 'Giảm giá giỏ hàng';
        case 'product_base':
            return 'Giảm giá sản phẩm';
        case 'category_base':
            return 'Giảm giá danh mục';
        case 'user_base':
            return 'Giảm giá cho người dùng';
        default:
            return 'Không xác định';
    }
}

// Get coupon discount text
function getCouponDiscountText($coupon) {
    if ($coupon['discount_type'] === 'amount') {
        return formatCurrency($coupon['discount']);
    } else {
        return $coupon['discount'] . '%';
    }
}

// Get usage count
function getCouponUsage($db, $couponId) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM coupon_usages WHERE coupon_id = ?");
        $stmt->execute([$couponId]);
        $result = $stmt->fetch();
        return $result ? $result['count'] : 0;
    } catch (PDOException $e) {
        return 0;
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý mã giảm giá - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý mã giảm giá - Admin <?php echo htmlspecialchars($site_name); ?>">
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
            margin-bottom: var(--space-6);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .page-title-wrapper {
            flex: 1;
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
        
        /* Filter section */
        .filter-section {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            padding: var(--space-6);
            margin-bottom: var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: var(--space-4);
        }
        
        .form-group {
            margin-bottom: var(--space-4);
        }
        
        .form-group:last-child {
            margin-bottom: 0;
        }
        
        .form-label {
            display: block;
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--text-secondary);
            margin-bottom: var(--space-2);
        }
        
        .form-control {
            width: 100%;
            padding: var(--space-3) var(--space-4);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            background: var(--surface);
            transition: var(--transition-normal);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        /* Card component */
        .card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .card-header {
            padding: var(--space-5);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-title {
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
            color: var(--text-primary);
        }
        
        .card-body {
            padding: var(--space-5);
            flex: 1;
            overflow: auto;
        }
        
        .card-footer {
            padding: var(--space-5);
            border-top: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: var(--space-1) var(--space-3);
            border-radius: var(--rounded-full);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-badge.active {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .status-badge.inactive {
            background: rgba(107, 114, 128, 0.1);
            color: #374151;
        }
        
        .status-badge.upcoming {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .status-badge.expired {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        /* Tables */
        .table-container {
            overflow-x: auto;
            margin-bottom: var(--space-6);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: var(--text-sm);
        }
        
        .table th {
            background: var(--gray-50);
            color: var(--text-secondary);
            font-weight: var(--font-semibold);
            padding: var(--space-3) var(--space-4);
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: var(--text-xs);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table td {
            padding: var(--space-3) var(--space-4);
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
        }
        
        .table tr:last-child td {
            border-bottom: none;
        }
        
        .table tr:hover {
            background: var(--gray-50);
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
        
        .btn-group {
            display: flex;
            gap: var(--space-2);
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            margin-top: var(--space-6);
        }
        
        .pagination-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: var(--rounded-lg);
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--text-secondary);
            background: var(--surface);
            border: 1px solid var(--border);
            text-decoration: none;
            transition: var(--transition-normal);
        }
        
        .pagination-link:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .pagination-link.active {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }
        
        .pagination-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            font-size: var(--text-sm);
            color: var(--text-secondary);
        }
        
        /* Coupon cards */
        .coupon-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: var(--space-4);
        }
        
        .coupon-card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            overflow: hidden;
            transition: var(--transition-normal);
            position: relative;
        }
        
        .coupon-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .coupon-header {
            padding: var(--space-4);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: var(--gray-50);
        }
        
        .coupon-code {
            font-family: monospace;
            font-weight: var(--font-bold);
            font-size: var(--text-lg);
            color: var(--primary);
            letter-spacing: 1px;
        }
        
        .coupon-body {
            padding: var(--space-4);
        }
        
        .coupon-type {
            font-size: var(--text-xs);
            color: var(--text-tertiary);
            margin-bottom: var(--space-2);
        }
        
        .coupon-discount {
            font-size: var(--text-xl);
            font-weight: var(--font-bold);
            color: var(--text-primary);
            margin-bottom: var(--space-2);
        }
        
        .coupon-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-2);
            margin-bottom: var(--space-3);
            font-size: var(--text-xs);
        }
        
        .coupon-detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .coupon-detail-label {
            color: var(--text-tertiary);
            margin-bottom: 2px;
        }
        
        .coupon-detail-value {
            color: var(--text-secondary);
            font-weight: var(--font-medium);
        }
        
        .coupon-footer {
            display: flex;
            justify-content: flex-end;
            padding: var(--space-3);
            border-top: 1px solid var(--border-light);
            gap: var(--space-2);
        }
        
        /* Modal for coupon form */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background-color: var(--surface);
            margin: 5% auto;
            padding: var(--space-6);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-xl);
            width: 700px;
            max-width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            animation: modalOpen 0.3s ease;
        }
        
        @keyframes modalOpen {
            from {opacity: 0; transform: translateY(-30px);}
            to {opacity: 1; transform: translateY(0);}
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
            padding-bottom: var(--space-4);
            border-bottom: 1px solid var(--border-light);
        }
        
        .modal-title {
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
        }
        
        .modal-close {
            font-size: var(--text-2xl);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-tertiary);
            transition: var(--transition-normal);
        }
        
        .modal-close:hover {
            color: var(--text-primary);
        }
        
        .modal-body {
            margin-bottom: var(--space-5);
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
            padding-top: var(--space-4);
            border-top: 1px solid var(--border-light);
        }
        
        /* Coupon form */
        .coupon-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-4);
        }
        
        .coupon-form .form-group.full-width {
            grid-column: span 2;
        }
        
        .form-group-inline {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .form-check {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            margin-bottom: var(--space-3);
        }
        
        .form-check-input {
            width: 16px;
            height: 16px;
        }
        
        .form-check-label {
            font-size: var(--text-sm);
            color: var(--text-secondary);
        }
        
        .input-group {
            display: flex;
            align-items: center;
        }
        
        .input-group .form-control:first-child {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        
        .input-group .input-group-text {
            padding: var(--space-3) var(--space-4);
            background: var(--gray-100);
            border: 1px solid var(--border);
            border-left: none;
            border-top-right-radius: var(--rounded-lg);
            border-bottom-right-radius: var(--rounded-lg);
            color: var(--text-secondary);
            font-size: var(--text-sm);
        }
        
        .field-help {
            font-size: var(--text-xs);
            color: var(--text-tertiary);
            margin-top: var(--space-1);
        }
        
        /* Dynamic form fields based on coupon type */
        .coupon-type-fields {
            margin-top: var(--space-4);
            padding-top: var(--space-4);
            border-top: 1px solid var(--border-light);
        }
        
        /* Notification system */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: var(--space-4) var(--space-5);
            border-radius: var(--rounded-lg);
            box-shadow: var(--shadow-xl);
            z-index: 9999;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            max-width: 350px;
            font-weight: var(--font-medium);
            color: white;
        }
        
        .notification.success {
            background: var(--success);
        }
        
        .notification.error {
            background: var(--danger);
        }
        
        .notification.info {
            background: var(--primary);
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: var(--space-10) 0;
            color: var(--text-tertiary);
        }
        
        .empty-state-icon {
            font-size: var(--text-4xl);
            margin-bottom: var(--space-4);
        }
        
        .empty-state-text {
            font-size: var(--text-lg);
            margin-bottom: var(--space-3);
        }
        
        .empty-state-subtext {
            font-size: var(--text-base);
            max-width: 500px;
            margin: 0 auto;
        }
        
        /* Switch/Toggle */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--gray-300);
            transition: var(--transition-normal);
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: var(--transition-normal);
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--success);
        }
        
        input:focus + .slider {
            box-shadow: 0 0 1px var(--success);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        /* Selectize-like styling for multiple select */
        .select-container {
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            background: var(--surface);
            padding: var(--space-2);
            min-height: 80px;
            max-height: 150px;
            overflow-y: auto;
        }
        
        .select-tags {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-2);
            margin-bottom: var(--space-2);
        }
        
        .select-tag {
            display: inline-flex;
            align-items: center;
            gap: var(--space-1);
            background: var(--gray-100);
            border-radius: var(--rounded);
            padding: var(--space-1) var(--space-2);
            font-size: var(--text-xs);
        }
        
        .select-tag-remove {
            cursor: pointer;
            color: var(--text-tertiary);
        }
        
        .select-tag-remove:hover {
            color: var(--danger);
        }
        
        .select-input {
            border: none;
            outline: none;
            padding: var(--space-1) var(--space-2);
            width: 100%;
            font-size: var(--text-sm);
        }
        
        .select-dropdown {
            position: absolute;
            width: 100%;
            max-height: 200px;
            overflow-y: auto;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--rounded-lg);
            box-shadow: var(--shadow-md);
            z-index: 100;
            display: none;
        }
        
        .select-option {
            padding: var(--space-2) var(--space-3);
            cursor: pointer;
            font-size: var(--text-sm);
        }
        
        .select-option:hover {
            background: var(--gray-100);
        }
        
        /* Responsive styles */
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
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .coupon-form {
                grid-template-columns: 1fr;
            }
            
            .coupon-form .form-group.full-width {
                grid-column: span 1;
            }
        }
        
        @media (max-width: 768px) {
            .content {
                padding: var(--space-4);
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-4);
            }
            
            .btn-group {
                width: 100%;
                justify-content: flex-start;
            }
            
            .coupon-grid {
                grid-template-columns: 1fr;
            }
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
                        <a href="coupons.php" class="nav-link active">
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
                            <span>Mã giảm giá</span>
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
                    <div class="page-title-wrapper">
                        <h1 class="page-title">Quản lý mã giảm giá</h1>
                        <p class="page-subtitle">Tạo và quản lý các mã giảm giá cho cửa hàng</p>
                    </div>
                    <button class="btn btn-primary" onclick="openCouponModal()">
                        <span>➕</span>
                        <span>Tạo mã giảm giá mới</span>
                    </button>
                </div>
                
                <!-- Filter Section -->
                <div class="filter-section">
                    <form action="" method="GET" class="filter-form">
                        <div class="form-group">
                            <label for="search" class="form-label">Tìm kiếm</label>
                            <input type="text" id="search" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Tìm theo mã giảm giá...">
                        </div>
                        
                        <div class="form-group">
                            <label for="type" class="form-label">Loại mã</label>
                            <select id="type" name="type" class="form-control">
                                <option value="" <?php echo $type === '' ? 'selected' : ''; ?>>Tất cả loại</option>
                                <option value="cart_base" <?php echo $type === 'cart_base' ? 'selected' : ''; ?>>Giảm giá giỏ hàng</option>
                                <option value="product_base" <?php echo $type === 'product_base' ? 'selected' : ''; ?>>Giảm giá sản phẩm</option>
                                <option value="category_base" <?php echo $type === 'category_base' ? 'selected' : ''; ?>>Giảm giá danh mục</option>
                                <option value="user_base" <?php echo $type === 'user_base' ? 'selected' : ''; ?>>Giảm giá cho người dùng</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="status" class="form-label">Trạng thái</label>
                            <select id="status" name="status" class="form-control">
                                <option value="-1" <?php echo $status === -1 ? 'selected' : ''; ?>>Tất cả trạng thái</option>
                                <option value="1" <?php echo $status === 1 ? 'selected' : ''; ?>>Đang hoạt động</option>
                                <option value="0" <?php echo $status === 0 ? 'selected' : ''; ?>>Vô hiệu</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="sort" class="form-label">Sắp xếp</label>
                            <select id="sort" name="sort" class="form-control">
                                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Mới nhất</option>
                                <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Cũ nhất</option>
                                <option value="code_asc" <?php echo $sort === 'code_asc' ? 'selected' : ''; ?>>Mã (A-Z)</option>
                                <option value="code_desc" <?php echo $sort === 'code_desc' ? 'selected' : ''; ?>>Mã (Z-A)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="per_page" class="form-label">Hiển thị</label>
                            <select id="per_page" name="per_page" class="form-control">
                                <option value="20" <?php echo $per_page === 20 ? 'selected' : ''; ?>>20 mã</option>
                                <option value="50" <?php echo $per_page === 50 ? 'selected' : ''; ?>>50 mã</option>
                                <option value="100" <?php echo $per_page === 100 ? 'selected' : ''; ?>>100 mã</option>
                            </select>
                        </div>
                        
                        <div class="form-group" style="display: flex; align-items: flex-end;">
                            <button type="submit" class="btn btn-primary">
                                <span>🔍</span>
                                <span>Tìm kiếm</span>
                            </button>
                            
                            <a href="coupons.php" class="btn btn-secondary" style="margin-left: 10px;">
                                <span>🔄</span>
                                <span>Đặt lại</span>
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Coupons List -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Danh sách mã giảm giá (<?php echo $total_records; ?>)</h2>
                    </div>
                    <div class="card-body">
                        <?php if (empty($coupons)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">🎫</div>
                                <h3 class="empty-state-text">Không có mã giảm giá nào</h3>
                                <p class="empty-state-subtext">Bạn chưa tạo mã giảm giá nào. Nhấn nút "Tạo mã giảm giá mới" để bắt đầu.</p>
                            </div>
                        <?php else: ?>
                            <div class="coupon-grid">
                                <?php foreach ($coupons as $coupon): ?>
                                    <?php 
                                    $statusClass = getCouponStatusClass($coupon, $current_time);
                                    $statusText = getCouponStatusText($coupon, $current_time);
                                    $details = json_decode($coupon['details'], true) ?? [];
                                    $usageCount = getCouponUsage($db, $coupon['id']);
                                    ?>
                                    <div class="coupon-card">
                                        <div class="coupon-header">
                                            <div class="coupon-code"><?php echo htmlspecialchars($coupon['code']); ?></div>
                                            <div>
                                                <span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                            </div>
                                        </div>
                                        <div class="coupon-body">
                                            <div class="coupon-type"><?php echo getCouponTypeText($coupon['type']); ?></div>
                                            <div class="coupon-discount"><?php echo getCouponDiscountText($coupon); ?></div>
                                            
                                            <div class="coupon-details">
                                                <div class="coupon-detail-item">
                                                    <div class="coupon-detail-label">Ngày bắt đầu</div>
                                                    <div class="coupon-detail-value"><?php echo formatDate($coupon['start_date']); ?></div>
                                                </div>
                                                <div class="coupon-detail-item">
                                                    <div class="coupon-detail-label">Ngày kết thúc</div>
                                                    <div class="coupon-detail-value"><?php echo formatDate($coupon['end_date']); ?></div>
                                                </div>
                                                <div class="coupon-detail-item">
                                                    <div class="coupon-detail-label">Đã sử dụng</div>
                                                    <div class="coupon-detail-value"><?php echo $usageCount; ?> lần</div>
                                                </div>
                                                <div class="coupon-detail-item">
                                                    <div class="coupon-detail-label">Tạo bởi</div>
                                                    <div class="coupon-detail-value"><?php echo htmlspecialchars($coupon['created_by'] ?? 'Admin'); ?></div>
                                                </div>
                                            </div>
                                            
                                            <?php if ($coupon['type'] === 'cart_base' && isset($details['min_buy'])): ?>
                                                <div style="font-size: var(--text-xs); margin-top: var(--space-2); color: var(--text-secondary);">
                                                    Đơn hàng tối thiểu: <?php echo formatCurrency($details['min_buy']); ?>
                                                    <?php if (isset($details['max_discount']) && $details['max_discount'] > 0): ?>
                                                        <br>Giảm tối đa: <?php echo formatCurrency($details['max_discount']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="coupon-footer">
                                            <button class="btn btn-secondary btn-sm" onclick="viewCoupon(<?php echo $coupon['id']; ?>)">
                                                <span>👁️</span>
                                                <span>Chi tiết</span>
                                            </button>
                                            <button class="btn btn-primary btn-sm" onclick="editCoupon(<?php echo $coupon['id']; ?>)">
                                                <span>✏️</span>
                                                <span>Sửa</span>
                                            </button>
                                            <button class="btn btn-danger btn-sm" onclick="deleteCoupon(<?php echo $coupon['id']; ?>)">
                                                <span>🗑️</span>
                                                <span>Xóa</span>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <div class="pagination">
                                    <?php
                                    // Previous page link
                                    if ($page > 1) {
                                        echo '<a href="?page=' . ($page - 1) . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&type=' . $type . '&status=' . $status . '&sort=' . $sort . '" class="pagination-link">«</a>';
                                    }
                                    
                                    // Page numbers
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if ($start_page > 1) {
                                        echo '<a href="?page=1&per_page=' . $per_page . '&search=' . urlencode($search) . '&type=' . $type . '&status=' . $status . '&sort=' . $sort . '" class="pagination-link">1</a>';
                                        if ($start_page > 2) {
                                            echo '<span class="pagination-ellipsis">…</span>';
                                        }
                                    }
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++) {
                                        echo '<a href="?page=' . $i . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&type=' . $type . '&status=' . $status . '&sort=' . $sort . '" class="pagination-link' . ($i == $page ? ' active' : '') . '">' . $i . '</a>';
                                    }
                                    
                                    if ($end_page < $total_pages) {
                                        if ($end_page < $total_pages - 1) {
                                            echo '<span class="pagination-ellipsis">…</span>';
                                        }
                                        echo '<a href="?page=' . $total_pages . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&type=' . $type . '&status=' . $status . '&sort=' . $sort . '" class="pagination-link">' . $total_pages . '</a>';
                                    }
                                    
                                    // Next page link
                                    if ($page < $total_pages) {
                                        echo '<a href="?page=' . ($page + 1) . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&type=' . $type . '&status=' . $status . '&sort=' . $sort . '" class="pagination-link">»</a>';
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Coupon Modal -->
    <div id="couponModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Tạo mã giảm giá mới</h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="couponForm" class="coupon-form">
                    <input type="hidden" id="coupon_id" name="coupon_id" value="">
                    
                    <div class="form-group">
                        <label for="code" class="form-label">Mã giảm giá <span style="color: red">*</span></label>
                        <input type="text" id="code" name="code" class="form-control" placeholder="Nhập mã giảm giá (VD: SUMMER2025)" required>
                        <div class="field-help">Mã giảm giá không được trùng nhau và sẽ hiển thị cho khách hàng</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="type" class="form-label">Loại mã giảm giá <span style="color: red">*</span></label>
                        <select id="coupon_type" name="type" class="form-control" required onchange="showCouponTypeFields()">
                            <option value="">-- Chọn loại mã giảm giá --</option>
                            <option value="cart_base">Giảm giá giỏ hàng</option>
                            <option value="product_base">Giảm giá sản phẩm</option>
                            <option value="category_base">Giảm giá danh mục</option>
                            <option value="user_base">Giảm giá cho người dùng</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="discount" class="form-label">Giá trị giảm giá <span style="color: red">*</span></label>
                        <div class="form-group-inline">
                            <input type="number" id="discount" name="discount" class="form-control" placeholder="Nhập giá trị giảm giá" required min="0" step="0.01">
                            <select id="discount_type" name="discount_type" class="form-control" style="width: 120px;">
                                <option value="amount">Số tiền</option>
                                <option value="percent">Phần trăm (%)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="start_date" class="form-label">Ngày bắt đầu <span style="color: red">*</span></label>
                        <input type="date" id="start_date" name="start_date" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date" class="form-label">Ngày kết thúc <span style="color: red">*</span></label>
                        <input type="date" id="end_date" name="end_date" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" id="status" name="status" class="form-check-input" checked>
                            <label for="status" class="form-check-label">Kích hoạt</label>
                        </div>
                    </div>
                    
                    <!-- Dynamic fields based on coupon type -->
                    <div id="cart_base_fields" class="coupon-type-fields" style="display: none;">
                        <div class="form-group">
                            <label for="min_buy" class="form-label">Giá trị đơn hàng tối thiểu</label>
                            <div class="input-group">
                                <input type="number" id="min_buy" name="min_buy" class="form-control" placeholder="0" min="0" step="0.01">
                                <span class="input-group-text">VND</span>
                            </div>
                            <div class="field-help">Để trống nếu không có giá trị tối thiểu</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="max_discount" class="form-label">Giảm giá tối đa</label>
                            <div class="input-group">
                                <input type="number" id="max_discount" name="max_discount" class="form-control" placeholder="0" min="0" step="0.01">
                                <span class="input-group-text">VND</span>
                            </div>
                            <div class="field-help">Chỉ áp dụng khi giảm giá theo phần trăm. Để trống nếu không có giới hạn</div>
                        </div>
                    </div>
                    
                    <div id="product_base_fields" class="coupon-type-fields" style="display: none;">
                        <div class="form-group full-width">
                            <label for="product_ids" class="form-label">Sản phẩm áp dụng <span style="color: red">*</span></label>
                            <select id="product_ids" name="product_ids" class="form-control" multiple style="height: 150px;">
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>"><?php echo htmlspecialchars($product['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="field-help">Giữ Ctrl (hoặc Command trên Mac) để chọn nhiều sản phẩm</div>
                        </div>
                    </div>
                    
                    <div id="category_base_fields" class="coupon-type-fields" style="display: none;">
                        <div class="form-group full-width">
                            <label for="category_ids" class="form-label">Danh mục áp dụng <span style="color: red">*</span></label>
                            <select id="category_ids" name="category_ids" class="form-control" multiple style="height: 150px;">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="field-help">Giữ Ctrl (hoặc Command trên Mac) để chọn nhiều danh mục</div>
                        </div>
                    </div>
                    
                    <div id="user_base_fields" class="coupon-type-fields" style="display: none;">
                        <div class="form-group">
                            <label for="user_limit" class="form-label">Số lượng người dùng tối đa</label>
                            <input type="number" id="user_limit" name="user_limit" class="form-control" placeholder="Không giới hạn" min="0">
                            <div class="field-help">Số lượng người dùng tối đa có thể sử dụng mã này. Để trống nếu không giới hạn</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="usage_limit_user" class="form-label">Giới hạn sử dụng mỗi người dùng</label>
                            <input type="number" id="usage_limit_user" name="usage_limit_user" class="form-control" placeholder="1" min="1" value="1">
                            <div class="field-help">Số lần tối đa mỗi người dùng có thể sử dụng mã này</div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Hủy</button>
                <button type="button" class="btn btn-primary" onclick="saveCoupon()">Lưu</button>
            </div>
        </div>
    </div>
    
    <!-- Coupon Details Modal -->
    <div id="couponDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Chi tiết mã giảm giá</h3>
                <button type="button" class="modal-close" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div class="modal-body" id="couponDetailsContent">
                <!-- Content will be loaded dynamically -->
                <div style="text-align: center; padding: var(--space-6);">
                    <p>Đang tải...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDetailsModal()">Đóng</button>
                <button type="button" class="btn btn-primary" id="editCouponBtn">Sửa</button>
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
        
        // Coupon modal
        const couponModal = document.getElementById('couponModal');
        const couponDetailsModal = document.getElementById('couponDetailsModal');
        const couponDetailsContent = document.getElementById('couponDetailsContent');
        const modalTitle = document.getElementById('modalTitle');
        const couponForm = document.getElementById('couponForm');
        const editCouponBtn = document.getElementById('editCouponBtn');
        
        // Current coupon ID for actions
        let currentCouponId = null;
        
        // Open modal for new coupon
        function openCouponModal() {
            modalTitle.textContent = 'Tạo mã giảm giá mới';
            
            // Reset form
            couponForm.reset();
            document.getElementById('coupon_id').value = '';
            
            // Hide all type fields
            document.querySelectorAll('.coupon-type-fields').forEach(field => {
                field.style.display = 'none';
            });
            
            // Set default dates
            const today = new Date();
            const nextMonth = new Date();
            nextMonth.setMonth(today.getMonth() + 1);
            
            document.getElementById('start_date').value = formatDateForInput(today);
            document.getElementById('end_date').value = formatDateForInput(nextMonth);
            
            couponModal.style.display = 'block';
        }
        
        function closeModal() {
            couponModal.style.display = 'none';
        }
        
        function closeDetailsModal() {
            couponDetailsModal.style.display = 'none';
        }
        
        // Show fields based on coupon type
        function showCouponTypeFields() {
            const couponType = document.getElementById('coupon_type').value;
            
            // Hide all type fields
            document.querySelectorAll('.coupon-type-fields').forEach(field => {
                field.style.display = 'none';
            });
            
            // Show the selected type fields
            if (couponType) {
                document.getElementById(couponType + '_fields').style.display = 'block';
            }
        }
        
        // Save coupon
        async function saveCoupon() {
            const formData = new FormData(couponForm);
            
            // Get selected product IDs
            if (formData.get('type') === 'product_base') {
                const productSelect = document.getElementById('product_ids');
                const selectedProducts = Array.from(productSelect.selectedOptions).map(option => option.value);
                formData.set('product_ids', selectedProducts.join(','));
            }
            
            // Get selected category IDs
            if (formData.get('type') === 'category_base') {
                const categorySelect = document.getElementById('category_ids');
                const selectedCategories = Array.from(categorySelect.selectedOptions).map(option => option.value);
                formData.set('category_ids', selectedCategories.join(','));
            }
            
            // Add action and token
            const couponId = formData.get('coupon_id');
            formData.append('action', couponId ? 'update_coupon' : 'create_coupon');
            formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                    closeModal();
                    
                    // Reload page after a short delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
            }
        }
        
        // View coupon details
        async function viewCoupon(couponId) {
            currentCouponId = couponId;
            
            try {
                const formData = new FormData();
                formData.append('action', 'get_coupon');
                formData.append('coupon_id', couponId);
                formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const coupon = result.coupon;
                    
                    // Format date
                    const startDate = new Date(coupon.start_date * 1000);
                    const endDate = new Date(coupon.end_date * 1000);
                    
                    // Format coupon type
                    let couponType = '';
                    switch (coupon.type) {
                        case 'cart_base':
                            couponType = 'Giảm giá giỏ hàng';
                            break;
                        case 'product_base':
                            couponType = 'Giảm giá sản phẩm';
                            break;
                        case 'category_base':
                            couponType = 'Giảm giá danh mục';
                            break;
                        case 'user_base':
                            couponType = 'Giảm giá cho người dùng';
                            break;
                    }
                    
                    // Format discount
                    let discountText = '';
                    if (coupon.discount_type === 'amount') {
                        discountText = formatCurrency(coupon.discount);
                    } else {
                        discountText = coupon.discount + '%';
                    }
                    
                    // Build details HTML
                    let detailsHtml = `
                        <div style="margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <h3 style="font-family: monospace; font-size: var(--text-2xl); color: var(--primary);">${coupon.code}</h3>
                                <div>
                                    <span class="status-badge ${coupon.status === 1 ? 'active' : 'inactive'}">${coupon.status === 1 ? 'Kích hoạt' : 'Vô hiệu'}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-4); margin-bottom: var(--space-4);">
                            <div>
                                <div style="font-weight: var(--font-semibold); margin-bottom: 5px;">Loại mã giảm giá:</div>
                                <div>${couponType}</div>
                            </div>
                            <div>
                                <div style="font-weight: var(--font-semibold); margin-bottom: 5px;">Giá trị giảm giá:</div>
                                <div>${discountText}</div>
                            </div>
                            <div>
                                <div style="font-weight: var(--font-semibold); margin-bottom: 5px;">Ngày bắt đầu:</div>
                                <div>${formatDate(startDate)}</div>
                            </div>
                            <div>
                                <div style="font-weight: var(--font-semibold); margin-bottom: 5px;">Ngày kết thúc:</div>
                                <div>${formatDate(endDate)}</div>
                            </div>
                            <div>
                                <div style="font-weight: var(--font-semibold); margin-bottom: 5px;">Đã sử dụng:</div>
                                <div>${coupon.usage_count} lần</div>
                            </div>
                            <div>
                                <div style="font-weight: var(--font-semibold); margin-bottom: 5px;">Ngày tạo:</div>
                                <div>${formatDate(new Date(coupon.created_at))}</div>
                            </div>
                        </div>
                    `;
                    
                    // Add type-specific details
                    if (coupon.type === 'cart_base') {
                        const minBuy = coupon.details.min_buy || 0;
                        const maxDiscount = coupon.details.max_discount || 0;
                        
                        detailsHtml += `
                            <div style="margin-bottom: var(--space-4); padding: var(--space-4); background: var(--gray-50); border-radius: var(--rounded-lg);">
                                <div style="font-weight: var(--font-semibold); margin-bottom: 5px;">Điều kiện áp dụng:</div>
                                <div style="margin-bottom: 5px;">Đơn hàng tối thiểu: ${formatCurrency(minBuy)}</div>
                                ${maxDiscount > 0 ? `<div>Giảm tối đa: ${formatCurrency(maxDiscount)}</div>` : ''}
                            </div>
                        `;
                    } else if (coupon.type === 'product_base' && coupon.details.product_ids) {
                        detailsHtml += `
                            <div style="margin-bottom: var(--space-4); padding: var(--space-4); background: var(--gray-50); border-radius: var(--rounded-lg);">
                                <div style="font-weight: var(--font-semibold); margin-bottom: 5px;">Sản phẩm áp dụng:</div>
                                <div>${coupon.details.product_ids.length} sản phẩm</div>
                            </div>
                        `;
                    } else if (coupon.type === 'category_base' && coupon.details.category_ids) {
                        detailsHtml += `
                            <div style="margin-bottom: var(--space-4); padding: var(--space-4); background: var(--gray-50); border-radius: var(--rounded-lg);">
                                <div style="font-weight: var(--font-semibold); margin-bottom: 5px;">Danh mục áp dụng:</div>
                                <div>${coupon.details.category_ids.length} danh mục</div>
                            </div>
                        `;
                    } else if (coupon.type === 'user_base') {
                        const userLimit = coupon.details.user_limit || 'Không giới hạn';
                        const usageLimitUser = coupon.details.usage_limit_user || 1;
                        
                        detailsHtml += `
                            <div style="margin-bottom: var(--space-4); padding: var(--space-4); background: var(--gray-50); border-radius: var(--rounded-lg);">
                                <div style="font-weight: var(--font-semibold); margin-bottom: 5px;">Điều kiện áp dụng:</div>
                                <div style="margin-bottom: 5px;">Số lượng người dùng tối đa: ${userLimit}</div>
                                <div>Giới hạn sử dụng mỗi người dùng: ${usageLimitUser} lần</div>
                            </div>
                        `;
                    }
                    
                    // Toggle status button
                    detailsHtml += `
                        <div style="margin-top: var(--space-4);">
                            <div style="font-weight: var(--font-semibold); margin-bottom: 5px;">Trạng thái:</div>
                            <label class="switch">
                                <input type="checkbox" ${coupon.status === 1 ? 'checked' : ''} onchange="toggleCouponStatus(${coupon.id}, this.checked ? 1 : 0)">
                                <span class="slider"></span>
                            </label>
                            <span style="margin-left: 10px; font-size: var(--text-sm);">${coupon.status === 1 ? 'Đang kích hoạt' : 'Đã vô hiệu hóa'}</span>
                        </div>
                    `;
                    
                    couponDetailsContent.innerHTML = detailsHtml;
                    
                    // Set up edit button
                    editCouponBtn.onclick = function() {
                        closeDetailsModal();
                        editCoupon(couponId);
                    };
                    
                    couponDetailsModal.style.display = 'block';
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
            }
        }
        
        // Edit coupon
        async function editCoupon(couponId) {
            try {
                const formData = new FormData();
                formData.append('action', 'get_coupon');
                formData.append('coupon_id', couponId);
                formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const coupon = result.coupon;
                    
                    // Set modal title
                    modalTitle.textContent = 'Sửa mã giảm giá';
                    
                    // Set form values
                    document.getElementById('coupon_id').value = coupon.id;
                    document.getElementById('code').value = coupon.code;
                    document.getElementById('coupon_type').value = coupon.type;
                    document.getElementById('discount').value = coupon.discount;
                    document.getElementById('discount_type').value = coupon.discount_type;
                    document.getElementById('start_date').value = formatDateForInput(new Date(coupon.start_date * 1000));
                    document.getElementById('end_date').value = formatDateForInput(new Date(coupon.end_date * 1000));
                    document.getElementById('status').checked = coupon.status === 1;
                    
                    // Show type fields
                    showCouponTypeFields();
                    
                    // Set type-specific values
                    if (coupon.type === 'cart_base') {
                        document.getElementById('min_buy').value = coupon.details.min_buy || '';
                        document.getElementById('max_discount').value = coupon.details.max_discount || '';
                    } else if (coupon.type === 'product_base' && coupon.details.product_ids) {
                        const productSelect = document.getElementById('product_ids');
                        
                        // Clear previous selections
                        Array.from(productSelect.options).forEach(option => {
                            option.selected = false;
                        });
                        
                        // Select products
                        coupon.details.product_ids.forEach(productId => {
                            const option = productSelect.querySelector(`option[value="${productId}"]`);
                            if (option) {
                                option.selected = true;
                            }
                        });
                    } else if (coupon.type === 'category_base' && coupon.details.category_ids) {
                        const categorySelect = document.getElementById('category_ids');
                        
                        // Clear previous selections
                        Array.from(categorySelect.options).forEach(option => {
                            option.selected = false;
                        });
                        
                        // Select categories
                        coupon.details.category_ids.forEach(categoryId => {
                            const option = categorySelect.querySelector(`option[value="${categoryId}"]`);
                            if (option) {
                                option.selected = true;
                            }
                        });
                    } else if (coupon.type === 'user_base') {
                        document.getElementById('user_limit').value = coupon.details.user_limit || '';
                        document.getElementById('usage_limit_user').value = coupon.details.usage_limit_user || 1;
                    }
                    
                    // Show modal
                    couponModal.style.display = 'block';
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
            }
        }
        
        // Delete coupon
        async function deleteCoupon(couponId) {
            if (!confirm('Bạn có chắc chắn muốn xóa mã giảm giá này? Hành động này không thể hoàn tác.')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'delete_coupon');
                formData.append('coupon_id', couponId);
                formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                    
                    // Reload page after a short delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
            }
        }
        
        // Toggle coupon status
        async function toggleCouponStatus(couponId, status) {
            try {
                const formData = new FormData();
                formData.append('action', 'toggle_status');
                formData.append('coupon_id', couponId);
                formData.append('status', status);
                formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
            }
        }
        
        // Helper function to format date for display
        function formatDate(date) {
            return date.toLocaleDateString('vi-VN', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });
        }
        
        // Helper function to format date for input
        function formatDateForInput(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            
            return `${year}-${month}-${day}`;
        }
        
        // Helper function to format currency
        function formatCurrency(amount) {
            return new Intl.NumberFormat('vi-VN', {
                style: 'currency',
                currency: 'VND',
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }).format(amount);
        }
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
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
        
        // Form submission for filters
        document.getElementById('per_page').addEventListener('change', function() {
            this.form.submit();
        });
        
        document.getElementById('sort').addEventListener('change', function() {
            this.form.submit();
        });
        
        // Close modals when clicking outside
        window.addEventListener('click', function(e) {
            if (e.target === couponModal) {
                closeModal();
            }
            
            if (e.target === couponDetailsModal) {
                closeDetailsModal();
            }
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Coupons Management - Initializing...');
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Coupons Management - Ready!');
        });
    </script>
</body>
</html>