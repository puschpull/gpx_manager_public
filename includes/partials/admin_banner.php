<?php
declare(strict_types=1);

/**
 * Admin partials.
 *
 * render_admin_menu()            — the administrator dropdown rendered inside the
 *                                  site header (replaces the former full-width blue
 *                                  admin bar, which cost 42px on every page and was
 *                                  inconsistently switched off on three pages).
 * render_visitor_preview_banner() — the orange full-width bar shown while an admin
 *                                  browses as a visitor.  Deliberately still a bar:
 *                                  it is a temporary state that must be impossible
 *                                  to overlook and one click to leave.
 *
 * Covers: QR-2, A11Y-014
 */

/**
 * Render the administrator dropdown for the site header.
 *
 * Outputs nothing if the session is not an admin session.
 * Must be called after session_start() and after i18n is loaded
 * (i.e. after config.php + i18n.php are required), and from within
 * the header markup so that .site-header colour rules apply.
 *
 * Requires Alpine.js (loaded by layout_header.php).
 */
function render_admin_menu(): void
{
    if (empty($_SESSION['is_admin'])) {
        return;
    }

    $via = htmlspecialchars($_SESSION['admin_via'] ?? 'login', ENT_QUOTES, 'UTF-8');
    $ip  = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '?', ENT_QUOTES, 'UTF-8');

    // Visitor-preview toggle is handled in public_access.php — pages that use auth.php
    // (admin.php, edit.php, import.php) never reach that code. Always route through
    // index.php which uses public_access.php, so the toggle is reliably processed.
    $previewUrl = htmlspecialchars('index.php?visitor_preview=1', ENT_QUOTES, 'UTF-8');

    $csrf = function_exists('csrf_field') ? csrf_field() : '';

    $ariaLabel    = htmlspecialchars(t('admin_banner_aria', 'Administrátorský panel'), ENT_QUOTES, 'UTF-8');
    $loggedInText = htmlspecialchars(t('admin_logged_in', 'Přihlášen jako'), ENT_QUOTES, 'UTF-8');
    $adminText    = htmlspecialchars(t('administrator', 'administrátor'), ENT_QUOTES, 'UTF-8');
    $adminPage    = htmlspecialchars(t('nav_admin', 'Administrace'), ENT_QUOTES, 'UTF-8');
    $previewText  = htmlspecialchars(t('preview_as_visitor', 'Náhled jako návštěvník'), ENT_QUOTES, 'UTF-8');
    $previewAria  = htmlspecialchars(t('preview_as_visitor_aria', 'Zobrazit stránku jako běžný návštěvník'), ENT_QUOTES, 'UTF-8');
    $logoutText   = htmlspecialchars(t('logout', 'Odhlásit se'), ENT_QUOTES, 'UTF-8');
    $logoutAria   = htmlspecialchars(t('logout_aria', 'Odhlásit se z administrátorského účtu'), ENT_QUOTES, 'UTF-8');

    // Geometrie je ve vlastních .gpx-adminmenu-* třídách (nevrstvené CSS v
    // layout_header.php), ne v Tailwind utilitách: app.css je commitnutý build
    // a nové utility by v něm chyběly, dokud ho někdo nepřegeneruje.
    // Z Tailwindu se berou jen barvy a stíny, které v app.css prokazatelně jsou.
    $itemClass = 'gpx-adminmenu-item transition-colors hover:bg-sand-100 dark:hover:bg-forest-700';
    $sepClass  = 'gpx-adminmenu-sep border-t border-sand-200 dark:border-forest-700';
    ?>
    <div x-data="{ open: false }" @click.outside="open = false"
         @keydown.escape.window="open = false"
         class="gpx-sm-inline-flex relative">
        <button @click="open = !open" type="button"
                class="gpx-adminmenu-btn transition-colors hover:bg-sand-100 dark:hover:bg-forest-800"
                aria-label="<?= $ariaLabel ?>"
                aria-haspopup="menu"
                :aria-expanded="open.toString()">
            <i data-lucide="settings" aria-hidden="true"></i>
            <i data-lucide="chevron-down" class="gpx-adminmenu-caret"
               :style="open ? 'transform:rotate(180deg)' : ''" aria-hidden="true"></i>
        </button>
        <div x-show="open" x-cloak x-transition.opacity.duration.150ms
             role="menu"
             class="gpx-adminmenu-panel rounded-lg bg-white dark:bg-forest-800 border border-sand-200 dark:border-forest-700 shadow-hover">

            <div class="gpx-adminmenu-meta">
                <?= $loggedInText ?> <strong><?= $adminText ?></strong> (<?= $via ?>)<br>
                IP: <strong><?= $ip ?></strong>
            </div>

            <div class="<?= $sepClass ?>"></div>

            <a href="admin.php" role="menuitem" class="<?= $itemClass ?>">
                <i data-lucide="shield" aria-hidden="true"></i>
                <span><?= $adminPage ?></span>
            </a>

            <a href="<?= $previewUrl ?>" role="menuitem"
               aria-label="<?= $previewAria ?>" class="<?= $itemClass ?>">
                <i data-lucide="eye" aria-hidden="true"></i>
                <span><?= $previewText ?></span>
            </a>

            <div class="<?= $sepClass ?>"></div>

            <form method="post" action="login.php" class="gpx-adminmenu-form">
                <?= $csrf ?>
                <input type="hidden" name="logout" value="1">
                <button type="submit" role="menuitem"
                        aria-label="<?= $logoutAria ?>"
                        class="gpx-adminmenu-logout <?= $itemClass ?>">
                    <i data-lucide="log-out" aria-hidden="true"></i>
                    <span><?= $logoutText ?></span>
                </button>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Render the visitor-preview banner.
 *
 * Shown when an admin is temporarily browsing the site as a visitor.
 * Provides a link to exit preview mode and return to the admin view.
 *
 * Outputs nothing if the session is not in visitor-preview mode.
 */
function render_visitor_preview_banner(): void
{
    if (empty($_SESSION['visitor_preview'])) {
        return;
    }

    $parts = parse_url($_SERVER['REQUEST_URI'] ?? '/');
    parse_str($parts['query'] ?? '', $qParams);
    $qParams['visitor_preview'] = '0';
    $exitUrl = htmlspecialchars(
        ($parts['path'] ?? '/') . '?' . http_build_query($qParams),
        ENT_QUOTES,
        'UTF-8'
    );

    $bannerAria  = htmlspecialchars(t('visitor_preview_banner_aria', 'Náhled jako návštěvník'), ENT_QUOTES, 'UTF-8');
    $bannerText  = htmlspecialchars(t('preview_as_visitor', 'Náhled jako návštěvník'), ENT_QUOTES, 'UTF-8');
    $exitText    = htmlspecialchars(t('exit_visitor_preview', 'Zpět na admin pohled'), ENT_QUOTES, 'UTF-8');
    $exitAria    = htmlspecialchars(t('exit_visitor_preview_aria', 'Ukončit náhled návštěvníka a přepnout zpět na admin pohled'), ENT_QUOTES, 'UTF-8');
    ?>
    <div role="region" aria-label="<?= $bannerAria ?>" class="visitor-preview-banner">
      <div>
        <span aria-hidden="true">&#128065;</span>
        <strong><?= $bannerText ?></strong>
        &mdash; <?= htmlspecialchars(t('preview_as_visitor_desc', 'vidíte stránku bez admin oprávnění'), ENT_QUOTES, 'UTF-8') ?>
      </div>
      <a href="<?= $exitUrl ?>" aria-label="<?= $exitAria ?>" class="visitor-preview-banner__exit">
        &times; <?= $exitText ?>
      </a>
    </div>
    <?php
}
