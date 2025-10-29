/**
 * UI/UX Enhancements - Phase 5
 * Description: Enhanced form validation, notifications, and user interactions
 * Author: TK-MALL Development Team
 * Version: 1.0.0
 */

(function() {
    'use strict';

    // ============================================
    // NOTIFICATION SYSTEM
    // ============================================

    const NotificationManager = {
        container: null,

        init() {
            if (!this.container) {
                this.container = document.createElement('div');
                this.container.className = 'notification-container';
                document.body.appendChild(this.container);
            }
        },

        show(message, type = 'info', duration = 5000) {
            this.init();

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;

            const icons = {
                success: '✓',
                error: '✕',
                warning: '⚠',
                info: 'ⓘ'
            };

            const titles = {
                success: 'Success',
                error: 'Error',
                warning: 'Warning',
                info: 'Information'
            };

            notification.innerHTML = `
                <div class="notification-icon">${icons[type] || icons.info}</div>
                <div class="notification-content">
                    <div class="notification-title">${titles[type] || titles.info}</div>
                    <div class="notification-message">${message}</div>
                </div>
                <button class="notification-close" aria-label="Close">&times;</button>
            `;

            this.container.appendChild(notification);

            // Close button functionality
            const closeBtn = notification.querySelector('.notification-close');
            closeBtn.addEventListener('click', () => this.remove(notification));

            // Auto remove after duration
            if (duration > 0) {
                setTimeout(() => this.remove(notification), duration);
            }

            return notification;
        },

        remove(notification) {
            notification.classList.add('removing');
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        },

        success(message, duration) {
            return this.show(message, 'success', duration);
        },

        error(message, duration) {
            return this.show(message, 'error', duration);
        },

        warning(message, duration) {
            return this.show(message, 'warning', duration);
        },

        info(message, duration) {
            return this.show(message, 'info', duration);
        }
    };

    // Make it globally available
    window.showNotification = (message, type, duration) => NotificationManager.show(message, type, duration);
    window.notification = NotificationManager;

    // ============================================
    // FORM VALIDATION
    // ============================================

    const FormValidator = {
        rules: {
            required: (value) => value.trim() !== '',
            email: (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value),
            phone: (value) => /^[0-9+\-\s()]+$/.test(value) && value.replace(/\D/g, '').length >= 10,
            minLength: (value, length) => value.length >= length,
            maxLength: (value, length) => value.length <= length,
            number: (value) => !isNaN(value) && value.trim() !== '',
            url: (value) => {
                try {
                    new URL(value);
                    return true;
                } catch {
                    return false;
                }
            }
        },

        messages: {
            required: 'This field is required',
            email: 'Please enter a valid email address',
            phone: 'Please enter a valid phone number',
            minLength: 'Please enter at least {length} characters',
            maxLength: 'Please enter no more than {length} characters',
            number: 'Please enter a valid number',
            url: 'Please enter a valid URL'
        },

        validateField(field) {
            const value = field.value;
            const rules = field.dataset.validate ? field.dataset.validate.split('|') : [];

            for (const rule of rules) {
                const [ruleName, ruleValue] = rule.split(':');

                if (this.rules[ruleName]) {
                    const isValid = ruleValue
                        ? this.rules[ruleName](value, ruleValue)
                        : this.rules[ruleName](value);

                    if (!isValid) {
                        const message = this.messages[ruleName].replace('{length}', ruleValue);
                        this.markInvalid(field, message);
                        return false;
                    }
                }
            }

            this.markValid(field);
            return true;
        },

        markValid(field) {
            field.classList.remove('is-invalid');
            field.classList.add('is-valid');

            const feedback = field.parentElement.querySelector('.invalid-feedback');
            if (feedback) {
                feedback.remove();
            }

            // Add valid feedback if it doesn't exist
            if (!field.parentElement.querySelector('.valid-feedback')) {
                const validFeedback = document.createElement('div');
                validFeedback.className = 'form-feedback valid-feedback';
                validFeedback.textContent = 'Looks good!';
                field.parentElement.appendChild(validFeedback);
            }
        },

        markInvalid(field, message) {
            field.classList.remove('is-valid');
            field.classList.add('is-invalid');

            // Remove existing feedback
            const existingFeedback = field.parentElement.querySelector('.form-feedback');
            if (existingFeedback) {
                existingFeedback.remove();
            }

            // Add invalid feedback
            const feedback = document.createElement('div');
            feedback.className = 'form-feedback invalid-feedback';
            feedback.textContent = message;
            field.parentElement.appendChild(feedback);
        },

        clearValidation(field) {
            field.classList.remove('is-valid', 'is-invalid');
            const feedback = field.parentElement.querySelector('.form-feedback');
            if (feedback) {
                feedback.remove();
            }
        },

        validateForm(form) {
            const fields = form.querySelectorAll('[data-validate]');
            let isValid = true;

            fields.forEach(field => {
                if (!this.validateField(field)) {
                    isValid = false;
                }
            });

            return isValid;
        }
    };

    // Auto-validate on blur
    document.addEventListener('blur', (e) => {
        if (e.target.matches('[data-validate]')) {
            FormValidator.validateField(e.target);
        }
    }, true);

    // Clear validation on input
    document.addEventListener('input', (e) => {
        if (e.target.matches('[data-validate].is-invalid')) {
            FormValidator.clearValidation(e.target);
        }
    });

    // Form submit validation
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (form.hasAttribute('data-validate-form')) {
            if (!FormValidator.validateForm(form)) {
                e.preventDefault();
                NotificationManager.error('Please fix the errors in the form');
            }
        }
    });

    window.FormValidator = FormValidator;

    // ============================================
    // LOADING OVERLAY
    // ============================================

    const LoadingManager = {
        overlay: null,

        init() {
            if (!this.overlay) {
                this.overlay = document.createElement('div');
                this.overlay.className = 'loading-overlay';
                this.overlay.innerHTML = '<div class="loading-spinner"></div>';
                document.body.appendChild(this.overlay);
            }
        },

        show() {
            this.init();
            this.overlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        },

        hide() {
            if (this.overlay) {
                this.overlay.classList.remove('show');
                document.body.style.overflow = '';
            }
        }
    };

    window.loading = LoadingManager;

    // ============================================
    // BUTTON LOADING STATE
    // ============================================

    function setButtonLoading(button, loading = true) {
        if (loading) {
            button.classList.add('is-loading');
            button.disabled = true;
            button.dataset.originalText = button.textContent;
        } else {
            button.classList.remove('is-loading');
            button.disabled = false;
            if (button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
            }
        }
    }

    window.setButtonLoading = setButtonLoading;

    // ============================================
    // MODAL MANAGEMENT
    // ============================================

    const ModalManager = {
        activeModal: null,

        open(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;

            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
            this.activeModal = modal;

            // Focus first focusable element
            const firstFocusable = modal.querySelector('input, button, select, textarea');
            if (firstFocusable) {
                setTimeout(() => firstFocusable.focus(), 100);
            }
        },

        close(modalId) {
            const modal = modalId ? document.getElementById(modalId) : this.activeModal;
            if (!modal) return;

            modal.classList.remove('show');
            document.body.style.overflow = '';
            this.activeModal = null;
        }
    };

    // Modal event listeners
    document.addEventListener('click', (e) => {
        // Open modal
        if (e.target.matches('[data-modal-open]')) {
            const modalId = e.target.dataset.modalOpen;
            ModalManager.open(modalId);
        }

        // Close modal
        if (e.target.matches('[data-modal-close]') ||
            e.target.classList.contains('modal-backdrop')) {
            ModalManager.close();
        }
    });

    // Close modal on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && ModalManager.activeModal) {
            ModalManager.close();
        }
    });

    window.modal = ModalManager;

    // ============================================
    // CONFIRM DIALOGS
    // ============================================

    function confirmAction(message, callback) {
        const confirmed = confirm(message);
        if (confirmed && typeof callback === 'function') {
            callback();
        }
        return confirmed;
    }

    window.confirmAction = confirmAction;

    // Auto-confirm for delete buttons
    document.addEventListener('click', (e) => {
        if (e.target.matches('[data-confirm]')) {
            const message = e.target.dataset.confirm || 'Are you sure?';
            if (!confirm(message)) {
                e.preventDefault();
                e.stopPropagation();
            }
        }
    });

    // ============================================
    // COPY TO CLIPBOARD
    // ============================================

    async function copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            NotificationManager.success('Copied to clipboard!');
            return true;
        } catch (err) {
            console.error('Failed to copy:', err);
            NotificationManager.error('Failed to copy to clipboard');
            return false;
        }
    }

    window.copyToClipboard = copyToClipboard;

    // Auto-copy buttons
    document.addEventListener('click', (e) => {
        if (e.target.matches('[data-copy]')) {
            const text = e.target.dataset.copy;
            copyToClipboard(text);
        }
    });

    // ============================================
    // DEBOUNCE UTILITY
    // ============================================

    function debounce(func, wait = 300) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    window.debounce = debounce;

    // ============================================
    // AUTO-DISMISS ALERTS
    // ============================================

    document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
        const delay = parseInt(alert.dataset.autoDismiss) || 5000;
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, delay);
    });

    // ============================================
    // SMOOTH SCROLL TO TOP
    // ============================================

    function scrollToTop() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }

    window.scrollToTop = scrollToTop;

    // Show/hide scroll to top button
    const scrollTopButton = document.createElement('button');
    scrollTopButton.className = 'scroll-to-top';
    scrollTopButton.innerHTML = '↑';
    scrollTopButton.onclick = scrollToTop;
    scrollTopButton.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: var(--color-primary);
        color: white;
        border: none;
        font-size: 24px;
        cursor: pointer;
        box-shadow: var(--shadow-lg);
        display: none;
        z-index: var(--z-fixed);
        transition: all 0.3s ease;
    `;

    document.body.appendChild(scrollTopButton);

    window.addEventListener('scroll', debounce(() => {
        if (window.pageYOffset > 300) {
            scrollTopButton.style.display = 'block';
        } else {
            scrollTopButton.style.display = 'none';
        }
    }, 100));

    // ============================================
    // IMAGE LAZY LOADING
    // ============================================

    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                        imageObserver.unobserve(img);
                    }
                }
            });
        });

        document.querySelectorAll('img[data-src]').forEach(img => {
            imageObserver.observe(img);
        });
    }

    // ============================================
    // INITIALIZE ON DOM READY
    // ============================================

    console.log('UI Enhancements loaded successfully');

})();
