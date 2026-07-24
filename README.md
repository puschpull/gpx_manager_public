# 🗺 GPX Manager

A web application for managing GPS tracks and photos from hikes and outdoor activities.

> 🇨🇿 [Instalační návod (česky)](instructions/cs.md) &nbsp;|&nbsp; 🇬🇧 [Installation guide (English)](instructions/en.md)

---

## What's new

### 2026-07 — planning, replay and real radar

- **Trip planner** — click a route on the map, get it snapped to real paths via routing;
  save plans, see weather for the planned day, estimate time from your own pace, and
  export to GPX for a Garmin handheld. Manual segments let you draw a straight shortcut
  across a field and mix it with auto-routed parts.
- **Hike replay** — a walking figure travels the track by its real timestamps, in sync with
  the elevation profile. Optional live weather at the hiker, a preview of the photo they
  are passing, and a precipitation overlay.
- **Real weather radar** — the precipitation overlay can use actual ČHMÚ radar composites
  (5-minute steps) instead of the hourly model estimate. Frames are fetched on demand and
  stored per track; the ČHMÚ archive only reaches ~7 days back.
- **Photos Nearby** — find photos around a point, the photo counterpart of Tracks Nearby.
- **More map layers** — CyclOSM, ČÚZK ZTM, Waymarked Trails, Mapy.com, Mapillary coverage,
  Wikimedia Commons photos and OSM POIs, all available on every map in the app.
- **Configurable top menu** — order and visibility of navigation items set in Administration.
- **Optional features** — replay, weather, radar and passing photos can each be switched
  off independently in Administration.

### 2026-06 — virtual tracks

- **Virtual tracks** — a route reconstructed from GPS photos alone, for trips with no GPX
  recording. Photos are clustered by time and location, the track is created from their
  positions, and it can be renamed, split, merged and given its own thumbnail.

### v2.0 — audit and refactor

- **Security audit** — 166 findings fixed: XXE prevention, CSRF on all mutating endpoints,
  path traversal hardening, XSS escaping, SQL injection prevention via prepared statements
- **Migration system** — numbered migrations with a CLI runner (`php migrate.php`)
- **Refactored architecture** — REST API endpoints (`api/`), shared PHP helpers, PSR-12,
  `declare(strict_types=1)`
- **Batch photo upload** — up to 100 photos per batch, ZIP archive support (Google Takeout compatible)
- **Photo import from local folder** — scan server directory, EXIF batch read, auto-assign to
  tracks by GPS + time
- **Accessibility** — WCAG 2.2 AA: skip links, ARIA landmarks, focus management, keyboard
  navigation, screen reader support
- **Modern UI** — Tailwind CSS v4 + Alpine.js 3.x, light/dark mode
- **Performance** — N+1 query fixes, centroid index, OSM tile cache, pagination
- **i18n** — 8 languages fully consistent (Czech, English, German, Slovak, Spanish, French,
  Italian, Polish)

---

## Features

- **GPX import** — single files or batch via ZIP archive
- **Interactive map** for each track with elevation profile, plus a replay of the hike
- **Virtual tracks** — routes reconstructed from GPS photos when no GPX exists
- **Trip planner** — plan a route, check the weather, export GPX for a GPS device
- **Photos on the track** — GPS EXIF → automatic assignment to track, lightbox gallery, timeline
- **Photos Nearby** and **Tracks Nearby** — find what you photographed or walked around a point
- **Statistics** — track overview, favourites, categories, difficulty, activity type
- **Filter & compare** — advanced filtering, side-by-side track comparison on map
- **Heatmap**, **photo heatmap** and **activity calendar**
- **GPX Cleaner** — strip GPS noise, stationary points and elevation spikes
- **Visitor mode** — public view-only access with configurable page visibility
- **Multilingual UI** — Czech, English, German, Slovak, Spanish, French, Italian, Polish
- **Light / dark mode**

---

## Requirements

| Component | Minimum | Recommended |
|---|---|---|
| PHP | **8.0** | 8.2+ |
| MySQL | 5.7 | 8.0+ |
| MariaDB | 10.3 | 10.11+ |
| Apache / Nginx | any current | — |

PHP extensions: `pdo_mysql`, `simplexml`, `gd`, `exif`, `zip`

Optional API keys (in `.env`) unlock extra map layers — Thunderforest, Mapy.com and
Mapillary. Without a key the layer simply is not offered; nothing else breaks.

---

## Quick Start

1. Copy files to the server (or into the WampServer `www/` folder)
2. Create an empty MySQL database (e.g. `gpx_manager`)
3. Create an empty file named `.setup-allowed` next to `setup.php` (security gate)
4. Open `http://localhost/gpx_manager/setup.php`
5. Enter DB credentials, admin password and your IP address
6. Run `php migrate.php` to apply any schema updates
7. Log in via `login.php`

📖 Full guide → [instructions/en.md](instructions/en.md)

---

## Project Structure

```
gpx_manager/
├── setup.php          ← installation wizard (deletes itself after install)
├── .env.example       ← configuration template
├── install.sql        ← SQL schema for manual import
├── migrate.php        ← CLI migration runner
├── config.php         ← loads configuration from .env
├── index.php          ← track overview
├── import.php         ← GPX import
├── photos.php         ← photo management
├── planner.php        ← trip planner
├── ...
├── api/               ← JSON endpoints (photos, planner, radar, POI, virtual tracks)
├── includes/          ← PHP backend logic (not reachable from the web)
├── migrations/        ← numbered SQL migrations
├── assets/css/        ← Tailwind build output (app.css)
├── css/               ← legacy page styles
├── js/                ← JavaScript modules
├── lang/              ← translations (8 languages)
├── tools/             ← CLI maintenance scripts
├── instructions/      ← installation & user guides (cs, en)
└── uploads/           ← GPX files, photos, thumbnails, caches (writable by server)
```

---

## Maintenance

```bash
php migrate.php                          # apply pending schema migrations
php scripts/lint_lang.php                # check translation keys across 8 languages
php tools/cleanup_uploads.php            # dry-run: expired caches, orphaned radar frames
php tools/cleanup_orphan_thumbs.php      # dry-run: thumbnails with no matching track
```

Both cleanup tools change nothing until you re-run them with `--apply`.

---

## License

This project is intended for personal and non-commercial use.
