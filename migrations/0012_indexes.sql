-- ============================================================
--  0012_indexes.sql — Critical indexes for hot-path queries
--  MySQL 5.7 / MariaDB 10.3 compatible.
--  Uses information_schema check pattern (idempotent).
--
--  Covers: DB-3 (stats.php records), DB-5 (photo_count subquery),
--          DB-7 (category_id FK), PERF-15
-- ============================================================

-- ------------------------------------------------------------
--  1. track_photos (track_id, visible)
--     Supports the correlated subquery in index_data.php:
--       SELECT COUNT(*) FROM track_photos p
--       WHERE p.track_id = t.id AND (p.visible IS NULL OR p.visible = 1)
--     Composite index eliminates the full track_photos scan per row.
-- ------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME  = 'track_photos'
             AND INDEX_NAME  = 'idx_tp_track_visible');
SET @sql := IF(@x = 0,
    'CREATE INDEX idx_tp_track_visible ON track_photos (track_id, visible)',
    'SELECT "idx_tp_track_visible exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
--  2. track_categories (category_id)
--     Supports EXISTS subqueries joining categories → track_categories
--     by category_id.  The PK covers (track_id, category_id); a
--     separate index on category_id alone enables efficient lookup
--     from the categories side.
-- ------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME  = 'track_categories'
             AND INDEX_NAME  = 'idx_tc_category');
SET @sql := IF(@x = 0,
    'CREATE INDEX idx_tc_category ON track_categories (category_id)',
    'SELECT "idx_tc_category exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
--  3. tracks (distance_km)
--     Supports stats.php: ORDER BY distance_km DESC LIMIT 1
-- ------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME  = 'tracks'
             AND INDEX_NAME  = 'idx_tracks_distance_km');
SET @sql := IF(@x = 0,
    'CREATE INDEX idx_tracks_distance_km ON tracks (distance_km)',
    'SELECT "idx_tracks_distance_km exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
--  4. tracks (ascent)
--     Supports stats.php: ORDER BY ascent DESC LIMIT 1
-- ------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME  = 'tracks'
             AND INDEX_NAME  = 'idx_tracks_ascent');
SET @sql := IF(@x = 0,
    'CREATE INDEX idx_tracks_ascent ON tracks (ascent)',
    'SELECT "idx_tracks_ascent exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
--  5. tracks (elevation_max)
--     Supports stats.php: ORDER BY elevation_max DESC LIMIT 1
-- ------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME  = 'tracks'
             AND INDEX_NAME  = 'idx_tracks_elevation_max');
SET @sql := IF(@x = 0,
    'CREATE INDEX idx_tracks_elevation_max ON tracks (elevation_max)',
    'SELECT "idx_tracks_elevation_max exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
--  6. tracks (speed_max)
--     Supports stats.php: WHERE speed_max IS NOT NULL ORDER BY speed_max DESC LIMIT 1
-- ------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME  = 'tracks'
             AND INDEX_NAME  = 'idx_tracks_speed_max');
SET @sql := IF(@x = 0,
    'CREATE INDEX idx_tracks_speed_max ON tracks (speed_max)',
    'SELECT "idx_tracks_speed_max exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
--  7. tracks (duration)
--     Supports stats.php: WHERE duration IS NOT NULL ORDER BY duration DESC LIMIT 1
-- ------------------------------------------------------------
SET @x := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME  = 'tracks'
             AND INDEX_NAME  = 'idx_tracks_duration');
SET @sql := IF(@x = 0,
    'CREATE INDEX idx_tracks_duration ON tracks (duration)',
    'SELECT "idx_tracks_duration exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
--  EXPLAIN orientation for top 3 hot-path queries
--  (verified on schema after 0011; run EXPLAIN ANALYZE on real data)
-- ============================================================
--
--  Query 1 — photo_count correlated subquery (index_data.php line ~119):
--    SELECT COUNT(*) FROM track_photos p
--    WHERE p.track_id = t.id AND (p.visible IS NULL OR p.visible = 1)
--
--    Before: type=ref on idx_tp_track (track_id only), then filter on visible.
--            For 50 rows/page × N photos each → 50 partial scans.
--    After:  type=ref on idx_tp_track_visible (track_id, visible).
--            MySQL can use the composite index to satisfy both predicates;
--            IS NULL branch still hits track_id lookup, visible filter
--            is on the second key part — rows examined reduced proportionally.
--
--  Query 2 — stats.php records (5 separate single-row lookups):
--    SELECT id, track_name, filename, distance_km
--    FROM tracks ORDER BY distance_km DESC LIMIT 1
--
--    Before: type=ALL, filesort on distance_km (~N rows examined).
--    After:  type=index on idx_tracks_distance_km, no filesort.
--            LIMIT 1 means the optimizer reads exactly 1 index entry (DESC).
--            Same reasoning applies for ascent, elevation_max, speed_max, duration.
--
--  Query 3 — category EXISTS subquery (helpers.php buildFilterSQL):
--    EXISTS (SELECT 1 FROM track_categories tc
--            JOIN categories c ON c.id = tc.category_id
--            WHERE tc.track_id = {prefix}id AND c.name IN (...))
--
--    Before: The JOIN uses categories PK on c.id, but track_categories
--            scan is driven by track_id (PK covers it).  The reverse path
--            (filter by category name → join to track_categories) has no
--            index on category_id for the second table access.
--    After:  idx_tc_category on track_categories(category_id) allows
--            MySQL to look up membership from the categories side efficiently,
--            particularly when the category set is large.
-- ============================================================
