<?php
require_once '../config.php';
session_start();

// Check if seller is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'seller') {
    header('Location: login.php');
    exit;
}

$seller_id = $_SESSION['user_id'];
$seller_name = $_SESSION['user_name'];

// Handle package upgrade
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'upgrade_package') {
    $package_id = (int) ($_POST['package_id'] ?? 0);
    $duration = (int) ($_POST['duration'] ?? 1); // months

    if ($package_id > 0 && $duration > 0) {
        try {
            // Get package details
            $stmt = $pdo->prepare("SELECT * FROM seller_packages WHERE id = ? AND status = 1");
            $stmt->execute([$package_id]);
            $package = $stmt->fetch();

            if ($package) {
                // Calculate total amount
                $monthly_price = $package['monthly_price'];
                $total_amount = $monthly_price * $duration;

                // Apply discount for longer periods
                if ($duration >= 12) {
                    $total_amount *= 0.8; // 20% discount for yearly
                } elseif ($duration >= 6) {
                    $total_amount *= 0.9; // 10% discount for 6+ months
                }

                // Create upgrade request (in real app, this would go to payment gateway)
                $expiry_date = date('Y-m-d H:i:s', strtotime("+{$duration} months"));

                $insert_stmt = $pdo->prepare("
                    INSERT INTO seller_package_orders (
                        seller_id, package_id, duration_months, amount, status, 
                        start_date, end_date, created_at
                    ) VALUES (?, ?, ?, ?, 'pending', NOW(), ?, NOW())
                ");
                $insert_stmt->execute([$seller_id, $package_id, $duration, $total_amount, $expiry_date]);

                $success = "Yêu cầu nâng cấp gói đã được gửi! Vui lòng thanh toán để kích hoạt.";
            } else {
                $error = "Gói dịch vụ không tồn tại!";
            }
        } catch (PDOException $e) {
            $error = "Có lỗi xảy ra: " . $e->getMessage();
        }
    }
}

try {
    // Get current seller info and package
    $stmt = $pdo->prepare("
        SELECT u.*, sp.name as package_name, sp.description as package_description,
               sp.monthly_price, sp.max_products, sp.max_images, sp.commission_rate,
               sp.featured_products, sp.analytics_access, sp.priority_support,
               spo.end_date as package_expiry
        FROM users u
        LEFT JOIN seller_packages sp ON u.customer_package_id = sp.id
        LEFT JOIN seller_package_orders spo ON u.id = spo.seller_id 
            AND spo.status = 'active' AND spo.end_date > NOW()
        WHERE u.id = ?
        ORDER BY spo.end_date DESC
        LIMIT 1
    ");
    $stmt->execute([$seller_id]);
    $seller_info = $stmt->fetch();

    // Get all available packages
    $packages_stmt = $pdo->prepare("
        SELECT * FROM seller_packages 
        WHERE status = 1 
        ORDER BY monthly_price ASC
    ");
    $packages_stmt->execute();
    $packages = $packages_stmt->fetchAll();

    // Get seller's package history
    $history_stmt = $pdo->prepare("
        SELECT spo.*, sp.name as package_name
        FROM seller_package_orders spo
        LEFT JOIN seller_packages sp ON spo.package_id = sp.id
        WHERE spo.seller_id = ?
        ORDER BY spo.created_at DESC
        LIMIT 10
    ");
    $history_stmt->execute([$seller_id]);
    $package_history = $history_stmt->fetchAll();

    // Get seller's current stats
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_products,
            SUM(CASE WHEN featured = 1 THEN 1 ELSE 0 END) as featured_products,
            COALESCE(SUM(
                SELECT COUNT(*) FROM uploads 
                WHERE user_id = ? AND deleted_at IS NULL
            ), 0) as total_images
        FROM products 
        WHERE user_id = ?
    ");
    $stats_stmt->execute([$seller_id, $seller_id]);
    $seller_stats = $stats_stmt->fetch();

} catch (PDOException $e) {
    $packages = [];
    $package_history = [];
    $seller_info = null;
    $seller_stats = ['total_products' => 0, 'featured_products' => 0, 'total_images' => 0];
    $error = "Có lỗi xảy ra khi tải dữ liệu.";
}

// Create default packages if none exist (for demo)
if (empty($packages)) {
    try {
        $default_packages = [
            [
                'name' => 'Gói Cơ Bản',
                'description' => 'Phù hợp cho seller mới bắt đầu',
                'monthly_price' => 0,
                'max_products' => 50,
                'max_images' => 200,
                'commission_rate' => 5.0,
                'featured_products' => 0,
                'analytics_access' => 0,
                'priority_support' => 0,
                'features' => json_encode([
                    'Đăng tối đa 50 sản phẩm',
                    'Upload tối đa 200 hình ảnh',
                    'Hoa hồng 5%',
                    'Hỗ trợ cơ bản'
                ])
            ],
            [
                'name' => 'Gói Tiêu Chuẩn',
                'description' => 'Dành cho seller có kinh nghiệm',
                'monthly_price' => 299000,
                'max_products' => 200,
                'max_images' => 1000,
                'commission_rate' => 3.0,
                'featured_products' => 5,
                'analytics_access' => 1,
                'priority_support' => 0,
                'features' => json_encode([
                    'Đăng tối đa 200 sản phẩm',
                    'Upload tối đa 1000 hình ảnh',
                    'Hoa hồng chỉ 3%',
                    '5 sản phẩm nổi bật',
                    'Truy cập báo cáo chi tiết',
                    'Hỗ trợ ưu tiên'
                ])
            ],
            [
                'name' => 'Gói Chuyên Nghiệp',
                'description' => 'Cho seller kinh doanh quy mô lớn',
                'monthly_price' => 599000,
                'max_products' => 1000,
                'max_images' => 5000,
                'commission_rate' => 2.0,
                'featured_products' => 20,
                'analytics_access' => 1,
                'priority_support' => 1,
                'features' => json_encode([
                    'Đăng tối đa 1000 sản phẩm',
                    'Upload tối đa 5000 hình ảnh',
                    'Hoa hồng chỉ 2%',
                    '20 sản phẩm nổi bật',
                    'Báo cáo phân tích chuyên sâu',
                    'Hỗ trợ 24/7',
                    'API tích hợp',
                    'Quản lý kho nâng cao'
                ])
            ]
        ];

        foreach ($default_packages as $pkg) {
            $insert_pkg = $pdo->prepare("
                INSERT INTO seller_packages (
                    name, description, monthly_price, max_products, max_images,
                    commission_rate, featured_products, analytics_access, 
                    priority_support, features, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $insert_pkg->execute([
                $pkg['name'],
                $pkg['description'],
                $pkg['monthly_price'],
                $pkg['max_products'],
                $pkg['max_images'],
                $pkg['commission_rate'],
                $pkg['featured_products'],
                $pkg['analytics_access'],
                $pkg['priority_support'],
                $pkg['features']
            ]);
        }

        // Reload packages
        $packages_stmt->execute();
        $packages = $packages_stmt->fetchAll();

    } catch (PDOException $e) {
        // Ignore error, use empty packages
    }
}

function formatCurrency($amount)
{
    if ($amount == 0)
        return 'Miễn phí';
    return number_format($amount, 0, ',', '.') . 'đ';
}

function getPackageStatus($seller_info)
{
    if (!$seller_info['package_name']) {
        return ['status' => 'Chưa có gói', 'class' => 'status-none', 'expiry' => ''];
    }

    if ($seller_info['package_expiry'] && strtotime($seller_info['package_expiry']) > time()) {
        $days_left = ceil((strtotime($seller_info['package_expiry']) - time()) / 86400);
        return [
            'status' => $seller_info['package_name'],
            'class' => 'status-active',
            'expiry' => "Còn {$days_left} ngày"
        ];
    }

    return [
        'status' => $seller_info['package_name'] . ' (Hết hạn)',
        'class' => 'status-expired',
        'expiry' => 'Đã hết hạn'
    ];
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gói dịch vụ - TikTok Shop Seller</title>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #f8fafc;
        color: #333;
        line-height: 1.6;
    }

    .layout {
        display: flex;
        min-height: 100vh;
    }

    .main-content {
        flex: 1;
        margin-left: 280px;
        min-height: 100vh;
    }

    .top-header {
        background: white;
        padding: 16px 24px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 50;
    }

    .header-left {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .header-left h1 {
        font-size: 24px;
        color: #1f2937;
        font-weight: 600;
    }

    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        color: #6b7280;
        margin-top: 4px;
    }

    .breadcrumb a {
        color: #ff0050;
        text-decoration: none;
    }

    .breadcrumb a:hover {
        text-decoration: underline;
    }

    .mobile-menu-btn {
        display: none;
        background: none;
        border: none;
        cursor: pointer;
        color: #4b5563;
        padding: 8px;
        border-radius: 6px;
        transition: all 0.3s ease;
    }

    .mobile-menu-btn:hover {
        background: #f3f4f6;
        color: #ff0050;
    }

    .content-wrapper {
        padding: 24px;
        max-width: 1200px;
        margin: 0 auto;
    }

    /* Current Package Status */
    .current-package {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 24px;
        border-radius: 16px;
        margin-bottom: 32px;
        position: relative;
        overflow: hidden;
    }

    .current-package::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 100px;
        height: 100px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        transform: translate(30px, -30px);
    }

    .package-status {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }

    .package-name {
        font-size: 24px;
        font-weight: 700;
    }

    .package-expiry {
        background: rgba(255, 255, 255, 0.2);
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 14px;
    }

    .package-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }

    .stat-item {
        text-align: center;
    }

    .stat-number {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .stat-label {
        font-size: 14px;
        opacity: 0.9;
    }

    .stat-progress {
        width: 100%;
        height: 4px;
        background: rgba(255, 255, 255, 0.3);
        border-radius: 2px;
        margin-top: 8px;
        overflow: hidden;
    }

    .stat-progress-bar {
        height: 100%;
        background: white;
        border-radius: 2px;
        transition: width 0.5s ease;
    }

    /* Packages Grid */
    .packages-section {
        margin-bottom: 32px;
    }

    .section-title {
        font-size: 20px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 16px;
        text-align: center;
    }

    .section-subtitle {
        text-align: center;
        color: #6b7280;
        margin-bottom: 32px;
    }

    .packages-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 24px;
        margin-bottom: 32px;
    }

    .package-card {
        background: white;
        border-radius: 16px;
        padding: 32px 24px;
        border: 2px solid #e5e7eb;
        transition: all 0.3s ease;
        position: relative;
        text-align: center;
    }

    .package-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
    }

    .package-card.featured {
        border-color: #ff0050;
        transform: translateY(-8px);
        box-shadow: 0 20px 40px rgba(255, 0, 80, 0.1);
    }

    .package-card.featured::before {
        content: 'Phổ biến';
        position: absolute;
        top: -10px;
        left: 50%;
        transform: translateX(-50%);
        background: #ff0050;
        color: white;
        padding: 6px 20px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    .package-header {
        margin-bottom: 24px;
    }

    .package-name {
        font-size: 24px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 8px;
    }

    .package-description {
        color: #6b7280;
        font-size: 14px;
    }

    .package-price {
        margin-bottom: 24px;
    }

    .price-amount {
        font-size: 36px;
        font-weight: 700;
        color: #ff0050;
        margin-bottom: 4px;
    }

    .price-period {
        color: #6b7280;
        font-size: 14px;
    }

    .package-features {
        list-style: none;
        margin-bottom: 32px;
        text-align: left;
    }

    .package-features li {
        padding: 8px 0;
        color: #4b5563;
        position: relative;
        padding-left: 24px;
    }

    .package-features li::before {
        content: '✓';
        position: absolute;
        left: 0;
        color: #10b981;
        font-weight: 600;
    }

    .package-features li.unavailable {
        color: #9ca3af;
        text-decoration: line-through;
    }

    .package-features li.unavailable::before {
        content: '✗';
        color: #ef4444;
    }

    .package-actions {
        display: flex;
        gap: 12px;
    }

    .btn {
        padding: 12px 24px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        flex: 1;
    }

    .btn-primary {
        background: #ff0050;
        color: white;
    }

    .btn-primary:hover {
        background: #cc0040;
        transform: translateY(-2px);
    }

    .btn-secondary {
        background: white;
        color: #4b5563;
        border: 1px solid #d1d5db;
    }

    .btn-secondary:hover {
        background: #f9fafb;
    }

    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none !important;
    }

    /* Duration Selector */
    .duration-selector {
        margin: 24px 0;
    }

    .duration-options {
        display: flex;
        gap: 8px;
        justify-content: center;
        margin-bottom: 16px;
    }

    .duration-option {
        padding: 8px 16px;
        border: 1px solid #d1d5db;
        border-radius: 20px;
        background: white;
        color: #4b5563;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .duration-option.active {
        background: #ff0050;
        color: white;
        border-color: #ff0050;
    }

    .discount-badge {
        background: #10b981;
        color: white;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 10px;
        margin-left: 8px;
    }

    /* Package History */
    .history-section {
        background: white;
        border-radius: 12px;
        padding: 24px;
        border: 1px solid #e5e7eb;
    }

    .history-table {
        width: 100%;
        border-collapse: collapse;
    }

    .history-table th,
    .history-table td {
        padding: 12px 16px;
        text-align: left;
        border-bottom: 1px solid #f3f4f6;
    }

    .history-table th {
        background: #f8f9fa;
        font-weight: 600;
        color: #374151;
        font-size: 14px;
    }

    .status-badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
        text-transform: uppercase;
    }

    .status-active {
        background: #dcfce7;
        color: #166534;
    }

    .status-pending {
        background: #fef3c7;
        color: #92400e;
    }

    .status-expired {
        background: #fee2e2;
        color: #dc2626;
    }

    .status-none {
        background: #f3f4f6;
        color: #6b7280;
    }

    /* Modal */
    .modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .modal.show {
        opacity: 1;
        visibility: visible;
    }

    .modal-content {
        background: white;
        border-radius: 16px;
        padding: 32px;
        max-width: 500px;
        width: 90%;
        transform: scale(0.9);
        transition: transform 0.3s ease;
    }

    .modal.show .modal-content {
        transform: scale(1);
    }

    .modal-header {
        text-align: center;
        margin-bottom: 24px;
    }

    .modal-title {
        font-size: 24px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 8px;
    }

    .modal-subtitle {
        color: #6b7280;
    }

    .modal-body {
        margin-bottom: 24px;
    }

    .price-summary {
        background: #f8fafc;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
    }

    .price-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
    }

    .price-row.total {
        font-weight: 700;
        color: #1f2937;
        border-top: 1px solid #e5e7eb;
        padding-top: 8px;
        margin-top: 8px;
        font-size: 18px;
    }

    .modal-actions {
        display: flex;
        gap: 12px;
    }

    /* Alert */
    .alert {
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        font-size: 14px;
    }

    .alert-success {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
    }

    .alert-error {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .main-content {
            margin-left: 0;
        }

        .mobile-menu-btn {
            display: block;
        }

        .content-wrapper {
            padding: 16px;
        }

        .packages-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }

        .package-stats {
            grid-template-columns: repeat(2, 1fr);
        }

        .package-actions {
            flex-direction: column;
        }

        .duration-options {
            flex-wrap: wrap;
        }

        .history-table {
            font-size: 12px;
        }

        .history-table th,
        .history-table td {
            padding: 8px 12px;
        }
    }

    @media (max-width: 480px) {
        .package-stats {
            grid-template-columns: 1fr;
        }

        .modal-content {
            padding: 24px 20px;
        }
    }

    /* Animations */
    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .package-card {
        animation: slideInUp 0.6s ease forwards;
    }

    .package-card:nth-child(2) {
        animation-delay: 0.1s;
    }

    .package-card:nth-child(3) {
        animation-delay: 0.2s;
    }
    </style>
</head>

<body>
    <div class="layout">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <div class="top-header">
                <div class="header-left">
                    <button class="mobile-menu-btn" onclick="toggleSidebar()">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" />
                        </svg>
                    </button>
                    <div>
                        <h1>Gói dịch vụ</h1>
                        <div class="breadcrumb">
                            <a href="dashboard.php">Dashboard</a>
                            <span>›</span>
                            <span>Gói dịch vụ</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-wrapper">
                <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                =

                <!-- Current Package Status -->
                <div class="current-package">
                    <?php
                    $package_info = getPackageStatus($seller_info);
                    $products_usage = $seller_info['max_products'] ?
                        ($seller_stats['total_products'] / $seller_info['max_products']) * 100 : 0;
                    $images_usage = $seller_info['max_images'] ?
                        ($seller_stats['total_images'] / $seller_info['max_images']) * 100 : 0;
                    ?>
                    <div class="package-status">
                        <div class="package-name"><?php echo $package_info['status']; ?></div>
                        <div class="package-expiry"><?php echo $package_info['expiry']; ?></div>
                    </div>

                    <div class="package-stats">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $seller_stats['total_products']; ?></div>
                            <div class="stat-label">Sản phẩm đã đăng</div>
                            <?php if ($seller_info['max_products']): ?>
                            <div class="stat-progress">
                                <div class="stat-progress-bar" style="width: <?php echo min(100, $products_usage); ?>%">
                                </div>
                            </div>
                            <div style="font-size: 12px; margin-top: 4px;">
                                <?php echo $seller_stats['total_products']; ?> /
                                <?php echo $seller_info['max_products']; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="stat-item">
                            <div class="stat-number"><?php echo $seller_stats['featured_products']; ?></div>
                            <div class="stat-label">Sản phẩm nổi bật</div>
                            <?php if ($seller_info['featured_products']): ?>
                            <div class="stat-progress">
                                <div class="stat-progress-bar"
                                    style="width: <?php echo ($seller_stats['featured_products'] / $seller_info['featured_products']) * 100; ?>%">
                                </div>
                            </div>
                            <div style="font-size: 12px; margin-top: 4px;">
                                <?php echo $seller_stats['featured_products']; ?> /
                                <?php echo $seller_info['featured_products']; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="stat-item">
                            <div class="stat-number">
                                <?php echo number_format($seller_info['commission_rate'] ?? 5, 1); ?>%
                            </div>
                            <div class="stat-label">Hoa hồng hiện tại</div>
                        </div>

                        <div class="stat-item">
                            <div class="stat-number"><?php echo $seller_stats['total_images']; ?></div>
                            <div class="stat-label">Hình ảnh đã tải</div>
                            <?php if ($seller_info['max_images']): ?>
                            <div class="stat-progress">
                                <div class="stat-progress-bar" style="width: <?php echo min(100, $images_usage); ?>%">
                                </div>
                            </div>
                            <div style="font-size: 12px; margin-top: 4px;">
                                <?php echo $seller_stats['total_images']; ?> / <?php echo $seller_info['max_images']; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Available Packages -->
                <div class="packages-section">
                    <h2 class="section-title">Chọn gói dịch vụ phù hợp</h2>
                    <p class="section-subtitle">Nâng cấp gói để mở khóa thêm nhiều tính năng và tăng doanh thu</p>

                    <div class="packages-grid">
                        <?php foreach ($packages as $index => $package):
                            $features = json_decode($package['features'] ?? '[]', true);
                            $is_current = $seller_info['customer_package_id'] == $package['id'];
                            $is_featured = $index == 1; // Middle package is featured
                            ?>
                        <div class="package-card <?php echo $is_featured ? 'featured' : ''; ?>">
                            <div class="package-header">
                                <div class="package-name"><?php echo htmlspecialchars($package['name']); ?></div>
                                <div class="package-description">
                                    <?php echo htmlspecialchars($package['description']); ?>
                                </div>
                            </div>

                            <div class="package-price">
                                <div class="price-amount"><?php echo formatCurrency($package['monthly_price']); ?></div>
                                <div class="price-period"><?php echo $package['monthly_price'] > 0 ? '/tháng' : ''; ?>
                                </div>
                            </div>

                            <ul class="package-features">
                                <?php if (is_array($features)): ?>
                                <?php foreach ($features as $feature): ?>
                                <li><?php echo htmlspecialchars($feature); ?></li>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <li>Đăng tối đa <?php echo $package['max_products']; ?> sản phẩm</li>
                                <li>Upload tối đa <?php echo $package['max_images']; ?> hình ảnh</li>
                                <li>Hoa hồng <?php echo $package['commission_rate']; ?>%</li>
                                <?php if ($package['featured_products'] > 0): ?>
                                <li><?php echo $package['featured_products']; ?> sản phẩm nổi bật</li>
                                <?php endif; ?>
                                <?php if ($package['analytics_access']): ?>
                                <li>Truy cập báo cáo chi tiết</li>
                                <?php endif; ?>
                                <?php if ($package['priority_support']): ?>
                                <li>Hỗ trợ ưu tiên 24/7</li>
                                <?php endif; ?>
                                <?php endif; ?>
                            </ul>

                            <div class="package-actions">
                                <?php if ($is_current): ?>
                                <button class="btn btn-secondary" disabled>Gói hiện tại</button>
                                <?php else: ?>
                                <button class="btn btn-primary"
                                    onclick="selectPackage(<?php echo $package['id']; ?>, '<?php echo htmlspecialchars($package['name']); ?>', <?php echo $package['monthly_price']; ?>)">
                                    <?php echo $package['monthly_price'] > 0 ? 'Nâng cấp' : 'Chọn gói'; ?>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Package History -->
                <?php if (!empty($package_history)): ?>
                <div class="history-section">
                    <h3 style="margin-bottom: 20px;">Lịch sử gói dịch vụ</h3>
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Gói dịch vụ</th>
                                <th>Thời gian</th>
                                <th>Số tiền</th>
                                <th>Trạng thái</th>
                                <th>Ngày đăng ký</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($package_history as $history): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($history['package_name']); ?></td>
                                <td><?php echo $history['duration_months']; ?> tháng</td>
                                <td><?php echo formatCurrency($history['amount']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $history['status']; ?>">
                                        <?php
                                                $status_text = [
                                                    'active' => 'Đang hoạt động',
                                                    'pending' => 'Chờ thanh toán',
                                                    'expired' => 'Đã hết hạn',
                                                    'cancelled' => 'Đã hủy'
                                                ];
                                                echo $status_text[$history['status']] ?? $history['status'];
                                                ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($history['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Package Selection Modal -->
    <div id="packageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title" id="modalPackageName">Nâng cấp gói</div>
                <div class="modal-subtitle">Chọn thời gian sử dụng để nhận ưu đãi tốt nhất</div>
            </div>
            <div class="modal-body">
                <div class="duration-selector">
                    <div class="duration-options">
                        <div class="duration-option active" data-months="1" onclick="selectDuration(1)">
                            1 tháng
                        </div>
                        <div class="duration-option" data-months="3" onclick="selectDuration(3)">
                            3 tháng
                        </div>
                        <div class="duration-option" data-months="6" onclick="selectDuration(6)">
                            6 tháng <span class="discount-badge">-10%</span>
                        </div>
                        <div class="duration-option" data-months="12" onclick="selectDuration(12)">
                            12 tháng <span class="discount-badge">-20%</span>
                        </div>
                    </div>
                </div>

                <div class="price-summary">
                    <div class="price-row">
                        <span>Giá gói/tháng:</span>
                        <span id="monthlyPrice">0đ</span>
                    </div>
                    <div class="price-row">
                        <span>Thời gian:</span>
                        <span id="selectedDuration">1 tháng</span>
                    </div>
                    <div class="price-row">
                        <span>Tạm tính:</span>
                        <span id="subtotal">0đ</span>
                    </div>
                    <div class="price-row" id="discountRow" style="display: none;">
                        <span>Giảm giá:</span>
                        <span id="discountAmount">0đ</span>
                    </div>
                    <div class="price-row total">
                        <span>Tổng thanh toán:</span>
                        <span id="totalAmount">0đ</span>
                    </div>
                </div>

                <div style="font-size: 14px; color: #6b7280; text-align: center;">
                    💳 Thanh toán an toàn qua các phương thức: VNPAY, MoMo, Banking
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeModal()">Hủy</button>
                <button class="btn btn-primary" onclick="confirmUpgrade()">Xác nhận nâng cấp</button>
            </div>
        </div>
    </div>

    <!-- Hidden Form -->
    <form id="upgradeForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="upgrade_package">
        <input type="hidden" name="package_id" id="selectedPackageId">
        <input type="hidden" name="duration" id="selectedDurationValue">
    </form>

    <script>
    let selectedPackageData = {};
    let selectedDuration = 1;

    // Select package
    function selectPackage(packageId, packageName, monthlyPrice) {
        selectedPackageData = {
            id: packageId,
            name: packageName,
            price: monthlyPrice
        };

        document.getElementById('modalPackageName').textContent = `Nâng cấp ${packageName}`;
        document.getElementById('selectedPackageId').value = packageId;
        document.getElementById('monthlyPrice').textContent = formatCurrency(monthlyPrice);

        // Reset to 1 month
        selectDuration(1);

        document.getElementById('packageModal').classList.add('show');
    }

    // Select duration
    function selectDuration(months) {
        selectedDuration = months;
        document.getElementById('selectedDurationValue').value = months;

        // Update active state
        document.querySelectorAll('.duration-option').forEach(option => {
            option.classList.remove('active');
        });
        document.querySelector(`[data-months="${months}"]`).classList.add('active');

        updatePriceSummary();
    }

    // Update price summary
    function updatePriceSummary() {
        const monthlyPrice = selectedPackageData.price || 0;
        const subtotal = monthlyPrice * selectedDuration;
        let discount = 0;

        // Apply discounts
        if (selectedDuration >= 12) {
            discount = subtotal * 0.2; // 20% off
        } else if (selectedDuration >= 6) {
            discount = subtotal * 0.1; // 10% off
        }

        const total = subtotal - discount;

        document.getElementById('selectedDuration').textContent = `${selectedDuration} tháng`;
        document.getElementById('subtotal').textContent = formatCurrency(subtotal);
        document.getElementById('totalAmount').textContent = formatCurrency(total);

        if (discount > 0) {
            document.getElementById('discountRow').style.display = 'flex';
            document.getElementById('discountAmount').textContent = formatCurrency(discount);
        } else {
            document.getElementById('discountRow').style.display = 'none';
        }
    }

    // Confirm upgrade
    function confirmUpgrade() {
        if (confirm(`Bạn có muốn nâng cấp gói ${selectedPackageData.name} trong ${selectedDuration} tháng?`)) {
            document.getElementById('upgradeForm').submit();
        }
    }

    // Close modal
    function closeModal() {
        document.getElementById('packageModal').classList.remove('show');
    }

    // Format currency
    function formatCurrency(amount) {
        if (amount === 0) return 'Miễn phí';
        return new Intl.NumberFormat('vi-VN').format(amount) + 'đ';
    }

    // Close modal when clicking outside
    document.getElementById('packageModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });

    // Sidebar toggle for mobile
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            sidebar.classList.toggle('open');
        }
    }

    // Auto-refresh package status every 30 seconds
    setInterval(function() {
        // You can implement auto-refresh logic here
    }, 30000);
    </script>
</body>

</html>