/**
 * ============================================
 * TK-MALL Admin JavaScript
 * ============================================
 * Admin panel specific functionality
 * Load this file AFTER global.js and components.js
 * Only load on admin pages
 *
 * Version: 1.0.0
 * Last Updated: 2025-10-28
 */

// ============================================
// SIDEBAR FUNCTIONALITY
// ============================================

/**
 * Initialize admin sidebar
 */
function initAdminSidebar() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');

    if (!sidebar || !sidebarToggle) return;

    // Toggle sidebar
    sidebarToggle.addEventListener('click', function(e) {
        e.stopPropagation();

        if (window.innerWidth > 1024) {
            // Desktop: collapse sidebar
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        } else {
            // Mobile: show/hide sidebar
            sidebar.classList.toggle('open');
        }
    });

    // Restore sidebar state on desktop
    if (window.innerWidth > 1024 && localStorage.getItem('sidebarCollapsed') === 'true') {
        sidebar.classList.add('collapsed');
    }

    // Close sidebar on mobile when clicking outside
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 1024 &&
            !sidebar.contains(e.target) &&
            !sidebarToggle.contains(e.target) &&
            sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
        }
    });

    // Handle window resize
    window.addEventListener('resize', debounce(() => {
        if (window.innerWidth > 1024) {
            sidebar.classList.remove('open');
        }
    }, 250));

    // Submenu toggle
    const menuItems = sidebar.querySelectorAll('.menu-item.has-submenu');
    menuItems.forEach(item => {
        const link = item.querySelector('.menu-link');
        link.addEventListener('click', function(e) {
            e.preventDefault();
            item.classList.toggle('open');
        });
    });
}

// ============================================
// DATA TABLES
// ============================================

/**
 * Initialize data tables with sorting, filtering, pagination
 */
class DataTable {
    constructor(tableElement, options = {}) {
        this.table = tableElement;
        this.options = {
            sortable: options.sortable !== false,
            searchable: options.searchable !== false,
            perPage: options.perPage || 10,
            ...options
        };

        this.currentPage = 1;
        this.sortColumn = null;
        this.sortDirection = 'asc';
        this.searchQuery = '';

        this.init();
    }

    init() {
        if (this.options.sortable) {
            this.initSorting();
        }
        if (this.options.searchable) {
            this.initSearch();
        }
        this.initActions();
    }

    initSorting() {
        const headers = this.table.querySelectorAll('th[data-sortable]');
        headers.forEach(header => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', () => {
                const column = header.dataset.column;
                this.sort(column);
            });
        });
    }

    sort(column) {
        // Toggle sort direction
        if (this.sortColumn === column) {
            this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            this.sortColumn = column;
            this.sortDirection = 'asc';
        }

        const tbody = this.table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));

        rows.sort((a, b) => {
            const aValue = a.querySelector(`td[data-column="${column}"]`)?.textContent || '';
            const bValue = b.querySelector(`td[data-column="${column}"]`)?.textContent || '';

            if (this.sortDirection === 'asc') {
                return aValue.localeCompare(bValue, 'vi');
            } else {
                return bValue.localeCompare(aValue, 'vi');
            }
        });

        // Re-append sorted rows
        rows.forEach(row => tbody.appendChild(row));

        // Update header indicators
        this.updateSortIndicators(column);
    }

    updateSortIndicators(column) {
        this.table.querySelectorAll('th[data-sortable]').forEach(th => {
            th.classList.remove('sort-asc', 'sort-desc');
        });

        const header = this.table.querySelector(`th[data-column="${column}"]`);
        if (header) {
            header.classList.add(`sort-${this.sortDirection}`);
        }
    }

    initSearch() {
        const searchInput = document.querySelector('[data-table-search]');
        if (!searchInput) return;

        const debouncedSearch = debounce((query) => {
            this.search(query);
        }, 300);

        searchInput.addEventListener('input', (e) => {
            debouncedSearch(e.target.value);
        });
    }

    search(query) {
        this.searchQuery = query.toLowerCase();
        const rows = this.table.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            if (text.includes(this.searchQuery)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });

        this.updateResultsCount();
    }

    updateResultsCount() {
        const visibleRows = this.table.querySelectorAll('tbody tr:not([style*="display: none"])').length;
        const totalRows = this.table.querySelectorAll('tbody tr').length;

        const countElement = document.querySelector('[data-table-count]');
        if (countElement) {
            countElement.textContent = `Hiển thị ${visibleRows} / ${totalRows} kết quả`;
        }
    }

    initActions() {
        // View buttons
        this.table.querySelectorAll('.table-action-btn.view').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = btn.dataset.id || btn.closest('tr').dataset.id;
                this.handleView(id);
            });
        });

        // Edit buttons
        this.table.querySelectorAll('.table-action-btn.edit').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = btn.dataset.id || btn.closest('tr').dataset.id;
                this.handleEdit(id);
            });
        });

        // Delete buttons
        this.table.querySelectorAll('.table-action-btn.delete').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const id = btn.dataset.id || btn.closest('tr').dataset.id;
                await this.handleDelete(id, btn);
            });
        });

        // Checkbox select all
        const selectAllCheckbox = this.table.querySelector('th input[type="checkbox"]');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (e) => {
                const checkboxes = this.table.querySelectorAll('tbody input[type="checkbox"]');
                checkboxes.forEach(cb => cb.checked = e.target.checked);
            });
        }
    }

    handleView(id) {
        // Override this method or use events
        console.log('View item:', id);
    }

    handleEdit(id) {
        // Override this method or use events
        console.log('Edit item:', id);
    }

    async handleDelete(id, button) {
        const confirmed = await confirmModal(
            'Bạn có chắc chắn muốn xóa mục này?',
            {
                title: 'Xác nhận xóa',
                confirmText: 'Xóa',
                cancelText: 'Hủy'
            }
        );

        if (!confirmed) return;

        const row = button.closest('tr');
        const deleteUrl = button.dataset.deleteUrl || `delete.php?id=${id}`;

        try {
            showLoading('Đang xóa...');
            const response = await postRequest(deleteUrl, { id });

            if (response.success) {
                showNotification('Xóa thành công!', 'success');
                row.remove();
                this.updateResultsCount();
            } else {
                showNotification(response.message || 'Không thể xóa!', 'error');
            }
        } catch (error) {
            console.error('Delete error:', error);
            showNotification('Có lỗi xảy ra!', 'error');
        } finally {
            hideLoading();
        }
    }
}

// ============================================
// CHARTS (with Chart.js)
// ============================================

/**
 * Initialize sales chart
 */
function initSalesChart(elementId, data) {
    const canvas = document.getElementById(elementId);
    if (!canvas || typeof Chart === 'undefined') return;

    const ctx = canvas.getContext('2d');

    // Format labels
    const labels = data.map(item => {
        if (item.month) {
            const [year, month] = item.month.split('-');
            const date = new Date(year, month - 1);
            return date.toLocaleDateString('vi-VN', { month: 'short', year: 'numeric' });
        }
        return item.label || '';
    });

    const revenues = data.map(item => item.revenue || 0);
    const orderCounts = data.map(item => item.order_count || item.count || 0);

    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Doanh thu',
                    data: revenues,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Số đơn hàng',
                    data: orderCounts,
                    borderColor: '#f5576c',
                    backgroundColor: 'rgba(245, 87, 108, 0.0)',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    tension: 0.4,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        font: {
                            family: "'Inter', sans-serif"
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.datasetIndex === 0) {
                                label += formatCurrency(context.raw);
                            } else {
                                label += context.raw;
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    ticks: {
                        callback: function(value) {
                            return formatCurrency(value);
                        }
                    }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    grid: {
                        drawOnChartArea: false
                    },
                    ticks: {
                        callback: function(value) {
                            return Math.round(value);
                        }
                    }
                }
            }
        }
    });
}

/**
 * Initialize category distribution chart (pie/doughnut)
 */
function initCategoryChart(elementId, data) {
    const canvas = document.getElementById(elementId);
    if (!canvas || typeof Chart === 'undefined') return;

    const ctx = canvas.getContext('2d');

    const labels = data.map(item => item.name || item.label);
    const values = data.map(item => item.count || item.value);

    const colors = [
        '#667eea', '#f5576c', '#ffc107', '#28a745',
        '#17a2b8', '#ff6b35', '#6c757d', '#e83e8c'
    ];

    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 15,
                        font: {
                            family: "'Inter', sans-serif"
                        }
                    }
                }
            }
        }
    });
}

// ============================================
// DASHBOARD WIDGETS
// ============================================

/**
 * Initialize dashboard stat cards with animations
 */
function initDashboardStats() {
    const statCards = document.querySelectorAll('.dashboard-card');

    statCards.forEach((card, index) => {
        // Stagger animation
        setTimeout(() => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'all 0.5s ease';

            setTimeout(() => {
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 50);
        }, index * 100);

        // Animate numbers
        const valueElement = card.querySelector('.dashboard-card-value');
        if (valueElement) {
            const targetValue = parseFloat(valueElement.textContent.replace(/[^0-9.-]+/g, ''));
            if (!isNaN(targetValue)) {
                animateValue(valueElement, 0, targetValue, 1000);
            }
        }
    });
}

/**
 * Animate number counting up
 */
function animateValue(element, start, end, duration) {
    const startTime = performance.now();
    const isCurrency = element.textContent.includes('₫') || element.textContent.includes('đ');

    function update(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);

        // Easing function
        const easeOutQuad = progress * (2 - progress);
        const current = start + (end - start) * easeOutQuad;

        if (isCurrency) {
            element.textContent = formatCurrency(Math.floor(current));
        } else {
            element.textContent = formatNumber(Math.floor(current));
        }

        if (progress < 1) {
            requestAnimationFrame(update);
        } else {
            if (isCurrency) {
                element.textContent = formatCurrency(end);
            } else {
                element.textContent = formatNumber(end);
            }
        }
    }

    requestAnimationFrame(update);
}

// ============================================
// FILTER & SEARCH
// ============================================

/**
 * Initialize admin filters
 */
function initAdminFilters() {
    const filterForm = document.querySelector('.filter-bar form');
    if (!filterForm) return;

    // Auto-submit on filter change
    const filterInputs = filterForm.querySelectorAll('select, input[type="date"]');
    filterInputs.forEach(input => {
        input.addEventListener('change', () => {
            filterForm.submit();
        });
    });

    // Search with debounce
    const searchInput = filterForm.querySelector('input[type="search"], input[name="search"]');
    if (searchInput) {
        const debouncedSubmit = debounce(() => {
            filterForm.submit();
        }, 500);

        searchInput.addEventListener('input', debouncedSubmit);
    }

    // Reset filters button
    const resetBtn = filterForm.querySelector('[data-action="reset"]');
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            filterForm.reset();
            filterForm.submit();
        });
    }
}

// ============================================
// BULK ACTIONS
// ============================================

/**
 * Initialize bulk actions
 */
function initBulkActions() {
    const bulkActionSelect = document.querySelector('[name="bulk_action"]');
    const bulkActionButton = document.querySelector('[data-action="bulk-apply"]');

    if (!bulkActionSelect || !bulkActionButton) return;

    bulkActionButton.addEventListener('click', async () => {
        const action = bulkActionSelect.value;
        if (!action) {
            showNotification('Vui lòng chọn hành động!', 'warning');
            return;
        }

        const selectedIds = getSelectedIds();
        if (selectedIds.length === 0) {
            showNotification('Vui lòng chọn ít nhất một mục!', 'warning');
            return;
        }

        const confirmed = await confirmModal(
            `Bạn có chắc chắn muốn ${action} ${selectedIds.length} mục đã chọn?`,
            {
                title: 'Xác nhận hành động',
                confirmText: 'Xác nhận',
                cancelText: 'Hủy'
            }
        );

        if (!confirmed) return;

        try {
            showLoading('Đang xử lý...');
            const response = await postRequest('bulk-action.php', {
                action: action,
                ids: selectedIds
            });

            if (response.success) {
                showNotification('Xử lý thành công!', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showNotification(response.message || 'Có lỗi xảy ra!', 'error');
            }
        } catch (error) {
            console.error('Bulk action error:', error);
            showNotification('Có lỗi xảy ra!', 'error');
        } finally {
            hideLoading();
        }
    });
}

/**
 * Get selected item IDs from checkboxes
 */
function getSelectedIds() {
    const checkboxes = document.querySelectorAll('tbody input[type="checkbox"]:checked');
    return Array.from(checkboxes).map(cb => cb.value || cb.dataset.id).filter(Boolean);
}

// ============================================
// IMAGE UPLOAD PREVIEW
// ============================================

/**
 * Initialize image upload with preview
 */
function initImageUpload() {
    const imageInputs = document.querySelectorAll('input[type="file"][accept*="image"]');

    imageInputs.forEach(input => {
        input.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            // Validate file type
            if (!file.type.startsWith('image/')) {
                showNotification('Vui lòng chọn file hình ảnh!', 'error');
                input.value = '';
                return;
            }

            // Validate file size (max 5MB)
            if (file.size > 5 * 1024 * 1024) {
                showNotification('Kích thước file không được vượt quá 5MB!', 'error');
                input.value = '';
                return;
            }

            // Show preview
            const reader = new FileReader();
            reader.onload = function(e) {
                showImagePreview(input, e.target.result);
            };
            reader.readAsDataURL(file);
        });
    });
}

/**
 * Show image preview
 */
function showImagePreview(input, imageSrc) {
    let preview = input.parentElement.querySelector('.image-preview');

    if (!preview) {
        preview = document.createElement('div');
        preview.className = 'image-preview';
        input.parentElement.appendChild(preview);
    }

    preview.innerHTML = `
        <img src="${imageSrc}" alt="Preview">
        <button type="button" class="remove-preview" aria-label="Remove">×</button>
    `;

    // Remove button
    const removeBtn = preview.querySelector('.remove-preview');
    removeBtn.addEventListener('click', () => {
        preview.remove();
        input.value = '';
    });
}

// ============================================
// INITIALIZATION
// ============================================

/**
 * Initialize all admin functionality
 */
function initAdmin() {
    console.log('TK-MALL Admin JS initialized');

    initAdminSidebar();
    initDashboardStats();
    initAdminFilters();
    initBulkActions();
    initImageUpload();

    // Initialize data tables
    document.querySelectorAll('.data-table').forEach(table => {
        new DataTable(table);
    });
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdmin);
} else {
    initAdmin();
}

// Export to window
window.DataTable = DataTable;
window.initSalesChart = initSalesChart;
window.initCategoryChart = initCategoryChart;
window.getSelectedIds = getSelectedIds;
