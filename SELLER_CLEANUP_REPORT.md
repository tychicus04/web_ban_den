# Seller References Cleanup Report
**Generated:** 2025-10-28
**Status:** Phase 1 Complete (login.php fixed) - Phase 2 Analysis Complete

---

## Executive Summary
This report documents all remaining seller references across the codebase that need to be cleaned up after Phase 1 (seller functionality removal from database and login.php).

**Total Files with Seller References:** 33 files
- **Admin Pages:** 21 files
- **Customer Pages:** 7 files
- **Other:** 5 files

---

## PRIORITY 1: Admin Panel Pages (Critical)

### 1. /home/user/web_ban_den/admin/dashboard.php
**Lines:** 1385-1389
**Type:** Navigation Link
**Issue:** Sidebar navigation contains link to sellers.php
```php
<div class="nav-item">
    <a href="sellers.php" class="nav-link">
        <span class="nav-icon">👥</span>
        <span class="nav-text">Người Bán</span>
    </a>
</div>
```
**Suggested Fix:** Remove entire nav-item div block (lines 1384-1390)

---

### 2. /home/user/web_ban_den/admin/users.php
**File:** TOO LARGE - Need to check with Grep
**Type:** Navigation Link + SQL Query + User Type Options
**Issues Found:**
1. Navigation link to sellers.php in sidebar
2. Likely has seller user type in filtering/display

**Suggested Fix:**
- Remove sellers.php navigation link from sidebar
- Remove 'seller' from user_type filter options
- Update SQL WHERE clauses to exclude seller type

---

### 3. /home/user/web_ban_den/admin/products.php
**Lines:** 224, 235, 1711-1713, 1423-1427
**Type:** SQL Query + Display + Navigation

**Issues:**
1. Line 224: `u_seller.name as seller_name` - SQL SELECT with alias
2. Line 235: `LEFT JOIN users u_seller ON p.user_id = u_seller.id` - JOIN to users table
3. Lines 1711-1713: Display seller name in product meta
```php
<?php if ($product['seller_name']): ?>
    <span>👤 <?php echo htmlspecialchars($product['seller_name']); ?></span>
<?php endif; ?>
```
4. Lines 1423-1427: Navigation link to sellers.php

**Suggested Fix:**
- Line 224: Remove `u_seller.name as seller_name` from SELECT
- Line 235: Remove entire `LEFT JOIN users u_seller ON p.user_id = u_seller.id` line
- Lines 1711-1713: Remove entire conditional block showing seller name
- Lines 1423-1427: Remove navigation item

---

### 4. /home/user/web_ban_den/admin/orders.php
**Lines:** 1751-1754
**Type:** Navigation Link

**Issue:** Sidebar navigation contains link to sellers.php
```php
<a href="sellers.php" class="nav-link">
    <span class="nav-icon">👥</span>
    <span class="nav-text">Người Bán</span>
</a>
```
**Suggested Fix:** Remove entire nav-item div block

---

### 5. /home/user/web_ban_den/admin/add-user.php
**Lines:** 98, 172-198, 1119-1122, 1301, 1322-1323, 1583, 1631
**Type:** Variable + SQL Insert + Navigation + UI Elements + JavaScript

**Issues:**
1. Line 98: `$create_seller = isset($_POST['create_seller']) && $_POST['create_seller'] === 'true';`
2. Lines 172-198: Code block to create seller and shop records
```php
// Create seller account if requested
if ($create_seller) {
    // First create seller record
    $stmt = $db->prepare("
        INSERT INTO sellers (
            user_id, verification_status,
            created_at, updated_at
        ) VALUES (
            ?, 0, NOW(), NOW()
        )
    ");
    $stmt->execute([$user_id]);

    // Then create shop record
    $stmt = $db->prepare("
        INSERT INTO shops (
            user_id, name, slug, verification_status,
            created_at, updated_at
        ) VALUES (
            ?, ?, ?, 0,
            NOW(), NOW()
        )
    ");
    $shop_name = $name . "'s Shop";
    $shop_slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name)) . '-shop';
    $stmt->execute([$user_id, $shop_name, $shop_slug]);
}
```
3. Lines 1119-1122: Navigation link to sellers.php
4. Line 1301: `<option value="seller">Người bán</option>` in user type dropdown
5. Lines 1322-1323: Checkbox for "Tạo tài khoản người bán"
6. Line 1583: JavaScript variable `const createSeller = document.getElementById('create-seller').checked;`
7. Line 1631: `create_seller: createSeller` in form data

**Suggested Fix:**
- Remove lines 98, 172-198 (entire seller creation logic)
- Remove lines 1119-1122 (navigation)
- Remove line 1301 (seller option from dropdown)
- Remove lines 1320-1330 (entire "Tài khoản đặc biệt" section with both checkboxes OR just seller checkbox)
- Remove line 1583 and 1631 from JavaScript

---

### 6. /home/user/web_ban_den/admin/user-edit.php
**Lines:** 114, 219, 229, 1004-1006, 1251-1254, 1510-1512
**Type:** Validation + Variable + SQL Query + CSS + Navigation + UI

**Issues:**
1. Line 114: `if (!in_array($user_type, ['customer', 'seller', 'admin'])) $errors[] = ...`
2. Line 219: `$seller_info = null;`
3. Line 229: SQL SELECT with NULL seller fields
4. Lines 1004-1006: CSS for seller badge
5. Lines 1251-1254: Navigation link
6. Lines 1510-1512: Seller option in dropdown

**Suggested Fix:**
- Line 114: Change to `['customer', 'admin']` (remove 'seller')
- Line 219: Remove `$seller_info = null;` line
- Line 229: Remove seller-related NULL fields from SELECT
- Lines 1004-1006: Remove `.status-badge.seller` CSS block
- Lines 1251-1254: Remove navigation item
- Lines 1510-1512: Remove seller option from dropdown

---

### 7. /home/user/web_ban_den/admin/user-details.php
**Lines:** 295-296, 1036-1039, 1689-1692, 1872-1873, 1955, 2207, 2294
**Type:** SQL Query + CSS + Navigation + Conditional Display + UI

**Issues:**
1. Lines 295-296: CASE statements for is_seller and has_shop
2. Lines 1036-1039: CSS for seller badge
3. Lines 1689-1692: Navigation link
4. Lines 1872-1873: Display seller badge
5. Line 1955: Conditional check for seller shop section
6. Line 2207: Another conditional for shop info display
7. Line 2294: Seller option in user type dropdown

**Suggested Fix:**
- Lines 295-296: Remove CASE statements
- Lines 1036-1039: Remove CSS
- Lines 1689-1692: Remove navigation
- Lines 1872-1873: Remove seller case from switch
- Lines 1955-2210: Remove entire seller/shop info section
- Line 2294: Remove seller option

---

### 8. /home/user/web_ban_den/admin/order-detail.php
**Lines:** 147, 1559-1561
**Type:** SQL Query + Display

**Issues:**
1. Line 147: `us.name as seller_name` in SELECT (with LEFT JOIN)
2. Lines 1559-1561: Display seller name in order details
```php
<?php if ($item['seller_name']): ?>
    <span>Người bán: <?php echo htmlspecialchars($item['seller_name']); ?></span>
<?php endif; ?>
```

**Suggested Fix:**
- Remove `us.name as seller_name` from SELECT
- Remove corresponding LEFT JOIN for seller
- Remove lines 1559-1561 display block

---

### 9. /home/user/web_ban_den/admin/pos.php
**Lines:** 398, 471-480, 485, 493, 514, 535, 1259-1262, 3432
**Type:** Variable + SQL Query + Navigation + JavaScript

**Issues:**
1. Line 398: `$seller_id = isset($_POST['seller_id']) ? (int)$_POST['seller_id'] : 0;`
2. Lines 471-480: Logic to get actual seller_id from shop
3. Lines 485, 493: seller_id in INSERT query
4. Lines 514, 535: seller_id in order_details INSERT
5. Lines 1259-1262: Navigation link
6. Line 3432: JavaScript `formData.append('seller_id', selectedShopId);`

**Suggested Fix:**
- Remove all seller_id related code
- Remove seller_id column from INSERT statements
- Change to use admin user ID (1) or remove entirely
- Remove navigation link
- Remove JavaScript seller_id append

---

### 10. /home/user/web_ban_den/admin/shop-details.php
**Lines:** 57, 167, 172, 184, 235
**Type:** Redirect + Variable + SQL Query

**Issues:**
1. Line 57: Redirect to sellers.php on error
2. Line 167: `$seller_id = $shop['user_id'];`
3. Line 172: `seller_id` column in payments INSERT
4. Line 184: seller_id in execute
5. Line 235: seller_id and verification_status in SELECT

**Suggested Fix:**
- This entire file is for seller shop management - **FILE SHOULD BE DELETED**
- Update any links to this file to be removed

---

### 11. Navigation Links in Multiple Admin Files
**Files:** analytics.php, settings.php, staff-edit.php, contacts.php, brands.php, categories.php, category-edit.php, reviews.php, banners.php, flash-deals.php, coupons.php
**Lines:** Various (1200-1700 range typically)
**Type:** Navigation Link

**Issue:** All contain identical navigation link to sellers.php:
```php
<a href="sellers.php" class="nav-link">
    <span class="nav-icon">👥</span>
    <span class="nav-text">Người Bán</span>
</a>
```

**Suggested Fix:** Remove navigation item from each file's sidebar

---

## PRIORITY 2: Customer-Facing Pages (High Priority)

### 12. /home/user/web_ban_den/logout.php
**Lines:** 29-30
**Type:** Conditional Redirect

**Issue:**
```php
} elseif ($user_type === 'seller') {
    header('Location: seller/login.php?message=logged_out');
```

**Suggested Fix:** Remove entire elseif block (lines 29-30)

---

### 13. /home/user/web_ban_den/index.php
**Lines:** 27, 43, 313-314, 362, 421-422
**Type:** SQL Query + Display

**Issues:**
1. Lines 27, 43: `u.name as seller_name` in SQL SELECT with LEFT JOIN
2. Lines 313-314, 421-422: Display seller name in product cards
```php
<div class="product-seller">Bán bởi:
    <?php echo htmlspecialchars($product['seller_name'] ?: 'TikTok Shop'); ?>
</div>
```
3. Line 362: Hardcoded "Bán bởi: TikTok Shop"

**Suggested Fix:**
- Remove `u.name as seller_name` from SELECT statements
- Remove LEFT JOIN for users/seller
- Option A: Remove seller display entirely
- Option B: Replace with static "Bán bởi: [Site Name]"

---

### 14. /home/user/web_ban_den/products.php
**Lines:** 118, 619, 1091-1092
**Type:** SQL Query + CSS + Display

**Issues:**
1. Line 118: `u.name as seller_name` in SELECT
2. Line 619: CSS for `.seller-name`
3. Lines 1091-1092: Display seller name

**Suggested Fix:**
- Remove seller_name from SQL
- Remove LEFT JOIN for seller
- Remove or update seller-name display
- Keep CSS for now (may be used elsewhere)

---

### 15. /home/user/web_ban_den/product-detail.php
**Lines:** 28, 30, 103, 343-365, 572
**Type:** SQL Query + Display Section + CSS

**Issues:**
1. Lines 28-30: Comment + SQL with seller info
2. Line 103: Another SQL with seller_name
3. Lines 343-365: Entire "Seller Info" section
```php
<!-- Seller Info -->
<div class="seller-info">
    <div class="seller-header">
        <div class="seller-avatar">
            <?php echo strtoupper(substr($product['seller_name'] ?: 'TikTok Shop', 0, 2)); ?>
        </div>
        <div>
            <div class="seller-name">
                <?php echo htmlspecialchars($product['seller_name'] ?: 'TikTok Shop'); ?>
            </div>
        </div>
    </div>
    <div class="seller-stats">
        <div class="seller-stat">...</div>
        <div class="seller-stat">...</div>
        <div class="seller-stat">...</div>
    </div>
</div>
```
4. Line 572: seller reference in related products

**Suggested Fix:**
- Remove seller_name and related fields from SQL
- Remove entire seller-info section (lines 343-365)
- Update line 572 to show stock info without seller context
- Remove seller-related CSS classes

---

### 16. /home/user/web_ban_den/cart.php
**Lines:** 99, 323, 809-810
**Type:** SQL Query + CSS + Display

**Issues:**
1. Line 99: `u.name as seller_name` in SELECT
2. Line 323: CSS for `.item-seller`
3. Lines 809-810: Display seller in cart items
```php
<div class="item-seller">
    Bán bởi: <?php echo htmlspecialchars($item['seller_name'] ?: 'TikTok Shop'); ?>
</div>
```

**Suggested Fix:**
- Remove seller_name from SQL
- Remove LEFT JOIN
- Option A: Remove seller display entirely
- Option B: Replace with static shop name
- Keep CSS for now

---

### 17. /home/user/web_ban_den/checkout.php
**Lines:** 66, 115, 117, 208-228, 234, 239, 559, 1047-1048
**Type:** SQL Query + Variable + Logic + CSS + Display

**Issues:**
1. Line 66: `u.name as seller_name` in SELECT
2. Line 115: `'seller_name' => $row['seller_name']`
3. Line 117: `'user_id' => $row['owner_id'] ?: 1 // seller_id` comment
4. Lines 208-228: **CRITICAL** - Order grouping by seller logic
```php
// Group items by seller
$sellers_orders = [];
foreach ($cart as $item) {
    $seller_id = $item['product']['user_id'];
    if (!isset($sellers_orders[$seller_id])) {
        $sellers_orders[$seller_id] = [];
    }
    $sellers_orders[$seller_id][] = $item;
}

// Create orders for each seller
foreach ($sellers_orders as $seller_id => $seller_items) {
    $seller_total = array_sum(array_column($seller_items, 'total'));

    // Create order for this seller
    $stmt = $pdo->prepare("INSERT INTO orders (combined_order_id, user_id, seller_id, ...");
    $stmt->execute([$combined_order_id, $user_id, $seller_id, ...]);
```
5. Lines 234, 239: seller_id in order_details INSERT
6. Line 559: CSS for `.item-seller`
7. Lines 1047-1048: Display seller in checkout

**Suggested Fix:**
- Remove seller_name from SQL
- Remove seller grouping logic (lines 208-218)
- Change to create ONE order per checkout (not per seller)
- Remove seller_id from all INSERT statements
- Update to use admin/shop user_id (1) or remove column
- Remove or update seller display

---

### 18. /home/user/web_ban_den/orders.php
**Lines:** 101, 842-845
**Type:** SQL Query + Display

**Issues:**
1. Line 101: Subquery to get seller_name
```php
(SELECT s.name FROM users s WHERE s.id = o.seller_id) as seller_name
```
2. Lines 842-845: Display seller name in order details
```php
<?php if ($order['seller_name']): ?>
    <div class="info-label">Người bán</div>
    <div class="info-value"><?php echo htmlspecialchars($order['seller_name']); ?></div>
<?php endif; ?>
```

**Suggested Fix:**
- Remove seller_name subquery from SQL
- Remove seller display section (lines 842-845)

---

## PRIORITY 3: Other Files (Medium Priority)

### 19. /home/user/web_ban_den/add-to-cart.php
**Type:** Backend Logic
**Check:** May have seller-related cart grouping logic

**Suggested Fix:** Review and remove any seller grouping

---

### 20. /home/user/web_ban_den/deals.php
**Type:** Page Display
**Check:** May display seller info in deals

**Suggested Fix:** Remove seller references if any

---

### 21. /home/user/web_ban_den/support.php
**Type:** Page Display
**Check:** May have seller contact options

**Suggested Fix:** Remove seller-related support options

---

## Summary of Changes Needed

### SQL Changes
1. **Remove LEFT JOIN users for seller** in:
   - admin/products.php
   - index.php
   - products.php
   - product-detail.php
   - cart.php
   - checkout.php
   - admin/order-detail.php

2. **Remove seller_id from INSERT/UPDATE** in:
   - admin/pos.php
   - checkout.php (orders and order_details tables)
   - admin/shop-details.php

3. **Remove seller subqueries** in:
   - orders.php

### PHP Logic Changes
1. **Remove seller account creation** in:
   - admin/add-user.php (lines 172-198)

2. **Remove seller grouping logic** in:
   - checkout.php (lines 208-228) - **MOST CRITICAL**

3. **Remove seller conditionals** in:
   - logout.php (lines 29-30)
   - admin/user-edit.php (line 114)

4. **Remove seller variables** in:
   - Multiple files ($seller_info, $create_seller, etc.)

### UI/Display Changes
1. **Remove navigation links to sellers.php** in:
   - All 21 admin panel files

2. **Remove seller option from dropdowns** in:
   - admin/add-user.php
   - admin/user-edit.php
   - admin/user-details.php

3. **Remove seller display sections** in:
   - product-detail.php (lines 343-365)
   - cart.php (lines 809-810)
   - checkout.php (lines 1047-1048)
   - orders.php (lines 842-845)
   - index.php (lines 313-314, 421-422)
   - products.php (lines 1091-1092)
   - admin/order-detail.php (lines 1559-1561)

4. **Remove seller checkboxes/options** in:
   - admin/add-user.php (line 1322-1323)

### CSS Changes
1. **Remove seller-specific CSS** in:
   - admin/user-edit.php (lines 1004-1006)
   - admin/user-details.php (lines 1036-1039)
   - Multiple files with `.seller-name`, `.seller-info`, `.seller-stats` classes

### JavaScript Changes
1. **Remove seller form handling** in:
   - admin/add-user.php (lines 1583, 1631)
   - admin/pos.php (line 3432)

---

## Files to Delete Entirely
1. `/home/user/web_ban_den/admin/shop-details.php` - Entire file is for seller shop management
2. `/home/user/web_ban_den/admin/sellers.php` - If it exists (assumed from navigation links)

---

## Testing Checklist After Cleanup
- [ ] Admin can create new users (customer, admin, staff only)
- [ ] Products display without seller information
- [ ] Cart works without seller grouping
- [ ] Checkout creates ONE order (not multiple per seller)
- [ ] Orders display without seller information
- [ ] All admin navigation works (no broken seller links)
- [ ] Product detail pages work without seller info
- [ ] User management works without seller type
- [ ] POS system works without seller selection
- [ ] No console errors in browser
- [ ] No PHP errors in logs

---

## Estimated Effort
- **Admin Pages:** 4-6 hours
- **Customer Pages:** 3-4 hours
- **Testing:** 2-3 hours
- **Total:** 9-13 hours

---

## Recommended Approach
1. **Phase 2A:** Admin navigation cleanup (remove all sellers.php links) - 1 hour
2. **Phase 2B:** Admin functionality cleanup (user management, products, orders) - 3 hours
3. **Phase 2C:** Customer pages cleanup (product display, cart, checkout) - 3 hours
4. **Phase 2D:** Critical checkout logic fix (remove seller grouping) - 2 hours
5. **Phase 2E:** Testing and verification - 2 hours

---

## Risk Assessment
**HIGH RISK:**
- checkout.php seller grouping logic (lines 208-228) - May break order creation
- admin/pos.php seller_id usage - May break POS orders

**MEDIUM RISK:**
- SQL queries removing seller JOINs - May cause column not found errors
- Navigation link removal - May cause 404 errors if not thorough

**LOW RISK:**
- Display/UI changes - Safe to remove
- CSS cleanup - Safe to remove unused classes

---

**End of Report**
