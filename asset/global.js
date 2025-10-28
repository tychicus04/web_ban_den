/**
 * ============================================
 * TK-MALL Global JavaScript
 * ============================================
 * Common utilities and functions used across the entire application
 * Load this file FIRST before other JS files
 *
 * Version: 1.0.0
 * Last Updated: 2025-10-28
 */

// ============================================
// GLOBAL CONFIGURATION
// ============================================

const TKMALL = {
    // Configuration
    config: {
        apiEndpoint: window.location.origin,
        notificationDuration: 3000,
        requestTimeout: 30000,
        debounceDelay: 300,
        animationDuration: 300
    },

    // State management
    state: {
        isLoading: false,
        notifications: [],
        modals: []
    },

    // Cache for optimizations
    cache: {},

    // Version
    version: '1.0.0'
};

// ============================================
// UTILITY FUNCTIONS
// ============================================

/**
 * Debounce function to limit how often a function can be called
 */
function debounce(func, delay = 300) {
    let timeoutId;
    return function (...args) {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => func.apply(this, args), delay);
    };
}

/**
 * Throttle function to ensure a function is called at most once per delay
 */
function throttle(func, delay = 300) {
    let lastCall = 0;
    return function (...args) {
        const now = new Date().getTime();
        if (now - lastCall < delay) return;
        lastCall = now;
        return func.apply(this, args);
    };
}

/**
 * Format currency in Vietnamese Dong
 */
function formatCurrency(amount) {
    return new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND',
        maximumFractionDigits: 0
    }).format(amount);
}

/**
 * Format number with thousand separators
 */
function formatNumber(number) {
    return new Intl.NumberFormat('vi-VN').format(number);
}

/**
 * Format date in Vietnamese format
 */
function formatDate(date, options = {}) {
    const defaultOptions = {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    };
    return new Date(date).toLocaleDateString('vi-VN', { ...defaultOptions, ...options });
}

/**
 * Format datetime in Vietnamese format
 */
function formatDateTime(datetime) {
    return new Date(datetime).toLocaleString('vi-VN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Sanitize HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Get query parameter from URL
 */
function getQueryParam(param) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(param);
}

/**
 * Set query parameter in URL without reloading
 */
function setQueryParam(param, value) {
    const url = new URL(window.location);
    url.searchParams.set(param, value);
    window.history.pushState({}, '', url);
}

/**
 * Generate unique ID
 */
function generateId(prefix = 'id') {
    return `${prefix}_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
}

/**
 * Check if element is in viewport
 */
function isInViewport(element) {
    const rect = element.getBoundingClientRect();
    return (
        rect.top >= 0 &&
        rect.left >= 0 &&
        rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
        rect.right <= (window.innerWidth || document.documentElement.clientWidth)
    );
}

/**
 * Smooth scroll to element
 */
function scrollToElement(element, offset = 0) {
    const targetElement = typeof element === 'string' ? document.querySelector(element) : element;
    if (!targetElement) return;

    const targetPosition = targetElement.getBoundingClientRect().top + window.pageYOffset - offset;
    window.scrollTo({
        top: targetPosition,
        behavior: 'smooth'
    });
}

// ============================================
// NOTIFICATION SYSTEM
// ============================================

/**
 * Show notification message
 */
function showNotification(message, type = 'info', duration = 3000) {
    // Remove existing notifications with same message
    document.querySelectorAll('.notification').forEach(notification => {
        if (notification.textContent.includes(message)) {
            notification.remove();
        }
    });

    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.setAttribute('role', 'alert');
    notification.setAttribute('aria-live', 'polite');

    // Add icon based on type
    const icons = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ'
    };
    const icon = icons[type] || icons.info;

    notification.innerHTML = `
        <span class="notification-icon">${icon}</span>
        <span class="notification-message">${escapeHtml(message)}</span>
        <button class="notification-close" aria-label="Close">×</button>
    `;

    // Add to page
    document.body.appendChild(notification);

    // Trigger animation
    setTimeout(() => notification.classList.add('show'), 10);

    // Close button handler
    const closeBtn = notification.querySelector('.notification-close');
    closeBtn.addEventListener('click', () => removeNotification(notification));

    // Auto remove after duration
    setTimeout(() => removeNotification(notification), duration);

    // Track in state
    TKMALL.state.notifications.push(notification);

    return notification;
}

/**
 * Remove notification
 */
function removeNotification(notification) {
    notification.classList.remove('show');
    setTimeout(() => {
        notification.remove();
        // Remove from state
        const index = TKMALL.state.notifications.indexOf(notification);
        if (index > -1) {
            TKMALL.state.notifications.splice(index, 1);
        }
    }, TKMALL.config.animationDuration);
}

/**
 * Clear all notifications
 */
function clearAllNotifications() {
    document.querySelectorAll('.notification').forEach(notification => {
        removeNotification(notification);
    });
}

// ============================================
// AJAX HELPER FUNCTIONS
// ============================================

/**
 * Make AJAX request with fetch API
 */
async function ajaxRequest(url, options = {}) {
    const defaultOptions = {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        timeout: TKMALL.config.requestTimeout
    };

    const config = { ...defaultOptions, ...options };

    // Add timeout
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), config.timeout);

    try {
        const response = await fetch(url, {
            ...config,
            signal: controller.signal
        });

        clearTimeout(timeoutId);

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return await response.json();
        }

        return await response.text();
    } catch (error) {
        if (error.name === 'AbortError') {
            throw new Error('Request timeout');
        }
        throw error;
    }
}

/**
 * POST request helper
 */
async function postRequest(url, data) {
    return ajaxRequest(url, {
        method: 'POST',
        body: JSON.stringify(data)
    });
}

/**
 * GET request helper
 */
async function getRequest(url) {
    return ajaxRequest(url, { method: 'GET' });
}

/**
 * Form data AJAX request
 */
async function postFormData(url, formData) {
    return ajaxRequest(url, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    });
}

// ============================================
// CART FUNCTIONS
// ============================================

/**
 * Update cart count in header
 */
async function updateCartCount() {
    try {
        const data = await getRequest('get-cart-count.php');
        const cartBadge = document.querySelector('.cart-badge');
        const cartLink = document.querySelector('.cart-link');

        if (data.count > 0) {
            if (cartBadge) {
                cartBadge.textContent = data.count;
            } else if (cartLink) {
                const badge = document.createElement('span');
                badge.className = 'cart-badge';
                badge.textContent = data.count;
                cartLink.appendChild(badge);
            }
        } else if (cartBadge) {
            cartBadge.remove();
        }

        return data.count;
    } catch (error) {
        console.error('Error updating cart count:', error);
        return 0;
    }
}

/**
 * Add item to cart
 */
async function addToCart(productId, quantity = 1, variations = {}) {
    try {
        const response = await postRequest('add-to-cart.php', {
            product_id: productId,
            quantity: quantity,
            variations: variations
        });

        if (response.success) {
            showNotification('Đã thêm sản phẩm vào giỏ hàng!', 'success');
            await updateCartCount();
            return true;
        } else {
            showNotification(response.message || 'Không thể thêm vào giỏ hàng!', 'error');
            return false;
        }
    } catch (error) {
        console.error('Error adding to cart:', error);
        showNotification('Có lỗi xảy ra, vui lòng thử lại!', 'error');
        return false;
    }
}

// ============================================
// FORM HELPERS
// ============================================

/**
 * Validate email format
 */
function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

/**
 * Validate phone number (Vietnamese format)
 */
function isValidPhone(phone) {
    const re = /^(0|\+84)[3|5|7|8|9][0-9]{8}$/;
    return re.test(phone);
}

/**
 * Validate form
 */
function validateForm(form) {
    let isValid = true;
    const errors = [];

    // Get all required inputs
    const requiredFields = form.querySelectorAll('[required]');

    requiredFields.forEach(field => {
        const value = field.value.trim();
        const fieldName = field.getAttribute('name') || field.getAttribute('id');

        // Remove previous error
        field.classList.remove('is-invalid');
        const existingError = field.parentElement.querySelector('.form-error');
        if (existingError) existingError.remove();

        // Validate
        if (!value) {
            isValid = false;
            errors.push(`${fieldName} không được để trống`);
            addFieldError(field, 'Trường này không được để trống');
        } else if (field.type === 'email' && !isValidEmail(value)) {
            isValid = false;
            errors.push(`${fieldName} không đúng định dạng`);
            addFieldError(field, 'Email không đúng định dạng');
        } else if (field.type === 'tel' && !isValidPhone(value)) {
            isValid = false;
            errors.push(`${fieldName} không đúng định dạng`);
            addFieldError(field, 'Số điện thoại không đúng định dạng');
        }
    });

    return { isValid, errors };
}

/**
 * Add error to form field
 */
function addFieldError(field, message) {
    field.classList.add('is-invalid');
    const error = document.createElement('div');
    error.className = 'form-error';
    error.textContent = message;
    field.parentElement.appendChild(error);
}

/**
 * Clear form errors
 */
function clearFormErrors(form) {
    form.querySelectorAll('.is-invalid').forEach(field => {
        field.classList.remove('is-invalid');
    });
    form.querySelectorAll('.form-error').forEach(error => {
        error.remove();
    });
}

/**
 * Serialize form to object
 */
function serializeForm(form) {
    const formData = new FormData(form);
    const data = {};
    for (const [key, value] of formData.entries()) {
        data[key] = value;
    }
    return data;
}

// ============================================
// LOADING STATE
// ============================================

/**
 * Show loading overlay
 */
function showLoading(message = 'Đang tải...') {
    TKMALL.state.isLoading = true;

    let loadingOverlay = document.getElementById('loading-overlay');
    if (!loadingOverlay) {
        loadingOverlay = document.createElement('div');
        loadingOverlay.id = 'loading-overlay';
        loadingOverlay.className = 'loading-overlay';
        loadingOverlay.innerHTML = `
            <div class="loading-spinner"></div>
            <div class="loading-message">${escapeHtml(message)}</div>
        `;
        document.body.appendChild(loadingOverlay);
    }

    loadingOverlay.classList.add('active');
    document.body.style.overflow = 'hidden';
}

/**
 * Hide loading overlay
 */
function hideLoading() {
    TKMALL.state.isLoading = false;

    const loadingOverlay = document.getElementById('loading-overlay');
    if (loadingOverlay) {
        loadingOverlay.classList.remove('active');
        document.body.style.overflow = '';
        setTimeout(() => loadingOverlay.remove(), TKMALL.config.animationDuration);
    }
}

// ============================================
// LOCAL STORAGE HELPERS
// ============================================

/**
 * Set local storage with expiry
 */
function setLocalStorage(key, value, expiryHours = 24) {
    const item = {
        value: value,
        expiry: Date.now() + (expiryHours * 60 * 60 * 1000)
    };
    localStorage.setItem(key, JSON.stringify(item));
}

/**
 * Get local storage with expiry check
 */
function getLocalStorage(key) {
    const itemStr = localStorage.getItem(key);
    if (!itemStr) return null;

    try {
        const item = JSON.parse(itemStr);
        if (Date.now() > item.expiry) {
            localStorage.removeItem(key);
            return null;
        }
        return item.value;
    } catch (e) {
        return null;
    }
}

/**
 * Remove from local storage
 */
function removeLocalStorage(key) {
    localStorage.removeItem(key);
}

/**
 * Clear expired items from local storage
 */
function clearExpiredLocalStorage() {
    Object.keys(localStorage).forEach(key => {
        getLocalStorage(key); // This will auto-remove expired items
    });
}

// ============================================
// INITIALIZATION
// ============================================

/**
 * Initialize global functionality
 */
function initGlobal() {
    console.log(`TK-MALL Global JS v${TKMALL.version} initialized`);

    // Clear expired local storage on load
    clearExpiredLocalStorage();

    // Add notification styles if not exist
    if (!document.getElementById('notification-styles')) {
        const style = document.createElement('style');
        style.id = 'notification-styles';
        style.textContent = `
            .notification {
                position: fixed;
                top: 20px;
                right: 20px;
                background: white;
                padding: 16px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                display: flex;
                align-items: center;
                gap: 12px;
                min-width: 300px;
                max-width: 500px;
                z-index: 10000;
                transform: translateX(calc(100% + 40px));
                opacity: 0;
                transition: all 0.3s ease;
            }

            .notification.show {
                transform: translateX(0);
                opacity: 1;
            }

            .notification-icon {
                font-size: 20px;
                font-weight: bold;
                flex-shrink: 0;
            }

            .notification-message {
                flex: 1;
                font-size: 14px;
                line-height: 1.4;
            }

            .notification-close {
                background: none;
                border: none;
                font-size: 24px;
                line-height: 1;
                cursor: pointer;
                padding: 0;
                color: #999;
                flex-shrink: 0;
            }

            .notification-close:hover {
                color: #333;
            }

            .notification-success {
                border-left: 4px solid #28a745;
            }

            .notification-success .notification-icon {
                color: #28a745;
            }

            .notification-error {
                border-left: 4px solid #dc3545;
            }

            .notification-error .notification-icon {
                color: #dc3545;
            }

            .notification-warning {
                border-left: 4px solid #ffc107;
            }

            .notification-warning .notification-icon {
                color: #ffc107;
            }

            .notification-info {
                border-left: 4px solid #17a2b8;
            }

            .notification-info .notification-icon {
                color: #17a2b8;
            }

            .loading-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                backdrop-filter: blur(2px);
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 20px;
                z-index: 99999;
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s ease;
            }

            .loading-overlay.active {
                opacity: 1;
                visibility: visible;
            }

            .loading-spinner {
                width: 50px;
                height: 50px;
                border: 4px solid rgba(255, 255, 255, 0.3);
                border-top-color: white;
                border-radius: 50%;
                animation: spin 0.8s linear infinite;
            }

            @keyframes spin {
                to { transform: rotate(360deg); }
            }

            .loading-message {
                color: white;
                font-size: 16px;
                font-weight: 500;
            }

            @media (max-width: 768px) {
                .notification {
                    right: 10px;
                    left: 10px;
                    min-width: auto;
                    max-width: none;
                }
            }
        `;
        document.head.appendChild(style);
    }

    // Add global event listeners
    setupGlobalEventListeners();
}

/**
 * Setup global event listeners
 */
function setupGlobalEventListeners() {
    // Close notifications on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            clearAllNotifications();
        }
    });

    // Handle AJAX errors globally
    window.addEventListener('unhandledrejection', (event) => {
        console.error('Unhandled promise rejection:', event.reason);
        // Optionally show error notification
        // showNotification('Có lỗi xảy ra, vui lòng thử lại!', 'error');
    });
}

// ============================================
// AUTO-INITIALIZATION
// ============================================

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initGlobal);
} else {
    initGlobal();
}

// Export to window for global access
window.TKMALL = TKMALL;
window.showNotification = showNotification;
window.updateCartCount = updateCartCount;
window.addToCart = addToCart;
window.formatCurrency = formatCurrency;
window.formatDate = formatDate;
window.formatDateTime = formatDateTime;
window.showLoading = showLoading;
window.hideLoading = hideLoading;
window.debounce = debounce;
window.throttle = throttle;
