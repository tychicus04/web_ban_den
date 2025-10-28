# TK-MALL E-Commerce Platform - Documentation Index

**Analysis Date:** October 28, 2025  
**Platform:** Vietnamese E-Commerce Marketplace  
**Tech Stack:** PHP 8.2, MySQL/MariaDB, HTML5, CSS3, JavaScript

---

## Documentation Files Overview

### Primary Analysis Documents

#### 1. **CODEBASE_ANALYSIS.md** (33 KB) - COMPREHENSIVE OVERVIEW
**Your main reference document - START HERE**

Contains:
- Overall architecture explanation (Custom MVC)
- Complete database schema (60+ tables)
- Detailed seller functionality (16 files, all features)
- Main features breakdown (6 major modules)
- User authentication & management
- Products management lifecycle
- Orders management flow
- UI/CSS issues & inconsistencies
- Frontend structure & layout
- Code organization & helper functions

Best for: Understanding the complete system, finding where features are implemented, understanding the flow of data.

---

#### 2. **QUICK_REFERENCE.md** (11 KB) - CHEAT SHEET
**Quick lookup for common tasks**

Contains:
- Directory structure tree
- User type access levels
- Critical database tables list
- Authentication flows (diagrams)
- Authentication functions
- Seller features at a glance
- Common query patterns
- CSS variables available
- Common helper functions
- Configuration settings
- Security features summary
- Performance considerations
- Common issues & solutions

Best for: Quick lookups, remembering file locations, finding authentication flows, common patterns.

---

### Refactoring Documentation

#### 3. **REFACTORING_GUIDE.md** (24 KB)
Step-by-step guide for CSS/JS refactoring

Contains:
- Refactoring goals & benefits
- New file structure for assets
- Setup instructions (5 steps)
- Code examples (before/after)
- Best practices
- Rules & patterns
- Component library standards
- Utility class guidelines
- Complete checklist

**Key Action Items:**
1. Extract inline CSS to `/asset/css/pages/*.css`
2. Extract inline JavaScript to `/asset/js/pages/*.js`
3. Replace hard-coded colors with CSS variables
4. Create page-specific CSS/JS files
5. Remove duplicate code

---

#### 4. **CSS_JS_REFACTORING_ANALYSIS.md** (15 KB)
Detailed analysis of current CSS/JS problems

Contains:
- Files with inline CSS
- Files with inline JavaScript
- Inconsistencies identified
- Per-file recommendations
- Priority levels
- Detailed examples

---

#### 5. **CSS_JS_REFACTORING_EXAMPLES.md** (15 KB)
Code examples for refactoring

Contains:
- Before/after code samples
- Utility class examples
- Component patterns
- Form styling examples
- Admin panel refactoring
- Seller dashboard refactoring

---

#### 6. **CSS_JS_REFACTORING_QUICK_REFERENCE.md** (6.3 KB)
Quick reference for refactoring patterns

Contains:
- File mapping
- CSS variable quick list
- JavaScript patterns
- Import statements
- Common refactorings

---

#### 7. **CSS_JS_REFACTORING_INDEX.md** (7.9 KB)
Index of all pages needing refactoring

Contains:
- File listing with status
- Priority ranking
- Estimated effort
- Dependencies

---

#### 8. **REFACTORING_SUMMARY.md** (12 KB)
Executive summary of refactoring work

Contains:
- Current state overview
- Problems identified
- Solutions proposed
- Implementation roadmap
- Timeline estimates

---

## Quick Navigation Guide

### If you want to understand...

#### "What files are in the project?"
→ See **CODEBASE_ANALYSIS.md** - Overall Architecture section

#### "How does seller functionality work?"
→ See **CODEBASE_ANALYSIS.md** - Seller Functionality section (1 page + reference)

#### "What tables are in the database?"
→ See **CODEBASE_ANALYSIS.md** - Database Schema section OR  
→ **QUICK_REFERENCE.md** - Critical Database Tables

#### "How does authentication work?"
→ See **QUICK_REFERENCE.md** - Authentication Flow & Functions sections

#### "How are products managed?"
→ See **CODEBASE_ANALYSIS.md** - Products Management section

#### "How are orders managed?"
→ See **CODEBASE_ANALYSIS.md** - Orders Management section

#### "What CSS/UI problems exist?"
→ See **CODEBASE_ANALYSIS.md** - UI/CSS Issues section OR  
→ **CSS_JS_REFACTORING_ANALYSIS.md** for detailed issues

#### "How do I refactor the CSS/JS?"
→ See **REFACTORING_GUIDE.md** - Main guide
→ **CSS_JS_REFACTORING_EXAMPLES.md** - Code examples
→ **CSS_JS_REFACTORING_QUICK_REFERENCE.md** - Quick patterns

#### "What's a common query pattern?"
→ See **QUICK_REFERENCE.md** - Common Query Patterns

#### "What config/settings are important?"
→ See **QUICK_REFERENCE.md** - Important Configuration section

---

## Key Statistics

### Codebase Size
- Total PHP files: 70+
- Database tables: 60+
- CSS files: 9
- JavaScript files: 6
- Configuration files: 6
- Documentation files: 8

### User Types
- Customers: Browse products, place orders, manage profile
- Sellers: 16 dedicated features (products, orders, finance, etc.)
- Admins: 29 management pages

### Database
- **Database Name:** u350721386_activeCMSECOM
- **Type:** MySQL/MariaDB
- **Engine:** InnoDB
- **Charset:** utf8mb4

### Major Features
1. Product Management (Sellers + Admins)
2. Order Management (Customers + Sellers + Admins)
3. Seller Management (Dashboard, Finance, Packages)
4. User Authentication (3 types)
5. Financial Tracking (Wallets, Withdrawals, Commissions)
6. Marketing (Coupons, Deals, Banners)

---

## Critical Files to Know

### Configuration
- `/config.php` - Database connection
- `/constants.php` - Site constants & helper functions
- `/auth.php` - Authentication functions
- `/csrf.php` - CSRF protection
- `/security-headers.php` - Security headers

### Customer Pages (14 files)
- `/index.php` - Homepage
- `/login.php` - Customer login
- `/register.php` - Registration
- `/products.php` - Product listing
- `/product-detail.php` - Product details
- `/cart.php` - Shopping cart
- `/checkout.php` - Checkout/payment
- `/orders.php` - Order history
- `/profile.php` - User profile
- `/sellers.php` - Seller marketplace
- Others: categories.php, category.php, deals.php, support.php

### Seller Pages (16 files in `/seller/`)
- `login.php` - Seller authentication
- `dashboard.php` - Seller home
- `products.php` - Product listing
- `add-product.php` - Create product
- `orders.php` - Seller's orders
- `finance.php` - Revenue tracking
- `withdraw.php` - Cash withdrawal
- `packages.php` - Subscription packages
- `store-settings.php` - Shop configuration
- Others: product-list.php, deposit.php, purchase.php, pos.php, sidebar.php, support.php, query-products.php

### Admin Pages (29 files in `/admin/`)
- `login.php` - Admin authentication
- `dashboard.php` - Admin home
- `products.php` - Product management
- `orders.php` - Order management
- `sellers.php` - Seller management & approval
- `users.php` - User management
- `categories.php` - Category management
- `seller-package.php` - Package management
- Others: 21 more admin features

### Frontend Assets
**CSS (9 files):**
- `global.css` - Variables & base
- `base.css` - Layout
- `components.css` - UI components
- `utilities.css` - Utility classes
- `forms.css` - Forms
- `tables.css` - Tables
- `modals.css` - Modals
- `admin.css` - Admin overrides
- `pages/product.css` - Product page

**JavaScript (6 files):**
- `global.js` - Core utilities
- `components.js` - UI components
- `forms.js` - Form validation
- `admin.js` - Admin functionality
- `modals.js` - Modal dialogs
- `pages/product.js` - Product page

---

## Architecture Overview

### Type: Custom MVC (Non-Framework)
- No Laravel, Symfony, or major framework
- Pure PHP with custom routing
- File-based routing (url = php file)
- Session-based authentication
- PDO for database

### Three-Tier User System
```
Customer → login.php → index.php (browse & buy)
Seller → seller/login.php → seller/dashboard.php (manage & sell)
Admin → admin/login.php → admin/dashboard.php (manage platform)
```

### Database Pattern
- All queries use PDO prepared statements
- SQL injection safe
- Pagination support built-in
- Foreign key relationships
- JSON support for complex data

### Frontend Pattern
- Server-side rendering (no SPA framework)
- Template-based (header.php, footer.php)
- CSS cascade (global → page-specific)
- JavaScript event delegation
- Responsive design (partial)

---

## Known Issues & TODOs

### Critical
- Database password empty (SECURITY WARNING!)
- Inline CSS/JS on most pages
- Hard-coded colors instead of variables
- Missing page-specific CSS/JS files

### Important
- No responsive design on admin pages
- CSS/JS not minified
- Images not optimized
- No caching headers
- Limited ARIA/accessibility

### Nice to Have
- API endpoints (REST)
- Unit tests
- Better error handling
- Advanced analytics
- Email notifications

---

## Next Steps

### For Understanding the Code
1. Read **CODEBASE_ANALYSIS.md** - Sections 1-3
2. Use **QUICK_REFERENCE.md** for lookups
3. Explore specific sections as needed

### For Refactoring CSS/JS
1. Read **REFACTORING_GUIDE.md** - Complete guide
2. Review **CSS_JS_REFACTORING_EXAMPLES.md** - Code samples
3. Use **CSS_JS_REFACTORING_QUICK_REFERENCE.md** - Quick patterns
4. Follow the checklist in REFACTORING_GUIDE.md

### For Feature Development
1. Understand **Database Schema** (CODEBASE_ANALYSIS.md section 2)
2. Find similar existing feature in code
3. Copy patterns from existing code
4. Add to appropriate section (customer, seller, or admin)
5. Add new CSS/JS files (don't add inline styles!)

### For Bug Fixes
1. Find relevant file in **QUICK_REFERENCE.md**
2. Check authentication/authorization
3. Review database queries
4. Check CSS/JavaScript console errors
5. Use **QUICK_REFERENCE.md** - Common Issues section

---

## File Locations Summary

| Feature | Location | Files |
|---------|----------|-------|
| Customer Pages | `/` root | 14 files |
| Seller Dashboard | `/seller/` | 16 files |
| Admin Dashboard | `/admin/` | 29 files |
| CSS | `/asset/css/` | 9 files |
| JavaScript | `/asset/js/` | 6 files |
| Config | `/` root | 6 files |
| Database | `/` root | 1 SQL file |

---

## Document Usage Recommendations

**For Beginners:**
1. QUICK_REFERENCE.md - Get oriented
2. CODEBASE_ANALYSIS.md Sections 1-3 - Understand structure
3. Specific sections as needed

**For Developers:**
1. QUICK_REFERENCE.md - Keep bookmarked
2. CODEBASE_ANALYSIS.md - Full reference
3. REFACTORING_GUIDE.md - When adding features

**For Refactoring:**
1. REFACTORING_GUIDE.md - Main guide
2. CSS_JS_REFACTORING_EXAMPLES.md - Code samples
3. CSS_JS_REFACTORING_QUICK_REFERENCE.md - Quick lookup

**For Maintenance:**
1. CODEBASE_ANALYSIS.md - Complete reference
2. QUICK_REFERENCE.md - Common issues
3. Specific section as needed

---

**Last Updated:** October 28, 2025
**Created by:** Codebase Analysis Tool
**Status:** Complete & Ready for Use

