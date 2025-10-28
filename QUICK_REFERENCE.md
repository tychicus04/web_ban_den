# TK-MALL Platform - Quick Reference Guide

## Key Directory Structure

```
/web_ban_den/
├── Core Pages (Customer)
│   ├── index.php              Homepage
│   ├── login.php              Customer login
│   ├── register.php           User registration
│   ├── profile.php            User profile management
│   ├── products.php           Browse products
│   ├── product-detail.php     Single product view
│   ├── categories.php         Browse categories
│   ├── cart.php               Shopping cart
│   ├── checkout.php           Checkout & payment
│   ├── orders.php             Order history
│   └── sellers.php            Seller marketplace
│
├── /admin/                    ADMIN DASHBOARD
│   ├── login.php              Admin authentication
│   ├── dashboard.php          Admin homepage
│   ├── products.php           Manage products
│   ├── orders.php             Manage orders
│   ├── sellers.php            Manage sellers & approval
│   ├── users.php              User management
│   ├── categories.php         Category management
│   ├── seller-package.php     Seller packages
│   └── [19 more files]        Other admin functions
│
├── /seller/                   SELLER DASHBOARD
│   ├── login.php              Seller authentication
│   ├── dashboard.php          Seller homepage
│   ├── products.php           Seller's products
│   ├── add-product.php        Create product
│   ├── orders.php             Seller's orders
│   ├── finance.php            Financial dashboard
│   ├── withdraw.php           Request withdrawal
│   ├── packages.php           Manage subscription
│   ├── store-settings.php     Shop settings
│   └── [7 more files]         Other seller features
│
├── /asset/
│   ├── /css/
│   │   ├── global.css         Variables & base styles
│   │   ├── base.css           Layout (header, nav, footer)
│   │   ├── components.css     UI components
│   │   ├── utilities.css      Utility classes
│   │   ├── forms.css          Form styling
│   │   ├── tables.css         Table styling
│   │   ├── admin.css          Admin overrides
│   │   └── /pages/
│   │       └── product.css    Product page styles
│   │
│   └── /js/
│       ├── global.js          Utilities & AJAX
│       ├── components.js      UI components
│       ├── forms.js           Form validation
│       ├── admin.js           Admin functionality
│       ├── modals.js          Modal dialogs
│       └── /pages/
│           └── product.js     Product page JS
│
├── Config Files
│   ├── config.php             Database connection
│   ├── constants.php          App settings & menus
│   ├── auth.php               Authentication functions
│   ├── csrf.php               CSRF token handling
│   ├── security-headers.php   Security headers
│   ├── header.php             Header template
│   └── footer.php             Footer template
│
└── Database
    └── u350721386_activeCMSECOM.sql (Database schema)
```

---

## User Type Access Levels

### Customer (Default)
- File: `login.php`
- Redirect: `login.php`
- Files: `index.php`, `products.php`, `orders.php`, `profile.php`

### Seller
- File: `seller/login.php`
- Redirect: `seller/login.php`
- Base: `seller/dashboard.php`
- Files: All files in `/seller/` directory

### Admin
- File: `admin/login.php`
- Redirect: `admin/login.php`
- Base: `admin/dashboard.php`
- Files: All files in `/admin/` directory

---

## Critical Database Tables

### User Management
- `users` - All users (customer, seller, admin)
- `staff` - Admin staff info
- `roles` - Admin roles
- `addresses` - User addresses
- `seller_applications` - Seller approval process
- `seller_bank_accounts` - Seller payment info

### Product & Catalog
- `products` - All products
- `categories` - Product categories
- `brands` - Product brands
- `attributes` - Product attributes (color, size, etc.)
- `product_categories` - Product-to-category mapping

### Orders & Transactions
- `orders` - Customer orders
- `order_details` - Individual items in orders
- `carts` - Shopping cart items
- `payments` - Payment records
- `commission_histories` - Seller commissions

### Financial
- `wallets` - User balance/wallet
- `seller_withdrawals` - Withdrawal requests
- `seller_packages` - Seller subscription packages
- `seller_package_orders` - Seller subscription orders

### Content
- `reviews` - Product reviews
- `coupons` - Discount codes
- `banners` - Website banners
- `deals` - Flash deals/promotions

---

## Authentication Flow

### Customer Login (login.php)
```
POST /login.php
  ↓
Verify email & password
  ↓
Check if banned
  ↓
Create session: $_SESSION['user_id'], ['user_type'] = 'customer'
  ↓
Redirect to index.php
```

### Seller Login (seller/login.php)
```
POST /seller/login.php
  ↓
Verify email & password
  ↓
Check user_type = 'seller'
  ↓
Create session: $_SESSION['user_type'] = 'seller'
  ↓
Redirect to seller/dashboard.php
```

### Admin Login (admin/login.php)
```
POST /admin/login.php
  ↓
Verify email & password
  ↓
Check user_type = 'admin'
  ↓
Create session with CSRF token
  ↓
Redirect to admin/dashboard.php
```

---

## Authentication Functions

**In auth.php:**
- `isLoggedIn()` - Check if user logged in
- `getCurrentUserId()` - Get user ID
- `getCurrentUserType()` - Get user type (customer|seller|admin)
- `requireLogin($userType)` - Force login, check user type
- `requireSeller()` - Require seller, else redirect to seller/login.php
- `requireAdmin()` - Require admin, else redirect to admin/login.php
- `loginUser($user)` - Create session
- `logout()` - Destroy session
- `verifyPassword($password, $hash)` - Check password
- `hashPassword($password)` - Hash password

---

## Seller Features (At a Glance)

### Dashboard (`seller/dashboard.php`)
- Balance display
- Order statistics
- Top selling products
- Revenue metrics
- Rating/reviews summary

### Product Management
- **Add Product** (`seller/add-product.php`)
  - Upload images
  - Set price, stock, discount
  - Assign category, brand
  - SEO fields
  
- **Products List** (`seller/products.php`)
  - View all seller's products
  - Edit, delete, toggle publish
  - Search, filter, paginate

### Orders (`seller/orders.php`)
- View seller's orders
- Filter by status
- Update delivery status
- Track shipments

### Finance
- **Dashboard** (`seller/finance.php`)
  - Revenue tracking
  - Commission breakdown
  - Analytics
  
- **Withdraw** (`seller/withdraw.php`)
  - Request cash withdrawal
  - Min: 50,000 VND
  - Max: 50,000,000 VND
  - Daily limit: 10,000,000 VND

### Packages (`seller/packages.php`)
- View available packages
- Upgrade/downgrade
- Pricing: 10% off 6+ months, 20% off 12 months

### Settings (`seller/store-settings.php`)
- Shop name, description, logo
- Language, currency, timezone
- Bank account management

---

## Common Query Patterns

### Get Current User
```php
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type']; // customer|seller|admin
```

### Seller's Products
```php
$stmt = $pdo->prepare("SELECT * FROM products WHERE user_id = ? AND published = 1");
$stmt->execute([$seller_id]);
$products = $stmt->fetchAll();
```

### Seller's Orders
```php
$stmt = $pdo->prepare("SELECT * FROM orders WHERE seller_id = ? ORDER BY created_at DESC");
$stmt->execute([$seller_id]);
$orders = $stmt->fetchAll();
```

### Seller's Earnings
```php
$stmt = $pdo->prepare("
    SELECT SUM(seller_earning) FROM commission_histories 
    WHERE seller_id = ?
");
$stmt->execute([$seller_id]);
$total_earnings = $stmt->fetchColumn();
```

### Seller's Balance
```php
$stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
$stmt->execute([$seller_id]);
$balance = $stmt->fetchColumn();
```

---

## CSS Variables Available

```css
:root {
    /* Colors */
    --color-primary: #1677ff;
    --color-secondary: #666;
    --color-success: #52c41a;
    --color-danger: #ff4d4f;
    --color-warning: #faad14;
    --color-text: #333;
    --color-border: #e1e5e9;
    --color-bg: #f8f9fa;
    
    /* Spacing */
    --spacing-xs: 4px;
    --spacing-sm: 8px;
    --spacing-md: 16px;
    --spacing-lg: 24px;
    --spacing-xl: 32px;
    
    /* Typography */
    --font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto;
    --font-size-sm: 12px;
    --font-size-base: 14px;
    --font-size-lg: 16px;
    
    /* Borders */
    --border-radius: 4px;
}
```

---

## Common Helper Functions

```php
// Price formatting
formatPrice($price)  // "1.234.567đ"

// Date formatting
formatDate($date)    // "01/12/2025"
formatDateTime($datetime) // "01/12/2025 14:30"

// Slug generation
generateSlug($string) // "my-product-name"

// Text truncation
truncateText($text, 100) // "Long text..."

// Get category icon
getCategoryIcon("Thời trang") // "👗"
```

---

## Important Configuration

**From constants.php:**
- Site name, URL, description
- Company info, contact details
- Payment methods
- Session timeout: 1 hour
- Max upload: 5MB
- Allowed types: jpg, jpeg, png, gif, webp
- Items per page: 12
- Min password length: 6 characters

**From config.php:**
- Database: u350721386_activeCMSECOM
- Host: localhost
- Username: root
- Password: (empty - SECURITY WARNING!)

---

## Security Features

✓ PDO prepared statements (SQL injection prevention)
✓ Password hashing (PASSWORD_DEFAULT)
✓ Session ID regeneration on login
✓ Session timeout (1 hour default)
✓ CSRF token validation (admin)
✓ Login attempt tracking
✓ IP address logging
✓ Banned user checks

---

## Performance Considerations

⚠ Large inline CSS on pages (should extract to files)
⚠ Inline JavaScript in pages (should extract to files)
⚠ CSS/JS not minified
⚠ Images not optimized
⚠ No caching headers set

---

## Common Issues & Solutions

### Login Issues
- Check `user_type` matches expected value
- Verify user is not banned (users.banned = 0)
- Check password hash with `verifyPassword()`

### Product Upload Issues
- Max size: 5MB
- Allowed: jpg, jpeg, png, gif, webp
- Check permissions on uploads directory

### Database Issues
- Prepared statements required (prevent SQL injection)
- Use bindValue() for parameters
- Check transaction handling

---

## Related Documentation Files

- `CODEBASE_ANALYSIS.md` - Full comprehensive analysis
- `REFACTORING_GUIDE.md` - CSS/JS refactoring instructions
- `CSS_JS_REFACTORING_ANALYSIS.md` - Detailed refactoring analysis
- `CSS_JS_REFACTORING_EXAMPLES.md` - Code examples for refactoring

