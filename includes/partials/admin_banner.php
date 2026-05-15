<?php
declare(strict_types=1);

/**
 * Admin banner partial.
 *
 * Renders the sticky admin bar shown at the top of every page when the
 * current session belongs to an administrator.  Also provides a separate
 * function for the visitor-preview banner shown when an admin has
 * temporarily entered visitor-preview mode.
 *
 * Covers: QR-2, A11Y-014
 */

/**
 * Render the administrator banner.
 *
 * Outputs nothing if the session is not an admin session.
 * Must be called after session_start() and after i18n is loaded
 * (i.e. after config.php + i18n.php are required).
 *
 * The visitor-preview link is built from the current REQUEST_URI so that
 * the admin can preview any page, not only index.php.
 */
function render_admin_banner(): void
{
    if (empty($_SESSION['is_admin'])) {
        return;
    }

    $via = htmlspecialchars($_SESSION['admin_via'] ?? 'login', ENT_QUOTES, 'UTF-8');
    $ip  = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '?', ENT_QUOTES, 'UTF-8');

    // Visitor-preview toggle is handled in public_access.php — pages that use auth.php
    // (admin.php, edit.php, settings.php) never reach that code. Always route through
    // index.php which uses public_access.php, so the toggle is reliably processed.
    $previewUrl = htmlspecialchars('index.php?visitor_preview=1', ENT_QUOTES, 'UTF-8');

    $csrf = function_exists('csrf_field') ? csrf_field() : '';

    $ariaLabel       = htmlspecialchars(t('admin_banner_aria', 'Administrátorský panel'), ENT_QUOTES, 'UTF-8');
    $loggedInText    = htmlspecialchars(t('admin_logged_in', 'Přihlášen jako'), ENT_QUOTES, 'UTF-8');
    $adminText       = htmlspecialchars(t('administrator', 'administrátor'), ENT_QUOTES, 'UTF-8');
    $previewText     = htmlspecialchars(t('preview_as_visitor', 'Náhled jako návštěvník'), ENT_QUOTES, 'UTF-8');
    $previewAria     = htmlspecialchars(t('preview_as_visitor_aria', 'Zobrazit stránku jako běžný návštěvník'), ENT_QUOTES, 'UTF-8');
    $logoutText      = htmlspecialchars(t('logout', 'Odhlásit se'), ENT_QUOTES, 'UTF-8');
    $logoutAria      = htmlspecialchars(t('logout_aria', 'Odhlásit se z administrátorského účtu'), ENT_QUOTES, 'UTF-8');
    ?>
    <div role="region" aria-label="<?= $ariaLabel ?>" class="admin-banner">
      <div>
        <?= $loggedInText ?>
        <strong><?= $adminText ?></strong>
        <span class="admin-banner__muted">(<?= $via ?>)</span>
        &nbsp;|&nbsp; IP: <strong><?= $ip ?></strong>
      </div>
      <div class="admin-banner__actions">
        <a href="<?= $previewUrl ?>"
           aria-label="<?= $previewAria ?>">
          <span aria-hidden="true">&#128065;</span>
          <?= $previewText ?>
        </a>
        <form method="post" action="login.php" class="admin-banner__logout-form">
          <?= $csrf ?>
          <input type="hidden" name="logout" value="1">
          <button type="submit" aria-label="<?= $logoutAria ?>">
            <?= $logoutText ?>
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
