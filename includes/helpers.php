<?php
/**
 * Common Helper Functions
 * Reusable utility functions used across the application
 *
 * This file consolidates helper functions that were previously duplicated
 * across multiple files in the codebase.
 *
 * @author TK-MALL Development Team
 * @version 2.0.0
 */

/**
 * Safely echo a value with XSS protection
 * Prevents XSS attacks and handles null values gracefully
 *
 * @param mixed $string The value to output
 * @return string Escaped HTML-safe string
 */
function safe_echo($string)
{
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Get value from array with default fallback
 * Prevents "Undefined index" notices
 *
 * @param array $array The array to get value from
 * @param string|int $key The key to look for
 * @param mixed $default Default value if key doesn't exist (default: '')
 * @return mixed The value or default
 */
function get_value($array, $key, $default = '')
{
    return isset($array[$key]) ? $array[$key] : $default;
}

/**
 * Check if array has a value at given key (not null)
 *
 * @param array $array The array to check
 * @param string|int $key The key to check
 * @return bool True if key exists and is not null
 */
function has_value($array, $key)
{
    return isset($array[$key]) && $array[$key] !== null;
}

/**
 * Format price in Vietnamese dong
 *
 * @param float|int $price The price to format
 * @return string Formatted price with "đ" suffix
 */
function formatPrice($price)
{
    if ($price === null || $price === '') {
        return '0đ';
    }
    return number_format((float)$price, 0, ',', '.') . 'đ';
}

/**
 * Format date to Vietnamese format (dd/mm/yyyy)
 *
 * @param string|null $date Date string to format
 * @return string Formatted date or empty string
 */
function formatDate($date)
{
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }
    return date('d/m/Y', strtotime($date));
}

/**
 * Format datetime to Vietnamese format (dd/mm/yyyy HH:ii)
 *
 * @param string|null $datetime Datetime string to format
 * @return string Formatted datetime or empty string
 */
function formatDateTime($datetime)
{
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '';
    }
    return date('d/m/Y H:i', strtotime($datetime));
}

/**
 * Generate URL-friendly slug from string
 *
 * @param string $string The string to convert
 * @return string URL-friendly slug
 */
function generateSlug($string)
{
    $string = trim($string);
    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

/**
 * Truncate text to specified length
 *
 * @param string $text Text to truncate
 * @param int $limit Maximum length (default: 100)
 * @param string $suffix Suffix to add if truncated (default: '...')
 * @return string Truncated text
 */
function truncateText($text, $limit = 100, $suffix = '...')
{
    if (mb_strlen($text) > $limit) {
        return mb_substr($text, 0, $limit) . $suffix;
    }
    return $text;
}

/**
 * Sanitize string input (trim, strip tags, escape HTML)
 *
 * @param string $input The input to sanitize
 * @return string Sanitized input
 */
function sanitizeString($input)
{
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize integer input
 *
 * @param mixed $input The input to sanitize
 * @param int $default Default value if invalid (default: 0)
 * @return int Sanitized integer
 */
function sanitizeInt($input, $default = 0)
{
    return filter_var($input, FILTER_VALIDATE_INT) !== false
        ? (int)$input
        : $default;
}

/**
 * Sanitize float input
 *
 * @param mixed $input The input to sanitize
 * @param float $default Default value if invalid (default: 0.0)
 * @return float Sanitized float
 */
function sanitizeFloat($input, $default = 0.0)
{
    return filter_var($input, FILTER_VALIDATE_FLOAT) !== false
        ? (float)$input
        : $default;
}

/**
 * Validate email address
 *
 * @param string $email Email to validate
 * @return bool True if valid, false otherwise
 */
function isValidEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Vietnamese format)
 *
 * @param string $phone Phone number to validate
 * @return bool True if valid, false otherwise
 */
function isValidPhone($phone)
{
    // Vietnamese phone format: 10 digits starting with 0
    return preg_match('/^0[0-9]{9}$/', $phone) === 1;
}

/**
 * Generate random string
 *
 * @param int $length Length of string (default: 16)
 * @return string Random string
 */
function generateRandomString($length = 16)
{
    return bin2hex(random_bytes($length / 2));
}

/**
 * Get category icon based on category name
 *
 * @param string $category_name Category name
 * @return string Icon emoji
 */
function getCategoryIcon($category_name)
{
    $category_icons = [
        'thời trang nữ' => '👗',
        'thời trang nam' => '👔',
        'điện thoại' => '📱',
        'máy tính' => '💻',
        'laptop' => '💻',
        'gia dụng' => '🏠',
        'sức khỏe' => '💄',
        'làm đẹp' => '💄',
        'thể thao' => '⚽',
        'sách' => '📚',
        'đồ chơi' => '🧸',
        'ô tô' => '🚗',
        'xe máy' => '🏍️',
        'mẹ và bé' => '👶',
        'thú cưng' => '🐕',
        'nhà cửa' => '🏡',
        'văn phòng' => '✏️',
        'thực phẩm' => '🍎',
        'đồng hồ' => '⌚',
        'giày dép' => '👟',
        'túi ví' => '👜',
        'điện tử' => '🔌',
        'camera' => '📷',
        'phụ kiện' => '📦',
        'bánh kẹo' => '🍰',
        'phòng dịch' => '😷',
        'nội thất' => '🛋️',
        'làm vườn' => '🌱',
        'pet' => '🐾',
        'mỹ phẩm' => '💋'
    ];

    $category_name_lower = strtolower($category_name);

    foreach ($category_icons as $keyword => $icon) {
        if (strpos($category_name_lower, $keyword) !== false) {
            return $icon;
        }
    }

    return '📦'; // Default icon
}

/**
 * Calculate discount percentage
 *
 * @param float $original_price Original price
 * @param float $sale_price Sale price
 * @return int Discount percentage (rounded)
 */
function calculateDiscount($original_price, $sale_price)
{
    if ($original_price <= 0) {
        return 0;
    }

    return (int)round((($original_price - $sale_price) / $original_price) * 100);
}

/**
 * Get order status badge HTML
 *
 * @param string $status Order status
 * @return string HTML badge
 */
function getOrderStatusBadge($status)
{
    $badges = [
        'pending' => '<span class="badge badge-warning">Chờ xử lý</span>',
        'processing' => '<span class="badge badge-info">Đang xử lý</span>',
        'shipping' => '<span class="badge badge-primary">Đang giao</span>',
        'completed' => '<span class="badge badge-success">Hoàn thành</span>',
        'cancelled' => '<span class="badge badge-danger">Đã hủy</span>',
        'refunded' => '<span class="badge badge-secondary">Đã hoàn tiền</span>'
    ];

    return $badges[$status] ?? '<span class="badge badge-secondary">Không xác định</span>';
}

/**
 * Get payment status badge HTML
 *
 * @param string $status Payment status
 * @return string HTML badge
 */
function getPaymentStatusBadge($status)
{
    $badges = [
        'pending' => '<span class="badge badge-warning">Chờ thanh toán</span>',
        'paid' => '<span class="badge badge-success">Đã thanh toán</span>',
        'failed' => '<span class="badge badge-danger">Thất bại</span>',
        'refunded' => '<span class="badge badge-info">Đã hoàn tiền</span>'
    ];

    return $badges[$status] ?? '<span class="badge badge-secondary">Không xác định</span>';
}

/**
 * Format file size in human readable format
 *
 * @param int $bytes File size in bytes
 * @return string Formatted file size
 */
function formatFileSize($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Check if string starts with specific substring
 *
 * @param string $haystack The string to search in
 * @param string $needle The substring to search for
 * @return bool True if starts with, false otherwise
 */
function startsWith($haystack, $needle)
{
    return strpos($haystack, $needle) === 0;
}

/**
 * Check if string ends with specific substring
 *
 * @param string $haystack The string to search in
 * @param string $needle The substring to search for
 * @return bool True if ends with, false otherwise
 */
function endsWith($haystack, $needle)
{
    return substr($haystack, -strlen($needle)) === $needle;
}

/**
 * Get time ago in Vietnamese
 *
 * @param string $datetime Datetime string
 * @return string Time ago text
 */
function timeAgo($datetime)
{
    if (empty($datetime)) {
        return '';
    }

    $timestamp = strtotime($datetime);
    $difference = time() - $timestamp;

    if ($difference < 60) {
        return 'Vừa xong';
    } elseif ($difference < 3600) {
        $mins = floor($difference / 60);
        return $mins . ' phút trước';
    } elseif ($difference < 86400) {
        $hours = floor($difference / 3600);
        return $hours . ' giờ trước';
    } elseif ($difference < 604800) {
        $days = floor($difference / 86400);
        return $days . ' ngày trước';
    } else {
        return formatDate($datetime);
    }
}

/**
 * Redirect to URL and exit
 *
 * @param string $url URL to redirect to
 * @param int $statusCode HTTP status code (default: 302)
 */
function redirect($url, $statusCode = 302)
{
    header('Location: ' . $url, true, $statusCode);
    exit;
}

/**
 * Check if request is AJAX request
 *
 * @return bool True if AJAX, false otherwise
 */
function isAjaxRequest()
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Check if request method is POST
 *
 * @return bool True if POST, false otherwise
 */
function isPostRequest()
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Check if request method is GET
 *
 * @return bool True if GET, false otherwise
 */
function isGetRequest()
{
    return $_SERVER['REQUEST_METHOD'] === 'GET';
}

/**
 * Get current URL
 *
 * @return string Current URL
 */
function getCurrentUrl()
{
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    return $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

/**
 * Get base URL
 *
 * @return string Base URL
 */
function getBaseUrl()
{
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    return $protocol . '://' . $_SERVER['HTTP_HOST'];
}
?>
