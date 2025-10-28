# Hướng Dẫn Refactor CSS/JS - TK-MALL E-Commerce

**Version:** 1.0.0  
**Last Updated:** October 28, 2025  
**Author:** TK-MALL Development Team

---

## 📋 Mục Lục

1. [Tổng Quan](#tổng-quan)
2. [Cấu Trúc File Mới](#cấu-trúc-file-mới)
3. [Các Bước Refactor](#các-bước-refactor)
4. [Ví Dụ Chi Tiết](#ví-dụ-chi-tiết)
5. [Quy Tắc & Best Practices](#quy-tắc--best-practices)
6. [Checklist](#checklist)

---

## 🎯 Tổng Quan

### Mục Tiêu
- Tách CSS inline ra file riêng
- Tách JavaScript inline ra file riêng
- Sử dụng utility classes thay vì inline styles
- Sử dụng event delegation thay vì inline event handlers
- Tổ chức code theo modules

### Lợi Ích
- **Maintainability**: Dễ bảo trì và cập nhật
- **Performance**: CSS/JS được cache, giảm kích thước HTML
- **Consistency**: Code đồng nhất, dễ đọc
- **Reusability**: Components và utilities có thể tái sử dụng

---

## 📁 Cấu Trúc File Mới

```
asset/
├── css/
│   ├── global.css          # CSS variables, reset, typography
│   ├── base.css            # Base layout (header, nav, footer)
│   ├── components.css      # Reusable components
│   ├── utilities.css       # Utility classes (flex, spacing, colors)
│   ├── forms.css           # Form components
│   ├── tables.css          # Table components
│   ├── modals.css          # Modal dialogs
│   ├── pages/
│   │   ├── home.css        # Homepage specific
│   │   ├── product.css     # Product page specific
│   │   └── admin.css       # Admin specific
│   └── category.css        # Category page specific
│
├── js/
│   ├── global.js           # Core utilities, AJAX, notifications
│   ├── components.js       # UI components (sliders, tabs, etc)
│   ├── forms.js            # Form validation & handling
│   ├── modals.js           # Modal system
│   └── pages/
│       ├── product.js      # Product page specific
│       ├── cart.js         # Cart functionality
│       └── admin.js        # Admin specific
```

---

## 🔄 Các Bước Refactor

### Bước 1: Setup - Include CSS/JS Files

**Trong `header.php` (trước `</head>`):**

```php
<!-- Global Styles (Load first) -->
<link rel="stylesheet" href="/asset/css/global.css">

<!-- Base & Layout -->
<link rel="stylesheet" href="/asset/css/base.css">

<!-- Components -->
<link rel="stylesheet" href="/asset/css/components.css">
<link rel="stylesheet" href="/asset/css/utilities.css">
<link rel="stylesheet" href="/asset/css/forms.css">
<link rel="stylesheet" href="/asset/css/tables.css">
<link rel="stylesheet" href="/asset/css/modals.css">

<!-- Page Specific (conditional) -->
<?php if ($current_page === 'product-detail'): ?>
<link rel="stylesheet" href="/asset/css/pages/product.css">
<?php endif; ?>
```

**Trước `</body>`:**

```php
<!-- Global Scripts (Load first) -->
<script src="/asset/js/global.js"></script>

<!-- Component Scripts -->
<script src="/asset/js/components.js"></script>
<script src="/asset/js/forms.js"></script>
<script src="/asset/js/modals.js"></script>

<!-- Page Specific (conditional) -->
<?php if ($current_page === 'product-detail'): ?>
<script src="/asset/js/pages/product.js"></script>
<?php endif; ?>
```

---

### Bước 2: Refactor Inline CSS

#### 2.1. Nhận Diện CSS Inline

**TÌM:**
```html
<div style="display: flex; gap: 10px; padding: 20px;">
<div style="background: #f8f9fa; border-radius: 8px;">
<span style="color: red; font-weight: bold;">Error</span>
```

#### 2.2. Chuyển Sang Utility Classes

**THAY BẰNG:**
```html
<div class="flex gap-md p-xl">
<div class="bg-light rounded-lg">
<span class="text-danger font-bold">Error</span>
```

#### 2.3. Các Utility Classes Thường Dùng

**Layout:**
```css
.flex                   /* display: flex */
.flex-column            /* flex-direction: column */
.justify-between        /* justify-content: space-between */
.items-center           /* align-items: center */
.gap-xs, .gap-sm, .gap-md, .gap-lg
```

**Spacing:**
```css
.m-xs, .m-sm, .m-md, .m-lg    /* margin */
.p-xs, .p-sm, .p-md, .p-lg    /* padding */
.mt-lg, .mb-lg, .ml-lg, .mr-lg /* margin top/bottom/left/right */
.px-lg, .py-lg                  /* padding horizontal/vertical */
```

**Typography:**
```css
.text-center, .text-left, .text-right
.font-bold, .font-medium, .font-normal
.text-xs, .text-sm, .text-base, .text-lg, .text-xl
```

**Colors:**
```css
.text-primary, .text-danger, .text-success, .text-warning
.bg-primary, .bg-light, .bg-white
```

**Borders & Radius:**
```css
.border, .border-t, .border-b
.rounded-sm, .rounded-md, .rounded-lg, .rounded-full
```

#### 2.4. Tạo Class Mới Khi Cần

**Nếu style phức tạp hoặc lặp lại nhiều, tạo class riêng:**

```html
<!-- TRƯỚC -->
<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            padding: 30px; 
            border-radius: 15px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);">

<!-- SAU -->
<div class="gradient-card">
```

**Thêm vào file CSS tương ứng:**

```css
/* In asset/css/components.css or pages/[page].css */
.gradient-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: var(--spacing-3xl);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-lg);
}
```

---

### Bước 3: Refactor Inline JavaScript

#### 3.1. Chuyển từ Inline Event Handlers

**TRƯỚC:**
```html
<button onclick="deleteItem(5)">Xóa</button>
<button onclick="editUser(10)">Sửa</button>
<select onchange="filterProducts(this.value)">
```

**SAU:**
```html
<button class="btn-delete" data-id="5">Xóa</button>
<button class="btn-edit" data-user-id="10">Sửa</button>
<select class="filter-products" data-filter="products">
```

#### 3.2. Sử dụng Event Delegation

**Thêm vào file JS tương ứng:**

```javascript
// In asset/js/pages/[page].js

// Delete button handler
document.addEventListener('click', (e) => {
    const deleteBtn = e.target.closest('.btn-delete');
    if (!deleteBtn) return;
    
    e.preventDefault();
    const itemId = deleteBtn.getAttribute('data-id');
    
    showConfirmModal({
        title: 'Xác nhận xóa',
        message: 'Bạn có chắc chắn muốn xóa?',
        onConfirm: async () => {
            try {
                await deleteItem(itemId);
                showNotification('Xóa thành công!', 'success');
                // Reload or update UI
            } catch (error) {
                showNotification('Xóa thất bại!', 'error');
            }
        }
    });
});

// Edit button handler
document.addEventListener('click', (e) => {
    const editBtn = e.target.closest('.btn-edit');
    if (!editBtn) return;
    
    const userId = editBtn.getAttribute('data-user-id');
    window.location.href = `user-edit.php?id=${userId}`;
});

// Filter change handler
document.addEventListener('change', (e) => {
    if (!e.target.matches('.filter-products')) return;
    
    const value = e.target.value;
    filterProducts(value);
});
```

---

### Bước 4: Refactor Style Tags

**TRƯỚC:**
```php
<style>
.product-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}
.product-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
}
</style>

<div class="product-grid">
    <div class="product-card">...</div>
</div>
```

**SAU:**

**1. Di chuyển CSS sang file riêng:**

```css
/* In asset/css/pages/product.css */
.product-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--spacing-xl);
}

.product-card {
    background: var(--color-bg-primary);
    padding: var(--spacing-xl);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    transition: transform var(--transition-base);
}

.product-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-xl);
}
```

**2. Xóa `<style>` tag và giữ lại HTML:**

```html
<div class="product-grid">
    <div class="product-card">...</div>
</div>
```

**3. Include CSS file trong header:**

```php
<?php if (in_array($current_page, ['products', 'category', 'product-detail'])): ?>
<link rel="stylesheet" href="/asset/css/pages/product.css">
<?php endif; ?>
```

---

### Bước 5: Refactor Script Tags

**TRƯỚC:**
```html
<script>
function loadProducts(page) {
    fetch(`/api/products?page=${page}`)
        .then(res => res.json())
        .then(data => {
            renderProducts(data);
        });
}

function renderProducts(products) {
    // render logic
}

// Auto load
document.addEventListener('DOMContentLoaded', () => {
    loadProducts(1);
});
</script>
```

**SAU:**

**1. Di chuyển sang file JS riêng:**

```javascript
/* In asset/js/pages/product.js */

/**
 * Product listing functionality
 */
class ProductListing {
    constructor(container) {
        this.container = container;
        this.currentPage = 1;
        this.init();
    }

    init() {
        this.loadProducts(1);
        this.setupPagination();
    }

    async loadProducts(page) {
        try {
            showLoading();
            const response = await fetch(`/api/products?page=${page}`);
            const data = await response.json();
            
            this.renderProducts(data.products);
            this.renderPagination(data.pagination);
            
            hideLoading();
        } catch (error) {
            console.error('Error loading products:', error);
            showNotification('Không thể tải sản phẩm!', 'error');
            hideLoading();
        }
    }

    renderProducts(products) {
        const html = products.map(product => `
            <div class="product-card" data-id="${product.id}">
                <img src="${product.image}" alt="${product.name}">
                <h3>${product.name}</h3>
                <p class="text-danger font-bold">${formatCurrency(product.price)}</p>
                <button class="btn-add-to-cart" data-product-id="${product.id}">
                    Thêm vào giỏ
                </button>
            </div>
        `).join('');

        this.container.innerHTML = html;
    }

    setupPagination() {
        document.addEventListener('click', (e) => {
            const pageBtn = e.target.closest('.pagination-btn');
            if (!pageBtn) return;

            e.preventDefault();
            const page = parseInt(pageBtn.getAttribute('data-page'));
            this.loadProducts(page);
        });
    }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    const container = document.querySelector('.product-grid');
    if (container) {
        new ProductListing(container);
    }
});
```

**2. Xóa `<script>` tag**

**3. Include JS file trước `</body>`:**

```php
<?php if (in_array($current_page, ['products', 'category'])): ?>
<script src="/asset/js/pages/product.js"></script>
<?php endif; ?>
```

---

## 📝 Ví Dụ Chi Tiết

### Ví Dụ 1: Product Card

**TRƯỚC:**
```html
<style>
.product-item {
    display: flex;
    flex-direction: column;
    padding: 15px;
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    margin-bottom: 10px;
}
.product-price {
    color: #ff6b35;
    font-weight: bold;
    font-size: 18px;
}
</style>

<div class="product-item">
    <img src="product.jpg" style="width: 100%; height: 200px; object-fit: cover;">
    <h3 style="margin: 10px 0; font-size: 16px;">Tên sản phẩm</h3>
    <div class="product-price">150.000₫</div>
    <button onclick="addToCart(5)" style="background: #1877f2; color: white; border: none; padding: 10px; border-radius: 5px; cursor: pointer;">
        Thêm vào giỏ
    </button>
</div>
```

**SAU:**

**HTML:**
```html
<div class="product-card">
    <img src="product.jpg" alt="Tên sản phẩm" class="product-image">
    <h3 class="product-name">Tên sản phẩm</h3>
    <div class="product-price">150.000₫</div>
    <button class="btn btn-primary add-to-cart-btn" data-product-id="5">
        Thêm vào giỏ
    </button>
</div>
```

**CSS (trong components.css - đã có sẵn):**
```css
/* Already defined in components.css */
.product-card { ... }
.product-image { ... }
.product-name { ... }
.product-price { ... }
```

**JavaScript (trong components.js hoặc pages/product.js):**
```javascript
// Event delegation - handle all add to cart buttons
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.add-to-cart-btn');
    if (!btn) return;

    e.preventDefault();
    const productId = btn.getAttribute('data-product-id');

    try {
        btn.disabled = true;
        btn.textContent = 'Đang thêm...';

        await addToCart(productId);

        btn.textContent = '✓ Đã thêm';
        setTimeout(() => {
            btn.disabled = false;
            btn.textContent = 'Thêm vào giỏ';
        }, 2000);
    } catch (error) {
        btn.disabled = false;
        btn.textContent = 'Thêm vào giỏ';
        showNotification('Không thể thêm vào giỏ!', 'error');
    }
});
```

---

### Ví Dụ 2: Modal Confirmation

**TRƯỚC:**
```html
<button onclick="confirmDelete(10)">Xóa</button>

<script>
function confirmDelete(id) {
    if (confirm('Bạn có chắc chắn muốn xóa?')) {
        fetch('/delete.php?id=' + id)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Xóa thành công!');
                    location.reload();
                }
            });
    }
}
</script>
```

**SAU:**

**HTML:**
```html
<button class="btn btn-danger" 
        data-delete-confirm 
        data-item-name="sản phẩm này"
        data-delete-url="/delete.php?id=10">
    Xóa
</button>
```

**JavaScript (trong modals.js - đã có sẵn):**
```javascript
// Already implemented in modals.js
// Just use data attributes:
// - data-delete-confirm: Enable delete confirmation
// - data-item-name: Name to show in confirmation
// - data-delete-url: URL to send delete request
```

---

### Ví Dụ 3: Table với Actions

**TRƯỚC:**
```html
<style>
table {
    width: 100%;
    border-collapse: collapse;
}
th, td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}
.action-btn {
    padding: 5px 10px;
    margin: 0 2px;
    cursor: pointer;
}
</style>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Tên</th>
            <th>Hành động</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>1</td>
            <td>Sản phẩm A</td>
            <td>
                <button class="action-btn" onclick="editItem(1)" style="background: #007bff; color: white;">Sửa</button>
                <button class="action-btn" onclick="deleteItem(1)" style="background: #dc3545; color: white;">Xóa</button>
            </td>
        </tr>
    </tbody>
</table>
```

**SAU:**

**HTML:**
```html
<table class="table table-striped table-hover">
    <thead>
        <tr>
            <th>ID</th>
            <th>Tên</th>
            <th class="text-center">Hành động</th>
        </tr>
    </thead>
    <tbody>
        <tr data-id="1">
            <td>1</td>
            <td>Sản phẩm A</td>
            <td>
                <div class="table-actions center">
                    <button class="btn-table-action btn-edit" data-id="1">
                        ✏️ Sửa
                    </button>
                    <button class="btn-table-action btn-delete" 
                            data-delete-confirm
                            data-item-name="sản phẩm A"
                            data-delete-url="/delete.php?id=1">
                        🗑️ Xóa
                    </button>
                </div>
            </td>
        </tr>
    </tbody>
</table>
```

**JavaScript:**
```javascript
// Edit handler
document.addEventListener('click', (e) => {
    const editBtn = e.target.closest('.btn-edit');
    if (!editBtn) return;

    const id = editBtn.getAttribute('data-id');
    window.location.href = `edit.php?id=${id}`;
});

// Delete handler is already in modals.js
```

---

## ✅ Quy Tắc & Best Practices

### CSS

1. **Sử dụng CSS Variables**
   ```css
   /* ❌ BAD */
   color: #1877f2;
   
   /* ✅ GOOD */
   color: var(--color-primary);
   ```

2. **Utility Classes cho Simple Styles**
   ```html
   <!-- ❌ BAD -->
   <div style="padding: 20px; margin-bottom: 10px;">
   
   <!-- ✅ GOOD -->
   <div class="p-xl mb-md">
   ```

3. **Component Classes cho Complex Styles**
   ```html
   <!-- ❌ BAD -->
   <div class="p-xl bg-white rounded-lg shadow-md border">
   
   <!-- ✅ GOOD -->
   <div class="card">
   ```

4. **BEM Naming cho Custom Components**
   ```css
   /* Component */
   .product-card { }
   
   /* Element */
   .product-card__image { }
   .product-card__title { }
   
   /* Modifier */
   .product-card--featured { }
   ```

### JavaScript

1. **Event Delegation**
   ```javascript
   // ❌ BAD
   document.querySelectorAll('.btn-delete').forEach(btn => {
       btn.addEventListener('click', handleDelete);
   });
   
   // ✅ GOOD
   document.addEventListener('click', (e) => {
       const btn = e.target.closest('.btn-delete');
       if (!btn) return;
       handleDelete(e, btn);
   });
   ```

2. **Data Attributes cho Configuration**
   ```html
   <!-- ✅ GOOD -->
   <button data-action="delete" 
           data-id="5" 
           data-confirm="true">
       Xóa
   </button>
   ```

3. **Async/Await cho AJAX**
   ```javascript
   // ❌ BAD
   fetch(url).then(res => res.json()).then(data => { ... });
   
   // ✅ GOOD
   try {
       const response = await fetch(url);
       const data = await response.json();
   } catch (error) {
       console.error(error);
   }
   ```

4. **Classes cho Reusable Logic**
   ```javascript
   // ✅ GOOD
   class ProductManager {
       constructor(options) { }
       load() { }
       render() { }
   }
   ```

---

## ☑️ Checklist

### Mỗi File PHP Cần Refactor:

- [ ] **Kiểm tra inline styles** (`style="..."`)
  - [ ] Chuyển sang utility classes nếu đơn giản
  - [ ] Tạo component class nếu phức tạp
  
- [ ] **Kiểm tra inline event handlers** (`onclick=""`, `onchange=""`, etc.)
  - [ ] Thêm data attributes
  - [ ] Tạo event delegation trong JS file
  
- [ ] **Kiểm tra `<style>` tags**
  - [ ] Di chuyển CSS sang file tương ứng
  - [ ] Sử dụng CSS variables
  - [ ] Xóa `<style>` tag
  
- [ ] **Kiểm tra `<script>` tags**
  - [ ] Di chuyển JavaScript sang file riêng
  - [ ] Tổ chức thành functions/classes
  - [ ] Sử dụng utilities từ global.js
  - [ ] Xóa `<script>` tag
  
- [ ] **Include CSS/JS files**
  - [ ] Thêm `<link>` trong header
  - [ ] Thêm `<script>` trước `</body>`
  - [ ] Conditional loading nếu cần
  
- [ ] **Test**
  - [ ] UI hiển thị đúng
  - [ ] Tất cả functions hoạt động
  - [ ] Không có console errors
  - [ ] Mobile responsive

---

## 🎓 Ví Dụ Hoàn Chỉnh: Refactor 1 File

### File: `product-detail.php`

**TRƯỚC (Simplified):**
```php
<!DOCTYPE html>
<html>
<head>
    <title>Chi tiết sản phẩm</title>
    <style>
    .product-detail {
        display: flex;
        gap: 30px;
        padding: 20px;
    }
    .product-image {
        flex: 1;
        max-width: 500px;
    }
    .product-info {
        flex: 1;
    }
    .price {
        color: #ff6b35;
        font-size: 24px;
        font-weight: bold;
    }
    </style>
</head>
<body>
    <div class="product-detail">
        <div class="product-image">
            <img src="product.jpg" style="width: 100%; border-radius: 10px;">
        </div>
        <div class="product-info">
            <h1 style="margin-bottom: 10px;">Tên sản phẩm</h1>
            <div class="price">500.000₫</div>
            <button onclick="addToCart(5)" style="background: #1877f2; color: white; padding: 15px 30px; border: none; border-radius: 8px; cursor: pointer;">
                Thêm vào giỏ hàng
            </button>
        </div>
    </div>

    <script>
    function addToCart(id) {
        fetch('/add-to-cart.php?id=' + id)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Đã thêm vào giỏ hàng!');
                }
            });
    }
    </script>
</body>
</html>
```

**SAU:**

**1. product-detail.php:**
```php
<?php
require_once 'config.php';
$current_page = 'product-detail';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Chi tiết sản phẩm</title>
    
    <!-- Global CSS -->
    <link rel="stylesheet" href="/asset/css/global.css">
    <link rel="stylesheet" href="/asset/css/base.css">
    <link rel="stylesheet" href="/asset/css/components.css">
    <link rel="stylesheet" href="/asset/css/utilities.css">
    
    <!-- Page Specific CSS -->
    <link rel="stylesheet" href="/asset/css/pages/product.css">
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="product-detail flex gap-3xl p-xl">
        <div class="product-image flex-1">
            <img src="product.jpg" alt="Tên sản phẩm" class="w-full rounded-lg">
        </div>
        <div class="product-info flex-1">
            <h1 class="mb-md">Tên sản phẩm</h1>
            <div class="product-price mb-xl">500.000₫</div>
            <button class="btn btn-primary btn-lg add-to-cart-btn" 
                    data-product-id="5">
                Thêm vào giỏ hàng
            </button>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <!-- Global JS -->
    <script src="/asset/js/global.js"></script>
    <script src="/asset/js/components.js"></script>
    
    <!-- Page Specific JS -->
    <script src="/asset/js/pages/product.js"></script>
</body>
</html>
```

**2. asset/css/pages/product.css:**
```css
/* Product Detail Page */
.product-detail {
    max-width: 1200px;
    margin: 0 auto;
}

.product-image img {
    box-shadow: var(--shadow-lg);
}

.product-price {
    font-size: var(--font-size-2xl);
    font-weight: var(--font-weight-bold);
    color: var(--color-secondary);
}

@media (max-width: 768px) {
    .product-detail {
        flex-direction: column;
    }
}
```

**3. asset/js/pages/product.js:**
```javascript
/**
 * Product Detail Page JavaScript
 */

// Add to cart handler
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.add-to-cart-btn');
    if (!btn) return;

    e.preventDefault();
    
    const productId = btn.getAttribute('data-product-id');
    const originalText = btn.textContent;

    try {
        btn.disabled = true;
        btn.textContent = 'Đang thêm...';

        const success = await addToCart(productId);

        if (success) {
            btn.textContent = '✓ Đã thêm vào giỏ';
            setTimeout(() => {
                btn.disabled = false;
                btn.textContent = originalText;
            }, 2000);
        }
    } catch (error) {
        btn.disabled = false;
        btn.textContent = originalText;
    }
});
```

---

## 🚀 Bắt Đầu Refactor

### Thứ Tự Ưu Tiên

1. **Critical Files (4 files):**
   - admin/shop-details.php
   - admin/user-details.php
   - admin/coupons.php
   - product-detail.php

2. **High Priority (3 files):**
   - admin/pos.php
   - admin/reviews.php
   - seller/store-settings.php

3. **Medium Priority (21 files)**
4. **Low Priority (29 files)**

### Estimate Timeline

- **Per file:** 30-60 phút (tùy độ phức tạp)
- **Critical files:** 1-2 tuần
- **High priority:** 1 tuần
- **Medium priority:** 2-3 tuần
- **Low priority:** 2 tuần

**Total:** ~6-8 tuần

---

## 📚 Resources

- [CSS_JS_REFACTORING_INDEX.md](CSS_JS_REFACTORING_INDEX.md) - Tổng quan
- [CSS_JS_REFACTORING_ANALYSIS.md](CSS_JS_REFACTORING_ANALYSIS.md) - Phân tích chi tiết
- [CSS_JS_REFACTORING_EXAMPLES.md](CSS_JS_REFACTORING_EXAMPLES.md) - Ví dụ thực tế
- [asset/README.md](asset/README.md) - Cấu trúc thư mục CSS/JS

---

**Happy Refactoring! 🎉**
