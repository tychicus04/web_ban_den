# 📁 Asset Directory Structure

Organized asset structure for TK-MALL e-commerce platform.

**Last Updated**: October 28, 2025

---

## 📂 Directory Structure

```
asset/
├── css/                    # All CSS files
│   ├── global.css         # Base styles, CSS variables, utilities (600+ lines)
│   ├── components.css     # Reusable UI components (800+ lines)
│   ├── admin.css          # Admin panel specific styles (500+ lines)
│   ├── base.css           # Legacy CSS (to be phased out)
│   └── category.css       # Legacy CSS (to be phased out)
│
├── js/                     # All JavaScript files
│   ├── global.js          # Core utilities and global functions (700+ lines)
│   ├── components.js      # UI components and interactions (800+ lines)
│   └── admin.js           # Admin panel functionality (600+ lines)
│
└── README.md              # This file
```

---

## 🎨 CSS Files

### global.css (Foundation Layer)
**Purpose**: Base styles, CSS variables, utilities

**Contains**:
- CSS custom properties (design tokens)
- CSS reset
- Typography system
- Utility classes (buttons, forms, cards, alerts, badges)
- Grid system
- Loading animations
- Print styles

**Load Order**: FIRST (always load before other CSS)

**Example**:
```html
<link rel="stylesheet" href="asset/css/global.css">
```

### components.css (Component Layer)
**Purpose**: Reusable UI components

**Contains**:
- Header & Navigation
- Product cards & listings
- Pagination & Breadcrumbs
- Footer
- Search bars
- Dropdown menus
- Modals
- Empty states

**Load Order**: SECOND (after global.css)

**Example**:
```html
<link rel="stylesheet" href="asset/css/components.css">
```

### admin.css (Admin Layer)
**Purpose**: Admin panel specific styles

**Contains**:
- Admin sidebar & layout
- Dashboard widgets & stats
- Data tables
- Filters & search
- Modals
- Charts & statistics
- Admin-specific responsive styles

**Load Order**: THIRD (only for admin pages)

**Example**:
```html
<!-- For admin pages only -->
<link rel="stylesheet" href="../asset/css/admin.css">
```

### Legacy Files
- **base.css**: Old base styles (1513 lines) - Contains duplication, to be refactored
- **category.css**: Old category styles (726 lines) - Contains duplication, to be refactored

---

## 🚀 JavaScript Files

### global.js (Foundation Layer)
**Purpose**: Core utilities and global functions

**Contains**:
- Configuration & state management
- Utility functions (debounce, throttle, formatters)
- Notification system
- AJAX helpers (getRequest, postRequest, ajaxRequest)
- Cart management functions
- Form validation helpers
- Loading state management
- Local storage helpers with expiry
- Security helpers (escapeHtml, XSS prevention)

**Load Order**: FIRST (always load before other JS)

**Example**:
```html
<script src="asset/js/global.js"></script>
```

**Key Functions**:
```javascript
// Notifications
showNotification(message, type);

// Cart
updateCartCount();
addToCart(productId, quantity, variations);

// AJAX
getRequest(url);
postRequest(url, data);

// Forms
validateForm(form);
isValidEmail(email);
isValidPhone(phone);

// Utilities
formatCurrency(amount);
formatDate(date);
escapeHtml(text);
debounce(fn, delay);
throttle(fn, delay);

// Loading
showLoading(message);
hideLoading();
```

### components.js (UI Layer)
**Purpose**: Reusable UI components and interactions

**Contains**:
- Header components (dropdown, search, mobile menu)
- Navigation (smooth scroll, sticky nav)
- Modal system (Modal class with events)
- Confirmation dialogs (confirmModal)
- Image gallery (zoom, thumbnails, lightbox)
- Product variations (size, color selection)
- Quantity controls
- Pagination
- Tabs
- Scroll to top
- Footer interactions
- Lazy loading images

**Load Order**: SECOND (after global.js)

**Example**:
```html
<script src="asset/js/components.js"></script>
```

**Key Functions**:
```javascript
// Modals
const modal = new Modal({title, content, size});
modal.open();
modal.close();

const confirmed = await confirmModal(message, options);

// Image Gallery
changeMainImage(imageSrc, thumbnail);
openImageModal(imageSrc);
closeImageModal();

// Product Variations
selectVariation(button, attributeId);
selectColor(colorButton);

// Quantity Controls
increaseQuantity();
decreaseQuantity();
```

### admin.js (Admin Layer)
**Purpose**: Admin panel specific functionality

**Contains**:
- Sidebar toggle & state management
- DataTable class (sortable, searchable, with CRUD)
- Chart.js integration (initSalesChart, initCategoryChart)
- Dashboard widgets & animations
- Filters & search with auto-submit
- Bulk actions system
- Image upload with preview
- Admin-specific event handlers

**Load Order**: THIRD (only for admin pages, after components.js)

**Example**:
```html
<!-- For admin pages only -->
<script src="../asset/js/admin.js"></script>
```

**Key Functions**:
```javascript
// DataTable
const table = new DataTable(tableElement, options);

// Charts
initSalesChart(canvasId, data);
initCategoryChart(canvasId, data);

// Bulk Actions
const selectedIds = getSelectedIds();
```

---

## 🎯 Load Order (IMPORTANT!)

### For Public Pages

```html
<!DOCTYPE html>
<html>
<head>
    <!-- CSS - Load in order -->
    <link rel="stylesheet" href="asset/css/global.css">
    <link rel="stylesheet" href="asset/css/components.css">

    <!-- Optional: Page-specific CSS -->
    <!-- <link rel="stylesheet" href="asset/css/my-page.css"> -->
</head>
<body>
    <!-- Page content -->

    <!-- JavaScript - Load at end of body -->
    <script src="asset/js/global.js"></script>
    <script src="asset/js/components.js"></script>

    <!-- Optional: Page-specific JavaScript -->
    <script>
    // Page-specific code here
    // Can use all global functions
    </script>
</body>
</html>
```

### For Admin Pages

```html
<!DOCTYPE html>
<html>
<head>
    <!-- CSS - Load in order -->
    <link rel="stylesheet" href="../asset/css/global.css">
    <link rel="stylesheet" href="../asset/css/components.css">
    <link rel="stylesheet" href="../asset/css/admin.css">
</head>
<body class="admin-layout">
    <!-- Admin content -->

    <!-- Chart.js (if using charts) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- TK-MALL Scripts - Load in order -->
    <script src="../asset/js/global.js"></script>
    <script src="../asset/js/components.js"></script>
    <script src="../asset/js/admin.js"></script>

    <!-- Optional: Admin page-specific JavaScript -->
    <script>
    // Admin page-specific code
    </script>
</body>
</html>
```

---

## 📏 File Sizes

### CSS Files
```
global.css:      15.7 KB (600+ lines)
components.css:  16.7 KB (800+ lines)
admin.css:       13.7 KB (500+ lines)
Total:           46.1 KB (~1900 lines)

Legacy:
base.css:        32.0 KB (1513 lines) - To be refactored
category.css:    12.1 KB (726 lines) - To be refactored
```

### JavaScript Files
```
global.js:       20.4 KB (700+ lines)
components.js:   23.5 KB (800+ lines)
admin.js:        21.8 KB (600+ lines)
Total:           65.7 KB (~2100 lines)
```

---

## ✅ Benefits of This Structure

### Before (Old Structure)
```
❌ All files in asset/ root (cluttered)
❌ No clear separation
❌ Hard to find files
❌ Mixing old and new files
```

### After (New Structure)
```
✅ Organized by file type (css/, js/)
✅ Clear separation of concerns
✅ Easy to find files
✅ Legacy files clearly marked
✅ Professional structure
✅ Scalable for future growth
```

---

## 🔄 Migration Notes

### Old Paths → New Paths

**CSS**:
```
asset/global.css        → asset/css/global.css
asset/components.css    → asset/css/components.css
asset/admin.css         → asset/css/admin.css
asset/base.css          → asset/css/base.css (legacy)
asset/category.css      → asset/css/category.css (legacy)
```

**JavaScript**:
```
asset/global.js         → asset/js/global.js
asset/components.js     → asset/js/components.js
asset/admin.js          → asset/js/admin.js
```

### Files Updated
- ✅ index.php - Paths updated
- ✅ INTEGRATION_GUIDE.md - Paths updated
- ✅ CSS_ARCHITECTURE.md - Paths updated
- ✅ JS_ARCHITECTURE.md - Paths updated
- ✅ INLINE_CODE_AUDIT.md - Paths updated

---

## 📚 Documentation

For detailed information about each file:

- **CSS Architecture**: See `../CSS_ARCHITECTURE.md`
- **JavaScript Architecture**: See `../JS_ARCHITECTURE.md`
- **Integration Guide**: See `../INTEGRATION_GUIDE.md`
- **Inline Code Audit**: See `../INLINE_CODE_AUDIT.md`

---

## 🎯 Next Steps

1. **Continue Integration**: Apply new architecture to remaining pages
2. **Refactor Legacy Files**: Remove duplicates from base.css and category.css
3. **Optimize**: Minify CSS/JS for production
4. **Monitor**: Track page load performance

---

## 💡 Best Practices

1. **Always maintain load order**: global → components → admin/page-specific
2. **Use CSS variables**: Defined in global.css
3. **Reuse components**: Don't duplicate code
4. **Keep page-specific code minimal**: Use global functions when possible
5. **Document changes**: Update this README when adding new files

---

**Version**: 1.0.0
**Last Updated**: October 28, 2025
**Maintained By**: TK-MALL Development Team
