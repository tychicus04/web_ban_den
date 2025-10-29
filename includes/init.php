<?php
/**
 * Frontend Initialization
 * Common initialization code for frontend pages
 *
 * This file consolidates frontend session management and initialization
 * code for customer-facing pages.
 *
 * @author TK-MALL Development Team
 * @version 2.0.0
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include required files
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../security-headers.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/response.php';

// Set security headers
setSecurityHeaders();

/**
 * Get cart count for current user
 *
 * @return int Cart item count
 */
function getCartCount()
{
    global $pdo;

    if (!isLoggedIn()) {
        // Check session cart for non-logged in users
        return isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(quantity), 0)
            FROM cart
            WHERE user_id = ?
        ");
        $stmt->execute([getCurrentUserId()]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error getting cart count: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get cart items for current user
 *
 * @return array Cart items
 */
function getCartItems()
{
    global $pdo;

    if (!isLoggedIn()) {
        return $_SESSION['cart'] ?? [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT c.*, p.name, p.image, p.price, p.sale_price
            FROM cart c
            JOIN products p ON c.product_id = p.id
            WHERE c.user_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([getCurrentUserId()]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting cart items: " . $e->getMessage());
        return [];
    }
}

/**
 * Get cart total for current user
 *
 * @return float Cart total
 */
function getCartTotal()
{
    $items = getCartItems();
    $total = 0;

    foreach ($items as $item) {
        $price = $item['sale_price'] ?? $item['price'];
        $quantity = $item['quantity'] ?? 1;
        $total += $price * $quantity;
    }

    return $total;
}

/**
 * Add item to cart
 *
 * @param int $productId Product ID
 * @param int $quantity Quantity (default: 1)
 * @return bool True on success, false on failure
 */
function addToCart($productId, $quantity = 1)
{
    global $pdo;

    if (!isLoggedIn()) {
        // Add to session cart for non-logged in users
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        if (isset($_SESSION['cart'][$productId])) {
            $_SESSION['cart'][$productId] += $quantity;
        } else {
            $_SESSION['cart'][$productId] = $quantity;
        }

        return true;
    }

    try {
        // Check if item already in cart
        $stmt = $pdo->prepare("
            SELECT id, quantity
            FROM cart
            WHERE user_id = ? AND product_id = ?
        ");
        $stmt->execute([getCurrentUserId(), $productId]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update quantity
            $stmt = $pdo->prepare("
                UPDATE cart
                SET quantity = quantity + ?
                WHERE id = ?
            ");
            $stmt->execute([$quantity, $existing['id']]);
        } else {
            // Insert new item
            $stmt = $pdo->prepare("
                INSERT INTO cart (user_id, product_id, quantity, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([getCurrentUserId(), $productId, $quantity]);
        }

        return true;
    } catch (PDOException $e) {
        error_log("Error adding to cart: " . $e->getMessage());
        return false;
    }
}

/**
 * Remove item from cart
 *
 * @param int $productId Product ID
 * @return bool True on success, false on failure
 */
function removeFromCart($productId)
{
    global $pdo;

    if (!isLoggedIn()) {
        if (isset($_SESSION['cart'][$productId])) {
            unset($_SESSION['cart'][$productId]);
        }
        return true;
    }

    try {
        $stmt = $pdo->prepare("
            DELETE FROM cart
            WHERE user_id = ? AND product_id = ?
        ");
        $stmt->execute([getCurrentUserId(), $productId]);
        return true;
    } catch (PDOException $e) {
        error_log("Error removing from cart: " . $e->getMessage());
        return false;
    }
}

/**
 * Clear cart for current user
 *
 * @return bool True on success, false on failure
 */
function clearCart()
{
    global $pdo;

    if (!isLoggedIn()) {
        $_SESSION['cart'] = [];
        return true;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
        $stmt->execute([getCurrentUserId()]);
        return true;
    } catch (PDOException $e) {
        error_log("Error clearing cart: " . $e->getMessage());
        return false;
    }
}

/**
 * Get featured products
 *
 * @param int $limit Number of products to fetch (default: 8)
 * @return array Featured products
 */
function getFeaturedProducts($limit = 8)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM products
            WHERE featured = 1 AND active = 1
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting featured products: " . $e->getMessage());
        return [];
    }
}

/**
 * Get categories with product count
 *
 * @return array Categories
 */
function getCategories()
{
    global $pdo;

    try {
        $stmt = $pdo->query("
            SELECT c.*, COUNT(p.id) as product_count
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id AND p.active = 1
            GROUP BY c.id
            ORDER BY c.name ASC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting categories: " . $e->getMessage());
        return [];
    }
}

/**
 * Get product by ID
 *
 * @param int $productId Product ID
 * @return array|false Product data or false
 */
function getProduct($productId)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT p.*, c.name as category_name, b.name as brand_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$productId]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting product: " . $e->getMessage());
        return false;
    }
}

/**
 * Search products
 *
 * @param string $query Search query
 * @param int $limit Result limit (default: 20)
 * @param int $offset Result offset (default: 0)
 * @return array Search results
 */
function searchProducts($query, $limit = 20, $offset = 0)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM products
            WHERE active = 1
            AND (name LIKE ? OR description LIKE ?)
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");

        $searchTerm = '%' . $query . '%';
        $stmt->execute([$searchTerm, $searchTerm, $limit, $offset]);

        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error searching products: " . $e->getMessage());
        return [];
    }
}

/**
 * Get page metadata for SEO
 *
 * @param string $pageTitle Page title
 * @param string $description Page description (optional)
 * @param string $keywords Page keywords (optional)
 * @return array Page metadata
 */
function getPageMetadata($pageTitle, $description = null, $keywords = null)
{
    return [
        'title' => $pageTitle . ' - ' . SITE_NAME,
        'description' => $description ?? SITE_DESCRIPTION,
        'keywords' => $keywords ?? SITE_KEYWORDS,
        'og_title' => $pageTitle,
        'og_description' => $description ?? SITE_DESCRIPTION,
        'og_image' => getBaseUrl() . '/asset/images/og-image.jpg'
    ];
}

/**
 * Generate breadcrumb HTML
 *
 * @param array $items Breadcrumb items [['title' => '', 'url' => '']]
 * @return string Breadcrumb HTML
 */
function generateBreadcrumb($items)
{
    $html = '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
    $html .= '<li class="breadcrumb-item"><a href="index.php">Trang chủ</a></li>';

    foreach ($items as $index => $item) {
        $isLast = $index === count($items) - 1;

        if ($isLast) {
            $html .= '<li class="breadcrumb-item active" aria-current="page">';
            $html .= safe_echo($item['title']);
            $html .= '</li>';
        } else {
            $html .= '<li class="breadcrumb-item">';
            $html .= '<a href="' . safe_echo($item['url']) . '">';
            $html .= safe_echo($item['title']);
            $html .= '</a></li>';
        }
    }

    $html .= '</ol></nav>';
    return $html;
}

// Auto-initialize cart count in session for quick access
if (!isset($_SESSION['cart_count'])) {
    $_SESSION['cart_count'] = getCartCount();
}
?>
