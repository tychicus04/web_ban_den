/**
 * Product Pages JavaScript - Product Detail & Listing
 * Author: TK-MALL Development Team
 * Version: 1.0.0
 * Dependencies: global.js, components.js
 */

// Product Detail Page Functionality
const ProductDetail = {
    // State
    currentProduct: null,
    selectedVariations: {},
    currentQuantity: 1,
    currentImageIndex: 0,
    
    /**
     * Initialize product detail page
     */
    init(productData) {
        this.currentProduct = productData;
        this.initGallery();
        this.initQuantityControls();
        this.initVariationSelectors();
        this.initTabs();
        this.initReviewFilters();
    },
    
    /**
     * Initialize product gallery
     */
    initGallery() {
        // Thumbnail clicks
        document.querySelectorAll('.thumbnail-item').forEach((thumbnail, index) => {
            thumbnail.addEventListener('click', () => {
                this.selectImage(index);
            });
        });
        
        // Main image zoom
        const mainImage = document.querySelector('.main-image');
        if (mainImage) {
            mainImage.addEventListener('click', () => {
                this.showImageZoom(this.currentImageIndex);
            });
        }
    },
    
    /**
     * Select image from gallery
     */
    selectImage(index) {
        this.currentImageIndex = index;
        
        // Update main image
        const images = this.getProductImages();
        const mainImage = document.querySelector('.main-image');
        if (mainImage && images[index]) {
            mainImage.src = images[index];
        }
        
        // Update thumbnail active state
        document.querySelectorAll('.thumbnail-item').forEach((thumb, i) => {
            thumb.classList.toggle('active', i === index);
        });
    },
    
    /**
     * Show zoomed image modal
     */
    showImageZoom(index) {
        const images = this.getProductImages();
        // Use Modal component from modals.js
        // Create image gallery modal (to be implemented)
        console.log('Show zoom for image:', index);
    },
    
    /**
     * Get all product images
     */
    getProductImages() {
        const images = [];
        document.querySelectorAll('.thumbnail-item img').forEach(img => {
            images.push(img.src);
        });
        return images;
    },
    
    /**
     * Initialize quantity controls
     */
    initQuantityControls() {
        const quantityInput = document.getElementById('quantity');
        const decreaseBtn = document.querySelector('.quantity-btn[onclick*="decrease"]');
        const increaseBtn = document.querySelector('.quantity-btn[onclick*="increase"]');
        
        if (!quantityInput) return;
        
        // Remove inline onclick handlers if exists
        if (decreaseBtn) {
            decreaseBtn.removeAttribute('onclick');
            decreaseBtn.addEventListener('click', () => this.decreaseQuantity());
        }
        
        if (increaseBtn) {
            increaseBtn.removeAttribute('onclick');
            increaseBtn.addEventListener('click', () => this.increaseQuantity());
        }
        
        // Handle manual input
        quantityInput.addEventListener('change', (e) => {
            this.validateQuantity(e.target);
        });
    },
    
    /**
     * Decrease quantity
     */
    decreaseQuantity() {
        const input = document.getElementById('quantity');
        if (!input) return;
        
        const min = parseInt(input.min) || 1;
        const current = parseInt(input.value) || min;
        
        if (current > min) {
            input.value = current - 1;
            this.currentQuantity = current - 1;
        }
    },
    
    /**
     * Increase quantity
     */
    increaseQuantity() {
        const input = document.getElementById('quantity');
        if (!input) return;
        
        const max = parseInt(input.max) || 999;
        const current = parseInt(input.value) || 1;
        
        if (current < max) {
            input.value = current + 1;
            this.currentQuantity = current + 1;
        }
    },
    
    /**
     * Validate quantity input
     */
    validateQuantity(input) {
        const min = parseInt(input.min) || 1;
        const max = parseInt(input.max) || 999;
        let value = parseInt(input.value) || min;
        
        if (value < min) value = min;
        if (value > max) value = max;
        
        input.value = value;
        this.currentQuantity = value;
    },
    
    /**
     * Initialize variation selectors
     */
    initVariationSelectors() {
        // Size variations
        document.querySelectorAll('.variation-option').forEach(option => {
            if (option.getAttribute('onclick')) {
                option.removeAttribute('onclick');
            }
            
            option.addEventListener('click', (e) => {
                const variationType = option.closest('.variation-section')
                    .querySelector('.variation-title').textContent.trim();
                this.selectVariation(variationType, option);
            });
        });
        
        // Color variations
        document.querySelectorAll('.color-option').forEach(colorBtn => {
            if (colorBtn.getAttribute('onclick')) {
                colorBtn.removeAttribute('onclick');
            }
            
            colorBtn.addEventListener('click', (e) => {
                this.selectColor(colorBtn);
            });
        });
    },
    
    /**
     * Select variation (size, etc)
     */
    selectVariation(type, element) {
        if (element.classList.contains('disabled')) return;
        
        // Remove active from siblings
        element.parentElement.querySelectorAll('.variation-option').forEach(opt => {
            opt.classList.remove('active');
        });
        
        // Add active to selected
        element.classList.add('active');
        
        // Store selection
        this.selectedVariations[type] = element.textContent.trim();
        
        // Update price if needed
        this.updatePriceByVariation();
    },
    
    /**
     * Select color variation
     */
    selectColor(element) {
        // Remove active from siblings
        element.parentElement.querySelectorAll('.color-option').forEach(opt => {
            opt.classList.remove('active');
        });
        
        // Add active to selected
        element.classList.add('active');
        
        // Store selection
        this.selectedVariations['color'] = element.getAttribute('title');
        
        // Update image if color has associated image
        // (to be implemented)
    },
    
    /**
     * Update price based on variation selection
     */
    updatePriceByVariation() {
        // This would fetch variation-specific price from server
        // For now just a placeholder
        console.log('Selected variations:', this.selectedVariations);
    },
    
    /**
     * Add product to cart
     */
    addToCart(productId) {
        // Check if required variations are selected
        const requiredVariations = this.getRequiredVariations();
        if (!this.validateVariationSelection(requiredVariations)) {
            showNotification('Vui lòng chọn đầy đủ thuộc tính sản phẩm', 'warning');
            return;
        }
        
        const quantity = this.currentQuantity;
        
        // Use AJAX utility from global.js
        ajaxRequest('/add-to-cart.php', {
            method: 'POST',
            body: {
                product_id: productId,
                quantity: quantity,
                variations: this.selectedVariations
            },
            onSuccess: (response) => {
                if (response.success) {
                    showNotification('Đã thêm vào giỏ hàng!', 'success');
                    updateCartCount();
                } else {
                    showNotification(response.message || 'Có lỗi xảy ra', 'error');
                }
            },
            onError: (error) => {
                showNotification('Không thể thêm vào giỏ hàng', 'error');
                console.error('Add to cart error:', error);
            }
        });
    },
    
    /**
     * Buy now - quick checkout
     */
    buyNow(productId) {
        // Check variations
        const requiredVariations = this.getRequiredVariations();
        if (!this.validateVariationSelection(requiredVariations)) {
            showNotification('Vui lòng chọn đầy đủ thuộc tính sản phẩm', 'warning');
            return;
        }
        
        // Add to cart first, then redirect to checkout
        this.addToCart(productId);
        
        // Wait a bit then redirect
        setTimeout(() => {
            window.location.href = '/checkout.php';
        }, 500);
    },
    
    /**
     * Get required variations
     */
    getRequiredVariations() {
        const variations = [];
        document.querySelectorAll('.variation-section').forEach(section => {
            const title = section.querySelector('.variation-title').textContent.trim();
            variations.push(title);
        });
        return variations;
    },
    
    /**
     * Validate that all required variations are selected
     */
    validateVariationSelection(required) {
        for (const variation of required) {
            if (!this.selectedVariations[variation]) {
                return false;
            }
        }
        return true;
    },
    
    /**
     * Initialize product tabs
     */
    initTabs() {
        document.querySelectorAll('.tab-btn').forEach(tabBtn => {
            if (tabBtn.getAttribute('onclick')) {
                tabBtn.removeAttribute('onclick');
            }
            
            tabBtn.addEventListener('click', () => {
                const targetId = tabBtn.textContent.includes('Mô tả') ? 'description' :
                               tabBtn.textContent.includes('Đánh giá') ? 'reviews' :
                               tabBtn.textContent.includes('Hỏi') ? 'qa' : 'shipping';
                
                this.switchTab(tabBtn, targetId);
            });
        });
    },
    
    /**
     * Switch product tab
     */
    switchTab(button, tabId) {
        // Remove active from all tabs and buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });
        
        // Add active to selected
        button.classList.add('active');
        const tabContent = document.getElementById(tabId);
        if (tabContent) {
            tabContent.classList.add('active');
        }
    },
    
    /**
     * Initialize review filters
     */
    initReviewFilters() {
        // Star filter buttons
        document.querySelectorAll('.rating-filter-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const stars = e.target.dataset.stars;
                this.filterReviews(stars);
            });
        });
    },
    
    /**
     * Filter reviews by star rating
     */
    filterReviews(stars) {
        // This would typically make an AJAX request to load filtered reviews
        console.log('Filter reviews by stars:', stars);
        
        // Update UI to show loading state
        showLoadingModal();
        
        // Make AJAX request (placeholder)
        ajaxRequest(`/api/reviews.php?product_id=${this.currentProduct.id}&stars=${stars}`, {
            method: 'GET',
            onSuccess: (response) => {
                hideLoadingModal();
                // Update reviews list (to be implemented)
                this.renderReviews(response.reviews);
            },
            onError: (error) => {
                hideLoadingModal();
                showNotification('Không thể tải đánh giá', 'error');
            }
        });
    },
    
    /**
     * Render reviews list
     */
    renderReviews(reviews) {
        // Placeholder for review rendering
        console.log('Render reviews:', reviews);
    }
};

// Related Products Functionality
const RelatedProducts = {
    /**
     * Load related products
     */
    load(productId, categoryId) {
        ajaxRequest(`/api/related-products.php?product_id=${productId}&category_id=${categoryId}`, {
            method: 'GET',
            onSuccess: (response) => {
                if (response.products) {
                    this.render(response.products);
                }
            },
            onError: (error) => {
                console.error('Failed to load related products:', error);
            }
        });
    },
    
    /**
     * Render related products
     */
    render(products) {
        const container = document.querySelector('.related-products-grid');
        if (!container) return;
        
        container.innerHTML = products.map(product => `
            <div class="product-card">
                <a href="/product-detail.php?id=${product.id}">
                    <img src="${product.thumbnail}" alt="${product.name}" class="product-image">
                    <h4 class="product-name">${product.name}</h4>
                    <div class="product-price">${formatCurrency(product.price)}</div>
                </a>
            </div>
        `).join('');
    }
};

// Expose to global scope for legacy inline handlers
// These will be removed as we refactor
window.selectSize = (el) => ProductDetail.selectVariation('Kích cỡ', el);
window.selectColor = (el) => ProductDetail.selectColor(el);
window.decreaseQuantity = () => ProductDetail.decreaseQuantity();
window.increaseQuantity = () => ProductDetail.increaseQuantity();
window.addToCart = (id) => ProductDetail.addToCart(id);
window.buyNow = (id) => ProductDetail.buyNow(id);
window.switchTab = (btn, id) => ProductDetail.switchTab(btn, id);

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        // Check if we're on product detail page
        if (document.querySelector('.product-container')) {
            ProductDetail.init({
                id: new URLSearchParams(window.location.search).get('id')
            });
        }
    });
} else {
    // DOM already loaded
    if (document.querySelector('.product-container')) {
        ProductDetail.init({
            id: new URLSearchParams(window.location.search).get('id')
        });
    }
}
