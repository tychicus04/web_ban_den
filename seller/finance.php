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

// Handle withdraw request
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'withdraw_request') {
    $amount = (float) ($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? '';
    $account_info = trim($_POST['account_info'] ?? '');

    try {
        // Get current balance
        $balance_stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
        $balance_stmt->execute([$seller_id]);
        $current_balance = $balance_stmt->fetchColumn();

        if ($amount <= 0) {
            throw new Exception("Số tiền rút phải lớn hơn 0!");
        }

        if ($amount < 50000) {
            throw new Exception("Số tiền rút tối thiểu là 50,000đ!");
        }

        if ($amount > $current_balance) {
            throw new Exception("Số dư không đủ!");
        }

        if (empty($method) || empty($account_info)) {
            throw new Exception("Vui lòng chọn phương thức và nhập thông tin tài khoản!");
        }

        // Create withdrawal request
        $withdraw_stmt = $pdo->prepare("
            INSERT INTO seller_withdrawals (
                seller_id, amount, method, account_info, status, 
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())
        ");
        $withdraw_stmt->execute([$seller_id, $amount, $method, $account_info]);

        $success = "Yêu cầu rút tiền đã được gửi! Chúng tôi sẽ xử lý trong 1-3 ngày làm việc.";

    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = "Có lỗi xảy ra: " . $e->getMessage();
    }
}

// Get time filter
$period = isset($_GET['period']) ? $_GET['period'] : '30days';
$date_filter = '';
$date_params = [];

switch ($period) {
    case '7days':
        $date_filter = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        break;
    case '30days':
        $date_filter = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        break;
    case '90days':
        $date_filter = "AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
        break;
    case 'year':
        $date_filter = "AND YEAR(created_at) = YEAR(CURDATE())";
        break;
}

try {
    // Get seller info and balance
    $seller_stmt = $pdo->prepare("SELECT name, balance FROM users WHERE id = ?");
    $seller_stmt->execute([$seller_id]);
    $seller_info = $seller_stmt->fetch();

    // Get revenue statistics
    $revenue_stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as total_orders,
            COALESCE(SUM(od.price * od.quantity), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN od.price * od.quantity ELSE 0 END), 0) as paid_revenue,
            COALESCE(SUM(CASE WHEN o.delivery_status = 'delivered' THEN od.price * od.quantity ELSE 0 END), 0) as delivered_revenue
        FROM orders o
        LEFT JOIN order_details od ON o.id = od.order_id
        WHERE od.seller_id = ? $date_filter
    ");
    $revenue_stmt->execute([$seller_id]);
    $revenue_stats = $revenue_stmt->fetch();

    // Get commission statistics
    $commission_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_commissions,
            COALESCE(SUM(admin_commission), 0) as total_admin_commission,
            COALESCE(SUM(seller_earning), 0) as total_seller_earning
        FROM commission_histories ch
        LEFT JOIN orders o ON ch.order_id = o.id
        WHERE ch.seller_id = ? $date_filter
    ");
    $commission_stmt->execute([$seller_id]);
    $commission_stats = $commission_stmt->fetch();

    // Get wallet transactions
    $wallet_stmt = $pdo->prepare("
        SELECT 
            'deposit' as type,
            amount,
            'Nạp tiền vào ví' as description,
            CASE WHEN approval = 1 THEN 'completed' ELSE 'pending' END as status,
            created_at
        FROM wallets 
        WHERE user_id = ? $date_filter
        
        UNION ALL
        
        SELECT 
            'commission' as type,
            seller_earning as amount,
            CONCAT('Hoa hồng đơn hàng #', order_id) as description,
            'completed' as status,
            created_at
        FROM commission_histories 
        WHERE seller_id = ? $date_filter
        
        UNION ALL
        
        SELECT 
            'withdrawal' as type,
            -amount as amount,
            CONCAT('Rút tiền qua ', method) as description,
            status,
            created_at
        FROM seller_withdrawals 
        WHERE seller_id = ? $date_filter
        
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $wallet_stmt->execute([$seller_id, $seller_id, $seller_id]);
    $transactions = $wallet_stmt->fetchAll();

    // Get daily revenue for chart (last 30 days)
    $chart_stmt = $pdo->prepare("
        SELECT 
            DATE(o.created_at) as date,
            COALESCE(SUM(od.price * od.quantity), 0) as revenue,
            COUNT(DISTINCT o.id) as orders
        FROM orders o
        LEFT JOIN order_details od ON o.id = od.order_id
        WHERE od.seller_id = ? 
        AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND o.payment_status = 'paid'
        GROUP BY DATE(o.created_at)
        ORDER BY date DESC
    ");
    $chart_stmt->execute([$seller_id]);
    $chart_data = $chart_stmt->fetchAll();

    // Get pending withdrawals
    $pending_withdrawals_stmt = $pdo->prepare("
        SELECT * FROM seller_withdrawals 
        WHERE seller_id = ? AND status IN ('pending', 'processing')
        ORDER BY created_at DESC
    ");
    $pending_withdrawals_stmt->execute([$seller_id]);
    $pending_withdrawals = $pending_withdrawals_stmt->fetchAll();

    // Calculate growth
    $growth_stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
                         THEN od.price * od.quantity ELSE 0 END), 0) as current_month,
            COALESCE(SUM(CASE WHEN DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) 
                         AND DATE(o.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                         THEN od.price * od.quantity ELSE 0 END), 0) as previous_month
        FROM orders o
        LEFT JOIN order_details od ON o.id = od.order_id
        WHERE od.seller_id = ? AND o.payment_status = 'paid'
    ");
    $growth_stmt->execute([$seller_id]);
    $growth_data = $growth_stmt->fetch();

    $growth_percentage = 0;
    if ($growth_data['previous_month'] > 0) {
        $growth_percentage = (($growth_data['current_month'] - $growth_data['previous_month']) / $growth_data['previous_month']) * 100;
    }

} catch (PDOException $e) {
    $seller_info = ['name' => '', 'balance' => 0];
    $revenue_stats = ['total_orders' => 0, 'total_revenue' => 0, 'paid_revenue' => 0, 'delivered_revenue' => 0];
    $commission_stats = ['total_commissions' => 0, 'total_admin_commission' => 0, 'total_seller_earning' => 0];
    $transactions = [];
    $chart_data = [];
    $pending_withdrawals = [];
    $growth_percentage = 0;
    $error = "Có lỗi xảy ra khi tải dữ liệu tài chính.";
}

// Create withdrawal table if not exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS seller_withdrawals (
            id int(11) PRIMARY KEY AUTO_INCREMENT,
            seller_id int(11) NOT NULL,
            amount decimal(10,2) NOT NULL,
            method varchar(50) NOT NULL,
            account_info text NOT NULL,
            status enum('pending','processing','completed','rejected') DEFAULT 'pending',
            admin_note text,
            processed_at datetime NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    // Table might already exist, ignore error
}

function formatCurrency($amount)
{
    return number_format($amount, 0, ',', '.') . 'đ';
}

function getTransactionIcon($type)
{
    $icons = [
        'deposit' => '💰',
        'commission' => '🎯',
        'withdrawal' => '💸',
        'refund' => '↩️'
    ];
    return $icons[$type] ?? '💳';
}

function getStatusBadge($status)
{
    $badges = [
        'completed' => '<span class="status-badge status-completed">Hoàn thành</span>',
        'pending' => '<span class="status-badge status-pending">Chờ xử lý</span>',
        'processing' => '<span class="status-badge status-processing">Đang xử lý</span>',
        'rejected' => '<span class="status-badge status-rejected">Từ chối</span>'
    ];
    return $badges[$status] ?? '<span class="status-badge">' . $status . '</span>';
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tài chính - TikTok Shop Seller</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

    .header-actions {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .period-select {
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        background: white;
        cursor: pointer;
    }

    .period-select:focus {
        outline: none;
        border-color: #ff0050;
    }

    .btn {
        padding: 8px 16px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-primary {
        background: #ff0050;
        color: white;
    }

    .btn-primary:hover {
        background: #cc0040;
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: #f3f4f6;
        color: #4b5563;
        border: 1px solid #d1d5db;
    }

    .btn-secondary:hover {
        background: #e5e7eb;
    }

    .content-wrapper {
        padding: 24px;
    }

    /* Balance Card */
    .balance-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 32px;
        border-radius: 20px;
        margin-bottom: 32px;
        position: relative;
        overflow: hidden;
    }

    .balance-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200px;
        height: 200px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
    }

    .balance-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }

    .balance-title {
        font-size: 18px;
        opacity: 0.9;
    }

    .balance-amount {
        font-size: 48px;
        font-weight: 700;
        margin-bottom: 16px;
    }

    .balance-growth {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        opacity: 0.9;
    }

    .growth-positive {
        color: #10b981;
    }

    .growth-negative {
        color: #ef4444;
    }

    .balance-actions {
        display: flex;
        gap: 12px;
        margin-top: 24px;
    }

    .balance-btn {
        padding: 12px 24px;
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: white;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s ease;
        backdrop-filter: blur(10px);
    }

    .balance-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: translateY(-2px);
    }

    /* Statistics Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 24px;
        margin-bottom: 32px;
    }

    .stat-card {
        background: white;
        padding: 24px;
        border-radius: 16px;
        border: 1px solid #e5e7eb;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--accent-color, #ff0050);
    }

    .stat-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }

    .stat-title {
        font-size: 14px;
        color: #6b7280;
        font-weight: 500;
    }

    .stat-icon {
        font-size: 24px;
    }

    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 8px;
    }

    .stat-subtitle {
        font-size: 14px;
        color: #6b7280;
    }

    .stat-card.revenue {
        --accent-color: #10b981;
    }

    .stat-card.orders {
        --accent-color: #3b82f6;
    }

    .stat-card.commission {
        --accent-color: #f59e0b;
    }

    .stat-card.pending {
        --accent-color: #ef4444;
    }

    /* Chart Section */
    .chart-section {
        background: white;
        padding: 24px;
        border-radius: 16px;
        border: 1px solid #e5e7eb;
        margin-bottom: 32px;
    }

    .chart-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }

    .chart-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
    }

    .chart-container {
        position: relative;
        height: 300px;
    }

    /* Two Column Layout */
    .two-column {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-bottom: 32px;
    }

    /* Transactions Section */
    .transactions-section {
        background: white;
        border-radius: 16px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    .section-header {
        padding: 24px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .section-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
    }

    .transaction-list {
        max-height: 400px;
        overflow-y: auto;
    }

    .transaction-item {
        display: flex;
        align-items: center;
        padding: 16px 24px;
        border-bottom: 1px solid #f3f4f6;
        transition: background 0.3s ease;
    }

    .transaction-item:hover {
        background: #f9fafb;
    }

    .transaction-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        margin-right: 16px;
    }

    .transaction-icon.deposit {
        background: #dcfce7;
    }

    .transaction-icon.commission {
        background: #fef3c7;
    }

    .transaction-icon.withdrawal {
        background: #fee2e2;
    }

    .transaction-info {
        flex: 1;
    }

    .transaction-description {
        font-weight: 500;
        color: #1f2937;
        margin-bottom: 4px;
    }

    .transaction-date {
        font-size: 12px;
        color: #6b7280;
    }

    .transaction-amount {
        text-align: right;
    }

    .amount-value {
        font-weight: 600;
        margin-bottom: 4px;
    }

    .amount-positive {
        color: #10b981;
    }

    .amount-negative {
        color: #ef4444;
    }

    .status-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 500;
        text-transform: uppercase;
    }

    .status-completed {
        background: #dcfce7;
        color: #166534;
    }

    .status-pending {
        background: #fef3c7;
        color: #92400e;
    }

    .status-processing {
        background: #e0e7ff;
        color: #3730a3;
    }

    .status-rejected {
        background: #fee2e2;
        color: #dc2626;
    }

    /* Withdraw Section */
    .withdraw-section {
        background: white;
        border-radius: 16px;
        border: 1px solid #e5e7eb;
    }

    .withdraw-form {
        padding: 24px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #374151;
        margin-bottom: 8px;
    }

    .form-input,
    .form-select,
    .form-textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        transition: border-color 0.3s ease;
    }

    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
        outline: none;
        border-color: #ff0050;
    }

    .form-textarea {
        resize: vertical;
        min-height: 80px;
    }

    .withdraw-info {
        background: #f8fafc;
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .withdraw-info h4 {
        color: #1f2937;
        margin-bottom: 8px;
    }

    .withdraw-info ul {
        font-size: 14px;
        color: #6b7280;
        margin-left: 16px;
    }

    .withdraw-info li {
        margin-bottom: 4px;
    }

    /* Pending Withdrawals */
    .pending-withdrawals {
        padding: 0 24px 24px;
    }

    .pending-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid #f3f4f6;
    }

    .pending-item:last-child {
        border-bottom: none;
    }

    .pending-info {
        flex: 1;
    }

    .pending-amount {
        font-weight: 600;
        color: #1f2937;
    }

    .pending-method {
        font-size: 12px;
        color: #6b7280;
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

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #6b7280;
    }

    .empty-state-icon {
        font-size: 48px;
        margin-bottom: 16px;
        opacity: 0.5;
    }

    .empty-state h3 {
        font-size: 16px;
        color: #374151;
        margin-bottom: 8px;
    }

    .empty-state p {
        font-size: 14px;
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
        padding: 24px;
        max-width: 500px;
        width: 90%;
        transform: scale(0.9);
        transition: transform 0.3s ease;
    }

    .modal.show .modal-content {
        transform: scale(1);
    }

    .modal-header {
        margin-bottom: 20px;
    }

    .modal-title {
        font-size: 20px;
        font-weight: 600;
        color: #1f2937;
    }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 24px;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .two-column {
            grid-template-columns: 1fr;
        }
    }

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

        .balance-card {
            padding: 24px 20px;
        }

        .balance-amount {
            font-size: 36px;
        }

        .stats-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }

        .balance-actions {
            flex-direction: column;
        }

        .chart-container {
            height: 250px;
        }

        .transaction-item {
            padding: 12px 16px;
        }

        .header-actions {
            flex-direction: column;
            gap: 8px;
        }
    }

    @media (max-width: 480px) {
        .balance-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }

        .transaction-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }

        .transaction-amount {
            align-self: flex-end;
        }
    }

    /* Loading Animation */
    .loading {
        opacity: 0.6;
        pointer-events: none;
    }

    .loading::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 20px;
        height: 20px;
        margin: -10px 0 0 -10px;
        border: 2px solid #f3f4f6;
        border-top: 2px solid #ff0050;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
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
                        <h1>Tài chính</h1>
                        <div class="breadcrumb">
                            <a href="dashboard.php">Dashboard</a>
                            <span>›</span>
                            <span>Tài chính</span>
                        </div>
                    </div>
                </div>
                <div class="header-actions">
                    <select class="period-select" onchange="changePeriod(this.value)">
                        <option value="7days" <?php echo $period == '7days' ? 'selected' : ''; ?>>7 ngày qua</option>
                        <option value="30days" <?php echo $period == '30days' ? 'selected' : ''; ?>>30 ngày qua</option>
                        <option value="90days" <?php echo $period == '90days' ? 'selected' : ''; ?>>90 ngày qua</option>
                        <option value="year" <?php echo $period == 'year' ? 'selected' : ''; ?>>Năm nay</option>
                    </select>
                    <button class="btn btn-secondary" onclick="window.location.reload()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 8 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z" />
                        </svg>
                        Làm mới
                    </button>
                </div>
            </div>

            <div class="content-wrapper">
                <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Balance Card -->
                <div class="balance-card">
                    <div class="balance-header">
                        <div class="balance-title">Số dư ví của bạn</div>
                        <div>💰</div>
                    </div>
                    <div class="balance-amount"><?php echo formatCurrency($seller_info['balance']); ?></div>
                    <div class="balance-growth">
                        <span>Tăng trưởng tháng này:</span>
                        <span class="<?php echo $growth_percentage >= 0 ? 'growth-positive' : 'growth-negative'; ?>">
                            <?php echo $growth_percentage >= 0 ? '▲' : '▼'; ?>
                            <?php echo abs(round($growth_percentage, 1)); ?>%
                        </span>
                    </div>
                    <div class="balance-actions">
                        <a href="#" class="balance-btn" onclick="openWithdrawModal()">
                            💸 Rút tiền
                        </a>
                        <a href="#" class="balance-btn" onclick="viewTransactionHistory()">
                            📊 Lịch sử giao dịch
                        </a>
                    </div>
                </div>

                <!-- Statistics Grid -->
                <div class="stats-grid">
                    <div class="stat-card revenue">
                        <div class="stat-header">
                            <div class="stat-title">Tổng doanh thu</div>
                            <div class="stat-icon">💰</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($revenue_stats['paid_revenue']); ?></div>
                        <div class="stat-subtitle"><?php echo $revenue_stats['total_orders']; ?> đơn hàng</div>
                    </div>

                    <div class="stat-card orders">
                        <div class="stat-header">
                            <div class="stat-title">Đơn hàng đã giao</div>
                            <div class="stat-icon">📦</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($revenue_stats['delivered_revenue']); ?></div>
                        <div class="stat-subtitle">Doanh thu đã hoàn thành</div>
                    </div>

                    <div class="stat-card commission">
                        <div class="stat-header">
                            <div class="stat-title">Hoa hồng kiếm được</div>
                            <div class="stat-icon">🎯</div>
                        </div>
                        <div class="stat-value"><?php echo formatCurrency($commission_stats['total_seller_earning']); ?>
                        </div>
                        <div class="stat-subtitle"><?php echo $commission_stats['total_commissions']; ?> giao dịch</div>
                    </div>

                    <div class="stat-card pending">
                        <div class="stat-header">
                            <div class="stat-title">Chờ thanh toán</div>
                            <div class="stat-icon">⏳</div>
                        </div>
                        <div class="stat-value">
                            <?php echo formatCurrency($revenue_stats['total_revenue'] - $revenue_stats['paid_revenue']); ?>
                        </div>
                        <div class="stat-subtitle">Đơn hàng chưa thanh toán</div>
                    </div>
                </div>

                <!-- Chart Section -->
                <div class="chart-section">
                    <div class="chart-header">
                        <div class="chart-title">Biểu đồ doanh thu 30 ngày qua</div>
                    </div>
                    <div class="chart-container">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>

                <!-- Two Column Layout -->
                <div class="two-column">
                    <!-- Transaction History -->
                    <div class="transactions-section">
                        <div class="section-header">
                            <div class="section-title">Giao dịch gần đây</div>
                        </div>
                        <div class="transaction-list">
                            <?php if (empty($transactions)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">💳</div>
                                <h3>Chưa có giao dịch nào</h3>
                                <p>Lịch sử giao dịch sẽ hiển thị ở đây</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($transactions as $transaction): ?>
                            <div class="transaction-item">
                                <div class="transaction-icon <?php echo $transaction['type']; ?>">
                                    <?php echo getTransactionIcon($transaction['type']); ?>
                                </div>
                                <div class="transaction-info">
                                    <div class="transaction-description">
                                        <?php echo htmlspecialchars($transaction['description']); ?></div>
                                    <div class="transaction-date">
                                        <?php echo date('d/m/Y H:i', strtotime($transaction['created_at'])); ?></div>
                                </div>
                                <div class="transaction-amount">
                                    <div
                                        class="amount-value <?php echo $transaction['amount'] >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                        <?php echo $transaction['amount'] >= 0 ? '+' : ''; ?>
                                        <?php echo formatCurrency(abs($transaction['amount'])); ?>
                                    </div>
                                    <?php echo getStatusBadge($transaction['status']); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Withdraw Section -->
                    <div class="withdraw-section">
                        <div class="section-header">
                            <div class="section-title">Rút tiền</div>
                        </div>

                        <!-- Pending Withdrawals -->
                        <?php if (!empty($pending_withdrawals)): ?>
                        <div class="pending-withdrawals">
                            <h4 style="margin-bottom: 12px; color: #374151;">Yêu cầu rút tiền đang xử lý</h4>
                            <?php foreach ($pending_withdrawals as $withdrawal): ?>
                            <div class="pending-item">
                                <div class="pending-info">
                                    <div class="pending-amount"><?php echo formatCurrency($withdrawal['amount']); ?>
                                    </div>
                                    <div class="pending-method"><?php echo htmlspecialchars($withdrawal['method']); ?> •
                                        <?php echo date('d/m/Y', strtotime($withdrawal['created_at'])); ?></div>
                                </div>
                                <?php echo getStatusBadge($withdrawal['status']); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <form method="POST" class="withdraw-form">
                            <input type="hidden" name="action" value="withdraw_request">

                            <div class="withdraw-info">
                                <h4>Thông tin rút tiền</h4>
                                <ul>
                                    <li>Số tiền rút tối thiểu: 50,000đ</li>
                                    <li>Phí rút tiền: Miễn phí</li>
                                    <li>Thời gian xử lý: 1-3 ngày làm việc</li>
                                    <li>Rút tiền trong giờ hành chính để xử lý nhanh hơn</li>
                                </ul>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Số tiền muốn rút *</label>
                                <input type="number" name="amount" class="form-input" placeholder="Nhập số tiền..."
                                    min="50000" max="<?php echo $seller_info['balance']; ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Phương thức rút tiền *</label>
                                <select name="method" class="form-select" required>
                                    <option value="">Chọn phương thức</option>
                                    <option value="bank_transfer">Chuyển khoản ngân hàng</option>
                                    <option value="momo">Ví MoMo</option>
                                    <option value="zalopay">ZaloPay</option>
                                    <option value="viettel_money">Viettel Money</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Thông tin tài khoản *</label>
                                <textarea name="account_info" class="form-textarea"
                                    placeholder="Nhập thông tin tài khoản (Số tài khoản, tên chủ tài khoản, ngân hàng...)"
                                    required></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                💸 Gửi yêu cầu rút tiền
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Withdraw Modal -->
    <div id="withdrawModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">Xác nhận rút tiền</div>
            </div>
            <div class="modal-body">
                <p>Bạn có chắc chắn muốn rút <strong id="confirmAmount">0đ</strong>?</p>
                <p style="color: #6b7280; font-size: 14px; margin-top: 8px;">
                    Yêu cầu sẽ được xử lý trong 1-3 ngày làm việc.
                </p>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeModal()">Hủy</button>
                <button class="btn btn-primary" onclick="confirmWithdraw()">Xác nhận</button>
            </div>
        </div>
    </div>

    <script>
    // Change period filter
    function changePeriod(period) {
        const url = new URL(window.location);
        url.searchParams.set('period', period);
        window.location = url;
    }

    // Open withdraw modal
    function openWithdrawModal() {
        document.getElementById('withdrawModal').classList.add('show');
    }

    // Close modal
    function closeModal() {
        document.getElementById('withdrawModal').classList.remove('show');
    }

    // View transaction history (scroll to transactions)
    function viewTransactionHistory() {
        document.querySelector('.transactions-section').scrollIntoView({
            behavior: 'smooth'
        });
    }

    // Revenue Chart
    const chartData = <?php echo json_encode(array_reverse($chart_data)); ?>;
    const labels = chartData.map(item => {
        const date = new Date(item.date);
        return date.toLocaleDateString('vi-VN', {
            month: 'short',
            day: 'numeric'
        });
    });
    const revenues = chartData.map(item => parseFloat(item.revenue));

    const ctx = document.getElementById('revenueChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Doanh thu (đ)',
                data: revenues,
                borderColor: '#ff0050',
                backgroundColor: 'rgba(255, 0, 80, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#ff0050',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 6,
                pointHoverRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('vi-VN').format(value) + 'đ';
                        }
                    },
                    grid: {
                        color: '#f3f4f6'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            },
            elements: {
                point: {
                    hoverRadius: 8
                }
            }
        }
    });

    // Format currency
    function formatCurrency(amount) {
        return new Intl.NumberFormat('vi-VN').format(amount) + 'đ';
    }

    // Auto refresh every 5 minutes
    setInterval(function() {
        window.location.reload();
    }, 300000);

    // Sidebar toggle for mobile
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            sidebar.classList.toggle('open');
        }
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
        if (e.ctrlKey || e.metaKey) {
            if (e.key === 'r') {
                e.preventDefault();
                window.location.reload();
            }
        }
    });

    // Close modal when clicking outside
    document.getElementById('withdrawModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // Smooth animations on scroll
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    // Observe stat cards
    document.querySelectorAll('.stat-card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(card);
    });
    </script>
</body>

</html>