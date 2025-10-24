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

// Messages
$success_message = '';
$error_message = '';

// Get seller current balance
try {
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$seller_id]);
    $current_balance = $stmt->fetchColumn() ?? 0;
} catch (PDOException $e) {
    $current_balance = 0;
    $error_message = "Không thể tải thông tin số dư.";
}

// Get manual payment methods for withdrawal
try {
    $stmt = $pdo->prepare("SELECT * FROM manual_payment_methods ORDER BY heading");
    $stmt->execute();
    $payment_methods = $stmt->fetchAll();
} catch (PDOException $e) {
    $payment_methods = [];
}

// Handle withdrawal request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_withdrawal'])) {
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $account_holder = trim($_POST['account_holder'] ?? '');
    $withdrawal_note = trim($_POST['withdrawal_note'] ?? '');
    
    // Validation
    if ($amount <= 0) {
        $error_message = "Số tiền rút phải lớn hơn 0.";
    } elseif ($amount < 50000) {
        $error_message = "Số tiền rút tối thiểu là 50,000đ.";
    } elseif ($amount > $current_balance) {
        $error_message = "Số tiền rút không được vượt quá số dư hiện tại.";
    } elseif ($amount > 50000000) {
        $error_message = "Số tiền rút tối đa là 50,000,000đ mỗi lần.";
    } elseif (empty($payment_method)) {
        $error_message = "Vui lòng chọn phương thức rút tiền.";
    } elseif (in_array($payment_method, ['bank_transfer', 'bank']) && (empty($bank_name) || empty($account_number) || empty($account_holder))) {
        $error_message = "Vui lòng điền đầy đủ thông tin ngân hàng.";
    } else {
        try {
            // Check daily withdrawal limit (example: 10M per day)
            $today_start = date('Y-m-d 00:00:00');
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(ABS(amount)), 0) as today_withdrawn
                FROM wallets 
                WHERE user_id = ? AND amount < 0 AND created_at >= ? AND approval = 1
            ");
            $stmt->execute([$seller_id, $today_start]);
            $today_withdrawn = $stmt->fetchColumn();
            
            $daily_limit = 10000000; // 10M VND
            if (($today_withdrawn + $amount) > $daily_limit) {
                $error_message = "Vượt quá hạn mức rút tiền hàng ngày (" . number_format($daily_limit, 0, ',', '.') . "đ).";
            } else {
                // Prepare payment details
                $payment_details = json_encode([
                    'type' => 'withdrawal',
                    'method' => $payment_method,
                    'bank_name' => $bank_name,
                    'account_number' => $account_number,
                    'account_holder' => $account_holder,
                    'note' => $withdrawal_note,
                    'requested_at' => date('Y-m-d H:i:s')
                ]);
                
                // Insert withdrawal request (negative amount in wallets table)
                $stmt = $pdo->prepare("
                    INSERT INTO wallets (user_id, amount, payment_method, payment_details, approval, offline_payment) 
                    VALUES (?, ?, ?, ?, 0, 1)
                ");
                $stmt->execute([
                    $seller_id,
                    -$amount, // Negative for withdrawal
                    $payment_method,
                    $payment_details
                ]);
                
                $success_message = "Yêu cầu rút tiền đã được gửi thành công! Chúng tôi sẽ xử lý trong vòng 24-48h.";
            }
        } catch (PDOException $e) {
            $error_message = "Có lỗi xảy ra khi xử lý yêu cầu rút tiền. Vui lòng thử lại.";
            error_log("Withdrawal error: " . $e->getMessage());
        }
    }
}

// Get withdrawal history
try {
    $stmt = $pdo->prepare("
        SELECT w.*, pm.name as payment_method_name
        FROM wallets w
        LEFT JOIN payment_methods pm ON w.payment_method = pm.name
        WHERE w.user_id = ? AND w.amount < 0
        ORDER BY w.created_at DESC 
        LIMIT 20
    ");
    $stmt->execute([$seller_id]);
    $withdrawal_history = $stmt->fetchAll();
} catch (PDOException $e) {
    $withdrawal_history = [];
}

// Get withdrawal statistics
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_requests,
            COALESCE(SUM(CASE WHEN approval = 1 THEN ABS(amount) ELSE 0 END), 0) as total_withdrawn,
            COALESCE(SUM(CASE WHEN approval = 0 AND offline_payment = 1 THEN ABS(amount) ELSE 0 END), 0) as pending_amount,
            COUNT(CASE WHEN approval = 0 AND offline_payment = 1 THEN 1 END) as pending_requests
        FROM wallets 
        WHERE user_id = ? AND amount < 0
    ");
    $stmt->execute([$seller_id]);
    $withdrawal_stats = $stmt->fetch();
} catch (PDOException $e) {
    $withdrawal_stats = [
        'total_requests' => 0,
        'total_withdrawn' => 0,
        'pending_amount' => 0,
        'pending_requests' => 0
    ];
}

// Format status text
function getWithdrawalStatusText($approval, $offline) {
    if ($approval == 1) {
        return 'Đã chuyển tiền';
    } elseif ($approval == -1) {
        return 'Đã từ chối';
    } elseif ($offline == 1) {
        return 'Đang xử lý';
    } else {
        return 'Đã hủy';
    }
}

function getWithdrawalStatusClass($approval, $offline) {
    if ($approval == 1) {
        return 'status-completed';
    } elseif ($approval == -1) {
        return 'status-rejected';
    } elseif ($offline == 1) {
        return 'status-pending';
    } else {
        return 'status-cancelled';
    }
}

// Bank list for Vietnam
$vietnamese_banks = [
    'Vietcombank' => 'Ngân hàng TMCP Ngoại thương Việt Nam',
    'VietinBank' => 'Ngân hàng TMCP Công thương Việt Nam',
    'BIDV' => 'Ngân hàng TMCP Đầu tư và Phát triển Việt Nam',
    'Agribank' => 'Ngân hàng Nông nghiệp và Phát triển Nông thôn',
    'Techcombank' => 'Ngân hàng TMCP Kỹ thương Việt Nam',
    'MBBank' => 'Ngân hàng TMCP Quân đội',
    'VPBank' => 'Ngân hàng TMCP Việt Nam Thịnh vượng',
    'ACB' => 'Ngân hàng TMCP Á Châu',
    'TPBank' => 'Ngân hàng TMCP Tiên Phong',
    'SHB' => 'Ngân hàng TMCP Sài Gòn - Hà Nội'
];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rút tiền - TikTok Shop Seller</title>
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

    .header-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .user-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        background: linear-gradient(45deg, #ff0050, #ff4d6d);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 16px;
    }

    .user-details h3 {
        font-size: 14px;
        font-weight: 600;
        color: #1f2937;
        margin: 0;
    }

    .user-balance {
        font-size: 12px;
        color: #10b981;
        font-weight: 500;
    }

    .content-wrapper {
        padding: 24px;
    }

    /* Balance Overview */
    .balance-overview {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 32px;
        border-radius: 16px;
        margin-bottom: 24px;
        position: relative;
        overflow: hidden;
    }

    .balance-overview::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 120px;
        height: 120px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        transform: translate(30%, -30%);
    }

    .balance-content {
        position: relative;
        z-index: 2;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 24px;
        align-items: center;
    }

    .balance-info h2 {
        font-size: 16px;
        opacity: 0.9;
        margin-bottom: 8px;
    }

    .balance-amount {
        font-size: 36px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .balance-note {
        font-size: 14px;
        opacity: 0.8;
    }

    .balance-actions {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .balance-btn {
        background: rgba(255, 255, 255, 0.2);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-width: 140px;
    }

    .balance-btn:hover {
        background: rgba(255, 255, 255, 0.3);
    }

    /* Statistics Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        text-align: center;
        transition: all 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .stat-card .icon {
        width: 48px;
        height: 48px;
        margin: 0 auto 12px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }

    .stat-card h3 {
        font-size: 14px;
        color: #6b7280;
        margin-bottom: 8px;
        font-weight: 500;
    }

    .stat-card .number {
        font-size: 24px;
        font-weight: 700;
        color: #1f2937;
    }

    .stat-card.total .icon {
        background: linear-gradient(45deg, #3b82f6, #60a5fa);
    }

    .stat-card.withdrawn .icon {
        background: linear-gradient(45deg, #10b981, #34d399);
    }

    .stat-card.pending .icon {
        background: linear-gradient(45deg, #f59e0b, #fbbf24);
    }

    .stat-card.available .icon {
        background: linear-gradient(45deg, #8b5cf6, #a78bfa);
    }

    /* Main Grid */
    .main-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
    }

    .section {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    .section-header {
        padding: 24px;
        border-bottom: 1px solid #e5e7eb;
    }

    .section-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 4px;
    }

    .section-subtitle {
        font-size: 14px;
        color: #6b7280;
    }

    .section-content {
        padding: 24px;
    }

    /* Form Styles */
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

    .form-input {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .form-input:focus {
        outline: none;
        border-color: #ff0050;
        box-shadow: 0 0 0 3px rgba(255, 0, 80, 0.1);
    }

    .form-select {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        background: white;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .form-select:focus {
        outline: none;
        border-color: #ff0050;
        box-shadow: 0 0 0 3px rgba(255, 0, 80, 0.1);
    }

    .form-textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        resize: vertical;
        min-height: 80px;
        transition: all 0.3s ease;
    }

    .form-textarea:focus {
        outline: none;
        border-color: #ff0050;
        box-shadow: 0 0 0 3px rgba(255, 0, 80, 0.1);
    }

    .amount-suggestions {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        margin-top: 8px;
    }

    .amount-btn {
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: white;
        color: #374151;
        text-align: center;
        cursor: pointer;
        font-size: 12px;
        transition: all 0.3s ease;
    }

    .amount-btn:hover {
        border-color: #ff0050;
        background: #fef2f2;
        color: #ff0050;
    }

    .submit-btn {
        width: 100%;
        padding: 14px;
        background: #ff0050;
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-top: 20px;
    }

    .submit-btn:hover {
        background: #dc2626;
        transform: translateY(-1px);
    }

    .submit-btn:disabled {
        background: #9ca3af;
        cursor: not-allowed;
        transform: none;
    }

    /* Bank Info Section */
    .bank-info {
        display: none;
        padding: 16px;
        background: #f9fafb;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        margin-top: 12px;
    }

    .bank-info.show {
        display: block;
    }

    .bank-info h4 {
        font-size: 14px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 12px;
    }

    /* Withdrawal History */
    .history-table {
        width: 100%;
        border-collapse: collapse;
    }

    .history-table th {
        text-align: left;
        padding: 12px;
        font-size: 12px;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
        background: #f9fafb;
        border-bottom: 1px solid #e5e7eb;
    }

    .history-table td {
        padding: 12px;
        border-bottom: 1px solid #f3f4f6;
        font-size: 14px;
    }

    .history-table tr:hover {
        background: #f9fafb;
    }

    .status-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
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

    .status-rejected {
        background: #fee2e2;
        color: #dc2626;
    }

    .status-cancelled {
        background: #f3f4f6;
        color: #6b7280;
    }

    .withdrawal-amount {
        font-weight: 600;
        color: #dc2626;
    }

    .withdrawal-info {
        font-size: 12px;
        color: #6b7280;
        margin-top: 4px;
    }

    /* Messages */
    .message {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
        font-weight: 500;
    }

    .message.success {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #34d399;
    }

    .message.error {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #f87171;
    }

    .no-data {
        text-align: center;
        padding: 40px;
        color: #9ca3af;
    }

    .no-data svg {
        width: 48px;
        height: 48px;
        margin-bottom: 12px;
        opacity: 0.5;
    }

    .withdrawal-limits {
        background: #fffbeb;
        border: 1px solid #fbbf24;
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 20px;
    }

    .withdrawal-limits h4 {
        font-size: 14px;
        font-weight: 600;
        color: #92400e;
        margin-bottom: 8px;
    }

    .withdrawal-limits ul {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .withdrawal-limits li {
        font-size: 12px;
        color: #92400e;
        padding: 2px 0;
        position: relative;
        padding-left: 16px;
    }

    .withdrawal-limits li::before {
        content: '•';
        position: absolute;
        left: 0;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .main-grid {
            grid-template-columns: 1fr;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .balance-content {
            grid-template-columns: 1fr;
            text-align: center;
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

        .balance-overview {
            padding: 24px;
        }

        .balance-amount {
            font-size: 28px;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .amount-suggestions {
            grid-template-columns: repeat(2, 1fr);
        }

        .user-details {
            display: none;
        }

        .history-table {
            font-size: 12px;
        }

        .history-table th,
        .history-table td {
            padding: 8px;
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
                    <h1>Rút tiền</h1>
                </div>
                <div class="header-right">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($seller_name, 0, 1)); ?>
                        </div>
                        <div class="user-details">
                            <h3><?php echo htmlspecialchars($seller_name); ?></h3>
                            <div class="user-balance">Số dư:
                                <?php echo number_format($current_balance, 0, ',', '.'); ?>đ
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-wrapper">
                <!-- Messages -->
                <?php if ($success_message): ?>
                <div class="message success"><?php echo $success_message; ?></div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="message error"><?php echo $error_message; ?></div>
                <?php endif; ?>

                <!-- Balance Overview -->
                <div class="balance-overview">
                    <div class="balance-content">
                        <div class="balance-info">
                            <h2>Số dư khả dụng</h2>
                            <div class="balance-amount"><?php echo number_format($current_balance, 0, ',', '.'); ?>đ
                            </div>
                            <div class="balance-note">Có thể rút ngay</div>
                        </div>
                        <div class="balance-actions">
                            <a href="deposit.php" class="balance-btn">
                                <span>💳</span>
                                Nạp tiền
                            </a>
                            <a href="finance.php" class="balance-btn">
                                <span>📊</span>
                                Lịch sử giao dịch
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card total">
                        <div class="icon">📋</div>
                        <h3>Tổng yêu cầu</h3>
                        <div class="number"><?php echo $withdrawal_stats['total_requests']; ?></div>
                    </div>
                    <div class="stat-card withdrawn">
                        <div class="icon">✅</div>
                        <h3>Đã rút</h3>
                        <div class="number">
                            <?php echo number_format($withdrawal_stats['total_withdrawn'], 0, ',', '.'); ?>đ</div>
                    </div>
                    <div class="stat-card pending">
                        <div class="icon">⏳</div>
                        <h3>Đang xử lý</h3>
                        <div class="number"><?php echo $withdrawal_stats['pending_requests']; ?></div>
                    </div>
                    <div class="stat-card available">
                        <div class="icon">💰</div>
                        <h3>Khả dụng</h3>
                        <div class="number"><?php echo number_format($current_balance, 0, ',', '.'); ?>đ</div>
                    </div>
                </div>

                <!-- Main Grid -->
                <div class="main-grid">
                    <!-- Withdrawal Form -->
                    <div class="section">
                        <div class="section-header">
                            <h2 class="section-title">Yêu cầu rút tiền</h2>
                            <p class="section-subtitle">Điền thông tin để tạo yêu cầu rút tiền</p>
                        </div>

                        <div class="section-content">
                            <div class="withdrawal-limits">
                                <h4>Quy định rút tiền:</h4>
                                <ul>
                                    <li>Số tiền rút tối thiểu: 50,000đ</li>
                                    <li>Số tiền rút tối đa: 50,000,000đ/lần</li>
                                    <li>Hạn mức hàng ngày: 10,000,000đ</li>
                                    <li>Thời gian xử lý: 24-48 giờ</li>
                                    <li>Phí rút tiền: Miễn phí</li>
                                </ul>
                            </div>

                            <form method="POST">
                                <div class="form-group">
                                    <label class="form-label">Số tiền cần rút (VNĐ)</label>
                                    <input type="number" name="amount" class="form-input" placeholder="Nhập số tiền..."
                                        min="50000" max="<?php echo min($current_balance, 50000000); ?>" step="1000"
                                        required>
                                    <div class="amount-suggestions">
                                        <div class="amount-btn" onclick="setAmount(500000)">500,000đ</div>
                                        <div class="amount-btn" onclick="setAmount(1000000)">1,000,000đ</div>
                                        <div class="amount-btn" onclick="setAmount(5000000)">5,000,000đ</div>
                                        <div class="amount-btn" onclick="setAmount(10000000)">10,000,000đ</div>
                                        <div class="amount-btn" onclick="setAmount(<?php echo $current_balance; ?>)">Tất
                                            cả</div>
                                        <div class="amount-btn" onclick="setCustomAmount()">Khác</div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Phương thức rút tiền</label>
                                    <select name="payment_method" class="form-select" required
                                        onchange="toggleBankInfo(this.value)">
                                        <option value="">Chọn phương thức...</option>
                                        <option value="bank_transfer">Chuyển khoản ngân hàng</option>
                                        <option value="momo">Ví MoMo</option>
                                        <option value="zalopay">ZaloPay</option>
                                        <?php foreach ($payment_methods as $method): ?>
                                        <option value="manual_<?php echo $method['id']; ?>">
                                            <?php echo htmlspecialchars($method['heading']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div id="bank-info" class="bank-info">
                                    <h4>Thông tin ngân hàng</h4>
                                    <div class="form-group">
                                        <label class="form-label">Tên ngân hàng</label>
                                        <select name="bank_name" class="form-select">
                                            <option value="">Chọn ngân hàng...</option>
                                            <?php foreach ($vietnamese_banks as $code => $name): ?>
                                            <option value="<?php echo $code; ?>"><?php echo $name; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Số tài khoản</label>
                                        <input type="text" name="account_number" class="form-input"
                                            placeholder="Nhập số tài khoản...">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Tên chủ tài khoản</label>
                                        <input type="text" name="account_holder" class="form-input"
                                            placeholder="Nhập tên chủ tài khoản..."
                                            value="<?php echo htmlspecialchars($seller_name); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Ghi chú (tùy chọn)</label>
                                    <textarea name="withdrawal_note" class="form-textarea"
                                        placeholder="Nhập ghi chú về yêu cầu rút tiền..."></textarea>
                                </div>

                                <button type="submit" name="submit_withdrawal" class="submit-btn"
                                    <?php echo $current_balance < 50000 ? 'disabled' : ''; ?>>
                                    <?php if ($current_balance < 50000): ?>
                                    Số dư không đủ
                                    <?php else: ?>
                                    Tạo yêu cầu rút tiền
                                    <?php endif; ?>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Withdrawal History -->
                    <div class="section">
                        <div class="section-header">
                            <h2 class="section-title">Lịch sử rút tiền</h2>
                            <p class="section-subtitle">20 yêu cầu gần nhất</p>
                        </div>

                        <div class="section-content" style="padding: 0; overflow-x: auto;">
                            <?php if (empty($withdrawal_history)): ?>
                            <div class="no-data">
                                <svg viewBox="0 0 24 24" fill="currentColor">
                                    <path
                                        d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                                </svg>
                                <p>Chưa có yêu cầu rút tiền nào</p>
                            </div>
                            <?php else: ?>
                            <table class="history-table">
                                <thead>
                                    <tr>
                                        <th>Ngày</th>
                                        <th>Số tiền</th>
                                        <th>Phương thức</th>
                                        <th>Trạng thái</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($withdrawal_history as $transaction): ?>
                                    <tr>
                                        <td>
                                            <div><?php echo date('d/m/Y', strtotime($transaction['created_at'])); ?>
                                            </div>
                                            <div style="font-size: 12px; color: #6b7280;">
                                                <?php echo date('H:i', strtotime($transaction['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="withdrawal-amount">
                                                <?php echo number_format(abs($transaction['amount']), 0, ',', '.'); ?>đ
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <?php echo htmlspecialchars($transaction['payment_method'] ?? 'N/A'); ?>
                                            </div>
                                            <?php if ($transaction['payment_details']): ?>
                                            <?php 
                                                $details = json_decode($transaction['payment_details'], true);
                                                if ($details && isset($details['bank_name'], $details['account_number'])):
                                            ?>
                                            <div class="withdrawal-info">
                                                <?php echo htmlspecialchars($details['bank_name']); ?><br>
                                                ***<?php echo substr($details['account_number'], -4); ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span
                                                class="status-badge <?php echo getWithdrawalStatusClass($transaction['approval'], $transaction['offline_payment']); ?>">
                                                <?php echo getWithdrawalStatusText($transaction['approval'], $transaction['offline_payment']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Set amount suggestion
    function setAmount(amount) {
        document.querySelector('input[name="amount"]').value = amount;
    }

    function setCustomAmount() {
        const input = document.querySelector('input[name="amount"]');
        input.focus();
        input.select();
    }

    // Toggle bank info section
    function toggleBankInfo(method) {
        const bankInfo = document.getElementById('bank-info');
        const bankFields = bankInfo.querySelectorAll('input, select');

        if (method === 'bank_transfer' || method === 'bank') {
            bankInfo.classList.add('show');
            // Make bank fields required
            bankFields.forEach(field => {
                if (field.name === 'bank_name' || field.name === 'account_number' || field.name ===
                    'account_holder') {
                    field.required = true;
                }
            });
        } else {
            bankInfo.classList.remove('show');
            // Remove required attribute
            bankFields.forEach(field => {
                field.required = false;
                field.value = '';
            });
        }
    }

    // Format number input
    document.querySelector('input[name="amount"]').addEventListener('input', function(e) {
        let value = e.target.value.replace(/[^0-9]/g, '');
        e.target.value = value;
    });

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const amount = parseFloat(document.querySelector('input[name="amount"]').value) || 0;
        const currentBalance = <?php echo $current_balance; ?>;
        const method = document.querySelector('select[name="payment_method"]').value;

        if (amount < 50000) {
            e.preventDefault();
            alert('Số tiền rút tối thiểu là 50,000đ.');
            return;
        }

        if (amount > currentBalance) {
            e.preventDefault();
            alert('Số tiền rút không được vượt quá số dư hiện tại.');
            return;
        }

        if (amount > 50000000) {
            e.preventDefault();
            alert('Số tiền rút tối đa là 50,000,000đ mỗi lần.');
            return;
        }

        if (!method) {
            e.preventDefault();
            alert('Vui lòng chọn phương thức rút tiền.');
            return;
        }

        if (method === 'bank_transfer') {
            const bankName = document.querySelector('input[name="bank_name"]').value;
            const accountNumber = document.querySelector('input[name="account_number"]').value;
            const accountHolder = document.querySelector('input[name="account_holder"]').value;

            if (!bankName || !accountNumber || !accountHolder) {
                e.preventDefault();
                alert('Vui lòng điền đầy đủ thông tin ngân hàng.');
                return;
            }
        }

        // Confirm withdrawal
        const confirmMessage =
            `Xác nhận rút ${amount.toLocaleString('vi-VN')}đ?\nSố dư sau khi rút: ${(currentBalance - amount).toLocaleString('vi-VN')}đ`;
        if (!confirm(confirmMessage)) {
            e.preventDefault();
        }
    });

    // Account number formatting
    document.querySelector('input[name="account_number"]').addEventListener('input', function(e) {
        // Remove non-digits
        let value = e.target.value.replace(/[^0-9]/g, '');
        // Limit to reasonable account number length
        if (value.length > 20) {
            value = value.substring(0, 20);
        }
        e.target.value = value;
    });
    </script>
</body>

</html>