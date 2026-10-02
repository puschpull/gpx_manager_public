<?php
declare(strict_types=1);

/**
 * Cestopis — čtení zveřejněného textu výletu (otevírá se v nové kartě
 * z detailu trasy).
 *
 * Návštěvník: jen se zapnutou funkcí „story" a se stránkou „story" ve
 * viditelných stránkách (Administrace), a jen zveřejněná verze.
 * Admin: navíc náhled libovolné hotové verze (?v=<id>) i před zveřejněním.
 *
 * Nic se tu nepočítá ani nevolá ven: text, zastávky, názvy míst a body
 * z mapy jsou v uložené verzi (facts_json) — stránka jen čte databázi.
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/track_title.php';
require_once __DIR__ . '/includes/story_helper.php';
require_once __DIR__ . '/includes/story_layout.php';

$trackId = (int)($_GET['id'] ?? 0);
$_isAdmin = !empty($_SESSION['is_admin']);

if (!$_isAdmin && !feature_enabled('story')) {
    header('Location: ' . ($trackId > 0 ? 'detail.php?id=' . $trackId : 'index.php'));
    exit;
}
check_page_access('story');
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

$_storyRepo = story_generator($pdo)->repository();
$_storyPreview = false;
$story = null;
$_v = (int)($_GET['v'] ?? 0);
if ($_isAdmin && $_v > 0) {
    $story = $_storyRepo->story($_v);
    if ($story !== null && ((int)$story['track_id'] !== $trackId || $story['status'] !== 'done')) {
        $story = null;
    }
    $_storyPreview = $story !== null && !$story['is_published'];
} else {
    $story = $_storyRepo->published($trackId);
}

$_storyTitle = track_display_title($pdo, $track);

// Fotky po zastávkách: časy zastávek z uložených faktů, fotky podle času
// pořízení (jen viditelné). Fotka mimo všechny zastávky → „Další fotky".
$_storyStops = [];
$_storyOther = [];
if ($story !== null) {
    foreach ((array)($story['facts']['zastavky'] ?? []) as $z) {
        $_storyStops[] = [
            'od'     => (string)($z['od'] ?? ''),
            'do'     => (string)($z['do'] ?? ''),
            'misto'  => $z['misto'] ?? null,
            'okoli'  => (array)($z['v_okoli'] ?? []),
            'delsi'  => !empty($z['delsi_zastaveni']),
            'trvani' => (int)($z['trvani_minut'] ?? 0),
            'photos' => [],
        ];
    }
    foreach ($_storyRepo->photos($trackId) as $p) {
        $hm = $p->takenAt->format('H:i');
        $placed = false;
        foreach ($_storyStops as &$s) {
            if ($hm >= $s['od'] && $hm <= $s['do']) {
                $s['photos'][] = $p;
                $placed = true;
                break;
            }
        }
        unset($s);
        if (!$placed) {
            $_storyOther[] = $p;
        }
    }
}
// Časopisová podoba: odstavce + fotky vložené do textu (includes/story_layout.php)
$_storyParas = [];
$_storyLayout = ['hero' => null, 'after' => []];
if ($story !== null) {
    $_st = \GpxManager\Cestopis\StoryText::split((string)$story['story']);
    $_storyParas = $_st['paras'];
    $_dims = [];
    $_stmt = $pdo->prepare('SELECT id, width, height FROM track_photos WHERE track_id = ?');
    $_stmt->execute([$trackId]);
    foreach ($_stmt->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_dims[(int)$_r['id']] = [(int)$_r['width'], (int)$_r['height']];
    }
    $_storyLayout = story_article_layout($_storyParas, $_storyStops, $_dims, $_st['photos'], $_st['hero']);
    unset($_dims, $_r, $_st);
}
unset($_stmt, $_v);

require __DIR__ . '/includes/story_view.php';
