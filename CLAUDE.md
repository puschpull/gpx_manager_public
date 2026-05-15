# CLAUDE.md — GPX Manager

> Konfigurace pro Claude Code agent orchestraci. Načte se automaticky při startu Claude Code v tomto repu.

---

## Project context

**Název projektu**: GPX Manager
**Stručný popis**: Self-hosted PHP webová aplikace pro správu GPS tras a fotek z výletů — import GPX, mapy, statistiky, fotogalerie, vícejazyčné UI, 9 témat. Cílový uživatel: jednotlivec nebo malá skupina, nasazení na sdílený hosting (Webglobe v ČR) nebo lokálně (WAMP).
**Stage**: production (live na vlastní doméně autora, single-tenant per instalace)
**Referenční audit**: `AUDIT_REPORT.md` (166 nálezů, 30 implementačních tasků)

---

## Stack

**Backend**: PHP 8.0+ (cíl 8.2+), žádný framework, čistý monolit
**Database**: MySQL 5.7+ / MariaDB 10.3+ přes PDO (prepared statements)
**Frontend**:
- Server-rendered HTML přes inline PHP
- Tailwind CSS v4 (`assets/css/input.css` → `assets/css/app.css`) — postupně migruje vrstvy
- Alpine.js 3.x pro interaktivitu v novém UI
- Legacy CSS v `css/theme-*.css` (9 témat) — koexistuje s Tailwind
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

- **PHP**: PSR-12 styl, `declare(strict_types=1);` POVINNĚ v nových souborech (postupně dopisujeme do existujících)
- **Naming**: **snake_case** pro funkce (`get_app_config`, `csrf_verify`), camelCase je legacy dluh (sjednocení v rámci TASK-16)
- **Strings**: všechny user-facing texty přes `t('key')` z lang souborů; technické komentáře v češtině jsou OK, ale komentáře v kódu by měly být postupně angličtinou (kód má identifikátory anglické)
- **DB**: výhradně prepared statements, NIKDY `$pdo->quote()` se string konkatenací
- **Validace**: input validace na vstupu (whitelist > blacklist), `htmlspecialchars`/`h()` na výstupu HTML, `js_safe_json()` pro JSON do `<script>` tagů
- **CSRF**: každý state-changing POST endpoint MUSÍ volat `csrf_verify()`; formuláře MUSÍ obsahovat `<?= csrf_field() ?>`
- **Soubory**: max ~500 řádků per `.php` (cíl po refaktoru); soubory větší než 1000 řádků jsou tech dluh (viz TASK-18, TASK-19)
- **Page pattern**: `*.php` v rootu = entry point, `includes/*_data.php` = data loading + business logic, `includes/*_view.php` = HTML šablona, `api/<modul>/<akce>.php` = JSON endpointy přes `ajax_endpoint()` wrapper
- **Git**: Conventional Commits (`feat:`, `fix:`, `chore:`, `refactor:`, `docs:`, `security:`)
- **Branch**: `task-NN-<slug>` (1:1 mapování na TASK-XX z `AUDIT_REPORT.md`), např. `task-01-xxe-uploads-hardening`
- **Commit message**: vždy reference na TASK-XX nebo na konkrétní nález (např. `Covers: SEC-001, SEC-002, SEC-019`)

---

## Doménový glosář (ubiquitous language)

- **Track / trasa**: GPX záznam (`tracks` tabulka), může mít difficulty (1–5), activity_type, kategorie (N:M přes `track_categories`)
- **Photo / fotka**: obrázek (JPG/PNG/WebP) v `track_photos`, volitelně přiřazen ke trase přes time+geo proximity (`auto_assign_photo_to_track` v `photo_helper.php`)
- **Visitor mode / visitor preview**: read-only přístup pro nepřihlášené uživatele (omezeno na stránky v `app_config.visible_pages`)
- **Admin via IP / Admin via login**: dva způsoby autentizace; `$_SESSION['admin_via']` = `'IP'` nebo `'login'`
- **App config**: globální runtime nastavení v tabulce `app_config` (themes, jazyky, viditelné stránky, uploads cesta)
- **Theme**: jedno z 9 barevných témat (`classic`, `dark`, `darkblue`, `darkgreen`, `blue`, `green`, `minimal`, `lightgray`, `brown`)
- **Activity type**: hodnota v `tracks.activity_type` — momentálně české stringy (`'Pěšky'`, `'Auto'`, `'Kolo'`...) — i18n přes label funkci (TASK-25 OPTION A)
- **Difficulty**: spočítaná hodnota 1–5 podle vzorce v `calculateDifficulty()` (distance + ascent kombinace)
- **Migration**: SQL soubor v `migrations/NNNN_*.sql` (po TASK-09); idempotentní, číslovaný, spouštěný přes `migrate.php`
- **safe_load_gpx**: jedna centrální funkce pro načtení GPX s `LIBXML_NONET` + DOCTYPE pre-check (po TASK-01)
- **ajax_endpoint wrapper**: centrální obal pro JSON endpointy (CSRF + auth gate + try/catch); zavádí TASK-18

---

## Architektura — vstupní body

- **Master audit & roadmap**: `AUDIT_REPORT.md` (jediný zdroj pravdy pro pořadí prací)
- **Schéma DB**: `install.sql` + `migrations/*.sql` (po TASK-09)
- **Konfigurace runtime**: `.env` (gitignored), template `.env.example`, loader v `config.php`
- **Auth / CSRF / security headers**: `includes/security.php`, `includes/auth.php`, `includes/public_access.php`
- **Helpers / i18n / format**: `includes/helpers.php` (před TASK-16) → po TASK-16 rozděleno na `i18n.php`, `format.php`, `paths.php`, `sort_query.php`, `activity.php`, `track_filter.php`, `constants.php`
- **Page modules**:
  - `index.php` (nový Tailwind) + `index-legacy.php` (legacy table) → sdílejí `includes/index_data.php`
  - `detail.php` → `includes/detail_data.php` + `includes/detail_view.php`
  - `filter.php` → `includes/filter_data.php` + `includes/filter_view.php`
  - `nearby.php`, `compare.php` — analogicky
  - `photos.php` (1429 řádků, refactor v TASK-18)
  - `photo_import.php` (1126 řádků, refactor v TASK-19)
  - `admin.php`, `edit.php`, `settings.php`, `setup.php` — single-file (refactor až ve fázi 4)
- **API endpointy**: `api_*.php` (toggle_favorite, bulk_action) + po TASK-18 nová složka `api/photos/`, `api/photo_import/`
- **GPX parser**: `includes/gpx_parser.php` (refactor v TASK-21)
- **Foto helpers**: `includes/photo_helper.php` (EXIF, auto-assign, thumb)
- **Thumb generator**: `includes/generate_thumb.php` (OSM tiles, refactor v TASK-20)

---

## Agenti — kdy koho zapojit

> Tento blok řídí `tech-lead-orchestrator`.

### Vždy zapoj
- **code-reviewer** před každým commitem do `develop`/`main`
- **security-auditor** pro: auth, sessions, CSRF, file uploads, XML parsing, SQL queries, file system operace
- **database-engineer** pro: schema změny, nové indexy, slow queries, migrace
- **accessibility-auditor** pro: jakoukoli user-facing UI změnu

### Často zapoj
- **performance-engineer** pro: index/filter/heatmap/nearby stránky, photo gallery, GPX parsing
- **backend-developer** pro: implementaci PHP endpointů, business logic, DB queries
- **frontend-developer** pro: JS moduly, CSS, Tailwind classy, Alpine.js komponenty
- **devops-engineer** pro: `.htaccess`, CI/CD, Composer setup, environment config
- **tech-writer** pro: changelog na release, ADR pro architektonická rozhodnutí, README update

### Pro tento projekt specifické
- **GPX/XML parsing** je security-sensitive (XXE) → **security-auditor** review POVINNÝ pro každou změnu v `gpx_parser.php`, `export_*.php`, `heatmap.php`
- **`photos.php` a `photo_import.php`** mají historicky špatnou bezpečnost (chybějící auth checks, CSRF) — každá změna těchto souborů VYŽADUJE security-auditor
- **`.htaccess` změny** → **devops-engineer** + **security-auditor** (uploads/PHP block je kritický)
- **Auto-migrace v db.php** je deprecated — žádné nové ALTER TABLE v db.php; všechno přes `migrations/*.sql` (po TASK-09)
- **i18n drift** — po každé změně lang souboru spustit `php scripts/lint_lang.php` (po TASK-14)

---

## Project-specific quality gates

Doplňující ke standardům z agentů:

- [ ] **Žádné `$pdo->quote()`** se string konkatenací — pouze prepared statements
- [ ] **Každý `simplexml_load_*` přes `safe_load_gpx()`** wrapper (LIBXML_NONET + DOCTYPE pre-check)
- [ ] **Každý POST endpoint má `csrf_verify()`** v první 5 řádcích handleru
- [ ] **Každý mutační endpoint má `$_isAdmin` check** (nebo explicitní visitor whitelist)
- [ ] **`uploads/` MUSÍ mít `.htaccess`** blokující PHP execution
- [ ] **Žádný `error_log` v `uploads/`** — pouze v `logs/` mimo webroot
- [ ] **i18n klíče konzistentní** napříč 8 jazyky (`php scripts/lint_lang.php` projde)
- [ ] **`declare(strict_types=1);`** v každém novém PHP souboru
- [ ] **Žádné nové `console.log`** v JS bez `if (window.GPX_DEBUG)`
- [ ] **CDN scripty pinnuté** na konkrétní verzi + SRI hash (žádné `@latest`)
- [ ] **WCAG 2.2 AA** — kontrast ≥ 4.5:1 pro normální text ve VŠECH 9 tématech
- [ ] **`prefers-reduced-motion` honored** — žádné animace bez respektování
- [ ] **`<html lang>` reflektuje `app_lang()`**, ne hardcoded `cs`

---

## Konkrétní integrace v tomto projektu

### MySQL / MariaDB
- Connection: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` z `.env`
- PDO s `ERRMODE_EXCEPTION`, charset `utf8mb4`
- Schema změny: výhradně přes `migrations/NNNN_*.sql` (po TASK-09); spuštění `php migrate.php`
- Při schema změně: `database-engineer` review + update `install.sql` (sync baseline)

### Apache (.htaccess)
- HTTPS redirect, mod_deflate, mod_expires
- Blokace `.env`, `.log`, `.sql`, `.md`, `setup.php` (po instalaci), `composer.*`, `package*.json`
- `uploads/.htaccess` MUSÍ existovat (blokace `*.php`)
- Security headers — single source of truth = `includes/security.php`, NE `.htaccess`

### OpenStreetMap tile servery
- Použito v `generate_thumb.php` pro track thumbnails
- Lokální cache v `uploads/_tile_cache/` (TTL 30 dní, po TASK-20)
- Respektovat OSM rate limit (50ms per request)

### Mapy.cz, Thunderforest, Mapillary
- API klíče v `.env` (TF_API_KEY, MAPYCOM_API_KEY, MAPILLARY_TOKEN)
- Klient-side v Leaflet layers
- Volitelné — pokud klíč není, vrstva se nezobrazí (musí být guarded — viz FE-11)

### CDN (Leaflet, Chart.js, Alpine.js, Lucide, Google Fonts)
- Pinout konkrétní verze + SRI integrity (po TASK-08)
- Long-term cíl: stáhnout do `assets/vendor/` (self-host)
- Pokud Google Fonts: vždy `&display=swap`

### Composer (po TASK-13)
- PSR-4 autoload: `GpxManager\` → `src/`
- Files autoload: `includes/helpers.php`, `includes/security.php`, `includes/app_config.php`, `includes/app_constants.php`
- Dev deps: PHPUnit (pro budoucí testy)

---

## Známé pasti & pravidla

> Co bylo špatně v minulosti, abychom se nevraceli.

- **`simplexml_load_*` bez `LIBXML_NONET`** je XXE — vždy přes `safe_load_gpx()` (po TASK-01)
- **`$pdo->quote()` se string interpolací** vypadá bezpečně, není; používej výhradně prepared statements s `?` nebo `:name`
- **Auth check JEN přes `check_page_access($page)`** v `includes/public_access.php` NESTAČÍ pro mutační endpointy — vždy explicitně `if (empty($_SESSION['is_admin'])) { http_response_code(403); exit; }`
- **`uploads_fs($userInput)`** musí validovat path traversal (po TASK-03) — jinak `../../config.php` projde
- **Auto-migrace v `db.php`** na každý request je vyřazena (TASK-09). Žádné nové ALTER TABLE tam.
- **Datum v `date_start` filtrech**: NEPOUŽÍVAT `DATE(date_start) = :d` (kills index) — vždy range `>= :from AND < :to`
- **i18n klíče**: cs/en mají odlišnou velikost než ostatní jazyky — po každé změně `php scripts/lint_lang.php` (po TASK-14)
- **`photos.php` AJAX endpointy** historicky neměly auth+CSRF check; pravidlo: každý mutační AJAX MUSÍ projít přes `ajax_endpoint()` wrapper (po TASK-18) s `['admin' => true, 'csrf' => true]`
- **`setup.php` zůstává v repu**, ale produkční instalace ho VŽDY smaže nebo zablokuje přes `.htaccess` (TASK-05 + TASK-12)
- **`error_log` cesta v `config.php`** — jen `logs/errors.log`, NIKDY `uploads/`
- **GPX bounds JSON** je užitečné pro UI, ale pro geo queries používej centroid_lat/lon sloupce (po TASK-11) — `bounds JSON` nelze indexovat
- **JS globální `window.*`** je legacy komunikační bus — nové moduly přes `window.GpxBus` event bus (po TASK-23)

---

## Komunikace

**Jazyk pro AI**: Czech (autor projektu je Čech, hovoří česky; identifikátory v kódu jsou anglické)
**Tone**: pragmatický, věcný, bez floskulí
**Doménové názvy v kódu**: English (`tracks`, `is_favorite`, `activity_type`)
**User-facing texty**: 8 jazyků přes `t()` — fallback čeština
**Commit messages**: česky NEBO anglicky (autor preferuje, ale klíčové je conventional format + odkaz na TASK-XX)

---

## Co NIKDY nedělat

- **Necommitovat `.env`** ani žádné credentials (CI gitleaks scan to chytí, po TASK-14)
- **Nepushnout `setup.php` aktivní** do produkce bez `.htaccess` blokace nebo bez smazání (TASK-05 hardening)
- **Nepřidávat `ALTER TABLE` do `db.php`** — pouze do `migrations/*.sql` (po TASK-09)
- **Neimplementovat AJAX endpoint bez `ajax_endpoint()` wrapperu** (po TASK-18) — žádné ad-hoc dispatchery
- **Neměnit DB schema bez `database-engineer`** review + nová migrace
- **Neměnit `security.php`** bez `security-auditor` review (CSRF flow, session params, headers)
- **Nepřepisovat `parse_gpx()`** bez `security-auditor` review (XXE attack surface)
- **Nezavádět `console.log` v produkčním JS** bez `window.GPX_DEBUG` guardu
- **Nepřidávat `unsafe-eval` nebo nové `unsafe-inline` do CSP** (po TASK-08) bez justification v ADR
- **Nemergovat bez `code-reviewer`** projití na PR
- **Neoznačovat TASK jako hotový bez splněných acceptance criteria** ze sekce v `AUDIT_REPORT.md`
- **Neměnit pořadí TASKů z `AUDIT_REPORT.md`** bez konzultace (mají dependency graf)
- **Nepřidávat dependency (Composer / CDN) bez review** — bezpečnost dodavatelského řetězce

---

## Workflow pro implementaci dle AUDIT_REPORT.md

Při zadání TASK-XX:

1. **Vytvoř branch**: `git checkout -b task-NN-<slug>` (slug = krátký popis, např. `task-01-xxe-uploads-hardening`)
2. **Otevři `AUDIT_REPORT.md`**, najdi sekci TASK-NN
3. **Před implementací**: zkontroluj acceptance criteria a závislosti (sekce "Závislosti")
4. **Implementuj** podle "Prompt k zadání" (step-by-step instrukce)
5. **Verifikuj** podle "VERIFIKACE" sekce
6. **Spusť relevantní gate agenty**:
   - `security-auditor` pokud TASK má SEC-* nálezy v "Pokrývá nálezy"
   - `database-engineer` pokud má DB-*
   - `accessibility-auditor` pokud má A11Y-*
   - `code-reviewer` vždy před commitem
7. **Commit**: Conventional Commits + reference na TASK-NN a covered nálezy
8. **Aktualizuj `AUDIT_REPORT.md`** — zaškrtni checkboxy v acceptance criteria sekci (nepovinné, ale doporučené)
9. **Push + PR** (pokud je remote setup)

---

## Status implementace

> Aktualizuj při dokončení každého TASKu.

### FÁZE 0 — Critical security
- [x] TASK-01 — XXE + uploads/.htaccess + error log (commit 6a48571)
- [x] TASK-02 — CSRF + admin auth na mutačních endpointech (commit f588069)
- [x] TASK-03 — Path traversal + filename sanitization (commit 8a16bd3)
- [x] TASK-04 — XSS escape + js_safe_json (commit d239859)
- [x] FÁZE 0 hotfix — fav-toggle CSRF wiring + settings.php CSRF

### FÁZE 1 — Security hardening
- [x] TASK-05 — Setup.php hardening (commit e0ab9e0)
- [x] TASK-06 — Login rate limiting + logout CSRF (commit 6b53bae)
- [x] TASK-07 — CSV injection + DoS protection (commit 681ee16)
- [x] TASK-08 — Security headers + CSP + SRI prep (commit 9faecd7)

### FÁZE 2 — DB & migrations
- [x] TASK-09 — Migration runner (commit 78370a3)
- [x] TASK-10 — Critical indexes + SHA-256 prep + date range filter (commit f893639)
- [x] TASK-11 — N+1 fixes — centroid + detail nav + nearby + similar (commit 6076d83)

### FÁZE 3 — DevOps
- [x] TASK-12 — Full `.htaccess` (HTTPS, gzip, cache, blocks) (commit c5ee6cb)
- [x] TASK-13 — Composer + PSR-4 autoloader + strict_types (commit 777c442)
- [x] TASK-14 — GitHub Actions CI + i18n lint + Tailwind verification (commit 72c309e)

### FÁZE 4 — Refactoring
- [x] TASK-15 — Theme/lang/pages konstanty (commit ec0ce54)
- [x] TASK-16 — helpers.php decomposition into 7 modules (commit fa455e2)
- [x] TASK-17 — Admin banner partial (commit 2514275)
- [x] TASK-18 — AJAX wrapper + photos.php split 1513→48 lines (commit e69d85f)
- [x] TASK-19 — photo_import.php split 1126→36 lines (commit 20a21ad)
- [x] TASK-20 — OSM tile cache (commit 3e6f274)
- [x] TASK-21 — GPX parser refactor + duplicate calc fix (commit 520dcd2)

### FÁZE 5 — Frontend
- [x] TASK-22 — FE quick fixes — Mapillary guard, AbortController, dedup column toggle, chart conflict (commit 2721850)
- [x] TASK-23 — FE lib extractions — js/lib/{event-bus,geo-utils,format-utils,map-factory}.js (commit 31c6b42)
- [x] TASK-24 — Theming consolidation + contrast fix + FOUC elimination (commit 56b175f)
- [x] TASK-25 — i18n consistency — window.gpxI18n + activity_type_label + sweep (commit 828daec)

### FÁZE 6 — Accessibility
- [x] TASK-26 — A11y quick wins (lang, skip link, contrast, reduced-motion, aria-hidden, aria-current) (commit a5527cd)
- [x] TASK-27 — A11y forms (label/id, autocomplete, role=alert, sr-only radios) (commit fb20b77)
- [x] TASK-28 — A11y tables + landmarks + sidebar toggle ARIA (commit 3b24202)
- [x] TASK-29 — A11y modals — lightbox + mobile drawer + lang menu + preset dialog (commit 36c0c70)
- [x] TASK-30 — A11y maps + chart canvases + data table alternatives (commit 1befccd)

---

## ✅ PLAN COMPLETE — 30/30 tasks implemented

All 166 audit findings addressed across 6 phases. See AUDIT_REPORT.md
for full traceability per finding ID.
