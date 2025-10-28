# Kế Hoạch Đơn Giản Hóa Website - TK-MALL

**Ngày tạo:** 28/10/2025
**Mục tiêu:** Đơn giản hóa website bằng cách loại bỏ chức năng seller và refactor code

---

## 📋 Tổng Quan

### Mục Tiêu Chính
1. **Loại bỏ chức năng Seller** - Website chỉ có 1 nhà phân phối duy nhất
2. **Refactor & Restructure code** - Tổ chức lại code để dễ maintain
3. **Fix UI issues** - Sửa các vấn đề UI và đồng nhất giao diện

### Lợi Ích
- ✅ Code đơn giản hơn, dễ maintain
- ✅ UI/UX nhất quán hơn
- ✅ Performance tốt hơn (ít code, ít database queries)
- ✅ Phù hợp với business model: 1 nhà phân phối duy nhất

---

## 🎯 Phần 1: Loại Bỏ Chức Năng Seller

### 1.1. Seller-Related Files (16 files)
Cần xóa toàn bộ thư mục `/seller/`:
```
seller/
├── add-product.php          ❌ XÓA
├── dashboard.php            ❌ XÓA
├── deposit.php              ❌ XÓA
├── finance.php              ❌ XÓA
├── login.php                ❌ XÓA
├── packages.php             ❌ XÓA
├── orders.php               ❌ XÓA
├── pos.php                  ❌ XÓA
├── product-list.php         ❌ XÓA
├── products.php             ❌ XÓA
├── query-products.php       ❌ XÓA
├── purchase.php             ❌ XÓA
├── sidebar.php              ❌ XÓA
├── store-settings.php       ❌ XÓA
├── withdraw.php             ❌ XÓA
└── support.php              ❌ XÓA
```

### 1.2. Customer-Facing Seller Pages
```
root/
├── sellers.php              ❌ XÓA (Marketplace listing sellers)
```

### 1.3. Admin Seller Management Files
Cần update/xóa các phần liên quan seller:
```
admin/
├── sellers.php              🔧 UPDATE/XÓA
├── seller-package.php       ❌ XÓA
├── seller-withdrawals.php   ❌ XÓA (nếu có)
```

### 1.4. Database Tables - Seller Tables (7 tables)
Cần xóa/comment out các tables sau trong SQL:
```sql
-- XÓA HOẶC COMMENT OUT:
seller_applications
seller_bank_accounts
seller_language_settings
seller_packages
seller_package_orders
seller_payment_settings
seller_withdrawals
```

### 1.5. Database Schema Changes

#### A. Users Table - Simplify user_type
```sql
-- TRƯỚC: 3 user types
user_type ENUM('customer', 'seller', 'admin')

-- SAU: 2 user types
user_type ENUM('customer', 'admin')

-- XÓA các fields không cần thiết cho seller:
-- Có thể giữ lại 'balance' field cho customer (wallet)
```

#### B. Products Table - Remove seller_id
```sql
-- TRƯỚC:
products (
    id,
    user_id INT (seller_id),  -- Reference to seller
    name,
    ...
)

-- SAU: 2 options

-- OPTION 1: Set user_id = NULL hoặc default admin ID
ALTER TABLE products MODIFY user_id INT DEFAULT NULL;
UPDATE products SET user_id = 1; -- 1 = Admin ID

-- OPTION 2: Remove user_id entirely (recommended)
-- Nhưng cần kiểm tra các foreign key constraints trước
```

#### C. Orders Table - Remove seller_id
```sql
-- TRƯỚC:
orders (
    id,
    user_id INT (customer_id),
    seller_id INT,  -- Reference to seller
    ...
)

order_details (
    id,
    order_id,
    product_id,
    seller_id INT,  -- Reference to seller
    ...
)

-- SAU: Remove seller_id
-- Không cần seller_id vì chỉ có 1 nhà phân phối
```

#### D. Commission Tables - Simplify hoặc xóa
```sql
-- XÓA hoặc đơn giản hóa:
commission_histories (
    order_id,
    seller_id,           -- Không cần
    admin_commission,    -- Có thể giữ
    seller_earning,      -- Không cần
    ...
)

-- Nếu không cần track commission, có thể xóa toàn bộ table này
```

---

## 🎯 Phần 2: Refactor & Restructure Code

### 2.1. Extract Inline CSS to Files

#### Các files cần refactor (Priority HIGH):
```
Priority 1 - Critical (Heavy inline CSS):
├── admin/shop-details.php      (300+ lines CSS)
├── admin/user-details.php      (250+ lines CSS)
├── admin/coupons.php           (200+ lines CSS)
├── product-detail.php          (150+ lines CSS)
├── login.php                   (100+ lines CSS)
└── register.php                (100+ lines CSS)

Priority 2 - High:
├── admin/pos.php
├── admin/reviews.php
└── cart.php

Priority 3 - Medium:
├── checkout.php
├── orders.php
├── profile.php
└── (21+ other files)
```

#### Quy trình refactor mỗi file:
1. **Identify inline styles**
   - Tìm `<style>` tags
   - Tìm `style="..."` attributes

2. **Extract to appropriate CSS file**
   - Simple styles → Utility classes (utilities.css)
   - Component styles → components.css
   - Page-specific → `/asset/css/pages/[page].css`

3. **Use CSS variables**
   ```css
   /* TRƯỚC */
   color: #1877f2;

   /* SAU */
   color: var(--color-primary);
   ```

4. **Remove inline `<style>` tags**

5. **Add CSS file references**
   ```php
   <link rel="stylesheet" href="/asset/css/pages/[page].css">
   ```

### 2.2. Extract Inline JavaScript to Files

#### Quy trình:
1. **Identify inline JavaScript**
   - Tìm `<script>` tags
   - Tìm inline event handlers: `onclick=""`, `onchange=""`, etc.

2. **Convert to event delegation**
   ```javascript
   // TRƯỚC
   <button onclick="deleteItem(5)">Xóa</button>

   // SAU
   <button class="btn-delete" data-id="5">Xóa</button>

   // In JS file:
   document.addEventListener('click', (e) => {
       const btn = e.target.closest('.btn-delete');
       if (!btn) return;
       const id = btn.getAttribute('data-id');
       deleteItem(id);
   });
   ```

3. **Organize into modules**
   - Global utilities → global.js
   - Components → components.js
   - Forms → forms.js
   - Page-specific → `/asset/js/pages/[page].js`

4. **Remove inline `<script>` tags**

5. **Add JS file references**
   ```php
   <script src="/asset/js/pages/[page].js"></script>
   ```

### 2.3. Restructure Code Organization

#### A. Create Page-Specific CSS/JS Files
```
asset/css/pages/
├── home.css
├── product.css ✅ (already exists)
├── cart.css
├── checkout.css
├── orders.css
├── profile.css
├── login.css
├── register.css
└── admin/
    ├── dashboard.css
    ├── products.css
    ├── orders.css
    └── users.css

asset/js/pages/
├── home.js
├── product.js ✅ (already exists)
├── cart.js
├── checkout.js
├── orders.js
├── profile.js
└── admin/
    ├── dashboard.js
    ├── products.js
    └── orders.js
```

#### B. Update Header/Footer Templates
```php
// In header.php - Add conditional CSS loading
<?php
$page_name = basename($_SERVER['PHP_SELF'], '.php');
$css_file = "/asset/css/pages/{$page_name}.css";
if (file_exists(__DIR__ . $css_file)) {
    echo "<link rel='stylesheet' href='{$css_file}'>";
}
?>

// Similar for footer.php - Add conditional JS loading
```

#### C. Create Helper Functions File
```php
// helpers.php - Centralize common functions
function getCurrentUser() { ... }
function isAdmin() { ... }
function formatPrice($price) { ... }
function formatDate($date) { ... }
// ... etc
```

### 2.4. Simplify Authentication

#### Remove Seller Authentication
```php
// In auth.php - Remove:
function requireSeller() { ... }
function isSeller() { ... }

// Update:
function getCurrentUserType() {
    // Only return 'customer' or 'admin'
    // Convert any 'seller' to 'customer' or remove
}
```

#### Update Login Pages
```
- Keep: login.php (customer)
- Keep: admin/login.php (admin)
- DELETE: seller/login.php
```

---

## 🎯 Phần 3: Fix UI Issues

### 3.1. Use CSS Variables Consistently
```css
/* In global.css - Already defined */
:root {
    --color-primary: #1677ff;
    --color-secondary: #666;
    --color-success: #52c41a;
    --color-danger: #ff4d4f;
    --color-warning: #faad14;
    --color-border: #e1e5e9;
    --color-bg: #f8f9fa;
    --spacing-xs: 4px;
    --spacing-sm: 8px;
    --spacing-md: 16px;
    --spacing-lg: 24px;
    --spacing-xl: 32px;
}

/* Replace all hard-coded colors with variables */
```

### 3.2. Standardize Components
```css
/* Buttons - Use consistent button classes */
.btn { /* base button */ }
.btn-primary { /* primary action */ }
.btn-secondary { /* secondary action */ }
.btn-danger { /* delete/cancel */ }
.btn-success { /* confirm/save */ }

/* Forms - Standardize input styles */
.form-control { /* text inputs */ }
.form-select { /* dropdowns */ }
.form-check { /* checkboxes/radio */ }

/* Cards - Standardize card components */
.card { /* base card */ }
.card-header { /* card header */ }
.card-body { /* card body */ }
.card-footer { /* card footer */ }
```

### 3.3. Fix Responsive Design
```css
/* Add consistent breakpoints */
/* Mobile first approach */
@media (max-width: 576px) { /* Mobile */ }
@media (max-width: 768px) { /* Tablet */ }
@media (max-width: 992px) { /* Small desktop */ }
@media (max-width: 1200px) { /* Desktop */ }
```

### 3.4. Improve Navigation
- Remove "Sellers" link from main navigation
- Update header.php to remove seller-related menu items
- Simplify menu structure

---

## 📊 Timeline & Priorities

### Phase 1: Remove Seller Functionality (1-2 ngày)
- [ ] Backup database và code
- [ ] Xóa thư mục /seller/
- [ ] Xóa sellers.php
- [ ] Update database schema
- [ ] Remove seller references từ admin pages
- [ ] Update authentication system
- [ ] Test basic functionality

### Phase 2: Database Cleanup (1 ngày)
- [ ] Create migration script
- [ ] Drop seller tables
- [ ] Update products table
- [ ] Update orders table
- [ ] Update users table
- [ ] Test database integrity

### Phase 3: Code Refactoring - High Priority (2-3 ngày)
- [ ] Refactor login.php
- [ ] Refactor register.php
- [ ] Refactor product-detail.php
- [ ] Refactor cart.php
- [ ] Refactor checkout.php
- [ ] Refactor admin critical pages

### Phase 4: Code Refactoring - Medium Priority (3-5 ngày)
- [ ] Refactor remaining customer pages
- [ ] Refactor remaining admin pages
- [ ] Create page-specific CSS files
- [ ] Create page-specific JS files

### Phase 5: UI/UX Improvements (2-3 ngày)
- [ ] Standardize components
- [ ] Fix responsive design issues
- [ ] Improve navigation
- [ ] Add loading states
- [ ] Add better error handling

### Phase 6: Testing & Polish (1-2 ngày)
- [ ] Test all customer flows
- [ ] Test all admin flows
- [ ] Test responsive design
- [ ] Fix bugs
- [ ] Performance optimization

**Total Estimate: 10-16 ngày làm việc**

---

## ✅ Success Criteria

### Functional Requirements
- ✅ Website hoạt động không có seller functionality
- ✅ Products được quản lý bởi admin
- ✅ Orders flow hoạt động bình thường
- ✅ Users có thể đăng ký và mua hàng
- ✅ Admin có thể quản lý toàn bộ platform

### Code Quality
- ✅ Không còn inline CSS
- ✅ Không còn inline JavaScript
- ✅ Code được tổ chức theo modules
- ✅ CSS variables được sử dụng consistently
- ✅ Event delegation thay vì inline handlers

### UI/UX
- ✅ Giao diện nhất quán trên tất cả pages
- ✅ Responsive trên mobile/tablet/desktop
- ✅ Components được standardized
- ✅ Navigation đơn giản và rõ ràng

---

## 🚨 Risks & Mitigation

### Risk 1: Data Loss
**Mitigation:**
- Backup database trước khi thay đổi
- Test trên local environment trước
- Có rollback plan

### Risk 2: Breaking Existing Features
**Mitigation:**
- Test thoroughly sau mỗi phase
- Giữ backup code
- Deploy từng phase một

### Risk 3: Frontend Breaking
**Mitigation:**
- Test trên nhiều browsers
- Check responsive design
- Validate HTML/CSS/JS

---

## 📝 Notes

### Về Products Management
Sau khi remove seller functionality:
- Admin sẽ quản lý tất cả products
- Không cần approval process (vì admin tự add)
- Có thể simplify product creation flow

### Về Orders Management
- Orders chỉ có customer_id
- Không cần seller_id
- Admin xử lý tất cả orders
- Có thể add staff roles nếu cần nhiều người quản lý

### Về User Management
- Chỉ còn 2 user types: customer, admin
- Có thể thêm "staff" role nếu cần
- Simplify registration process

---

**Document Version:** 1.0
**Last Updated:** 28/10/2025
