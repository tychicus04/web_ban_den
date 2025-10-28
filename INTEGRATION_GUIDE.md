# 🔧 TK-MALL Integration Guide

Hướng dẫn chi tiết cách tích hợp CSS và JavaScript architecture mới vào các trang existing.

**Last Updated**: October 28, 2025

---

## 📋 Quick Start

### ✅ Files Đã Hoàn Thành Integration

- **header.php** ✅ - Đã remove hết inline CSS/JS
- **footer.php** ✅ - Đã remove hết inline CSS/JS
- **index.php** ⚠️ - Đã update CSS includes, inline JS còn cần refactor thêm

### 🎯 Files Cần Integration

- product-detail.php (Critical Priority - ~1700 lines inline code)
- cart.php (High Priority)
- checkout.php (High Priority)
- admin/dashboard.php (Critical Priority - admin panel)
- All other PHP pages

---

## 🚀 Step-by-Step Integration

### Step 1: Update HTML Head

**Remove Old CSS**:
```html
<!-- ❌ OLD - Remove này -->
<link rel="stylesheet" href="asset/base.css">
```

**Add New CSS**:
```html
<!-- ✅ NEW - Add these in order -->
<link rel="stylesheet" href="asset/global.css">
<link rel="stylesheet" href="asset/components.css">

<!-- For admin pages, also add -->
<link rel="stylesheet" href="asset/admin.css">
```

### Step 2: Add JavaScript Files Before `</body>`

**For Public Pages**:
```html
    <!-- Your page content -->
    <?php include 'footer.php'; ?>

    <!-- ✅ Add these before </body> -->
    <script src="asset/global.js"></script>
    <script src="asset/components.js"></script>

    <!-- Optional: Page-specific JavaScript -->
    <script>
    // Page-specific code here
    // Can use all functions from global.js and components.js
    </script>
</body>
</html>
```

**For Admin Pages**:
```html
    <!-- Admin content -->
    <?php include 'admin-footer.php'; ?>

    <!-- ✅ Add these before </body> -->
    <script src="../asset/global.js"></script>
    <script src="../asset/components.js"></script>
    <script src="../asset/admin.js"></script>

    <!-- Optional: Admin page-specific JavaScript -->
    <script>
    // Admin page-specific code
    </script>
</body>
</html>
```

### Step 3: Remove Inline CSS

**Before**:
```html
<style>
.my-button {
    background: #1877f2;
    color: white;
    padding: 10px 20px;
    border-radius: 6px;
}

.my-card {
    background: white;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    padding: 20px;
}
</style>
```

**After**:
```html
<!-- ✅ Use existing classes from global.css and components.css -->
<button class="btn btn-primary">Click Me</button>
<div class="card">Card content</div>

<!-- OR if styles are unique, move to separate CSS file -->
```

**Create Page-Specific CSS File** (if needed):
```css
/* asset/product-detail.css */
/* Only unique styles for product-detail page */
.product-gallery-custom {
    /* Unique styling */
}
```

```html
<!-- Then include it -->
<link rel="stylesheet" href="asset/global.css">
<link rel="stylesheet" href="asset/components.css">
<link rel="stylesheet" href="asset/product-detail.css">
```

### Step 4: Replace Inline JavaScript Functions

**Before** (Inline in each file):
```javascript
<script>
function showNotification(message, type) {
    // 50 lines of code duplicated everywhere
}

function updateCartCount() {
    // 30 lines of code duplicated everywhere
}

function addToCart(productId) {
    // 40 lines of code duplicated everywhere
}
</script>
```

**After** (Use global functions):
```javascript
<!-- Remove entire inline script block -->
<!-- These functions are now available globally from asset/global.js -->

<script>
// Just use them directly:
showNotification('Added to cart!', 'success');
await updateCartCount();
await addToCart(productId, quantity);
</script>
```

### Step 5: Replace Inline Event Handlers

**Before** (Inline onclick):
```html
<button onclick="addToCart(123)">Add to Cart</button>
<button onclick="deleteItem(456)">Delete</button>
<select onchange="this.form.submit()">...</select>
<form onsubmit="return validateForm(this)">...</form>
```

**After** (Event delegation):
```html
<!-- Remove inline onclick attributes -->
<button class="add-to-cart-btn" data-product-id="123">Add to Cart</button>
<button class="delete-btn" data-id="456">Delete</button>
<select class="auto-submit">...</select>
<form class="validated-form">...</form>

<script>
// Add event listeners in JavaScript
document.addEventListener('click', function(e) {
    // Add to cart
    if (e.target.classList.contains('add-to-cart-btn')) {
        const productId = e.target.dataset.productId;
        addToCart(productId); // Function from global.js
    }

    // Delete
    if (e.target.classList.contains('delete-btn')) {
        const id = e.target.dataset.id;
        deleteItem(id);
    }
});

// Auto-submit select
document.querySelectorAll('.auto-submit').forEach(select => {
    select.addEventListener('change', function() {
        this.form.submit();
    });
});

// Form validation
document.querySelectorAll('.validated-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const { isValid } = validateForm(this); // Function from global.js
        if (!isValid) {
            e.preventDefault();
        }
    });
});
</script>
```

---

## 📄 Complete Page Template

Đây là template hoàn chỉnh cho một trang mới:

```php
<?php
session_start();
require_once 'config.php';

// Page logic here...

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Title - TK-MALL</title>

    <!-- CSRF Token -->
    <?php require_once 'csrf.php'; echo csrfTokenMeta(); ?>

    <!-- TK-MALL CSS Architecture -->
    <link rel="stylesheet" href="asset/global.css">
    <link rel="stylesheet" href="asset/components.css">

    <!-- Page-specific CSS (optional) -->
    <!-- <link rel="stylesheet" href="asset/my-page.css"> -->
</head>
<body>
    <?php include 'header.php'; ?>

    <!-- Page Content -->
    <main class="container">
        <h1>Page Content</h1>

        <!-- Example: Product Card -->
        <div class="product-card">
            <img src="product.jpg" alt="Product" class="product-image">
            <h3 class="product-name">Product Name</h3>
            <div class="product-price"><?php echo formatCurrency(100000); ?></div>
            <button class="btn btn-primary add-to-cart-btn" data-product-id="123">
                Add to Cart
            </button>
        </div>

        <!-- Example: Form -->
        <form class="validated-form" method="POST">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       class="form-input" required>
            </div>
            <button type="submit" class="btn btn-primary">Submit</button>
        </form>
    </main>

    <?php include 'footer.php'; ?>

    <!-- TK-MALL JavaScript Architecture -->
    <script src="asset/global.js"></script>
    <script src="asset/components.js"></script>

    <!-- Page-specific JavaScript (optional) -->
    <script>
    // Add to cart handler
    document.addEventListener('click', async function(e) {
        if (e.target.classList.contains('add-to-cart-btn')) {
            const productId = e.target.dataset.productId;
            const success = await addToCart(productId, 1);
            if (success) {
                showNotification('Đã thêm vào giỏ hàng!', 'success');
            }
        }
    });

    // Form submission
    document.querySelector('.validated-form').addEventListener('submit', async function(e) {
        e.preventDefault();

        const { isValid } = validateForm(this);
        if (!isValid) return;

        showLoading('Đang xử lý...');

        try {
            const formData = serializeForm(this);
            const response = await postRequest('api/endpoint.php', formData);

            if (response.success) {
                showNotification('Thành công!', 'success');
            } else {
                showNotification(response.message, 'error');
            }
        } catch (error) {
            showNotification('Có lỗi xảy ra!', 'error');
        } finally {
            hideLoading();
        }
    });
    </script>
</body>
</html>
```

---

## 🎯 Specific Page Integration Examples

### Example 1: product-detail.php

**Current Issues**:
- ~1200 lines of inline CSS (lines ~200-1400)
- ~500 lines of inline JavaScript (lines ~1498-2000)
- Massive duplication

**Integration Plan**:

1. **Remove inline `<style>` block entirely**
   - Styles for image gallery → already in components.css
   - Styles for variations → already in components.css
   - Styles for quantity controls → already in components.css
   - Move unique styles to asset/product-detail.css (if any)

2. **Remove inline `<script>` block**
   - `changeMainImage()` → already in components.js
   - `openImageModal()` → already in components.js
   - `selectVariation()` → already in components.js
   - `increaseQuantity()`, `decreaseQuantity()` → already in components.js
   - `addToCart()` → already in global.js
   - Keep only page-specific initialization

3. **Replace inline event handlers**:
```html
<!-- Before -->
<button onclick="changeMainImage(...)">Thumbnail</button>
<div onclick="selectVariation(this, 'size')">Size M</div>
<button onclick="increaseQuantity()">+</button>
<button onclick="addToCart(123)">Add to Cart</button>

<!-- After -->
<button class="thumbnail-item" data-image="...">Thumbnail</button>
<div class="variation-option" data-attribute-id="size">Size M</div>
<button class="quantity-btn" data-action="increase">+</button>
<button class="add-to-cart-btn" data-product-id="123">Add to Cart</button>

<!-- JavaScript auto-initialized by components.js -->
```

4. **Final structure**:
```html
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="asset/global.css">
    <link rel="stylesheet" href="asset/components.css">
    <!-- Maybe: <link rel="stylesheet" href="asset/product-detail.css"> -->
</head>
<body>
    <?php include 'header.php'; ?>

    <!-- Product content with clean HTML -->

    <?php include 'footer.php'; ?>

    <script src="asset/global.js"></script>
    <script src="asset/components.js"></script>

    <!-- Minimal page-specific JS if needed -->
    <script>
    // Only product-specific initialization
    // All functions already available from global.js and components.js
    </script>
</body>
</html>
```

### Example 2: cart.php

**Integration**:
```html
<head>
    <link rel="stylesheet" href="asset/global.css">
    <link rel="stylesheet" href="asset/components.css">
</head>
<body>
    <?php include 'header.php'; ?>

    <!-- Cart items -->
    <form method="POST">
        <div class="cart-item" data-cart-id="1">
            <input type="number" class="form-input quantity-input"
                   value="2" min="1" data-cart-id="1">
            <button type="button" class="btn btn-danger remove-cart-item"
                    data-cart-id="1">Xóa</button>
        </div>
    </form>

    <?php include 'footer.php'; ?>

    <script src="asset/global.js"></script>
    <script src="asset/components.js"></script>

    <script>
    // Update quantity
    document.querySelectorAll('.quantity-input').forEach(input => {
        input.addEventListener('change', async function() {
            const cartId = this.dataset.cartId;
            const quantity = this.value;

            showLoading();
            try {
                const response = await postRequest('update-cart.php', {
                    cart_id: cartId,
                    quantity: quantity
                });

                if (response.success) {
                    showNotification('Cập nhật thành công!', 'success');
                    await updateCartCount();
                } else {
                    showNotification(response.message, 'error');
                }
            } finally {
                hideLoading();
            }
        });
    });

    // Remove item
    document.querySelectorAll('.remove-cart-item').forEach(btn => {
        btn.addEventListener('click', async function() {
            const cartId = this.dataset.cartId;

            const confirmed = await confirmModal('Xóa sản phẩm khỏi giỏ hàng?');
            if (!confirmed) return;

            showLoading();
            try {
                const response = await postRequest('remove-cart.php', { cart_id: cartId });
                if (response.success) {
                    showNotification('Đã xóa!', 'success');
                    this.closest('.cart-item').remove();
                    await updateCartCount();
                }
            } finally {
                hideLoading();
            }
        });
    });
    </script>
</body>
</html>
```

### Example 3: admin/dashboard.php

**Integration**:
```html
<head>
    <link rel="stylesheet" href="../asset/global.css">
    <link rel="stylesheet" href="../asset/components.css">
    <link rel="stylesheet" href="../asset/admin.css">
</head>
<body class="admin-layout">
    <button id="sidebar-toggle">☰</button>

    <aside id="sidebar" class="admin-sidebar">
        <!-- Sidebar content -->
    </aside>

    <main class="admin-main">
        <!-- Dashboard widgets -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="dashboard-card-title">Total Revenue</div>
                <div class="dashboard-card-value">$<?php echo number_format($revenue); ?></div>
            </div>
        </div>

        <!-- Sales Chart -->
        <canvas id="sales-canvas" height="300"></canvas>

        <!-- Data Table -->
        <table class="data-table">
            <thead>
                <tr>
                    <th data-sortable data-column="id">ID</th>
                    <th data-sortable data-column="name">Name</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr data-id="1">
                    <td data-column="id">1</td>
                    <td data-column="name">John Doe</td>
                    <td>
                        <button class="table-action-btn edit" data-id="1">Edit</button>
                        <button class="table-action-btn delete" data-id="1">Delete</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </main>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- TK-MALL Scripts -->
    <script src="../asset/global.js"></script>
    <script src="../asset/components.js"></script>
    <script src="../asset/admin.js"></script>

    <script>
    // Pass data from PHP to JavaScript
    const salesData = <?php echo json_encode($monthly_sales); ?>;

    // Initialize charts
    if (salesData.length > 0) {
        initSalesChart('sales-canvas', salesData);
    }

    // Everything else (sidebar, tables, etc.) is auto-initialized by admin.js
    </script>
</body>
</html>
```

---

## ✅ Integration Checklist

For each page you integrate, check:

### HTML Head
- [ ] Added `<link rel="stylesheet" href="asset/global.css">`
- [ ] Added `<link rel="stylesheet" href="asset/components.css">`
- [ ] Added `<link rel="stylesheet" href="asset/admin.css">` (if admin page)
- [ ] Removed or commented out old CSS links
- [ ] Removed all `<style>` blocks (moved to CSS files or deleted)

### HTML Body
- [ ] Removed all inline `onclick`, `onchange`, `onsubmit` attributes
- [ ] Replaced with `data-*` attributes or classes
- [ ] Using CSS classes from global.css and components.css
- [ ] Removed `style="..."` inline styles (use classes instead)

### JavaScript
- [ ] Added `<script src="asset/global.js"></script>` before `</body>`
- [ ] Added `<script src="asset/components.js"></script>` after global.js
- [ ] Added `<script src="asset/admin.js"></script>` for admin pages
- [ ] Removed duplicate functions (showNotification, updateCartCount, addToCart, etc.)
- [ ] Replaced inline `<script>` blocks with external or minimal page-specific code
- [ ] Using event delegation instead of inline handlers

### Testing
- [ ] Page loads without JavaScript errors (check console)
- [ ] All buttons and links work
- [ ] Forms submit correctly
- [ ] Modals open and close
- [ ] AJAX requests work (cart, forms, etc.)
- [ ] Responsive design works (mobile, tablet, desktop)
- [ ] No visual regressions (compare with before)

---

## 🚨 Common Issues & Solutions

### Issue 1: Functions Not Found

**Error**: `ReferenceError: showNotification is not defined`

**Solution**:
- Make sure `global.js` is loaded BEFORE your page-specific script
- Check browser console for loading errors
- Verify file paths are correct

```html
<!-- ✅ Correct order -->
<script src="asset/global.js"></script>
<script src="asset/components.js"></script>
<script>
// Now can use all functions
showNotification('Test', 'success');
</script>
```

### Issue 2: Styles Not Applying

**Error**: Page looks broken, styles missing

**Solution**:
- Check CSS file paths are correct
- Clear browser cache (Ctrl+F5)
- Verify load order: global.css → components.css → page-specific
- Check browser console for 404 errors

### Issue 3: onclick Not Working

**Error**: Buttons with `data-*` attributes don't respond

**Solution**:
- Make sure event listeners are set up in JavaScript
- Use event delegation for dynamic elements
- Check that `components.js` is loaded

```javascript
// ✅ Event delegation
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('my-btn')) {
        // Handle click
    }
});
```

### Issue 4: CSRF Token Missing

**Error**: `CSRF token validation failed`

**Solution**:
- Make sure CSRF meta tag is in `<head>`
- Pass token in AJAX requests

```html
<head>
    <?php require_once 'csrf.php'; echo csrfTokenMeta(); ?>
</head>
```

```javascript
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
await postRequest('api.php', {
    csrf_token: csrfToken,
    data: myData
});
```

---

## 📊 Progress Tracking

### High Priority Pages (Do First)

- [x] header.php - ✅ Completed
- [x] footer.php - ✅ Completed
- [~] index.php - ⚠️ Partially done (CSS updated, JS needs refactor)
- [ ] product-detail.php - ❌ Not started (Critical - 1700+ lines inline)
- [ ] cart.php - ❌ Not started
- [ ] checkout.php - ❌ Not started
- [ ] admin/dashboard.php - ❌ Not started (Critical)

### Medium Priority Pages

- [ ] products.php
- [ ] category.php
- [ ] profile.php
- [ ] orders.php
- [ ] admin/products.php
- [ ] admin/orders.php
- [ ] admin/users.php

### Low Priority Pages

- [ ] login.php
- [ ] register.php
- [ ] support.php
- [ ] Other pages

---

## 💡 Tips & Best Practices

1. **Start with header.php and footer.php** ✅ - Already done
   - These are included everywhere
   - Clean them up first to benefit all pages

2. **One page at a time**
   - Don't try to refactor everything at once
   - Test each page after refactoring

3. **Keep page-specific code minimal**
   - Only put truly unique logic in page scripts
   - Use global functions whenever possible

4. **Use browser DevTools**
   - Console tab: Check for errors
   - Network tab: Verify files load correctly
   - Elements tab: Inspect applied styles

5. **Test thoroughly**
   - Test all functionality after integration
   - Test on mobile, tablet, desktop
   - Test in different browsers

6. **Commit often**
   - Commit after each page is integrated
   - Makes it easy to rollback if needed

---

## 📚 Additional Resources

- **CSS Architecture**: See `CSS_ARCHITECTURE.md`
- **JavaScript Architecture**: See `JS_ARCHITECTURE.md`
- **Inline Code Audit**: See `INLINE_CODE_AUDIT.md`
- **API Reference**: See JS_ARCHITECTURE.md for complete function reference

---

**Version**: 1.0.0
**Last Updated**: October 28, 2025
**Status**: In Progress - header.php and footer.php ✅ completed
