<?php
/**
 * Data Check Script
 * Kiểm tra dữ liệu trong database và hiển thị thông tin
 */

require_once __DIR__ . '/../includes/admin_init.php';
$admin = initAdminPage(true, true);
$db = getDB();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kiểm tra dữ liệu - Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f3f4f6;
            padding: 2rem;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 { color: #111827; margin-bottom: 2rem; font-size: 2rem; }
        h2 { color: #374151; margin-bottom: 1rem; font-size: 1.25rem; }
        .status { 
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 600;
            margin: 0.5rem 0;
        }
        .status.success { background: #d1fae5; color: #065f46; }
        .status.warning { background: #fef3c7; color: #92400e; }
        .status.error { background: #fee2e2; color: #991b1b; }
        table { 
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        th, td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        .info { 
            background: #eff6ff;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            border-left: 4px solid #3b82f6;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 1rem;
        }
        .btn:hover { background: #2563eb; }
        code {
            background: #f3f4f6;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Kiểm tra dữ liệu Database</h1>
        
        <?php
        // Check Orders
        try {
            $stmt = $db->query("SELECT COUNT(*) as total FROM orders");
            $orders = $stmt->fetch(PDO::FETCH_ASSOC);
            $total_orders = $orders['total'] ?? 0;
            
            echo '<div class="card">';
            echo '<h2>📦 Đơn hàng (Orders)</h2>';
            
            if ($total_orders > 0) {
                echo '<div class="status success">✅ Có ' . $total_orders . ' đơn hàng</div>';
                
                // Check payment status
                $stmt = $db->query("SELECT payment_status, COUNT(*) as count FROM orders GROUP BY payment_status");
                $payment_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo '<table>';
                echo '<thead><tr><th>Trạng thái thanh toán</th><th>Số lượng</th></tr></thead>';
                echo '<tbody>';
                foreach ($payment_stats as $stat) {
                    echo '<tr>';
                    echo '<td><code>' . htmlspecialchars($stat['payment_status'] ?? 'NULL') . '</code></td>';
                    echo '<td>' . $stat['count'] . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                
                // Check recent orders
                $stmt = $db->query("SELECT id, code, grand_total, payment_status, delivery_status, created_at FROM orders ORDER BY created_at DESC LIMIT 5");
                $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo '<h3 style="margin-top: 1.5rem; margin-bottom: 0.5rem;">5 đơn hàng gần nhất:</h3>';
                echo '<table>';
                echo '<thead><tr><th>ID</th><th>Mã</th><th>Tổng tiền</th><th>TT Thanh toán</th><th>TT Giao hàng</th><th>Ngày tạo</th></tr></thead>';
                echo '<tbody>';
                foreach ($recent_orders as $order) {
                    echo '<tr>';
                    echo '<td>' . $order['id'] . '</td>';
                    echo '<td><code>' . htmlspecialchars($order['code']) . '</code></td>';
                    echo '<td>' . number_format($order['grand_total'], 0, ',', '.') . ' ₫</td>';
                    echo '<td><code>' . htmlspecialchars($order['payment_status']) . '</code></td>';
                    echo '<td><code>' . htmlspecialchars($order['delivery_status']) . '</code></td>';
                    echo '<td>' . date('d/m/Y H:i', strtotime($order['created_at'])) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                
            } else {
                echo '<div class="status warning">⚠️ Chưa có đơn hàng nào</div>';
                echo '<div class="info">💡 Cần tạo đơn hàng mẫu để hiển thị chart doanh thu</div>';
            }
            echo '</div>';
            
        } catch (PDOException $e) {
            echo '<div class="card">';
            echo '<div class="status error">❌ Lỗi: ' . $e->getMessage() . '</div>';
            echo '</div>';
        }
        
        // Check Monthly Sales Query
        try {
            echo '<div class="card">';
            echo '<h2>📈 Dữ liệu Chart (12 tháng gần nhất)</h2>';
            
            $sql = "
                SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as order_count,
                    COALESCE(SUM(grand_total), 0) as revenue
                FROM orders
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month ASC
            ";
            
            $stmt = $db->query($sql);
            $monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($monthly_data)) {
                echo '<div class="status success">✅ Có ' . count($monthly_data) . ' tháng có dữ liệu</div>';
                echo '<table>';
                echo '<thead><tr><th>Tháng</th><th>Số đơn</th><th>Doanh thu</th></tr></thead>';
                echo '<tbody>';
                foreach ($monthly_data as $data) {
                    echo '<tr>';
                    echo '<td><code>' . $data['month'] . '</code></td>';
                    echo '<td>' . $data['order_count'] . '</td>';
                    echo '<td>' . number_format($data['revenue'], 0, ',', '.') . ' ₫</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<div class="status warning">⚠️ Không có dữ liệu trong 12 tháng gần nhất</div>';
                echo '<div class="info">💡 Chart sẽ hiển thị dummy data (0) để không bị lỗi</div>';
            }
            
            echo '</div>';
            
        } catch (PDOException $e) {
            echo '<div class="card">';
            echo '<div class="status error">❌ Lỗi query: ' . $e->getMessage() . '</div>';
            echo '</div>';
        }
        
        // Check Tables
        echo '<div class="card">';
        echo '<h2>🗄️ Thông tin Tables</h2>';
        
        $tables = ['orders', 'order_details', 'products', 'users', 'categories'];
        echo '<table>';
        echo '<thead><tr><th>Table</th><th>Số bản ghi</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($tables as $table) {
            try {
                $stmt = $db->query("SELECT COUNT(*) as count FROM $table");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                echo '<tr>';
                echo '<td><code>' . $table . '</code></td>';
                echo '<td>' . $result['count'] . '</td>';
                echo '</tr>';
            } catch (PDOException $e) {
                echo '<tr>';
                echo '<td><code>' . $table . '</code></td>';
                echo '<td class="status error">Error</td>';
                echo '</tr>';
            }
        }
        
        echo '</tbody></table>';
        echo '</div>';
        ?>
        
        <div class="card">
            <h2>💡 Kết luận</h2>
            <div class="info">
                <strong>Để chart hiển thị đúng:</strong><br>
                1. Cần có ít nhất 1 đơn hàng trong database<br>
                2. Đơn hàng cần có <code>payment_status = 'paid'</code> hoặc <code>'completed'</code><br>
                3. Hoặc tạo đơn hàng mẫu để test<br>
                4. Chart sẽ tự động hiển thị message "Chưa có đơn hàng" nếu không có data
            </div>
            <a href="dashboard.php" class="btn">← Quay lại Dashboard</a>
        </div>
    </div>
</body>
</html>
