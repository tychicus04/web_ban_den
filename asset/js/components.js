/**
 * ============================================
 * TK-MALL Components JavaScript
 * ============================================
 * Reusable UI components and interactions
 * Load this file AFTER global.js
 *
 * Version: 1.0.0
 * Last Updated: 2025-10-28
 */

// ============================================
// HEADER COMPONENTS
// ============================================

/**
 * Initialize header components
 */
function initHeader() {
    initUserDropdown();
    initSearchFunctionality();
    initMobileMenu();
}

/**
 * User dropdown functionality
 */
function initUserDropdown() {
    const dropdown = document.querySelector('.user-dropdown');
    if (!dropdown) return;

    const dropdownBtn = dropdown.querySelector('.user-dropdown-btn');
    const dropdownMenu = dropdown.querySelector('.user-dropdown-menu');

    // Toggle dropdown on button click
    if (dropdownBtn) {
        dropdownBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown.classList.toggle('show');
        });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if (dropdown && !dropdown.contains(e.target)) {
            dropdown.classList.remove('show');
        }
    });

    // Close on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && dropdown.classList.contains('show')) {
            dropdown.classList.remove('show');
        }
    });
}

/**
 * Search functionality with validation and suggestions
 */
function initSearchFunctionality() {
    const searchForm = document.querySelector('.search-container form');
    const searchInput = document.querySelector('.search-input');

    if (searchForm) {
        searchForm.addEventListener('submit', (e) => {
            const query = searchInput.value.trim();
            if (!query) {
                e.preventDefault();
                showNotification('Vui lòng nhập từ khóa tìm kiếm', 'warning');
                searchInput.focus();
            }
        });
    }

    // Auto-complete with debounce
    if (searchInput) {
        const debouncedSearch = debounce((query) => {
            if (query.length >= 2) {
                fetchSearchSuggestions(query);
            } else {
                hideSearchSuggestions();
            }
        }, 300);

        searchInput.addEventListener('input', (e) => {
            debouncedSearch(e.target.value.trim());
        });

        // Clear suggestions on blur (with delay to allow click)
        searchInput.addEventListener('blur', () => {
            setTimeout(hideSearchSuggestions, 200);
        });
    }
}

/**
 * Fetch search suggestions
 */
async function fetchSearchSuggestions(query) {
    try {
        const response = await getRequest(`search-suggestions.php?q=${encodeURIComponent(query)}`);
        if (response.suggestions && response.suggestions.length > 0) {
            showSearchSuggestions(response.suggestions);
        } else {
            hideSearchSuggestions();
        }
    } catch (error) {
        console.error('Error fetching search suggestions:', error);
    }
}

/**
 * Show search suggestions dropdown
 */
function showSearchSuggestions(suggestions) {
    let suggestionsContainer = document.getElementById('search-suggestions');

    if (!suggestionsContainer) {
        suggestionsContainer = document.createElement('div');
        suggestionsContainer.id = 'search-suggestions';
        suggestionsContainer.className = 'search-suggestions';
        document.querySelector('.search-container').appendChild(suggestionsContainer);
    }

    suggestionsContainer.innerHTML = suggestions.map(item => `
        <a href="product-detail.php?slug=${item.slug}" class="suggestion-item">
            <img src="${item.image || 'placeholder.png'}" alt="${item.name}">
            <div class="suggestion-content">
                <div class="suggestion-name">${escapeHtml(item.name)}</div>
                <div class="suggestion-price">${formatCurrency(item.price)}</div>
            </div>
        </a>
    `).join('');

    suggestionsContainer.classList.add('show');
}

/**
 * Hide search suggestions
 */
function hideSearchSuggestions() {
    const suggestionsContainer = document.getElementById('search-suggestions');
    if (suggestionsContainer) {
        suggestionsContainer.classList.remove('show');
    }
}

/**
 * Mobile menu toggle
 */
function initMobileMenu() {
    const menuToggle = document.getElementById('mobile-menu-toggle');
    const mobileMenu = document.getElementById('mobile-menu');

    if (menuToggle && mobileMenu) {
        menuToggle.addEventListener('click', () => {
            mobileMenu.classList.toggle('active');
            document.body.style.overflow = mobileMenu.classList.contains('active') ? 'hidden' : '';
        });

        // Close on overlay click
        mobileMenu.addEventListener('click', (e) => {
            if (e.target === mobileMenu) {
                mobileMenu.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    }
}

// ============================================
// NAVIGATION COMPONENTS
// ============================================

/**
 * Initialize navigation
 */
function initNavigation() {
    // Smooth scroll effect for nav links
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', function(e) {
            // Add click effect
            this.style.transform = 'scale(0.95)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 100);
        });
    });

    // Sticky navigation
    let lastScroll = 0;
    const nav = document.querySelector('.nav');

    if (nav) {
        window.addEventListener('scroll', throttle(() => {
            const currentScroll = window.pageYOffset;

            if (currentScroll > 100) {
                nav.classList.add('sticky');
            } else {
                nav.classList.remove('sticky');
            }

            lastScroll = currentScroll;
        }, 100));
    }
}

// ============================================
// MODAL COMPONENT
// ============================================

/**
 * Modal class for reusable modals
 */
class Modal {
    constructor(options = {}) {
        this.id = options.id || generateId('modal');
        this.title = options.title || '';
        this.content = options.content || '';
        this.size = options.size || 'medium'; // small, medium, large
        this.closeOnOverlay = options.closeOnOverlay !== false;
        this.closeOnEscape = options.closeOnEscape !== false;
        this.onOpen = options.onOpen || null;
        this.onClose = options.onClose || null;
        this.modal = null;

        this.create();
    }

    create() {
        this.modal = document.createElement('div');
        this.modal.id = this.id;
        this.modal.className = `modal modal-${this.size}`;
        this.modal.innerHTML = `
            <div class="modal-overlay"></div>
            <div class="modal-dialog">
                <div class="modal-header">
                    <h3 class="modal-title">${this.title}</h3>
                    <button class="modal-close" aria-label="Close">×</button>
                </div>
                <div class="modal-body">
                    ${this.content}
                </div>
            </div>
        `;

        document.body.appendChild(this.modal);

        // Setup event listeners
        this.setupEventListeners();

        // Track in global state
        TKMALL.state.modals.push(this);
    }

    setupEventListeners() {
        // Close button
        const closeBtn = this.modal.querySelector('.modal-close');
        closeBtn.addEventListener('click', () => this.close());

        // Close on overlay click
        if (this.closeOnOverlay) {
            const overlay = this.modal.querySelector('.modal-overlay');
            overlay.addEventListener('click', () => this.close());
        }

        // Close on ESC key
        if (this.closeOnEscape) {
            this.escapeHandler = (e) => {
                if (e.key === 'Escape' && this.modal.classList.contains('active')) {
                    this.close();
                }
            };
            document.addEventListener('keydown', this.escapeHandler);
        }
    }

    open() {
        this.modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        if (this.onOpen) {
            this.onOpen(this);
        }

        return this;
    }

    close() {
        this.modal.classList.remove('active');
        document.body.style.overflow = '';

        if (this.onClose) {
            this.onClose(this);
        }

        return this;
    }

    destroy() {
        if (this.escapeHandler) {
            document.removeEventListener('keydown', this.escapeHandler);
        }
        this.modal.remove();

        // Remove from global state
        const index = TKMALL.state.modals.indexOf(this);
        if (index > -1) {
            TKMALL.state.modals.splice(index, 1);
        }
    }

    setContent(content) {
        const body = this.modal.querySelector('.modal-body');
        body.innerHTML = content;
        return this;
    }

    setTitle(title) {
        const titleEl = this.modal.querySelector('.modal-title');
        titleEl.textContent = title;
        return this;
    }
}

/**
 * Show confirmation modal
 */
function confirmModal(message, options = {}) {
    return new Promise((resolve) => {
        const modal = new Modal({
            title: options.title || 'Xác nhận',
            content: `
                <p style="margin-bottom: 20px;">${escapeHtml(message)}</p>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button class="btn btn-secondary" data-action="cancel">
                        ${options.cancelText || 'Hủy'}
                    </button>
                    <button class="btn btn-primary" data-action="confirm">
                        ${options.confirmText || 'Xác nhận'}
                    </button>
                </div>
            `,
            size: 'small',
            closeOnOverlay: false,
            onClose: () => modal.destroy()
        });

        modal.open();

        // Handle button clicks
        modal.modal.addEventListener('click', (e) => {
            if (e.target.dataset.action === 'confirm') {
                resolve(true);
                modal.close();
            } else if (e.target.dataset.action === 'cancel') {
                resolve(false);
                modal.close();
            }
        });
    });
}

// ============================================
// IMAGE GALLERY COMPONENT
// ============================================

/**
 * Initialize product image gallery
 */
function initImageGallery() {
    const mainImage = document.getElementById('mainImage');
    const thumbnails = document.querySelectorAll('.thumbnail-item');
    const imageModal = document.getElementById('imageModal');

    // Thumbnail click
    thumbnails.forEach(thumbnail => {
        thumbnail.addEventListener('click', () => {
            const imageSrc = thumbnail.dataset.image || thumbnail.src;
            changeMainImage(imageSrc, thumbnail);
        });
    });

    // Main image click to open modal
    if (mainImage) {
        mainImage.addEventListener('click', () => {
            openImageModal(mainImage.src);
        });
    }

    // Close modal handlers
    if (imageModal) {
        const closeBtn = imageModal.querySelector('.modal-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeImageModal);
        }

        imageModal.addEventListener('click', (e) => {
            if (e.target === imageModal) {
                closeImageModal();
            }
        });
    }
}

/**
 * Change main gallery image
 */
function changeMainImage(imageSrc, thumbnail) {
    const mainImage = document.getElementById('mainImage');
    if (mainImage) {
        mainImage.src = imageSrc;
    }

    // Update active thumbnail
    document.querySelectorAll('.thumbnail-item').forEach(item => {
        item.classList.remove('active');
    });
    if (thumbnail) {
        thumbnail.classList.add('active');
    }
}

/**
 * Open image modal
 */
function openImageModal(imageSrc) {
    const imageModal = document.getElementById('imageModal');
    if (!imageModal) return;

    const modalImage = document.getElementById('modalImage');
    if (modalImage) {
        modalImage.src = imageSrc;
    }

    imageModal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

/**
 * Close image modal
 */
function closeImageModal() {
    const imageModal = document.getElementById('imageModal');
    if (!imageModal) return;

    imageModal.classList.remove('active');
    document.body.style.overflow = '';
}

// ============================================
// PRODUCT VARIATIONS
// ============================================

/**
 * Initialize product variations
 */
function initProductVariations() {
    const variationButtons = document.querySelectorAll('.variation-option');

    variationButtons.forEach(button => {
        if (!button.classList.contains('disabled')) {
            button.addEventListener('click', function() {
                const attributeId = this.dataset.attributeId;
                selectVariation(this, attributeId);
            });
        }
    });

    // Color variations
    const colorButtons = document.querySelectorAll('.color-option');
    colorButtons.forEach(button => {
        button.addEventListener('click', function() {
            selectColor(this);
        });
    });
}

/**
 * Select product variation
 */
function selectVariation(button, attributeId) {
    // Remove active from siblings
    button.parentElement.querySelectorAll('.variation-option').forEach(opt => {
        opt.classList.remove('active');
    });

    // Add active to selected
    button.classList.add('active');

    // Store selection
    if (!window.selectedVariations) {
        window.selectedVariations = {};
    }
    window.selectedVariations[attributeId] = button.textContent.trim();

    // Update price if needed
    updatePriceByVariation();
}

/**
 * Select color variation
 */
function selectColor(button) {
    // Remove active from siblings
    button.parentElement.querySelectorAll('.color-option').forEach(opt => {
        opt.classList.remove('active');
    });

    // Add active to selected
    button.classList.add('active');

    // Store selection
    if (!window.selectedVariations) {
        window.selectedVariations = {};
    }
    window.selectedVariations['color'] = button.getAttribute('title') || button.dataset.color;
}

/**
 * Update price based on variations
 */
function updatePriceByVariation() {
    // This would typically make an AJAX call
    // For now, just log the selections
    console.log('Selected variations:', window.selectedVariations);
}

// ============================================
// QUANTITY CONTROLS
// ============================================

/**
 * Initialize quantity controls
 */
function initQuantityControls() {
    const quantityInput = document.getElementById('quantity');
    const decreaseBtn = document.querySelector('.quantity-btn[onclick*="decrease"]');
    const increaseBtn = document.querySelector('.quantity-btn[onclick*="increase"]');

    if (decreaseBtn) {
        decreaseBtn.addEventListener('click', decreaseQuantity);
    }

    if (increaseBtn) {
        increaseBtn.addEventListener('click', increaseQuantity);
    }

    if (quantityInput) {
        quantityInput.addEventListener('change', validateQuantity);
        quantityInput.addEventListener('input', validateQuantity);
    }
}

/**
 * Decrease quantity
 */
function decreaseQuantity() {
    const quantityInput = document.getElementById('quantity');
    if (!quantityInput) return;

    const currentValue = parseInt(quantityInput.value) || 1;
    if (currentValue > 1) {
        quantityInput.value = currentValue - 1;
        quantityInput.dispatchEvent(new Event('change'));
    }
}

/**
 * Increase quantity
 */
function increaseQuantity() {
    const quantityInput = document.getElementById('quantity');
    if (!quantityInput) return;

    const currentValue = parseInt(quantityInput.value) || 1;
    const maxStock = parseInt(quantityInput.getAttribute('max')) || 999999;

    if (currentValue < maxStock) {
        quantityInput.value = currentValue + 1;
        quantityInput.dispatchEvent(new Event('change'));
    } else {
        showNotification('Số lượng đã đạt tối đa!', 'warning');
    }
}

/**
 * Validate quantity input
 */
function validateQuantity() {
    const quantityInput = document.getElementById('quantity');
    if (!quantityInput) return;

    let value = parseInt(quantityInput.value);
    const min = parseInt(quantityInput.getAttribute('min')) || 1;
    const max = parseInt(quantityInput.getAttribute('max')) || 999999;

    if (isNaN(value) || value < min) {
        value = min;
    } else if (value > max) {
        value = max;
        showNotification('Số lượng đã đạt tối đa!', 'warning');
    }

    quantityInput.value = value;
}

// ============================================
// PAGINATION
// ============================================

/**
 * Initialize pagination
 */
function initPagination() {
    const paginationBtns = document.querySelectorAll('.pagination-btn');

    paginationBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (this.disabled || this.classList.contains('active')) {
                e.preventDefault();
                return;
            }

            // Add loading effect
            showLoading('Đang tải...');
        });
    });
}

// ============================================
// TABS COMPONENT
// ============================================

/**
 * Initialize tabs
 */
function initTabs() {
    const tabButtons = document.querySelectorAll('.tab-btn');

    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const tabId = this.dataset.tab;

            // Remove active from all tabs and buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });

            // Add active to clicked tab and content
            this.classList.add('active');
            const tabContent = document.getElementById(tabId);
            if (tabContent) {
                tabContent.classList.add('active');
            }
        });
    });
}

// ============================================
// SCROLL TO TOP
// ============================================

/**
 * Initialize scroll to top button
 */
function initScrollToTop() {
    let scrollToTopBtn = document.getElementById('scroll-to-top');

    if (!scrollToTopBtn) {
        scrollToTopBtn = document.createElement('button');
        scrollToTopBtn.id = 'scroll-to-top';
        scrollToTopBtn.className = 'scroll-to-top';
        scrollToTopBtn.innerHTML = '↑';
        scrollToTopBtn.setAttribute('aria-label', 'Scroll to top');
        document.body.appendChild(scrollToTopBtn);
    }

    // Show/hide based on scroll position
    window.addEventListener('scroll', throttle(() => {
        if (window.pageYOffset > 300) {
            scrollToTopBtn.classList.add('show');
        } else {
            scrollToTopBtn.classList.remove('show');
        }
    }, 200));

    // Scroll to top on click
    scrollToTopBtn.addEventListener('click', () => {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
}

// ============================================
// FOOTER INTERACTIONS
// ============================================

/**
 * Initialize footer
 */
function initFooter() {
    // Smooth hover effect for footer links
    document.querySelectorAll('.footer-links a').forEach(link => {
        link.addEventListener('mouseenter', function() {
            this.style.paddingLeft = '10px';
            this.style.transition = 'padding-left 0.3s ease';
        });

        link.addEventListener('mouseleave', function() {
            this.style.paddingLeft = '0';
        });
    });

    // Newsletter form
    const newsletterForm = document.querySelector('.newsletter-form');
    if (newsletterForm) {
        newsletterForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const email = this.querySelector('input[type="email"]').value;

            if (email && isValidEmail(email)) {
                try {
                    showLoading('Đang đăng ký...');
                    // Make API call here
                    await new Promise(resolve => setTimeout(resolve, 1000)); // Simulated
                    hideLoading();
                    showNotification('Đã đăng ký nhận tin thành công!', 'success');
                    this.reset();
                } catch (error) {
                    hideLoading();
                    showNotification('Có lỗi xảy ra, vui lòng thử lại!', 'error');
                }
            } else {
                showNotification('Email không hợp lệ!', 'error');
            }
        });
    }
}

// ============================================
// LAZY LOADING IMAGES
// ============================================

/**
 * Initialize lazy loading for images
 */
function initLazyLoading() {
    const images = document.querySelectorAll('img[data-src]');

    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
                observer.unobserve(img);
            }
        });
    }, {
        rootMargin: '50px'
    });

    images.forEach(img => imageObserver.observe(img));
}

// ============================================
// INITIALIZATION
// ============================================

/**
 * Initialize all components
 */
function initComponents() {
    console.log('TK-MALL Components initialized');

    initHeader();
    initNavigation();
    initScrollToTop();
    initFooter();
    initLazyLoading();
    initPagination();
    initTabs();

    // Page-specific components (check if elements exist)
    if (document.getElementById('mainImage')) {
        initImageGallery();
    }

    if (document.querySelector('.variation-option')) {
        initProductVariations();
    }

    if (document.getElementById('quantity')) {
        initQuantityControls();
    }
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initComponents);
} else {
    initComponents();
}

// Export to window
window.Modal = Modal;
window.confirmModal = confirmModal;
window.changeMainImage = changeMainImage;
window.openImageModal = openImageModal;
window.closeImageModal = closeImageModal;
window.selectVariation = selectVariation;
window.selectColor = selectColor;
window.decreaseQuantity = decreaseQuantity;
window.increaseQuantity = increaseQuantity;
