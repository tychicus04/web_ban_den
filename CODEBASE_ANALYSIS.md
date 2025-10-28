# TK-MALL E-Commerce Platform - Comprehensive Codebase Analysis

**Last Updated:** October 28, 2025
**Platform:** Vietnamese E-Commerce Platform (TK-MALL)
**Technology Stack:** PHP 8.2, MySQL/MariaDB, HTML5, CSS3, JavaScript
**Total PHP Files:** 70+

---

## Table of Contents
1. [Overall Architecture](#overall-architecture)
2. [Database Schema](#database-schema)
3. [Seller Functionality](#seller-functionality)
4. [Main Features & Modules](#main-features--modules)
5. [User Authentication & Management](#user-authentication--management)
6. [Products Management](#products-management)
7. [Orders Management](#orders-management)
8. [UI/CSS Issues & Inconsistencies](#uicss-issues--inconsistencies)
9. [Frontend Structure](#frontend-structure)
10. [Code Organization](#code-organization)

---

## Overall Architecture

### Architecture Type: **Custom MVC-like Structure (Non-Framework)**

This is a **traditional server-side PHP application** with a custom architecture rather than using frameworks like Laravel or Symfony. The structure follows a simplified MVC pattern with separation of concerns:

**Structure:**
```
/web_ban_den/
├── Root Level Pages (Customer-facing)
│   ├── index.php              # Homepage
│   ├── login.php              # Customer login
│   ├── register.php           # User registration
│   ├── products.php           # Products listing
│   ├── product-detail.php     # Product details
│   ├── categories.php         # Categories listing
│   ├── category.php           # Single category
│   ├── cart.php               # Shopping cart
│   ├── checkout.php           # Checkout process
│   ├── orders.php             # Order history
│   ├── profile.php            # User profile
│   ├── sellers.php            # Sellers listing/marketplace
│   ├── support.php            # Support/Contact
│   └── deals.php              # Deals/Promotions
│
├── /admin/                    # Admin Dashboard
│   ├── login.php              # Admin authentication
│   ├── dashboard.php          # Admin home
│   ├── products.php           # Product management
│   ├── orders.php             # Order management
│   ├── sellers.php            # Seller management & approval
│   ├── users.php              # User management
│   ├── categories.php         # Category management
│   ├── seller-package.php     # Seller packages
│   └── [15+ more pages]       # Other admin features
│
├── /seller/                   # Seller Dashboard
│   ├── login.php              # Seller authentication
│   ├── dashboard.php          # Seller home
│   ├── products.php           # Seller's products
│   ├── product-list.php       # Product listings
│   ├── add-product.php        # Create product
│   ├── orders.php             # Seller's orders
│   ├── finance.php            # Finance tracking
│   ├── withdraw.php           # Withdrawal requests
│   ├── packages.php           # Package management
│   └── [6+ more pages]        # Other seller features
│
├── /asset/                    # Frontend Assets
│   ├── /css/
│   │   ├── global.css         # CSS variables & base
│   │   ├── base.css           # Layout (header, nav, footer)
│   │   ├── components.css     # UI components
│   │   ├── utilities.css      # Utility classes
│   │   ├── forms.css          # Form styles
│   │   ├── tables.css         # Table styles
│   │   ├── admin.css          # Admin-specific styles
│   │   └── /pages/
│   │       └── product.css    # Product page specific
│   │
│   └── /js/
│       ├── global.js          # Core utilities & AJAX
│       ├── components.js      # UI components
│       ├── forms.js           # Form validation
│       ├── admin.js           # Admin scripts
│       └── /pages/
│           ├── product.js     # Product page JS
│           └── [more]
│
├── Core Config Files
│   ├── config.php             # Database connection (PDO)
│   ├── constants.php          # App constants & settings
│   ├── auth.php               # Authentication functions
│   ├── csrf.php               # CSRF token handling
│   ├── security-headers.php   # Security headers
│   └── header.php             # Common header template
│   └── footer.php             # Common footer template
│
└── Database
    └── u350721386_activeCMSECOM.sql  # Database schema (2006 lines)
```

### Key Architecture Features:

1. **No Framework**: Pure PHP with custom routing via file names
2. **Database**: PDO-based MySQL/MariaDB connection
3. **Session-Based**: Traditional PHP session management for authentication
4. **Page-Based Routing**: URL = PHP file name (not RESTful)
5. **Template Inclusion**: Common header/footer included in all pages
6. **Three User Tiers**:
   - **Customer**: Browse, purchase, track orders
   - **Seller**: Manage products, orders, finances
   - **Admin**: Full platform management

---

## Database Schema

### Database Name: `u350721386_activeCMSECOM`

### Core Tables (60+ tables total):

#### User & Authentication Tables:
```sql
users (Main user table)
├── id, name, email, password
├── user_type (customer|seller|admin)
├── balance, banned status
├── avatar, address, country, state, city
├── referral_code, referred_by
├── phone, device_token
└── created_at, updated_at

staff (Admin staff)
├── user_id (FK → users)
├── role_id (FK → roles)
└── timestamp

roles (Role-based access)
├── name (admin, moderator, etc)
└── permissions

model_has_roles, model_has_permissions (Role/Permission mapping)
role_has_permissions
```

#### Seller-Specific Tables:
```sql
seller_applications
├── user_id (FK → users)
├── shop_name, full_name
├── citizen_id, phone_number
├── shop_description, shop_logo
├── cccd_front, cccd_back (ID documents)
├── status (pending|approved|rejected)
├── rejection_reason
└── reviewed_at, admin_id

seller_bank_accounts
├── user_id (FK → users)
├── bank_code, bank_name
├── account_number, account_holder
├── branch, is_primary
└── status (active|pending|rejected)

seller_packages
├── name, description
├── monthly_price, max_products, max_images
├── commission_rate, featured_products
├── analytics_access, priority_support
├── features (JSON), status

seller_package_orders
├── seller_id, package_id
├── duration_months, amount
├── status (pending|active|expired|cancelled)
├── start_date, end_date

seller_payment_settings
├── user_id
├── auto_withdraw, min_withdraw_amount
├── withdraw_day

seller_withdrawals
├── seller_id, amount, method
├── account_info (JSON)
├── status (pending|processing|completed|rejected)

seller_language_settings
├── user_id
├── interface_language, content_languages
├── timezone, currency, date_format
```

#### Product Management Tables:
```sql
products
├── id, user_id (FK → users = seller)
├── name, slug, description
├── category_id, brand_id
├── unit_price, discount, discount_type
├── current_stock, published, approved
├── featured, rating, num_of_sale
├── thumbnail_img, photos (JSON array of IDs)

product_categories (Many-to-many)
├── product_id, category_id

categories
├── id, parent_id, level
├── name, commision_rate
├── banner, icon, cover_image
├── featured, top, digital

brands
├── id, name, logo, slug

attributes & attribute_values
├── Attribute system for product variants

product_stocks, product_taxes
├── Additional product info
```

#### Order & Transaction Tables:
```sql
orders
├── id, user_id, seller_id
├── grand_total, payment_status
├── delivery_status (pending|shipping|delivered|cancelled)
├── tracking_code, shipping_address
├── created_at, updated_at

order_details
├── id, order_id, product_id
├── quantity, price, tax
├── delivery_status, seller_id

commission_histories
├── order_id, seller_id
├── admin_commission, seller_earning

combined_orders
├── user_id, grand_total
├── shipping_address

carts
├── user_id, product_id
├── quantity, price, tax
├── discount, coupon_code
├── shipping_cost, carrier_id
```

#### Financial Tables:
```sql
wallets
├── user_id, amount
├── payment_method, payment_details
├── approval, offline_payment

payments
├── order_id, amount, status
├── payment_method, transaction_id
```

#### Other Key Tables:
```sql
coupons, user_coupons
reviews, ratings
wishlists
contacts, conversations, messages
addresses
uploads (File management)
business_settings (Configuration)
activities, activity_claims (Promotions)
migrations, permissions (System tables)
```

---

## Seller Functionality

### Seller Portal Files (16 PHP files in `/seller/`):

**Location:** `/home/user/web_ban_den/seller/`

#### Core Seller Pages:

1. **seller/login.php**
   - Seller-specific login page
   - Only allows `user_type = 'seller'` to login
   - Session-based authentication
   - Remember me functionality
   - Auto-redirect if already logged in

2. **seller/dashboard.php**
   - Seller home page with statistics
   - Displays:
     - Seller balance (from `users.balance`)
     - Total orders, pending, shipped, delivered, cancelled
     - Total products, total items sold
     - Revenue metrics
     - Average rating from customer reviews
     - Top selling products (12 most sold)
     - Category-wise breakdown

3. **seller/products.php**
   - List all seller's products
   - Actions: Edit, Delete, Toggle publish status
   - Pagination
   - Search & filter
   - Bulk operations

4. **seller/product-list.php**
   - Alternative product listing view
   - Query and display with filters

5. **seller/add-product.php**
   - Create new product form
   - Image upload (single thumbnail + multiple photos)
   - Category, brand, attributes selection
   - Price, discount, stock management
   - SEO fields (meta title, description)
   - AJAX image handling

6. **seller/query-products.php**
   - Advanced product search
   - Filter, sort, pagination

7. **seller/orders.php**
   - View seller's orders
   - Filter by status (pending, processing, shipped, etc.)
   - Order details
   - Status updates
   - Tracking information

8. **seller/finance.php**
   - Financial dashboard
   - Revenue tracking
   - Commission breakdowns
   - Transaction history
   - Revenue charts & analytics

9. **seller/withdraw.php**
   - Request withdrawal of earnings
   - Minimum: 50,000 VND
   - Maximum: 50,000,000 VND per request
   - Daily limit: 10,000,000 VND
   - Payment methods:
     - Bank transfer
     - Wallet/Digital payment
   - Stores in `seller_withdrawals` table
   - Status: pending → processing → completed/rejected

10. **seller/deposit.php**
    - Add funds to seller balance
    - Payment method selection
    - Integration with payment gateway

11. **seller/packages.php**
    - Display available seller packages
    - Current package info
    - Package upgrade/downgrade
    - Pricing based on duration (1, 3, 6, 12 months)
    - Discounts: 10% for 6+ months, 20% for 12 months
    - Features comparison

12. **seller/purchase.php**
    - Package purchase flow
    - Integration with payment system

13. **seller/store-settings.php**
    - Seller shop configuration
    - Shop name, description, logo
    - Language settings
    - Currency settings
    - Timezone settings
    - Bank account management

14. **seller/support.php**
    - Support ticket system
    - Help & FAQ
    - Contact admin

15. **seller/pos.php**
    - Point of Sale system
    - In-store sales processing
    - Quick checkout
    - Receipt generation

16. **seller/sidebar.php**
    - Navigation menu for seller dashboard
    - Links to all seller features

#### Seller Database Integration:

**Key Operations:**
- All queries filter by `seller_id = $_SESSION['user_id']`
- Balance tracked in `users.balance`
- Earnings calculated from `orders` + `commission_histories`
- Withdrawals stored in `seller_withdrawals` table
- Package subscriptions in `seller_package_orders`
- Settings in `seller_language_settings`, `seller_payment_settings`, `seller_bank_accounts`

---

## Main Features & Modules

### 1. Product Management Module

**Customer View:**
- `/products.php` - Browse all products with filters
- `/product-detail.php` - Single product view
- Search, filter by:
  - Category
  - Brand
  - Price range
  - Featured products
  - On sale
  - In stock
- Sort by: Newest, price, rating, popularity
- View modes: Grid or List

**Seller View:**
- Add, edit, delete products
- Bulk operations
- Image management
- Stock management
- Featured/discount settings
- Category assignment

**Admin View:**
- Approve/reject products
- Edit any product
- Delete products
- View all products
- Seller verification

### 2. Order Management Module

**Customer:**
- `/orders.php` - View order history
- Filter by status, date range
- Search by order ID or tracking code
- Cancel pending orders
- View order details
- Track shipments

**Seller:**
- `/seller/orders.php` - Manage seller's orders
- Update delivery status
- Generate shipping labels
- Customer communication

**Admin:**
- `/admin/orders.php` - All platform orders
- Order approval/processing
- Payment verification
- Refund management
- Analytics

### 3. Seller Management Module

**Seller Features:**
- Dashboard with KPIs
- Financial tracking
- Package management
- Bank account management
- Withdrawal requests
- Store settings
- Support tickets

**Admin Features:**
- `/admin/sellers.php` - Seller listing & approval
- Seller verification (50+ products + 4.5+ rating)
- Commission management
- Package management
- Ban/suspend sellers
- Performance analytics

### 4. User Management Module

**Customer:**
- `/profile.php` - Profile management
- `/register.php` - Registration
- Referral codes
- Address management
- Wishlist
- Customer support

**Admin:**
- `/admin/users.php` - User management
- User types (customer, seller, admin)
- Ban/suspend users
- View user details
- Manage addresses
- Track customer activity

### 5. Financial Module

**Seller Side:**
- Real-time balance display
- Revenue tracking
- Commission history
- Withdrawal history
- Payment method management

**Admin Side:**
- Commission configuration per category
- Payment processing
- Refund management
- Financial reports
- Wallet system integration

**Key Tables:**
- `wallets` - User balance tracking
- `payments` - Payment records
- `commission_histories` - Commission tracking
- `seller_withdrawals` - Withdrawal requests

### 6. Marketing & Promotion Module

- `/deals.php` - Flash deals/promotions
- Coupons system (`coupons`, `user_coupons` tables)
- Banners & featured products
- Brand management
- Categories with commissions
- Activities & rewards

---

## User Authentication & Management

### Authentication System

**Files:**
- `auth.php` - Central authentication functions
- `login.php` - Customer login
- `register.php` - Customer registration
- `/seller/login.php` - Seller login
- `/admin/login.php` - Admin login

### Session Management

**Session Variables:**
```php
$_SESSION['user_id']      // User ID
$_SESSION['user_name']    // User name
$_SESSION['user_type']    // customer|seller|admin
$_SESSION['user_email']   // User email
$_SESSION['login_time']   // Login timestamp
$_SESSION['seller_logged_in']  // Seller flag
$_SESSION['admin_token']   // CSRF token (admin)
$_SESSION['admin_login_time']  // Admin login time
```

### Auth Functions (from `auth.php`):

```php
isLoggedIn()              // Check if user logged in
getCurrentUserId()        // Get current user ID
getCurrentUserType()      // Get user type
requireLogin()            // Force login redirect
requireAdmin()            // Require admin
requireSeller()           // Require seller
requireCustomer()         // Require customer
isAdmin() / isSeller() / isCustomer()  // Type checks
loginUser($user)          // Create session
logout($redirectTo)       // Destroy session
getUserById($id)          // Fetch user
getUserByEmail($email)    // Find user by email
verifyPassword()          // Password verification
hashPassword()            // Password hashing
validatePasswordStrength() // Strength validation
```

### User Types:

1. **Customer** (Default)
   - Redirect: `login.php`
   - Features: Browse, purchase, track orders

2. **Seller**
   - Redirect: `seller/login.php`
   - Features: Product management, order fulfillment, finance

3. **Admin**
   - Redirect: `admin/login.php`
   - Features: Full platform management

### Security Features:

- Session ID regeneration on login
- Session timeout (1 hour customer, 8 hours admin)
- CSRF token validation
- Password hashing with `PASSWORD_DEFAULT`
- Prepared statements (PDO)
- Login attempt tracking
- IP address logging

### User Fields:

```sql
users table:
├── id, name, email
├── password (hashed)
├── user_type (customer|seller|admin)
├── banned (0|1)
├── balance (seller earnings)
├── phone, address, country, state, city, postal_code
├── avatar, avatar_original
├── referral_code, referred_by
├── email_verified_at, verification_code
├── device_token (for push notifications)
├── provider, provider_id (OAuth)
├── remember_token
└── created_at, updated_at
```

---

## Products Management

### Product Lifecycle:

1. **Creation (Seller)**
   - Seller uploads product via `/seller/add-product.php`
   - Stored in `products` table with `published=0, approved=0`

2. **Admin Approval**
   - Admin reviews product
   - Sets `approved=1` to make visible
   - Can reject or request changes

3. **Publishing**
   - Seller or admin publishes product
   - Sets `published=1`
   - Now visible to customers

4. **Management**
   - Edit pricing, stock, images
   - Toggle featured status
   - Apply discounts
   - View analytics

### Product Table Fields:

```sql
products:
├── id, user_id (seller), category_id
├── name, slug
├── description, tags
├── unit_price, discount, discount_type
├── tax, tax_type
├── current_stock
├── thumbnail_img, photos (JSON array)
├── sku, barcode
├── featured, published, approved
├── rating, num_of_sale
├── meta_title, meta_description
└── created_at, updated_at

Images stored in:
├── uploads table
├── id, file_name, file_size
├── user_id, type (product, banner, etc)
└── External links supported
```

### Product Variants:

**Attribute System:**
- `attributes` - Attribute names (Color, Size, etc.)
- `attribute_values` - Values (Red, Blue, Small, Large)
- `attribute_category` - Map attributes to categories

**Stored as JSON:**
- `products.variation` - Product variant JSON

### Product Images:

- **Thumbnail:** Single featured image (`thumbnail_img` = upload ID)
- **Gallery:** Array of upload IDs (stored as JSON in `photos`)
- Images from `uploads` table
- Support for external image links

### Inventory Management:

- `current_stock` - Available quantity
- `product_stocks` - Stock history/tracking
- Automatically decremented on order placement
- Low stock warnings available

---

## Orders Management

### Order Flow:

1. **Customer Places Order** (`checkout.php`)
   - Items from cart → order
   - Shipping address selection
   - Coupon application
   - Payment method selection
   - Creates `orders` + `order_details` records

2. **Payment Processing**
   - Multiple payment methods supported
   - Payment recorded in `payments` table
   - Status: pending → paid/failed

3. **Order Confirmation**
   - Admin/seller reviews
   - Sets delivery status: pending → processing

4. **Fulfillment** (`seller/orders.php`)
   - Seller packs order
   - Generates shipping label
   - Updates status: shipping

5. **Delivery**
   - Status updates: in_transit → delivered
   - Customer receives order
   - Seller gets earnings

### Order Tables:

```sql
orders:
├── id, user_id (customer), seller_id
├── grand_total, shipping_cost, discount, tax
├── payment_status (pending|paid|failed|refunded)
├── delivery_status (pending|processing|shipping|delivered|cancelled)
├── tracking_code, shipping_address
├── created_at, updated_at

order_details:
├── id, order_id, product_id
├── quantity, price, tax
├── seller_id, delivery_status
└── created_at

combined_orders:
├── id, user_id
├── grand_total, shipping_address
└── timestamp

commission_histories:
├── order_id, order_detail_id, seller_id
├── admin_commission, seller_earning
└── timestamp
```

### Seller Order View:

**Filters:**
- Status (pending, processing, shipped, delivered, cancelled)
- Date range
- Search by order ID

**Actions:**
- View order details
- Update status
- Generate shipping label
- Contact customer
- Process refund

### Admin Order View:

**Features:**
- All orders across all sellers
- Status management
- Payment verification
- Refund processing
- Commission tracking
- Order analytics

---

## UI/CSS Issues & Inconsistencies

### Current Issues Documented:

The project includes refactoring guides (`REFACTORING_GUIDE.md`, `CSS_JS_REFACTORING_ANALYSIS.md`) identifying these issues:

### 1. Inline CSS Problems

**Issues:**
- Heavy use of inline `<style>` blocks in PHP pages
- Hard-coded colors (instead of CSS variables)
- Duplicate CSS across multiple files
- No separation of concerns (HTML + CSS mixed)

**Example:**
```php
// In login.php, register.php, seller pages:
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    body {
        font-family: -apple-system, BlinkMacSystemFont, ...;
        background: #ffffff;
    }
    // ... 100+ lines per file
</style>
```

### 2. Hard-Coded Colors

**Current:**
- Header: `#ffffff` (white)
- Border: `#e1e5e9`, `#e1e8ed`
- Primary: `#1677ff`, `#166fe5`
- Text: `#333`, `#666`, `#999`
- Background: `#f8f9fa`

**Should Use CSS Variables:**
```css
:root {
    --color-primary: #1677ff;
    --color-secondary: #666;
    --color-border: #e1e5e9;
    --color-bg: #f8f9fa;
}
```

### 3. Inline JavaScript

**Issues:**
- Form validation mixed with HTML
- Event handlers in HTML attributes
- Event listeners defined inline
- No JavaScript file references in most pages

**Example:**
```php
<form method="POST">
    <input type="text" onchange="validateEmail(this)">
    <script>
        function validateEmail(el) { /* ... */ }
    </script>
</form>
```

### 4. Inconsistent Styling Across Pages

**Problems:**
- Login pages have different CSS than register
- Admin pages don't match customer pages
- Seller pages have unique styling
- No consistent component library
- Different spacing, colors, typography

### 5. Missing CSS/JS Files

**Current State:**
```
/asset/css/:
✓ global.css          (Exists)
✓ base.css            (Exists)
✓ components.css      (Exists)
✓ utilities.css       (Exists)
✓ forms.css           (Exists)
✓ tables.css          (Exists)
✓ modals.css          (Exists)
✓ admin.css           (Exists)
✓ category.css        (Exists)
✓ /pages/product.css  (Exists)

/asset/js/:
✓ global.js           (Exists)
✓ components.js       (Exists)
✓ forms.js            (Exists)
✓ admin.js            (Exists)
✓ modals.js           (Exists)
✓ /pages/product.js   (Exists)

❌ Missing per-page specific files:
  - pages/home.css, home.js
  - pages/cart.css, cart.js
  - pages/checkout.css, checkout.js
  - pages/orders.css, orders.js
  - pages/profile.css, profile.js
  - pages/sellers.css, sellers.js
  - pages/login.css, login.js (inline currently)
  - pages/seller-*.css/js files
  - pages/admin-*.css/js files
```

### 6. Responsive Design Issues

**Problems:**
- Not all pages are mobile-responsive
- Media queries scattered
- Breakpoints inconsistent
- Some pages rely on viewport meta tag only
- Admin tables overflow on mobile

### 7. Performance Issues

- Large inline CSS on every page load
- CSS/JS not minified
- Images not optimized
- No CSS/JS caching headers
- Possible render-blocking resources

### 8. Accessibility Issues

- Limited ARIA labels
- Form inputs could use better labels
- Color contrast needs verification
- Keyboard navigation incomplete
- Error messages not linked to form fields

### 9. Component Inconsistencies

**Buttons:**
- Different button colors on different pages
- Inconsistent padding/sizing
- Varying hover effects

**Forms:**
- Different input styling
- Inconsistent label positioning
- Error message styling varies

**Tables:**
- Admin tables different from customer tables
- Sorting UI inconsistent
- Pagination styling varies

---

## Frontend Structure

### Layout Template

**Base Structure (header.php + footer.php):**

```html
<header>
  - Logo
  - Search bar
  - User section (login/profile/logout)
  - Cart count badge
</header>

<nav>
  - Main navigation menu
  - Active page highlighting
</nav>

[Page Content]

<footer>
  - Company info
  - Footer links (4 sections)
  - Social media links
  - Stats display
</footer>
```

### HTML/PHP Include Structure:

Most pages follow this pattern:
```php
<?php
session_start();
require_once 'config.php';  // DB connection
require_once 'constants.php'; // Constants/menus

// Page logic here
// Database queries
// Business logic
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width">
    <title>Page Title - TK-MALL</title>
    <!-- Inline styles often here -->
    <style>
        /* Page-specific CSS */
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <!-- Page content HTML/PHP -->
    
    <?php require 'footer.php'; ?>
    
    <!-- Inline scripts often here -->
    <script>
        // Page-specific JavaScript
    </script>
</body>
</html>
```

### CSS Architecture (Current)

**CSS Cascade:**
1. `global.css` - Base variables, reset, typography
2. `base.css` - Header, nav, footer, layout
3. `components.css` - Buttons, cards, modals, etc.
4. `utilities.css` - Flex, spacing, display classes
5. `forms.css` - Form-specific styles
6. `tables.css` - Table-specific styles
7. `modals.css` - Modal dialogs
8. `category.css` - Category page specific
9. `admin.css` - Admin-specific overrides
10. `pages/product.css` - Product page specific

### CSS Variables (from global.css):

```css
:root {
    --color-primary: #1677ff;
    --color-secondary: #666;
    --color-success: #52c41a;
    --color-danger: #ff4d4f;
    --color-warning: #faad14;
    --color-info: #1890ff;
    --color-text: #333;
    --color-border: #e1e5e9;
    --color-bg: #f8f9fa;
    --spacing-xs: 4px;
    --spacing-sm: 8px;
    --spacing-md: 16px;
    --spacing-lg: 24px;
    --spacing-xl: 32px;
    --font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto;
    --font-size-sm: 12px;
    --font-size-base: 14px;
    --font-size-lg: 16px;
    --border-radius: 4px;
}
```

### JavaScript Architecture

**File Organization:**
- `global.js` - AJAX, alerts, utilities
- `components.js` - UI components (tabs, sliders, dropdowns)
- `forms.js` - Form validation
- `admin.js` - Admin-specific functionality
- `modals.js` - Modal dialogs
- `pages/product.js` - Product page features

### Utility Classes (from utilities.css)

```css
/* Flexbox */
.flex { display: flex; }
.flex-col { flex-direction: column; }
.flex-center { justify-content: center; align-items: center; }
.gap-sm, .gap-md, .gap-lg { gap: spacing-value; }

/* Spacing */
.p-*, .m-*, .mt-*, .mb-*, .ml-*, .mr-* { /* padding/margin */ }

/* Display */
.block, .inline-block, .inline, .hidden, .show { /* display */ }

/* Colors */
.text-primary, .text-secondary, .bg-primary, .bg-light { /* colors */ }

/* Sizing */
.w-full, .h-full, .container, .w-1/2, .w-1/3 { /* width/sizing */ }

/* Borders */
.border, .border-top, .rounded, .rounded-lg { /* borders */ }
```

---

## Code Organization

### Key Configuration Files:

**1. config.php**
```php
- Database connection setup (PDO)
- Error handling
- Environment variable support (.env file)
- Fallback hardcoded config
```

**2. constants.php**
```php
- SITE_NAME = 'TK-MALL'
- SITE_URL, SITE_TAGLINE, SITE_DESCRIPTION
- COMPANY_INFO (address, phone, email, license)
- SOCIAL_MEDIA links
- PAYMENT_METHODS array
- FOOTER_MENUS array
- Helper functions:
  - getCategoryIcon($name)
  - formatPrice($price) → "123.456đ"
  - formatDate($date) → "dd/mm/yyyy"
  - formatDateTime($datetime)
  - generateSlug($string)
  - truncateText($text, $limit)
- SITE FEATURES array
- ERROR/SUCCESS messages
- Session timeout: 1 hour
- Max upload: 5MB
- Allowed image types: jpg, jpeg, png, gif, webp
```

**3. auth.php**
```php
- isLoggedIn(), getCurrentUserId(), getCurrentUserType()
- requireLogin($userType, $redirectTo)
- requireAdmin(), requireSeller(), requireCustomer()
- isAdmin(), isSeller(), isCustomer()
- loginUser($user), logout($redirectTo)
- getUserById($id), getUserByEmail($email)
- verifyPassword($password, $hashed)
- hashPassword($password)
- emailExists($email)
- updateLastLogin($userId)
- logAuthAttempt($email, $success, $ip)
- getUserIP()
- validatePasswordStrength($password)
```

**4. csrf.php**
```php
- CSRF token generation and validation
- generateCSRFToken()
- validateCSRFToken($token)
```

**5. security-headers.php**
```php
- Security headers setup
- Content-Security-Policy
- X-Frame-Options
- X-Content-Type-Options
```

### Helper Functions Used:

From constants.php:
```php
formatPrice($amount)      // "1.234.567đ"
formatDate($date)         // "01/12/2025"
formatDateTime($datetime) // "01/12/2025 14:30"
generateSlug($string)     // "my-product-name"
truncateText($text, 100)  // "Long text..."
getCategoryIcon($name)    // "👗" for "Thời trang"
```

From config.php (database connection):
```php
$pdo->prepare()   // Prepared statements
$stmt->execute()
$stmt->fetch()    // Single row
$stmt->fetchAll() // Multiple rows
$stmt->fetchColumn() // Single value
```

### Admin-Specific Functions:

From admin pages:
```php
getDBConnection()        // Backward compatibility function
getBusinessSetting($db, $type, $default)
formatCurrency($amount, $currency = 'VND')
```

### Database Helper Patterns:

**Prepared Statements (Safe from SQL injection):**
```php
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND banned = 0");
$stmt->execute([$user_id]);
$user = $stmt->fetch(); // PDO::FETCH_ASSOC (default)
```

**Pagination Pattern:**
```php
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
$offset = ($page - 1) * $limit;

// In query:
LIMIT :limit OFFSET :offset
// Bind values:
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
```

**Search with LIKE:**
```php
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where[] = "(p.name LIKE :search OR p.description LIKE :search)";
$params[':search'] = "%$search%";
```

---

## Summary & Key Findings

### Strengths:
✓ Clean separation of customer/seller/admin areas
✓ Proper use of PDO prepared statements (security)
✓ Session-based authentication with timeout
✓ Comprehensive product & order management
✓ Seller financial tracking system
✓ Multiple payment methods support
✓ User role-based access control
✓ CSRF protection implemented

### Areas for Improvement:
❌ Heavy reliance on inline CSS & JavaScript
❌ No frontend framework (PHP only)
❌ Inconsistent UI/styling across pages
❌ Large file sizes (inline styles per page)
❌ Inline HTML/CSS mixing (maintainability)
❌ Missing responsive design on some pages
❌ Performance optimization needed (caching, minification)
❌ Limited API (REST API would help)
❌ Tests/validation not visible
❌ Admin dashboard could use better analytics

### Refactoring Priorities:
1. Extract all inline CSS to `/asset/css/pages/*.css` files
2. Extract all inline JavaScript to `/asset/js/pages/*.js` files
3. Replace hard-coded colors with CSS variables
4. Implement consistent component library
5. Add responsive design utilities
6. Minify CSS/JS files
7. Add performance optimization headers
8. Improve admin dashboard UX
9. Add API endpoints for AJAX operations
10. Implement comprehensive error handling/logging

---

**Analysis Complete** - October 28, 2025
