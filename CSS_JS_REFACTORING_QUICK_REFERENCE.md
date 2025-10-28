# TK-MALL CSS/JS Refactoring - Quick Reference Guide

## All 57 Files Requiring Refactoring

### CRITICAL (4 files - 232 total issues)
1. **admin/shop-details.php** - 66 issues
   - 2× style tags, 1× script, 13× onclick, 1× onchange, 49× inline styles
   - ACTION: Extract styles + convert 49 inline styles to classes + move handlers

2. **admin/user-details.php** - 60 issues
   - 1× style, 1× script, 21× onclick, 2× onchange, 35× inline styles
   - ACTION: Extract style + refactor 35 inline styles + move 21 handlers

3. **admin/coupons.php** - 55 issues
   - 1× style, 1× script, 10× onclick, 2× onchange, 41× inline styles
   - ACTION: Extract style + create utility classes + move handlers

4. **product-detail.php** - 51 issues
   - 1× style, 1× script, 16× onclick, 33× inline styles
   - ACTION: Extract style + refactor 33 styles + convert 16 handlers

---

### HIGH (3 files - 117 total issues)
5. **admin/pos.php** - 43 issues
   - 1× style, 2× scripts, 40× inline styles
   - ACTION: Extract + consolidate scripts + refactor styles

6. **admin/reviews.php** - 37 issues
   - 1× style, 1× script, 10× onclick, 25× inline styles
   - ACTION: Extract style + create rating utility classes + move handlers

7. **seller/store-settings.php** - 37 issues
   - 1× style, 1× script, 7× onclick, 1× onchange, 26× inline styles
   - ACTION: Extract style + refactor 26 styles + move handlers

---

### MEDIUM (21 files - 429 total issues)

**Higher sub-priority (20+ issues):**
8. **seller/pos.php** - 30 issues
9. **admin/sellers.php** - 29 issues
10. **admin/contacts.php** - 29 issues
11. **seller/products.php** - 29 issues
12. **admin/settings.php** - 27 issues
13. **admin/user-edit.php** - 23 issues
14. **profile.php** - 22 issues
15. **admin/users.php** - 22 issues
16. **seller/purchase.php** - 21 issues

**Remaining medium (16-20 issues):**
17. **seller/packages.php** - 20 issues
18. **seller/product-list.php** - 20 issues
19. **checkout.php** - 19 issues
20. **sellers.php** - 19 issues
21. **seller/deposit.php** - 19 issues
22. **cart.php** - 18 issues
23. **deals.php** - 18 issues
24. **products.php** - 18 issues
25. **admin/seller-package.php** - 18 issues
26. **admin/categories.php** - 18 issues
27. **seller/add-product.php** - 18 issues
28. **seller/query-products.php** - 16 issues

---

### LOW (29 files - 215 total issues)

29. **category.php** - 15
30. **categories.php** - 15
31. **admin/flash-deals.php** - 15
32. **admin/add-user.php** - 14
33. **support.php** - 13
34. **admin/products.php** - 13
35. **seller/dashboard.php** - 12
36. **seller/orders.php** - 12
37. **seller/withdraw.php** - 12
38. **seller/finance.php** - 12
39. **admin/brands.php** - 11
40. **seller/support.php** - 11
41. **admin/product-edit.php** - 10
42. **admin/order-detail.php** - 9
43. **orders.php** - 8
44. **admin/banners.php** - 8
45. **admin/staff.php** - 8
46. **admin/staff-edit.php** - 8
47. **admin/product-view.php** - 7
48. **admin/analytics.php** - 7
49. **admin/sidebar.php** - 5
50. **seller/sidebar.php** - 5
51. **seller/login.php** - 5
52. **admin/category-edit.php** - 4
53. **admin/orders.php** - 4
54. **admin/login.php** - 3
55. **register.php** - 2
56. **admin/dashboard.php** - 2
57. **login.php** - 1

---

## Priority Breakdown

| Priority | Count | Total Issues | Details |
|----------|-------|--------------|---------|
| CRITICAL | 4 | 232 | Immediate attention needed |
| HIGH | 3 | 117 | Should be done soon |
| MEDIUM | 21 | 429 | Recommended |
| LOW | 29 | 215 | Optional/Quick wins |
| **TOTAL** | **57** | **993** | Across all files |

---

## Issue Type Distribution

| Type | Count |
|------|-------|
| Inline style attributes (style="") | 502 |
| Event handlers (onclick, onchange, etc.) | 441 |
| Style tags (<style>) | ~290 |
| Script tags (<script>) | ~127 |

---

## Action Items

### Immediate (This Week)
- [ ] Read CSS_JS_REFACTORING_ANALYSIS.md (full report)
- [ ] Review 4 CRITICAL files
- [ ] Plan Phase 1 infrastructure setup
- [ ] Create CSS/JS directory structure

### Short-term (Next 2 Weeks)
- [ ] Refactor CRITICAL files (4 files, 232 issues)
- [ ] Refactor HIGH priority files (3 files, 117 issues)

### Medium-term (Weeks 3-4)
- [ ] Refactor MEDIUM priority files (21 files, 429 issues)

### Long-term (Week 5-6)
- [ ] Refactor LOW priority files (29 files, 215 issues)
- [ ] Final audit and optimization

---

## File Categories Summary

**Public Pages (13):**
- CRITICAL: 1 file (product-detail.php)
- MEDIUM: 6 files
- LOW: 6 files
- Skip: header.php, footer.php, index.php (already done)

**Admin Pages (29):**
- CRITICAL: 3 files (shop-details, user-details, coupons)
- HIGH: 2 files (pos, reviews)
- MEDIUM: 14 files
- LOW: 10 files

**Seller Pages (16):**
- MEDIUM: 6 files
- LOW: 10 files

---

## Key Refactoring Patterns

### Pattern 1: Extract Inline Styles
```html
<!-- BEFORE -->
<div style="display: flex; gap: 10px; padding: 20px;">...</div>

<!-- AFTER -->
<div class="flex-layout-standard">...</div>
<!-- In CSS file: .flex-layout-standard { display: flex; gap: 10px; padding: 20px; } -->
```

### Pattern 2: Event Handler Delegation
```html
<!-- BEFORE -->
<button onclick="deleteItem(123)">Delete</button>

<!-- AFTER -->
<button class="btn-delete" data-id="123">Delete</button>
<!-- In JS file: event delegation listener for .btn-delete -->
```

### Pattern 3: Consolidate Scripts
```html
<!-- BEFORE -->
<script>function foo() { ... }</script>
<script>function bar() { ... }</script>

<!-- AFTER -->
<script src="/asset/js/module.js"></script>
<!-- Functions in single external file -->
```

---

## Expected Outcomes

**Code Quality:**
- Reduced HTML file sizes (inline code removed)
- Better CSS organization (centralized)
- Improved maintainability (single source of truth)

**Performance:**
- CSS caching across pages
- Optimized event delegation
- Reduced DOM parsing overhead

**Metrics:**
- ~1,000 inline code instances will be refactored
- ~290 style tags consolidated into modular CSS
- ~127 script tags consolidated into modular JS
- ~441 inline handlers converted to event delegation

---

## Resources

- Full Analysis: `/CSS_JS_REFACTORING_ANALYSIS.md`
- This Quick Reference: Quick lookup for file priorities
- Architecture Docs: See `/CSS_ARCHITECTURE.md` and `/JS_ARCHITECTURE.md`

---

## Contact & Questions

For questions about the analysis or refactoring approach, refer to the detailed analysis report.

