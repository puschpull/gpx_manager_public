<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  sort_query.php — Query-string & table-sort helpers
 *  build_query() preserves active filter params across page
 *  links; sort_th() renders sortable column headers.
 * ===========================================================
 */

/**
 * Builds a query string that carries all active filter params forward,
 * with optional overrides (pass null as a value to drop a key).
 *
 * @param array $override Key/value pairs to add or override; null value removes key.
 */
function build_query(array $override = []): string {
    $keys = [
        'q', 'color', 'cat', 'cat_mode', 'fav_only', 'diff_min', 'diff_max', 'activity',
        'dist_min', 'dist_max', 'dist_inv',
        'ascent_min', 'ascent_max', 'ascent_inv',
        'descent_min', 'descent_max', 'descent_inv',
        'savg_min', 'savg_max', 'savg_inv',
        'savg_total_min', 'savg_total_max', 'savg_total_inv',
        'smax_min', 'smax_max', 'smax_inv',
        'elevation_min_val', 'elevation_max_val', 'elevation_inv',
        'date_from', 'date_to', 'date_inv',
        'sort_by', 'sort_dir',
        'per_page', 'page', 'filter_submit',
    ];

    $data = [];
    foreach ($keys as $k) {
        if (isset($_GET[$k])) $data[$k] = $_GET[$k];
    }

    foreach ($override as $k => $v) {
        if ($v === null) unset($data[$k]);
        else $data[$k] = $v;
    }

    return http_build_query($data);
}

/**
 * Renders a complete sortable <th> element with aria-sort and scope="col".
 *
 * Returns a full <th> tag (not just an anchor) so that aria-sort is placed
 * on the correct element as required by WCAG 2.2 / ARIA spec (A11Y-006).
 *
 * @param string $label      Visible column label (HTML-safe string expected from caller).
 * @param string $col        Column identifier (value of sort_by param).
 * @param string $currentBy  Currently active sort column.
 * @param string $currentDir Currently active sort direction ('ASC'|'DESC').
 * @param string $class      Optional extra CSS classes for the <th>.
 */
function sort_th(string $label, string $col, string $currentBy, string $currentDir, string $class = ''): string {
    $isCurrent  = ($currentBy === $col);
    $nextDir    = ($isCurrent && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $qs         = build_query(['sort_by' => $col, 'sort_dir' => $nextDir, 'page' => 1]);
    $arrow      = $isCurrent ? ($currentDir === 'ASC' ? ' ↑' : ' ↓') : '';
    $ariaSort   = $isCurrent ? ($currentDir === 'ASC' ? 'ascending' : 'descending') : 'none';
    $classAttr  = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';
    $link       = '<a href="?' . h($qs) . '" title="Seřadit">' . $label . $arrow . '</a>';
    return '<th scope="col" aria-sort="' . $ariaSort . '"' . $classAttr . '>' . $link . '</th>';
}
