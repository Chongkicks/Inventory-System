<?php

// Normalize requested page numbers so bad query strings do not break pagination.
function pagination_current_page($value, $default = 1)
{
    $page = (int) ($value ?? $default);
    return $page < 1 ? (int) $default : $page;
}

// Return current page, total pages, and SQL offset for a list.
function pagination_page_state($requestedPage, $totalItems, $perPage)
{
    $perPage = max(1, (int) $perPage);
    $totalItems = max(0, (int) $totalItems);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $currentPage = max(1, min((int) $requestedPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;

    return [$currentPage, $totalPages, $offset];
}

// Preserve current filters while removing page/modal parameters that should be recalculated.
function pagination_query_params(array $exclude = [])
{
    $query = $_GET;

    foreach ($exclude as $key) {
        unset($query[$key]);
    }

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    return $query;
}

// Build a URL with query parameters and an optional page anchor.
function pagination_url($path, array $params = [], $anchor = '')
{
    $query = http_build_query($params);
    $url = $path;

    if ($query !== '') {
        $url .= '?' . $query;
    }

    if ($anchor !== '') {
        $url .= '#' . ltrim((string) $anchor, '#');
    }

    return $url;
}

// Render the shared previous/page/next controls used by products, users, and history.
function pagination_render($path, $pageParam, $currentPage, $totalItems, $perPage, array $queryParams = [], $label = 'items', $anchor = '')
{
    $pageParam = (string) $pageParam;
    $currentPage = max(1, (int) $currentPage);
    $totalItems = max(0, (int) $totalItems);
    $perPage = max(1, (int) $perPage);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $safeLabel = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');

    if ($totalItems === 0) {
        $summaryText = 'No ' . $safeLabel . ' found.';
    } else {
        $start = (($currentPage - 1) * $perPage) + 1;
        $end = min($currentPage * $perPage, $totalItems);
        $summaryLabel = $totalItems === 1 ? rtrim($safeLabel, 's') : $safeLabel;
        $summaryText = 'Showing ' . $start . '-' . $end . ' of ' . $totalItems . ' ' . $summaryLabel;
    }

    $buildLink = function ($page, $label, $isActive = false, $isDisabled = false) use ($path, $pageParam, $queryParams, $anchor) {
        $page = (int) $page;
        $classes = 'pagination-link';
        $attributes = '';
        $content = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');

        if ($isActive) {
            $classes .= ' is-active';
            $attributes .= ' aria-current="page"';
        }

        if ($isDisabled) {
            $classes .= ' is-disabled';
            $attributes .= ' aria-disabled="true" tabindex="-1"';
            return '<span class="' . $classes . '"' . $attributes . '>' . $content . '</span>';
        }

        $linkParams = $queryParams;
        $linkParams[$pageParam] = $page;

        return '<a class="' . $classes . '"' . $attributes . ' href="' . htmlspecialchars(pagination_url($path, $linkParams, $anchor), ENT_QUOTES, 'UTF-8') . '">' . $content . '</a>';
    };

    $links = [];
    $links[] = $buildLink($currentPage - 1, 'Previous', false, $currentPage <= 1);

    if ($totalPages <= 7) {
        for ($page = 1; $page <= $totalPages; $page++) {
            $links[] = $buildLink($page, (string) $page, $page === $currentPage, false);
        }
    } else {
        $links[] = $buildLink(1, '1', $currentPage === 1, false);

        if ($currentPage > 3) {
            $links[] = '<span class="pagination-ellipsis">...</span>';
        }

        $start = max(2, $currentPage - 1);
        $end = min($totalPages - 1, $currentPage + 1);

        for ($page = $start; $page <= $end; $page++) {
            if ($page === 1 || $page === $totalPages) {
                continue;
            }

            $links[] = $buildLink($page, (string) $page, $page === $currentPage, false);
        }

        if ($currentPage < $totalPages - 2) {
            $links[] = '<span class="pagination-ellipsis">...</span>';
        }

        $links[] = $buildLink($totalPages, (string) $totalPages, $currentPage === $totalPages, false);
    }

    $links[] = $buildLink($currentPage + 1, 'Next', false, $currentPage >= $totalPages);

    return
        '<div class="pagination">' .
            '<p class="pagination-summary">' . $summaryText . '</p>' .
            '<div class="pagination-controls" role="navigation" aria-label="' . $safeLabel . ' pagination">' .
                implode('', $links) .
            '</div>' .
        '</div>';
}
