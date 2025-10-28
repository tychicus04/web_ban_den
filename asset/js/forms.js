/**
 * ============================================
 * TK-MALL Forms JavaScript
 * ============================================
 * Handles form validation, submission, and interactions
 * Dependencies: global.js
 *
 * Version: 1.0.0
 * Last Updated: 2025-10-28
 */

// ============================================
// FORM VALIDATION
// ============================================

/**
 * Enhanced form validation with custom rules
 */
class FormValidator {
    constructor(form, options = {}) {
        this.form = typeof form === 'string' ? document.querySelector(form) : form;
        this.options = {
            validateOnBlur: options.validateOnBlur !== false,
            validateOnInput: options.validateOnInput || false,
            showErrors: options.showErrors !== false,
            scrollToError: options.scrollToError !== false,
            customRules: options.customRules || {},
            onSubmit: options.onSubmit || null,
            onValidationFail: options.onValidationFail || null
        };

        this.errors = {};
        this.init();
    }

    init() {
        if (!this.form) return;

        this.form.setAttribute('novalidate', '');
        this.attachEvents();
    }

    attachEvents() {
        // Form submission
        this.form.addEventListener('submit', (e) => this.handleSubmit(e));

        // Validate on blur
        if (this.options.validateOnBlur) {
            this.form.querySelectorAll('input, select, textarea').forEach(field => {
                field.addEventListener('blur', () => this.validateField(field));
            });
        }

        // Validate on input
        if (this.options.validateOnInput) {
            this.form.querySelectorAll('input, select, textarea').forEach(field => {
                field.addEventListener('input', debounce(() => this.validateField(field), 500));
            });
        }
    }

    handleSubmit(e) {
        e.preventDefault();

        // Clear previous errors
        this.clearErrors();

        // Validate all fields
        const isValid = this.validateAll();

        if (isValid) {
            if (this.options.onSubmit) {
                this.options.onSubmit(this.form, this.getFormData());
            } else {
                this.form.submit();
            }
        } else {
            if (this.options.onValidationFail) {
                this.options.onValidationFail(this.errors);
            }

            if (this.options.scrollToError) {
                this.scrollToFirstError();
            }
        }
    }

    validateAll() {
        let isValid = true;
        const fields = this.form.querySelectorAll('input, select, textarea');

        fields.forEach(field => {
            if (!this.validateField(field)) {
                isValid = false;
            }
        });

        return isValid;
    }

    validateField(field) {
        const fieldName = field.getAttribute('name') || field.getAttribute('id');
        const value = field.value.trim();
        let error = null;

        // Required validation
        if (field.hasAttribute('required') && !value) {
            error = 'Trường này không được để trống';
        }

        // Type validations
        if (!error && value) {
            switch (field.type) {
                case 'email':
                    if (!isValidEmail(value)) {
                        error = 'Email không đúng định dạng';
                    }
                    break;
                case 'tel':
                    if (!isValidPhone(value)) {
                        error = 'Số điện thoại không đúng định dạng';
                    }
                    break;
                case 'url':
                    if (!this.isValidUrl(value)) {
                        error = 'URL không đúng định dạng';
                    }
                    break;
                case 'number':
                    const min = field.getAttribute('min');
                    const max = field.getAttribute('max');
                    const numValue = parseFloat(value);

                    if (isNaN(numValue)) {
                        error = 'Vui lòng nhập số';
                    } else if (min && numValue < parseFloat(min)) {
                        error = `Giá trị tối thiểu là ${min}`;
                    } else if (max && numValue > parseFloat(max)) {
                        error = `Giá trị tối đa là ${max}`;
                    }
                    break;
            }
        }

        // Length validations
        if (!error && value) {
            const minLength = field.getAttribute('minlength');
            const maxLength = field.getAttribute('maxlength');

            if (minLength && value.length < parseInt(minLength)) {
                error = `Tối thiểu ${minLength} ký tự`;
            } else if (maxLength && value.length > parseInt(maxLength)) {
                error = `Tối đa ${maxLength} ký tự`;
            }
        }

        // Pattern validation
        if (!error && value && field.hasAttribute('pattern')) {
            const pattern = new RegExp(field.getAttribute('pattern'));
            if (!pattern.test(value)) {
                error = field.getAttribute('data-pattern-error') || 'Định dạng không đúng';
            }
        }

        // Custom rules
        if (!error && value && this.options.customRules[fieldName]) {
            const customError = this.options.customRules[fieldName](value, field);
            if (customError) {
                error = customError;
            }
        }

        // Update field state
        if (error) {
            this.setFieldError(field, error);
            this.errors[fieldName] = error;
            return false;
        } else {
            this.clearFieldError(field);
            delete this.errors[fieldName];
            return true;
        }
    }

    setFieldError(field, message) {
        field.classList.add('is-invalid');
        field.classList.remove('is-valid');

        if (this.options.showErrors) {
            let errorElement = field.parentElement.querySelector('.form-error');

            if (!errorElement) {
                errorElement = document.createElement('div');
                errorElement.className = 'form-error';
                field.parentElement.appendChild(errorElement);
            }

            errorElement.textContent = message;
        }
    }

    clearFieldError(field) {
        field.classList.remove('is-invalid');
        field.classList.add('is-valid');

        const errorElement = field.parentElement.querySelector('.form-error');
        if (errorElement) {
            errorElement.remove();
        }
    }

    clearErrors() {
        this.errors = {};
        this.form.querySelectorAll('.is-invalid').forEach(field => {
            this.clearFieldError(field);
        });
    }

    scrollToFirstError() {
        const firstError = this.form.querySelector('.is-invalid');
        if (firstError) {
            scrollToElement(firstError, 100);
            firstError.focus();
        }
    }

    getFormData() {
        return serializeForm(this.form);
    }

    isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch {
            return false;
        }
    }
}

// ============================================
// AJAX FORM SUBMISSION
// ============================================

/**
 * Handle form submission via AJAX
 */
function setupAjaxForms() {
    document.querySelectorAll('form[data-ajax="true"]').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const submitBtn = form.querySelector('[type="submit"]');
            const originalText = submitBtn.textContent;

            try {
                // Disable submit button
                submitBtn.disabled = true;
                submitBtn.textContent = 'Đang xử lý...';

                // Get form data
                const formData = new FormData(form);
                const url = form.getAttribute('action') || window.location.href;
                const method = form.getAttribute('method') || 'POST';

                // Submit via fetch
                const response = await fetch(url, {
                    method: method,
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showNotification(result.message || 'Thành công!', 'success');

                    // Reset form if specified
                    if (form.hasAttribute('data-reset-on-success')) {
                        form.reset();
                    }

                    // Redirect if specified
                    if (result.redirect) {
                        setTimeout(() => {
                            window.location.href = result.redirect;
                        }, 1000);
                    }

                    // Callback
                    const callback = form.getAttribute('data-success-callback');
                    if (callback && typeof window[callback] === 'function') {
                        window[callback](result);
                    }
                } else {
                    showNotification(result.message || 'Có lỗi xảy ra!', 'error');

                    // Show field errors if present
                    if (result.errors) {
                        Object.keys(result.errors).forEach(fieldName => {
                            const field = form.querySelector(`[name="${fieldName}"]`);
                            if (field) {
                                addFieldError(field, result.errors[fieldName]);
                            }
                        });
                    }
                }
            } catch (error) {
                console.error('Form submission error:', error);
                showNotification('Có lỗi xảy ra, vui lòng thử lại!', 'error');
            } finally {
                // Re-enable submit button
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        });
    });
}

// ============================================
// FILE INPUT PREVIEW
// ============================================

/**
 * Setup file input previews
 */
function setupFileInputPreviews() {
    document.querySelectorAll('input[type="file"][data-preview]').forEach(input => {
        input.addEventListener('change', (e) => {
            const previewId = input.getAttribute('data-preview');
            const previewElement = document.getElementById(previewId);

            if (!previewElement) return;

            const file = e.target.files[0];

            if (file) {
                // Show filename for non-image files
                if (!file.type.startsWith('image/')) {
                    previewElement.textContent = file.name;
                    return;
                }

                // Show image preview
                const reader = new FileReader();
                reader.onload = (e) => {
                    if (previewElement.tagName === 'IMG') {
                        previewElement.src = e.target.result;
                        previewElement.style.display = 'block';
                    } else {
                        previewElement.innerHTML = `<img src="${e.target.result}" alt="Preview" style="max-width: 100%; max-height: 200px;">`;
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    });
}

// ============================================
// DYNAMIC FIELD MANAGEMENT
// ============================================

/**
 * Add/Remove dynamic fields
 */
function setupDynamicFields() {
    // Add field button
    document.addEventListener('click', (e) => {
        const addBtn = e.target.closest('[data-add-field]');
        if (!addBtn) return;

        e.preventDefault();

        const templateId = addBtn.getAttribute('data-add-field');
        const template = document.getElementById(templateId);
        const container = addBtn.closest('.dynamic-fields-container') ||
            document.querySelector(`[data-field-container="${templateId}"]`);

        if (template && container) {
            const clone = template.content.cloneNode(true);
            const fieldsWrapper = container.querySelector('.dynamic-fields') || container;
            fieldsWrapper.appendChild(clone);
        }
    });

    // Remove field button
    document.addEventListener('click', (e) => {
        const removeBtn = e.target.closest('[data-remove-field]');
        if (!removeBtn) return;

        e.preventDefault();

        const fieldItem = removeBtn.closest('[data-field-item]');
        if (fieldItem) {
            fieldItem.remove();
        }
    });
}

// ============================================
// FORM FIELD MASKS
// ============================================

/**
 * Setup input masks (phone, currency, etc.)
 */
function setupInputMasks() {
    // Phone number mask
    document.querySelectorAll('input[data-mask="phone"]').forEach(input => {
        input.addEventListener('input', (e) => {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 10) value = value.substring(0, 10);

            if (value.length > 6) {
                value = value.replace(/(\d{4})(\d{3})(\d{3})/, '$1 $2 $3');
            } else if (value.length > 3) {
                value = value.replace(/(\d{4})(\d+)/, '$1 $2');
            }

            e.target.value = value;
        });
    });

    // Currency mask
    document.querySelectorAll('input[data-mask="currency"]').forEach(input => {
        input.addEventListener('input', (e) => {
            let value = e.target.value.replace(/\D/g, '');
            if (value) {
                value = parseInt(value).toLocaleString('vi-VN');
            }
            e.target.value = value;
        });

        input.addEventListener('blur', (e) => {
            let value = e.target.value.replace(/\D/g, '');
            if (value) {
                e.target.value = formatCurrency(parseInt(value));
            }
        });
    });
}

// ============================================
// PASSWORD TOGGLE
// ============================================

/**
 * Setup password visibility toggle
 */
function setupPasswordToggles() {
    document.querySelectorAll('[data-toggle-password]').forEach(toggle => {
        toggle.addEventListener('click', (e) => {
            e.preventDefault();

            const targetId = toggle.getAttribute('data-toggle-password');
            const input = document.getElementById(targetId);

            if (input) {
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                toggle.textContent = isPassword ? '👁️' : '👁️‍🗨️';
            }
        });
    });
}

// ============================================
// CHARACTER COUNTER
// ============================================

/**
 * Setup character counters for textareas/inputs
 */
function setupCharacterCounters() {
    document.querySelectorAll('[data-character-count]').forEach(field => {
        const maxLength = field.getAttribute('maxlength') || field.getAttribute('data-max-length');
        if (!maxLength) return;

        // Create counter element
        const counter = document.createElement('div');
        counter.className = 'character-counter';
        counter.style.cssText = 'font-size: 12px; color: #666; text-align: right; margin-top: 4px;';
        field.parentElement.appendChild(counter);

        // Update counter
        const updateCounter = () => {
            const remaining = maxLength - field.value.length;
            counter.textContent = `${remaining} ký tự còn lại`;
            counter.style.color = remaining < 10 ? '#dc3545' : '#666';
        };

        field.addEventListener('input', updateCounter);
        updateCounter(); // Initial update
    });
}

// ============================================
// INITIALIZATION
// ============================================

function initForms() {
    console.log('Forms system initialized');

    setupAjaxForms();
    setupFileInputPreviews();
    setupDynamicFields();
    setupInputMasks();
    setupPasswordToggles();
    setupCharacterCounters();

    // Initialize validators for forms with data-validate attribute
    document.querySelectorAll('form[data-validate="true"]').forEach(form => {
        new FormValidator(form);
    });
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initForms);
} else {
    initForms();
}

// Export to window
window.FormValidator = FormValidator;
window.validateForm = validateForm; // Keep compatibility with global.js function
