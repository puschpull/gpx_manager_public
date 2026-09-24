<?php
/**
 * Karta „Nasazení" v Administraci — jen pro admina (admin.php je za auth.php).
 * Data připravuje deploy_status() v includes/deploy_status.php ($deploy).
 * Ať není potřeba SSH: na kterém commitu web běží, jestli odpovídá GitHubu,
 * konec deploy.log a hlášení zablokovaného obsahu (csp.log).
 */
$_d = $deploy ?? [];
$_short = static fn(?string $sha): string => $sha ? substr($sha, 0, 7) : '—';
$_badge = static function (string $state, string $text): string {
    $colors = ['ok' => '#2e7d32', 'warn' => '#ef6c00', 'error' => '#c62828', 'info' => 'var(--text-muted)'];
    return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;'
         . 'color:#fff;background:' . ($colors[$state] ?? $colors['info']) . ';">' . h($text) . '</span>';
};
$_isProd = defined('APP_ENV') && APP_ENV !== 'local';
?>
<div class="admin-card" style="grid-column:1 / -1;">
    <h3>🚀 <?= h(t('admin_deploy', 'Nasazení')) ?></h3>

    <?php if (empty($_d['git'])): ?>
        <p style="font-size:13px;color:var(--text-muted);margin:0;">
            <?= h(t('deploy_no_git', 'Tato instalace neběží z gitu — stav nasazení nelze zjistit.')) ?>
        </p>
    <?php else:
        $_same   = $_d['head'] && $_d['remote'] && $_d['head'] === $_d['remote'];
        $_info   = $_d['info'] ?? null;
        $_ago    = $_d['fetched_at'] ? (int)floor((time() - $_d['fetched_at']) / 60) : null;
        // Cron kontroluje GitHub každé 3 minuty; po 10 minutách ticha je něco špatně.
        // Jen tam, kde nasazovací log existuje — ruční git pull by jinak „strašil".
        $_stale  = $_isProd && !empty($_d['deploy_readable']) && $_ago !== null && $_ago > 10;
    ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px 24px;font-size:13px;align-items:baseline;">
            <div>
                <?= h(t('deploy_commit', 'Web běží na')) ?>:
                <code style="font-size:13px;font-weight:600;"><?= h($_short($_d['head'])) ?></code>
                <?php if (!empty($_info['subject'])): ?>
                    <span style="color:var(--text-muted);">— <?= h(mb_strimwidth($_info['subject'], 0, 90, '…')) ?></span>
                <?php endif; ?>
                <?php if (!empty($_info['time'])): ?>
                    <span style="color:var(--text-muted);">(<?= date('j. n. Y H:i', (int)$_info['time']) ?>)</span>
                <?php endif; ?>
            </div>
            <div>
                <?= h(t('deploy_github', 'GitHub')) ?>:
                <code style="font-size:13px;"><?= h($_short($_d['remote'])) ?></code>
                <?php if ($_same): ?>
                    <?= $_badge('ok', t('deploy_uptodate', 'aktuální')) ?>
                <?php elseif ($_d['remote']): ?>
                    <?= $_badge('warn', t('deploy_behind', 'na GitHubu je jiná verze — nasazení čeká nebo selhalo, viz log níže')) ?>
                <?php endif; ?>
            </div>
            <?php if ($_ago !== null): ?>
                <div style="color:<?= $_stale ? '#c62828' : 'var(--text-muted)' ?>;">
                    <?= h(t('deploy_last_check', 'poslední kontrola GitHubu')) ?>:
                    <?= date('j. n. H:i', (int)$_d['fetched_at']) ?>
                    <?php if ($_stale): ?>
                        — <?= h(t('deploy_stale', 'déle než 10 minut, nasazovací cron možná neběží')) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:14px;">
        <div>
            <h4 style="font-size:12px;margin:0 0 6px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;">
                <?= h(t('deploy_log_title', 'Poslední záznamy nasazení')) ?>
            </h4>
            <?php if (empty($_d['deploy_readable'])): ?>
                <p style="font-size:12px;color:var(--text-muted);margin:0;">
                    <?= h(t('deploy_log_na', 'Log nasazení není z webu čitelný.')) ?>
                    <code style="font-size:11px;"><?= h((string)($_d['deploy_log'] ?? '')) ?></code>
                </p>
            <?php elseif (empty($_d['deploy_lines'])): ?>
                <p style="font-size:12px;color:var(--text-muted);margin:0;"><?= h(t('deploy_log_empty', 'Log nasazení je prázdný.')) ?></p>
            <?php else: ?>
                <ul style="list-style:none;margin:0;padding:0;font-size:12px;display:flex;flex-direction:column;gap:4px;">
                    <?php foreach (array_reverse($_d['deploy_lines']) as $_l): ?>
                        <li style="display:flex;gap:8px;align-items:baseline;">
                            <span style="color:var(--text-muted);white-space:nowrap;min-width:78px;"><?= h($_l['time']) ?></span>
                            <span style="width:9px;height:9px;border-radius:50%;flex:none;background:<?=
                                ['ok' => '#2e7d32', 'warn' => '#ef6c00', 'error' => '#c62828'][$_l['state']] ?? '#9e9e9e' ?>;"></span>
                            <span style="word-break:break-word;"><?= h(mb_strimwidth($_l['text'], 0, 220, '…')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div>
            <h4 style="font-size:12px;margin:0 0 6px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;">
                <?= h(t('deploy_csp_title', 'Zablokovaný obsah (CSP)')) ?>
                <?php if (!empty($_d['csp_count'])): ?>
                    · <?= h(str_replace('{n}', (string)$_d['csp_count'], t('deploy_csp_count', 'hlášení celkem: {n}'))) ?>
                <?php endif; ?>
            </h4>
            <?php if (empty($_d['csp_lines'])): ?>
                <p style="font-size:12px;color:var(--text-muted);margin:0;">
                    <?= h(t('deploy_csp_none', 'Žádná hlášení — prohlížeče nic neblokovaly.')) ?>
                </p>
            <?php else: ?>
                <ul style="list-style:none;margin:0;padding:0;font-size:12px;display:flex;flex-direction:column;gap:4px;">
                    <?php foreach (array_reverse($_d['csp_lines']) as $_c): ?>
                        <li>
                            <span style="color:var(--text-muted);"><?= h($_c['time']) ?></span>
                            <code style="font-size:11px;"><?= h($_c['directive']) ?></code>
                            <?= h($_c['page']) ?>
                            <?php if ($_c['sample'] !== ''): ?>
                                <span style="color:var(--text-muted);">— „<?= h(mb_strimwidth($_c['sample'], 0, 60, '…')) ?>"</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p style="font-size:11px;color:var(--text-muted);margin:6px 0 0;">
                    <?= h(t('deploy_csp_hint', 'Každé hlášení = něco, co prohlížeč nespustil nebo nenačetl. Záznamy označené TEST jsou zkušební.')) ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
