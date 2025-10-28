<?php
// Site Configuration Constants
define('SITE_NAME', 'TK-MALL');
define('SITE_TAGLINE', 'Nền tảng thương mại điện tử hàng đầu Việt Nam');
define('SITE_DESCRIPTION', 'TK-MALL - Mua sắm online với hàng triệu sản phẩm chính hãng, giá tốt nhất. Miễn phí vận chuyển, thanh toán an toàn, đổi trả dễ dàng.');
define('SITE_KEYWORDS', 'mua sắm online, thương mại điện tử, sản phẩm chính hãng, giá rẻ');
define('SITE_URL', 'https://tkmall.vn');

// Contact Information
define('COMPANY_NAME', 'Công ty TNHH TK-MALL Việt Nam');
define('COMPANY_ADDRESS', 'Tầng 4-5-6, Tòa nhà Capital Place, số 29 đường Liễu Giai, Phường Ngọc Khánh, Quận Ba Đình, Thành phố Hà Nội, Việt Nam');
define('COMPANY_PHONE', '19001221');
define('COMPANY_EMAIL', 'cskh@tkmall.vn');
define('BUSINESS_LICENSE', '0123456789 do Sở Kế hoạch và Đầu tư Thành phố Hà Nội cấp ngày 01/01/2020');

// Social Media Links
define('FACEBOOK_URL', 'https://facebook.com/tkmall');
define('INSTAGRAM_URL', 'https://instagram.com/tkmall');
define('YOUTUBE_URL', 'https://youtube.com/tkmall');
define('TIKTOK_URL', 'https://tiktok.com/@tkmall');
define('ZALO_URL', 'https://zalo.me/tkmall');

// App Download Links
define('APP_STORE_URL', 'https://apps.apple.com/app/tkmall');
define('GOOGLE_PLAY_URL', 'https://play.google.com/store/apps/details?id=com.tkmall');

// Navigation Menu Items
$main_menu = [
    'index' => ['title' => 'Trang chủ', 'url' => 'index.php'],
    'categories' => ['title' => 'Danh mục', 'url' => 'categories.php'],
    'products' => ['title' => 'Sản phẩm', 'url' => 'products.php'],
    'deals' => ['title' => 'Khuyến mãi', 'url' => 'deals.php'],
    'sellers' => ['title' => 'Đối tác', 'url' => 'sellers.php'],
    'support' => ['title' => 'Hỗ trợ', 'url' => 'support.php']
];

// Footer Menu Items
$footer_menus = [
    'about' => [
        'title' => 'Về TK-MALL',
        'items' => [
            ['title' => 'Giới thiệu về TK-MALL', 'url' => 'about.php'],
            ['title' => 'Tuyển dụng', 'url' => 'careers.php'],
            ['title' => 'Điều khoản sử dụng', 'url' => 'terms.php'],
            ['title' => 'Chính sách bảo mật', 'url' => 'privacy.php'],
            ['title' => 'Cam kết chính hãng', 'url' => 'authentic.php'],
            ['title' => 'Flash Sales', 'url' => 'flash-sales.php'],
            ['title' => 'Chương trình Affiliate', 'url' => 'affiliate.php']
        ]
    ],
    'buyers' => [
        'title' => 'Dành cho người mua',
        'items' => [
            ['title' => 'Trung tâm trợ giúp', 'url' => 'help-center.php'],
            ['title' => 'Hướng dẫn mua hàng', 'url' => 'how-to-buy.php'],
            ['title' => 'Phương thức thanh toán', 'url' => 'payment-methods.php'],
            ['title' => 'Vận chuyển & Giao hàng', 'url' => 'shipping.php'],
            ['title' => 'Trả hàng & Hoàn tiền', 'url' => 'returns.php'],
            ['title' => 'Chăm sóc khách hàng', 'url' => 'customer-care.php'],
            ['title' => 'Chính sách bảo hành', 'url' => 'warranty.php']
        ]
    ],
    'sellers' => [
        'title' => 'Dành cho người bán',
        'items' => [
            ['title' => 'Kênh người bán', 'url' => 'seller-center.php'],
            ['title' => 'Trở thành Người bán TK-MALL', 'url' => 'become-seller.php'],
            ['title' => 'Học viện Seller', 'url' => 'seller-university.php'],
            ['title' => 'Trung tâm TK-MALL Ads', 'url' => 'ads-center.php'],
            ['title' => 'Công cụ Seller', 'url' => 'seller-tools.php'],
            ['title' => 'Hỗ trợ Seller', 'url' => 'seller-support.php'],
            ['title' => 'Bảng giá dịch vụ', 'url' => 'seller-fees.php']
        ]
    ]
];

// Payment Methods
$payment_methods = [
    ['title' => 'Visa', 'icon' => '💳'],
    ['title' => 'MasterCard', 'icon' => '💳'],
    ['title' => 'Thẻ ATM', 'icon' => '🏧'],
    ['title' => 'MoMo', 'icon' => '📱'],
    ['title' => 'ZaloPay', 'icon' => '💰'],
    ['title' => 'VNPay', 'icon' => '🏦'],
    ['title' => 'ShopeePay', 'icon' => '💳'],
    ['title' => 'GrabPay', 'icon' => '🚗']
];

// Site Features
$site_features = [
    [
        'icon' => '🚚',
        'title' => 'Miễn phí vận chuyển',
        'description' => 'Cho đơn hàng từ 200.000đ'
    ],
    [
        'icon' => '🔒',
        'title' => 'Thanh toán an toàn',
        'description' => 'Bảo mật thông tin 100%'
    ],
    [
        'icon' => '🔄',
        'title' => 'Đổi trả dễ dàng',
        'description' => 'Trong vòng 7 ngày'
    ],
    [
        'icon' => '📞',
        'title' => 'Hỗ trợ 24/7',
        'description' => 'Tư vấn mọi lúc mọi nơi'
    ]
];

// Statistics
$site_stats = [
    ['label' => 'Khách hàng', 'value' => '1M+'],
    ['label' => 'Sản phẩm', 'value' => '100K+'],
    ['label' => 'Đối tác', 'value' => '500+'],
    ['label' => 'Đơn hàng', 'value' => '5M+']
];

// Category Icons Mapping
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

// Helper Functions
function getCategoryIcon($category_name)
{
    global $category_icons;
    $category_name_lower = strtolower($category_name);

    foreach ($category_icons as $keyword => $icon) {
        if (strpos($category_name_lower, $keyword) !== false) {
            return $icon;
        }
    }
    return '📦'; // Default icon
}

function formatPrice($price)
{
    return number_format($price, 0, ',', '.') . 'đ';
}

function formatDate($date)
{
    // Handle null/empty dates to prevent PHP 8.1+ deprecation warnings
    if (empty($date)) {
        return '';
    }
    return date('d/m/Y', strtotime($date));
}

function formatDateTime($datetime)
{
     
    if (empty($datetime)) {
        return '';
    }
    return date('d/m/Y H:i', strtotime($datetime));
}

function generateSlug($string)
{
    $string = trim($string);
    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

function truncateText($text, $limit = 100)
{
    if (strlen($text) > $limit) {
        return substr($text, 0, $limit) . '...';
    }
    return $text;
}

// Application Settings
define('ITEMS_PER_PAGE', 12);
define('SEARCH_RESULTS_PER_PAGE', 20);
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('MIN_PASSWORD_LENGTH', 6);
define('SESSION_TIMEOUT', 3600); // 1 hour

// Error Messages
$error_messages = [
    'login_required' => 'Vui lòng đăng nhập để tiếp tục',
    'invalid_credentials' => 'Tên đăng nhập hoặc mật khẩu không đúng',
    'access_denied' => 'Bạn không có quyền truy cập trang này',
    'product_not_found' => 'Sản phẩm không tồn tại',
    'out_of_stock' => 'Sản phẩm đã hết hàng',
    'invalid_quantity' => 'Số lượng không hợp lệ',
    'cart_empty' => 'Giỏ hàng của bạn đang trống',
    'upload_failed' => 'Tải file lên thất bại',
    'invalid_file_type' => 'Loại file không được hỗ trợ',
    'file_too_large' => 'Kích thước file quá lớn'
];

// Success Messages
$success_messages = [
    'login_success' => 'Đăng nhập thành công',
    'logout_success' => 'Đăng xuất thành công',
    'register_success' => 'Đăng ký tài khoản thành công',
    'product_added_to_cart' => 'Đã thêm sản phẩm vào giỏ hàng',
    'order_placed' => 'Đặt hàng thành công',
    'profile_updated' => 'Cập nhật thông tin thành công',
    'password_changed' => 'Đổi mật khẩu thành công'
];

// Currency and Locale Settings
define('DEFAULT_CURRENCY', 'VND');
define('DEFAULT_LOCALE', 'vi_VN');
define('TIMEZONE', 'Asia/Ho_Chi_Minh');

// Set timezone
date_default_timezone_set(TIMEZONE);
?>