<?php
declare(strict_types=1);

/**
 * Cestopis — lehké dotazy pro odkaz v detailu trasy (bez tříd src/Cestopis,
 * detail se načítá často).
 */

/**
 * Smí aktuální uživatel cestopisy vidět? Funkce zapnutá (Administrace →
 * Volitelné funkce) a pro návštěvníka navíc stránka „story" ve visible_pages.
 */
function story_visible_to_user(): bool {
    if (!feature_enabled('story')) {
        return false;
    }
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }
    return in_array('story', (array)get_app_config('visible_pages', all_pages()), true);
}

/** Id zveřejněné verze cestopisu trasy, nebo null. */
function story_published_id(PDO $pdo, int $trackId): ?int {
    try {
        $st = $pdo->prepare(
            "SELECT id FROM track_stories WHERE track_id = ? AND is_published = 1 AND status = 'done' LIMIT 1"
        );
        $st->execute([$trackId]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    } catch (PDOException $e) {
        // Tabulka chybí — kód je novější než databáze (migrace 0020 neproběhla)
        return null;
    }
}
