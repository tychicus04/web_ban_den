/**
 * ============================================
 * TK-MALL Modal & Dialog JavaScript
 * ============================================
 * Handles modal dialogs, confirmations, and popups
 * Dependencies: global.js
 *
 * Version: 1.0.0
 * Last Updated: 2025-10-28
 */

// ============================================
// MODAL CLASS
// ============================================

class Modal {
    constructor(options = {}) {
        this.options = {
            id: options.id || generateId('modal'),
            title: options.title || '',
            content: options.content || '',
            size: options.size || 'md', // sm, md, lg, xl, fullscreen
            type: options.type || 'default', // default, confirm, gallery, bottom-sheet, drawer
            closeOnBackdrop: options.closeOnBackdrop !== false,
            closeOnEscape: options.closeOnEscape !== false,
            showCloseButton: options.showCloseButton !== false,
            footer: options.footer || null,
            animation: options.animation || 'fade', // fade, slide-down, slide-up
            onOpen: options.onOpen || null,
            onClose: options.onClose || null,
            onConfirm: options.onConfirm || null,
            onCancel: options.onCancel || null
        };

        this.modal = null;
        this.backdrop = null;
        this.isOpen = false;

        this.init();
    }

    init() {
        this.createBackdrop();
        this.createModal();
        this.attachEvents();
    }

    createBackdrop() {
        this.backdrop = document.createElement('div');
        this.backdrop.className = 'modal-backdrop';
        this.backdrop.id = `${this.options.id}-backdrop`;
    }

    createModal() {
        this.modal = document.createElement('div');
        this.modal.className = `modal ${this.options.type ? 'modal-' + this.options.type : ''}`;
        this.modal.id = this.options.id;
        this.modal.setAttribute('role', 'dialog');
        this.modal.setAttribute('aria-modal', 'true');
        this.modal.setAttribute('aria-labelledby', `${this.options.id}-title`);

        const dialog = document.createElement('div');
        dialog.className = `modal-dialog modal-${this.options.size} ${this.options.animation}`;

        const header = this.createHeader();
        const body = this.createBody();
        const footer = this.createFooter();

        dialog.appendChild(header);
        dialog.appendChild(body);
        if (footer) dialog.appendChild(footer);

        this.modal.appendChild(dialog);
    }

    createHeader() {
        const header = document.createElement('div');
        header.className = 'modal-header';

        if (this.options.title) {
            const title = document.createElement('h3');
            title.className = 'modal-title';
            title.id = `${this.options.id}-title`;
            title.textContent = this.options.title;
            header.appendChild(title);
        }

        if (this.options.showCloseButton) {
            const closeBtn = document.createElement('button');
            closeBtn.className = 'modal-close';
            closeBtn.innerHTML = '×';
            closeBtn.setAttribute('aria-label', 'Close');
            closeBtn.addEventListener('click', () => this.close());
            header.appendChild(closeBtn);
        }

        return header;
    }

    createBody() {
        const body = document.createElement('div');
        body.className = 'modal-body';

        if (typeof this.options.content === 'string') {
            body.innerHTML = this.options.content;
        } else if (this.options.content instanceof HTMLElement) {
            body.appendChild(this.options.content);
        }

        return body;
    }

    createFooter() {
        if (!this.options.footer) return null;

        const footer = document.createElement('div');
        footer.className = 'modal-footer';

        if (typeof this.options.footer === 'string') {
            footer.innerHTML = this.options.footer;
        } else if (Array.isArray(this.options.footer)) {
            this.options.footer.forEach(btn => {
                const button = document.createElement('button');
                button.className = btn.class || 'btn';
                button.textContent = btn.text;
                button.addEventListener('click', btn.onClick || (() => this.close()));
                footer.appendChild(button);
            });
        }

        return footer;
    }

    attachEvents() {
        // Close on backdrop click
        if (this.options.closeOnBackdrop) {
            this.modal.addEventListener('click', (e) => {
                if (e.target === this.modal) {
                    this.close();
                }
            });
        }

        // Close on escape key
        if (this.options.closeOnEscape) {
            this.escapeHandler = (e) => {
                if (e.key === 'Escape' && this.isOpen) {
                    this.close();
                }
            };
            document.addEventListener('keydown', this.escapeHandler);
        }
    }

    open() {
        document.body.appendChild(this.backdrop);
        document.body.appendChild(this.modal);

        // Trigger reflow for animation
        this.modal.offsetHeight;
        this.backdrop.offsetHeight;

        requestAnimationFrame(() => {
            this.backdrop.classList.add('show');
            this.modal.classList.add('show');
            document.body.classList.add('modal-open');
        });

        this.isOpen = true;

        // Focus trap
        this.modal.focus();

        if (this.options.onOpen) {
            this.options.onOpen(this);
        }

        return this;
    }

    close() {
        this.backdrop.classList.remove('show');
        this.modal.classList.remove('show');
        document.body.classList.remove('modal-open');

        setTimeout(() => {
            if (this.backdrop.parentNode) {
                this.backdrop.remove();
            }
            if (this.modal.parentNode) {
                this.modal.remove();
            }
        }, 300);

        this.isOpen = false;

        if (this.options.onClose) {
            this.options.onClose(this);
        }

        return this;
    }

    destroy() {
        if (this.escapeHandler) {
            document.removeEventListener('keydown', this.escapeHandler);
        }
        this.close();
    }

    setContent(content) {
        const body = this.modal.querySelector('.modal-body');
        if (body) {
            if (typeof content === 'string') {
                body.innerHTML = content;
            } else if (content instanceof HTMLElement) {
                body.innerHTML = '';
                body.appendChild(content);
            }
        }
        return this;
    }

    setTitle(title) {
        const titleElement = this.modal.querySelector('.modal-title');
        if (titleElement) {
            titleElement.textContent = title;
        }
        return this;
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Show a confirmation dialog
 */
function showConfirmModal(options = {}) {
    const defaults = {
        title: 'Xác nhận',
        message: 'Bạn có chắc chắn muốn thực hiện hành động này?',
        confirmText: 'Xác nhận',
        cancelText: 'Hủy',
        type: 'warning', // warning, danger, info, success
        onConfirm: null,
        onCancel: null
    };

    const config = { ...defaults, ...options };

    const iconMap = {
        warning: '⚠️',
        danger: '❌',
        info: 'ℹ️',
        success: '✅'
    };

    const content = `
        <div class="modal-confirm">
            <div class="modal-confirm-icon ${config.type}">${iconMap[config.type]}</div>
            <p class="modal-confirm-message">${escapeHtml(config.message)}</p>
            ${config.description ? `<p class="modal-confirm-description">${escapeHtml(config.description)}</p>` : ''}
        </div>
    `;

    const modal = new Modal({
        title: config.title,
        content: content,
        size: 'sm',
        type: 'confirm',
        footer: [
            {
                text: config.cancelText,
                class: 'btn btn-secondary',
                onClick: () => {
                    if (config.onCancel) config.onCancel();
                    modal.close();
                }
            },
            {
                text: config.confirmText,
                class: `btn btn-${config.type === 'danger' ? 'danger' : 'primary'}`,
                onClick: () => {
                    if (config.onConfirm) config.onConfirm();
                    modal.close();
                }
            }
        ]
    });

    modal.open();
    return modal;
}

/**
 * Show an alert dialog
 */
function showAlertModal(options = {}) {
    const defaults = {
        title: 'Thông báo',
        message: '',
        type: 'info',
        buttonText: 'Đóng'
    };

    const config = { ...defaults, ...options };

    const iconMap = {
        warning: '⚠️',
        danger: '❌',
        info: 'ℹ️',
        success: '✅'
    };

    const content = `
        <div class="modal-confirm">
            <div class="modal-confirm-icon ${config.type}">${iconMap[config.type]}</div>
            <p class="modal-confirm-message">${escapeHtml(config.message)}</p>
        </div>
    `;

    const modal = new Modal({
        title: config.title,
        content: content,
        size: 'sm',
        footer: [
            {
                text: config.buttonText,
                class: 'btn btn-primary',
                onClick: () => modal.close()
            }
        ]
    });

    modal.open();
    return modal;
}

/**
 * Show a loading modal
 */
function showLoadingModal(message = 'Đang xử lý...') {
    const content = `
        <div class="text-center py-xl">
            <div class="loading-spinner mx-auto mb-lg"></div>
            <p class="text-secondary">${escapeHtml(message)}</p>
        </div>
    `;

    const modal = new Modal({
        content: content,
        size: 'sm',
        showCloseButton: false,
        closeOnBackdrop: false,
        closeOnEscape: false
    });

    modal.open();
    return modal;
}

/**
 * Show image gallery modal
 */
function showImageModal(imageUrl, title = '') {
    const content = `
        <img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(title)}" class="modal-gallery-image">
    `;

    const modal = new Modal({
        title: title,
        content: content,
        size: 'xl',
        type: 'gallery'
    });

    modal.open();
    return modal;
}

/**
 * Initialize modal triggers from data attributes
 */
function initModalTriggers() {
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-modal-open]');
        if (!trigger) return;

        e.preventDefault();

        const targetId = trigger.getAttribute('data-modal-open');
        const targetModal = document.getElementById(targetId);

        if (targetModal) {
            // Extract options from data attributes
            const options = {
                id: targetId,
                title: targetModal.getAttribute('data-modal-title') || '',
                size: targetModal.getAttribute('data-modal-size') || 'md',
                type: targetModal.getAttribute('data-modal-type') || 'default',
                content: targetModal.innerHTML
            };

            const modal = new Modal(options);
            modal.open();

            // Store reference for closing
            targetModal._modalInstance = modal;
        }
    });

    // Close modal triggers
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-modal-close]');
        if (!trigger) return;

        const modalElement = trigger.closest('.modal');
        if (modalElement && modalElement._modalInstance) {
            modalElement._modalInstance.close();
        }
    });
}

// ============================================
// DELETE CONFIRMATION HANDLERS
// ============================================

/**
 * Setup delete confirmation handlers
 */
function setupDeleteHandlers() {
    document.addEventListener('click', (e) => {
        const deleteBtn = e.target.closest('[data-delete-confirm]');
        if (!deleteBtn) return;

        e.preventDefault();

        const itemName = deleteBtn.getAttribute('data-item-name') || 'mục này';
        const deleteUrl = deleteBtn.getAttribute('data-delete-url') || deleteBtn.getAttribute('href');
        const redirectUrl = deleteBtn.getAttribute('data-redirect-url');

        showConfirmModal({
            title: 'Xác nhận xóa',
            message: `Bạn có chắc chắn muốn xóa ${itemName}?`,
            description: 'Hành động này không thể hoàn tác.',
            type: 'danger',
            confirmText: 'Xóa',
            cancelText: 'Hủy',
            onConfirm: async () => {
                try {
                    showLoading('Đang xóa...');

                    const response = await fetch(deleteUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    hideLoading();

                    if (response.ok) {
                        showNotification('Xóa thành công!', 'success');

                        // Redirect or reload
                        if (redirectUrl) {
                            setTimeout(() => {
                                window.location.href = redirectUrl;
                            }, 1000);
                        } else {
                            setTimeout(() => {
                                window.location.reload();
                            }, 1000);
                        }
                    } else {
                        showNotification('Xóa thất bại!', 'error');
                    }
                } catch (error) {
                    hideLoading();
                    console.error('Delete error:', error);
                    showNotification('Có lỗi xảy ra!', 'error');
                }
            }
        });
    });
}

// ============================================
// INITIALIZATION
// ============================================

function initModals() {
    console.log('Modal system initialized');
    initModalTriggers();
    setupDeleteHandlers();
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initModals);
} else {
    initModals();
}

// Export to window
window.Modal = Modal;
window.showConfirmModal = showConfirmModal;
window.showAlertModal = showAlertModal;
window.showLoadingModal = showLoadingModal;
window.showImageModal = showImageModal;
