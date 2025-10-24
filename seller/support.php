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
    $seller_balance = $stmt->fetchColumn() ?? 0;
} catch (PDOException $e) {
    $seller_balance = 0;
}

// Handle support ticket submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {
    $subject = trim($_POST['subject'] ?? '');
    $category = $_POST['category'] ?? '';
    $priority = $_POST['priority'] ?? 'normal';
    $content = trim($_POST['content'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    // Validation
    if (empty($subject)) {
        $error_message = "Vui lòng nhập tiêu đề yêu cầu hỗ trợ.";
    } elseif (empty($category)) {
        $error_message = "Vui lòng chọn danh mục vấn đề.";
    } elseif (empty($content)) {
        $error_message = "Vui lòng mô tả chi tiết vấn đề bạn gặp phải.";
    } elseif (strlen($content) < 20) {
        $error_message = "Mô tả vấn đề phải có ít nhất 20 ký tự.";
    } else {
        try {
            // Handle file upload if exists
            $attachment_filename = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/support/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $file_extension = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'];

                if (in_array($file_extension, $allowed_extensions) && $_FILES['attachment']['size'] <= 10 * 1024 * 1024) {
                    $attachment_filename = 'support_' . $seller_id . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $attachment_filename;

                    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
                        $attachment_filename = null;
                    }
                }
            }

            // Get seller email for contact
            $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$seller_id]);
            $seller_email = $stmt->fetchColumn() ?? '';

            // Insert support ticket
            $ticket_content = "DANH MỤC: " . strtoupper($category) . "\n";
            $ticket_content .= "ĐỘ ưu TIÊN: " . strtoupper($priority) . "\n";
            $ticket_content .= "SELLER ID: " . $seller_id . "\n";
            $ticket_content .= "TÊN SELLER: " . $seller_name . "\n";
            if ($phone) {
                $ticket_content .= "SỐ ĐIỆN THOẠI: " . $phone . "\n";
            }
            $ticket_content .= "\n--- NỘI DUNG ---\n" . $content;

            if ($attachment_filename) {
                $ticket_content .= "\n\n--- FILE ĐÍNH KÈM ---\n" . $attachment_filename;
            }

            $stmt = $pdo->prepare("
                INSERT INTO contacts (name, email, phone, content, image) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $seller_name . " - " . $subject,
                $seller_email,
                $phone,
                $ticket_content,
                $attachment_filename
            ]);

            $ticket_id = $pdo->lastInsertId();
            $success_message = "Yêu cầu hỗ trợ đã được gửi thành công! Mã ticket: #" . str_pad($ticket_id, 6, '0', STR_PAD_LEFT) . ". Chúng tôi sẽ phản hồi trong vòng 24h.";

        } catch (PDOException $e) {
            $error_message = "Có lỗi xảy ra khi gửi yêu cầu hỗ trợ. Vui lòng thử lại.";
            error_log("Support ticket error: " . $e->getMessage());
        }
    }
}

// Get recent support tickets for this seller
try {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               CASE WHEN c.reply IS NOT NULL THEN 'Đã phản hồi' ELSE 'Đang xử lý' END as status
        FROM contacts c
        WHERE c.name LIKE ? OR c.content LIKE ?
        ORDER BY c.created_at DESC
        LIMIT 10
    ");
    $search_pattern = "%" . $seller_name . "%";
    $seller_pattern = "%SELLER ID: " . $seller_id . "%";
    $stmt->execute([$search_pattern, $seller_pattern]);
    $recent_tickets = $stmt->fetchAll();
} catch (PDOException $e) {
    $recent_tickets = [];
}

// Support categories
$support_categories = [
    'account' => 'Tài khoản & Đăng nhập',
    'products' => 'Quản lý sản phẩm',
    'orders' => 'Đơn hàng & Giao hàng',
    'payments' => 'Thanh toán & Rút tiền',
    'technical' => 'Lỗi kỹ thuật',
    'policy' => 'Chính sách & Quy định',
    'other' => 'Khác'
];

// Priority levels
$priority_levels = [
    'low' => 'Thấp',
    'normal' => 'Bình thường',
    'high' => 'Cao',
    'urgent' => 'Khẩn cấp'
];

// FAQ data
$faq_data = [
    [
        'question' => 'Làm thế nào để thêm sản phẩm mới?',
        'answer' => 'Bạn có thể thêm sản phẩm bằng cách vào menu "Các sản phẩm" > "Thêm sản phẩm". Điền đầy đủ thông tin sản phẩm, upload hình ảnh chất lượng cao và mô tả chi tiết để thu hút khách hàng.'
    ],
    [
        'question' => 'Tại sao đơn hàng của tôi bị hủy?',
        'answer' => 'Đơn hàng có thể bị hủy do nhiều lý do: sản phẩm hết hàng, thông tin giao hàng không chính xác, khách hàng thay đổi ý định, hoặc không thanh toán đúng hạn. Kiểm tra trạng thái cụ thể trong phần quản lý đơn hàng.'
    ],
    [
        'question' => 'Khi nào tôi nhận được tiền từ đơn hàng?',
        'answer' => 'Tiền từ đơn hàng sẽ được chuyển vào ví của bạn sau khi đơn hàng được giao thành công và khách hàng xác nhận. Thời gian giữ tiền thường là 7-14 ngày để đảm bảo chất lượng dịch vụ.'
    ],
    [
        'question' => 'Làm sao để tăng doanh số bán hàng?',
        'answer' => 'Một số tips: 1) Upload ảnh sản phẩm chất lượng cao, 2) Viết mô tả chi tiết và hấp dẫn, 3) Cập nhật giá cạnh tranh, 4) Phản hồi nhanh chóng với khách hàng, 5) Tham gia các chương trình khuyến mãi.'
    ],
    [
        'question' => 'Tôi quên mật khẩu đăng nhập, phải làm gì?',
        'answer' => 'Sử dụng tính năng "Quên mật khẩu" ở trang đăng nhập. Nhập email đã đăng ký và làm theo hướng dẫn trong email được gửi đến. Nếu không nhận được email, kiểm tra thư mục spam.'
    ],
    [
        'question' => 'Làm thế nào để rút tiền từ ví?',
        'answer' => 'Vào menu "Rút tiền", nhập số tiền cần rút (tối thiểu 50,000đ), chọn phương thức và điền thông tin ngân hàng. Yêu cầu sẽ được xử lý trong 24-48h làm việc.'
    ]
];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hỗ trợ nhanh - TikTok Shop Seller</title>
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

    /* Hero Section */
    .support-hero {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 40px;
        border-radius: 16px;
        margin-bottom: 32px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .support-hero::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 200px;
        height: 200px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        transform: translate(30%, -30%);
    }

    .hero-content {
        position: relative;
        z-index: 2;
    }

    .hero-title {
        font-size: 32px;
        font-weight: 700;
        margin-bottom: 12px;
    }

    .hero-subtitle {
        font-size: 18px;
        opacity: 0.9;
        margin-bottom: 24px;
    }

    .hero-stats {
        display: flex;
        justify-content: center;
        gap: 32px;
        margin-top: 24px;
    }

    .hero-stat {
        text-align: center;
    }

    .hero-stat .number {
        font-size: 24px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .hero-stat .label {
        font-size: 14px;
        opacity: 0.8;
    }

    /* Quick Actions */
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 32px;
    }

    .action-card {
        background: white;
        padding: 24px;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        text-align: center;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .action-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .action-icon {
        width: 48px;
        height: 48px;
        margin: 0 auto 16px;
        background: linear-gradient(45deg, #ff0050, #ff4d6d);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        color: white;
    }

    .action-title {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 8px;
    }

    .action-description {
        font-size: 14px;
        color: #6b7280;
        line-height: 1.4;
    }

    /* Main Grid */
    .main-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 32px;
    }

    /* Support Form */
    .support-section {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    .section-header {
        padding: 24px;
        border-bottom: 1px solid #e5e7eb;
        background: #f9fafb;
    }

    .section-title {
        font-size: 20px;
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
        min-height: 120px;
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
        text-align: center;
    }

    .form-file:hover {
        border-color: #ff0050;
        background: #fef2f2;
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }

    .form-note {
        font-size: 12px;
        color: #6b7280;
        margin-top: 4px;
    }

    .priority-selector {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
        margin-top: 8px;
    }

    .priority-option {
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

    .priority-option.selected {
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

    /* FAQ Section */
    .faq-item {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        margin-bottom: 12px;
        overflow: hidden;
    }

    .faq-question {
        padding: 16px 20px;
        background: #f9fafb;
        border: none;
        width: 100%;
        text-align: left;
        font-size: 14px;
        font-weight: 500;
        color: #1f2937;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.3s ease;
    }

    .faq-question:hover {
        background: #f3f4f6;
    }

    .faq-question .icon {
        transition: transform 0.3s ease;
    }

    .faq-question.active .icon {
        transform: rotate(180deg);
    }

    .faq-answer {
        padding: 0 20px;
        max-height: 0;
        overflow: hidden;
        transition: all 0.3s ease;
        background: white;
    }

    .faq-answer.show {
        padding: 16px 20px;
        max-height: 200px;
    }

    .faq-answer p {
        font-size: 14px;
        color: #6b7280;
        line-height: 1.6;
    }

    /* Contact Methods */
    .contact-methods {
        display: grid;
        gap: 16px;
    }

    .contact-method {
        padding: 16px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 12px;
        transition: all 0.3s ease;
    }

    .contact-method:hover {
        background: #f9fafb;
        border-color: #ff0050;
    }

    .contact-icon {
        width: 40px;
        height: 40px;
        background: linear-gradient(45deg, #ff0050, #ff4d6d);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 18px;
    }

    .contact-info h4 {
        font-size: 14px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 4px;
    }

    .contact-info p {
        font-size: 12px;
        color: #6b7280;
    }

    /* Recent Tickets */
    .ticket-item {
        padding: 16px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        margin-bottom: 12px;
        transition: all 0.3s ease;
    }

    .ticket-item:hover {
        background: #f9fafb;
    }

    .ticket-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }

    .ticket-id {
        font-size: 12px;
        font-weight: 600;
        color: #6b7280;
    }

    .ticket-status {
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 500;
        text-transform: uppercase;
    }

    .status-processing {
        background: #fef3c7;
        color: #92400e;
    }

    .status-replied {
        background: #dcfce7;
        color: #166534;
    }

    .ticket-title {
        font-size: 14px;
        font-weight: 500;
        color: #1f2937;
        margin-bottom: 4px;
    }

    .ticket-date {
        font-size: 12px;
        color: #6b7280;
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

    /* Responsive */
    @media (max-width: 1200px) {
        .main-grid {
            grid-template-columns: 1fr;
        }

        .quick-actions {
            grid-template-columns: repeat(2, 1fr);
        }

        .hero-stats {
            gap: 24px;
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

        .support-hero {
            padding: 24px;
        }

        .hero-title {
            font-size: 24px;
        }

        .hero-subtitle {
            font-size: 16px;
        }

        .hero-stats {
            flex-direction: column;
            gap: 16px;
        }

        .quick-actions {
            grid-template-columns: 1fr;
        }

        .form-grid {
            grid-template-columns: 1fr;
        }

        .priority-selector {
            grid-template-columns: repeat(2, 1fr);
        }

        .user-details {
            display: none;
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
                    <h1>Hỗ trợ nhanh</h1>
                </div>
                <div class="header-right">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($seller_name, 0, 1)); ?>
                        </div>
                        <div class="user-details">
                            <h3><?php echo htmlspecialchars($seller_name); ?></h3>
                            <div class="user-balance">Số dư: <?php echo number_format($seller_balance, 0, ',', '.'); ?>đ
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

                <!-- Hero Section -->
                <div class="support-hero">
                    <div class="hero-content">
                        <h1 class="hero-title">Trung tâm hỗ trợ TikTok Shop</h1>
                        <p class="hero-subtitle">Chúng tôi luôn sẵn sàng hỗ trợ bạn 24/7</p>
                        <div class="hero-stats">
                            <div class="hero-stat">
                                <div class="number">&lt; 2h</div>
                                <div class="label">Thời gian phản hồi</div>
                            </div>
                            <div class="hero-stat">
                                <div class="number">99.9%</div>
                                <div class="label">Tỷ lệ giải quyết</div>
                            </div>
                            <div class="hero-stat">
                                <div class="number">24/7</div>
                                <div class="label">Hỗ trợ liên tục</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <div class="action-card" onclick="scrollToSection('support-form')">
                        <div class="action-icon">📩</div>
                        <h3 class="action-title">Gửi yêu cầu hỗ trợ</h3>
                        <p class="action-description">Tạo ticket hỗ trợ mới cho vấn đề của bạn</p>
                    </div>
                    <div class="action-card" onclick="scrollToSection('faq-section')">
                        <div class="action-icon">❓</div>
                        <h3 class="action-title">Câu hỏi thường gặp</h3>
                        <p class="action-description">Tìm câu trả lời nhanh chóng</p>
                    </div>
                    <div class="action-card" onclick="openLiveChat()">
                        <div class="action-icon">💬</div>
                        <h3 class="action-title">Live Chat</h3>
                        <p class="action-description">Trò chuyện trực tiếp với nhân viên hỗ trợ</p>
                    </div>
                    <div class="action-card" onclick="openVideoCall()">
                        <div class="action-icon">📹</div>
                        <h3 class="action-title">Video Call</h3>
                        <p class="action-description">Hỗ trợ qua video call (9h-17h)</p>
                    </div>
                </div>

                <!-- Main Grid -->
                <div class="main-grid">
                    <!-- Support Form -->
                    <div>
                        <div id="support-form" class="support-section" style="margin-bottom: 32px;">
                            <div class="section-header">
                                <h2 class="section-title">Gửi yêu cầu hỗ trợ</h2>
                                <p class="section-subtitle">Mô tả chi tiết vấn đề để chúng tôi hỗ trợ bạn tốt nhất</p>
                            </div>

                            <div class="section-content">
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="form-group">
                                        <label class="form-label">Tiêu đề yêu cầu *</label>
                                        <input type="text" name="subject" class="form-input"
                                            placeholder="Tóm tắt ngắn gọn vấn đề..." required>
                                    </div>

                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="form-label">Danh mục vấn đề *</label>
                                            <select name="category" class="form-select" required>
                                                <option value="">Chọn danh mục...</option>
                                                <?php foreach ($support_categories as $key => $value): ?>
                                                <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Số điện thoại liên hệ</label>
                                            <input type="tel" name="phone" class="form-input"
                                                placeholder="Số điện thoại của bạn...">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Độ ưu tiên</label>
                                        <input type="hidden" name="priority" value="normal">
                                        <div class="priority-selector">
                                            <div class="priority-option" data-priority="low">Thấp</div>
                                            <div class="priority-option selected" data-priority="normal">Bình thường
                                            </div>
                                            <div class="priority-option" data-priority="high">Cao</div>
                                            <div class="priority-option" data-priority="urgent">Khẩn cấp</div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Mô tả chi tiết vấn đề *</label>
                                        <textarea name="content" class="form-textarea"
                                            placeholder="Mô tả chi tiết vấn đề bạn gặp phải, các bước đã thực hiện, và kết quả mong muốn..."
                                            required></textarea>
                                        <div class="form-note">Tối thiểu 20 ký tự. Càng chi tiết càng giúp chúng tôi hỗ
                                            trợ tốt hơn.</div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">File đính kèm (tùy chọn)</label>
                                        <input type="file" name="attachment" class="form-file"
                                            accept="image/*,.pdf,.doc,.docx,.txt">
                                        <div class="form-note">
                                            Hỗ trợ: JPG, PNG, PDF, DOC, TXT. Tối đa 10MB.
                                        </div>
                                    </div>

                                    <button type="submit" name="submit_ticket" class="submit-btn">
                                        Gửi yêu cầu hỗ trợ
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- FAQ Section -->
                        <div id="faq-section" class="support-section">
                            <div class="section-header">
                                <h2 class="section-title">Câu hỏi thường gặp</h2>
                                <p class="section-subtitle">Tìm câu trả lời nhanh chóng cho các vấn đề phổ biến</p>
                            </div>

                            <div class="section-content">
                                <?php foreach ($faq_data as $index => $faq): ?>
                                <div class="faq-item">
                                    <button class="faq-question" onclick="toggleFAQ(<?php echo $index; ?>)">
                                        <span><?php echo htmlspecialchars($faq['question']); ?></span>
                                        <span class="icon">▼</span>
                                    </button>
                                    <div class="faq-answer" id="faq-<?php echo $index; ?>">
                                        <p><?php echo htmlspecialchars($faq['answer']); ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div>
                        <!-- Contact Methods -->
                        <div class="support-section" style="margin-bottom: 32px;">
                            <div class="section-header">
                                <h3 class="section-title">Liên hệ trực tiếp</h3>
                                <p class="section-subtitle">Các kênh hỗ trợ nhanh chóng</p>
                            </div>

                            <div class="section-content">
                                <div class="contact-methods">
                                    <div class="contact-method" onclick="openLiveChat()">
                                        <div class="contact-icon">💬</div>
                                        <div class="contact-info">
                                            <h4>Live Chat</h4>
                                            <p>Trực tuyến • Phản hồi < 5 phút</p>
                                        </div>
                                    </div>

                                    <div class="contact-method">
                                        <div class="contact-icon">📞</div>
                                        <div class="contact-info">
                                            <h4>Hotline: 1900-123-456</h4>
                                            <p>24/7 • Miễn phí cuộc gọi</p>
                                        </div>
                                    </div>

                                    <div class="contact-method">
                                        <div class="contact-icon">📧</div>
                                        <div class="contact-info">
                                            <h4>support@tiktokshop.vn</h4>
                                            <p>Email • Phản hồi < 2 giờ</p>
                                        </div>
                                    </div>

                                    <div class="contact-method">
                                        <div class="contact-icon">📹</div>
                                        <div class="contact-info">
                                            <h4>Video Call Support</h4>
                                            <p>9:00 - 17:00 • Hỗ trợ trực quan</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Tickets -->
                        <div class="support-section">
                            <div class="section-header">
                                <h3 class="section-title">Ticket gần đây</h3>
                                <p class="section-subtitle">Theo dõi trạng thái yêu cầu</p>
                            </div>

                            <div class="section-content">
                                <?php if (empty($recent_tickets)): ?>
                                <div class="no-data">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path
                                            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                                    </svg>
                                    <p>Chưa có ticket nào</p>
                                </div>
                                <?php else: ?>
                                <?php foreach (array_slice($recent_tickets, 0, 5) as $ticket): ?>
                                <div class="ticket-item">
                                    <div class="ticket-header">
                                        <span
                                            class="ticket-id">#<?php echo str_pad($ticket['id'], 6, '0', STR_PAD_LEFT); ?></span>
                                        <span
                                            class="ticket-status <?php echo $ticket['reply'] ? 'status-replied' : 'status-processing'; ?>">
                                            <?php echo $ticket['status']; ?>
                                        </span>
                                    </div>
                                    <div class="ticket-title">
                                        <?php
                                                $title = explode(' - ', $ticket['name'], 2);
                                                echo htmlspecialchars($title[1] ?? $title[0]);
                                                ?>
                                    </div>
                                    <div class="ticket-date">
                                        <?php echo date('d/m/Y H:i', strtotime($ticket['created_at'])); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Priority selector
    document.querySelectorAll('.priority-option').forEach(option => {
        option.addEventListener('click', function() {
            document.querySelectorAll('.priority-option').forEach(opt => opt.classList.remove(
                'selected'));
            this.classList.add('selected');
            document.querySelector('input[name="priority"]').value = this.dataset.priority;
        });
    });

    // FAQ toggle
    function toggleFAQ(index) {
        const answer = document.getElementById('faq-' + index);
        const question = answer.previousElementSibling;

        if (answer.classList.contains('show')) {
            answer.classList.remove('show');
            question.classList.remove('active');
        } else {
            // Close all other FAQ items
            document.querySelectorAll('.faq-answer').forEach(item => item.classList.remove('show'));
            document.querySelectorAll('.faq-question').forEach(item => item.classList.remove('active'));

            // Open clicked item
            answer.classList.add('show');
            question.classList.add('active');
        }
    }

    // Scroll to section
    function scrollToSection(sectionId) {
        document.getElementById(sectionId).scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }

    // Mock functions for live chat and video call
    function openLiveChat() {
        alert('Tính năng Live Chat sẽ được cập nhật sớm!');
    }

    function openVideoCall() {
        alert('Tính năng Video Call hỗ trợ từ 9:00 - 17:00 sẽ được cập nhật sớm!');
    }

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const content = document.querySelector('textarea[name="content"]').value;
        if (content.length < 20) {
            e.preventDefault();
            alert('Mô tả vấn đề phải có ít nhất 20 ký tự.');
            return;
        }
    });

    // File upload validation
    document.querySelector('input[name="attachment"]').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            // Check file size (10MB)
            if (file.size > 10 * 1024 * 1024) {
                alert('File quá lớn. Kích thước tối đa 10MB.');
                this.value = '';
                return;
            }

            // Check file type
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif',
                'application/pdf', 'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'text/plain'
            ];
            if (!allowedTypes.includes(file.type)) {
                alert('Định dạng file không được hỗ trợ.');
                this.value = '';
                return;
            }
        }
    });

    // Character counter for textarea
    document.querySelector('textarea[name="content"]').addEventListener('input', function() {
        const length = this.value.length;
        const note = this.nextElementSibling;
        if (length < 20) {
            note.textContent =
                `Cần thêm ${20 - length} ký tự. Càng chi tiết càng giúp chúng tôi hỗ trợ tốt hơn.`;
            note.style.color = '#dc2626';
        } else {
            note.textContent = 'Tối thiểu 20 ký tự. Càng chi tiết càng giúp chúng tôi hỗ trợ tốt hơn.';
            note.style.color = '#6b7280';
        }
    });
    </script>
</body>

</html>