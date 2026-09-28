<?php
declare(strict_types=1);

namespace GpxManager\Cestopis;

use PDO;

final class Repository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function track(int $trackId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, track_name, alt_title, note, place_name, activity_type, difficulty,
                    date_start, date_end, distance_km, ascent, descent,
                    elevation_min, elevation_max, duration, moving_time, stopped_time,
                    speed_avg, speed_max, device
             FROM tracks WHERE id = ?'
        );
        $stmt->execute([$trackId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException("Trasa {$trackId} v databázi není.");
        }

        return $row;
    }

    /** @return list<Photo> */
    public function photos(int $trackId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, taken_at, lat, lon, altitude, caption, filename
             FROM track_photos
             WHERE track_id = ? AND visible = 1 AND taken_at IS NOT NULL
             ORDER BY taken_at'
        );
        $stmt->execute([$trackId]);

        $photos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $photos[] = new Photo(
                id: (int) $row['id'],
                takenAt: new \DateTimeImmutable((string) $row['taken_at']),
                lat: $row['lat'] !== null ? (float) $row['lat'] : null,
                lon: $row['lon'] !== null ? (float) $row['lon'] : null,
                altitude: $row['altitude'] !== null ? (float) $row['altitude'] : null,
                caption: $row['caption'],
                filename: (string) $row['filename'],
            );
        }

        return $photos;
    }

    /** @return list<string> */
    public function categories(int $trackId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.name FROM categories c
             JOIN track_categories tc ON tc.category_id = c.id
             WHERE tc.track_id = ? ORDER BY c.name'
        );
        $stmt->execute([$trackId]);

        return array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ------------------------------------------------------------------
    //  Verze cestopisu (track_stories)
    // ------------------------------------------------------------------

    /** Po této době se „running" bere jako spadlé (proces umřel bez zápisu). */
    private const STALE_MINUTES = 15;

    private const COLUMNS = 'id, track_id, style, model, with_map, photo_mode, photos_sent, status,
        error_message, is_published, input_tokens, output_tokens, cost_usd, duration_s,
        facts_json, story, created_at, finished_at';

    /**
     * Založí řádek „running" — dřív, než se zavolá API. Když už pro trasu
     * jedno generování běží, vrátí null (druhé kliknutí nesmí platit znovu).
     */
    public function start(int $trackId, string $style, string $model, bool $withMap, string $photoMode): ?int
    {
        $this->expireStale();

        // Zámek na trasu: dva souběžné požadavky nesmí oba projít kontrolou
        $lock = 'gpx_story_' . $trackId;
        $get = $this->pdo->prepare('SELECT GET_LOCK(?, 5)');
        $get->execute([$lock]);
        if ((int) $get->fetchColumn() !== 1) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM track_stories WHERE track_id = ? AND status = 'running'");
            $stmt->execute([$trackId]);
            if ((int) $stmt->fetchColumn() > 0) {
                return null;
            }
            $this->pdo->prepare(
                'INSERT INTO track_stories (track_id, style, model, with_map, photo_mode) VALUES (?, ?, ?, ?, ?)'
            )->execute([$trackId, $style, $model, $withMap ? 1 : 0, $photoMode]);

            return (int) $this->pdo->lastInsertId();
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }

    /**
     * @param array<string,mixed> $facts
     * @param array{input_tokens:int, output_tokens:int, model:string} $usage
     */
    public function finish(int $id, string $story, array $facts, array $usage, ?float $cost, int $seconds, int $photosSent): void
    {
        $this->pdo->prepare(
            "UPDATE track_stories SET status = 'done', story = ?, facts_json = ?, model = ?,
                    input_tokens = ?, output_tokens = ?, cost_usd = ?, duration_s = ?, photos_sent = ?,
                    finished_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        )->execute([
            $story,
            json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $usage['model'],
            $usage['input_tokens'],
            $usage['output_tokens'],
            $cost,
            min($seconds, 65535),
            $photosSent,
            $id,
        ]);
    }

    /**
     * Neúspěch. Když API už odpovědělo (odmítnutí, uříznutí), spotřeba se
     * zapíše také — zaplatilo se a počítá se do měsíčního stropu.
     * @param array{input_tokens:int, output_tokens:int, model:string}|null $usage
     */
    public function fail(int $id, string $message, ?array $usage, ?float $cost, int $seconds): void
    {
        $this->pdo->prepare(
            "UPDATE track_stories SET status = 'error', error_message = ?,
                    input_tokens = ?, output_tokens = ?, cost_usd = ?, duration_s = ?,
                    finished_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        )->execute([
            mb_substr($message, 0, 500),
            $usage['input_tokens'] ?? null,
            $usage['output_tokens'] ?? null,
            $cost,
            min($seconds, 65535),
            $id,
        ]);
    }

    /** Spadlé generování (proces skončil bez zápisu) označí jako chybu. */
    public function expireStale(): void
    {
        $this->pdo->exec(
            "UPDATE track_stories SET status = 'error', error_message = 'Generování nedoběhlo (přerušeno).',
                    finished_at = CURRENT_TIMESTAMP
             WHERE status = 'running' AND created_at < NOW() - INTERVAL " . self::STALE_MINUTES . ' MINUTE'
        );
    }

    /**
     * Verze k trase, od nejstarší.
     * @return list<array<string,mixed>>
     */
    public function stories(int $trackId, bool $onlyDone = false): array
    {
        $this->expireStale();
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM track_stories WHERE track_id = ? AND status <> 'deleted'"
            . ($onlyDone ? " AND status = 'done'" : '') . ' ORDER BY id'
        );
        $stmt->execute([$trackId]);

        return array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function story(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM track_stories WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** Zveřejněná verze trasy (tu vidí návštěvník), nebo null. @return array<string,mixed>|null */
    public function published(int $trackId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM track_stories
             WHERE track_id = ? AND is_published = 1 AND status = 'done' LIMIT 1"
        );
        $stmt->execute([$trackId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** Zveřejní jednu verzi; ostatní verze téže trasy stáhne (veřejná je nejvýš jedna). */
    public function publish(int $id): bool
    {
        $story = $this->story($id);
        if ($story === null || $story['status'] !== 'done') {
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE track_stories SET is_published = 0 WHERE track_id = ?')
                ->execute([$story['track_id']]);
            $this->pdo->prepare('UPDATE track_stories SET is_published = 1 WHERE id = ?')->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return true;
    }

    public function unpublish(int $trackId): void
    {
        $this->pdo->prepare('UPDATE track_stories SET is_published = 0 WHERE track_id = ?')->execute([$trackId]);
    }

    /**
     * Smaže verzi: text a podklady pryč, řádek zůstane se stavem „deleted" —
     * jinak by smazáním zmizela útrata z měsíčního součtu a strop by šel obejít.
     * Běžící generování smazat nejde (jeho výsledek by se neměl kam zapsat).
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE track_stories SET status = 'deleted', story = NULL, facts_json = NULL,
                    error_message = NULL, is_published = 0
             WHERE id = ? AND status IN ('done', 'error')"
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /** Útrata v USD od začátku aktuálního měsíce (včetně neúspěšných volání). */
    public function monthSpend(): float
    {
        return (float) $this->pdo->query(
            "SELECT COALESCE(SUM(cost_usd), 0) FROM track_stories
             WHERE created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')"
        )->fetchColumn();
    }

    /**
     * Průměrná skutečná spotřeba dřívějších verzí se stejnou variantou —
     * podklad pro odhad ceny. Null, když taková verze ještě nebyla.
     * @return array{input_tokens:float, output_tokens:float, count:int}|null
     */
    public function averageUsage(string $style, string $model, bool $withMap, string $photoMode): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT AVG(input_tokens) AS i, AVG(output_tokens) AS o, COUNT(*) AS n FROM track_stories
             WHERE status = 'done' AND input_tokens IS NOT NULL
               AND style = ? AND model LIKE ? AND with_map = ? AND photo_mode = ?"
        );
        $stmt->execute([$style, $model . '%', $withMap ? 1 : 0, $photoMode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return ($row === false || (int) $row['n'] === 0) ? null
            : ['input_tokens' => (float) $row['i'], 'output_tokens' => (float) $row['o'], 'count' => (int) $row['n']];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        foreach (['id', 'track_id', 'photos_sent', 'input_tokens', 'output_tokens', 'duration_s'] as $k) {
            $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        }
        $row['with_map'] = (bool) $row['with_map'];
        $row['is_published'] = (bool) $row['is_published'];
        $row['cost_usd'] = $row['cost_usd'] === null ? null : (float) $row['cost_usd'];
        $row['facts'] = $row['facts_json'] !== null ? (json_decode((string) $row['facts_json'], true) ?: []) : [];
        unset($row['facts_json']);

        return $row;
    }
}
