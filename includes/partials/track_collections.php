<?php
declare(strict_types=1);

/**
 * Track detail: collections the track belongs to (links) and, for the admin,
 * a picker to add it to another collection or remove it (js/track-collections.js).
 * Expects $pdo, $track, $_isAdmin. Renders nothing when collections are hidden
 * for the visitor, or the table does not exist yet (before migration 0022).
 */

require_once __DIR__ . '/../collections_helper.php';

if (collections_visible()) {
    $_tcMember = collections_of_track($pdo, (int)$track['id'], $_isAdmin);
    $_tcAll    = $_isAdmin ? collections_names($pdo) : [];
    $_tcFree   = array_diff_key($_tcAll, array_column($_tcMember, 'name', 'id'));

    if ($_tcMember || $_tcAll) { ?>
<div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-forest-700/70 dark:text-sand-100/70" id="trackCollections"
     data-track-id="<?= (int)$track['id'] ?>" data-csrf="<?= $_isAdmin ? h(csrf_token()) : '' ?>">
    <i data-lucide="library" class="w-3.5 h-3.5" aria-hidden="true"></i>
    <span><?= h(t('col_in', 'Kolekce:')) ?></span>
    <?php foreach ($_tcMember as $_c): ?>
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-forest-100 dark:bg-forest-800 text-forest-700 dark:text-sand-100 text-xs font-medium">
            <a href="collection.php?id=<?= $_c['id'] ?>" class="hover:text-terracotta-500"><?= h($_c['name']) ?></a>
            <?php if ($_isAdmin): ?>
                <button type="button" data-tc-remove="<?= $_c['id'] ?>" class="opacity-60" style="cursor:pointer"
                        title="<?= h(t('col_remove', 'Odebrat z kolekce')) ?>" aria-label="<?= h(t('col_remove', 'Odebrat z kolekce')) ?>">✕</button>
            <?php endif; ?>
        </span>
    <?php endforeach; ?>
    <?php if ($_isAdmin && $_tcFree): ?>
        <select id="tcAdd" aria-label="<?= h(t('col_add_to', 'Přidat do kolekce')) ?>" class="text-xs rounded-md border border-sand-200 dark:border-forest-700" style="padding:2px 6px; background:transparent; color:inherit">
            <option value=""><?= h(t('col_add_to', 'Přidat do kolekce')) ?>…</option>
            <?php foreach ($_tcFree as $_cid => $_cname): ?>
                <option value="<?= (int)$_cid ?>"><?= h($_cname) ?></option>
            <?php endforeach; ?>
        </select>
    <?php elseif ($_isAdmin && !$_tcAll): ?>
        <a href="collections.php" class="text-xs hover:text-terracotta-500"><?= h(t('col_new', '+ Nová kolekce')) ?></a>
    <?php endif; ?>
</div>
<?php if ($_isAdmin): ?>
<script defer src="<?= asset('js/track-collections.js') ?>"></script>
<?php endif; ?>
<?php
    }
}
