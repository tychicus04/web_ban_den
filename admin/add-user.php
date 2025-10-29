<?php
/**
 * Admin Add User Page
 *
 * @refactored Uses centralized admin_init.php for authentication and helpers
 */

// Initialize admin page with authentication and admin info
require_once __DIR__ . '/../includes/admin_init.php';
$admin = initAdminPage(true, true);
$db = getDB();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    try {
        switch ($_POST['action']) {
            case 'add_user':
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = trim($_POST['password'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $user_type = trim($_POST['user_type'] ?? 'customer');
                
                // Additional fields
                $address = trim($_POST['address'] ?? '');
                $country_id = (int)($_POST['country_id'] ?? 0);
                $state_id = (int)($_POST['state_id'] ?? 0);
                $city_id = (int)($_POST['city_id'] ?? 0);
                $postal_code = trim($_POST['postal_code'] ?? '');
                $create_affiliate = isset($_POST['create_affiliate']) && $_POST['create_affiliate'] === 'true';
                $initial_balance = (float)($_POST['initial_balance'] ?? 0);
                
                // Validation
                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'Tên không được để trống']);
                    break;
                }
                
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Email không hợp lệ']);
                    break;
                }
                
                if (empty($password) || strlen($password) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Mật khẩu phải có ít nhất 6 ký tự']);
                    break;
                }
                
                // Check if email already exists
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Email đã được sử dụng bởi người dùng khác']);
                    break;
                }
                
                // Begin transaction
                $db->beginTransaction();
                
                try {
                    // Generate referral code
                    $referral_code = substr(md5(time() . $email), 0, 10);
                    
                    // Hash password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Create user
                    $stmt = $db->prepare("
                        INSERT INTO users (
                            name, email, password, phone, user_type, 
                            balance, referral_code, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, 
                            ?, ?, NOW(), NOW()
                        )
                    ");
                    $stmt->execute([
                        $name, $email, $hashed_password, $phone, $user_type, 
                        $initial_balance, $referral_code
                    ]);
                    
                    $user_id = $db->lastInsertId();
                    
                    // Add address if provided
                    if (!empty($address) && $country_id > 0 && $state_id > 0) {
                        $stmt = $db->prepare("
                            INSERT INTO addresses (
                                user_id, address, country_id, state_id, 
                                city_id, postal_code, phone, set_default, 
                                created_at, updated_at
                            ) VALUES (
                                ?, ?, ?, ?, 
                                ?, ?, ?, 1, 
                                NOW(), NOW()
                            )
                        ");
                        $stmt->execute([
                            $user_id, $address, $country_id, $state_id, 
                            $city_id, $postal_code, $phone
                        ]);
                    }

                    // Create affiliate account if requested
                    if ($create_affiliate) {
                        $stmt = $db->prepare("
                            INSERT INTO affiliate_users (
                                user_id, status, created_at, updated_at
                            ) VALUES (
                                ?, 1, NOW(), NOW()
                            )
                        ");
                        $stmt->execute([$user_id]);
                        
                        // Create affiliate stats record
                        $stmt = $db->prepare("
                            INSERT INTO affiliate_stats (
                                affiliate_user_id, created_at, updated_at
                            ) VALUES (
                                ?, NOW(), NOW()
                            )
                        ");
                        $stmt->execute([$user_id]);
                    }
                    
                    // If user type is staff, create staff record
                    if ($user_type === 'staff') {
                        // Get a default role for staff
                        $stmt = $db->prepare("SELECT id FROM roles WHERE name = 'Staff' OR name = 'Employee' LIMIT 1");
                        $stmt->execute();
                        $role = $stmt->fetch();
                        $role_id = $role ? $role['id'] : 1; // Default to role ID 1 if no staff role found
                        
                        $stmt = $db->prepare("
                            INSERT INTO staff (
                                user_id, role_id, created_at, updated_at
                            ) VALUES (
                                ?, ?, NOW(), NOW()
                            )
                        ");
                        $stmt->execute([$user_id, $role_id]);
                    }
                    
                    // Add wallet transaction if initial balance provided
                    if ($initial_balance > 0) {
                        $stmt = $db->prepare("
                            INSERT INTO wallets (
                                user_id, amount, payment_method, payment_details, 
                                approval, created_at, updated_at
                            ) VALUES (
                                ?, ?, 'admin', ?, 1, NOW(), NOW()
                            )
                        ");
                        $payment_details = json_encode([
                            'added_by' => $_SESSION['user_id'],
                            'added_at' => date('Y-m-d H:i:s'),
                            'details' => 'Số dư ban đầu khi tạo tài khoản'
                        ]);
                        $stmt->execute([$user_id, $initial_balance, $payment_details]);
                    }
                    
                    $db->commit();
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Đã tạo người dùng thành công', 
                        'user_id' => $user_id
                    ]);
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("User action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Get countries, states, cities for address form
$countries = [];
try {
    $stmt = $db->query("SELECT id, name FROM countries WHERE status = 1 ORDER BY name");
    $countries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Countries fetch error: " . $e->getMessage());
    $countries = [];
}

$states = [];
try {
    $stmt = $db->query("SELECT id, name, country_id FROM states WHERE status = 1 ORDER BY name");
    $states = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("States fetch error: " . $e->getMessage());
    $states = [];
}

$cities = [];
try {
    $stmt = $db->query("SELECT id, name, state_id FROM cities WHERE status = 1 ORDER BY name");
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Cities fetch error: " . $e->getMessage());
    $cities = [];
}

$site_name = getBusinessSetting($db, 'site_name', 'CarousellVN');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thêm người dùng - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Thêm người dùng mới - Admin <?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-add-user.css">
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
                        <a href="users.php" class="nav-link active">
                            <span class="nav-icon">👥</span>
                            <span class="nav-text">Người dùng</span>
                        </a>
                    </div>   
                    <div class="nav-item">
                    <div class="nav-item">
                        <a href="reviews.php" class="nav-link">
                            <span class="nav-icon">⭐</span>
                            <span class="nav-text">Đánh giá</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="contacts.php" class="nav-link">
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
                            <a href="users.php">Người dùng</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span>Thêm mới</span>
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
                                <div class="user-role"><?php echo htmlspecialchars($_SESSION['admin_role'] ?? 'Administrator'); ?></div>
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
                    <div class="page-header-left">
                        <h1 class="page-title">Thêm người dùng mới</h1>
                        <p class="page-subtitle">Tạo tài khoản người dùng mới trong hệ thống</p>
                    </div>
                    
                    <div class="page-header-actions">
                        <a href="users.php" class="btn btn-secondary">
                            <span>↩️</span>
                            <span>Quay lại</span>
                        </a>
                    </div>
                </div>
                
                <!-- User Form -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Thông tin tài khoản</h2>
                    </div>
                    <div class="card-body">
                        <form id="user-form">
                            <!-- Basic Information -->
                            <h3 style="margin-bottom: var(--space-4); color: var(--text-secondary);">Thông tin cơ bản</h3>
                            
                            <div class="form-row">
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label" for="name">Họ tên <span style="color: red">*</span></label>
                                        <input type="text" class="form-control" id="name" placeholder="Nhập họ tên" required>
                                    </div>
                                </div>
                                
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label" for="email">Email <span style="color: red">*</span></label>
                                        <input type="email" class="form-control" id="email" placeholder="Nhập email" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label" for="password">Mật khẩu <span style="color: red">*</span></label>
                                        <input type="password" class="form-control" id="password" placeholder="Nhập mật khẩu" required onkeyup="checkPasswordStrength()">
                                        <div class="password-strength">
                                            <div class="strength-meter">
                                                <div class="strength-meter-fill" id="strength-meter-fill"></div>
                                            </div>
                                            <div class="strength-text" id="strength-text">Vui lòng nhập mật khẩu</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label" for="confirm-password">Xác nhận mật khẩu <span style="color: red">*</span></label>
                                        <input type="password" class="form-control" id="confirm-password" placeholder="Nhập lại mật khẩu" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label" for="phone">Số điện thoại</label>
                                        <input type="text" class="form-control" id="phone" placeholder="Nhập số điện thoại">
                                    </div>
                                </div>
                                
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label" for="user-type">Loại tài khoản <span style="color: red">*</span></label>
                                        <select class="form-control" id="user-type">
                                            <option value="customer">Khách hàng</option>
                                            <option value="staff">Nhân viên</option>
                                            <option value="admin">Quản trị viên</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label" for="initial-balance">Số dư ban đầu</label>
                                        <input type="number" class="form-control" id="initial-balance" placeholder="0" min="0" step="1000" value="0">
                                        <div class="form-hint">Số dư ban đầu của tài khoản (để trống nếu không có)</div>
                                    </div>
                                </div>
                                
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label">Tài khoản đặc biệt</label>
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="create-affiliate">
                                            <label class="form-check-label" for="create-affiliate">Tạo tài khoản affiliate</label>
                                        </div>
                                        <div class="form-hint">Tự động tạo tài khoản affiliate</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Address Information -->
                            <h3 style="margin: var(--space-6) 0 var(--space-4); color: var(--text-secondary);">Thông tin địa chỉ (không bắt buộc)</h3>
                            
                            <div class="form-row">
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label" for="address">Địa chỉ</label>
                                        <input type="text" class="form-control" id="address" placeholder="Nhập địa chỉ">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label" for="country">Quốc gia</label>
                                        <select class="form-control" id="country" onchange="updateStates()">
                                            <option value="">Chọn quốc gia</option>
                                            <?php foreach ($countries as $country): ?>
                                                <option value="<?php echo $country['id']; ?>"><?php echo htmlspecialchars($country['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label" for="state">Tỉnh/Thành phố</label>
                                        <select class="form-control" id="state" onchange="updateCities()">
                                            <option value="">Chọn tỉnh/thành phố</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label" for="city">Quận/Huyện</label>
                                        <select class="form-control" id="city">
                                            <option value="">Chọn quận/huyện</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-col">
                                    <div class="form-group">
                                        <label class="form-label" for="postal-code">Mã bưu điện</label>
                                        <input type="text" class="form-control" id="postal-code" placeholder="Nhập mã bưu điện">
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-secondary" onclick="resetForm()">
                            <span>🔄</span>
                            <span>Làm mới</span>
                        </button>
                        <button class="btn btn-primary" onclick="createUser()" id="create-user-btn">
                            <span>✅</span>
                            <span>Tạo người dùng</span>
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Store country, state, city data for address form
        const countryData = <?php echo json_encode($countries); ?>;
        const stateData = <?php echo json_encode($states); ?>;
        const cityData = <?php echo json_encode($cities); ?>;
        
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
        
        // Address select functions
        function updateStates() {
            const countryId = document.getElementById('country').value;
            const stateSelect = document.getElementById('state');
            
            // Clear state select
            stateSelect.innerHTML = '<option value="">Chọn tỉnh/thành phố</option>';
            
            // Clear city select
            document.getElementById('city').innerHTML = '<option value="">Chọn quận/huyện</option>';
            
            if (countryId) {
                // Filter states by country
                const states = stateData.filter(state => state.country_id == countryId);
                
                // Add options
                states.forEach(state => {
                    const option = document.createElement('option');
                    option.value = state.id;
                    option.textContent = state.name;
                    stateSelect.appendChild(option);
                });
            }
        }
        
        function updateCities() {
            const stateId = document.getElementById('state').value;
            const citySelect = document.getElementById('city');
            
            // Clear city select
            citySelect.innerHTML = '<option value="">Chọn quận/huyện</option>';
            
            if (stateId) {
                // Filter cities by state
                const cities = cityData.filter(city => city.state_id == stateId);
                
                // Add options
                cities.forEach(city => {
                    const option = document.createElement('option');
                    option.value = city.id;
                    option.textContent = city.name;
                    citySelect.appendChild(option);
                });
            }
        }
        
        // Password strength checker
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            const strengthMeter = document.getElementById('strength-meter-fill');
            const strengthText = document.getElementById('strength-text');
            
            // Reset
            strengthMeter.style.width = '0%';
            strengthMeter.style.backgroundColor = '#e5e7eb';
            
            if (!password) {
                strengthText.textContent = 'Vui lòng nhập mật khẩu';
                return;
            }
            
            let strength = 0;
            
            // Length check
            if (password.length >= 6) {
                strength += 25;
            }
            
            // Contains lowercase
            if (/[a-z]/.test(password)) {
                strength += 25;
            }
            
            // Contains uppercase
            if (/[A-Z]/.test(password)) {
                strength += 25;
            }
            
            // Contains number or special char
            if (/[0-9!@#$%^&*]/.test(password)) {
                strength += 25;
            }
            
            // Update UI
            strengthMeter.style.width = strength + '%';
            
            if (strength < 25) {
                strengthMeter.style.backgroundColor = '#ef4444';
                strengthText.textContent = 'Rất yếu';
                strengthText.style.color = '#ef4444';
            } else if (strength < 50) {
                strengthMeter.style.backgroundColor = '#f59e0b';
                strengthText.textContent = 'Yếu';
                strengthText.style.color = '#f59e0b';
            } else if (strength < 75) {
                strengthMeter.style.backgroundColor = '#10b981';
                strengthText.textContent = 'Khá mạnh';
                strengthText.style.color = '#10b981';
            } else {
                strengthMeter.style.backgroundColor = '#059669';
                strengthText.textContent = 'Mạnh';
                strengthText.style.color = '#059669';
            }
        }
        
        // AJAX helper function
        async function makeRequest(action, data) {
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
        
        // Create user
        async function createUser() {
            // Get form values
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm-password').value;
            const phone = document.getElementById('phone').value.trim();
            const userType = document.getElementById('user-type').value;
            const initialBalance = parseFloat(document.getElementById('initial-balance').value) || 0;
            const createAffiliate = document.getElementById('create-affiliate').checked;
            
            // Address info
            const address = document.getElementById('address').value.trim();
            const countryId = document.getElementById('country').value;
            const stateId = document.getElementById('state').value;
            const cityId = document.getElementById('city').value;
            const postalCode = document.getElementById('postal-code').value.trim();
            
            // Validation
            if (!name) {
                showNotification('Vui lòng nhập họ tên', 'error');
                document.getElementById('name').focus();
                return;
            }
            
            if (!email || !email.match(/^[\w-\.]+@([\w-]+\.)+[\w-]{2,4}$/)) {
                showNotification('Vui lòng nhập email hợp lệ', 'error');
                document.getElementById('email').focus();
                return;
            }
            
            if (!password || password.length < 6) {
                showNotification('Mật khẩu phải có ít nhất 6 ký tự', 'error');
                document.getElementById('password').focus();
                return;
            }
            
            if (password !== confirmPassword) {
                showNotification('Mật khẩu xác nhận không khớp', 'error');
                document.getElementById('confirm-password').focus();
                return;
            }
            
            // Show loading
            const createBtn = document.getElementById('create-user-btn');
            createBtn.disabled = true;
            createBtn.innerHTML = '<span class="loading"></span> Đang xử lý';
            
            // Prepare data
            const userData = {
                name,
                email,
                password,
                phone,
                user_type: userType,
                initial_balance: initialBalance,
                create_affiliate: createAffiliate,
                address,
                country_id: countryId,
                state_id: stateId,
                city_id: cityId,
                postal_code: postalCode
            };
            
            // Send request
            const result = await makeRequest('add_user', userData);
            
            if (result) {
                // Reset form
                resetForm();
                
                // Redirect to user details after 2 seconds
                setTimeout(() => {
                    window.location.href = 'user-details.php?id=' + result.user_id;
                }, 1500);
            } else {
                // Re-enable button
                createBtn.disabled = false;
                createBtn.innerHTML = '<span>✅</span><span>Tạo người dùng</span>';
            }
        }
        
        // Reset form
        function resetForm() {
            document.getElementById('user-form').reset();
            document.getElementById('strength-meter-fill').style.width = '0%';
            document.getElementById('strength-text').textContent = 'Vui lòng nhập mật khẩu';
            document.getElementById('strength-text').style.color = '';
            
            // Clear address dropdowns
            document.getElementById('state').innerHTML = '<option value="">Chọn tỉnh/thành phố</option>';
            document.getElementById('city').innerHTML = '<option value="">Chọn quận/huyện</option>';
            
            // Reset button
            const createBtn = document.getElementById('create-user-btn');
            createBtn.disabled = false;
            createBtn.innerHTML = '<span>✅</span><span>Tạo người dùng</span>';
        }
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // Hide and remove notification after 3 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }
        
        // Mobile responsive handling
        function handleResponsive() {
            const isDesktop = window.innerWidth > 1024;
            
            if (isDesktop && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
            
            if (!isDesktop && sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
            }
        }
        
        window.addEventListener('resize', handleResponsive);
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Add User - Initializing...');
            
            handleResponsive();
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            // Focus first field
            document.getElementById('name').focus();
            
            console.log('✅ Add User - Ready!');
        });
    </script>
</body>
</html>