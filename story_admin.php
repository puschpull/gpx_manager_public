<?php
declare(strict_types=1);

/**
 * Cestopis — správa verzí k jedné trase (jen admin).
 *
 * Volba varianty (styl, model, mapa, fotky) s odhadem ceny, měsíční strop,
 * generování na pozadí s průběhem, seznam verzí, zveřejnění a smazání.
 * Logika je v src/Cestopis (pravidla v src/Cestopis/CLAUDE.md), akce
 * v api/story/*. Návštěvník sem nesmí — přesměruje se na detail trasy.
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/track_title.php';
require_once __DIR__ . '/includes/story_helper.php';

$trackId = (int)($_GET['id'] ?? 0);
if (empty($_SESSION['is_admin'])) {
    header('Location: ' . ($trackId > 0 ? 'detail.php?id=' . $trackId : 'index.php'));
    exit;
}
if ($trackId <= 0) {
    http_response_code(400);
    exit(h(t('err_invalid_id')));
}

$_stmt = $pdo->prepare('SELECT * FROM tracks WHERE id = ?');
$_stmt->execute([$trackId]);
$track = $_stmt->fetch(PDO::FETCH_ASSOC);
if ($track === false) {
    http_response_code(404);
    exit(h(t('err_track_not_found')));
}

$_storyRepo      = story_generator($pdo)->repository();
$_storyTitle     = track_display_title($pdo, $track);
$_storyEnabled   = feature_enabled('story');
$_storyHasKey    = story_api_key() !== '';
$_storyPhotos    = count($_storyRepo->photos($trackId));
$_storyVersions  = array_reverse($_storyRepo->stories($trackId));   // nejnovější nahoře
$_storyMonth     = $_storyRepo->monthSpend();
$_storyCap       = story_monthly_cap();
$_storyRunningId = null;
foreach ($_storyVersions as $_v) {
    if ($_v['status'] === 'running') {
        $_storyRunningId = (int)$_v['id'];
        break;
    }
}
$_storyHasPublished = false;
foreach ($_storyVersions as $_v) {
    if ($_v['is_published']) {
        $_storyHasPublished = true;
        break;
    }
}
$_storyVisitorsSee = in_array('story', (array)get_app_config('visible_pages', all_pages()), true);
unset($_stmt, $_v);

require __DIR__ . '/includes/story_admin_view.php';
