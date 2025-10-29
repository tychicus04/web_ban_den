<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Check if config file exists
if (!file_exists('config.php')) {
    die('Error: config.php file not found!');
}

if (!file_exists('constants.php')) {
    die('Error: constants.php file not found!');
}

require_once 'config.php';
require_once 'constants.php';

// Check if PDO connection exists
if (!isset($pdo)) {
    die('Error: Database connection not found in config.php!');
}

// Set page-specific variables
$current_page = 'categories';
$require_login = false; // Allow guests to view categories

// Get user info if logged in
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : null;
$user_type = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : null;

// Pagination parameters - using constant from constants.php
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = ITEMS_PER_PAGE * 2; // 24 categories per page for grid layout
$offset = ($page - 1) * $limit;

// Search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$level = isset($_GET['level']) ? (int) $_GET['level'] : '';
$featured = isset($_GET['featured']) ? 1 : '';
$top = isset($_GET['top']) ? 1 : '';

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "name LIKE :search";
    $params[':search'] = "%$search%";
}

if ($level !== '') {
    $where_conditions[] = "level = :level";
    $params[':level'] = $level;
}

if ($featured !== '') {
    $where_conditions[] = "featured = 1";
}

if ($top !== '') {
    $where_conditions[] = "top = 1";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get categories with pagination
try {
    // Test database connection first
    $test_query = $pdo->query("SELECT 1");
    if (!$test_query) {
        throw new Exception("Database connection failed");
    }

    $stmt = $pdo->prepare("
        SELECT c.*, 
               (SELECT COUNT(*) FROM categories sub WHERE sub.parent_id = c.id) as subcategory_count,
               (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.published = 1 AND p.approved = 1) as product_count
        FROM categories c 
        $where_clause
        ORDER BY c.order_level ASC, c.name ASC 
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $categories = $stmt->fetchAll();

    // Get total count for pagination
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM categories c $where_clause");
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_categories = $count_stmt->fetchColumn();
    $total_pages = ceil($total_categories / $limit);
} catch (PDOException $e) {
    error_log("Database Error in categories.php: " . $e->getMessage());
    $categories = [];
    $total_categories = 0;
    $total_pages = 1;
}

// Get featured categories for highlights
try {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.published = 1 AND p.approved = 1) as product_count
        FROM categories c 
        WHERE c.featured = 1 
        ORDER BY c.order_level ASC 
        LIMIT 8
    ");
    $stmt->execute();
    $featured_categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Featured Categories Error: " . $e->getMessage());
    $featured_categories = [];
}

// Get category stats
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_categories,
            COUNT(CASE WHEN featured = 1 THEN 1 END) as featured_count,
            COUNT(CASE WHEN level = 0 THEN 1 END) as parent_count,
            COUNT(CASE WHEN level > 0 THEN 1 END) as sub_count
        FROM categories
    ");
    $stats_stmt->execute();
    $category_stats = $stats_stmt->fetch();
} catch (PDOException $e) {
    error_log("Category Stats Error: " . $e->getMessage());
    $category_stats = [
        'total_categories' => 0,
        'featured_count' => 0,
        'parent_count' => 0,
        'sub_count' => 0
    ];
}

// Function to get category image
function getCategoryImage($category, $pdo)
{
    if (!empty($category['banner'])) {
        try {
            $stmt = $pdo->prepare("SELECT file_name FROM uploads WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$category['banner']]);
            $img_result = $stmt->fetch();
            if ($img_result) {
                return $img_result['file_name'];
            }
        } catch (PDOException $e) {
            // Ignore error
        }
    }

    if (!empty($category['cover_image'])) {
        try {
            $stmt = $pdo->prepare("SELECT file_name FROM uploads WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$category['cover_image']]);
            $img_result = $stmt->fetch();
            if ($img_result) {
                return $img_result['file_name'];
            }
        } catch (PDOException $e) {
            // Ignore error
        }
    }

    return '';
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Danh mục sản phẩm - <?php echo SITE_NAME; ?></title>
    <meta name="description"
        content="Khám phá tất cả danh mục sản phẩm tại <?php echo SITE_NAME; ?>. <?php echo SITE_DESCRIPTION; ?>">
    <meta name="keywords" content="danh mục sản phẩm, categories, <?php echo SITE_KEYWORDS; ?>">
    <!-- CSS Files -->
    <link rel="stylesheet" href="asset/css/global.css">
    <link rel="stylesheet" href="asset/css/components.css">
    <link rel="stylesheet" href="asset/css/base.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="asset/css/pages/category.css">
</head>

<body>
    <?php
    if (file_exists('header.php')) {
        include 'header.php';
    } else {
        echo '<div style="padding: 20px; background: #f8f9fa; text-align: center;">Header not found</div>';
    }
    ?>

    <div class="section" style="margin-top: 40px;">
        <!-- Search and Filter Section -->
        <div class="categories-filters">
            <form method="GET" action="" class="filter-row">
                <div class="filter-group">
                    <div class="search-box">
                        <input type="text" name="search" placeholder="Tìm kiếm danh mục..."
                            value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit">🔍</button>
                    </div>

                    <select name="level" class="filter-select">
                        <option value="">Tất cả cấp độ</option>
                        <option value="0" <?php echo $level === 0 ? 'selected' : ''; ?>>Danh mục chính</option>
                        <option value="1" <?php echo $level === 1 ? 'selected' : ''; ?>>Danh mục con cấp 1</option>
                        <option value="2" <?php echo $level === 2 ? 'selected' : ''; ?>>Danh mục con cấp 2</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-checkbox">
                        <input type="checkbox" name="featured" <?php echo $featured ? 'checked' : ''; ?>>
                        Chỉ danh mục nổi bật
                    </label>

                    <label class="filter-checkbox">
                        <input type="checkbox" name="top" <?php echo $top ? 'checked' : ''; ?>>
                        Danh mục hot
                    </label>

                    <div class="view-toggle">
                        <button type="button" class="view-btn active" onclick="switchView('grid')">🔷 Lưới</button>
                        <button type="button" class="view-btn" onclick="switchView('list')">📋 Danh sách</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Featured Categories -->
        <?php if (!empty($featured_categories) && empty($search) && $level === '' && !$featured && !$top): ?>
            <div class="featured-categories">
                <div class="section-header">
                    <h2 class="section-title">Danh mục nổi bật</h2>
                </div>

                <div class="featured-grid">
                    <?php foreach ($featured_categories as $category): ?>
                        <div class="featured-category-card" onclick="navigateToProducts(<?php echo $category['id']; ?>)">
                            <span class="featured-category-icon"><?php echo getCategoryIcon($category['name']); ?></span>
                            <h3 class="featured-category-name"><?php echo htmlspecialchars($category['name']); ?></h3>
                            <p class="featured-category-count"><?php echo number_format($category['product_count']); ?> sản phẩm
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Results Header -->
        <div class="section-header">
            <h2 class="section-title">
                <?php if (!empty($search)): ?>
                    Kết quả tìm kiếm: "<?php echo htmlspecialchars($search); ?>"
                <?php else: ?>
                    Tất cả danh mục
                <?php endif; ?>
            </h2>
            <div class="section-meta">
                <span>Trang <?php echo $page; ?> / <?php echo $total_pages; ?>
                    (<?php echo number_format($total_categories); ?> danh mục)</span>
            </div>
        </div>

        <!-- Categories Grid View -->
        <div class="categories-grid-view active">
            <?php if (!empty($categories)): ?>
                <div class="categories-main-grid">
                    <?php foreach ($categories as $category): ?>
                        <?php $category_image = getCategoryImage($category, $pdo); ?>
                        <div class="category-card" onclick="navigateToProducts(<?php echo $category['id']; ?>)">
                            <div class="category-image">
                                <?php if ($category_image): ?>
                                    <img src="<?php echo htmlspecialchars($category_image); ?>"
                                        alt="<?php echo htmlspecialchars($category['name']); ?>"
                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="category-image-icon" style="display: none;">
                                        <?php echo getCategoryIcon($category['name']); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="category-image-icon">
                                        <?php echo getCategoryIcon($category['name']); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="category-badges">
                                    <?php if ($category['featured']): ?>
                                        <span class="category-badge badge-featured">Nổi bật</span>
                                    <?php endif; ?>
                                    <?php if ($category['top']): ?>
                                        <span class="category-badge badge-top">Hot</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="category-content">
                                <div class="category-header">
                                    <h3 class="category-name"><?php echo htmlspecialchars($category['name']); ?></h3>
                                    <span class="category-level">Cấp <?php echo $category['level']; ?></span>
                                </div>

                                <div class="category-meta">
                                    <span><?php echo number_format($category['product_count']); ?> sản phẩm</span>
                                    <span><?php echo number_format($category['subcategory_count']); ?> danh mục con</span>
                                </div>

                                <div class="category-actions">
                                    <button class="category-btn btn-primary"
                                        onclick="event.stopPropagation(); navigateToProducts(<?php echo $category['id']; ?>)">
                                        Xem sản phẩm
                                    </button>
                                    <?php if ($category['subcategory_count'] > 0): ?>
                                        <button class="category-btn btn-secondary"
                                            onclick="event.stopPropagation(); viewSubcategories(<?php echo $category['id']; ?>)">
                                            Danh mục con
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Categories List View -->
        <div class="categories-list-view">
            <?php if (!empty($categories)): ?>
                <?php foreach ($categories as $category): ?>
                    <div class="category-list-item" onclick="navigateToProducts(<?php echo $category['id']; ?>)">
                        <div class="category-list-icon">
                            <?php echo getCategoryIcon($category['name']); ?>
                        </div>
                        <div class="category-list-content">
                            <h3 class="category-list-name"><?php echo htmlspecialchars($category['name']); ?></h3>
                            <div class="category-list-stats">
                                <span>📦 <?php echo number_format($category['product_count']); ?> sản phẩm</span>
                                <span>🏷️ <?php echo number_format($category['subcategory_count']); ?> danh mục con</span>
                                <span>💰 Hoa hồng: <?php echo $category['commision_rate']; ?>%</span>
                                <span>📊 Cấp <?php echo $category['level']; ?></span>
                            </div>
                        </div>
                        <div class="category-list-actions">
                            <button class="category-btn btn-primary"
                                onclick="event.stopPropagation(); navigateToProducts(<?php echo $category['id']; ?>)">
                                Xem sản phẩm
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Empty State -->
        <?php if (empty($categories)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📭</div>
                <h3 class="empty-state-title">Không tìm thấy danh mục nào</h3>
                <p class="empty-state-description">
                    <?php if (!empty($search)): ?>
                        Thử tìm kiếm với từ khóa khác hoặc xóa bộ lọc
                    <?php else: ?>
                        Hiện tại chưa có danh mục nào được thêm vào hệ thống
                    <?php endif; ?>
                </p>
                <button class="empty-state-action" onclick="clearFilters()">
                    <?php echo !empty($search) ? 'Xóa bộ lọc' : 'Về trang chủ'; ?>
                </button>
            </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
                        class="pagination-btn">‹ Trước</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                        class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
                        class="pagination-btn">Tiếp ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php
    if (file_exists('footer.php')) {
        include 'footer.php';
    } else {
        echo '<div style="padding: 20px; background: #f8f9fa; text-align: center;">Footer not found</div>';
    }
    ?>

    <script>
    // Navigation functions
    function navigateToProducts(categoryId) {
        window.location.href = `products.php?category_id=${categoryId}`;
    }

    function viewSubcategories(categoryId) {
        window.location.href = `categories.php?parent_id=${categoryId}`;
    }

    // View switching
    function switchView(viewType) {
        const gridView = document.querySelector('.categories-grid-view');
        const listView = document.querySelector('.categories-list-view');
        const gridBtn = document.querySelector('.view-btn:first-child');
        const listBtn = document.querySelector('.view-btn:last-child');

        if (viewType === 'grid') {
            gridView.classList.add('active');
            listView.classList.remove('active');
            gridBtn.classList.add('active');
            listBtn.classList.remove('active');
        } else {
            gridView.classList.remove('active');
            listView.classList.add('active');
            gridBtn.classList.remove('active');
            listBtn.classList.add('active');
        }

        // Save preference
        localStorage.setItem('categoriesViewType', viewType);
    }

    // Clear all filters
    function clearFilters() {
        window.location.href = '<?php echo !empty($search) ? "categories.php" : "index.php"; ?>';
    }

    // Auto-submit form on checkbox change
    document.querySelectorAll('.filter-checkbox input[type="checkbox"]').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            this.closest('form').submit();
        });
    });

    // Auto-submit form on select change
    document.querySelectorAll('.filter-select').forEach(select => {
        select.addEventListener('change', function() {
            this.closest('form').submit();
        });
    });

    // Initialize view preference
    document.addEventListener('DOMContentLoaded', function() {
        const savedViewType = localStorage.getItem('categoriesViewType');
        if (savedViewType) {
            switchView(savedViewType);
        }

        // Add smooth animations
        const cards = document.querySelectorAll('.category-card, .category-list-item');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
            card.style.animation = 'slideInFromBottom 0.6s ease-out forwards';
        });
    });

    // CSS animation for slideInFromBottom
    const style = document.createElement('style');
    style.textContent = `
            @keyframes slideInFromBottom {
                from {
                    transform: translateY(30px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
        `;
    document.head.appendChild(style);
    </script>
    <!-- JavaScript Files -->
    <script src="asset/js/global.js"></script>
    <script src="asset/js/components.js"></script>
</body>

</html>