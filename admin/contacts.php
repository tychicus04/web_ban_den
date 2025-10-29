<?php
/**
 * Admin Contacts Page
 *
 * @refactored Uses centralized admin_init.php for authentication and helpers
 */

// Initialize admin page with authentication and admin info
require_once __DIR__ . '/../includes/admin_init.php';
$admin = initAdminPage(true, true);
$db = getDB();

// Pagination setup
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;
$offset = ($page - 1) * $per_page;

// Search/filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Verify CSRF token
    if (!isset($_POST['token']) || !hash_equals($_SESSION['admin_token'], $_POST['token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    if (!isset($_POST['contact_id']) || !is_numeric($_POST['contact_id'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid contact ID']);
        exit;
    }
    
    $contact_id = intval($_POST['contact_id']);
    
    try {
        switch ($_POST['action']) {
            case 'mark_read':
                $stmt = $db->prepare("UPDATE contacts SET viewed = 1 WHERE id = ?");
                $stmt->execute([$contact_id]);
                echo json_encode(['success' => true, 'message' => 'Đã đánh dấu là đã đọc']);
                break;
                
            case 'mark_unread':
                $stmt = $db->prepare("UPDATE contacts SET viewed = 0 WHERE id = ?");
                $stmt->execute([$contact_id]);
                echo json_encode(['success' => true, 'message' => 'Đã đánh dấu là chưa đọc']);
                break;
                
            case 'delete_contact':
                $stmt = $db->prepare("DELETE FROM contacts WHERE id = ?");
                $stmt->execute([$contact_id]);
                echo json_encode(['success' => true, 'message' => 'Liên hệ đã được xóa']);
                break;
                
            case 'reply_contact':
                if (!isset($_POST['reply']) || empty($_POST['reply'])) {
                    echo json_encode(['success' => false, 'message' => 'Nội dung phản hồi không được để trống']);
                    break;
                }
                
                $reply = trim($_POST['reply']);
                
                // Update the contact with the reply
                $stmt = $db->prepare("UPDATE contacts SET reply = ?, viewed = 1, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$reply, $contact_id]);
                
                // Get contact details for email
                $stmt = $db->prepare("SELECT name, email, content FROM contacts WHERE id = ?");
                $stmt->execute([$contact_id]);
                $contact = $stmt->fetch();
                
                if ($contact) {
                    // Send email to the customer (implement your email sending function)
                    $site_name = getBusinessSetting($db, 'site_name', 'Your Store');
                    $site_email = getBusinessSetting($db, 'contact_email', 'noreply@example.com');
                    
                    // Email sending logic would go here
                    // sendEmail($contact['email'], "Re: Your inquiry at $site_name", $reply, $site_email);
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Phản hồi đã được gửi',
                        'reply' => $reply,
                        'date' => date('d/m/Y H:i')
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Không tìm thấy liên hệ']);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("Contact action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Build the query based on filters
$params = [];
$query = "SELECT * FROM contacts WHERE 1=1";

if (!empty($search)) {
    $query .= " AND (name LIKE ? OR email LIKE ? OR content LIKE ? OR phone LIKE ?)";
    $search_param = "%$search%";
    array_push($params, $search_param, $search_param, $search_param, $search_param);
}

if ($status === 'read') {
    $query .= " AND viewed = 1";
} elseif ($status === 'unread') {
    $query .= " AND viewed = 0";
} elseif ($status === 'replied') {
    $query .= " AND reply IS NOT NULL AND reply != ''";
} elseif ($status === 'not_replied') {
    $query .= " AND (reply IS NULL OR reply = '')";
}

if (!empty($date_from)) {
    $query .= " AND DATE(created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND DATE(created_at) <= ?";
    $params[] = $date_to;
}

// Count total records for pagination
$count_query = str_replace("SELECT *", "SELECT COUNT(*) as total", $query);
$stmt = $db->prepare($count_query);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'] ?? 0;
$total_pages = ceil($total_records / $per_page);

// Sorting
if ($sort === 'oldest') {
    $query .= " ORDER BY created_at ASC";
} elseif ($sort === 'a-z') {
    $query .= " ORDER BY name ASC";
} elseif ($sort === 'z-a') {
    $query .= " ORDER BY name DESC";
} else { // default: newest
    $query .= " ORDER BY created_at DESC";
}

// Add pagination
$query .= " LIMIT $per_page OFFSET $offset";

// Fetch contacts
try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $contacts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Contact fetch error: " . $e->getMessage());
    $contacts = [];
}

$site_name = getBusinessSetting($db, 'site_name', 'Your Store');
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý liên hệ - Admin <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Quản lý liên hệ - Admin <?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Preload critical fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../asset/css/pages/admin-contacts.css">
    <link rel="stylesheet" href="../asset/css/pages/admin-sidebar.css">
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
                        <div class="breadcrumb-item">
                            <a href="dashboard.php">Admin</a>
                        </div>
                        <span class="breadcrumb-separator">›</span>
                        <div class="breadcrumb-item">
                            <span>Liên hệ</span>
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
                                <div class="user-role"><?php echo htmlspecialchars($admin['role_name'] ?? 'Administrator'); ?></div>
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
                    <div class="page-title-wrapper">
                        <h1 class="page-title">Quản lý liên hệ</h1>
                        <p class="page-subtitle">Quản lý và phản hồi các tin nhắn liên hệ từ khách hàng</p>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="filter-section">
                    <form action="" method="GET" class="filter-form">
                        <div class="form-group">
                            <label for="search" class="form-label">Tìm kiếm</label>
                            <input type="text" id="search" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Tìm theo tên, email, nội dung...">
                        </div>
                        
                        <div class="form-group">
                            <label for="status" class="form-label">Trạng thái</label>
                            <select id="status" name="status" class="form-control">
                                <option value="" <?php echo $status === '' ? 'selected' : ''; ?>>Tất cả trạng thái</option>
                                <option value="read" <?php echo $status === 'read' ? 'selected' : ''; ?>>Đã đọc</option>
                                <option value="unread" <?php echo $status === 'unread' ? 'selected' : ''; ?>>Chưa đọc</option>
                                <option value="replied" <?php echo $status === 'replied' ? 'selected' : ''; ?>>Đã phản hồi</option>
                                <option value="not_replied" <?php echo $status === 'not_replied' ? 'selected' : ''; ?>>Chưa phản hồi</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="date_from" class="form-label">Từ ngày</label>
                            <input type="date" id="date_from" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="date_to" class="form-label">Đến ngày</label>
                            <input type="date" id="date_to" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="sort" class="form-label">Sắp xếp</label>
                            <select id="sort" name="sort" class="form-control">
                                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Mới nhất</option>
                                <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Cũ nhất</option>
                                <option value="a-z" <?php echo $sort === 'a-z' ? 'selected' : ''; ?>>A-Z</option>
                                <option value="z-a" <?php echo $sort === 'z-a' ? 'selected' : ''; ?>>Z-A</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="per_page" class="form-label">Hiển thị</label>
                            <select id="per_page" name="per_page" class="form-control">
                                <option value="20" <?php echo $per_page === 20 ? 'selected' : ''; ?>>20 liên hệ</option>
                                <option value="50" <?php echo $per_page === 50 ? 'selected' : ''; ?>>50 liên hệ</option>
                                <option value="100" <?php echo $per_page === 100 ? 'selected' : ''; ?>>100 liên hệ</option>
                            </select>
                        </div>
                        
                        <div class="form-group" style="display: flex; align-items: flex-end;">
                            <button type="submit" class="btn btn-primary">
                                <span>🔍</span>
                                <span>Tìm kiếm</span>
                            </button>
                            
                            <a href="contacts.php" class="btn btn-secondary" style="margin-left: 10px;">
                                <span>🔄</span>
                                <span>Đặt lại</span>
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Contacts List -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Danh sách liên hệ (<?php echo $total_records; ?>)</h2>
                    </div>
                    <div class="card-body">
                        <?php if (empty($contacts)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">📭</div>
                                <h3 class="empty-state-text">Không có liên hệ nào</h3>
                                <p class="empty-state-subtext">Chưa có tin nhắn liên hệ nào từ khách hàng hoặc tất cả các tin nhắn đã được xử lý.</p>
                            </div>
                        <?php else: ?>
                            <div class="contact-list">
                                <?php foreach ($contacts as $contact): ?>
                                    <div class="contact-card <?php echo ($contact['viewed'] == 0) ? 'unread' : ((!empty($contact['reply'])) ? 'replied' : ''); ?>" onclick="viewContactDetails(<?php echo $contact['id']; ?>)">
                                        <div class="contact-header">
                                            <div class="contact-title"><?php echo htmlspecialchars($contact['name']); ?></div>
                                            <div class="contact-meta">
                                                <?php if ($contact['viewed'] == 0): ?>
                                                    <span class="status-badge unread">Chưa đọc</span>
                                                <?php elseif (!empty($contact['reply'])): ?>
                                                    <span class="status-badge replied">Đã phản hồi</span>
                                                <?php else: ?>
                                                    <span class="status-badge read">Đã đọc</span>
                                                <?php endif; ?>
                                                <span><?php echo formatDate($contact['created_at']); ?></span>
                                            </div>
                                        </div>
                                        <div class="contact-body">
                                            <div class="contact-info">
                                                <div class="contact-label">Email:</div>
                                                <div class="contact-value"><?php echo htmlspecialchars($contact['email']); ?></div>
                                                
                                                <?php if (!empty($contact['phone'])): ?>
                                                <div class="contact-label">Điện thoại:</div>
                                                <div class="contact-value"><?php echo htmlspecialchars($contact['phone']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="contact-message">
                                                <?php echo htmlspecialchars(truncateText($contact['content'], 150)); ?>
                                            </div>
                                            <?php if (!empty($contact['reply'])): ?>
                                            <div class="contact-reply">
                                                <strong>Phản hồi:</strong> <?php echo htmlspecialchars(truncateText($contact['reply'], 100)); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="contact-footer">
                                            <button class="btn btn-secondary btn-sm" onclick="event.stopPropagation(); viewContactDetails(<?php echo $contact['id']; ?>)">
                                                <span>👁️</span>
                                                <span>Xem chi tiết</span>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <div class="pagination">
                                    <?php
                                    // Previous page link
                                    if ($page > 1) {
                                        echo '<a href="?page=' . ($page - 1) . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&status=' . $status . '&date_from=' . $date_from . '&date_to=' . $date_to . '&sort=' . $sort . '" class="pagination-link">«</a>';
                                    }
                                    
                                    // Page numbers
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if ($start_page > 1) {
                                        echo '<a href="?page=1&per_page=' . $per_page . '&search=' . urlencode($search) . '&status=' . $status . '&date_from=' . $date_from . '&date_to=' . $date_to . '&sort=' . $sort . '" class="pagination-link">1</a>';
                                        if ($start_page > 2) {
                                            echo '<span class="pagination-ellipsis">…</span>';
                                        }
                                    }
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++) {
                                        echo '<a href="?page=' . $i . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&status=' . $status . '&date_from=' . $date_from . '&date_to=' . $date_to . '&sort=' . $sort . '" class="pagination-link' . ($i == $page ? ' active' : '') . '">' . $i . '</a>';
                                    }
                                    
                                    if ($end_page < $total_pages) {
                                        if ($end_page < $total_pages - 1) {
                                            echo '<span class="pagination-ellipsis">…</span>';
                                        }
                                        echo '<a href="?page=' . $total_pages . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&status=' . $status . '&date_from=' . $date_from . '&date_to=' . $date_to . '&sort=' . $sort . '" class="pagination-link">' . $total_pages . '</a>';
                                    }
                                    
                                    // Next page link
                                    if ($page < $total_pages) {
                                        echo '<a href="?page=' . ($page + 1) . '&per_page=' . $per_page . '&search=' . urlencode($search) . '&status=' . $status . '&date_from=' . $date_from . '&date_to=' . $date_to . '&sort=' . $sort . '" class="pagination-link">»</a>';
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Contact Details Modal -->
    <div id="contactModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Chi tiết liên hệ</h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalContent">
                <!-- Content will be loaded dynamically -->
                <div style="text-align: center; padding: var(--space-6);">
                    <p>Đang tải...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Đóng</button>
                <div id="contactActions" class="btn-group"></div>
            </div>
        </div>
    </div>

    <script>
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
        
        // Contact details modal
        const modal = document.getElementById('contactModal');
        const modalContent = document.getElementById('modalContent');
        const contactActions = document.getElementById('contactActions');
        
        // Current contact ID for actions
        let currentContactId = null;
        
        // Open modal and load contact details
        function viewContactDetails(contactId) {
            currentContactId = contactId;
            
            // Find the contact card
            const contactCard = document.querySelector(`.contact-card[onclick*="${contactId}"]`);
            if (!contactCard) return;
            
            // Extract details from the card
            const name = contactCard.querySelector('.contact-title').textContent.trim();
            const statusBadge = contactCard.querySelector('.status-badge').cloneNode(true);
            const createdAt = contactCard.querySelector('.contact-meta span:last-child').textContent.trim();
            const email = contactCard.querySelector('.contact-value').textContent.trim();
            const phone = contactCard.querySelector('.contact-value:nth-of-type(2)')?.textContent.trim() || 'Không có';
            const message = contactCard.querySelector('.contact-message').textContent.trim();
            const reply = contactCard.querySelector('.contact-reply')?.textContent.replace('Phản hồi:', '').trim();
            
            const isUnread = statusBadge.classList.contains('unread');
            const isReplied = statusBadge.classList.contains('replied');
            
            // Build modal content
            let modalHtml = `
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h4 style="margin-bottom: 10px;">${name}</h4>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            ${statusBadge.outerHTML}
                            <span>${createdAt}</span>
                        </div>
                    </div>
                    <div style="background: var(--gray-50); padding: 10px; border-radius: 8px; margin-bottom: 15px;">
                        <div><strong>Email:</strong> ${email}</div>
                        <div><strong>Điện thoại:</strong> ${phone}</div>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h4 style="margin-bottom: 10px;">Nội dung liên hệ</h4>
                    <div style="background: var(--gray-50); padding: 15px; border-radius: 8px; white-space: pre-wrap;">${message}</div>
                </div>
            `;
            
            // Add reply section if exists
            if (isReplied && reply) {
                modalHtml += `
                    <div style="margin-bottom: 20px;">
                        <h4 style="margin-bottom: 10px;">Phản hồi</h4>
                        <div style="background: #f0f7ff; padding: 15px; border-radius: 8px; border-left: 2px solid var(--primary); white-space: pre-wrap;">${reply}</div>
                    </div>
                `;
            }
            
            // Add reply form if not replied yet
            if (!isReplied) {
                modalHtml += `
                    <div class="reply-form">
                        <h4 style="margin-bottom: 10px;">Gửi phản hồi</h4>
                        <textarea id="replyText" class="reply-textarea" placeholder="Nhập nội dung phản hồi..."></textarea>
                        <button type="button" class="btn btn-primary" onclick="sendReply()">
                            <span>📤</span>
                            <span>Gửi phản hồi</span>
                        </button>
                    </div>
                `;
            }
            
            modalContent.innerHTML = modalHtml;
            
            // Set action buttons
            contactActions.innerHTML = '';
            
            if (isUnread) {
                contactActions.innerHTML += `
                    <button class="btn btn-success" onclick="markAsRead()">
                        <span>✓</span>
                        <span>Đánh dấu đã đọc</span>
                    </button>
                `;
            } else {
                contactActions.innerHTML += `
                    <button class="btn btn-warning" onclick="markAsUnread()">
                        <span>✗</span>
                        <span>Đánh dấu chưa đọc</span>
                    </button>
                `;
            }
            
            contactActions.innerHTML += `
                <button class="btn btn-danger" onclick="deleteContact()">
                    <span>🗑️</span>
                    <span>Xóa</span>
                </button>
            `;
            
            // Mark as read if unread
            if (isUnread) {
                markAsRead(false); // Don't reload page
            }
            
            modal.style.display = 'block';
        }
        
        function closeModal() {
            modal.style.display = 'none';
            currentContactId = null;
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });
        
        // Contact actions
        async function markAsRead(reload = true) {
            if (!currentContactId) return;
            
            const success = await makeRequest('mark_read', { contact_id: currentContactId });
            if (success && reload) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function markAsUnread() {
            if (!currentContactId) return;
            
            const success = await makeRequest('mark_unread', { contact_id: currentContactId });
            if (success) {
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function deleteContact() {
            if (!currentContactId) return;
            
            if (!confirm('Bạn có chắc chắn muốn xóa liên hệ này? Hành động này không thể hoàn tác.')) {
                return;
            }
            
            const success = await makeRequest('delete_contact', { contact_id: currentContactId });
            if (success) {
                closeModal();
                setTimeout(() => window.location.reload(), 1000);
            }
        }
        
        async function sendReply() {
            if (!currentContactId) return;
            
            const replyText = document.getElementById('replyText').value.trim();
            if (!replyText) {
                showNotification('Vui lòng nhập nội dung phản hồi', 'error');
                return;
            }
            
            const result = await makeRequest('reply_contact', { 
                contact_id: currentContactId,
                reply: replyText
            });
            
            if (result.success) {
                // Update the modal to show the reply
                const replyForm = document.querySelector('.reply-form');
                if (replyForm) {
                    const replySection = document.createElement('div');
                    replySection.style.marginBottom = '20px';
                    replySection.innerHTML = `
                        <h4 style="margin-bottom: 10px;">Phản hồi</h4>
                        <div style="background: #f0f7ff; padding: 15px; border-radius: 8px; border-left: 2px solid var(--primary); white-space: pre-wrap;">${replyText}</div>
                    `;
                    replyForm.parentNode.insertBefore(replySection, replyForm);
                    replyForm.remove();
                }
                
                // Update the contact card on the page
                const contactCard = document.querySelector(`.contact-card[onclick*="${currentContactId}"]`);
                if (contactCard) {
                    contactCard.classList.add('replied');
                    contactCard.classList.remove('unread');
                    
                    const statusBadge = contactCard.querySelector('.status-badge');
                    if (statusBadge) {
                        statusBadge.className = 'status-badge replied';
                        statusBadge.textContent = 'Đã phản hồi';
                    }
                    
                    const contactBody = contactCard.querySelector('.contact-body');
                    if (contactBody) {
                        let replyDiv = contactBody.querySelector('.contact-reply');
                        if (!replyDiv) {
                            replyDiv = document.createElement('div');
                            replyDiv.className = 'contact-reply';
                            contactBody.appendChild(replyDiv);
                        }
                        replyDiv.innerHTML = `<strong>Phản hồi:</strong> ${truncateText(replyText, 100)}`;
                    }
                }
                
                // Update the action buttons
                contactActions.innerHTML = `
                    <button class="btn btn-warning" onclick="markAsUnread()">
                        <span>✗</span>
                        <span>Đánh dấu chưa đọc</span>
                    </button>
                    <button class="btn btn-danger" onclick="deleteContact()">
                        <span>🗑️</span>
                        <span>Xóa</span>
                    </button>
                `;
            }
        }
        
        // Helper function to truncate text
        function truncateText(text, length = 100) {
            if (text.length <= length) return text;
            return text.substring(0, length) + '...';
        }
        
        // AJAX helper function
        async function makeRequest(action, data = {}) {
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
        
        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
                setTimeout(() => {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }
        
        // Form submission for filters
        document.getElementById('per_page').addEventListener('change', function() {
            this.form.submit();
        });
        
        document.getElementById('sort').addEventListener('change', function() {
            this.form.submit();
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Contacts Management - Initializing...');
            
            // Add loading completion indicator
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.3s ease';
                document.body.style.opacity = '1';
            }, 100);
            
            console.log('✅ Contacts Management - Ready!');
        });
    </script>
</body>
</html>