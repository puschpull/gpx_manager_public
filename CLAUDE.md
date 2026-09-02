# CLAUDE.md — GPX Manager

> Kontext projektu pro Claude Code. Načte se automaticky při startu v tomto repu, takže sem patří jen to,
> co je při práci opravdu potřeba vědět — ne historie. Ta je v `_archiv/`.

---

## Project context

**Název projektu**: GPX Manager
**Stručný popis**: Self-hosted PHP webová aplikace pro správu GPS tras a fotek z výletů — import GPX, mapy, statistiky, fotogalerie, vícejazyčné UI, světlý/tmavý režim. Cílový uživatel: jednotlivec nebo malá skupina, nasazení na sdílený hosting (Webglobe v ČR) nebo lokálně (WAMP).
**Stage**: production (live na vlastní doméně autora, single-tenant per instalace)
**Historie**: audit z 7/2026 (166 nálezů, 30 tasků) je hotový a odložený v `_archiv/` — do práce nevstupuje

---

## Stack

**Backend**: PHP 8.0+ (cíl 8.2+), žádný framework, čistý monolit
**Database**: MySQL 5.7+ / MariaDB 10.3+ přes PDO (prepared statements)
**Frontend**:
- Server-rendered HTML přes inline PHP
- Tailwind CSS v4 (`assets/css/input.css` → `assets/css/app.css`) — postupně migruje vrstvy
- Alpine.js 3.x pro interaktivitu v novém UI
- Legacy CSS proměnné pro světlý/tmavý režim v `css/style.css` (`:root` + `html.dark`) — koexistuje s Tailwind
- Vanilla JS v `js/` (žádný build pipeline, soubory servovány raw)
**Mapy**: Leaflet 1.9.4 (CDN), leaflet-gpx, leaflet.heat, leaflet.vectorgrid (Mapillary)
**Grafy**: Chart.js 4.x (CDN)
**Ikony**: Lucide (CDN)
**Auth**: IP allowlist (z `.env ADMIN_IPS`) + session-based login s bcrypt hashem (`password_hash`/`password_verify`), CSRF tokeny ručně přes `includes/security.php`
**Hosting**: Apache + mod_rewrite (`.htaccess`), shared hosting (Webglobe) NEBO lokální WAMP/Docker
**Observability**: PHP `error_log` (cíl: přesunout mimo webroot, do `logs/`)
**i18n**: vlastní pole v `lang/{cs,en,de,sk,es,fr,pl,it}.php`, funkce `t($key)`
**Build**: žádný (Tailwind kompiluje se lokálně, `app.css` se commituje); cíl: minimální CI build krok

---

## Konvence kódu

- **PHP**: PSR-12 styl, `declare(strict_types=1);` POVINNĚ v nových souborech. V existujících je dopsaný všude kromě HTML šablon (`includes/*_view.php`, `table_tracks.php`, `pager_*.php`, `layout_*.php`) a stránek, které samy generují HTML (`stats.php`, `calendar.php`, `admin.php`, `edit.php`, `settings.php`, `login.php`, `import.php`, `heatmap.php`, `photo_heatmap.php`, `map_search.php`, `virtual_tracks*.php`) — tam je přes 370 volání `htmlspecialchars()`/`h()` s implicitní konverzí int→string, které by `strict_types` shodilo za běhu na konkrétní datové cestě. Do těch souborů ho nepřidávej, dokud nebudou volání přetypovaná. Stav: 101/127 souborů.
- **Naming**: **snake_case** pro funkce (`get_app_config`, `csrf_verify`), camelCase je legacy dluh
- **Strings**: všechny user-facing texty přes `t('key')` z lang souborů; technické komentáře v češtině jsou OK, ale komentáře v kódu by měly být postupně angličtinou (kód má identifikátory anglické)
- **DB**: výhradně prepared statements, NIKDY `$pdo->quote()` se string konkatenací
- **Validace**: input validace na vstupu (whitelist > blacklist), `htmlspecialchars`/`h()` na výstupu HTML, `js_safe_json()` pro JSON do `<script>` tagů
- **CSRF**: každý state-changing POST endpoint MUSÍ volat `csrf_verify()`; formuláře MUSÍ obsahovat `<?= csrf_field() ?>`
- **Soubory**: max ~500 řádků per `.php`; nad 1000 řádků je to tech dluh
- **Page pattern**: `*.php` v rootu = entry point, `includes/*_data.php` = data loading + business logic, `includes/*_view.php` = HTML šablona, `api/<modul>/<akce>.php` = JSON endpointy přes `ajax_endpoint()` wrapper
- **Git**: Conventional Commits (`feat:`, `fix:`, `chore:`, `refactor:`, `docs:`, `security:`)
- **Branch**: krátký popisný název, např. `radar-prehled` nebo `fix-viditelnost-sloupcu`
- **Commit message**: co se změnilo a proč; u opravy i příčina, ne jen příznak

---

## Doménový glosář (ubiquitous language)

- **Track / trasa**: GPX záznam (`tracks` tabulka), může mít difficulty (1–5), activity_type, kategorie (N:M přes `track_categories`)
- **Photo / fotka**: obrázek (JPG/PNG/WebP) v `track_photos`, volitelně přiřazen ke trase přes time+geo proximity (`auto_assign_photo_to_track` v `photo_helper.php`)
- **Visitor mode / visitor preview**: read-only přístup pro nepřihlášené uživatele (omezeno na stránky v `app_config.visible_pages`)
- **Admin via IP / Admin via login**: dva způsoby autentizace; `$_SESSION['admin_via']` = `'IP'` nebo `'login'`
- **App config**: globální runtime nastavení v tabulce `app_config` (themes, jazyky, viditelné stránky, uploads cesta)
- **Theme**: světlý/tmavý režim — řízený Tailwind `.dark` třídou (`gpx-theme` v localStorage, přepínač v `layout_header.php`); legacy stránky dědí přes CSS proměnné `:root`/`html.dark` v `css/style.css`. (9 barevných témat bylo odstraněno jako mrtvý kód, 2026-06-25.)
- **Activity type**: hodnota v `tracks.activity_type` — momentálně české stringy (`'Pěšky'`, `'Auto'`, `'Kolo'`...) — i18n přes label funkci
- **Difficulty**: spočítaná hodnota 1–5 podle vzorce v `calculateDifficulty()` (distance + ascent kombinace)
- **Migration**: SQL soubor v `migrations/NNNN_*.sql`; idempotentní, číslovaný, spouštěný přes `migrate.php`
- **safe_load_gpx**: jedna centrální funkce pro načtení GPX s `LIBXML_NONET` + DOCTYPE pre-check
- **ajax_endpoint wrapper**: centrální obal pro JSON endpointy (CSRF + auth gate + try/catch)

---

## Architektura — vstupní body

- **Schéma DB**: `install.sql` + `migrations/*.sql`
- **Konfigurace runtime**: `.env` (gitignored), template `.env.example`, loader v `config.php`
- **Auth / CSRF / security headers**: `includes/security.php`, `includes/auth.php`, `includes/public_access.php`
- **Helpers / i18n / format**: `includes/helpers.php` je jen zavaděč; obsah je v `i18n.php`, `format.php`, `paths.php`, `sort_query.php`, `activity.php`, `track_filter.php`, `constants.php`
- **Page modules**:
  - `index.php` (nový Tailwind) + `index-legacy.php` (legacy table) → sdílejí `includes/index_data.php`
  - `detail.php` → `includes/detail_data.php` + `includes/detail_view.php`
  - `filter.php` → `includes/filter_data.php` + `includes/filter_view.php`
  - `nearby.php`, `compare.php` — analogicky
  - `photos.php` a `photo_import.php` — tenké vstupní body, logika je v `api/photos/` a `api/photo_import/`
  - `admin.php`, `edit.php`, `settings.php`, `setup.php` — single-file
- **API endpointy**: `api_*.php` (toggle_favorite, bulk_action) + složky `api/photos/`, `api/photo_import/`, `api/planner/`, `api/radar/`, `api/poi/`
- **GPX parser**: `includes/gpx_parser.php`
- **Foto helpers**: `includes/photo_helper.php` (EXIF, auto-assign, thumb)
- **Thumb generator**: `includes/generate_thumb.php` (OSM dlaždice s diskovou cache); velký obrázek pro sdílení dělá `includes/share_image.php`

---

## Project-specific quality gates

Kontrolní seznam, který má projít každá změna:

- [ ] **Žádné `$pdo->quote()`** se string konkatenací — pouze prepared statements
- [ ] **Každý `simplexml_load_*` přes `safe_load_gpx()`** wrapper (LIBXML_NONET + DOCTYPE pre-check)
- [ ] **Každý POST endpoint má `csrf_verify()`** v první 5 řádcích handleru
- [ ] **Každý mutační endpoint má `$_isAdmin` check** (nebo explicitní visitor whitelist)
- [ ] **`uploads/` MUSÍ mít `.htaccess`** blokující PHP execution
- [ ] **Žádný `error_log` v `uploads/`** — pouze v `logs/` mimo webroot
- [ ] **i18n klíče konzistentní** napříč 8 jazyky (`php scripts/lint_lang.php` projde)
- [ ] **`declare(strict_types=1);`** v každém novém PHP souboru
- [ ] **Žádné nové `console.log`** v JS bez `if (window.GPX_DEBUG)`
- [ ] **Žádné `hidden md:flex`** a podobné CSS-only přepínače viditelnosti (`php scripts/lint_css_fragile.php` projde)
- [ ] **CDN scripty pinnuté** na konkrétní verzi + SRI hash (žádné `@latest`)
- [ ] **WCAG 2.2 AA** — kontrast ≥ 4.5:1 pro normální text ve světlém i tmavém režimu
- [ ] **`prefers-reduced-motion` honored** — žádné animace bez respektování
- [ ] **`<html lang>` reflektuje `app_lang()`**, ne hardcoded `cs`

---

## Konkrétní integrace v tomto projektu

### MySQL / MariaDB
- Connection: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` z `.env`
- PDO s `ERRMODE_EXCEPTION`, charset `utf8mb4`
- Schema změny: výhradně přes `migrations/NNNN_*.sql`; spuštění `php migrate.php`
- Při schema změně: nová migrace + srovnat `install.sql` (baseline pro čistou instalaci)

### Apache (.htaccess)
- HTTPS redirect, mod_deflate, mod_expires
- Blokace `.env`, `.log`, `.sql`, `.md`, `setup.php` (po instalaci), `composer.*`, `package*.json`
- `uploads/.htaccess` MUSÍ existovat (blokace `*.php`)
- Security headers — single source of truth = `includes/security.php`, NE `.htaccess`

### OpenStreetMap tile servery
- Použito v `generate_thumb.php` pro track thumbnails
- Lokální cache v `uploads/_tile_cache/` (TTL 30 dní)
- Respektovat OSM rate limit (50ms per request)

### Mapy.cz, Thunderforest, Mapillary
- API klíče v `.env` (TF_API_KEY, MAPYCOM_API_KEY, MAPILLARY_TOKEN)
- Klient-side v Leaflet layers
- Volitelné — pokud klíč není, vrstva se nezobrazí (musí být guarded — viz FE-11)

### CDN (Leaflet, Chart.js, Alpine.js, Lucide, Google Fonts)
- Pinout konkrétní verze + SRI integrity
- Long-term cíl: stáhnout do `assets/vendor/` (self-host)
- Pokud Google Fonts: vždy `&display=swap`

### Composer
- PSR-4 autoload: `GpxManager\` → `src/`
- Files autoload: `includes/helpers.php`, `includes/security.php`, `includes/app_config.php`, `includes/app_constants.php`
- Dev deps: PHPUnit (pro budoucí testy)

---

## Známé pasti & pravidla

> Co bylo špatně v minulosti, abychom se nevraceli.

- **`simplexml_load_*` bez `LIBXML_NONET`** je XXE — vždy přes `safe_load_gpx()`
- **`$pdo->quote()` se string interpolací** vypadá bezpečně, není; používej výhradně prepared statements s `?` nebo `:name`
- **Auth check JEN přes `check_page_access($page)`** v `includes/public_access.php` NESTAČÍ pro mutační endpointy — vždy explicitně `if (empty($_SESSION['is_admin'])) { http_response_code(403); exit; }`
- **`uploads_fs($userInput)`** musí validovat path traversal — jinak `../../config.php` projde
- **Auto-migrace v `db.php`** na každý request je vyřazena. Žádné nové ALTER TABLE tam.
- **Datum v `date_start` filtrech**: NEPOUŽÍVAT `DATE(date_start) = :d` (kills index) — vždy range `>= :from AND < :to`
- **i18n klíče**: cs/en mají odlišnou velikost než ostatní jazyky — po každé změně `php scripts/lint_lang.php`
- **`photos.php` AJAX endpointy** historicky neměly auth+CSRF check; pravidlo: každý mutační AJAX MUSÍ projít přes `ajax_endpoint()` wrapper s `['admin' => true, 'csrf' => true]`
- **`setup.php` zůstává v repu**, ale produkční instalace ho VŽDY smaže nebo zablokuje přes `.htaccess`
- **`error_log` cesta v `config.php`** — jen `logs/errors.log`, NIKDY `uploads/`
- **GPX bounds JSON** je užitečné pro UI, ale pro geo queries používej centroid_lat/lon sloupce — `bounds JSON` nelze indexovat
- **JS globální `window.*`** je legacy komunikační bus — nové moduly přes `window.GpxBus` event bus
- **`hidden md:flex` a spol. jsou křehké**: Tailwind v4 dává utility do `@layer utilities`, a podle specifikace CSS je porazí jakékoli NEVRSTVENÉ pravidlo — bez ohledu na pořadí a bez `!important`. Rozšíření prohlížeče si běžně vkládají do stránky vlastní `.hidden{display:none}`, čímž zmizí každý prvek, jehož viditelnost stojí čistě na `hidden` + responzivní variantě (27.7.2026 tak zmizelo celé horní menu, a v anonymním okně to přitom fungovalo). Používej nevrstvené `.gpx-md-flex` / `.gpx-md-block` / `.gpx-sm-inline` / `.gpx-sm-inline-flex` z `includes/layout_header.php`. Hlídá to `scripts/lint_css_fragile.php` v CI. `hidden` přidávané a odebírané JavaScriptem je v pořádku.

---

## Komunikace

**Jazyk pro AI**: Czech (autor projektu je Čech, hovoří česky; identifikátory v kódu jsou anglické)
**Tone**: pragmatický, věcný, bez floskulí
**Doménové názvy v kódu**: English (`tracks`, `is_favorite`, `activity_type`)
**User-facing texty**: 8 jazyků přes `t()` — fallback čeština
**Commit messages**: česky (privátní repo) nebo anglicky (public repo); vždy conventional format

---

## Co NIKDY nedělat

- **Necommitovat `.env`** ani žádné credentials (CI gitleaks scan to chytí)
- **Nepushnout `setup.php` aktivní** do produkce bez `.htaccess` blokace nebo bez smazání
- **Nepřidávat `ALTER TABLE` do `db.php`** — pouze do `migrations/*.sql`
- **Neimplementovat AJAX endpoint bez `ajax_endpoint()` wrapperu** — žádné ad-hoc dispatchery
- **Neměnit DB schema jinak než novou migrací** v `migrations/` + srovnat `install.sql`
- **Neměnit `security.php`** bez rozmyslu nad CSRF flow, parametry session a hlavičkami — a bez vysvětlení v commitu
- **Nepřepisovat `parse_gpx()`** bez ohledu na XXE — každé čtení GPX jde přes `safe_load_gpx()`
- **Nezavádět `console.log` v produkčním JS** bez `window.GPX_DEBUG` guardu
- **Nepřidávat `unsafe-eval` nebo nové `unsafe-inline` do CSP** bez justification v ADR
- **Nemergovat, co neproběhlo na localhostu** — skutečný rozhodčí je spuštěný kód, ne kontrola od modelu
- **Nepřidávat dependency (Composer / CDN) bez review** — bezpečnost dodavatelského řetězce

---
