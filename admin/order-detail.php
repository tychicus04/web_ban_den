<?php
/**
 * Admin Order Detail Page
 *
 * @refactored Uses centralized admin_init.php for authentication and helpers
 */

// Initialize admin page
require_once __DIR__ . '/../includes/admin_init.php';
$admin = initAdminPage(true, true);
$db = getDB();

$message = '';
$error = '';
$order_id = intval($_GET['id'] ?? 0);

if ($order_id <= 0) {
    header('Location: orders.php?error=invalid_order_id');
    exit;
}

// Handle order updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['admin_token'], $_POST['csrf_token'])) {
        $error = 'Invalid CSRF token';
    } else {
        switch ($_POST['action']) {
            case 'update_status':
                $delivery_status = $_POST['delivery_status'] ?? '';
                $payment_status = $_POST['payment_status'] ?? '';
                $tracking_code = trim($_POST['tracking_code'] ?? '');
                $admin_notes = trim($_POST['admin_notes'] ?? '');
                
                try {
                    $stmt = $db->prepare("
                        UPDATE orders 
                        SET delivery_status = ?, 
                            payment_status = ?, 
                            tracking_code = ?,
                            updated_at = CURRENT_TIMESTAMP 
                        WHERE id = ?
                    ");
                    
                    if ($stmt->execute([$delivery_status, $payment_status, $tracking_code, $order_id])) {
                        // Add admin note if provided
                        if (!empty($admin_notes)) {
                            $stmt = $db->prepare("
                                INSERT INTO order_notes (order_id, user_id, note, note_type, created_at) 
                                VALUES (?, ?, ?, 'admin', CURRENT_TIMESTAMP)
                            ");
                            $stmt->execute([$order_id, $_SESSION['user_id'], $admin_notes]);
                        }
                        
                        $message = "Cập nhật đơn hàng #$order_id thành công!";
                        
                        // Log activity
                        error_log("Order #$order_id updated by admin ID: " . $_SESSION['user_id']);
                    } else {
                        $error = "Không thể cập nhật đơn hàng.";
                    }
                } catch (PDOException $e) {
                    error_log("Update order error: " . $e->getMessage());
                    $error = "Lỗi cơ sở dữ liệu khi cập nhật.";
                }
                break;
                
            case 'add_note':
                $note = trim($_POST['note'] ?? '');
                if (!empty($note)) {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO order_notes (order_id, user_id, note, note_type, created_at) 
                            VALUES (?, ?, ?, 'admin', CURRENT_TIMESTAMP)
                        ");
                        
                        if ($stmt->execute([$order_id, $_SESSION['user_id'], $note])) {
                            $message = "Thêm ghi chú thành công!";
                        }
                    } catch (PDOException $e) {
                        error_log("Add note error: " . $e->getMessage());
                        $error = "Không thể thêm ghi chú.";
                    }
                }
                break;
        }
    }
}

// Get order details
$order = null;
try {
    $stmt = $db->prepare("
        SELECT o.*, 
               u.name as customer_name, 
               u.email as customer_email,
               u.phone as customer_phone,
               u.avatar as customer_avatar,
               co.shipping_address as combined_address
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN combined_orders co ON o.combined_order_id = co.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        header('Location: orders.php?error=order_not_found');
        exit;
    }
} catch (PDOException $e) {
    error_log("Fetch order error: " . $e->getMessage());
    header('Location: orders.php?error=database_error');
    exit;
}

// Get order items
$order_items = [];
try {
    $stmt = $db->prepare("
        SELECT od.*, 
               p.name as product_name,
               p.slug as product_slug,
               p.sku as product_sku,
               p.weight as product_weight,
               u.file_name as product_image,
               us.name as seller_name
        FROM order_details od
        LEFT JOIN products p ON od.product_id = p.id
        LEFT JOIN uploads u ON p.thumbnail_img = u.id
        LEFT JOIN users us ON p.user_id = us.id
        WHERE od.order_id = ?
        ORDER BY od.id ASC
    ");
    $stmt->execute([$order_id]);
    $order_items = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Fetch order items error: " . $e->getMessage());
    $order_items = [];
}

// Get order notes/history
$order_notes = [];
try {
    $stmt = $db->prepare("
        SELECT on.*, u.name as user_name
        FROM order_notes on
        LEFT JOIN users u ON on.user_id = u.id
        WHERE on.order_id = ?
        ORDER BY on.created_at DESC
    ");
    $stmt->execute([$order_id]);
    $order_notes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Fetch order notes error: " . $e->getMessage());
    $order_notes = [];
}

// Get shipping info
$shipping_info = null;
if ($order['shipping_address']) {
    $shipping_info = json_decode($order['shipping_address'], true);
} elseif ($order['combined_address']) {
    $shipping_info = json_decode($order['combined_address'], true);
}

// Status translation
function getStatusText($status, $type = 'delivery') {
    $delivery_statuses = [
        'pending' => 'Chờ xử lý',
        'confirmed' => 'Đã xác nhận',
        'processing' => 'Đang xử lý',
        'shipped' => 'Đã gửi hàng',
        'delivered' => 'Đã giao hàng',
        'cancelled' => 'Đã hủy',
        'returned' => 'Trả hàng'
    ];
    
    $payment_statuses = [
        'unpaid' => 'Chưa thanh toán',
        'pending' => 'Chờ thanh toán',
        'paid' => 'Đã thanh toán',
        'refunded' => 'Đã hoàn tiền',
        'failed' => 'Thanh toán thất bại'
    ];
    
    if ($type === 'payment') {
        return $payment_statuses[$status] ?? ucfirst($status);
    }
    
    return $delivery_statuses[$status] ?? ucfirst($status);
}

// Order timeline
function getOrderTimeline($order, $order_notes) {
    $timeline = [];
    
    // Order created
    $timeline[] = [
        'date' => $order['created_at'],
        'title' => 'Đơn hàng được tạo',
        'description' => 'Khách hàng đã đặt đơn hàng #' . $order['id'],
        'type' => 'created',
        'icon' => '📦'
    ];
    
    // Add notes to timeline
    foreach ($order_notes as $note) {
        $timeline[] = [
            'date' => $note['created_at'],
            'title' => $note['note_type'] === 'admin' ? 'Ghi chú từ Admin' : 'Ghi chú hệ thống',
            'description' => $note['note'],
            'type' => 'note',
            'icon' => '📝',
            'user' => $note['user_name']
        ];
    }
    
    // Sort by date
    usort($timeline, function($a, $b) {
         
        $time_a = !empty($a['date']) ? strtotime($a['date']) : 0;
        $time_b = !empty($b['date']) ? strtotime($b['date']) : 0;
        return $time_b - $time_a;
    });
    
    return $timeline;
}

// Calculate totals
$subtotal = 0;
$total_tax = 0;
$total_shipping = 0;
$total_items = 0;

foreach ($order_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
    $total_tax += $item['tax'];
    $total_shipping += $item['shipping_cost'];
    $total_items += $item['quantity'];
}

$timeline = getOrderTimeline($order, $order_notes);
$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đơn hàng #<?php echo $order['id']; ?> - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Chi tiết đơn hàng #<?php echo $order['id']; ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-sidebar.css">
    <link rel="stylesheet" href="../asset/css/pages/admin-order-detail.css">
</head>

<body>
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <?php require_once __DIR__ . '/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle sidebar">
                        ☰
                    </button>
                    <nav class="breadcrumb" aria-label="Breadcrumb">
                        <a href="dashboard.php" class="breadcrumb-link">Admin</a>
                        <span class="breadcrumb-separator">›</span>
                        <a href="orders.php" class="breadcrumb-link">Đơn hàng</a>
                        <span class="breadcrumb-separator">›</span>
                        <span>Chi tiết #<?php echo $order['id']; ?></span>
                    </nav>
                </div>
                
                <div class="header-actions">
                    <button type="button" class="btn btn-secondary" onclick="window.print()">
                        🖨️ In đơn hàng
                    </button>
                    <button type="button" class="btn btn-warning" onclick="showModal('update-order-modal')">
                        ✏️ Cập nhật trạng thái
                    </button>
                    <a href="orders.php" class="btn btn-primary">
                        ← Quay lại danh sách
                    </a>
                </div>
            </header>
            
            <!-- Content -->
            <div class="content">
                <!-- Messages -->
                <?php if ($message): ?>
                    <div class="message success">
                        <span>✅</span>
                        <span><?php echo htmlspecialchars($message); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="message error">
                        <span>❌</span>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>
                
                <!-- Order Header -->
                <div class="order-header">
                    <div class="order-header-content">
                        <div class="order-info">
                            <div class="order-field">
                                <div class="order-label">Mã đơn hàng</div>
                                <div class="order-value large">#<?php echo $order['id']; ?></div>
                            </div>
                            
                            <div class="order-field">
                                <div class="order-label">Ngày đặt hàng</div>
                                <div class="order-value"><?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?></div>
                            </div>
                            
                            <div class="order-field">
                                <div class="order-label">Tổng tiền</div>
                                <div class="order-value large"><?php echo formatCurrency($order['grand_total']); ?></div>
                            </div>
                            
                            <div class="order-field">
                                <div class="order-label">Trạng thái giao hàng</div>
                                <span class="status-badge <?php echo $order['delivery_status']; ?>">
                                    <?php echo getStatusText($order['delivery_status'], 'delivery'); ?>
                                </span>
                            </div>
                            
                            <div class="order-field">
                                <div class="order-label">Trạng thái thanh toán</div>
                                <span class="status-badge <?php echo $order['payment_status']; ?>">
                                    <?php echo getStatusText($order['payment_status'], 'payment'); ?>
                                </span>
                            </div>
                            
                            <?php if ($order['tracking_code']): ?>
                            <div class="order-field">
                                <div class="order-label">Mã vận đơn</div>
                                <div class="order-value"><?php echo htmlspecialchars($order['tracking_code']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Order Content Grid -->
                <div class="order-grid">
                    <!-- Order Items -->
                    <div class="order-items">
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title">Sản phẩm đặt hàng (<?php echo $total_items; ?> sản phẩm)</h2>
                            </div>
                            <div class="card-content">
                                <div class="item-list">
                                    <?php foreach ($order_items as $item): ?>
                                        <div class="order-item">
                                            <img 
                                                src="<?php echo !empty($item['product_image']) && file_exists('../' . $item['product_image']) ? '../' . htmlspecialchars($item['product_image']) : 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80"><rect width="80" height="80" fill="%23f3f4f6"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="24" fill="%236b7280">📦</text></svg>'; ?>" 
                                                alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                                class="item-image"
                                                loading="lazy"
                                            >
                                            
                                            <div class="item-details">
                                                <div class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                                
                                                <div class="item-meta">
                                                    <?php if ($item['product_sku']): ?>
                                                        <span>SKU: <?php echo htmlspecialchars($item['product_sku']); ?></span>
                                                    <?php endif; ?>

                                                    <?php if ($item['product_weight']): ?>
                                                        <span>Trọng lượng: <?php echo $item['product_weight']; ?>g</span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <?php if ($item['variation'] && $item['variation'] !== '[]'): ?>
                                                    <div class="item-variation">
                                                        <?php 
                                                            $variations = json_decode($item['variation'], true);
                                                            if (is_array($variations)) {
                                                                foreach ($variations as $key => $value) {
                                                                    echo htmlspecialchars($key) . ': ' . htmlspecialchars($value) . ' ';
                                                                }
                                                            }
                                                        ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="item-pricing">
                                                <div class="item-price"><?php echo formatCurrency($item['price']); ?></div>
                                                <div class="item-quantity">Số lượng: <?php echo $item['quantity']; ?></div>
                                                <div class="item-total"><?php echo formatCurrency($item['price'] * $item['quantity']); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Order Summary -->
                                <div class="order-summary">
                                    <div class="summary-row">
                                        <span class="summary-label">Tạm tính:</span>
                                        <span class="summary-value"><?php echo formatCurrency($subtotal); ?></span>
                                    </div>
                                    
                                    <?php if ($total_tax > 0): ?>
                                    <div class="summary-row">
                                        <span class="summary-label">Thuế:</span>
                                        <span class="summary-value"><?php echo formatCurrency($total_tax); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="summary-row">
                                        <span class="summary-label">Phí vận chuyển:</span>
                                        <span class="summary-value"><?php echo formatCurrency($total_shipping); ?></span>
                                    </div>
                                    
                                    <?php if ($order['coupon_discount'] > 0): ?>
                                    <div class="summary-row">
                                        <span class="summary-label">Giảm giá:</span>
                                        <span class="summary-value">-<?php echo formatCurrency($order['coupon_discount']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="summary-row">
                                        <span class="summary-label">Tổng cộng:</span>
                                        <span class="summary-value"><?php echo formatCurrency($order['grand_total']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sidebar Info -->
                    <div class="order-sidebar">
                        <!-- Customer Info -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Thông tin khách hàng</h3>
                            </div>
                            <div class="customer-card">
                                <div class="customer-avatar">
                                    <?php echo strtoupper(substr($order['customer_name'] ?? 'G', 0, 1)); ?>
                                </div>
                                
                                <div class="customer-name"><?php echo htmlspecialchars($order['customer_name'] ?? 'Khách vãng lai'); ?></div>
                                
                                <?php if ($order['customer_email']): ?>
                                    <div class="customer-detail">
                                        <span>📧</span>
                                        <span><?php echo htmlspecialchars($order['customer_email']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($order['customer_phone']): ?>
                                    <div class="customer-detail">
                                        <span>📞</span>
                                        <span><?php echo htmlspecialchars($order['customer_phone']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="customer-detail">
                                    <span>🎯</span>
                                    <span>Khách hàng <?php echo $order['user_id'] ? 'thành viên' : 'vãng lai'; ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Shipping Address -->
                        <?php if ($shipping_info): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Địa chỉ giao hàng</h3>
                            </div>
                            <div class="address-card">
                                <div class="address-text">
                                    <?php if (is_array($shipping_info)): ?>
                                        <?php echo htmlspecialchars($shipping_info['address'] ?? ''); ?><br>
                                        <?php echo htmlspecialchars($shipping_info['city'] ?? ''); ?><br>
                                        <?php echo htmlspecialchars($shipping_info['state'] ?? ''); ?><br>
                                        <?php echo htmlspecialchars($shipping_info['country'] ?? 'Vietnam'); ?><br>
                                        <?php if (!empty($shipping_info['postal_code'])): ?>
                                            <?php echo htmlspecialchars($shipping_info['postal_code']); ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Payment & Delivery Info -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Thanh toán & Giao hàng</h3>
                            </div>
                            <div class="card-content">
                                <div class="order-field">
                                    <div class="order-label">Hình thức thanh toán</div>
                                    <div class="order-value"><?php echo ucfirst($order['payment_type'] ?? 'Chưa xác định'); ?></div>
                                </div>
                                
                                <div class="order-field">
                                    <div class="order-label">Hình thức vận chuyển</div>
                                    <div class="order-value"><?php echo ucfirst($order['shipping_type'] ?? 'Standard'); ?></div>
                                </div>
                                
                                <?php if ($order['pickup_point_id']): ?>
                                <div class="order-field">
                                    <div class="order-label">Điểm nhận hàng</div>
                                    <div class="order-value"><?php echo htmlspecialchars($order['pickup_point_id']); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Order Timeline -->
                <div class="timeline-container">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Lịch sử đơn hàng</h2>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="showModal('add-note-modal')">
                                📝 Thêm ghi chú
                            </button>
                        </div>
                        <div class="card-content">
                            <div class="timeline">
                                <?php foreach ($timeline as $item): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-icon"><?php echo $item['icon']; ?></div>
                                        <div class="timeline-content">
                                            <div class="timeline-title"><?php echo htmlspecialchars($item['title']); ?></div>
                                            <div class="timeline-description"><?php echo htmlspecialchars($item['description']); ?></div>
                                            <div class="timeline-date">
                                                <?php echo date('d/m/Y H:i', strtotime($item['date'])); ?>
                                                <?php if (isset($item['user'])): ?>
                                                    - bởi <?php echo htmlspecialchars($item['user']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Update Order Modal -->
    <div class="modal" id="update-order-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Cập nhật trạng thái đơn hàng #<?php echo $order['id']; ?></h3>
                <button type="button" class="modal-close" onclick="closeModal('update-order-modal')">×</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_token']; ?>">
                <input type="hidden" name="action" value="update_status">
                
                <div class="form-group">
                    <label class="form-label">Trạng thái giao hàng</label>
                    <select name="delivery_status" class="form-select">
                        <option value="pending" <?php echo $order['delivery_status'] === 'pending' ? 'selected' : ''; ?>>Chờ xử lý</option>
                        <option value="confirmed" <?php echo $order['delivery_status'] === 'confirmed' ? 'selected' : ''; ?>>Đã xác nhận</option>
                        <option value="processing" <?php echo $order['delivery_status'] === 'processing' ? 'selected' : ''; ?>>Đang xử lý</option>
                        <option value="shipped" <?php echo $order['delivery_status'] === 'shipped' ? 'selected' : ''; ?>>Đã gửi hàng</option>
                        <option value="delivered" <?php echo $order['delivery_status'] === 'delivered' ? 'selected' : ''; ?>>Đã giao hàng</option>
                        <option value="cancelled" <?php echo $order['delivery_status'] === 'cancelled' ? 'selected' : ''; ?>>Đã hủy</option>
                        <option value="returned" <?php echo $order['delivery_status'] === 'returned' ? 'selected' : ''; ?>>Trả hàng</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Trạng thái thanh toán</label>
                    <select name="payment_status" class="form-select">
                        <option value="unpaid" <?php echo $order['payment_status'] === 'unpaid' ? 'selected' : ''; ?>>Chưa thanh toán</option>
                        <option value="pending" <?php echo $order['payment_status'] === 'pending' ? 'selected' : ''; ?>>Chờ thanh toán</option>
                        <option value="paid" <?php echo $order['payment_status'] === 'paid' ? 'selected' : ''; ?>>Đã thanh toán</option>
                        <option value="refunded" <?php echo $order['payment_status'] === 'refunded' ? 'selected' : ''; ?>>Đã hoàn tiền</option>
                        <option value="failed" <?php echo $order['payment_status'] === 'failed' ? 'selected' : ''; ?>>Thanh toán thất bại</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Mã vận đơn</label>
                    <input 
                        type="text" 
                        name="tracking_code" 
                        class="form-input" 
                        placeholder="Nhập mã vận đơn..."
                        value="<?php echo htmlspecialchars($order['tracking_code'] ?? ''); ?>"
                    >
                </div>
                
                <div class="form-group">
                    <label class="form-label">Ghi chú admin</label>
                    <textarea 
                        name="admin_notes" 
                        class="form-textarea" 
                        placeholder="Thêm ghi chú về việc cập nhật này..."
                    ></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('update-order-modal')">
                        Hủy
                    </button>
                    <button type="submit" class="btn btn-primary">
                        💾 Cập nhật đơn hàng
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Add Note Modal -->
    <div class="modal" id="add-note-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Thêm ghi chú</h3>
                <button type="button" class="modal-close" onclick="closeModal('add-note-modal')">×</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['admin_token']; ?>">
                <input type="hidden" name="action" value="add_note">
                
                <div class="form-group">
                    <label class="form-label">Nội dung ghi chú</label>
                    <textarea 
                        name="note" 
                        class="form-textarea" 
                        placeholder="Nhập ghi chú về đơn hàng này..."
                        required
                    ></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('add-note-modal')">
                        Hủy
                    </button>
                    <button type="submit" class="btn btn-primary">
                        📝 Thêm ghi chú
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Sidebar toggle
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
        
        // Modal functions
        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }
        
        // Close modal on backdrop click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });
        
        // Auto-refresh order status every 60 seconds
        let autoRefreshInterval;
        
        function startAutoRefresh() {
            autoRefreshInterval = setInterval(() => {
                if (!document.querySelector('.modal.show')) {
                    // Check for order status updates
                    fetch(`order-status.php?id=<?php echo $order['id']; ?>`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.delivery_status !== '<?php echo $order['delivery_status']; ?>' || 
                                data.payment_status !== '<?php echo $order['payment_status']; ?>') {
                                // Reload page if status changed
                                location.reload();
                            }
                        })
                        .catch(error => {
                            console.error('Auto-refresh error:', error);
                        });
                }
            }, 60000); // 60 seconds
        }
        
        function stopAutoRefresh() {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
            }
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close modals
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    closeModal(modal.id);
                });
            }
            
            // Ctrl/Cmd + P to print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            
            // Ctrl/Cmd + U to update status
            if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
                e.preventDefault();
                showModal('update-order-modal');
            }
            
            // Ctrl/Cmd + N to add note
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                showModal('add-note-modal');
            }
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('📦 Order Details - Initializing...');
            
            // Start auto-refresh
            startAutoRefresh();
            
            // Add loading states to forms
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<div class="loading"></div> Đang xử lý...';
                    }
                });
            });
            
            // Highlight current order status
            const statusBadges = document.querySelectorAll('.status-badge');
            statusBadges.forEach(badge => {
                badge.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.05)';
                });
                badge.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });
            
            console.log('✅ Order Details - Ready!');
            console.log('🔄 Auto-refresh enabled | ⌨️ Keyboard shortcuts active | 🖨️ Print ready');
        });
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            stopAutoRefresh();
        });
        
        // Page visibility API - pause auto-refresh when tab is hidden
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopAutoRefresh();
            } else {
                startAutoRefresh();
            }
        });
        
        // Print optimization
        window.addEventListener('beforeprint', function() {
            // Hide elements that shouldn't be printed
            document.querySelectorAll('.btn, .modal').forEach(el => {
                el.style.display = 'none';
            });
        });
        
        window.addEventListener('afterprint', function() {
            // Restore elements after printing
            document.querySelectorAll('.btn, .modal').forEach(el => {
                el.style.display = '';
            });
        });
        
        // Enhanced order item interactions
        document.querySelectorAll('.order-item').forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.borderColor = 'var(--primary)';
                this.style.boxShadow = 'var(--shadow-md)';
            });
            
            item.addEventListener('mouseleave', function() {
                this.style.borderColor = 'var(--border-light)';
                this.style.boxShadow = 'var(--shadow-xs)';
            });
        });
        
        // Error handling
        window.addEventListener('error', function(e) {
            console.error('JavaScript error:', e.error);
        });
        
        // Copy tracking code functionality
        function copyTrackingCode() {
            const trackingCode = '<?php echo $order['tracking_code'] ?? ''; ?>';
            if (trackingCode) {
                navigator.clipboard.writeText(trackingCode).then(() => {
                    // Show success message
                    const message = document.createElement('div');
                    message.className = 'message success';
                    message.innerHTML = '<span>✅</span><span>Đã copy mã vận đơn!</span>';
                    document.querySelector('.content').insertBefore(message, document.querySelector('.order-header'));
                    
                    setTimeout(() => {
                        message.remove();
                    }, 3000);
                });
            }
        }
        
        // Add click handler for tracking code if it exists
        <?php if ($order['tracking_code']): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const trackingElements = document.querySelectorAll('[data-tracking-code]');
            trackingElements.forEach(el => {
                el.style.cursor = 'pointer';
                el.title = 'Click để copy mã vận đơn';
                el.addEventListener('click', copyTrackingCode);
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>