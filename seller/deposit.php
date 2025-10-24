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

// Get available payment methods
try {
    $stmt = $pdo->prepare("SELECT * FROM payment_methods WHERE active = 1 ORDER BY name");
    $stmt->execute();
    $payment_methods = $stmt->fetchAll();
} catch (PDOException $e) {
    $payment_methods = [];
}

// Get manual payment methods
try {
    $stmt = $pdo->prepare("SELECT * FROM manual_payment_methods ORDER BY heading");
    $stmt->execute();
    $manual_payment_methods = $stmt->fetchAll();
} catch (PDOException $e) {
    $manual_payment_methods = [];
}

// Handle deposit form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_deposit'])) {
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    $payment_details = $_POST['payment_details'] ?? '';

    // Validation
    if ($amount <= 0) {
        $error_message = "Số tiền nạp phải lớn hơn 0.";
    } elseif ($amount < 10000) {
        $error_message = "Số tiền nạp tối thiểu là 10,000đ.";
    } elseif ($amount > 100000000) {
        $error_message = "Số tiền nạp tối đa là 100,000,000đ.";
    } elseif (empty($payment_method)) {
        $error_message = "Vui lòng chọn phương thức thanh toán.";
    } else {
        try {
            // Handle file upload for receipt
            $receipt_filename = null;
            if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/receipts/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $file_extension = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];

                if (in_array($file_extension, $allowed_extensions)) {
                    $receipt_filename = 'receipt_' . $seller_id . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $receipt_filename;

                    if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $upload_path)) {
                        $receipt_filename = null;
                    }
                }
            }

            // Insert wallet transaction
            $stmt = $pdo->prepare("
                INSERT INTO wallets (user_id, amount, payment_method, payment_details, approval, offline_payment, reciept) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $is_offline = in_array($payment_method, ['bank_transfer', 'manual']);
            $approval_status = $is_offline ? 0 : 1; // Auto approve for online payments

            $stmt->execute([
                $seller_id,
                $amount,
                $payment_method,
                $payment_details,
                $approval_status,
                $is_offline ? 1 : 0,
                $receipt_filename
            ]);

            // If auto-approved, update user balance
            if ($approval_status == 1) {
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$amount, $seller_id]);
                $current_balance += $amount;
                $success_message = "Nạp tiền thành công! Số dư đã được cập nhật.";
            } else {
                $success_message = "Yêu cầu nạp tiền đã được gửi. Chúng tôi sẽ xử lý trong vòng 24h.";
            }

        } catch (PDOException $e) {
            $error_message = "Có lỗi xảy ra khi xử lý giao dịch. Vui lòng thử lại.";
            error_log("Deposit error: " . $e->getMessage());
        }
    }
}

// Get deposit history
try {
    $stmt = $pdo->prepare("
        SELECT w.*, pm.name as payment_method_name
        FROM wallets w
        LEFT JOIN payment_methods pm ON w.payment_method = pm.name
        WHERE w.user_id = ? 
        ORDER BY w.created_at DESC 
        LIMIT 20
    ");
    $stmt->execute([$seller_id]);
    $deposit_history = $stmt->fetchAll();
} catch (PDOException $e) {
    $deposit_history = [];
}

// Format status text
function getDepositStatusText($approval, $offline)
{
    if ($approval == 1) {
        return 'Đã duyệt';
    } elseif ($offline == 1) {
        return 'Chờ duyệt';
    } else {
        return 'Đã hủy';
    }
}

function getDepositStatusClass($approval, $offline)
{
    if ($approval == 1) {
        return 'status-approved';
    } elseif ($offline == 1) {
        return 'status-pending';
    } else {
        return 'status-cancelled';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nạp tiền - TikTok Shop Seller</title>
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
    }

    .balance-title {
        font-size: 16px;
        opacity: 0.9;
        margin-bottom: 8px;
    }

    .balance-amount {
        font-size: 36px;
        font-weight: 700;
        margin-bottom: 16px;
    }

    .balance-actions {
        display: flex;
        gap: 16px;
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
        gap: 8px;
    }

    .balance-btn:hover {
        background: rgba(255, 255, 255, 0.3);
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

    /* Deposit Form */
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

    .form-file {
        width: 100%;
        padding: 12px 16px;
        border: 2px dashed #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s ease;
        background: #f9fafb;
    }

    .form-file:hover {
        border-color: #ff0050;
        background: #fef2f2;
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

    /* Deposit History */
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

    .status-approved {
        background: #dcfce7;
        color: #166534;
    }

    .status-pending {
        background: #fef3c7;
        color: #92400e;
    }

    .status-cancelled {
        background: #fee2e2;
        color: #dc2626;
    }

    .payment-info {
        font-size: 12px;
        color: #6b7280;
        margin-bottom: 8px;
    }

    .receipt-link {
        color: #ff0050;
        text-decoration: none;
        font-size: 12px;
    }

    .receipt-link:hover {
        text-decoration: underline;
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

    /* Responsive */
    @media (max-width: 1200px) {
        .main-grid {
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

        .balance-overview {
            padding: 24px;
        }

        .balance-amount {
            font-size: 28px;
        }

        .balance-actions {
            flex-direction: column;
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
                    <h1>Nạp tiền</h1>
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
                <!-- Balance Overview -->
                <div class="balance-overview">
                    <div class="balance-content">
                        <div class="balance-title">Số dư hiện tại</div>
                        <div class="balance-amount"><?php echo number_format($current_balance, 0, ',', '.'); ?>đ</div>
                        <div class="balance-actions">
                            <a href="withdraw.php" class="balance-btn">
                                <span>💳</span>
                                Rút tiền
                            </a>
                            <a href="finance.php" class="balance-btn">
                                <span>📊</span>
                                Lịch sử giao dịch
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Main Grid -->
                <div class="main-grid">
                    <!-- Deposit Form -->
                    <div class="section">
                        <div class="section-header">
                            <h2 class="section-title">Nạp tiền vào tài khoản</h2>
                            <p class="section-subtitle">Chọn phương thức thanh toán và nhập số tiền cần nạp</p>
                        </div>

                        <div class="section-content">
                            <?php if ($success_message): ?>
                            <div class="message success"><?php echo $success_message; ?></div>
                            <?php endif; ?>

                            <?php if ($error_message): ?>
                            <div class="message error"><?php echo $error_message; ?></div>
                            <?php endif; ?>

                            <form method="POST" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label class="form-label">Số tiền cần nạp (VNĐ)</label>
                                    <input type="number" name="amount" class="form-input" placeholder="Nhập số tiền..."
                                        min="10000" max="100000000" step="1000" required>
                                    <div class="amount-suggestions">
                                        <div class="amount-btn" onclick="setAmount(50000)">50,000đ</div>
                                        <div class="amount-btn" onclick="setAmount(100000)">100,000đ</div>
                                        <div class="amount-btn" onclick="setAmount(500000)">500,000đ</div>
                                        <div class="amount-btn" onclick="setAmount(1000000)">1,000,000đ</div>
                                        <div class="amount-btn" onclick="setAmount(5000000)">5,000,000đ</div>
                                        <div class="amount-btn" onclick="setAmount(10000000)">10,000,000đ</div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Phương thức thanh toán</label>
                                    <select name="payment_method" class="form-select" required
                                        onchange="togglePaymentInfo(this.value)">
                                        <option value="">Chọn phương thức...</option>
                                        <option value="bank_transfer">Chuyển khoản ngân hàng</option>
                                        <option value="momo">Ví MoMo</option>
                                        <option value="zalopay">ZaloPay</option>
                                        <option value="vnpay">VNPay</option>
                                        <?php foreach ($manual_payment_methods as $method): ?>
                                        <option value="manual_<?php echo $method['id']; ?>">
                                            <?php echo htmlspecialchars($method['heading']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div id="payment-info" style="display: none;">
                                    <div class="form-group">
                                        <label class="form-label">Thông tin thanh toán</label>
                                        <div id="payment-details"></div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Ghi chú (tùy chọn)</label>
                                    <textarea name="payment_details" class="form-textarea"
                                        placeholder="Nhập ghi chú về giao dịch..."></textarea>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Ảnh biên lai (tùy chọn)</label>
                                    <input type="file" name="receipt" class="form-file" accept="image/*,.pdf">
                                    <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                                        Hỗ trợ: JPG, PNG, PDF (tối đa 5MB)
                                    </div>
                                </div>

                                <button type="submit" name="submit_deposit" class="submit-btn">
                                    Xác nhận nạp tiền
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Deposit History -->
                    <div class="section">
                        <div class="section-header">
                            <h2 class="section-title">Lịch sử nạp tiền</h2>
                            <p class="section-subtitle">20 giao dịch gần nhất</p>
                        </div>

                        <div class="section-content" style="padding: 0; overflow-x: auto;">
                            <?php if (empty($deposit_history)): ?>
                            <div class="no-data">
                                <svg viewBox="0 0 24 24" fill="currentColor">
                                    <path
                                        d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                                </svg>
                                <p>Chưa có giao dịch nạp tiền nào</p>
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
                                    <?php foreach ($deposit_history as $transaction): ?>
                                    <tr>
                                        <td>
                                            <div><?php echo date('d/m/Y', strtotime($transaction['created_at'])); ?>
                                            </div>
                                            <div style="font-size: 12px; color: #6b7280;">
                                                <?php echo date('H:i', strtotime($transaction['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: #ff0050;">
                                                +<?php echo number_format($transaction['amount'], 0, ',', '.'); ?>đ
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <?php echo htmlspecialchars($transaction['payment_method'] ?? 'N/A'); ?>
                                            </div>
                                            <?php if ($transaction['payment_details']): ?>
                                            <div class="payment-info">
                                                <?php echo htmlspecialchars(substr($transaction['payment_details'], 0, 50)); ?>
                                                <?php if (strlen($transaction['payment_details']) > 50)
                                                                echo '...'; ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($transaction['reciept']): ?>
                                            <a href="../uploads/receipts/<?php echo htmlspecialchars($transaction['reciept']); ?>"
                                                target="_blank" class="receipt-link">Xem biên lai</a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span
                                                class="status-badge <?php echo getDepositStatusClass($transaction['approval'], $transaction['offline_payment']); ?>">
                                                <?php echo getDepositStatusText($transaction['approval'], $transaction['offline_payment']); ?>
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

    // Toggle payment info
    function togglePaymentInfo(method) {
        const paymentInfo = document.getElementById('payment-info');
        const paymentDetails = document.getElementById('payment-details');

        if (!method) {
            paymentInfo.style.display = 'none';
            return;
        }

        let info = '';
        switch (method) {
            case 'bank_transfer':
                info = `
                    <div style="background: #f3f4f6; padding: 16px; border-radius: 8px; font-size: 14px;">
                        <strong>Thông tin chuyển khoản:</strong><br>
                        Ngân hàng: Vietcombank<br>
                        Số tài khoản: 0123456789<br>
                        Chủ tài khoản: TIKTOK SHOP VIETNAM<br>
                        Nội dung: NAPTIEP_${<?php echo $seller_id; ?>}
                    </div>
                `;
                break;
            case 'momo':
                info = `
                    <div style="background: #f3f4f6; padding: 16px; border-radius: 8px; font-size: 14px;">
                        <strong>Thông tin MoMo:</strong><br>
                        Số điện thoại: 0123456789<br>
                        Tên: TIKTOK SHOP<br>
                        Nội dung: NAPTIEP_${<?php echo $seller_id; ?>}
                    </div>
                `;
                break;
            default:
                if (method.startsWith('manual_')) {
                    const methodId = method.replace('manual_', '');
                    <?php foreach ($manual_payment_methods as $method): ?>
                    if (methodId == '<?php echo $method['id']; ?>') {
                        info = `
                            <div style="background: #f3f4f6; padding: 16px; border-radius: 8px; font-size: 14px;">
                                <strong><?php echo htmlspecialchars($method['heading']); ?>:</strong><br>
                                <?php echo nl2br(htmlspecialchars($method['description'] ?? '')); ?><br>
                                <?php if ($method['bank_info']): ?>
                                <div style="margin-top: 8px;">
                                    <?php echo nl2br(htmlspecialchars($method['bank_info'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        `;
                    }
                    <?php endforeach; ?>
                }
                break;
        }

        if (info) {
            paymentDetails.innerHTML = info;
            paymentInfo.style.display = 'block';
        } else {
            paymentInfo.style.display = 'none';
        }
    }

    // Format number input
    document.querySelector('input[name="amount"]').addEventListener('input', function(e) {
        let value = e.target.value.replace(/[^0-9]/g, '');
        e.target.value = value;
    });

    // File upload validation
    document.querySelector('input[name="receipt"]').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const maxSize = 5 * 1024 * 1024; // 5MB
            if (file.size > maxSize) {
                alert('File quá lớn. Vui lòng chọn file nhỏ hơn 5MB.');
                e.target.value = '';
            }
        }
    });
    </script>
</body>

</html>