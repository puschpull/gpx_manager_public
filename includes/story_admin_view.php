<?php
declare(strict_types=1);
/**
 * story_admin_view.php — šablona správy cestopisu k jedné trase.
 * Proměnné připraví story_admin.php. Všechny čísla do h() přetypovaná
 * (strict_types).
 */

/** Text verze → odstavce (prázdný řádek = nový odstavec). */
$_storyParagraphs = static function (?string $text): string {
    // Značky fotek („[foto N]“, „[foto N/k]“) jen naznačit — kam fotka přijde
    $st = \GpxManager\Cestopis\StoryText::split((string)$text);
    $mark = static fn(array $m): string => '<p class="story-hint">📷 ' . h(str_replace('{n}',
        $m['stop'] . ($m['pick'] !== null ? '/' . $m['pick'] : ''),
        t('story_photo_mark', 'fotka ze zastávky {n}'))) . "</p>\n";
    $out = $st['hero'] !== null ? $mark($st['hero']) : '';
    foreach ($st['paras'] as $i => $p) {
        $out .= '<p>' . nl2br(h($p)) . "</p>\n";
        if (isset($st['photos'][$i])) {
            $out .= $mark($st['photos'][$i]);
        }
    }
    return $out;
};
$_usd = static fn(?float $v): string => $v === null ? '?' : '$' . number_format($v, 2, '.', ' ');

$page_title = t('story_title', 'Cestopis') . ' — ' . $_storyTitle;
require __DIR__ . '/layout_header.php';
?>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<link rel="stylesheet" href="<?= asset('css/story.css') ?>">

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6 pb-12 story-admin">
    <p class="story-back">
        <a href="detail.php?id=<?= (string)$trackId ?>">← <?= h(t('story_back_detail', 'Zpět na detail trasy')) ?></a>
    </p>
    <h1 class="story-h1">📖 <?= h(t('story_title', 'Cestopis')) ?> <span><?= h($_storyTitle) ?></span></h1>
    <p class="story-lead"><?= h(t('story_lead', 'Text výletu napíše jazykový model z dat trasy, názvů míst, bodů z mapy a případně z fotek. Každá verze se uloží; návštěvníci uvidí jen tu, kterou zveřejníš.')) ?></p>

    <?php if (!$_storyEnabled): ?>
        <div class="story-note story-note-warn" role="alert"><?= h(t('story_disabled_admin', 'Funkce Cestopis je vypnutá. Zapni ji v Administraci → Volitelné funkce.')) ?></div>
    <?php endif; ?>
    <?php if (!$_storyHasKey): ?>
        <div class="story-note story-note-warn" role="alert"><?= h(t('story_no_key_help', 'Chybí API klíč: doplň do souboru .env řádek ANTHROPIC_API_KEY=… (klíč z platform.claude.com). Bez něj nejde generovat; uložené verze zůstávají.')) ?></div>
    <?php endif; ?>
    <?php if ($_storyPhotos === 0): ?>
        <div class="story-note" role="status"><?= h(t('story_no_photos', 'Trasa nemá fotky s časem — není z čeho psát.')) ?></div>
    <?php endif; ?>

    <div class="story-grid">
        <form class="story-card" id="story-form" novalidate>
            <h2><?= h(t('story_new', 'Nová verze')) ?></h2>

            <fieldset>
                <legend><?= h(t('story_style', 'Styl')) ?></legend>
                <?php foreach (story_styles() as $_k => $_label): ?>
                    <label class="story-choice">
                        <input type="radio" name="style" value="<?= h($_k) ?>" <?= $_k === 'literary' ? 'checked' : '' ?>>
                        <?= h($_label) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <fieldset>
                <legend><?= h(t('story_model', 'Model')) ?></legend>
                <?php foreach (story_models() as $_k => $_label): ?>
                    <label class="story-choice">
                        <input type="radio" name="model" value="<?= h($_k) ?>" <?= $_k === 'claude-opus-5' ? 'checked' : '' ?>>
                        <?= h($_label) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <fieldset>
                <legend><?= h(t('story_sources', 'Podklady')) ?></legend>
                <label class="story-choice">
                    <input type="checkbox" name="with_map" value="1" checked>
                    <?= h(t('story_with_map', 'Body z mapy OpenStreetMap (vyhlídky, vrcholy, jeskyně…)')) ?>
                </label>
            </fieldset>

            <fieldset>
                <legend><?= h(t('story_photos', 'Fotky pro model')) ?></legend>
                <?php foreach (story_photo_modes() as $_k => $_label): ?>
                    <label class="story-choice">
                        <input type="radio" name="photo_mode" value="<?= h($_k) ?>" <?= $_k === 'none' ? 'checked' : '' ?>>
                        <?= h($_label) ?>
                    </label>
                <?php endforeach; ?>
                <p class="story-hint story-hint-warn" id="story-photo-warning" hidden>
                    <?= h(t('story_photos_warning', 'Fotky se odešlou poskytovateli modelu (Anthropic). Posílej jen trasy, kde na fotkách nejsou lidé.')) ?>
                </p>
            </fieldset>

            <p class="story-estimate" aria-live="polite">
                <?= h(t('story_estimate', 'Odhad ceny')) ?>:
                <strong id="story-est">…</strong>
                <small id="story-est-basis"></small>
            </p>

            <button type="submit" class="story-btn story-btn-primary" id="story-generate"
                <?= (!$_storyEnabled || !$_storyHasKey || $_storyPhotos === 0 || $_storyRunningId !== null) ? 'disabled' : '' ?>>
                ✨ <?= h(t('story_generate', 'Vygenerovat')) ?>
            </button>

            <div class="story-progress" id="story-progress" role="status" aria-live="polite" hidden>
                <span class="story-spinner" aria-hidden="true"></span>
                <span id="story-progress-text"></span>
            </div>
        </form>

        <aside class="story-card">
            <h2><?= h(t('story_spending', 'Útrata')) ?></h2>
            <p>
                <?= h(t('story_month', 'Tento měsíc')) ?>:
                <strong id="story-spent"><?= h($_usd($_storyMonth)) ?></strong>
                / <span id="story-cap-show"><?= h($_usd($_storyCap)) ?></span>
            </p>
            <div class="story-meter" aria-hidden="true">
                <span id="story-meter-bar" style="--story-fill: <?= (string)min(100, (int)round($_storyCap > 0 ? $_storyMonth / $_storyCap * 100 : 100)) ?>%"></span>
            </div>
            <form id="story-cap-form" class="story-cap-form" novalidate>
                <label>
                    <?= h(t('story_cap', 'Měsíční strop (USD)')) ?>
                    <input type="number" name="cap" min="0" max="1000" step="0.5" value="<?= h(number_format($_storyCap, 2, '.', '')) ?>">
                </label>
                <button type="submit" class="story-btn"><?= h(t('story_cap_save', 'Uložit')) ?></button>
            </form>
            <p class="story-hint"><?= h(t('story_cap_hint', 'Když by nová verze strop překročila, aplikace se zeptá, jestli přesto pokračovat. Útrata se počítá z uložených verzí včetně neúspěšných a smazaných; tvrdý limit nastav i u klíče na platform.claude.com.')) ?></p>
        </aside>
    </div>

    <form class="story-card story-editor" id="story-editor" novalidate>
        <h2 id="story-ed-title"><?= h(t('story_ed_new', 'Vlastní text (místopisný cestopis)')) ?></h2>
        <p class="story-hint"><?= h(t('story_ed_hint', 'Text napsaný mimo API — např. místopisný článek z ověřených pramenů. Uloží se jako nová verze (koncept), nic se neplatí. Odstavce odděl prázdným řádkem. Fotku vložíš kliknutím na náhled níže; značka nad prvním odstavcem určí úvodní fotku.')) ?></p>
        <input type="hidden" name="base_id" value="">
        <label class="story-ed-label" for="story-ed-text"><?= h(t('story_text', 'Text')) ?></label>
        <textarea id="story-ed-text" name="text" rows="16" spellcheck="true"></textarea>
        <label class="story-ed-label" for="story-ed-sources"><?= h(t('story_sources_title', 'Prameny')) ?></label>
        <textarea id="story-ed-sources" name="sources" rows="4" placeholder="Název | https://…"></textarea>
        <p class="story-hint"><?= h(t('story_ed_sources_hint', 'Jeden pramen na řádek: „Název | https://…“. Zobrazí se pod článkem.')) ?></p>

        <?php if ($_storyPickStops !== []): ?>
        <details class="story-picker">
            <summary><?= h(t('story_ed_photos', 'Vložit fotku')) ?> (<?= (string)count($_storyPickStops) ?> <?= h(t('story_ed_stops', 'zastávek')) ?>)</summary>
            <?php foreach ($_storyPickStops as $_ps): ?>
                <div class="story-picker-stop">
                    <strong><?= (string)$_ps['n'] ?>.</strong>
                    <span class="story-muted"><?= h($_ps['od']) ?><?= $_ps['do'] !== $_ps['od'] ? '–' . h($_ps['do']) : '' ?></span>
                    <div class="story-picker-photos">
                        <?php foreach ($_ps['photos'] as $_k => $_pp): $_code = '[foto ' . $_ps['n'] . '/' . ($_k + 1) . ']'; ?>
                            <button type="button" data-story-mark="<?= h($_code) ?>" title="<?= h($_code . ' · ' . $_pp['time']) ?>">
                                <img src="<?= h($_pp['thumb']) ?>" loading="lazy" alt="<?= h($_code) ?>">
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </details>
        <?php endif; ?>

        <div class="story-actions">
            <button type="submit" class="story-btn story-btn-primary" <?= !$_storyEnabled ? 'disabled' : '' ?>>💾 <?= h(t('story_ed_save', 'Uložit jako novou verzi')) ?></button>
            <button type="button" class="story-btn" id="story-ed-cancel" hidden><?= h(t('story_ed_cancel', 'Zrušit úpravu')) ?></button>
            <span class="story-hint" id="story-ed-status" role="status" aria-live="polite"></span>
        </div>
    </form>

    <div class="story-card story-versions" id="story-versions">
        <div class="story-versions-head">
            <h2><?= h(t('story_versions', 'Verze')) ?> (<?= (string)count($_storyVersions) ?>)</h2>
            <?php if (count($_storyVersions) > 1): ?>
                <button type="button" class="story-btn" id="story-side" aria-pressed="false">
                    <?= h(t('story_side_by_side', 'Vedle sebe')) ?>
                </button>
            <?php endif; ?>
        </div>

        <?php if ($_storyHasPublished && !$_storyVisitorsSee): ?>
            <p class="story-note" role="status"><?= h(t('story_visitors_hidden', 'Zveřejněnou verzi zatím vidíš jen ty. Návštěvníci ji uvidí, až v Administraci → Konfigurace přístupu zaškrtneš stránku „Cestopis“.')) ?></p>
        <?php endif; ?>

        <?php if ($_storyVersions === []): ?>
            <p class="story-hint"><?= h(t('story_no_versions', 'Zatím žádná verze.')) ?></p>
        <?php endif; ?>

        <div class="story-list" id="story-list">
        <?php foreach ($_storyVersions as $_i => $_s): ?>
            <article class="story-version story-status-<?= h($_s['status']) ?>" data-id="<?= (string)$_s['id'] ?>">
                <header>
                    <strong>#<?= (string)$_s['id'] ?></strong>
                    <span><?= h(story_variant_label($_s)) ?></span>
                    <span class="story-muted"><?= h(date('j. n. Y H:i', (int)strtotime((string)$_s['created_at']))) ?></span>
                    <?php if ($_s['cost_usd'] !== null): ?>
                        <span class="story-muted"><?= h($_usd($_s['cost_usd'])) ?></span>
                    <?php endif; ?>
                    <?php if ($_s['duration_s'] !== null): ?>
                        <span class="story-muted"><?= (string)$_s['duration_s'] ?> s</span>
                    <?php endif; ?>
                    <?php if ($_s['status'] === 'running'): ?>
                        <span class="story-badge story-badge-run"><?= h(t('story_running', 'Generuje se…')) ?></span>
                    <?php elseif ($_s['status'] === 'error'): ?>
                        <span class="story-badge story-badge-err"><?= h(t('story_badge_error', 'Chyba')) ?></span>
                    <?php elseif ($_s['is_published']): ?>
                        <span class="story-badge story-badge-pub"><?= h(t('story_published', 'Zveřejněno')) ?></span>
                    <?php else: ?>
                        <span class="story-badge"><?= h(t('story_draft', 'Koncept')) ?></span>
                    <?php endif; ?>
                </header>

                <?php if ($_s['status'] === 'error'): ?>
                    <p class="story-error"><?= h((string)$_s['error_message']) ?></p>
                <?php endif; ?>

                <?php if ($_s['status'] !== 'running'): ?>
                <div class="story-actions">
                    <?php if ($_s['status'] === 'done'): ?>
                        <a class="story-btn" href="story.php?id=<?= (string)$trackId ?>&amp;v=<?= (string)$_s['id'] ?>" target="_blank" rel="noopener">👁 <?= h(t('story_preview', 'Náhled')) ?></a>
                    <?php endif; ?>
                    <?php if ($_s['status'] === 'done' && !$_s['is_published']): ?>
                        <button type="button" class="story-btn" data-story-action="publish" data-id="<?= (string)$_s['id'] ?>">🌐 <?= h(t('story_publish', 'Zveřejnit')) ?></button>
                    <?php elseif ($_s['is_published']): ?>
                        <button type="button" class="story-btn" data-story-action="unpublish" data-id="<?= (string)$_s['id'] ?>"><?= h(t('story_unpublish', 'Stáhnout ze zveřejnění')) ?></button>
                    <?php endif; ?>
                    <?php if ($_s['status'] === 'done'): ?>
                        <button type="button" class="story-btn" data-story-edit="<?= (string)$_s['id'] ?>">✏️ <?= h(t('story_edit', 'Upravit')) ?></button>
                    <?php endif; ?>
                    <button type="button" class="story-btn story-btn-danger" data-story-action="delete" data-id="<?= (string)$_s['id'] ?>">🗑 <?= h(t('story_delete', 'Smazat')) ?></button>
                </div>
                <?php endif; ?>

                <?php if ($_s['status'] === 'done'): ?>
                    <details class="story-text-wrap" <?= $_i === 0 ? 'open' : '' ?>>
                        <summary><?= h(t('story_text', 'Text')) ?> (<?= (string)mb_strlen((string)$_s['story']) ?> <?= h(t('story_chars', 'znaků')) ?>)</summary>
                        <div class="story-text"><?= $_storyParagraphs($_s['story']) ?></div>
                    </details>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
    </div>
</section>

<script nonce="<?= csp_nonce() ?>">
window.GPX_STORY = {
    trackId:   <?= js_safe_json($trackId) ?>,
    csrfToken: <?= js_safe_json(csrf_token()) ?>,
    runningId: <?= js_safe_json($_storyRunningId) ?>,
    canGenerate: <?= js_safe_json($_storyEnabled && $_storyHasKey && $_storyPhotos > 0) ?>,
    i18n: {
        estHistory:      <?= js_safe_json(t('story_est_history', 'podle dřívějších verzí se stejnou volbou')) ?>,
        estFormula:      <?= js_safe_json(t('story_est_formula', 'hrubý odhad')) ?>,
        photosShort:     <?= js_safe_json(t('story_photos_short', 'fotek')) ?>,
        confirmGenerate: <?= js_safe_json(t('story_confirm_generate', 'Vygenerovat novou verzi? Odhadovaná cena: {usd}.')) ?>,
        confirmOverCap:  <?= js_safe_json(t('story_confirm_over_cap', 'Měsíční strop by byl překročen: tento měsíc utraceno {spent} ze stropu {cap}, odhad této verze {usd}. Přesto vygenerovat?')) ?>,
        confirmDelete:   <?= js_safe_json(t('story_confirm_delete', 'Smazat tuto verzi? Text nepůjde obnovit; její cena zůstane v útratě měsíce.')) ?>,
        running:         <?= js_safe_json(t('story_running', 'Generuje se…')) ?>,
        runningNote:     <?= js_safe_json(t('story_running_note', 'Stránku můžeš zavřít, text se uloží sám.')) ?>,
        failed:          <?= js_safe_json(t('story_failed', 'Generování selhalo')) ?>,
        error:           <?= js_safe_json(t('error', 'Chyba')) ?>
    }
};
</script>
<script nonce="<?= csp_nonce() ?>">
window.GPX_STORY_ED = {
    versions: <?= js_safe_json((object)$_storyEdVersions) ?>,
    i18n: {
        titleNew:   <?= js_safe_json(t('story_ed_new', 'Vlastní text (místopisný cestopis)')) ?>,
        titleEdit:  <?= js_safe_json(t('story_ed_edit', 'Úprava verze #{id} — uloží se jako nová verze')) ?>,
        saving:     <?= js_safe_json(t('story_ed_saving', 'Ukládám…')) ?>,
        saved:      <?= js_safe_json(t('story_ed_saved', 'Uloženo jako verze #{id}.')) ?>,
        discard:    <?= js_safe_json(t('story_ed_discard', 'Rozepsaný text v editoru se zahodí. Pokračovat?')) ?>,
        error:      <?= js_safe_json(t('error', 'Chyba')) ?>
    }
};
</script>
<script src="<?= asset('js/story-admin.js') ?>"></script>
<script src="<?= asset('js/story-editor.js') ?>"></script>

</div><?php require __DIR__ . '/layout_footer.php'; ?>
