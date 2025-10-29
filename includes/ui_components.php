<?php
/**
 * UI Component Helper Functions
 * Reusable HTML component generators
 *
 * This file provides functions to generate common UI components,
 * reducing HTML duplication across the application.
 *
 * @author TK-MALL Development Team
 * @version 2.0.0
 */

/**
 * Generate pagination HTML
 *
 * @param int $currentPage Current page number
 * @param int $totalPages Total number of pages
 * @param string $baseUrl Base URL for pagination links (without page param)
 * @param array $params Additional query parameters
 * @return string Pagination HTML
 */
function renderPagination($currentPage, $totalPages, $baseUrl = '', $params = [])
{
    if ($totalPages <= 1) {
        return '';
    }

    $currentPage = max(1, min($currentPage, $totalPages));

    // Build query string from params
    $queryParams = $params;
    $separator = strpos($baseUrl, '?') !== false ? '&' : '?';

    $html = '<nav aria-label="Pagination"><ul class="pagination">';

    // Previous button
    if ($currentPage > 1) {
        $queryParams['page'] = $currentPage - 1;
        $url = $baseUrl . $separator . http_build_query($queryParams);
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '">« Trước</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">« Trước</span></li>';
    }

    // Page numbers
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    // First page
    if ($start > 1) {
        $queryParams['page'] = 1;
        $url = $baseUrl . $separator . http_build_query($queryParams);
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '">1</a></li>';

        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    // Page numbers
    for ($i = $start; $i <= $end; $i++) {
        $queryParams['page'] = $i;
        $url = $baseUrl . $separator . http_build_query($queryParams);

        if ($i == $currentPage) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $url . '">' . $i . '</a></li>';
        }
    }

    // Last page
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }

        $queryParams['page'] = $totalPages;
        $url = $baseUrl . $separator . http_build_query($queryParams);
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '">' . $totalPages . '</a></li>';
    }

    // Next button
    if ($currentPage < $totalPages) {
        $queryParams['page'] = $currentPage + 1;
        $url = $baseUrl . $separator . http_build_query($queryParams);
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '">Sau »</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Sau »</span></li>';
    }

    $html .= '</ul></nav>';

    return $html;
}

/**
 * Generate status badge HTML
 *
 * @param string $status Status value
 * @param array $statusConfig Status configuration ['status' => ['label' => '', 'class' => '']]
 * @return string Badge HTML
 */
function renderStatusBadge($status, $statusConfig = [])
{
    $defaultConfig = [
        'active' => ['label' => 'Hoạt động', 'class' => 'success'],
        'inactive' => ['label' => 'Không hoạt động', 'class' => 'secondary'],
        'pending' => ['label' => 'Chờ xử lý', 'class' => 'warning'],
        'approved' => ['label' => 'Đã duyệt', 'class' => 'success'],
        'rejected' => ['label' => 'Từ chối', 'class' => 'danger'],
        'banned' => ['label' => 'Bị cấm', 'class' => 'danger'],
    ];

    $config = array_merge($defaultConfig, $statusConfig);

    if (isset($config[$status])) {
        $label = $config[$status]['label'];
        $class = $config[$status]['class'];
        return '<span class="badge badge-' . $class . '">' . htmlspecialchars($label) . '</span>';
    }

    return '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>';
}

/**
 * Generate alert/message HTML
 *
 * @param string $message Message text
 * @param string $type Alert type (success, error, warning, info)
 * @param bool $dismissible Whether alert is dismissible (default: true)
 * @return string Alert HTML
 */
function renderAlert($message, $type = 'info', $dismissible = true)
{
    $typeClass = [
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'danger' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info',
    ];

    $class = $typeClass[$type] ?? 'alert-info';
    $dismissClass = $dismissible ? ' alert-dismissible fade show' : '';

    $html = '<div class="alert ' . $class . $dismissClass . '" role="alert">';
    $html .= htmlspecialchars($message);

    if ($dismissible) {
        $html .= '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
        $html .= '<span aria-hidden="true">&times;</span>';
        $html .= '</button>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * Generate confirmation modal HTML
 *
 * @param string $id Modal ID
 * @param string $title Modal title
 * @param string $message Modal message
 * @param string $confirmText Confirm button text (default: 'Xác nhận')
 * @param string $cancelText Cancel button text (default: 'Hủy')
 * @param string $confirmClass Confirm button class (default: 'btn-primary')
 * @return string Modal HTML
 */
function renderConfirmModal($id, $title, $message, $confirmText = 'Xác nhận', $cancelText = 'Hủy', $confirmClass = 'btn-primary')
{
    $html = <<<HTML
<div class="modal fade" id="{$id}" tabindex="-1" role="dialog" aria-labelledby="{$id}Label" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="{$id}Label">{$title}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                {$message}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{$cancelText}</button>
                <button type="button" class="btn {$confirmClass}" id="{$id}Confirm">{$confirmText}</button>
            </div>
        </div>
    </div>
</div>
HTML;

    return $html;
}

/**
 * Generate data table HTML
 *
 * @param array $headers Table headers ['Column Name', 'Column 2', ...]
 * @param array $rows Table rows [['cell1', 'cell2'], ['cell1', 'cell2']]
 * @param array $options Table options ['class' => '', 'id' => '', 'responsive' => true]
 * @return string Table HTML
 */
function renderDataTable($headers, $rows, $options = [])
{
    $class = $options['class'] ?? 'table table-striped table-bordered';
    $id = $options['id'] ?? '';
    $responsive = $options['responsive'] ?? true;

    $html = '';

    if ($responsive) {
        $html .= '<div class="table-responsive">';
    }

    $html .= '<table class="' . $class . '"';
    if ($id) {
        $html .= ' id="' . $id . '"';
    }
    $html .= '>';

    // Headers
    $html .= '<thead><tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . htmlspecialchars($header) . '</th>';
    }
    $html .= '</tr></thead>';

    // Body
    $html .= '<tbody>';
    if (empty($rows)) {
        $html .= '<tr><td colspan="' . count($headers) . '" class="text-center">Không có dữ liệu</td></tr>';
    } else {
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
    }
    $html .= '</tbody>';

    $html .= '</table>';

    if ($responsive) {
        $html .= '</div>';
    }

    return $html;
}

/**
 * Generate breadcrumb HTML
 *
 * @param array $items Breadcrumb items [['title' => '', 'url' => '']]
 * @param bool $includeHome Include home link (default: true)
 * @return string Breadcrumb HTML
 */
function renderBreadcrumb($items, $includeHome = true)
{
    $html = '<nav aria-label="breadcrumb"><ol class="breadcrumb">';

    if ($includeHome) {
        $html .= '<li class="breadcrumb-item"><a href="index.php">Trang chủ</a></li>';
    }

    foreach ($items as $index => $item) {
        $isLast = $index === count($items) - 1;

        if ($isLast) {
            $html .= '<li class="breadcrumb-item active" aria-current="page">';
            $html .= htmlspecialchars($item['title']);
            $html .= '</li>';
        } else {
            $html .= '<li class="breadcrumb-item">';
            if (isset($item['url'])) {
                $html .= '<a href="' . htmlspecialchars($item['url']) . '">';
                $html .= htmlspecialchars($item['title']);
                $html .= '</a>';
            } else {
                $html .= htmlspecialchars($item['title']);
            }
            $html .= '</li>';
        }
    }

    $html .= '</ol></nav>';

    return $html;
}

/**
 * Generate form input HTML
 *
 * @param string $name Input name
 * @param string $label Input label
 * @param string $value Input value (default: '')
 * @param array $options Options ['type' => 'text', 'required' => false, 'placeholder' => '', 'class' => '']
 * @return string Form input HTML
 */
function renderFormInput($name, $label, $value = '', $options = [])
{
    $type = $options['type'] ?? 'text';
    $required = $options['required'] ?? false;
    $placeholder = $options['placeholder'] ?? '';
    $class = $options['class'] ?? 'form-control';
    $error = $options['error'] ?? '';

    $html = '<div class="form-group">';
    $html .= '<label for="' . $name . '">' . htmlspecialchars($label);

    if ($required) {
        $html .= ' <span class="text-danger">*</span>';
    }

    $html .= '</label>';

    if ($type === 'textarea') {
        $html .= '<textarea name="' . $name . '" id="' . $name . '" class="' . $class . '"';
        if ($placeholder) {
            $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        }
        if ($required) {
            $html .= ' required';
        }
        $html .= '>' . htmlspecialchars($value) . '</textarea>';
    } else {
        $html .= '<input type="' . $type . '" name="' . $name . '" id="' . $name . '" class="' . $class . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        if ($placeholder) {
            $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        }
        if ($required) {
            $html .= ' required';
        }
        $html .= '>';
    }

    if ($error) {
        $html .= '<div class="invalid-feedback d-block">' . htmlspecialchars($error) . '</div>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * Generate card HTML
 *
 * @param string $title Card title
 * @param string $content Card content
 * @param array $options Options ['footer' => '', 'class' => '', 'headerClass' => '']
 * @return string Card HTML
 */
function renderCard($title, $content, $options = [])
{
    $footer = $options['footer'] ?? '';
    $class = $options['class'] ?? 'card';
    $headerClass = $options['headerClass'] ?? 'card-header';

    $html = '<div class="' . $class . '">';

    if ($title) {
        $html .= '<div class="' . $headerClass . '">' . htmlspecialchars($title) . '</div>';
    }

    $html .= '<div class="card-body">' . $content . '</div>';

    if ($footer) {
        $html .= '<div class="card-footer">' . $footer . '</div>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * Generate loading spinner HTML
 *
 * @param string $text Loading text (default: 'Đang tải...')
 * @param string $size Size (sm, md, lg, default: md)
 * @return string Spinner HTML
 */
function renderLoadingSpinner($text = 'Đang tải...', $size = 'md')
{
    $sizeClass = [
        'sm' => 'spinner-border-sm',
        'md' => '',
        'lg' => 'spinner-border-lg'
    ];

    $class = $sizeClass[$size] ?? '';

    $html = '<div class="text-center my-4">';
    $html .= '<div class="spinner-border ' . $class . '" role="status">';
    $html .= '<span class="sr-only">' . htmlspecialchars($text) . '</span>';
    $html .= '</div>';
    if ($text) {
        $html .= '<p class="mt-2">' . htmlspecialchars($text) . '</p>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Generate empty state HTML
 *
 * @param string $message Empty state message
 * @param string $icon Icon HTML or emoji (default: '📭')
 * @param string $actionHtml Action button HTML (optional)
 * @return string Empty state HTML
 */
function renderEmptyState($message, $icon = '📭', $actionHtml = '')
{
    $html = '<div class="empty-state text-center py-5">';
    $html .= '<div class="empty-icon" style="font-size: 4rem; margin-bottom: 1rem;">' . $icon . '</div>';
    $html .= '<p class="text-muted">' . htmlspecialchars($message) . '</p>';

    if ($actionHtml) {
        $html .= '<div class="mt-3">' . $actionHtml . '</div>';
    }

    $html .= '</div>';

    return $html;
}
?>
