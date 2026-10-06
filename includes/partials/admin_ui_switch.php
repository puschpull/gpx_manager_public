<?php
declare(strict_types=1);

/**
 * Přepínač vzhledu administrace (Nový / Původní) — na obou verzích stránky.
 * Odesílá POST na admin.php, volba se uloží do app_config 'admin_ui'.
 * Styly jsou tady, protože každá verze má jiné CSS.
 */
function render_admin_ui_switch(string $current): void
{
    $opts = ['new' => t('adm2_ui_new', 'Nový'), 'classic' => t('adm2_ui_classic', 'Původní')];
    ?>
    <style>
        .admin-ui-switch { display: inline-flex; align-items: center; gap: 8px; margin: 0; font-size: 13px; }
        .admin-ui-switch-label { color: #55665d; }
        .admin-ui-switch-group { display: inline-flex; padding: 3px; border-radius: 10px; background: #e8e0d2; }
        .admin-ui-switch button { border: 0; background: transparent; color: #2d4a3e; font: inherit; font-weight: 600;
            padding: 5px 12px; border-radius: 8px; cursor: pointer; line-height: 1.2; }
        .admin-ui-switch button:hover { background: #f5f1ea; }
        .admin-ui-switch button[aria-pressed="true"] { background: #2d4a3e; color: #fff; cursor: default; }
        .admin-ui-switch button:focus-visible { outline: 2px solid #a85f2f; outline-offset: 2px; }
        html.dark .admin-ui-switch-label { color: #b9c4bc; }
        html.dark .admin-ui-switch-group { background: #243028; }
        html.dark .admin-ui-switch button { color: #f5f1ea; }
        html.dark .admin-ui-switch button:hover { background: #2b3a31; }
        html.dark .admin-ui-switch button[aria-pressed="true"] { background: #5b8a75; color: #0f1713; }
    </style>
    <form method="post" action="admin.php" class="admin-ui-switch">
        <?= csrf_field() ?>
        <span class="admin-ui-switch-label" id="admin-ui-switch-label"><?= h(t('adm2_ui_label', 'Vzhled administrace')) ?>:</span>
        <span class="admin-ui-switch-group" role="group" aria-labelledby="admin-ui-switch-label">
            <?php foreach ($opts as $val => $label): $on = $val === $current; ?>
                <button type="<?= $on ? 'button' : 'submit' ?>" name="set_admin_ui" value="<?= h($val) ?>"
                        aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= h($label) ?></button>
            <?php endforeach; ?>
        </span>
    </form>
    <?php
}
