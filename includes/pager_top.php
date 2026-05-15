<?php
$firstQ = build_query(['page'=>1]);
$prevQ  = build_query(['page'=> max(1, $page-1)]);
$nextQ  = build_query(['page'=> min($total_pages, $page+1)]);
$lastQ  = build_query(['page'=> $total_pages]);
?>
<div class="pager">
    <a class="pgbtn <?= $page<=1?'disabled':'' ?>" href="?<?= h($firstQ) ?>">⏮ První</a>
    <a class="pgbtn <?= $page<=1?'disabled':'' ?>" href="?<?= h($prevQ) ?>">◀ Předchozí</a>
    <span class="info">Strana <?= $page ?> / <?= $total_pages ?></span>
    <a class="pgbtn <?= $page>=$total_pages?'disabled':'' ?>" href="?<?= h($nextQ) ?>">Následující ▶</a>
    <a class="pgbtn <?= $page>=$total_pages?'disabled':'' ?>" href="?<?= h($lastQ) ?>">Poslední ⏭</a>
</div>
