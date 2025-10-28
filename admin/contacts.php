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
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    if (!isset($_POST['contact_id']) || !is_numeric($_POST['contact_id'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid contact ID']);
        exit;
    }
    
    $contact_id = intval($_POST['contact_id']);
    
    try {
        switch ($_POST['action']) {
            case 'mark_read':
                $stmt = $db->prepare("UPDATE contacts SET viewed = 1 WHERE id = ?");
                $stmt->execute([$contact_id]);
                echo json_encode(['success' => true, 'message' => 'Đã đánh dấu là đã đọc']);
                break;
                
            case 'mark_unread':
                $stmt = $db->prepare("UPDATE contacts SET viewed = 0 WHERE id = ?");
                $stmt->execute([$contact_id]);
                echo json_encode(['success' => true, 'message' => 'Đã đánh dấu là chưa đọc']);
                break;
                
            case 'delete_contact':
                $stmt = $db->prepare("DELETE FROM contacts WHERE id = ?");
                $stmt->execute([$contact_id]);
                echo json_encode(['success' => true, 'message' => 'Liên hệ đã được xóa']);
                break;
                
            case 'reply_contact':
                if (!isset($_POST['reply']) || empty($_POST['reply'])) {
                    echo json_encode(['success' => false, 'message' => 'Nội dung phản hồi không được để trống']);
                    break;
                }
                
                $reply = trim($_POST['reply']);
                
                // Update the contact with the reply
                $stmt = $db->prepare("UPDATE contacts SET reply = ?, viewed = 1, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$reply, $contact_id]);
                
                // Get contact details for email
                $stmt = $db->prepare("SELECT name, email, content FROM contacts WHERE id = ?");
                $stmt->execute([$contact_id]);
                $contact = $stmt->fetch();
                
                if ($contact) {
                    // Send email to the customer (implement your email sending function)
                    $site_name = getBusinessSetting($db, 'site_name', 'Your Store');
                    $site_email = getBusinessSetting($db, 'contact_email', 'noreply@example.com');
                    
                    // Email sending logic would go here
                    // sendEmail($contact['email'], "Re: Your inquiry at $site_name", $reply, $site_email);
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Phản hồi đã được gửi',
                        'reply' => $reply,
                        'date' => date('d/m/Y H:i')
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Không tìm thấy liên hệ']);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("Contact action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Build the query based on filters
$params = [];
$query = "SELECT * FROM contacts WHERE 1=1";

if (!empty($search)) {
    $query .= " AND (name LIKE ? OR email LIKE ? OR content LIKE ? OR phone LIKE ?)";
    $search_param = "%$search%";
    array_push($params, $search_param, $search_param, $search_param, $search_param);
}

if ($status === 'read') {
    $query .= " AND viewed = 1";
} elseif ($status === 'unread') {
    $query .= " AND viewed = 0";
} elseif ($status === 'replied') {
    $query .= " AND reply IS NOT NULL AND reply != ''";
} elseif ($status === 'not_replied') {
    $query .= " AND (reply IS NULL OR reply = '')";
}

if (!empty($date_from)) {
    $query .= " AND DATE(created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(created_at) <= ?";
    $params[] = $date_to;
}

// Count total records for pagination
$count_query = str_replace("SELECT *", "SELECT COUNT(*) as total", $query);
$stmt = $db->prepare($count_query);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'] ?? 0;
$total_pages = ceil($total_records / $per_page);

// Sorting
if ($sort === 'oldest') {
    $query .= " ORDER BY created_at ASC";
} elseif ($sort === 'a-z') {
    $query .= " ORDER BY name ASC";
} elseif ($sort === 'z-a') {
    $query .= " ORDER BY name DESC";
} else { // default: newest
    $query .= " ORDER BY created_at DESC";
}

// Add pagination
$query .= " LIMIT $per_page OFFSET $offset";

// Fetch contacts
try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $contacts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Contact fetch error: " . $e->getMessage());
    $contacts = [];
}

$site_name = getBusinessSetting($db, 'site_name', 'Your Store');

// Helper function to format dates
function formatDate($date) {
    // Handle null/empty dates to prevent PHP 8.1+ deprecation warnings
    if (empty($date)) return 'N/A';
    return date('d/m/Y H:i', strtotime($date));
}

// Helper function to truncate text
function truncateText($text, $length = 100) {
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    
    return mb_substr($text, 0, $length) . '...';
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý liên hệ - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý liên hệ - Admin <?php echo htmlspecialchars($site_name); ?>">
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
        
        .status-badge.read {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .status-badge.unread {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
        }
        
        .status-badge.replied {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
        }
        
        .status-badge.pending {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
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
        
        /* Contact list */
        .contact-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: var(--space-4);
        }
        
        .contact-card {
            background: var(--surface);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            overflow: hidden;
            transition: var(--transition-normal);
        }
        
        .contact-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .contact-card.unread {
            border-left: 3px solid var(--primary);
        }
        
        .contact-card.replied {
            border-left: 3px solid var(--success);
        }
        
        .contact-header {
            padding: var(--space-4);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .contact-title {
            font-weight: var(--font-semibold);
            color: var(--text-primary);
            font-size: var(--text-base);
        }
        
        .contact-meta {
            color: var(--text-tertiary);
            font-size: var(--text-xs);
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }
        
        .contact-body {
            padding: var(--space-4);
        }
        
        .contact-info {
            display: grid;
            grid-template-columns: 80px 1fr;
            gap: var(--space-2);
            margin-bottom: var(--space-3);
            font-size: var(--text-sm);
        }
        
        .contact-label {
            color: var(--text-secondary);
            font-weight: var(--font-medium);
        }
        
        .contact-value {
            color: var(--text-primary);
        }
        
        .contact-message {
            background: var(--gray-50);
            padding: var(--space-3);
            border-radius: var(--rounded-md);
            font-size: var(--text-sm);
            margin-bottom: var(--space-3);
            white-space: pre-line;
        }
        
        .contact-reply {
            background: #f0f7ff;
            padding: var(--space-3);
            border-radius: var(--rounded-md);
            font-size: var(--text-sm);
            margin-top: var(--space-3);
            border-left: 2px solid var(--primary);
            white-space: pre-line;
        }
        
        .contact-footer {
            display: flex;
            justify-content: flex-end;
            padding: var(--space-3);
            border-top: 1px solid var(--border-light);
            gap: var(--space-2);
        }
        
        /* Modal for contact details */
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
            margin: 10% auto;
            padding: var(--space-6);
            border-radius: var(--rounded-xl);
            box-shadow: var(--shadow-xl);
            width: 600px;
            max-width: 90%;
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
        }
        
        .reply-form {
            margin-top: var(--space-4);
        }
        
        .reply-textarea {
            width: 100%;
            min-height: 120px;
            padding: var(--space-3);
            border: 1px solid var(--border);
            border-radius: var(--rounded-md);
            font-size: var(--text-sm);
            font-family: var(--font-sans);
            resize: vertical;
            margin-bottom: var(--space-3);
        }
        
        .reply-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
            
            .contact-list {
                grid-template-columns: 1fr;
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
                        <a href="contacts.php" class="nav-link active">
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
                            <span>Liên hệ</span>
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
                        <h1 class="page-title">Quản lý liên hệ</h1>
                        <p class="page-subtitle">Quản lý và phản hồi các tin nhắn liên hệ từ khách hàng</p>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="filter-section">
                    <form action="" method="GET" class="filter-form">
                        <div class="form-group">
                            <label for="search" class="form-label">Tìm kiếm</label>
                            <input type="text" id="search" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Tìm theo tên, email, nội dung...">
                        </div>
                        
                        <div class="form-group">
                            <label for="status" class="form-label">Trạng thái</label>
                            <select id="status" name="status" class="form-control">
                                <option value="" <?php echo $status === '' ? 'selected' : ''; ?>>Tất cả trạng thái</option>
                                <option value="read" <?php echo $status === 'read' ? 'selected' : ''; ?>>Đã đọc</option>
                                <option value="unread" <?php echo $status === 'unread' ? 'selected' : ''; ?>>Chưa đọc</option>
                                <option value="replied" <?php echo $status === 'replied' ? 'selected' : ''; ?>>Đã phản hồi</option>
                                <option value="not_replied" <?php echo $status === 'not_replied' ? 'selected' : ''; ?>>Chưa phản hồi</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="date_from" class="form-label">Từ ngày</label>
                            <input type="date" id="date_from" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="date_to" class="form-label">Đến ngày</label>
                            <input type="date" id="date_to" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="sort" class="form-label">Sắp xếp</label>
                            <select id="sort" name="sort" class="form-control">
                                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Mới nhất</option>
                                <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Cũ nhất</option>
                                <option value="a-z" <?php echo $sort === 'a-z' ? 'selected' : ''; ?>>A-Z</option>
                                <option value="z-a" <?php echo $sort === 'z-a' ? 'selected' : ''; ?>>Z-A</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="per_page" class="form-label">Hiển thị</label>
                            <select id="per_page" name="per_page" class="form-control">
                                <option value="20" <?php echo $per_page === 20 ? 'selected' : ''; ?>>20 liên hệ</option>
                                <option value="50" <?php echo $per_page === 50 ? 'selected' : ''; ?>>50 liên hệ</option>
                                <option value="100" <?php echo $per_page === 100 ? 'selected' : ''; ?>>100 liên hệ</option>
                            </select>
                        </div>
                        
                        <div class="form-group" style="display: flex; align-items: flex-end;">
                            <button type="submit" class="btn btn-primary">
                                <span>🔍</span>
                                <span>Tìm kiếm</span>
                            </button>
                            
                            <a href="contacts.php" class="btn btn-secondary" style="margin-left: 10px;">
                                <span>🔄</span>
                                <span>Đặt lại</span>
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Contacts List -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Danh sách liên hệ (<?php echo $total_records; ?>)</h2>
                    </div>
                    <div class="card-body">
                        <?php if (empty($contacts)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">📭</div>
                                <h3 class="empty-state-text">Không có liên hệ nào</h3>
                                <p class="empty-state-subtext">Chưa có tin nhắn liên hệ nào từ khách hàng hoặc tất cả các tin nhắn đã được xử lý.</p>
                            </div>
                        <?php else: ?>
                            <div class="contact-list">
                                <?php foreach ($contacts as $contact): ?>
                                    <div class="contact-card <?php echo ($contact['viewed'] == 0) ? 'unread' : ((!empty($contact['reply'])) ? 'replied' : ''); ?>" onclick="viewContactDetails(<?php echo $contact['id']; ?>)">
                                        <div class="contact-header">
                                            <div class="contact-title"><?php echo htmlspecialchars($contact['name']); ?></div>
                                            <div class="contact-meta">
                                                <?php if ($contact['viewed'] == 0): ?>
                                                    <span class="status-badge unread">Chưa đọc</span>
                                                <?php elseif (!empty($contact['reply'])): ?>
                                                    <span class="status-badge replied">Đã phản hồi</span>
                                                <?php else: ?>
                                                    <span class="status-badge read">Đã đọc</span>
                                                <?php endif; ?>
                                                <span><?php echo formatDate($contact['created_at']); ?></span>
                                            </div>
                                        </div>
                                        <div class="contact-body">
                                            <div class="contact-info">
                                                <div class="contact-label">Email:</div>
                                                <div class="contact-value"><?php echo htmlspecialchars($contact['email']); ?></div>
                                                
                                                <?php if (!empty($contact['phone'])): ?>
                                                <div class="contact-label">Điện thoại:</div>
                                                <div class="contact-value"><?php echo htmlspecialchars($contact['phone']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="contact-message">
                                                <?php echo htmlspecialchars(truncateText($contact['content'], 150)); ?>
                                            </div>
                                            <?php if (!empty($contact['reply'])): ?>
                                            <div class="contact-reply">
                                                <strong>Phản hồi:</strong> <?php echo htmlspecialchars(truncateText($contact['reply'], 100)); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="contact-footer">
                                            <button class="btn btn-secondary btn-sm" onclick="event.stopPropagation(); viewContactDetails(<?php echo $contact['id']; ?>)">
                                                <span>👁️</span>
                                                <span>Xem chi tiết</span>
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
                                        echo '<a href="?page=' . ($page - 1) . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&status=' . $status . '&date_from=' . $date_from . '&date_to=' . $date_to . '&sort=' . $sort . '" class="pagination-link">«</a>';
                                    }
                                    
                                    // Page numbers
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if ($start_page > 1) {
                                        echo '<a href="?page=1&per_page=' . $per_page . '&search=' . urlencode($search) . '&status=' . $status . '&date_from=' . $date_from . '&date_to=' . $date_to . '&sort=' . $sort . '" class="pagination-link">1</a>';
                                        if ($start_page > 2) {
                                            echo '<span class="pagination-ellipsis">…</span>';
                                        }
                                    }
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++) {
                                        echo '<a href="?page=' . $i . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&status=' . $status . '&date_from=' . $date_from . '&date_to=' . $date_to . '&sort=' . $sort . '" class="pagination-link' . ($i == $page ? ' active' : '') . '">' . $i . '</a>';
                                    }
                                    
                                    if ($end_page < $total_pages) {
                                        if ($end_page < $total_pages - 1) {
                                            echo '<span class="pagination-ellipsis">…</span>';
                                        }
                                        echo '<a href="?page=' . $total_pages . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&status=' . $status . '&date_from=' . $date_from . '&date_to=' . $date_to . '&sort=' . $sort . '" class="pagination-link">' . $total_pages . '</a>';
                                    }
                                    
                                    // Next page link
                                    if ($page < $total_pages) {
                                        echo '<a href="?page=' . ($page + 1) . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&status=' . $status . '&date_from=' . $date_from . '&date_to=' . $date_to . '&sort=' . $sort . '" class="pagination-link">»</a>';
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
    
    <!-- Contact Details Modal -->
    <div id="contactModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Chi tiết liên hệ</h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalContent">
                <!-- Content will be loaded dynamically -->
                <div style="text-align: center; padding: var(--space-6);">
                    <p>Đang tải...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Đóng</button>
                <div id="contactActions" class="btn-group"></div>
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
        
        // Contact details modal
        const modal = document.getElementById('contactModal');
        const modalContent = document.getElementById('modalContent');
        const contactActions = document.getElementById('contactActions');
        
        // Current contact ID for actions
        let currentContactId = null;
        
        // Open modal and load contact details
        function viewContactDetails(contactId) {
            currentContactId = contactId;
            
            // Find the contact card
            const contactCard = document.querySelector(`.contact-card[onclick*="${contactId}"]`);
            if (!contactCard) return;
            
            // Extract details from the card
            const name = contactCard.querySelector('.contact-title').textContent.trim();
            const statusBadge = contactCard.querySelector('.status-badge').cloneNode(true);
            const createdAt = contactCard.querySelector('.contact-meta span:last-child').textContent.trim();
            const email = contactCard.querySelector('.contact-value').textContent.trim();
            const phone = contactCard.querySelector('.contact-value:nth-of-type(2)')?.textContent.trim() || 'Không có';
            const message = contactCard.querySelector('.contact-message').textContent.trim();
            const reply = contactCard.querySelector('.contact-reply')?.textContent.replace('Phản hồi:', '').trim();
            
            const isUnread = statusBadge.classList.contains('unread');
            const isReplied = statusBadge.classList.contains('replied');
            
            // Build modal content
            let modalHtml = `
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h4 style="margin-bottom: 10px;">${name}</h4>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            ${statusBadge.outerHTML}
                            <span>${createdAt}</span>
                        </div>
                    </div>
                    <div style="background: var(--gray-50); padding: 10px; border-radius: 8px; margin-bottom: 15px;">
                        <div><strong>Email:</strong> ${email}</div>
                        <div><strong>Điện thoại:</strong> ${phone}</div>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h4 style="margin-bottom: 10px;">Nội dung liên hệ</h4>
                    <div style="background: var(--gray-50); padding: 15px; border-radius: 8px; white-space: pre-wrap;">${message}</div>
                </div>
            `;
            
            // Add reply section if exists
            if (isReplied && reply) {
                modalHtml += `
                    <div style="margin-bottom: 20px;">
                        <h4 style="margin-bottom: 10px;">Phản hồi</h4>
                        <div style="background: #f0f7ff; padding: 15px; border-radius: 8px; border-left: 2px solid var(--primary); white-space: pre-wrap;">${reply}</div>
                    </div>
                `;
            }
            
            // Add reply form if not replied yet
            if (!isReplied) {
                modalHtml += `
                    <div class="reply-form">
                        <h4 style="margin-bottom: 10px;">Gửi phản hồi</h4>
                        <textarea id="replyText" class="reply-textarea" placeholder="Nhập nội dung phản hồi..."></textarea>
                        <button type="button" class="btn btn-primary" onclick="sendReply()">
                            <span>📤</span>
                            <span>Gửi phản hồi</span>
                        </button>
                    </div>
                `;
            }
            
            modalContent.innerHTML = modalHtml;
            
            // Set action buttons
            contactActions.innerHTML = '';
            
            if (isUnread) {
                contactActions.innerHTML += `
                    <button class="btn btn-success" onclick="markAsRead()">
                        <span>✓</span>
                        <span>Đánh dấu đã đọc</span>
                    </button>
                `;
            } else {
                contactActions.innerHTML += `
                    <button class="btn btn-warning" onclick="markAsUnread()">
                        <span>✗</span>
                        <span>Đánh dấu chưa đọc</span>
                    </button>
                `;
            }
            
            contactActions.innerHTML += `
                <button class="btn btn-danger" onclick="deleteContact()">
                    <span>🗑️</span>
                    <span>Xóa</span>
                </button>
            `;
            
            // Mark as read if unread
            if (isUnread) {
                markAsRead(false); // Don't reload page
            }
            
            modal.style.display = 'block';
        }
        
        function closeModal() {
            modal.style.display = 'none';
            currentContactId = null;
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });
        
        // Contact actions
        async function markAsRead(reload = true) {
            if (!currentContactId) return;
            
            const success = await makeRequest('mark_read', { contact_id: currentContactId });
            if (success && reload) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function markAsUnread() {
            if (!currentContactId) return;
            
            const success = await makeRequest('mark_unread', { contact_id: currentContactId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function deleteContact() {
            if (!currentContactId) return;
            
            if (!confirm('Bạn có chắc chắn muốn xóa liên hệ này? Hành động này không thể hoàn tác.')) {
                return;
            }
            
            const success = await makeRequest('delete_contact', { contact_id: currentContactId });
            if (success) {
                closeModal();
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function sendReply() {
            if (!currentContactId) return;
            
            const replyText = document.getElementById('replyText').value.trim();
            if (!replyText) {
                showNotification('Vui lòng nhập nội dung phản hồi', 'error');
                return;
            }
            
            const result = await makeRequest('reply_contact', { 
                contact_id: currentContactId,
                reply: replyText
            });
            
            if (result.success) {
                // Update the modal to show the reply
                const replyForm = document.querySelector('.reply-form');
                if (replyForm) {
                    const replySection = document.createElement('div');
                    replySection.style.marginBottom = '20px';
                    replySection.innerHTML = `
                        <h4 style="margin-bottom: 10px;">Phản hồi</h4>
                        <div style="background: #f0f7ff; padding: 15px; border-radius: 8px; border-left: 2px solid var(--primary); white-space: pre-wrap;">${replyText}</div>
                    `;
                    replyForm.parentNode.insertBefore(replySection, replyForm);
                    replyForm.remove();
                }
                
                // Update the contact card on the page
                const contactCard = document.querySelector(`.contact-card[onclick*="${currentContactId}"]`);
                if (contactCard) {
                    contactCard.classList.add('replied');
                    contactCard.classList.remove('unread');
                    
                    const statusBadge = contactCard.querySelector('.status-badge');
                    if (statusBadge) {
                        statusBadge.className = 'status-badge replied';
                        statusBadge.textContent = 'Đã phản hồi';
                    }
                    
                    const contactBody = contactCard.querySelector('.contact-body');
                    if (contactBody) {
                        let replyDiv = contactBody.querySelector('.contact-reply');
                        if (!replyDiv) {
                            replyDiv = document.createElement('div');
                            replyDiv.className = 'contact-reply';
                            contactBody.appendChild(replyDiv);
                        }
                        replyDiv.innerHTML = `<strong>Phản hồi:</strong> ${truncateText(replyText, 100)}`;
                    }
                }
                
                // Update the action buttons
                contactActions.innerHTML = `
                    <button class="btn btn-warning" onclick="markAsUnread()">
                        <span>✗</span>
                        <span>Đánh dấu chưa đọc</span>
                    </button>
                    <button class="btn btn-danger" onclick="deleteContact()">
                        <span>🗑️</span>
                        <span>Xóa</span>
                    </button>
                `;
            }
        }
        
        // Helper function to truncate text
        function truncateText(text, length = 100) {
            if (text.length <= length) return text;
            return text.substring(0, length) + '...';
        }
        
        // AJAX helper function
        async function makeRequest(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('token', '<?php echo $_SESSION['admin_token']; ?>');
            
            Object.keys(data).forEach(key => {
                formData.append(key, data[key]);
            });
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(result.message, 'success');
                    return result;
                } else {
                    showNotification(result.message, 'error');
                    return false;
                }
            } catch (error) {
                showNotification('Có lỗi xảy ra: ' + error.message, 'error');
                return false;
            }
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
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Contacts Management - Initializing...');
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Contacts Management - Ready!');
        });
    </script>
</body>
</html>