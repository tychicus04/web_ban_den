# Includes - Shared PHP Utilities

This folder contains reusable PHP code that was previously duplicated across multiple files in the codebase.

## Files Overview

### `helpers.php`
Common utility functions used throughout the application.

**Key Functions:**
- `safe_echo()` - XSS-safe output with null handling
- `get_value()` - Safe array access with default values
- `has_value()` - Check if array key exists and is not null
- `formatPrice()`, `formatDate()`, `formatDateTime()` - Vietnamese formatting
- `sanitizeString()`, `sanitizeInt()`, `sanitizeFloat()` - Input sanitization
- `generateSlug()`, `truncateText()` - String utilities
- `getCategoryIcon()` - Get category emoji icons
- `calculateDiscount()` - Calculate discount percentage
- `getOrderStatusBadge()`, `getPaymentStatusBadge()` - Status badges
- `timeAgo()` - Human-readable time differences
- `redirect()` - HTTP redirects
- `isAjaxRequest()`, `isPostRequest()`, `isGetRequest()` - Request type checks

**Usage:**
```php
require_once 'includes/helpers.php';

echo safe_echo($user_input);
$price = formatPrice(1000000); // "1.000.000đ"
$date = formatDate('2024-01-15'); // "15/01/2024"
```

### `admin_init.php`
Admin page initialization and authentication.

**Features:**
- Automatic admin authentication check
- Session timeout management (8 hours)
- CSRF token generation
- Admin info fetching
- Permission checking
- Action logging
- Admin statistics

**Usage:**
```php
// Option 1: Auto-init with authentication
require_once __DIR__ . '/../includes/admin_init.php';

// Option 2: Manual init with custom options
define('NO_AUTO_INIT', true);
require_once __DIR__ . '/../includes/admin_init.php';
$admin = initAdminPage(true, true); // requireAuth, fetchAdminInfo

// Option 3: Get admin info separately
$admin = getAdminInfo();
```

**Functions:**
- `checkAdminAuth()` - Check admin authentication
- `getAdminInfo()` - Get admin user details
- `initAdminCSRFToken()` - Initialize CSRF token
- `verifyAdminCSRFToken()` - Verify CSRF token
- `initAdminPage()` - Complete admin page initialization
- `hasAdminPermission()` - Check admin permissions
- `logAdminAction()` - Log admin actions
- `getAdminStats()` - Get dashboard statistics

### `response.php`
Standardized JSON response utilities for API/AJAX endpoints.

**Key Functions:**
- `sendJson()` - Send JSON response
- `sendSuccess()` - Send success response
- `sendError()` - Send error response
- `sendValidationError()` - Send validation errors
- `sendUnauthorized()`, `sendForbidden()`, `sendNotFound()`, `sendServerError()` - HTTP status responses
- `sendPaginatedResponse()` - Send paginated data
- `sendCreated()`, `sendNoContent()` - RESTful responses
- `validateRequired()` - Validate required fields
- `validateEmailField()`, `validatePhoneField()`, `validatePasswordField()` - Field validators
- `requirePostMethod()`, `requireAjax()` - Request type guards
- `getJsonInput()` - Get JSON from request body

**Usage:**
```php
require_once __DIR__ . '/../includes/response.php';

// Success response
sendSuccess(['users' => $users], 'Users fetched successfully');

// Error response
sendError('User not found', 404);

// Validation
$errors = validateRequired($_POST, ['name', 'email', 'password']);
if ($errors !== true) {
    sendValidationError($errors);
}

// Paginated response
sendPaginatedResponse($items, $total, $page, $perPage);
```

### `init.php`
Frontend initialization for customer-facing pages.

**Features:**
- Session management
- Cart operations
- Product queries
- Category queries
- Search functionality
- SEO metadata
- Breadcrumb generation

**Functions:**
- `getCartCount()`, `getCartItems()`, `getCartTotal()` - Cart functions
- `addToCart()`, `removeFromCart()`, `clearCart()` - Cart operations
- `getFeaturedProducts()` - Get featured products
- `getCategories()` - Get categories with product count
- `getProduct()` - Get product by ID
- `searchProducts()` - Search products
- `getPageMetadata()` - Get SEO metadata
- `generateBreadcrumb()` - Generate breadcrumb HTML

**Usage:**
```php
require_once 'includes/init.php';

$cart_count = getCartCount();
$featured = getFeaturedProducts(8);
$categories = getCategories();

addToCart($product_id, $quantity);
```

## Migration Guide

### Before (Old Code)
```php
<?php
session_start();
require_once '../config.php';

// Helper functions
function safe_echo($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

function get_value($array, $key, $default = '') {
    return isset($array[$key]) ? $array[$key] : $default;
}

// Authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$db = getDBConnection();
// ... rest of code
?>
```

### After (New Code)
```php
<?php
require_once __DIR__ . '/../includes/admin_init.php';

// All authentication, session, helpers are auto-loaded
// $pdo is available
// safe_echo(), get_value(), has_value() are available

// Optional: Get admin info
$admin = getAdminInfo();

// ... rest of code
?>
```

## Benefits

✅ **Reduced Duplication**: Eliminated 12+ duplicate function definitions
✅ **Consistent Code**: Same helpers used everywhere
✅ **Easier Maintenance**: Update once, affects all files
✅ **Better Security**: Centralized security checks
✅ **Faster Development**: Reusable utilities
✅ **Better Testing**: Can test helper functions in isolation

## Statistics

- **Helper functions eliminated**: 12 duplicates → 1 source
- **Admin files affected**: 23+ files
- **Lines of code removed**: ~300+ duplicate lines
- **Security improvements**: Centralized auth & CSRF protection
