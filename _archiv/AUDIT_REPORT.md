# GPX Manager — Komplexní audit a implementační plán

> **Audit proveden:** 2026-05-11
> **Auditovaný stav:** master branch z https://github.com/puschpull/gpx_manager_public
> **Cesta projektu:** `E:\Repositories\gpx-manager`
> **Rozsah:** 62 PHP souborů (~700 KB), 23 JS (~184 KB), 20 CSS (~141 KB), 8 jazykových mutací
> **Tým auditorů:** 8 specializovaných agentů (security, code review, database, performance, frontend, accessibility, devops, backend architecture)

---

## Jak číst tento dokument

1. **Část A — Executive summary** — manažerský přehled, top rizika, fázový plán.
2. **Část B — Katalog nálezů** — všech 168 nálezů seřazených podle dimenze (security, kód, DB, výkon, frontend, a11y, devops, backend).
3. **Část C — Implementační balíčky pro Claude Code Pro** — 30 samostatných úkolů, každý 1–3 hodiny práce, s hotovým promptem k zadání.
4. **Část D — Přílohy** — referenční snippety (.htaccess, CI YAML, Docker compose).

**Tagy nálezů:** `SEC-NNN` (security), `QR-NN` (code review), `DB-NN` (database), `PERF-NN` (performance), `FE-NN` (frontend), `A11Y-NNN` (accessibility), `OPS-NN` (devops), `BE-NN` (backend architecture).

**Pro implementátora s Claude Code Pro:**
Každý úkol v Části C je navržen tak, aby:
- Šel zadat jako jediný prompt v rámci jedné session
- Měl jasná acceptance criteria
- Nevyžadoval čtení celého kódu (cesty + řádky jsou v promptu)
- Vešel se do 5 h Pro okna (typicky 1–3 h reálné práce)
- Měl referenci na konkrétní nálezy v Části B pro kontext

---

# ČÁST A — Executive Summary

## A.1 Závažnost — celkové skóre

| Dimenze | Critical | High | Medium | Low / Info | Celkem |
|---|---:|---:|---:|---:|---:|
| **Security** | 3 | 9 | 12 | 8 | **32** |
| **Code Review** | 1 | 4 | 11 | 8 | **24** |
| **Database** | 2 | 5 | 4 | 1 | **12** |
| **Performance** | 1 | 5 | 9 | 3 | **18** |
| **Frontend** | 0 | 5 | 9 | 5 | **19** |
| **Accessibility** | 9 | 14 | 5 | 2 | **30** |
| **DevOps** | 1 | 5 | 6 | 4 | **16** |
| **Backend arch.** | 2 | 4 | 6 | 3 | **15** |
| **CELKEM** | **19** | **51** | **62** | **34** | **166** |

## A.2 Top 10 nejhorších věcí (musíš opravit jako první)

1. **SEC-005 (Critical)** — Visitor může uploadovat a mazat fotky, pokud je `photos` v `visible_pages`. 6 z 8 photo AJAX endpointů nemá `$_isAdmin` check.
2. **SEC-001/002 (Critical)** — XXE v `gpx_parser.php` a 5 dalších XML parserech. Útočník čte `.env` se všemi credentials.
3. **SEC-019 (High)** — `uploads/` nemá `.htaccess` blokující PHP execution. Kombinováno s SEC-007 = RCE.
4. **A11Y-001 (Blocker)** — `<html lang="cs">` natvrdo, ignoruje aktivní jazyk. Screen readery čtou špatnou jazykovou variantu.
5. **DB-4 / OPS-03 / PERF-1 / BE-1 / QR-1 (Critical)** — Auto-migrace v `db.php` na každém HTTP requestu (9 SELECT + ALTER race).
6. **SEC-006 (High)** — `setup.php` bez CSRF + .env injection přes newline. Reinstall možný po DB chybě.
7. **A11Y-005 (Blocker)** — Tabulka tras (`table_tracks.php`) bez `<thead>`, `scope`, `<caption>` — pro screen reader uživatele nepoužitelná.
8. **SEC-016 (Medium)** — Path traversal v `uploads_fs($track['filename'])` při delete. Admin (nebo CSRF) maže libovolné soubory.
9. **A11Y-012 (Blocker)** — Lightbox bez focus trapu a return-focus. Klávesnice po otevření "zamrzne".
10. **SEC-012 (High)** — Stored XSS v photo caption renderované v Leaflet tooltipu bez escape.

## A.3 Fázový plán (doporučené pořadí)

```
FÁZE 0 — Critical security (1–2 dny)      → TASK-01 až TASK-04
FÁZE 1 — Security hardening (2–3 dny)     → TASK-05 až TASK-08
FÁZE 2 — DB & migrations (2–3 dny)        → TASK-09 až TASK-11
FÁZE 3 — DevOps & infra (2 dny)           → TASK-12 až TASK-14
FÁZE 4 — Refactoring (3–5 dní)            → TASK-15 až TASK-21
FÁZE 5 — Frontend (2–3 dny)               → TASK-22 až TASK-25
FÁZE 6 — Accessibility (3–4 dny)          → TASK-26 až TASK-30
```

**Celkový odhad:** 15–22 čistých pracovních dnů (jeden senior dev) NEBO 30+ sessions Claude Code Pro.

## A.4 Závislosti mezi fázemi

```
FÁZE 0 (security P0) ──> všechno ostatní (jinak otevřená produkce)
FÁZE 1 (security P1) ──> FÁZE 3 (CSP závisí na CDN strategii)
FÁZE 2 (migrace)     ──> FÁZE 4 (refactor potřebuje stabilní DB layer)
FÁZE 3 (composer)    ──> FÁZE 4 (autoload, strict_types)
FÁZE 4 (struktura)   ──> FÁZE 5 + 6 (čistší kód = snadnější a11y/FE změny)
FÁZE 5 a 6 mohou běžet paralelně.
```

## A.5 Pozitivní zjištění (co nedělat)

- **`includes/security.php`** — CSRF, secure session, headers správně implementované.
- **`includes/gpx_parser.php`** — namespace handling, TrackStatsExtension merge — mature kód.
- **`config.php`** — `.env` loader, exception handler s AJAX-aware JSON. Solidní.
- **PDO prepared statements** — drtivá většina dotazů parametrizovaná správně.
- **`password_hash`/`password_verify`** — používáno správně (PASSWORD_DEFAULT = bcrypt).
- **i18n architektura** (`lang/*.php` + `t()`) — pragmatická a funkční pro projekt této velikosti.
- **`*_data.php` + `*_view.php` split** tam, kde aplikován — čistý vzor.
- **Setup wizard UX** (`setup.php`) — vícekrokový, self-delete, multi-language.

---

# ČÁST B — Katalog nálezů

## B.1 Security (32 nálezů)

### Critical

**SEC-001 — XXE v `gpx_parser.php`**
- Soubor: `includes/gpx_parser.php:11-12`
- `simplexml_load_file()` bez `LIBXML_NONET`. Útočník v GPX vloží `<!DOCTYPE ... <!ENTITY xxe SYSTEM "file:///.env">` → `track_name` obsahuje obsah `.env` (DB creds, ADMIN_PASS_HASH).
- Fix: `simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NONET)` + pre-check na `<!DOCTYPE`/`<!ENTITY` v prvních 4 KB souboru.

**SEC-002 — XXE v dalších 5 XML parserech**
- Soubory: `export_geojson.php:24`, `export_kml.php:20`, `heatmap.php:86`, `includes/nearby_data.php:60`, `includes/generate_thumb.php:87`.
- Stejný problém. `export_*` přístupné visitorům.
- Fix: centrální `safe_load_gpx($path)` wrapper, použít všude.

**SEC-005 — Photo AJAX endpointy bez admin checku**
- Soubor: `photos.php:36-364`
- Pouze `toggle_visible` a `bulk_visible` ověřují `$_isAdmin`. `upload`, `assign`, `delete`, `update_caption`, `bulk_delete`, `bulk_assign` jsou otevřené visitorovi (pokud `photos` ∈ `visible_pages`).
- Fix: gate všech mutačních endpointů kombinací `$_isAdmin` + `csrf_verify()`.

### High

**SEC-003 — `api_toggle_favorite.php` bez CSRF.** Útočník přes phishing přepíná oblíbené trasy adminovi.

**SEC-004 — `admin.php` POST forms bez CSRF.** Útočník přes CSRF mění `uploads_fs_path` a `visible_pages` adminovi → potenciálně RCE.

**SEC-006 — `setup.php` bez CSRF + newline injection do `.env` + reinstall window.** Race condition při čerstvém deployi; reinstall po DB chybě.

**SEC-007 — `filter_data.php save_cleaned` zapisuje user-controlled obsah do `uploads/`** bez size limit a DOCTYPE pre-check. Kombinováno s SEC-019 = RCE.

**SEC-008 — CSV injection v `export.php`.** `track_name` jako `=cmd|'/C calc'!A0` se v Excelu spustí jako formula.

**SEC-009 — Login brute force ochrana v `login.php`** vázaná na session cookie. Útočník zahodí cookie → bypass.

**SEC-011 — `heatmap.php?ajax=rebuild` přístupné visitorům.** Žádný admin check, 10 min CPU + 512 MB RAM per request → DoS.

**SEC-012 — Stored XSS přes photo caption v Leaflet tooltipu** (`js/detail-map.js:329-330`). `caption` se renderuje jako HTML bez escape.

**SEC-013 — Photos.php mutační endpointy bez CSRF.** Stejně jako SEC-005, plus chybí CSRF guard.

**SEC-019 — `uploads/` bez `.htaccess` blokujícího PHP execution.** Apex enabler pro všechny upload-based RCE chainy.

### Medium

**SEC-010** — Filename traversal v `setup.php` POST do `.env` (newline injection).
**SEC-014** — Filter `presets` GET endpoint je veřejný — info disclosure.
**SEC-015** — `json_encode()` v `<script>` bez `JSON_HEX_TAG|JSON_HEX_AMP` → potenciální XSS přes `</script>` injection v `filename`.
**SEC-016** — Path traversal v `uploads_fs($track['filename'])` při `delete.php`. Admin (nebo CSRF) maže `../../config.php`.
**SEC-017** — Zip Slip — `ZipArchive::extractTo()` v `photos.php:82-113` bez kontroly entry names.
**SEC-018** — Photo upload bez validace velikosti, počtu, image bomb (50 MP+ rozměry).
**SEC-020** — `_errors.log` ve veřejné složce `uploads/` — info disclosure přes URL.
**SEC-021** — Chybí `Content-Security-Policy`, `Strict-Transport-Security`, `Permissions-Policy`.
**SEC-022** — Externí CDN scripty bez SRI + `@latest`/`@3.x.x` floating verze.
**SEC-023** — `visitor_preview=1` GET mutuje session bez CSRF.
**SEC-026** — `nearby_data.php` parsuje 30 GPX bez admin checku a rate limitu.
**SEC-029** — `photo_import.php` skenuje libovolnou filesystem cestu (CSRF-able).
**SEC-030** — Žádný rate limiting v celé aplikaci.

### Low / Info

**SEC-024** — Logout přes GET (`?logout=1`) — CSRF přes `<img>`.
**SEC-025** — Session cookie `SameSite=Lax` místo `Strict`.
**SEC-027** — `index_view.php` filename v img alt — bezpečné (h() escape funguje).
**SEC-028** — Odkaz na `phpinfo.php` v `admin.php` (soubor neexistuje, ale je to footgun).
**SEC-031** — `track_name`/`note` ze `edit.php` — admin-only, render přes `h()`, OK.
**SEC-032** — Blindní důvěra v `X-Forwarded-Proto` pro HTTPS detection.
**SEC-033** — `app_lang` cookie reflection (validace AŽ po setcookie).
**SEC-034** — `DB_NAME` a `APP_ENV` v patičce vidí visitor.
**SEC-035** — `index_data.php?ajax=1` vrací HTML v JSON — escape OK.

---

## B.2 Code Quality (24 nálezů)

### Critical

**QR-1 — Auto-migrace v `db.php` na každém requestu.** 8 SELECT + potenciální ALTER bez race protection, bez verzování, bez auditu. Viz též DB-4, OPS-03, PERF-1, BE-1.

### High

**QR-2 — Admin banner HTML duplikovaný v `auth.php:45-53` a `public_access.php:76-100`.** Hard-coded styly, čeština bez diakritiky.

**QR-3 — Theme/lang/visible_pages literály ve 15 souborech.** Přidání tématu = úprava 15 souborů.

**QR-4 — `index.php` vs `index-legacy.php` — dvě paralelní UI bez decision dokumentu.** Legacy view má 30+ sloupců a column toggles, které nová verze nemá.

**QR-5 — `photos.php` 1429 řádků, 8 AJAX endpointů + view + inline JS v jednom.**

### Medium

**QR-6** — `photo_import.php` 1126 řádků s 350 řádky inline `<style>`.
**QR-7** — `$pdo->quote()` namísto prepared statements v `api_bulk_action.php:84,105`.
**QR-8** — `declare(strict_types=1)` nepoužito nikde.
**QR-9** — `css/variables-reference.css` obsahuje skutečné CSS i s nevalidním blokem — footgun.
**QR-12** — i18n key drift mezi 8 jazykovými soubory (cs/en má 369, ostatní 368).
**QR-13** — CSRF aplikován nekonzistentně (některé endpointy ano, jiné ne).
**QR-14** — Žádný JS module system, globální `window.*` jako communication bus, `console.log` s emoji v produkci.
**QR-15** — `error_log` jako jediná error path, žádná centrální AJAX error obálka.
**QR-16** — `helpers.php` 550 řádků slučujících 7 nesouvisejících oblastí.

### Low / Nice-to-have

**QR-10** — Naming mix camelCase + snake_case pro funkce.
**QR-11** — Mix čeština/angličtina v kódu, komentářích, DB hodnotách (`'Pěšky'`, `'Auto'`).
**QR-17** — `t()` má návratový typ `string|array` — runtime TypeError při polích.
**QR-18** — `console.log` debug noise s emoji.
**QR-19** — Nekonzistentní použití `*_data.php` + `*_view.php` patternu.
**QR-20** — Magic numbers napříč (`500m` photo radius, `0.5 m/s` moving threshold, `80 km/h` auto threshold).
**QR-21** — `parse_gpx()` 230 řádků, 5 odpovědností, duplikovaný výpočet.
**QR-22** — `setcookie()` po potenciálním výstupu (bez `headers_sent()` guardu).
**QR-23** — `init_app_config()` běží unconditionally, 3 round-tripy/request navždy.
**QR-24** — Komentáře v češtině duplikující kód místo "proč".

---

## B.3 Database (12 nálezů)

### Critical

**DB-4 — Auto-migrace v `db.php` na každém requestu.** 9 SQL round-tripů/request, race conditions při concurrent deploy.

**DB-12 — `api_bulk_action.php:84,105` používá `$pdo->quote()` se string interpolací místo prepared statements** pro hodnoty z `$_POST`.

### High

**DB-2 — `file_hash VARCHAR(40)` = SHA-1, překonáno.** Rozšířit na 64 znaků pro SHA-256.

**DB-5 — `index_data.php` correlated subquery pro `photo_count`** bez složeného indexu `(track_id, visible)`.

**DB-6 — `DATE(date_start)` wrapper v WHERE** zabraňuje použití `idx_tracks_date` jako range indexu.

**DB-7 — `track_categories` nemá index na `category_id`** — EXISTS subquery jede full scan.

**DB-9/DB-10 — `detail_data.php` načítá všechna ID** do PHP paměti; `nearby_data.php` a `detail_data.php/similar` dělají full scan přes všechny trasy.

### Medium

**DB-1** — `FLOAT` pro distance/elevation — měl by být `DECIMAL` (přesnost).
**DB-3** — Chybí indexy na sloupcích pro `stats.php` rekordy (`distance_km`, `ascent`, `elevation_max`, `speed_max`, `duration`).
**DB-8** — `bounds JSON` použito ve WHERE bez možnosti indexu.
**DB-11** — `filter_presets.settings JSON` bez query patternu.

---

## B.4 Performance (18 nálezů)

### Critical

**PERF-1 — Auto-migrace v `db.php`** přidává 45–135 ms TTFB každému requestu.

### High

**PERF-2 — `detail_data.php` načítá všechna ID tras** pro prev/next navigaci. O(n) per detail load.
**PERF-3 — `nearby_data.php` parsuje 30 GPX souborů per AJAX request.** 1200+ ms na I/O.
**PERF-4 — `generate_thumb.php` stahuje OSM tiles synchronně** s `usleep(50ms)` per tile. 2400 ms per thumb.
**PERF-5 — GPX Parser `simplexml_load_file()` načte celý DOM** do paměti. 10–40 MB per soubor.
**PERF-9 — Všechny CDN scripty bez `async`/`defer`** blokují render. ~600 KB na detail page.
**PERF-13 — `.htaccess` bez gzip a Cache-Control** pro statika.

### Medium

**PERF-6** — `detectActivityType()` voláno 2× v `import.php`.
**PERF-7** — `heatmap.php?ajax=rebuild` bez admin checku, bez batch.
**PERF-8** — `detail_data.php` similar tracks: full table scan + haversine v PHP.
**PERF-10** — Google Fonts bez `display=swap`.
**PERF-11** — Žádný minify, žádný bundling, 4 různé CDN domény.
**PERF-12** — 9 theme CSS, flash of wrong theme.
**PERF-14** — `index_data.php` 7+ SQL per page load bez cache.
**PERF-15** — `stats.php` 6 separate SELECTů bez indexů.
**PERF-16** — `photo_import.php` EXIF batch dedup přes `orig_name` (N+1).
**PERF-17** — Lucide `@latest` bez `defer`.

### Low

**PERF-18** — `generate_photo_thumb` čte EXIF 2× per soubor.

---

## B.5 Frontend (19 nálezů)

### High

**FE-1 — Dvojí theming systém** koexistuje (starý `css/theme-*.css` + nový Tailwind `assets/css/app.css`) s konfliktními CSS proměnnými.

**FE-2 — Globální `window.*` namespace polluce** jako komunikační bus. Tvrdé pořadí načítání skriptů.

**FE-3 — Duplikovaný kód map** (~200 řádků) v `detail-map.js`, `filter-map.js`, `nearby-map.js`, `compare-map.js`.

**FE-4 — Dvě paralelní implementace column visibility** v `index-columns.js` a `index-ui.js`. Aktivní konflikt.

**FE-5 — AJAX race condition v `index-ajax.js`** bez `AbortController`.

### Medium

**FE-6** — Theme flicker (FOUC) v `theme.js` — aplikace tématu po DOMContentLoaded.
**FE-7** — Haversine duplikovaná v `filter-core.js`, `detail-elevation.js`.
**FE-8** — `formatDuration` duplikovaná v `nearby-data.js` a `filter-ui.js`.
**FE-9** — Chart.js instance management konflikt v `index-ajax.js` vs `index-chart.js`.
**FE-10** — Leaflet/Chart.js z CDN bez SRI; `leaflet.vectorgrid@latest`.
**FE-11** — `overlayMapillary` inicializován i bez tokenu → 401 spam v konzoli.
**FE-12** — `detail-elevation.js` injectuje `<style>` tag do `<head>` dynamicky.
**FE-13** — URL state — filtry nesynchronizovány s URL (no `pushState`).

### Low

**FE-14** — `mobile-init.js` používá inline styles pro layout.
**FE-15** — `theme-tester.js` bez garance neloadování v produkci.
**FE-16** — i18n v JS nekonzistentní (3 různé vzory).
**FE-17** — `index-ajax.js` obsahuje Chart.js init (patří do `index-chart.js`).
**FE-18** — Alpine.js `@3.x.x` floating verze.
**FE-19** — `variables-reference.css` riziko nechtěného includování.

---

## B.6 Accessibility (30 nálezů)

### Blockers (WCAG A)

**A11Y-001 — `<html lang="cs">` hardcoded** v `layout_header.php:23`, `login.php:50`, `photo_import.php:223`, `rebuild_thumbs.php:64`. Aplikace má 8 jazyků.

**A11Y-002 — Žádný skip-to-content link** v `layout_header.php`.

**A11Y-003 — Mobile drawer bez focus trapu, return-focus, `aria-modal`** v `layout_header.php:165-221`.

**A11Y-005 — Tabulka tras `table_tracks.php`** bez `<thead>`, `<caption>`, `scope`, `aria-sort`, emoji-only headers.

**A11Y-010 — Import dropzone bez keyboard access** v `import.php:382`; status bez `aria-live`.

**A11Y-012 — Lightbox bez focus trapu, return-focus, dialog `aria-label`** (`js/lightbox.js:1-137`).

**A11Y-016/017 — Kontrast** — `--accent-color` selhává AA pro normální text v `classic`/`minimal`/`blue` témech; `--text-muted` selhává v `lightgray` (3.86:1).

**A11Y-022 — `<canvas id="elev">`** bez `role`, `aria-label`, datové tabulky-alternativy.

### High (WCAG A/AA)

**A11Y-004** — Language switcher dropdown bez `role="menu"`, šipek, `aria-expanded`.
**A11Y-006** — Sortable headers bez `aria-sort`.
**A11Y-007** — Login form bez `autocomplete`, `for/id`, `role="alert"` na chybě.
**A11Y-008** — Setup wizard labely bez `for/id`.
**A11Y-009** — Edit form bez `aria-invalid`, `role="alert"` na flash.
**A11Y-011** — Filter `<details><summary>` s vnořeným `<label>` + `<input>`.
**A11Y-013** — Lightbox caption ukazuje pouze timestamp, nemá alt text.
**A11Y-014** — Admin banner bez `role="region"`, emoji button bez accessible name.
**A11Y-015** — Settings radio inputs s `display:none` → nedosažitelné klávesnicí/SR.
**A11Y-018** — Filter badge "speed" `#e53935` (4.23:1), "stationary" `#9e9d24` (2.88:1) selhávají kontrast.
**A11Y-019** — Save message `#28a745` (3.13:1) selhává.
**A11Y-020** — Žádný `prefers-reduced-motion` v žádném CSS.
**A11Y-021** — Mapy bez `role="img"`, `aria-label`, skip-to-data.
**A11Y-024** — `#sidebarToggle` bez `aria-expanded`, `aria-controls`, accessible name.
**A11Y-026** — `prompt()` pro preset name a `confirm()` pro delete — nepřístupné.
**A11Y-027** — `<input type="file">` bez label/aria-label.

### Medium / Low

**A11Y-023** — Filter používá `<main class="filter-main">` uvnitř hlavního `<main>` (duplicitní landmark).
**A11Y-025** — Lucide SVG bez `aria-hidden="true"` u dekorativních ikon.
**A11Y-028** — Chip filter používá `aria-pressed` na `<a>` (chybná ARIA).
**A11Y-029** — Import status pouze barvou.
**A11Y-030** — Touch target size — některé buttony pod 24×24px.

---

## B.7 DevOps (16 nálezů)

### Critical

**OPS-01 — `_errors.log` ve veřejné `uploads/`.** Stack traces přístupné přes `/uploads/_errors.log`.

### High

**OPS-02** — Žádný CI/CD pipeline.
**OPS-03** — Auto-migrace na každý request (viz DB-4, PERF-1).
**OPS-04** — `setup.php` a `install.sql` bez `.htaccess` blokace po instalaci.
**OPS-05** — Environment detekce přes `SERVER_ADDR` nespolehlivá za reverse proxy.
**OPS-06** — `.htaccess` chybí HTTPS redirect, gzip, cache, blokace `.log`/`.sql`/`.md`.

### Medium

**OPS-07** — Konflikt `X-Frame-Options` mezi `.htaccess` (SAMEORIGIN) a `security.php` (DENY).
**OPS-08** — Chybí CSP header.
**OPS-09** — Žádné backupové skripty v repu.
**OPS-10** — CDN bez verzí (`@latest`, `@3.x.x`).
**OPS-11** — Chybí HSTS.
**OPS-12** — Nestrukturované logy.

### Low

**OPS-13** — Žádný health endpoint.
**OPS-14** — Žádný Docker / compose pro lokální dev.
**OPS-15** — Tailwind build artifact (`app.css`) commitnut bez build pipeline.
**OPS-16** — Žádný rollback runbook.

---

## B.8 Backend Architecture (15 nálezů)

### Critical

**BE-1** — `db.php` je bootstrap + migration runner + global state.
**BE-10** — Chybí auth check na 6 photo AJAX endpoints (viz SEC-005).

### High

**BE-2** — `photos.php` 1429 řádků, 8 AJAX endpointů, neaplikuje vlastní `*_data + *_view` vzor.
**BE-3** — Nekonzistentní CSRF ochrana.
**BE-4** — Žádné `declare(strict_types=1)`.
**BE-7/BE-9** — `$pdo->quote()` se string interpolací (SQL injection vzor).

### Medium

**BE-5** — Duplikovaná theme/lang init na 15 stránkách.
**BE-6** — `recalc_*.php` a `rebuild_thumbs.php` synchronní web skripty bez time protection.
**BE-8** — `die()` vrací HTTP 200 při chybách.
**BE-11** — Žádný autoloader / Composer.
**BE-12** — Detail/edit navigace načítá všechna ID.
**BE-13** — `generate_thumb()` bez tile cache.
**BE-15** — Podobné trasy algoritmus načítá všechny.

### Low

**BE-14** — Nestrukturovaný `error_log()`.

---

# ČÁST C — Implementační balíčky pro Claude Code Pro

> **Jak používat:** Každý balíček (TASK-NN) je samostatný úkol. Otevři Claude Code Pro v adresáři projektu, zkopíruj sekci **"Prompt k zadání"** a vlož ji jako první zprávu. Claude má všechna potřebná data v promptu — nemusí číst celý kód, jen konkrétní soubory.
>
> **Doporučení k workflow:**
> 1. Před každou TASK proveď commit aktuálního stavu (`git commit -am "checkpoint before TASK-NN"`).
> 2. Po dokončení spusť aplikaci lokálně, projdi smoke test scénář z taskové sekce **"Acceptance"**.
> 3. Pokud test selže, použij Claude Code k debugu v téže session, nebo otevři novou session s konkrétním promptem.
> 4. Commitni změny pod jasným názvem (např. `TASK-01: XXE + uploads htaccess hardening`).

## TASK-01 — XXE protection + uploads/ PHP block + error log relocation

**Fáze:** 0 (P0 critical security)
**Pokrývá nálezy:** SEC-001, SEC-002, SEC-019, SEC-020
**Odhad:** 1–1.5 h
**Závislosti:** žádné — udělej jako první.

### Cíl
Zablokovat 3 nejhorší attack vectors: (a) XXE v GPX parserech, (b) PHP execution v `uploads/`, (c) `_errors.log` přístupný přes web.

### Acceptance criteria
- [ ] V `includes/gpx_parser.php` `simplexml_load_file()` používá `LIBXML_NONET` a má pre-check na `<!DOCTYPE`/`<!ENTITY`.
- [ ] Existuje helper `safe_load_gpx($path)` v `includes/gpx_parser.php`, exportovaný funkcí.
- [ ] Všech 5 míst (`export_geojson.php:24`, `export_kml.php:20`, `heatmap.php:86`, `includes/nearby_data.php:60`, `includes/generate_thumb.php:87`) používá `safe_load_gpx()`.
- [ ] `uploads/.htaccess` blokuje `*.php`, `*.phtml`, `*.phps`, `*.log`, povoluje `*.gpx`, `*.jpg`, `*.png`, `*.webp`, `*.json`.
- [ ] Error log přesunut do `logs/errors.log` (mimo webroot Apache), složka `logs/` má vlastní `.htaccess` s `Require all denied`. `config.php:58` aktualizován.
- [ ] Smoke test: aplikace funguje normálně, GPX import projde, fotky se zobrazují.
- [ ] Negative test: vytvoř testovací GPX s `<!DOCTYPE ... <!ENTITY xxe SYSTEM "file:///etc/passwd">`, importuj — musí být odmítnut nebo entity neresolvována. `track_name` v DB nesmí obsahovat obsah `/etc/passwd`.

### Soubory k úpravě
- `includes/gpx_parser.php` (řádek 7-13 přidat safe loader)
- `export_geojson.php:24`, `export_kml.php:20`
- `heatmap.php:86`
- `includes/nearby_data.php:60`
- `includes/generate_thumb.php:87`
- `config.php:58`
- nový `uploads/.htaccess`
- nový `logs/.htaccess`
- nová složka `logs/` (`.gitkeep`)

### Prompt k zadání
```
Implementuj TASK-01 z AUDIT_REPORT.md (řádky pro tuto sekci najdeš v dokumentu).
Cíl: zablokovat XXE útoky v GPX parserech, blokovat PHP execution v uploads/,
přesunout error log mimo webroot.

KONKRÉTNÍ KROKY:

1) Otevři `includes/gpx_parser.php`. Hned za `<?php` přidej tuto novou funkci:

   ```php
   function safe_load_gpx(string $filePath): ?SimpleXMLElement {
       if (!is_readable($filePath)) return null;
       $head = @file_get_contents($filePath, false, null, 0, 4096);
       if ($head === false) return null;
       if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
           error_log("safe_load_gpx: refusing DTD/ENTITY in $filePath");
           return null;
       }
       libxml_use_internal_errors(true);
       $xml = simplexml_load_file($filePath, 'SimpleXMLElement', LIBXML_NONET);
       return $xml !== false ? $xml : null;
   }
   ```

2) V `parse_gpx()` (řádek ~7-13) nahraď stávající `simplexml_load_file($filePath)`
   voláním `safe_load_gpx($filePath)`. Pokud vrátí null, vrať z `parse_gpx()` null.

3) V těchto souborech nahraď `@simplexml_load_file($x)` nebo `simplexml_load_file($x)`
   voláním `safe_load_gpx($x)`:
   - export_geojson.php:24
   - export_kml.php:20
   - heatmap.php:86
   - includes/nearby_data.php:60
   - includes/generate_thumb.php:87

   Pokud `safe_load_gpx` vrátí null, soubor přeskoč (continue v loopu) nebo
   vrať vhodnou chybu (404/422) v non-loop kontextu.

4) Vytvoř soubor `uploads/.htaccess` s tímto obsahem:

   ```apache
   # Block PHP execution
   <FilesMatch "\.(ph[pt]?|phtml|phps)$">
       Require all denied
   </FilesMatch>
   # Block sensitive files
   <FilesMatch "\.(log|sql|env|ini|sh|bak)$">
       Require all denied
   </FilesMatch>
   # Disable directory listing
   Options -Indexes
   # Force download for HTML/SVG to prevent XSS
   <FilesMatch "\.(html?|svg|xml)$">
       Header set Content-Disposition "attachment"
   </FilesMatch>
   ```

5) Vytvoř složku `logs/` se souborem `.gitkeep`. Vytvoř `logs/.htaccess`:

   ```apache
   Require all denied
   ```

6) V `config.php` najdi řádek `ini_set('error_log', __DIR__ . '/uploads/_errors.log');`
   (řádek ~58) a změň na `ini_set('error_log', __DIR__ . '/logs/errors.log');`.

7) Aktualizuj `.gitignore` — přidej řádek `logs/*.log` (pokud tam není).

8) NEMAZAT starý uploads/_errors.log — nech ho být, ale aplikace už do něj nepíše.
   Doporuč uživateli, ať starý smaže po deployi.

VERIFIKACE:
- Spusť `php -l includes/gpx_parser.php` — musí projít.
- Otevři aplikaci v prohlížeči, naviguj na index — musí fungovat.
- Pokud máš testovací GPX, importuj ho.

Hotovo? Zobraz mi shrnutí změn a navrhni commit message.
```

---

## TASK-02 — CSRF + admin auth na všech mutačních endpointech

**Fáze:** 0
**Pokrývá nálezy:** SEC-003, SEC-004, SEC-005, SEC-013, BE-3, BE-10, QR-13
**Odhad:** 2–2.5 h
**Závislosti:** žádné (paralelně s TASK-01).

### Cíl
Každý POST endpoint, který mění stav, musí mít: (a) `csrf_verify()`, (b) `$_isAdmin` check tam, kde je to mutace adminem (ne visitor preview).

### Acceptance criteria
- [ ] `api_toggle_favorite.php` má `csrf_verify()` na začátku POST větve.
- [ ] `admin.php` POST handlery (`save_access_config`, `save_uploads_config`) mají `csrf_verify()`; formuláře v HTML mají `<?= csrf_field() ?>`.
- [ ] `photos.php` AJAX endpointy `upload`, `assign`, `delete`, `update_caption`, `bulk_delete`, `bulk_assign` mají `$_isAdmin` check + `csrf_verify()`.
- [ ] Frontend JS pro photos posílá `X-CSRF-Token` header nebo `_csrf_token` v body.
- [ ] `layout_header.php` má `<meta name="csrf-token" content="<?= csrf_token() ?>">` pro JS přístup.
- [ ] Smoke test: jako přihlášený admin smaž fotku, přiřaď fotku, uploaduj GPX, změň visible_pages — vše funguje.
- [ ] Negative test (pokud máš jiný browser/inkognito): jako visitor zkus POST na `photos.php?ajax=delete` — vrátí 403.

### Soubory k úpravě
- `api_toggle_favorite.php` (přidat CSRF check po POST detekci)
- `admin.php` (CSRF check + csrf_field v 2 formulářích)
- `photos.php:36-364` (6 AJAX endpointů — gate auth + CSRF)
- `includes/layout_header.php` (přidat meta tag s CSRF tokenem v <head>)
- inline JS v `photos.php` nebo `js/` — frontend musí token poslat

### Prompt k zadání
```
Implementuj TASK-02 z AUDIT_REPORT.md.
Cíl: přidat CSRF ochranu a $_isAdmin check na všechny mutační POST endpointy.

KROKY:

1) V `includes/layout_header.php` najdi <head> blok. Přidej do něj:

   <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">

   Tento meta tag musí být na všech stránkách. Pokud `csrf_token()` není
   dostupné (např. v setup.php), guard if (function_exists('csrf_token')).

2) V `api_toggle_favorite.php` hned po `if ($_SERVER['REQUEST_METHOD'] !== 'POST')`
   bloku přidej:

   if (!csrf_verify()) {
       http_response_code(403);
       echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
       exit;
   }

3) V `admin.php` najdi 2 POST handlery (řádek ~12 `save_access_config` a
   ~33 `save_uploads_config`). Před první if-blok POST handleru přidej:

   if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
       http_response_code(403);
       die('Invalid CSRF token');
   }

   Pak v HTML obou formulářů (najdi <form method="post" ...>) přidej hned
   za otevírací <form> tag:

   <?= csrf_field() ?>

4) V `photos.php` najdi 8 AJAX dispatcherů (od řádku ~36).
   Pro tyto akce přidej obě kontroly: upload, assign, delete, update_caption,
   bulk_delete, bulk_assign. Vlož je hned na začátek if-bloku (před hlavní logiku):

   if (!$_isAdmin) {
       http_response_code(403);
       echo json_encode(['ok' => false, 'msg' => 'Forbidden']);
       exit;
   }
   if (!csrf_verify()) {
       http_response_code(403);
       echo json_encode(['ok' => false, 'msg' => 'CSRF']);
       exit;
   }

   Pro toggle_visible a bulk_visible nech existující $_isAdmin check, jen přidej csrf_verify.

5) V inline JS na konci `photos.php` (po HTML) najdi fetch() volání pro AJAX.
   Před každým fetch přidej čtení CSRF tokenu z meta:

   const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

   A do fetch options přidej:

   fetch(url, {
       method: 'POST',
       headers: { 'X-CSRF-Token': csrfToken },  // <-- nový
       body: formData
   })

   Pokud používáš FormData, můžeš místo header alternativně appendnout:
   formData.append('_csrf_token', csrfToken);

6) Otevři `includes/security.php` a ověř, že csrf_verify() čte i hlavičku
   X-CSRF-Token (řádek ~57: $_SERVER['HTTP_X_CSRF_TOKEN']). Ano, čte. OK.

VERIFIKACE:
- Otevři aplikaci, přihlas se jako admin.
- Smaž fotku přes UI — musí proběhnout úspěšně.
- Otevři DevTools Network, zkontroluj, že request obsahuje X-CSRF-Token header.
- Otevři inkognito okno, zkus POST přes curl bez tokenu — musí vrátit 403.

Hotovo? Shrnutí + commit message.
```

---

## TASK-03 — Path traversal a filename sanitization

**Fáze:** 0
**Pokrývá nálezy:** SEC-016, SEC-007 (partial), BE-7, BE-9, QR-7, DB-12
**Odhad:** 1.5 h
**Závislosti:** TASK-01 (uploads/.htaccess existuje).

### Cíl
Zabránit path traversal přes user-controlled filename a nahradit `$pdo->quote()` patterny prepared statements.

### Acceptance criteria
- [ ] `includes/helpers.php` `uploads_fs()` validuje, že výsledná cesta zůstává uvnitř base složky.
- [ ] `edit.php` POST handler validuje `filename` — pouze `[\w\-]+\.gpx` přes regex.
- [ ] `api_bulk_action.php` řádky 84 a 105 používají `prepare()` + `execute()` místo `$pdo->quote()` se string concat.
- [ ] `recalc_activity.php` (řádky 39-43) používá `?` placeholders + `str_repeat` pro IN clause.
- [ ] `filter_data.php save_cleaned` validuje GPX content velikost (max 50 MB) a DOCTYPE pre-check.
- [ ] Smoke test: import, edit (rename track), delete — vše funguje.
- [ ] Negative test: pokus se přes API změnit filename na `../config.php` — odmítnuto.

### Soubory k úpravě
- `includes/helpers.php` (úprava `uploads_fs()`)
- `edit.php` (filename validace v POST handleru)
- `api_bulk_action.php:84,105`
- `recalc_activity.php:39-43`
- `includes/filter_data.php` (save_cleaned ~ řádky 77-167)

### Prompt k zadání
```
Implementuj TASK-03 z AUDIT_REPORT.md.
Cíl: filename sanitization a nahrazení $pdo->quote() patternů prepared statements.

KROKY:

1) V `includes/helpers.php` najdi funkci uploads_fs() (kolem řádku 20).
   Uprav ji tak, aby ověřovala, že výsledná cesta nevybočí mimo base:

   function uploads_fs(string $relative = ''): string {
       static $base = null;
       if ($base === null) {
           $configured = get_app_config('uploads_fs_path', '');
           $base = $configured ?: (__DIR__ . '/../uploads/');
           $base = rtrim($base, "/\\") . DIRECTORY_SEPARATOR;
       }
       if ($relative === '') return $base;
       // Strip any leading slashes/backslashes
       $clean = ltrim($relative, "/\\");
       $candidate = $base . $clean;
       // Verify it stays inside base after resolution
       $realBase = realpath($base);
       if ($realBase === false) return $candidate; // base doesn't exist yet
       $parent = dirname($candidate);
       $realParent = realpath($parent);
       if ($realParent !== false && strpos($realParent, $realBase) !== 0) {
           throw new RuntimeException("Path traversal blocked: $relative");
       }
       return $candidate;
   }

2) V `edit.php` najdi POST handler (sekce kde se UPDATE tracks). Najdi řádek,
   který zpracovává $_POST['filename'] nebo používá $data['filename']. Hned po
   `$data['filename'] = $_POST['filename'] ?? '';` (nebo podobně) přidej:

   $data['filename'] = preg_replace('/[^\w\-.]+/', '', $data['filename'] ?? '');
   if (!$data['filename'] || !str_ends_with(strtolower($data['filename']), '.gpx')) {
       $flash = 'Invalid filename — must be alphanumeric + .gpx extension';
       // pokračuj na render bez UPDATE
       goto render_form; // nebo $errors[] = ...; nepokračuj k UPDATE
   }

   Pokud kód nemá goto strukturu, vytvoř $errors array a podmiň UPDATE.

3) V `api_bulk_action.php` najdi řádky ~84 a ~105 s patternem:

   $catId = (int)$pdo->query("SELECT id FROM categories WHERE name = " . $pdo->quote($value))->fetchColumn();

   Nahraď:

   $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
   $stmt->execute([$value]);
   $catId = (int)$stmt->fetchColumn();

   Pro IN clause (pokud existuje), použij placeholders:

   $placeholders = implode(',', array_fill(0, count($arr), '?'));
   $stmt = $pdo->prepare("SELECT id FROM categories WHERE name IN ($placeholders)");
   $stmt->execute($arr);

4) V `recalc_activity.php` najdi řádky 39-43 s patternem:

   WHERE tc.track_id = ? AND c.name IN ('" . implode("','", $activityCategories) . "')

   Přepiš na prepared statement s placeholders:

   $placeholders = implode(',', array_fill(0, count($activityCategories), '?'));
   $sql = "... WHERE tc.track_id = ? AND c.name IN ($placeholders) ...";
   $stmt = $pdo->prepare($sql);
   $stmt->execute(array_merge([$trackId], $activityCategories));

5) V `includes/filter_data.php` najdi handler save_cleaned (kolem řádku 77).
   Před file_put_contents přidej validace:

   $gpxContent = $_POST['gpx_content'] ?? '';
   if (strlen($gpxContent) > 50 * 1024 * 1024) {
       http_response_code(413);
       echo json_encode(['error' => 'GPX too large (max 50 MB)']);
       exit;
   }
   $head = substr($gpxContent, 0, 4096);
   if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
       http_response_code(400);
       echo json_encode(['error' => 'GPX contains DTD/ENTITY']);
       exit;
   }
   // Sanitize originalName:
   $rawName = $_POST['original_name'] ?? 'cleaned.gpx';
   $baseName = preg_replace('/[^\w\-]+/', '_', pathinfo($rawName, PATHINFO_FILENAME));
   if (!$baseName) $baseName = 'cleaned';
   // Pokračuj s existující logikou, ale použij $baseName

VERIFIKACE:
- `php -l` na všech upravených souborech.
- Spusť aplikaci. Otevři edit page existující trasy, zkus uložit normální změnu.
- Zkus přes DevTools změnit filename input na "../config.php" a submit — musí zobrazit error, neuložit.

Hotovo? Shrnutí + commit.
```

---

## TASK-04 — XSS escape pro photo caption + JS safe JSON helper

**Fáze:** 0
**Pokrývá nálezy:** SEC-012, SEC-015
**Odhad:** 1 h
**Závislosti:** TASK-02 (caption update už CSRF chráněn).

### Cíl
Eliminovat stored XSS přes photo captions a `</script>` injection v JSON embeded v `<script>` tagách.

### Acceptance criteria
- [ ] `js/detail-map.js` escapuje `photo.caption` před vložením do Leaflet tooltipu (textContent nebo escape funkce).
- [ ] `photos.php update_caption` handler sanitizuje caption (strip control chars, limit 1000 znaků).
- [ ] V `includes/helpers.php` existuje `js_safe_json($value)` helper s `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS`.
- [ ] Všechny `*_view.php` soubory, které embedují JSON do `<script>` tagu, používají `js_safe_json()`.
- [ ] Negative test: nastav caption na `<img src=x onerror=alert(1)>` přes API, otevři detail trasy — žádný alert.

### Soubory k úpravě
- `js/detail-map.js:323-330` (escape caption)
- `photos.php` (update_caption handler)
- `includes/helpers.php` (přidat `js_safe_json`)
- `includes/filter_view.php:317-320` a další `*_view.php` s JSON embeddingem

### Prompt k zadání
```
Implementuj TASK-04 z AUDIT_REPORT.md.
Cíl: vyřešit XSS přes photo caption a </script> injection v JSON embedech.

KROKY:

1) V `js/detail-map.js` najdi blok kolem řádku 323-330, kde se staví tooltip:

   const lines = [];
   if (photo.taken_at) lines.push("📅 " + photo.taken_at);
   if (photo.caption)  lines.push("💬 " + photo.caption);
   circle.bindTooltip(lines.join("<br>") || "📸", ...);

   Přidej escape funkci nahoře v souboru (nebo na začátek IIFE):

   function escHtml(s) {
       return String(s).replace(/[&<>"']/g, m => ({
           '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
       }[m]));
   }

   Pak v lines pushi escapuj:

   if (photo.taken_at) lines.push("📅 " + escHtml(photo.taken_at));
   if (photo.caption)  lines.push("💬 " + escHtml(photo.caption));

2) V `photos.php` najdi update_caption AJAX handler (kolem řádku 258).
   Před UPDATE statement přidej sanitizaci:

   $caption = $_POST['caption'] ?? '';
   // Strip control chars except \t \n, limit length
   $caption = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $caption);
   $caption = mb_substr($caption, 0, 1000);

3) V `includes/helpers.php` přidej na konec nový helper:

   function js_safe_json($value): string {
       return json_encode(
           $value,
           JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE
       );
   }

4) V těchto souborech najdi `<?= json_encode(...) ?>` uvnitř <script>...</script>
   bloků a nahraď za `<?= js_safe_json(...) ?>`:
   - includes/detail_view.php (řádky ~317-320 a okolí)
   - includes/filter_view.php (řádky ~317-320 a okolí)
   - includes/nearby_view.php
   - includes/compare_view.php
   - includes/index_view.php (pokud má script bloky s json_encode)

   Použij Grep pro `json_encode` v `includes/*_view.php`.

   POZOR: Nepřepisuj json_encode tam, kde je výstup do AJAX response
   (Content-Type: application/json) — tam stačí klasický json_encode.
   Týká se to pouze inline <script> bloků.

5) V `js/detail-map.js` najdi i další místa, kde se vykresluje user content
   do HTML (popup, tooltip, marker label). Zkontroluj a escapuj.
   Hlavně: `bindPopup`, `bindTooltip`, `.innerHTML` přiřazení.

VERIFIKACE:
- Otevři detail trasy s fotkami v Leaflet mapě.
- V DB nebo přes admin UI nastav caption pro jednu fotku na text:
  <img src=x onerror=alert('XSS')>
- Reload detail stránky, najed na marker — tooltip ukáže text doslovně,
  žádný alert.

Hotovo? Shrnutí + commit.
```

---

## TASK-05 — Setup.php hardening + reinstall protection

**Fáze:** 1
**Pokrývá nálezy:** SEC-006, SEC-010
**Odhad:** 1.5 h
**Závislosti:** TASK-02 (CSRF infrastruktura existuje).

### Cíl
`setup.php` musí být odolný proti CSRF, newline injection do `.env`, race condition při instalaci a opakovanému spuštění po DB chybě.

### Acceptance criteria
- [ ] `setup.php` má vlastní lightweight CSRF guard (před `config.php` se neloaduje).
- [ ] Funkce `env_safe()` strippne `\r\n\0` z hodnot zapisovaných do `.env`.
- [ ] Při existenci `.env` setup okamžitě vrátí HTTP 403 a die.
- [ ] Setup vyžaduje existenci souboru `.setup-allowed` v root projektu (admin ho vytvoří před prvním spuštěním); setup ho po úspěšné instalaci smaže.
- [ ] Setup je dostupný **pouze z localhost** (127.0.0.1, ::1) — jinak 403.
- [ ] Při DB chybě uprostřed instalace zůstanou `.env` i `setup.php` neaktivní (nutno vytvořit nový `.setup-allowed`).
- [ ] `INSTALL.md` aktualizován o krok: "Před `setup.php` vytvoř prázdný soubor `.setup-allowed` v rootu".

### Prompt k zadání
```
Implementuj TASK-05 z AUDIT_REPORT.md. Cíl: zabezpečit setup.php.

KROKY:

1) Na začátek `setup.php` (po <?php) přidej pre-flight checks:

   // 1) Pouze z localhost
   $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
   if (!in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
       http_response_code(403);
       die('Setup is only accessible from localhost.');
   }

   // 2) Pokud .env existuje, instalace je hotová
   if (is_file(__DIR__ . '/.env')) {
       http_response_code(403);
       die('Application already installed. Delete .env to reinstall.');
   }

   // 3) Vyžadovat .setup-allowed marker (anti-race + anti-reinstall)
   if (!is_file(__DIR__ . '/.setup-allowed')) {
       http_response_code(403);
       die('Setup is not enabled. Create empty file .setup-allowed in the project root first.');
   }

   // 4) Vlastní lightweight CSRF (config.php se možná ještě nedá načíst)
   if (session_status() === PHP_SESSION_NONE) session_start();
   if (empty($_SESSION['setup_csrf'])) {
       $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
   }
   $setupCsrf = $_SESSION['setup_csrf'];

   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
       $submitted = $_POST['_csrf'] ?? '';
       if (!hash_equals($setupCsrf, $submitted)) {
           http_response_code(403);
           die('Invalid CSRF token.');
       }
   }

2) Najdi všechny <form method="post" ...> v setup.php. Hned za otevírací tag přidej:

   <input type="hidden" name="_csrf" value="<?= htmlspecialchars($setupCsrf) ?>">

3) Najdi sekci, kde se sestavuje $envContent (řádky ~185-220). Před konkatenaci
   přidej helper a aplikuj na všechny user-supplied hodnoty:

   function env_safe(string $v): string {
       // Strip CR/LF/NULL — prevent newline injection do .env
       return str_replace(["\r", "\n", "\0"], '', $v);
   }

   Pak v sestavování $envContent obal každou ${...} hodnotu:

   $envContent = "# GPX Manager — configuration\n"
       . "DB_HOST=" . env_safe($s['dbHost']) . "\n"
       . "DB_NAME=" . env_safe($s['dbName']) . "\n"
       . "DB_USER=" . env_safe($s['dbUser']) . "\n"
       . "DB_PASS=" . env_safe($s['dbPass']) . "\n\n"
       . "ADMIN_USER=" . env_safe($s['adminUser']) . "\n"
       . "ADMIN_PASS_HASH=" . env_safe($s['adminHash']) . "\n\n"
       . "ADMIN_IPS=" . env_safe($adminIPs) . "\n\n"
       . "TF_API_KEY=" . env_safe($tfKey) . "\n"
       . "MAPYCOM_API_KEY=" . env_safe($mapyKey) . "\n"
       . "MAPILLARY_TOKEN=" . env_safe($mapillaryTok) . "\n";

4) Najdi sekci úspěšné instalace (po file_put_contents .env, po DB import).
   Před @unlink(__FILE__) přidej:

   // Vymaž marker — disable další setup spuštění
   @unlink(__DIR__ . '/.setup-allowed');

5) Aktualizuj `INSTALL.md` a `instructions/cs.md`, `instructions/en.md`.
   Přidej krok mezi "vytvoř DB" a "spusť setup.php":

   "PŘED spuštěním setup.php vytvoř v root složce prázdný soubor s názvem
   `.setup-allowed`. Bez tohoto souboru setup odmítne spustit (ochrana proti
   nechtěnému přeinstalování). Setup ho po úspěšné instalaci sám smaže."

   V .gitignore přidej řádek:
   .setup-allowed

VERIFIKACE:
- Smaž .env (pokud existuje), vytvoř .setup-allowed.
- Otevři setup.php — projde.
- Bez .setup-allowed otevři setup.php — 403.
- Dokonči instalaci — .env vznikne, .setup-allowed zmizí.
- Otevři znovu setup.php — 403 "already installed".
- Vyzkoušej POST s `admin_ips=127.0.0.1%0AADMIN_USER=evil` — env_safe to musí zlikvidovat.

Hotovo? Shrnutí + commit.
```

---

## TASK-06 — Login rate limiting (IP-based) + logout CSRF

**Fáze:** 1
**Pokrývá nálezy:** SEC-009, SEC-024, SEC-030 (partial)
**Odhad:** 1.5 h
**Závislosti:** TASK-02.

### Cíl
Nahradit cookie-bound brute force ochranu IP-based limiterem v DB. Logout musí být POST + CSRF.

### Acceptance criteria
- [ ] Nová DB tabulka `login_attempts (ip, attempted_at, success)` vytvořená v migrations.
- [ ] `login.php` čítá pokusy z IP za posledních 5 min; po 5 neúspěšných zablokuje IP na 15 min.
- [ ] Logout změněn z GET (`?logout=1`) na POST formulář s CSRF tokenem.
- [ ] Po logoutu se cookie session smaže (`setcookie(session_name(), '', ...)`).
- [ ] Po **úspěšném** loginu volá `session_regenerate_id(true)`.
- [ ] Smoke test: 6× špatné heslo z jedné IP → 6. pokus odmítnut na 15 min.

### Prompt k zadání
```
Implementuj TASK-06 z AUDIT_REPORT.md. Cíl: IP-based rate limiting + POST logout.

KROKY:

1) V `install.sql` (i v auto-migraci v db.php) přidej tabulku:

   CREATE TABLE IF NOT EXISTS login_attempts (
       id INT AUTO_INCREMENT PRIMARY KEY,
       ip VARCHAR(45) NOT NULL,
       attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       success TINYINT(1) NOT NULL DEFAULT 0,
       INDEX idx_la_ip_time (ip, attempted_at)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

   Pokud bude TASK-09 (migration runner) hotov, dej to do migrations/0009_login_attempts.sql.
   Jinak přidej do install.sql + auto-migrace v db.php.

2) V `login.php` nahraď session-bound logiku IP-based:

   // Místo $_SESSION['login_attempts']:
   $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

   // Spočítej neúspěšné pokusy za posledních 15 minut z této IP:
   $stmt = $pdo->prepare(
       "SELECT COUNT(*) FROM login_attempts
        WHERE ip = ? AND success = 0
          AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
   );
   $stmt->execute([$ip]);
   $failedCount = (int)$stmt->fetchColumn();

   if ($failedCount >= 5) {
       $error = 'Too many failed attempts. Try again in 15 minutes.';
       // Render login form, NEPROVEĎ password check
   } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
       // ... existující CSRF check ...
       $user = $_POST['user'] ?? '';
       $pass = $_POST['pass'] ?? '';
       $valid = ($user === ADMIN_USER) && password_verify($pass, ADMIN_PASS_HASH);

       // Log attempt
       $pdo->prepare("INSERT INTO login_attempts (ip, success) VALUES (?, ?)")
           ->execute([$ip, $valid ? 1 : 0]);

       if ($valid) {
           session_regenerate_id(true); // POVINNÉ proti session fixation
           $_SESSION['is_admin'] = true;
           $_SESSION['admin_via'] = 'login';
           // Cleanup old failed attempts pro tuto IP (volitelné, ale uklidí tabulku)
           $pdo->prepare("DELETE FROM login_attempts WHERE ip = ? AND success = 0")->execute([$ip]);
           header('Location: index.php');
           exit;
       } else {
           $error = 'Invalid credentials.';
       }
   }

3) Logout — najdi v login.php nebo includes/auth.php `if (isset($_GET['logout']))`.
   Změň na POST + CSRF:

   if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
       if (!csrf_verify()) {
           http_response_code(403);
           die('CSRF');
       }
       $_SESSION = [];
       if (ini_get('session.use_cookies')) {
           $p = session_get_cookie_params();
           setcookie(session_name(), '', time() - 42000,
               $p['path'], $p['domain'], $p['secure'], $p['httponly']);
       }
       session_destroy();
       header('Location: login.php');
       exit;
   }

4) V `includes/auth.php` najdi banner s "Odhlasit se" odkazem
   (řádek ~50 `<a href="login.php?logout=1">`). Změň na POST form:

   <form method="post" action="login.php" style="display:inline;">
       <?= csrf_field() ?>
       <input type="hidden" name="logout" value="1">
       <button type="submit" style="...stejné styly jako původní <a>...">
           Odhlásit se
       </button>
   </form>

5) Stejné v `includes/public_access.php` pokud tam logout odkaz je.

6) Po úspěšném loginu vždy regenerate session ID — je v kroku 2.

7) Cleanup cron (volitelné, doporučené): přidej do `recalc_*.php` nebo
   samostatného skriptu mazání starých login_attempts:

   DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

VERIFIKACE:
- Otevři login.php, zadej špatné heslo 5× — 6. pokus zobrazí "Too many failed".
- Vyčkej 15 min NEBO smaž ručně řádky `DELETE FROM login_attempts WHERE ip = '127.0.0.1';`.
- Přihlas se správně, odhlas se přes nový POST button — funguje.
- DevTools: zkontroluj, že `Cookie: PHPSESSID=...` se po logoutu změní.

Hotovo? Shrnutí + commit.
```

---

## TASK-07 — CSV injection + DoS endpoints + heatmap admin gate

**Fáze:** 1
**Pokrývá nálezy:** SEC-008, SEC-011, SEC-018, SEC-026
**Odhad:** 1 h

### Cíl
Excel CSV injection v exportu, DoS-able heatmap rebuild, image bomb protection, nearby DoS.

### Acceptance criteria
- [ ] `export.php` escapuje hodnoty začínající `=`, `+`, `-`, `@`, `\t`, `\r` prefixem `'`.
- [ ] `heatmap.php?ajax=rebuild` vyžaduje `$_isAdmin` + CSRF + rate limit 1×/hodinu.
- [ ] `nearby_data.php` AJAX má per-IP rate limit max 20 req/min.
- [ ] `photo_helper.php` validuje `getimagesize` před GD decode; odmítne obrázky > 50 megapixelů nebo > 50 MB.

### Prompt k zadání
```
Implementuj TASK-07 z AUDIT_REPORT.md. Cíl: CSV injection + DoS protection.

KROKY:

1) V `export.php` najdi `fputcsv` cyklus (řádek ~78-106). Přidej helper a obal volání:

   function csv_safe(?string $v): ?string {
       if ($v === null || $v === '') return $v;
       if (preg_match('/^[=+\-@\t\r]/', $v)) return "'" . $v;
       return $v;
   }

   // V cyklu:
   fputcsv($out, [
       $r['id'],
       csv_safe($r['filename']),
       csv_safe($r['track_name']),
       csv_safe($r['alt_title']),
       csv_safe($r['categories'] ?? ''),
       csv_safe($r['color']),
       csv_safe($r['device']),
       // ... všechny ostatní text fields přes csv_safe
       $r['distance_km'], $r['ascent'], // numerické nechat
   ], ';', '"', '\\');

2) V `heatmap.php` najdi řádek `if (isset($_GET['ajax']) && $_GET['ajax'] === 'rebuild') {`.
   Přidej hned na začátek bloku:

   if (empty($_SESSION['is_admin'])) {
       http_response_code(403);
       header('Content-Type: application/json');
       echo json_encode(['error' => 'Admin only']);
       exit;
   }
   if (!csrf_verify()) {
       http_response_code(403);
       header('Content-Type: application/json');
       echo json_encode(['error' => 'CSRF']);
       exit;
   }
   // Rate limit: 1× za hodinu (file-based marker)
   $rebuildMarker = uploads_fs('_heatmap_rebuild.marker');
   if (is_file($rebuildMarker) && (time() - filemtime($rebuildMarker)) < 3600) {
       http_response_code(429);
       header('Content-Type: application/json');
       echo json_encode(['error' => 'Rebuild ran recently. Try again later.']);
       exit;
   }
   @file_put_contents($rebuildMarker, time());

3) V `includes/nearby_data.php` přidej rate limit. Najdi začátek AJAX dispatchu.
   Přidej:

   $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
   $rateFile = sys_get_temp_dir() . '/gpx_nearby_' . md5($ip) . '.txt';
   $now = time();
   $window = 60; // 1 min
   $maxReq = 20;
   $hits = is_file($rateFile)
       ? array_filter(explode("\n", file_get_contents($rateFile)), fn($t) => (int)$t > $now - $window)
       : [];
   if (count($hits) >= $maxReq) {
       http_response_code(429);
       echo json_encode(['error' => 'Too many requests']);
       exit;
   }
   $hits[] = (string)$now;
   file_put_contents($rateFile, implode("\n", $hits), LOCK_EX);

4) V `includes/photo_helper.php` najdi `generate_photo_thumb` nebo
   `process_single_photo` (kolem řádku 284-330). Před GD decode přidej:

   $info = @getimagesize($srcPath);
   if (!$info || !isset($info[0], $info[1])) {
       error_log("photo_helper: getimagesize failed for $srcPath");
       return false;
   }
   [$w, $h, $type] = $info;
   // Image bomb protection
   if ($w * $h > 50_000_000) {  // > 50 megapixels
       error_log("photo_helper: image too large {$w}x{$h}");
       return false;
   }
   $fileSize = filesize($srcPath);
   if ($fileSize > 50 * 1024 * 1024) {  // > 50 MB
       error_log("photo_helper: file too large $fileSize");
       return false;
   }
   if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
       error_log("photo_helper: unsupported type $type");
       return false;
   }

5) V `photos.php` upload handler (kolem řádku 36-121) přidej před foreach
   limity počtu souborů:

   $maxFiles = 200;
   $files = $_FILES['photos'] ?? [];
   if (isset($files['name']) && is_array($files['name'])) {
       if (count($files['name']) > $maxFiles) {
           echo json_encode(['ok' => false, 'msg' => "Max $maxFiles photos per upload"]);
           exit;
       }
   }

VERIFIKACE:
- Vytvoř testovací GPX s track_name "=cmd|'/C calc'!A0", importuj.
- Exportuj CSV — otevři v editoru, hodnota musí být "'=cmd|..." (s ').
- Otevři heatmap, jako visitor — pokus o rebuild → 403.
- Jako admin spusť rebuild dvakrát rychle za sebou — druhý 429.
- Spam nearby AJAX → po 20 req v minutě → 429.

Hotovo? Shrnutí + commit.
```

---

## TASK-08 — HTTP security headers, CSP, SRI hashes

**Fáze:** 1
**Pokrývá nálezy:** SEC-021, SEC-022, OPS-08, OPS-11, FE-10, FE-18, PERF-17, OPS-10
**Odhad:** 2 h
**Závislosti:** TASK-01 (uploads/.htaccess).

### Cíl
CSP, HSTS, Permissions-Policy. Pinout CDN verze a přidat SRI hashe (nebo doporučit self-host).

### Acceptance criteria
- [ ] `includes/security.php send_security_headers()` přidává CSP, HSTS (v produkci), Permissions-Policy.
- [ ] `X-XSS-Protection` odebrán (deprecated).
- [ ] Všechny CDN scripty mají konkrétní verzi (žádné `@latest`, `@3.x.x`).
- [ ] Všechny CDN scripty mají `integrity="sha384-..."` a `crossorigin="anonymous"`.
- [ ] Lucide a Leaflet scripty mají `defer`.
- [ ] Google Fonts URL obsahuje `&display=swap`.
- [ ] Smoke test: aplikace funguje, DevTools Console nehlásí CSP violation. Pokud hlásí, allowlist domain v CSP nebo upravit kód.

### Prompt k zadání
```
Implementuj TASK-08 z AUDIT_REPORT.md. Cíl: HTTP security headers a SRI/pin CDN.

KROKY:

1) V `includes/security.php` funkce `send_security_headers()` (řádek ~63-68).
   Nahraď tělo:

   function send_security_headers(): void {
       header('X-Content-Type-Options: nosniff');
       header('X-Frame-Options: DENY');
       // X-XSS-Protection odebráno — deprecated, harmful in some browsers
       header('Referrer-Policy: strict-origin-when-cross-origin');
       header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=()');
       header('Cross-Origin-Opener-Policy: same-origin');

       if (defined('APP_ENV') && APP_ENV !== 'local') {
           header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
       }

       // CSP — pokrývá všechny CDN použité v aplikaci
       $csp = "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; "
            . "style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.googleapis.com; "
            . "img-src 'self' data: blob: https:; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "connect-src 'self' https://commons.wikimedia.org "
            . "https://*.tile.openstreetmap.org https://*.tile.opentopomap.org "
            . "https://server.arcgisonline.com https://api.mapy.com "
            . "https://*.tile.thunderforest.com https://tiles.mapillary.com "
            . "https://api.open-meteo.com; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self';";
       header("Content-Security-Policy: $csp");
   }

   POZN: 'unsafe-inline' pro script-src je tu kvůli inline <script> blokům
   v *.php souborech. Long-term cíl je odstranit, ale to je samostatná task.

2) Najdi všechny CDN scripty v projektu (Grep `unpkg.com`, `cdnjs.cloudflare.com`,
   `cdn.jsdelivr.net`, `fonts.googleapis.com` v *.php).

   Pinout konkrétní verze a přidej SRI. Pro každou knihovnu vyhledej aktuální
   verzi a hash na https://www.srihash.org/.

   Příklady náhrad (ověř aktuální hashe!):

   PŘED:
   <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>

   PO:
   <script src="https://unpkg.com/alpinejs@3.14.1/dist/cdn.min.js"
           integrity="sha384-AKTUALNI_HASH"
           crossorigin="anonymous" defer></script>

   PŘED:
   <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>

   PO:
   <script src="https://unpkg.com/lucide@0.516.0/dist/umd/lucide.min.js"
           integrity="sha384-AKTUALNI_HASH"
           crossorigin="anonymous" defer></script>

   Pro Leaflet, leaflet-gpx, Chart.js, leaflet.vectorgrid, leaflet.fullscreen,
   leaflet.heat — pinout verze (najdi v aktuálních souborech), získej SRI hash.

   Soubory k úpravě (Grep pro <script src="https://):
   - includes/layout_header.php (Alpine, Lucide, Google Fonts)
   - includes/detail_view.php (Leaflet, leaflet-gpx, Chart.js, vectorgrid)
   - includes/filter_view.php (Leaflet, Chart.js, vectorgrid)
   - includes/nearby_view.php (Leaflet)
   - includes/compare_view.php (Leaflet)
   - heatmap.php (Leaflet, leaflet.heat)
   - map_search.php (Leaflet)
   - login.php (Alpine, Lucide)
   - photo_import.php (pokud má CDN)
   - rebuild_thumbs.php (pokud má CDN)

3) V `includes/layout_header.php` najdi Google Fonts <link>:

   <link href="https://fonts.googleapis.com/css2?family=Inter:..."
         rel="stylesheet">

   Změň URL: přidej `&display=swap` na konec query stringu.
   Také přidej `crossorigin` na <link rel="preconnect" href="https://fonts.gstatic.com">.

4) Otevři aplikaci, projdi všechny stránky, sleduj DevTools Console pro CSP
   violations. Pokud najdeš violation:
   - Pokud je legitimní zdroj — přidej do CSP (např. nový tile server).
   - Pokud je injection — opravit kód.

POZN: SRI hashe je pracné získávat ručně. Pokud preferuješ self-hosting,
stáhni knihovny do `assets/vendor/` a hostuj lokálně (řeší to OPS-10, FE-10,
FE-18 jednou ranou). Self-hosting je samostatná task TASK-XX (případně udělej
v rámci této, pokud zbývá čas).

VERIFIKACE:
- Otevři aplikaci, DevTools Network — všechny CDN scripty mají integrity attribut.
- DevTools Console — žádné "Refused to load" CSP errory.
- Otevři securityheaders.com (dev tool) nebo curl -I — vidíš CSP, HSTS, Permissions-Policy.
- Vyzkoušej iframe embed na test stránce — musí se odmítnout (X-Frame-Options DENY).

Hotovo? Shrnutí + commit.
```

---

## TASK-09 — Migration runner (replace auto-migrations)

**Fáze:** 2
**Pokrývá nálezy:** DB-4, QR-1, BE-1, OPS-03, PERF-1, QR-23
**Odhad:** 2.5 h
**Závislosti:** žádné (lze paralelně s FÁZE 0/1).

### Cíl
Nahradit "ALTER TABLE on catch" pattern v `db.php` číslovaným migration systémem.

### Acceptance criteria
- [ ] Existuje složka `migrations/` s číslovanými SQL soubory (`0001_baseline.sql`, `0002_add_is_favorite.sql`, atd.).
- [ ] Existuje `migrate.php` CLI/web skript, který spouští pending migrace v transakci nebo s `GET_LOCK`.
- [ ] DB má tabulku `schema_migrations (name VARCHAR PK, applied_at TIMESTAMP)`.
- [ ] `includes/db.php` zredukováno na ~15 řádků (jen PDO factory + require migration runner při bootstrap, ale runner běží jen pokud schema_version v cache souboru/static var nesouhlasí).
- [ ] `init_app_config()` zavolán pouze pokud cache stále stará.
- [ ] Smoke test: aplikace funguje, migrace běží jen jednou (ne na každý request).

### Prompt k zadání
```
Implementuj TASK-09 z AUDIT_REPORT.md. Cíl: nahradit auto-migrace systémovým runnerem.

KROKY:

1) Vytvoř složku `migrations/` v rootu projektu.

2) Vytvoř `migrations/0001_baseline.sql` — zkopíruj sem celý obsah `install.sql`
   (CREATE TABLE pro tracks, categories, track_categories, track_photos,
   app_config, filter_presets + INSERT IGNORE pro default config).

   POZN: Pro existující instalaci by se 0001 neměla spouštět (data již existují).
   To řešíme přes bootstrap step v migrate.php.

3) Vytvoř `migrations/0002_is_favorite.sql`:

   ALTER TABLE tracks ADD COLUMN IF NOT EXISTS is_favorite TINYINT(1) NOT NULL DEFAULT 0 AFTER trackpoints_count;
   CREATE INDEX IF NOT EXISTS idx_tracks_favorite ON tracks (is_favorite);

   POZN: `IF NOT EXISTS` na ALTER funguje až od MariaDB 10.5 / MySQL 8.0.29.
   Pro starší použij pattern:

   SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracks'
                AND COLUMN_NAME = 'is_favorite');
   SET @sql := IF(@x = 0,
       'ALTER TABLE tracks ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0',
       'SELECT "column already exists" AS info');
   PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

4) Pro každou existující auto-migraci v db.php vytvoř samostatný soubor:
   - 0003_difficulty.sql
   - 0004_activity_type.sql
   - 0005_track_photos.sql (CREATE TABLE)
   - 0006_track_photos_file_hash.sql
   - 0007_track_photos_caption.sql
   - 0008_track_photos_img_direction.sql
   - 0009_track_photos_visible.sql

5) Vytvoř `migrations/0010_schema_migrations.sql`:

   CREATE TABLE IF NOT EXISTS schema_migrations (
       name VARCHAR(120) PRIMARY KEY,
       applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

6) Vytvoř `migrate.php` v rootu projektu:

   <?php
   require_once __DIR__ . '/config.php';

   $pdo = new PDO(
       'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
       DB_USER, DB_PASS,
       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
   );

   // Ensure schema_migrations table exists
   $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
       name VARCHAR(120) PRIMARY KEY,
       applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

   // Acquire advisory lock — prevent concurrent migration
   $lockOk = (int)$pdo->query("SELECT GET_LOCK('gpx_manager_migrate', 10)")->fetchColumn();
   if (!$lockOk) {
       die("Could not acquire migration lock\n");
   }

   try {
       // Get applied migrations
       $applied = $pdo->query("SELECT name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
       $applied = array_flip($applied);

       // Find migration files
       $files = glob(__DIR__ . '/migrations/*.sql');
       sort($files);

       $ranCount = 0;
       foreach ($files as $file) {
           $name = basename($file);
           if (isset($applied[$name])) continue;

           // 0001_baseline.sql se nespouští pokud tabulka tracks existuje
           if ($name === '0001_baseline.sql') {
               $exists = $pdo->query("SHOW TABLES LIKE 'tracks'")->rowCount() > 0;
               if ($exists) {
                   $pdo->prepare("INSERT INTO schema_migrations (name) VALUES (?)")
                       ->execute([$name]);
                   echo "Skipped (already installed): $name\n";
                   continue;
               }
           }

           $sql = file_get_contents($file);
           echo "Applying: $name ... ";
           try {
               $pdo->exec($sql);
               $pdo->prepare("INSERT INTO schema_migrations (name) VALUES (?)")
                   ->execute([$name]);
               echo "OK\n";
               $ranCount++;
           } catch (PDOException $e) {
               echo "FAILED: " . $e->getMessage() . "\n";
               throw $e;
           }
       }
       echo "Done. $ranCount migration(s) applied.\n";
   } finally {
       $pdo->query("SELECT RELEASE_LOCK('gpx_manager_migrate')");
   }

7) Smaž **veškeré** auto-migrace v `includes/db.php` (řádky ~16-107).
   Soubor zredukuj na:

   <?php
   require_once __DIR__ . '/../config.php';

   try {
       $pdo = new PDO(
           'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
           DB_USER, DB_PASS,
           [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
       );
   } catch (PDOException $e) {
       error_log("db.php connection: " . $e->getMessage());
       die(APP_ENV === 'local'
           ? "Database connection failed: " . $e->getMessage()
           : "Chyba připojení k databázi.");
   }

   require_once __DIR__ . '/app_config.php';
   init_app_config();

8) Uprav `includes/app_config.php` funkci `init_app_config()` aby běžela
   pouze pokud cache file nesouhlasí s aktuální verzí, nebo přes static
   per-process flag:

   function init_app_config(): void {
       static $done = false;
       if ($done) return;
       global $pdo;

       // Skip if app_config has been already initialized (check pomocí cache key)
       $cached = get_app_config('_init_done', '0');
       if ($cached === '1') {
           $done = true;
           return;
       }

       // Insert defaults (idempotent)
       $defaults = [
           'allowed_themes' => '["classic","dark","darkblue","darkgreen","blue","green","minimal","lightgray","brown"]',
           'allowed_langs'  => '["cs","en","de","sk","es","fr","pl","it"]',
           'visible_pages'  => '["stats","calendar","heatmap","map_search","nearby","filter","compare","settings"]',
           '_init_done'     => '1',
       ];
       $stmt = $pdo->prepare("INSERT IGNORE INTO app_config (config_key, config_value) VALUES (?, ?)");
       foreach ($defaults as $k => $v) {
           $stmt->execute([$k, $v]);
       }
       $done = true;
   }

9) Aktualizuj `INSTALL.md` a `instructions/*.md`:

   Po nahrání souborů + vytvoření .env:
   "Spusť `php migrate.php` v command-line nebo otevři migrate.php v prohlížeči
   (jen z localhost!). Po prvním spuštění migrace doinstaluje schéma,
   při dalších spuštěních doinstaluje pouze nové migrace."

10) Volitelné: zajisti, že migrate.php je dostupné jen z CLI nebo localhost:

    if (PHP_SAPI !== 'cli') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!in_array($ip, ['127.0.0.1', '::1'])) {
            http_response_code(403);
            die('Migrate is CLI or localhost only.');
        }
    }

VERIFIKACE:
- Lokálně: nuluj DB, spusť `php migrate.php` — projde, vytvoří všechny tabulky.
- Spusť znovu — řekne "Done. 0 migration(s) applied."
- Otevři aplikaci, projdi index/detail/filter — vše funguje.
- DevTools Network → tab Timing: TTFB by mělo být zlevnit (žádných 9 SELECT navíc).

Hotovo? Shrnutí + commit.
```

---

## TASK-10 — Kritické DB indexy + DECIMAL místo FLOAT

**Fáze:** 2
**Pokrývá nálezy:** DB-1, DB-2, DB-3, DB-5, DB-6, DB-7, PERF-15
**Odhad:** 1.5 h
**Závislosti:** TASK-09 (migration runner existuje).

### Cíl
Přidat chybějící indexy pro top hot path queries + opravit datové typy.

### Acceptance criteria
- [ ] Migrace `0011_indexes.sql` přidává:
  - Index `(track_id, visible)` na `track_photos`
  - Index na `category_id` v `track_categories`
  - Index na `distance_km`, `ascent`, `elevation_max`, `speed_max`, `duration` v `tracks` (pro stats rekordy)
- [ ] Migrace `0012_file_hash_sha256.sql` rozšiřuje `file_hash` na VARCHAR(64).
- [ ] `index_data.php` `DATE(date_start)` filtr přepsán na range (`date_start >= :from AND date_start < :to`).
- [ ] `EXPLAIN` pro top 5 queries potvrzuje použití indexů (orientačně, dokumentuj v komentáři).

### Prompt k zadání
```
Implementuj TASK-10 z AUDIT_REPORT.md. Cíl: kritické indexy a fix FLOAT → DECIMAL.

KROKY:

1) Vytvoř `migrations/0011_indexes.sql`:

   -- Index na track_photos (track_id, visible) — pro photo_count subquery
   CREATE INDEX idx_tp_track_visible ON track_photos (track_id, visible);

   -- Index na category_id v track_categories — pro EXISTS subquery
   CREATE INDEX idx_tc_category ON track_categories (category_id);

   -- Indexy pro stats.php rekordy
   CREATE INDEX idx_tracks_distance ON tracks (distance_km);
   CREATE INDEX idx_tracks_ascent ON tracks (ascent);
   CREATE INDEX idx_tracks_elev_max ON tracks (elevation_max);
   CREATE INDEX idx_tracks_speed_max ON tracks (speed_max);
   CREATE INDEX idx_tracks_duration ON tracks (duration);

2) Vytvoř `migrations/0012_file_hash_sha256.sql`:

   ALTER TABLE tracks MODIFY COLUMN file_hash VARCHAR(64) DEFAULT NULL;
   ALTER TABLE track_photos MODIFY COLUMN file_hash VARCHAR(64) DEFAULT NULL;

   POZN: stávající SHA-1 hashe (40 znaků) zůstávají platné — VARCHAR(64) je
   forward-compatible. Nové uploady mohou postupně přejít na SHA-256.

3) V `import.php` a `photo_helper.php` najdi `sha1_file()` volání. Pro nové
   uploady **postupně přejít** na `hash_file('sha256', $path)`. Toto je
   breaking pro dedup s existujícími daty, proto:

   OPTION A (recommended pro nyní): nech sha1, jen rozšiř sloupec.
   OPTION B: přepiš na sha256, vyžaduje data migration.

   V této task zvol OPTION A.

4) Najdi v `includes/index_data.php` (a `helpers.php buildFilterSQL`)
   patternem `DATE(date_start)`. Pokud existuje, přepiš na range:

   PŘED:
   WHERE DATE(date_start) = :date_filter

   PO:
   WHERE date_start >= :from AND date_start < :to
   (kde $params['from'] = $date, $params['to'] = $date + 1 day)

   POZN: Pokud filter UI dovoluje pouze "den" filter, převeď na range.
   Pokud dovoluje range "od—do", už pravděpodobně to dělá range — zkontroluj.

5) Spusť migrace: `php migrate.php`.

6) Spusť ručně v MySQL klientu EXPLAIN pro tyto dotazy a ověř `key` column:

   EXPLAIN SELECT * FROM tracks WHERE distance_km > 10 ORDER BY distance_km DESC LIMIT 1;
   -- Mělo by ukázat key = idx_tracks_distance

   EXPLAIN SELECT * FROM tracks t WHERE EXISTS (
     SELECT 1 FROM track_categories tc WHERE tc.track_id = t.id AND tc.category_id = 5
   );
   -- Mělo by ukázat key = idx_tc_category

   EXPLAIN SELECT id, (SELECT COUNT(*) FROM track_photos p WHERE p.track_id = t.id AND p.visible = 1)
   FROM tracks t LIMIT 10;
   -- Mělo by ukázat key = idx_tp_track_visible

   Zapiš výsledky jako komentář na konec migrations/0011_indexes.sql.

VERIFIKACE:
- `php migrate.php` — projde bez errorů.
- Aplikace funguje normálně.
- Stats stránka načte se znatelně rychleji (subjektivně).

Hotovo? Shrnutí + commit.
```

---

## TASK-11 — N+1 fixes: detail navigation, similar tracks, nearby

**Fáze:** 2
**Pokrývá nálezy:** PERF-2, PERF-3, PERF-8, BE-12, BE-15, DB-9, DB-10
**Odhad:** 2.5 h
**Závislosti:** TASK-10 (indexy).

### Cíl
Eliminovat O(n) PHP scans přes všechny trasy pro: (a) prev/next navigaci, (b) similar tracks, (c) nearby search.

### Acceptance criteria
- [ ] `includes/detail_data.php` prev/next dělá 2 cílené SQL queries (nikoliv `SELECT id FROM tracks`).
- [ ] `edit.php` prev/next stejně.
- [ ] `tracks` má nové sloupce `centroid_lat DOUBLE`, `centroid_lon DOUBLE` (migrace 0013).
- [ ] Backfill skript naplní `centroid_*` z existujících `bounds` JSON.
- [ ] `includes/nearby_data.php` SQL pre-filter na `centroid_*` BBOX, GPX parsing eliminován (nebo limit 5 souborů).
- [ ] Similar tracks v `detail_data.php` SQL pre-filter na centroid BBOX (max 50 km).

### Prompt k zadání
```
Implementuj TASK-11 z AUDIT_REPORT.md. Cíl: O(n) → O(log n) pro detail/nearby/similar.

KROKY:

1) Vytvoř `migrations/0013_centroid.sql`:

   ALTER TABLE tracks ADD COLUMN centroid_lat DOUBLE DEFAULT NULL AFTER bounds;
   ALTER TABLE tracks ADD COLUMN centroid_lon DOUBLE DEFAULT NULL AFTER centroid_lat;
   CREATE INDEX idx_tracks_centroid ON tracks (centroid_lat, centroid_lon);

2) Vytvoř `migrations/0014_centroid_backfill.php` (PHP, ne SQL):

   <?php
   require_once __DIR__ . '/../config.php';
   require_once __DIR__ . '/../includes/db.php';

   $stmt = $pdo->query("SELECT id, bounds FROM tracks WHERE centroid_lat IS NULL AND bounds IS NOT NULL");
   $update = $pdo->prepare("UPDATE tracks SET centroid_lat = ?, centroid_lon = ? WHERE id = ?");
   $updated = 0;
   while ($r = $stmt->fetch()) {
       $b = json_decode($r['bounds'], true);
       if (!is_array($b)) continue;
       // bounds format: {minLat, maxLat, minLon, maxLon} or [[s,w],[n,e]]
       $minLat = $b['minLat'] ?? ($b[0][0] ?? null);
       $maxLat = $b['maxLat'] ?? ($b[1][0] ?? null);
       $minLon = $b['minLon'] ?? ($b[0][1] ?? null);
       $maxLon = $b['maxLon'] ?? ($b[1][1] ?? null);
       if ($minLat === null || $maxLat === null) continue;
       $update->execute([($minLat + $maxLat) / 2, ($minLon + $maxLon) / 2, $r['id']]);
       $updated++;
   }
   echo "Updated $updated tracks with centroid.\n";

   Spustit jednou: `php migrations/0014_centroid_backfill.php`.

3) V `import.php` po INSERT/UPDATE tracks přidej výpočet centroid:

   $bounds = json_decode($insertedBounds, true);
   if ($bounds) {
       $minLat = $bounds['minLat'] ?? null;
       $maxLat = $bounds['maxLat'] ?? null;
       $minLon = $bounds['minLon'] ?? null;
       $maxLon = $bounds['maxLon'] ?? null;
       if ($minLat !== null && $maxLat !== null) {
           $cLat = ($minLat + $maxLat) / 2;
           $cLon = ($minLon + $maxLon) / 2;
           $pdo->prepare("UPDATE tracks SET centroid_lat = ?, centroid_lon = ? WHERE id = ?")
               ->execute([$cLat, $cLon, $trackId]);
       }
   }

4) V `includes/detail_data.php` najdi navigation sekci (řádky ~175-195).
   Nahraď fetch všech ID dvěma cílenými queries:

   $sortBy = $sort_by; // už whitelistnuté
   $sortDir = strtoupper($sort_dir) === 'DESC' ? 'DESC' : 'ASC';

   // Current track's sort value
   $cur = $pdo->prepare("SELECT `$sortBy` AS v FROM tracks WHERE id = ?");
   $cur->execute([$track_id]);
   $curVal = $cur->fetchColumn();

   // Previous (smaller value, or same value with smaller id)
   $prevOp = $sortDir === 'ASC' ? '<' : '>';
   $stmt = $pdo->prepare(
       "SELECT id FROM tracks
        WHERE (`$sortBy` $prevOp ? OR (`$sortBy` = ? AND id $prevOp ?))
        ORDER BY `$sortBy` " . ($sortDir === 'ASC' ? 'DESC' : 'ASC') . ", id " . ($sortDir === 'ASC' ? 'DESC' : 'ASC') . "
        LIMIT 1"
   );
   $stmt->execute([$curVal, $curVal, $track_id]);
   $prevId = $stmt->fetchColumn() ?: null;

   // Next (larger value)
   $nextOp = $sortDir === 'ASC' ? '>' : '<';
   $stmt = $pdo->prepare(
       "SELECT id FROM tracks
        WHERE (`$sortBy` $nextOp ? OR (`$sortBy` = ? AND id $nextOp ?))
        ORDER BY `$sortBy` $sortDir, id $sortDir
        LIMIT 1"
   );
   $stmt->execute([$curVal, $curVal, $track_id]);
   $nextId = $stmt->fetchColumn() ?: null;

5) V `edit.php` najdi podobnou navigaci (řádky ~77-108). Použij stejný vzor.

6) V `includes/nearby_data.php` najdi blok fetchAll všech tras + GPX parse loop.
   Přepiš na SQL pre-filter:

   $lat = (float)($_GET['lat'] ?? 0);
   $lon = (float)($_GET['lon'] ?? 0);
   $radiusKm = (float)($_GET['radius'] ?? 50);

   // Hrubý BBOX filter (1 stupeň ~ 111 km)
   $latDelta = $radiusKm / 111;
   $lonDelta = $radiusKm / (111 * cos(deg2rad($lat)));

   $stmt = $pdo->prepare("
       SELECT id, filename, track_name, distance_km, bounds, centroid_lat, centroid_lon, ...
       FROM tracks
       WHERE centroid_lat BETWEEN ? AND ?
         AND centroid_lon BETWEEN ? AND ?
         AND bounds IS NOT NULL
       LIMIT 100
   ");
   $stmt->execute([$lat - $latDelta, $lat + $latDelta, $lon - $lonDelta, $lon + $lonDelta]);
   $candidates = $stmt->fetchAll();

   // Pro každého kandidáta spočítej přesnou haversine vzdálenost od centroid
   // Eliminuj fáze 2 — žádný GPX parse v request path

   foreach ($candidates as &$c) {
       $c['dist_km'] = haversine($lat, $lon, $c['centroid_lat'], $c['centroid_lon']);
   }
   usort($candidates, fn($a, $b) => $a['dist_km'] <=> $b['dist_km']);
   $top = array_slice($candidates, 0, 30);

   Pokud potřebuješ přesnější (např. closest point on track, ne centroid),
   načti BBOX a počítej z bounds rohů — bez GPX parse.

7) V `includes/detail_data.php` najdi similar tracks (řádky ~37-103).
   Přidej SQL pre-filter před PHP scoring:

   // Pre-filter: trasy s centroid do 100 km od current track
   $curCentroidStmt = $pdo->prepare("SELECT centroid_lat, centroid_lon FROM tracks WHERE id = ?");
   $curCentroidStmt->execute([$track_id]);
   $cur = $curCentroidStmt->fetch();
   if ($cur && $cur['centroid_lat'] !== null) {
       $latDelta = 100 / 111;
       $lonDelta = 100 / (111 * cos(deg2rad($cur['centroid_lat'])));
       $stmt = $pdo->prepare("
           SELECT id, track_name, distance_km, ascent, activity_type, bounds, centroid_lat, centroid_lon
           FROM tracks
           WHERE id != ?
             AND bounds IS NOT NULL
             AND centroid_lat BETWEEN ? AND ?
             AND centroid_lon BETWEEN ? AND ?
           LIMIT 100
       ");
       $stmt->execute([
           $track_id,
           $cur['centroid_lat'] - $latDelta, $cur['centroid_lat'] + $latDelta,
           $cur['centroid_lon'] - $lonDelta, $cur['centroid_lon'] + $lonDelta
       ]);
       $allTracks = $stmt->fetchAll();
   } else {
       // Fallback — žádný centroid, ponech původní logiku ale s LIMIT
       $allTracks = $pdo->prepare("SELECT ... FROM tracks WHERE id != ? LIMIT 200")
                        ->execute([$track_id])->fetchAll();
   }

VERIFIKACE:
- Spusť migrace + backfill.
- Otevři detail libovolné trasy — prev/next funguje, žádná timeout.
- Otevři nearby s polohou — výsledky se objeví do 1 s (bývalo 3-5 s).
- DevTools Performance: zaznamenat profil obnovení detail stránky, time < 600ms.

Hotovo? Shrnutí + commit.
```

---

## TASK-12 — Kompletní `.htaccess` (HTTPS, gzip, cache, blocky, HSTS)

**Fáze:** 3
**Pokrývá nálezy:** OPS-01, OPS-04, OPS-06, OPS-07, OPS-11, PERF-13
**Odhad:** 1 h
**Závislosti:** TASK-08 (HSTS koordinace), TASK-01 (uploads/.htaccess existuje).

### Cíl
Apache `.htaccess` musí kromě stávající ochrany blokovat sensitive paths, vynutit HTTPS, komprimovat statika, cache assety na 1 rok, sjednotit security headers se security.php.

### Acceptance criteria
- [ ] HTTPS redirect (`RewriteCond %{HTTPS} off`).
- [ ] `mod_deflate` zapnut pro text/html, text/css, application/javascript, application/json, image/svg+xml.
- [ ] `mod_expires` + `Cache-Control: max-age=31536000, immutable` pro CSS/JS/PNG/JPG/WebP/woff2.
- [ ] `Cache-Control: no-cache` pro `.php` a `.html`.
- [ ] Blokace `setup.php`, `install.sql`, `*.log`, `*.sql`, `*.md`, `*.bak`, `composer.*`, `package*.json`.
- [ ] `X-Frame-Options` odebrán z `.htaccess` (zachová se v `security.php` pro single source of truth).
- [ ] Smoke test: aplikace funguje, DevTools Network ukazuje gzip + cache hlavičky.

### Prompt k zadání
```
Implementuj TASK-12 z AUDIT_REPORT.md. Cíl: kompletní .htaccess konfigurace.

KROKY:

1) Otevři root `.htaccess`. Nahraď celý obsah (zachovej stávající upload limity)
   touto referenční verzí. Zkontroluj, jestli stávající upload limity (php_value)
   odpovídají; pokud ano, ponech.

   # ============================================================
   # GPX Manager — .htaccess
   # ============================================================

   # ---- Upload limity (zachovej z původního) ----
   <IfModule mod_php.c>
       php_value upload_max_filesize 200M
       php_value post_max_size 210M
       php_value max_execution_time 300
       php_value memory_limit 256M
   </IfModule>

   # ---- Charset ----
   AddDefaultCharset UTF-8
   AddCharset UTF-8 .txt .css .js .html

   # ---- Zákaz výpisu adresářů ----
   Options -Indexes

   # ---- HTTPS redirect ----
   <IfModule mod_rewrite.c>
       RewriteEngine On
       RewriteCond %{HTTPS} off
       RewriteCond %{HTTP:X-Forwarded-Proto} !=https
       RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
   </IfModule>

   # ---- Blokace dotfiles ----
   <FilesMatch "^\.">
       Require all denied
   </FilesMatch>

   # ---- Blokace PHP konfiguračních a citlivých souborů ----
   <FilesMatch "^(config\.php|phpinfo\.php|info\.php|test\.php)$">
       Require all denied
   </FilesMatch>

   # ---- Blokace instalačních souborů (po instalaci) ----
   <FilesMatch "^(setup\.php|install\.php|migrate\.php)$">
       Require all denied
   </FilesMatch>

   # POZN: setup.php se sám smaže po úspěšné instalaci.
   # migrate.php by mělo být dostupné jen z CLI nebo localhost (interně kontroluje).

   # ---- Blokace SQL, logů, záloh, .md, lock files, package files ----
   <FilesMatch "\.(sql|log|bak|md|sh|lock)$">
       Require all denied
   </FilesMatch>

   <FilesMatch "^(composer\.(json|lock)|package(-lock)?\.json|tailwind\.config\.js)$">
       Require all denied
   </FilesMatch>

   # ---- HTTP bezpečnostní hlavičky ----
   # POZN: X-Frame-Options a další security headers nastavuje security.php
   # (single source of truth). Zde pouze static defaults pro non-PHP requesty.
   <IfModule mod_headers.c>
       Header always set X-Content-Type-Options "nosniff"
       Header always set Referrer-Policy "strict-origin-when-cross-origin"
   </IfModule>

   # ---- Komprese (mod_deflate) ----
   <IfModule mod_deflate.c>
       AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css
       AddOutputFilterByType DEFLATE application/javascript application/json
       AddOutputFilterByType DEFLATE image/svg+xml application/xml
   </IfModule>

   # ---- Cache static assets ----
   <IfModule mod_expires.c>
       ExpiresActive On
       ExpiresByType text/css "access plus 30 days"
       ExpiresByType application/javascript "access plus 30 days"
       ExpiresByType image/png "access plus 30 days"
       ExpiresByType image/jpeg "access plus 30 days"
       ExpiresByType image/webp "access plus 30 days"
       ExpiresByType image/svg+xml "access plus 30 days"
       ExpiresByType font/woff2 "access plus 1 year"
       ExpiresByType font/woff "access plus 1 year"
   </IfModule>

   <IfModule mod_headers.c>
       <FilesMatch "\.(css|js|png|jpe?g|webp|gif|ico|svg|woff2?)$">
           Header set Cache-Control "public, max-age=2592000"
       </FilesMatch>

       <FilesMatch "\.(php|html?)$">
           Header set Cache-Control "no-cache, no-store, must-revalidate"
           Header set Pragma "no-cache"
       </FilesMatch>
   </IfModule>

2) Z `.htaccess` ODSTRAŇ řádek `Header always set X-Frame-Options "SAMEORIGIN"`
   (pokud tam je). X-Frame-Options nastavuje security.php (DENY).

3) Volitelné: pokud chceš `Cache-Control: immutable` (silnější cache), je
   potřeba **versioning assetu v URL** (např. `app.css?v=abc123`). Bez verzování
   ponech `max-age=2592000` (30 dní).

   Pro implementaci version helpers viz TASK-14 (CI build).

4) Pokud `uploads/.htaccess` ještě nebyl vytvořen (TASK-01), vytvoř ho teď
   (viz TASK-01 step 4).

VERIFIKACE:
- `curl -I http://your-server/css/style.css` → vidíš `Content-Encoding: gzip`
  a `Cache-Control: public, max-age=2592000`.
- `curl -I http://your-server/install.sql` → 403.
- `curl -I http://your-server/composer.json` → 403.
- Otevři HTTPS verzi → funguje. HTTP verze → redirect na HTTPS.

Hotovo? Shrnutí + commit.
```

---

## TASK-13 — Composer + PSR-4 autoloader + strict_types

**Fáze:** 3
**Pokrývá nálezy:** BE-11, BE-4, QR-8
**Odhad:** 2 h
**Závislosti:** žádné.

### Cíl
Zavést Composer s files autoloadem pro existující `includes/*.php` a PSR-4 autoload pro budoucí `src/`. Postupně přidat `declare(strict_types=1)` do kritických souborů.

### Acceptance criteria
- [ ] `composer.json` existuje s `psr-4: GpxManager\: src/` a `files: [includes/helpers.php, includes/security.php, includes/app_config.php]`.
- [ ] `vendor/autoload.php` v `config.php` na začátku (po require_once .env load).
- [ ] `declare(strict_types=1);` přidáno do: `includes/helpers.php`, `includes/gpx_parser.php`, `includes/photo_helper.php`, `includes/security.php`, `includes/app_config.php`, `includes/db.php`.
- [ ] `composer.json` má dev dependency `phpunit/phpunit` (pro budoucí testy).
- [ ] `.gitignore` má `/vendor/`.
- [ ] `composer install` funguje.
- [ ] Smoke test: aplikace funguje, žádné TypeErrory.

### Prompt k zadání
```
Implementuj TASK-13 z AUDIT_REPORT.md. Cíl: Composer autoload + strict_types.

KROKY:

1) Vytvoř `composer.json` v rootu projektu:

   {
       "name": "puschpull/gpx-manager",
       "description": "Self-hosted GPX & photo manager",
       "type": "project",
       "license": "proprietary",
       "require": {
           "php": "^8.0"
       },
       "require-dev": {
           "phpunit/phpunit": "^10.0"
       },
       "autoload": {
           "psr-4": {
               "GpxManager\\": "src/"
           },
           "files": [
               "includes/helpers.php",
               "includes/security.php",
               "includes/app_config.php"
           ]
       },
       "config": {
           "sort-packages": true
       }
   }

2) Spusť `composer install` (vyžaduje Composer 2.x lokálně).
   Pokud Composer není dostupný:
   `php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"`
   `php composer-setup.php`
   `php composer.phar install`

3) Aktualizuj `.gitignore` — přidej:

   /vendor/
   composer.lock  # debatable — pokud chceš deterministický build, commitni; pro distribuovaný projekt někdy ignoruj

   POZN: composer.lock obvykle commitni. Pro distribuovanou aplikaci ho ignoruj
   pokud chceš, aby uživatelé instalovali aktuální verze. Pro tento projekt
   doporučuji commitnout (předvídatelná dependency tree).

4) V `config.php` na začátek (po `<?php` a před DB konstantami) přidej:

   // Composer autoloader (pokud existuje)
   $autoload = __DIR__ . '/vendor/autoload.php';
   if (is_file($autoload)) {
       require_once $autoload;
   }

   POZN: aplikace musí fungovat i bez vendor/, dokud uživatel nespustí
   composer install. Soubory v `files` autoload se v tom případě requireují
   přes klasický require_once na různých místech (jako dnes). Composer
   autoload je preferred path, ale ne mandatory.

5) Přidej `declare(strict_types=1);` jako PRVNÍ řádek v těchto souborech
   (hned za `<?php`, na samostatném řádku):

   - includes/helpers.php
   - includes/gpx_parser.php
   - includes/photo_helper.php
   - includes/security.php
   - includes/app_config.php
   - includes/db.php

   POZOR: po přidání strict_types může PHP odhalit type mismatch bugs.
   Spusť aplikaci, otestuj klíčové scénáře (import GPX, edit, delete, photo upload).
   Pokud něco selže s TypeError, fixuj:
   - explicit cast: `(int)$value`, `(float)$value`, `(string)$value`
   - sjednotit signatury funkcí
   - před voláním ověřit typ vstupu

6) Vytvoř prázdnou složku `src/` pro budoucí PSR-4 třídy (`.gitkeep`).

7) Vytvoř `phpunit.xml.dist` v rootu pro budoucí testy:

   <?xml version="1.0" encoding="UTF-8"?>
   <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
            xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
            bootstrap="vendor/autoload.php"
            colors="true">
       <testsuites>
           <testsuite name="unit">
               <directory>tests/unit</directory>
           </testsuite>
       </testsuites>
   </phpunit>

   Vytvoř `tests/unit/.gitkeep`.

VERIFIKACE:
- `composer install` projde.
- `php -l includes/helpers.php` atd. — projde (žádné syntax errory).
- Otevři aplikaci v prohlížeči. Otestuj: index, detail, filter, import GPX,
  upload fotky, edit track, delete track. Žádné TypeErrory v logu.
- `vendor/bin/phpunit --version` — funguje.

Hotovo? Shrnutí + commit.
```

---

## TASK-14 — Minimální CI pipeline (GitHub Actions)

**Fáze:** 3
**Pokrývá nálezy:** OPS-02, OPS-15, QR-12
**Odhad:** 1.5 h
**Závislosti:** TASK-13 (Composer).

### Cíl
GitHub Actions workflow: PHP lint + syntax check, Tailwind build verification, i18n key drift check, gitleaks secret scan.

### Acceptance criteria
- [ ] `.github/workflows/ci.yml` existuje.
- [ ] Workflow jede na push/PR na main a develop.
- [ ] PHP lint projde (`find . -name "*.php" | xargs php -l`).
- [ ] `scripts/lint_lang.php` ověří, že všech 8 jazykových souborů má stejné klíče.
- [ ] Tailwind build check: pokud `assets/css/app.css` je outdated vůči `assets/css/input.css`, CI selže.
- [ ] Gitleaks scan + check, že `.env` není commitnut.
- [ ] Smoke test: pushni dummy change, CI projde.

### Prompt k zadání
```
Implementuj TASK-14 z AUDIT_REPORT.md. Cíl: GitHub Actions CI workflow.

KROKY:

1) Vytvoř `package.json` v rootu (pokud neexistuje):

   {
     "name": "gpx-manager",
     "version": "1.0.0",
     "scripts": {
       "build": "tailwindcss -i assets/css/input.css -o assets/css/app.css --minify",
       "watch": "tailwindcss -i assets/css/input.css -o assets/css/app.css --watch"
     },
     "devDependencies": {
       "tailwindcss": "^4.2.4"
     }
   }

2) Vytvoř `scripts/lint_lang.php`:

   <?php
   declare(strict_types=1);

   $langDir = __DIR__ . '/../lang';
   $files = glob($langDir . '/*.php');
   if (!$files) {
       fwrite(STDERR, "No lang files found in $langDir\n");
       exit(1);
   }

   $allKeys = [];
   foreach ($files as $f) {
       $strings = require $f;
       if (!is_array($strings)) {
           fwrite(STDERR, "$f did not return array\n");
           exit(1);
       }
       $allKeys[basename($f)] = array_keys($strings);
   }

   $reference = reset($allKeys);
   $refName = array_key_first($allKeys);
   $hasErrors = false;

   foreach ($allKeys as $name => $keys) {
       $missing = array_diff($reference, $keys);
       $extra = array_diff($keys, $reference);
       if ($missing) {
           fwrite(STDERR, "[$name] missing keys (vs $refName): " . implode(', ', $missing) . "\n");
           $hasErrors = true;
       }
       if ($extra) {
           fwrite(STDERR, "[$name] extra keys (vs $refName): " . implode(', ', $extra) . "\n");
           $hasErrors = true;
       }
   }

   if ($hasErrors) {
       fwrite(STDERR, "i18n key drift detected.\n");
       exit(1);
   }
   echo "i18n key sets consistent across " . count($allKeys) . " languages.\n";
   exit(0);

3) Vytvoř `.github/workflows/ci.yml`:

   name: CI

   on:
     push:
       branches: [main, develop]
     pull_request:

   jobs:
     php-lint:
       name: PHP Syntax & Lint
       runs-on: ubuntu-latest
       steps:
         - uses: actions/checkout@v4

         - name: Setup PHP
           uses: shivammathur/setup-php@v2
           with:
             php-version: '8.2'
             extensions: pdo_mysql, simplexml, gd, exif, zip, mbstring

         - name: Composer install
           run: composer install --no-progress --prefer-dist

         - name: PHP syntax check (parallel)
           run: |
             find . -name "*.php" \
               -not -path "./vendor/*" \
               -not -path "./.git/*" \
               -print0 | xargs -0 -n1 -P4 php -l > /dev/null

         - name: i18n key consistency
           run: php scripts/lint_lang.php

     css-build:
       name: Tailwind Build Verification
       runs-on: ubuntu-latest
       steps:
         - uses: actions/checkout@v4

         - name: Setup Node
           uses: actions/setup-node@v4
           with:
             node-version: '20'
             cache: 'npm'

         - name: Install deps
           run: npm ci || npm install

         - name: Build CSS
           run: npm run build

         - name: Check app.css is up to date
           run: |
             if ! git diff --quiet assets/css/app.css; then
               echo "::error::assets/css/app.css is outdated. Run 'npm run build' and commit."
               git diff assets/css/app.css | head -50
               exit 1
             fi

     security-scan:
       name: Security Scan
       runs-on: ubuntu-latest
       steps:
         - uses: actions/checkout@v4
           with:
             fetch-depth: 0

         - name: Gitleaks
           uses: gitleaks/gitleaks-action@v2
           env:
             GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}

         - name: Check .env not committed
           run: |
             if git ls-files | grep -q "^\.env$"; then
               echo "::error::.env is committed!"
               exit 1
             fi

         - name: Warn if setup.php present
           run: |
             if [ -f "setup.php" ]; then
               echo "::warning::setup.php is present in repo. After install, delete it or block via .htaccess."
             fi

4) Aktualizuj `.gitignore`:

   /node_modules/
   /vendor/
   /.setup-allowed
   /logs/*.log
   /uploads/*
   !/uploads/.gitkeep
   !/uploads/.htaccess
   .env

5) Commitni vše. Push na branch, otevři PR — CI by mělo proběhnout.

VERIFIKACE:
- Lokálně: `php scripts/lint_lang.php` projde nebo nahlásí konkrétní missing keys.
  Pokud nahlásí, oprav drift (přidej chybějící klíče do non-cs/en souborů).
- `npm install && npm run build` — funguje (předpoklad: máš Node 20+).
- Push PR → GitHub Actions tab → 3 jobs zelené.

Hotovo? Shrnutí + commit.
```

---

KONEC FÁZE 3.

## TASK-15 — Theme/lang/visible_pages konstanty centralizace

**Fáze:** 4
**Pokrývá nálezy:** QR-3, BE-5
**Odhad:** 1 h
**Závislosti:** žádné.

### Cíl
Eliminovat 15 duplicit pole `['classic','dark',...]` a podobných.

### Acceptance criteria
- [ ] Nový soubor `includes/app_constants.php` definuje `all_themes()`, `all_langs()`, `all_pages()`.
- [ ] Helper `active_theme()` čte GET → cookie → výchozí + validuje proti `all_themes()`.
- [ ] Helper `init_theme_cookie()` zapíše cookie pokud GET parametr existuje a je validní.
- [ ] Všech 15 PHP souborů (admin, calendar, edit, heatmap, map_search, photos, photo_import, settings, stats, 5× includes/*_data.php, app_config, public_access) volá tyto helpery místo duplikace.

### Prompt k zadání
```
Implementuj TASK-15 z AUDIT_REPORT.md. Cíl: konstanty témat/jazyků/stránek na jednom místě.

KROKY:

1) Vytvoř `includes/app_constants.php`:

   <?php
   declare(strict_types=1);

   function all_themes(): array {
       return ['classic','dark','darkblue','darkgreen','blue','green','minimal','lightgray','brown'];
   }

   function all_langs(): array {
       return ['cs','en','de','sk','es','fr','pl','it'];
   }

   function all_pages(): array {
       return ['stats','calendar','heatmap','map_search','nearby','filter','compare','settings'];
   }

   function available_themes(): array {
       $allowed = get_app_config('allowed_themes', json_encode(all_themes()));
       $arr = is_string($allowed) ? json_decode($allowed, true) : $allowed;
       return is_array($arr) ? array_values(array_intersect($arr, all_themes())) : all_themes();
   }

   function available_langs(): array {
       $allowed = get_app_config('allowed_langs', json_encode(all_langs()));
       $arr = is_string($allowed) ? json_decode($allowed, true) : $allowed;
       return is_array($arr) ? array_values(array_intersect($arr, all_langs())) : all_langs();
   }

   function active_theme(): string {
       $available = available_themes();
       if (!empty($_GET['theme']) && in_array($_GET['theme'], $available, true)) {
           return $_GET['theme'];
       }
       if (!empty($_COOKIE['theme']) && in_array($_COOKIE['theme'], $available, true)) {
           return $_COOKIE['theme'];
       }
       return $available[0] ?? 'classic';
   }

   function init_theme_cookie(): void {
       if (headers_sent()) return;
       if (!empty($_GET['theme']) && in_array($_GET['theme'], available_themes(), true)) {
           setcookie('theme', $_GET['theme'], time() + 365 * 24 * 3600, '/');
           $_COOKIE['theme'] = $_GET['theme'];
       }
   }

2) Najdi v projektu duplicitní bloky (Grep pro `['classic','dark'`).
   V každém z těchto souborů (admin.php, calendar.php, edit.php, heatmap.php,
   map_search.php, photos.php, photo_import.php, settings.php, stats.php,
   includes/compare_data.php, includes/detail_data.php, includes/filter_data.php,
   includes/index_data.php, includes/nearby_data.php, includes/app_config.php,
   includes/public_access.php) najdi pattern jako:

   $allThemes = ['classic','dark','darkblue',...];
   $available_themes = get_app_config('allowed_themes', $allThemes);
   if (is_string($available_themes)) $available_themes = json_decode($available_themes, true);
   if (!is_array($available_themes) || !$available_themes) $available_themes = $allThemes;
   $theme = $_GET['theme'] ?? $_COOKIE['theme'] ?? $available_themes[0];
   if (!in_array($theme, $available_themes)) $theme = $available_themes[0];
   if (isset($_GET['theme']) && in_array($_GET['theme'], $available_themes)) {
       setcookie('theme', $_GET['theme'], time() + 365*24*3600, '/');
   }

   Nahraď za:

   require_once __DIR__ . '/includes/app_constants.php'; // nebo relativní cestu

   init_theme_cookie();
   $theme = active_theme();
   $available_themes = available_themes();
   $allowedLangs = available_langs();
   $allThemes = all_themes(); // pro admin.php který potřebuje master list

3) V `index.php` a `index-legacy.php` udělej totéž pokud tam tato logika je.

4) V `includes/app_config.php` `init_app_config()` (pokud upraveno v TASK-09)
   můžeš nahradit hardcoded JSON za:

   $defaults = [
       'allowed_themes' => json_encode(all_themes()),
       'allowed_langs'  => json_encode(all_langs()),
       'visible_pages'  => json_encode(all_pages()),
       '_init_done'     => '1',
   ];

5) V `composer.json` (pokud TASK-13 hotov) přidej `includes/app_constants.php`
   do files autoload, aby byl globálně dostupný:

   "autoload": {
       ...
       "files": [
           "includes/app_constants.php",
           "includes/helpers.php",
           "includes/security.php",
           "includes/app_config.php"
       ]
   }

   Spusť `composer dump-autoload`.

VERIFIKACE:
- Otevři libovolnou stránku — theme cookie funguje.
- Změň URL ?theme=dark — uloží se cookie, načte se dark.
- V admin.php zaškrtni jen 2 témata — na index se zobrazí jen ta v dropdownu.

Hotovo? Shrnutí + commit.
```

---

## TASK-16 — helpers.php decomposition

**Fáze:** 4
**Pokrývá nálezy:** QR-16, QR-17, QR-20
**Odhad:** 2 h
**Závislosti:** TASK-15.

### Cíl
Rozdělit `helpers.php` (550 řádků, 7 oblastí) do 6 cílených souborů. Opravit `t()` return type. Centralizovat magic numbers.

### Acceptance criteria
- [ ] Nové soubory: `includes/i18n.php`, `includes/format.php`, `includes/paths.php`, `includes/sort_query.php`, `includes/activity.php`, `includes/track_filter.php`, `includes/constants.php`.
- [ ] `helpers.php` zredukováno na require_once aggregator (~10 řádků).
- [ ] `t()` má signaturu `function t(string $key, ?string $default = null): string` (vrací vždy string).
- [ ] `t_arr()` pro vzácný array-valued případ.
- [ ] `buildFilterSQL()` přijímá `$filters` array parametr (s `$_GET` jako default).
- [ ] `includes/constants.php` definuje magic numbers (COOKIE_TTL_DAYS, PHOTO_AUTO_ASSIGN_RADIUS_M, MOVING_THRESHOLD_MS, ACTIVITY_AUTO_THRESHOLD_KMH).
- [ ] Smoke test: vše funguje.

### Prompt k zadání
```
Implementuj TASK-16 z AUDIT_REPORT.md. Cíl: rozdělit helpers.php.

KROKY:

1) Otevři `includes/helpers.php`, identifikuj logické bloky:
   - Paths: uploads_fs, uploads_url, gpx_url, thumb_url, photo_thumb_url, photo_full_url
   - HTML escape: h
   - i18n: app_lang, t, renderLangSelector, renderHeaderMeta
   - Format: app_units, fmtDist, fmtElev, fmtSpeed, fmtDur, formatSecondsToHMS
   - Sort: build_query, sort_th
   - Difficulty/Activity: calculateDifficulty, difficultyLabel, difficultyBadge, detectActivityType, activityBadge
   - Filter SQL: buildFilterSQL, buildRangeSQL

2) Vytvoř `includes/constants.php`:

   <?php
   declare(strict_types=1);

   const COOKIE_TTL_DAYS = 365;
   const COOKIE_TTL_SECONDS = COOKIE_TTL_DAYS * 24 * 3600;

   // Foto auto-assignment radius (m) — viz photo_helper.php
   const PHOTO_AUTO_ASSIGN_RADIUS_M = 500;

   // GPX speed below which we consider "stationary" (m/s) — viz gpx_parser.php
   const MOVING_THRESHOLD_MS = 0.5;

   // Auto-detect activity_type = 'Auto' threshold (km/h max speed)
   const ACTIVITY_AUTO_THRESHOLD_KMH = 80;

   // Default items per page on index/filter pages
   const DEFAULT_PER_PAGE = 50;

   // Photo upload limits
   const PHOTO_MAX_PER_ZIP = 200;
   const PHOTO_MAX_FILE_SIZE = 50 * 1024 * 1024; // 50 MB
   const PHOTO_MAX_MEGAPIXELS = 50_000_000; // 50 MP

   // GPX content max size for save_cleaned
   const GPX_MAX_CONTENT_SIZE = 50 * 1024 * 1024;

3) Vytvoř `includes/paths.php` — přesuň sem funkce: uploads_fs, uploads_url,
   gpx_url, thumb_url, photo_thumb_url, photo_full_url, sanitizeFileName,
   is_safe_image_path, validate_gpx_file. Začni `<?php declare(strict_types=1);`.

4) Vytvoř `includes/format.php` — přesuň: h, app_units, fmtDist, fmtElev,
   fmtSpeed, fmtDur, formatSecondsToHMS.

5) Vytvoř `includes/i18n.php` — přesuň: app_lang, t, t_arr, renderLangSelector,
   renderHeaderMeta.

   Uprav `t()`:

   function t(string $key, ?string $default = null): string {
       static $strings = null;
       if ($strings === null) {
           $langFile = __DIR__ . '/../lang/' . app_lang() . '.php';
           $strings = file_exists($langFile) ? require $langFile : [];
       }
       $val = $strings[$key] ?? null;
       if (is_array($val)) {
           // Array value — pokud volaný chtěl string, vrátíme klíč jako fallback
           if (defined('APP_ENV') && APP_ENV === 'local') {
               trigger_error("t('$key') is an array — use t_arr() instead", E_USER_WARNING);
           }
           return $default ?? $key;
       }
       if ($val === null) return $default ?? $key;
       return (string)$val;
   }

   function t_arr(string $key): array {
       static $strings = null;
       if ($strings === null) {
           $langFile = __DIR__ . '/../lang/' . app_lang() . '.php';
           $strings = file_exists($langFile) ? require $langFile : [];
       }
       $val = $strings[$key] ?? null;
       return is_array($val) ? $val : [];
   }

6) Vytvoř `includes/sort_query.php` — přesuň: build_query, sort_th.

   Uprav sort_th aby vracela aria-sort:

   function sort_th(string $col, string $label, string $currentBy, string $currentDir): string {
       $newDir = ($currentBy === $col && $currentDir === 'ASC') ? 'DESC' : 'ASC';
       $href = '?' . build_query(['sort_by' => $col, 'sort_dir' => $newDir]);
       $ariaSort = 'none';
       $arrow = '';
       if ($currentBy === $col) {
           $ariaSort = $currentDir === 'ASC' ? 'ascending' : 'descending';
           $arrow = ' <span aria-hidden="true">' . ($currentDir === 'ASC' ? '↑' : '↓') . '</span>';
       }
       return sprintf(
           '<th scope="col" aria-sort="%s"><a href="%s">%s%s</a></th>',
           $ariaSort, htmlspecialchars($href), htmlspecialchars($label), $arrow
       );
   }

7) Vytvoř `includes/activity.php` — přesuň: calculateDifficulty, difficultyLabel,
   difficultyBadge, detectActivityType, activityBadge. Použij konstanty
   z `constants.php`.

8) Vytvoř `includes/track_filter.php` — přesuň: buildFilterSQL, buildRangeSQL.

   Uprav buildFilterSQL aby přijímala $filters array:

   function buildFilterSQL(array $filters = null): array {
       $filters = $filters ?? $_GET;
       // ... zbytek logiky, ale čte z $filters místo $_GET
   }

   Vrátí: ['where' => ..., 'params' => ...].

9) Zredukuj `helpers.php` na aggregator:

   <?php
   declare(strict_types=1);

   require_once __DIR__ . '/constants.php';
   require_once __DIR__ . '/paths.php';
   require_once __DIR__ . '/format.php';
   require_once __DIR__ . '/i18n.php';
   require_once __DIR__ . '/sort_query.php';
   require_once __DIR__ . '/activity.php';
   require_once __DIR__ . '/track_filter.php';

10) V composer.json files autoload (pokud TASK-13 hotov) můžeš přidat
    všechny nové soubory, nebo nechat helpers.php jako single entry point.

VERIFIKACE:
- `php -l` na všech nových souborech.
- Otevři aplikaci, projdi index, detail, filter, edit, photos.
- Změň jazyk, zkontroluj t() funguje.
- DevTools Console — žádné errory.

Hotovo? Shrnutí + commit.
```

---

## TASK-17 — Admin banner partial

**Fáze:** 4
**Pokrývá nálezy:** QR-2, A11Y-014
**Odhad:** 45 min

### Cíl
Vyextrahovat HTML admin banneru z `auth.php` a `public_access.php` do jednoho partial souboru. Přidat ARIA.

### Prompt k zadání
```
Implementuj TASK-17 z AUDIT_REPORT.md. Cíl: extrakce admin banneru do partial.

KROKY:

1) Vytvoř `includes/partials/admin_banner.php`:

   <?php
   declare(strict_types=1);

   function render_admin_banner(): void {
       if (empty($_SESSION['is_admin'])) return;
       $via = htmlspecialchars($_SESSION['admin_via'] ?? 'login', ENT_QUOTES, 'UTF-8');
       $ip = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '?', ENT_QUOTES, 'UTF-8');
       $previewUrl = 'index.php?visitor_preview=1';
       $csrf = function_exists('csrf_token') ? csrf_token() : '';
       ?>
       <div role="region" aria-label="<?= htmlspecialchars(t('admin_banner_aria', 'Administrátorský panel')) ?>"
            class="admin-banner">
         <div>
           <?= htmlspecialchars(t('admin_logged_in', 'Přihlášen jako')) ?>
           <strong><?= htmlspecialchars(t('administrator', 'administrátor')) ?></strong>
           <span class="muted">(<?= $via ?>)</span>
           | IP: <strong><?= $ip ?></strong>
         </div>
         <div class="admin-banner__actions">
           <a href="<?= htmlspecialchars($previewUrl) ?>"
              aria-label="<?= htmlspecialchars(t('preview_as_visitor_aria', 'Zobrazit stránku jako návštěvník')) ?>">
             <span aria-hidden="true">👁</span>
             <?= htmlspecialchars(t('preview_as_visitor', 'Náhled jako návštěvník')) ?>
           </a>
           <form method="post" action="login.php" class="admin-banner__logout-form">
             <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
             <input type="hidden" name="logout" value="1">
             <button type="submit" aria-label="<?= htmlspecialchars(t('logout_aria', 'Odhlásit se')) ?>">
               <?= htmlspecialchars(t('logout', 'Odhlásit se')) ?>
             </button>
           </form>
         </div>
       </div>
       <?php
   }

2) V `includes/auth.php` najdi `function render_admin_banner()` (řádek ~39-54).
   Smaž celý blok, místo něj přidej:

   require_once __DIR__ . '/partials/admin_banner.php';

   (Funkce se teď definuje v partial.)

3) V `includes/public_access.php` najdi banner HTML (řádky ~76-100).
   Buď použij stejný `render_admin_banner()` (preferred), nebo extrahuj
   visitor_preview banner do druhého partial.

4) Přidej styly do `css/style.css` (nebo `assets/css/input.css` pokud chceš
   přes Tailwind):

   .admin-banner {
       background: #003366;
       color: #fff;
       padding: 6px 12px;
       font-family: Arial, sans-serif;
       font-size: 13px;
       display: flex;
       justify-content: space-between;
       align-items: center;
       border-bottom: 2px solid #002244;
       position: sticky;
       top: 0;
       z-index: 9999;
   }
   .admin-banner .muted { opacity: 0.8; }
   .admin-banner__actions {
       display: flex;
       gap: 8px;
       align-items: center;
   }
   .admin-banner__actions a,
   .admin-banner__actions button {
       color: #fff;
       text-decoration: none;
       background: #e65c00;
       padding: 4px 10px;
       border-radius: 6px;
       border: none;
       cursor: pointer;
       font-size: inherit;
   }
   .admin-banner__logout-form button { background: #cc3333; }
   .admin-banner__actions a:focus-visible,
   .admin-banner__actions button:focus-visible {
       outline: 2px solid #fff;
       outline-offset: 2px;
   }

5) Přidej i18n klíče do všech 8 lang souborů (`lang/*.php`):

   'admin_banner_aria' => 'Administrátorský panel',
   'admin_logged_in' => 'Přihlášen jako',
   'administrator' => 'administrátor',
   'preview_as_visitor' => 'Náhled jako návštěvník',
   'preview_as_visitor_aria' => 'Zobrazit stránku jako běžný návštěvník',
   'logout' => 'Odhlásit se',
   'logout_aria' => 'Odhlásit se z administrátorského účtu',

   Přelož do en/de/sk/es/fr/pl/it. Klíče musí být ve VŠECH 8 souborech.

VERIFIKACE:
- Spusť `php scripts/lint_lang.php` (pokud TASK-14) — projde.
- Otevři aplikaci jako admin — banner se zobrazí.
- Klikni Odhlásit se — funguje.
- DevTools accessibility tree — banner má role="region" a aria-label.

Hotovo? Shrnutí + commit.
```

---

## TASK-18 — AJAX endpoint wrapper + photos.php split

**Fáze:** 4
**Pokrývá nálezy:** QR-5, QR-15, BE-2
**Odhad:** 3 h
**Závislosti:** TASK-02 (CSRF infra).

### Cíl
Centrální `ajax_endpoint()` wrapper pro všechny JSON endpointy. Rozdělit `photos.php` (1429 řádků) podle vzoru.

### Acceptance criteria
- [ ] Nový `includes/ajax.php` exportuje `ajax_endpoint(callable $handler, array $opts = [])` který: nastaví JSON content-type, ověří CSRF (pokud opts['csrf']), try/catch → vrátí `{ok:false, error}`, loguje s context.
- [ ] `photos.php` zredukováno na ≤200 řádek (HTML pages + dispatch).
- [ ] Nový `includes/photos_data.php` (DB queries, data prep).
- [ ] Nový `includes/photos_view.php` (HTML).
- [ ] Nová složka `api/photos/` s endpointy: `upload.php`, `assign.php`, `delete.php`, `caption.php`, `visibility.php`, `bulk.php` — každý ≤100 řádek, používá `ajax_endpoint`.
- [ ] Frontend JS (extrahovaný do `js/photos.js`) volá nové `/api/photos/*.php`.
- [ ] Smoke test: všechny photo akce fungují přes UI.

### Prompt k zadání
```
Implementuj TASK-18 z AUDIT_REPORT.md. Velký refactor — naplánuj 3 hodiny.

KROKY:

1) Vytvoř `includes/ajax.php`:

   <?php
   declare(strict_types=1);

   /**
    * Centrální wrapper pro AJAX/JSON endpointy.
    * Usage:
    *   ajax_endpoint(function() {
    *       global $pdo;
    *       $id = (int)($_POST['id'] ?? 0);
    *       // ... logic ...
    *       return ['ok' => true, 'data' => $result];
    *   }, ['csrf' => true, 'admin' => true]);
    */
   function ajax_endpoint(callable $handler, array $opts = []): void {
       $requireCsrf = $opts['csrf'] ?? true;
       $requireAdmin = $opts['admin'] ?? false;
       $allowPublicVisitor = $opts['public_ok'] ?? false;
       $endpoint = $opts['name'] ?? (basename($_SERVER['SCRIPT_NAME']));

       header('Content-Type: application/json; charset=utf-8');
       header('X-Content-Type-Options: nosniff');

       try {
           if ($requireAdmin && empty($_SESSION['is_admin'])) {
               http_response_code(403);
               echo json_encode(['ok' => false, 'error' => 'Forbidden']);
               return;
           }
           if ($requireCsrf && !csrf_verify()) {
               http_response_code(403);
               echo json_encode(['ok' => false, 'error' => 'CSRF']);
               return;
           }
           $result = $handler();
           echo json_encode($result, JSON_UNESCAPED_UNICODE);
       } catch (Throwable $e) {
           error_log("[AJAX $endpoint] " . $e->getMessage() . " at " . $e->getFile() . ':' . $e->getLine());
           http_response_code(500);
           $message = (defined('APP_ENV') && APP_ENV === 'local')
               ? $e->getMessage()
               : 'Internal error';
           echo json_encode(['ok' => false, 'error' => $message]);
       }
   }

2) Vytvoř složku `api/photos/` a v ní:

   `api/photos/upload.php`:

   <?php
   require_once __DIR__ . '/../../includes/db.php';
   require_once __DIR__ . '/../../includes/auth.php';  // nebo public_access pokud upload veřejný
   require_once __DIR__ . '/../../includes/ajax.php';
   require_once __DIR__ . '/../../includes/photo_helper.php';

   ajax_endpoint(function() {
       global $pdo;
       // ... přesuň sem upload handler z photos.php řádky ~36-121 ...
       return ['ok' => true, 'results' => $results];
   }, ['csrf' => true, 'admin' => true, 'name' => 'photos.upload']);

   Podobně pro:
   - api/photos/assign.php (přesuň z photos.php řádky kolem ~211)
   - api/photos/delete.php (~232)
   - api/photos/caption.php (~258)
   - api/photos/bulk_delete.php (~279)
   - api/photos/bulk_assign.php (~306)
   - api/photos/visibility.php (toggle + bulk_visible, ~328-360)

   Public endpointy (track, bounds) musí zůstat — buď v photos.php samostatně,
   nebo `api/photos/track.php` s `['admin' => false, 'public_ok' => true]`.

3) Vytvoř `includes/photos_data.php` — přesuň sem DB query logiku pro
   gallery (které photos zobrazit, kategorizace podle dne/měsíce, dostupné
   tracks pro select). Žádné HTML, žádný echo.

   <?php
   declare(strict_types=1);
   require_once __DIR__ . '/db.php';
   require_once __DIR__ . '/app_constants.php';

   function load_photos_page_data(): array {
       global $pdo;
       // ... všechny SELECTy pro gallery view ...
       return [
           'photos' => $photos,
           'tracks' => $tracksForSelect,
           'groups' => $groupsByDate,
           // ...
       ];
   }

4) Vytvoř `includes/photos_view.php` — přesuň sem HTML z photos.php.
   Pouze prezentace, čte data z proměnných předaných v `photos.php` dispatch.

5) Vytvoř `js/photos.js` — přesuň sem inline JS z photos.php (~řádky 891-1423).
   Uprav fetch volání aby volaly nové endpointy:

   PŘED: fetch('photos.php?ajax=delete', { method: 'POST', body: fd })
   PO:   fetch('api/photos/delete.php', { method: 'POST', body: fd })

   Použij CSRF token z meta tagu (viz TASK-02 step 1).

6) Zredukuj `photos.php` na:

   <?php
   require_once __DIR__ . '/includes/public_access.php';
   require_once __DIR__ . '/includes/db.php';
   require_once __DIR__ . '/includes/photos_data.php';

   if (!check_page_access('photos')) {
       header('Location: index.php');
       exit;
   }

   $pageData = load_photos_page_data();
   extract($pageData);  // nebo předat explicitně

   include __DIR__ . '/includes/layout_header.php';
   include __DIR__ . '/includes/photos_view.php';
   include __DIR__ . '/includes/layout_footer.php';

7) Po dokončení smaž z `photos.php` veškerou AJAX dispatch logiku
   (řádky 36-364).

VERIFIKACE:
- Otevři photos.php — gallery se zobrazí stejně jako dřív.
- Uploaduj fotku — funguje (DevTools Network: nový endpoint /api/photos/upload.php).
- Smaž fotku, přiřaď k trase, hromadně smaž — vše funguje.
- Jako visitor (mimo admin IP): otevři photos.php (pokud v visible_pages),
  zkus volat /api/photos/delete.php — 403.

Hotovo? Shrnutí + commit. Velký refactor, dělej incrementálně po jednom
endpointu, ověř po každém kroku.
```

---

## TASK-19 — photo_import.php split + inline CSS/JS extraction

**Fáze:** 4
**Pokrývá nálezy:** QR-6, PERF-16, PERF-18
**Odhad:** 2 h
**Závislosti:** TASK-18.

### Cíl
Stejný refactor pattern pro `photo_import.php` (1126 řádků). Plus EXIF batch optimalizace.

### Acceptance criteria
- [ ] `photo_import.php` ≤ 200 řádků.
- [ ] `includes/photo_import_data.php`, `includes/photo_import_view.php`.
- [ ] `api/photo_import/{scan,exif,thumb,import}.php`.
- [ ] `css/photo-import.css` (extrakce z inline `<style>`).
- [ ] `js/photo-import.js` (extrakce z inline `<script>`).
- [ ] `exif_batch` handler bachuje dedup `WHERE orig_name IN (?, ?, ...)`.
- [ ] EXIF orientation čte 1× (předáno do `generate_photo_thumb`).

### Prompt k zadání (zkrácený, vzor TASK-18)
```
Implementuj TASK-19 z AUDIT_REPORT.md.

Postupuj analogicky k TASK-18 ale pro photo_import.php:

1) Extrahuj 4 AJAX handlery do api/photo_import/{scan,exif,thumb,import}.php,
   všechny přes ajax_endpoint() wrapper.
2) Přesuň HTML do includes/photo_import_view.php.
3) Přesuň DB logiku do includes/photo_import_data.php.
4) Extrahuj inline <style> (řádky ~244-587) do css/photo-import.css.
5) Extrahuj inline <script> do js/photo-import.js.

Optimalizace v rámci task:

a) V handleru exif_batch (kolem photo_import.php:100-116):
   Místo per-soubor dedup SELECTu udělej batch:

   $origNames = array_column($filesToCheck, 'orig_name');
   $placeholders = implode(',', array_fill(0, count($origNames), '?'));
   $stmt = $pdo->prepare("SELECT orig_name FROM track_photos WHERE orig_name IN ($placeholders)");
   $stmt->execute($origNames);
   $existing = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

   foreach ($filesToCheck as $f) {
       if (isset($existing[$f['orig_name']])) {
           // duplikát
       }
   }

b) V generate_photo_thumb (photo_helper.php:140-146) přijmi orientaci jako parametr:

   function generate_photo_thumb(string $srcPath, string $destPath, int $maxDim, ?int $orientation = null): bool {
       if ($orientation === null) {
           $exif = @exif_read_data($srcPath);
           $orientation = $exif['Orientation'] ?? 1;
       }
       // ... zbytek
   }

   V process_single_photo, kde se volá read_photo_exif následované
   generate_photo_thumb, předej orientation explicitně:

   $exifData = read_photo_exif($tmpPath);
   $orientation = $exifData['orientation'] ?? 1;
   generate_photo_thumb($tmpPath, $destPath, 1600, $orientation);

VERIFIKACE:
- Photo import scan funguje.
- Batch EXIF read trvá kratší dobu (test 100 fotek).
- Import projde, žádné errory.

Hotovo? Shrnutí + commit.
```

---

## TASK-20 — Thumbnail generation: tile cache + async strategy

**Fáze:** 4
**Pokrývá nálezy:** PERF-4, BE-13
**Odhad:** 1.5 h

### Cíl
Lokální OSM tile cache + lazy generation thumbu při prvním zobrazení místo importu.

### Acceptance criteria
- [ ] `fetch_tile()` v `generate_thumb.php` čte z `uploads/_tile_cache/{z}/{x}/{y}.png` před HTTP requestem.
- [ ] Cache TTL 30 dní.
- [ ] Po fetch z OSM uloží do cache.
- [ ] Volitelně: implementace lazy thumb — pokud thumb neexistuje, GET na `thumb_url` vygeneruje a uloží.

### Prompt k zadání
```
Implementuj TASK-20 z AUDIT_REPORT.md. Cíl: OSM tile cache + thumb optimization.

KROKY:

1) V `includes/generate_thumb.php` najdi `fetch_tile()` (řádky ~32-50).
   Přidej cache check:

   function fetch_tile(int $z, int $x, int $y): ?string {
       $cacheDir = uploads_fs('_tile_cache/' . $z . '/' . $x);
       if (!is_dir($cacheDir)) {
           @mkdir($cacheDir, 0755, true);
       }
       $cacheFile = $cacheDir . '/' . $y . '.png';
       $ttl = 30 * 24 * 3600; // 30 dní

       if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
           return file_get_contents($cacheFile);
       }

       $url = "https://tile.openstreetmap.org/$z/$x/$y.png";
       $ctx = stream_context_create([
           'http' => [
               'header' => "User-Agent: GpxManager/1.0\r\n",
               'timeout' => 10,
           ]
       ]);
       $data = @file_get_contents($url, false, $ctx);
       if ($data === false) return null;

       @file_put_contents($cacheFile, $data);
       usleep(50_000); // OSM rate limit — 50ms mezi requesty
       return $data;
   }

2) Přidej do `uploads/.htaccess` (TASK-01 output) protection (cache je
   bezpečné servovat, ale ne PHP):
   (Cache zůstane chráněn už existujícím FilesMatch *.png allow rulem.)

3) Volitelné — lazy thumbnail generation:

   Vytvoř `thumb.php` v rootu:

   <?php
   require_once __DIR__ . '/includes/db.php';
   require_once __DIR__ . '/includes/generate_thumb.php';

   $id = (int)($_GET['id'] ?? 0);
   if ($id <= 0) { http_response_code(404); exit; }

   $stmt = $pdo->prepare("SELECT filename FROM tracks WHERE id = ?");
   $stmt->execute([$id]);
   $filename = $stmt->fetchColumn();
   if (!$filename) { http_response_code(404); exit; }

   $thumbPath = uploads_fs('_thumbs/' . pathinfo($filename, PATHINFO_FILENAME) . '.png');
   if (!is_file($thumbPath)) {
       generate_thumb_for($filename, $thumbPath);  // pomocí existující funkce
   }
   if (!is_file($thumbPath)) {
       // Fallback placeholder
       http_response_code(404);
       exit;
   }

   header('Content-Type: image/png');
   header('Cache-Control: public, max-age=2592000');
   readfile($thumbPath);

   V `helpers.php thumb_url()` přepiš na `thumb.php?id={trackId}`.
   V `import.php` vynech synchronní generování thumbu, místo toho jen
   poznamenat, že thumb chybí — vygeneruje se on-demand při prvním zobrazení.

4) Stačí step 1 + 2 pro tuto task. Step 3 je volitelný, pokud zbude čas.

VERIFIKACE:
- Smaž uploads/_tile_cache (pokud existuje).
- Spusť rebuild_thumbs.php pro 5 tras.
- Druhé spuštění proběhne výrazně rychleji (cache hit).

Hotovo? Shrnutí + commit.
```

---

## TASK-21 — GPX parser memory + duplicate calc cleanup

**Fáze:** 4
**Pokrývá nálezy:** PERF-5, QR-21
**Odhad:** 1.5 h

### Cíl
Refaktorovat `parse_gpx()` (230 řádků, 5 odpovědností, duplicitní výpočet). Zvážit XMLReader pro velké soubory.

### Acceptance criteria
- [ ] `parse_gpx()` rozdělen na privátní helpery: `_extract_trackpoints`, `_compute_metrics`, `_apply_trackstats_extension`, `_compute_bounds`.
- [ ] Duplicitní výpočet `avgSpeedMS_*` odstraněn.
- [ ] Funkce vrací konzistentní strukturu (případně value object).
- [ ] Volitelné: XMLReader streaming pro soubory > 5 MB.
- [ ] Smoke test: import GPX všech velikostí (1 KB až 50 MB).

### Prompt k zadání
```
Implementuj TASK-21 z AUDIT_REPORT.md. Cíl: refactor parse_gpx().

KROKY:

1) Otevři `includes/gpx_parser.php`. Najdi `parse_gpx()` (řádky 7-221).

2) Identifikuj duplicitní výpočet — řádky 137-138 a 191-192 (`avgSpeedMS_moving`,
   `avgSpeedMS_total`) se počítají dvakrát. Najdi a odstraň druhou kopii.

3) Refactor — rozděl na privátní funkce:

   /**
    * Extrahuje pole trackpointů z XML.
    * @return array<int, array{lat:float, lon:float, ele:?float, time:?string}>
    */
   function _gpx_extract_trackpoints(SimpleXMLElement $xml): array { ... }

   /**
    * Spočítá distance, ascent, descent, speeds z trackpointů.
    */
   function _gpx_compute_metrics(array $trackpoints, float $movingThreshold = 0.5): array { ... }

   /**
    * Aplikuje TrackStatsExtension hodnoty přes naše spočítané (preferuje GPX values).
    */
   function _gpx_apply_trackstats(SimpleXMLElement $xml, array $metrics): array { ... }

   /**
    * Spočítá min/max lat/lon bounds.
    */
   function _gpx_compute_bounds(array $trackpoints): ?array { ... }

   Pak parse_gpx() se zredukuje na orchestraci:

   function parse_gpx(string $filePath): ?array {
       $xml = safe_load_gpx($filePath);  // z TASK-01
       if (!$xml) return null;

       // namespace registration (zachovej z původního)
       // ...

       $trackpoints = _gpx_extract_trackpoints($xml);
       if (empty($trackpoints)) return null;

       $metrics = _gpx_compute_metrics($trackpoints, MOVING_THRESHOLD_MS);
       $metrics = _gpx_apply_trackstats($xml, $metrics);
       $bounds = _gpx_compute_bounds($trackpoints);

       return [
           'name' => (string)($xml->trk->name ?? ''),
           'date_start' => $trackpoints[0]['time'] ?? null,
           'date_end' => end($trackpoints)['time'] ?? null,
           'trackpoints_count' => count($trackpoints),
           'distance_km' => $metrics['distance_km'],
           'ascent' => $metrics['ascent'],
           'descent' => $metrics['descent'],
           'elevation_min' => $metrics['elevation_min'],
           'elevation_max' => $metrics['elevation_max'],
           'speed_max' => $metrics['speed_max'],
           'speed_avg' => $metrics['speed_avg'],
           'speed_avg_total' => $metrics['speed_avg_total'],
           'duration' => $metrics['duration'],
           'moving_time' => $metrics['moving_time'],
           'stopped_time' => $metrics['stopped_time'],
           // ... další pole
           'bounds' => $bounds,
       ];
   }

4) Volitelné — XMLReader streaming pro velké soubory:

   Před `parse_gpx`, přidej heuristiku:

   $size = filesize($filePath);
   if ($size > 5 * 1024 * 1024) {  // 5 MB
       return parse_gpx_streaming($filePath);  // implementuj XMLReader-based parser
   }

   `parse_gpx_streaming` použije XMLReader::open + while ($reader->read())
   loop. Toto je větší implementace — pokud zbude čas, jinak nech jen
   refactor + duplikát fix.

VERIFIKACE:
- Importuj 5 různých GPX souborů (malý ~1 KB, střední ~500 KB, velký ~5 MB).
- Všechny stats odpovídají hodnotám před refactorem (porovnej před/po
  na konkrétní trase).
- Žádné regresní bugs.

Hotovo? Shrnutí + commit.
```

---

## TASK-22 — Frontend quick fixes (Mapillary guard, AbortController, columns konflikt, defer)

**Fáze:** 5
**Pokrývá nálezy:** FE-4, FE-5, FE-9, FE-11, FE-17, PERF-9, PERF-17
**Odhad:** 1.5 h
**Závislosti:** TASK-08 (SRI/defer udělané pro CDN).

### Cíl
Vyřešit 5 frontend bugů s viditelným okamžitým dopadem.

### Acceptance criteria
- [ ] `js/detail-map.js` neinicializuje `overlayMapillary` pokud `mapillaryToken` je prázdný (kopíruje vzor z `filter-map.js`).
- [ ] `js/index-ajax.js` používá `AbortController` pro řešení race condition při debounce.
- [ ] V `js/index-ui.js` smazaná column-visibility sekce (řádky 112-181); zachována pouze v `js/index-columns.js`.
- [ ] Chart.js init odstraněn z `js/index-ajax.js` (zůstává jen v `index-chart.js`); `index-ui.js:186` reference opravena.
- [ ] `js/index-data.js` smazán (obsahoval jen 2 console.log).
- [ ] Všechny CDN `<script>` mají `defer` (Lucide, Leaflet, Chart.js, leaflet-gpx, vectorgrid).

### Prompt k zadání
```
Implementuj TASK-22 z AUDIT_REPORT.md. Cíl: 5 frontend quick fixů.

KROKY:

1) FE-11: v `js/detail-map.js` najdi blok inicializace `overlayMapillary`
   (kolem řádku 119-147). Obal podmínkou:

   const mapillaryToken = window.gpxDetailData?.mapillaryToken || '';
   let overlayMapillary = null;
   if (mapillaryToken && typeof L.vectorGrid !== 'undefined') {
       overlayMapillary = L.vectorGrid.protobuf(
           'https://tiles.mapillary.com/maps/vtp/mly1_public/2/{z}/{x}/{y}?access_token=' + mapillaryToken,
           { ... existující config }
       );
   }

   Pak ve všech místech kde `overlayMapillary` je přidán do layerControl,
   přidej guard:

   if (overlayMapillary) {
       overlays['Mapillary'] = overlayMapillary;
   }

2) FE-5: v `js/index-ajax.js` najdi fetch volání (typicky uvnitř debounce
   handleru). Přidej AbortController:

   let currentController = null;

   function performAjaxFilter() {
       if (currentController) currentController.abort();
       currentController = new AbortController();

       const url = '...';
       fetch(url, { signal: currentController.signal })
           .then(r => r.json())
           .then(data => {
               // ... update table
           })
           .catch(err => {
               if (err.name === 'AbortError') return; // očekávané při novém requestu
               console.error('AJAX filter failed', err);
           });
   }

3) FE-4: otevři `js/index-ui.js`. Najdi sekci s column-visibility logikou
   (řádky 112-181, hledej `.col-toggle` event listenery a `visible_cols`
   cookie). Smaž ji celou. Zachovej zbylé funkce.

   `js/index-columns.js` zůstává — toto je autoritativní implementace
   (klíč `hiddenCols`, třída `.col-hidden`).

   Pokud `index-columns.js` používá jiný klíč než `index-ui.js`, sjednoť
   na jeden (preferuj `hiddenCols` z index-columns.js).

4) FE-9/FE-17: v `js/index-ajax.js` najdi `updateChart` funkci a všechny
   Chart.js operace (řádky kolem ~50 řádků chart bloku). Smaž je
   — chart implementace zůstává v `index-chart.js`.

   V `js/index-ui.js` najdi řádek 186 `window.tracksChart` — pokud existuje,
   opravit referenci nebo odstranit (záleží, co tam dělá).

   V `index-chart.js` ověř, že po AJAX filtru se chart aktualizuje. Pokud ne,
   exportuj `updateTracksChart(newData)` funkci a volej ji z index-ajax.js.

5) FE-17 dodatek: smaž `js/index-data.js` (obsahuje jen `console.log`).
   Najdi všechny `<script src=".../index-data.js">` v *_view.php a smaž je.

6) PERF-9/PERF-17: u všech `<script src="https://..."` (CDN scripty)
   přidej atribut `defer`:

   Grep pro `<script src="https://` napříč includes/*.php, *.php.
   Přidej `defer` všude, kde chybí.

   POZN: Po `defer` se skripty spouští až po DOM parsování. Pokud nějaký
   inline `<script>` přímo používá globální z CDN scriptu (např. L = Leaflet),
   přesuň ten inline kód do `DOMContentLoaded` handleru.

VERIFIKACE:
- Otevři detail trasy bez Mapillary token — DevTools Console: žádné 401 errory.
- Index s rychle měněným filtrem — žádné race condition, výsledek odpovídá
  poslednímu filtru.
- Index column toggle — funguje konzistentně, žádný flicker.
- DevTools Network: všechny CDN scripty mají `defer`.

Hotovo? Shrnutí + commit.
```

---

## TASK-23 — Frontend lib extractions (event-bus, map-factory, geo-utils, format-utils)

**Fáze:** 5
**Pokrývá nálezy:** FE-2, FE-3, FE-7, FE-8
**Odhad:** 2 h

### Cíl
Eliminovat 200+ řádků duplicity v JS. Zavést event bus pro cross-script komunikaci.

### Acceptance criteria
- [ ] `js/lib/geo-utils.js` exportuje `haversine`, `toRad`, `subsampleArrays`.
- [ ] `js/lib/format-utils.js` exportuje `formatDuration`, `formatDate`, `escHtml`.
- [ ] `js/lib/map-factory.js` exportuje `createBaseLayers(apiKeys)`, `createWikimediaLayer(map)`.
- [ ] `js/lib/event-bus.js` exportuje `GpxBus` (EventTarget instance).
- [ ] `js/detail-map.js`, `js/filter-map.js`, `js/nearby-map.js`, `js/compare-map.js` používají `createBaseLayers`.
- [ ] `js/filter-core.js`, `js/detail-elevation.js` používají `geo-utils.haversine`.
- [ ] Soubory v `js/lib/` načteny PŘED ostatními skripty na všech `*_view.php`.

### Prompt k zadání
```
Implementuj TASK-23 z AUDIT_REPORT.md. Cíl: extrahovat sdílené JS knihovny.

KROKY:

1) Vytvoř `js/lib/event-bus.js`:

   (function() {
       'use strict';
       window.GpxBus = window.GpxBus || new EventTarget();
   })();

2) Vytvoř `js/lib/geo-utils.js`:

   (function() {
       'use strict';
       window.GpxGeo = window.GpxGeo || {};

       window.GpxGeo.toRad = function(deg) { return deg * Math.PI / 180; };

       window.GpxGeo.haversine = function(lat1, lon1, lat2, lon2) {
           const R = 6371; // km
           const dLat = window.GpxGeo.toRad(lat2 - lat1);
           const dLon = window.GpxGeo.toRad(lon2 - lon1);
           const a = Math.sin(dLat / 2) ** 2 +
               Math.cos(window.GpxGeo.toRad(lat1)) *
               Math.cos(window.GpxGeo.toRad(lat2)) *
               Math.sin(dLon / 2) ** 2;
           return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
       };

       window.GpxGeo.subsampleArrays = function(arrays, maxLen) {
           const len = arrays[0].length;
           if (len <= maxLen) return arrays;
           const step = Math.ceil(len / maxLen);
           return arrays.map(arr => arr.filter((_, i) => i % step === 0));
       };
   })();

3) Vytvoř `js/lib/format-utils.js`:

   (function() {
       'use strict';
       window.GpxFmt = window.GpxFmt || {};

       window.GpxFmt.formatDuration = function(seconds) {
           if (!seconds || isNaN(seconds)) return '';
           const h = Math.floor(seconds / 3600);
           const m = Math.floor((seconds % 3600) / 60);
           return h > 0 ? `${h}h ${m}m` : `${m}m`;
       };

       window.GpxFmt.escHtml = function(s) {
           return String(s).replace(/[&<>"']/g, m => ({
               '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
           }[m]));
       };

       window.GpxFmt.formatDate = function(iso) {
           if (!iso) return '';
           const d = new Date(iso);
           return d.toLocaleDateString();
       };
   })();

4) Vytvoř `js/lib/map-factory.js`:

   (function() {
       'use strict';
       window.GpxMapFactory = window.GpxMapFactory || {};

       window.GpxMapFactory.createBaseLayers = function(apiKeys) {
           const layers = {
               'OSM': L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                   attribution: '© OpenStreetMap',
                   maxZoom: 19
               }),
               'Topo': L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
                   attribution: '© OpenTopoMap',
                   maxZoom: 17
               }),
               'Satellite': L.tileLayer(
                   'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                   { attribution: '© Esri', maxZoom: 19 }
               ),
           };

           if (apiKeys.thunderforest) {
               layers['Thunderforest'] = L.tileLayer(
                   `https://tile.thunderforest.com/cycle/{z}/{x}/{y}.png?apikey=${apiKeys.thunderforest}`,
                   { attribution: '© Thunderforest', maxZoom: 19 }
               );
           }

           if (apiKeys.mapycom) {
               ['Basic', 'Tourist', 'Winter', 'Aerial'].forEach(variant => {
                   layers[`Mapy.cz ${variant}`] = L.tileLayer(
                       `https://api.mapy.com/v1/maptiles/${variant.toLowerCase()}/256/{z}/{x}/{y}?apikey=${apiKeys.mapycom}`,
                       { attribution: '© Seznam.cz a.s.', maxZoom: 19 }
                   );
               });
           }

           return layers;
       };

       window.GpxMapFactory.createWikimediaLayer = function(map) {
           const markers = L.layerGroup();
           // Loader pro Wikimedia Commons fotky — přesuň z detail-map.js / nearby-map.js
           const loadPhotos = function() {
               const bounds = map.getBounds();
               // ... fetch a marker rendering logika ...
           };
           map.on('moveend', loadPhotos);
           return markers;
       };
   })();

5) Najdi všechny soubory v `js/`, které definují vlastní `haversine`,
   `formatDuration`, `escapeHtml`, OSM tile layer init.

   - js/filter-core.js: smaž local haversine, použij `window.GpxGeo.haversine`.
   - js/detail-elevation.js: stejně.
   - js/nearby-data.js: smaž formatDuration + escapeHtml, použij window.GpxFmt.
   - js/filter-ui.js: stejně formatDuration.
   - js/detail-map.js: smaž tile layer init, použij `GpxMapFactory.createBaseLayers({...})`.
   - js/filter-map.js: stejně.
   - js/nearby-map.js: stejně.
   - js/compare-map.js: stejně.

6) Ve všech *_view.php (detail_view, filter_view, nearby_view, compare_view,
   index_view, index_legacy_view) najdi `<script src="js/...`. Přidej PŘED
   ostatní skripty:

   <script src="js/lib/event-bus.js" defer></script>
   <script src="js/lib/geo-utils.js" defer></script>
   <script src="js/lib/format-utils.js" defer></script>
   <script src="js/lib/map-factory.js" defer></script>

   POZN: pokud kód spoléhá na pořadí (event-bus musí být první), zachovej.
   `defer` zachovává pořadí výskytu v dokumentu.

VERIFIKACE:
- Otevři detail trasy — mapa funguje, base layers se zobrazí.
- Otevři filter — elevation graf se vykreslí, haversine funguje.
- DevTools Console — žádné `undefined is not a function`.
- Smaž `window.formatDuration` v inspector — má fallback na `window.GpxFmt.formatDuration`?

Hotovo? Shrnutí + commit.
```

---

## TASK-24 — Theming konsolidace (Tailwind dark mode + odstranění legacy)

**Fáze:** 5
**Pokrývá nálezy:** FE-1, FE-6, FE-12, FE-15, FE-19, A11Y-016, A11Y-017
**Odhad:** 3 h
**Závislosti:** TASK-15 (konstanty).

### Cíl
Vyřešit konflikt dvou theming systémů. Buď konsolidovat na Tailwind, nebo na CSS proměnné v `:root`. Opravit kontrast v 4 problémových tématech.

### Acceptance criteria
- [ ] Rozhodnuto a dokumentováno: zachovat starý systém (CSS proměnné v `theme-*.css`) jako autoritativní pro všechny stránky, NEBO migrovat vše na Tailwind dark mode.
- [ ] FOUC eliminován — theme nastaven inline script v `<head>` před parsováním `<body>`.
- [ ] `--accent-color` v `classic`/`minimal`/`blue` opraven na ≥4.5:1 kontrast.
- [ ] `--text-muted` v `lightgray` opraven na ≥4.5:1.
- [ ] `theme-tester.js` načítán pouze pro admina v lokálním prostředí.
- [ ] `css/variables-reference.css` přejmenován na `docs/css-variables-reference.md` (out of CSS load path).
- [ ] `js/detail-elevation.js` neinjectuje `<style>` runtime — přesunuto do CSS.

### Prompt k zadání
```
Implementuj TASK-24 z AUDIT_REPORT.md. Cíl: konsolidace theming + kontrast fix.

KROKY:

1) ROZHODNUTÍ: Auditem zjištěno, že nový redesign (Tailwind + Alpine) je
   na index.php, photos.php, login.php, layout_header.php. Starý systém
   (theme-*.css) na admin.php, photo_import.php, settings.php, stats.php,
   heatmap.php, calendar.php, edit.php, detail.php, filter.php, nearby.php,
   compare.php, map_search.php.

   STRATEGIE: ponech starý systém (CSS theme-*.css) jako master, protože
   pokrývá většinu stránek a má 9 témat. Nová Tailwind vrstva (.dark třída)
   může koexistovat — `:root` proměnné z theme-*.css se použijí jako
   defaults v Tailwind utility classes.

   ALTERNATIVA: pokud chceš jít druhým směrem, migruj všechny stránky na
   Tailwind. To je samostatná velká task.

2) Vyřeš FOUC. V `includes/layout_header.php` najdi <head> blok.
   Přidej hned za <meta charset> a před externí CSS linky:

   <script>
       (function() {
           // Aplikace tématu před DOMContentLoaded — eliminuje flash
           var theme = '';
           try {
               var match = document.cookie.match(/(?:^|;\s*)theme=([^;]+)/);
               if (match) theme = decodeURIComponent(match[1]);
           } catch (e) {}
           if (!theme) {
               try { theme = localStorage.getItem('theme') || ''; } catch (e) {}
           }
           if (theme) {
               var link = document.createElement('link');
               link.rel = 'stylesheet';
               link.href = 'css/theme-' + theme + '.css';
               link.id = 'theme-style';
               document.head.appendChild(link);
           }
       })();
   </script>

   POZN: theme cookie už setuje PHP před výstupem HTML (server-side),
   takže ji JS čte synchronně. Žádný HTTP request navíc.

3) V `js/theme.js` smaž blok, který v DOMContentLoaded přidává <link>.
   Nech jen logiku pro CHANGE tématu (po výběru z dropdownu).

4) FE-15: v `js/theme-tester.js` (a v jeho inclusion místech) přidej guard:

   V <?php if (!empty($_SESSION['is_admin']) && APP_ENV === 'local'): ?>
       <script src="js/theme-tester.js" defer></script>
   <?php endif; ?>

5) A11Y-016/017: oprav kontrast v theme CSS:

   V `css/theme-classic.css` najdi `--accent-color: #0078d7;` a změň na:
   --accent-color: #005fa3; /* 4.5:1 na #f9f9f9 */

   V `css/theme-minimal.css` `--accent-color: #1e88e5;` →
   --accent-color: #1565c0;

   V `css/theme-blue.css` `--accent-color: #1976d2;` →
   --accent-color: #1565c0;

   V `css/theme-lightgray.css` `--text-muted: #777777;` →
   --text-muted: #636363; /* 4.55:1 */

   Volitelně přidej `--link-color` jako samostatnou proměnnou pro inline
   text, kde je potřeba ještě tmavší než UI accent.

6) FE-12: v `js/detail-elevation.js` najdi blok ~261-296, kde se přes
   `document.createElement('style')` injectuje CSS. Přesuň pravidla:

   - `.slope-legend { ... }`
   - `.tooltip-toggle { ... }`

   do `css/style.css` nebo nového `css/detail.css`. V JS soubor odstraň
   inject blok.

7) FE-19: přejmenuj `css/variables-reference.css` na
   `docs/css-variables-reference.md` (přesuň do nové složky `docs/`).
   Pokud má skutečný CSS obsah, převeď ho na markdown code blocks.

8) Aktualizuj `.gitignore`:
   /css/variables-reference.css  (pokud zůstává v gitu, nemůže být v gitignore)
   Místo toho v `.htaccess` přidej blokaci — ale jen pokud zůstane v repu:

   <Files "variables-reference.css">
       Require all denied
   </Files>

   Lepší: úplně smazat z repu (po přesunu do docs/).

VERIFIKACE:
- Otevři aplikaci s tématem `lightgray` — muted text čitelný.
- Refresh stránky → žádný flash dřívějšího/výchozího tématu.
- Změň téma v dropdownu → okamžitý přechod, žádné flicker.
- Color contrast checker (axe DevTools, WAVE) na index s `classic` tématem →
  link contrast ≥ 4.5:1.
- theme-tester nelze najít v page source jako visitor.

Hotovo? Shrnutí + commit.
```

---

## TASK-25 — i18n konzistence: JS strings, drift fix, t_arr migrace

**Fáze:** 5
**Pokrývá nálezy:** FE-16, QR-11, QR-12, QR-17
**Odhad:** 2 h
**Závislosti:** TASK-14 (lint_lang.php existuje), TASK-16 (t_arr existuje).

### Cíl
Sjednotit JS i18n na jeden vzor (`window.gpxI18n`). Doplnit chybějící klíče napříč 8 jazyky. Sweep raw Czech v `photos.php`, `photo_import.php`, `admin.php`, `setup.php`.

### Acceptance criteria
- [ ] `layout_header.php` exportuje `window.gpxI18n = <?= js_safe_json($commonI18nKeys) ?>` pro JS.
- [ ] `js/detail-weather.js`, `js/filter-ui.js`, `js/detail-elevation.js` čtou z `window.gpxI18n` místo hardcoded strings.
- [ ] `scripts/lint_lang.php` projde bez chyb.
- [ ] V `photos.php`, `photo_import.php`, `admin.php`, `setup.php` raw české stringy nahrazeny `t('key')`.
- [ ] Aktivity types v DB: rozhodnuto, zda migrovat na lower-case english klíče (`hiking`, `biking`...) nebo nechat české hodnoty (rozhodnutí dokumentuj v komentáři).

### Prompt k zadání
```
Implementuj TASK-25 z AUDIT_REPORT.md. Cíl: i18n konzistence.

KROKY:

1) V `includes/layout_header.php` najdi <head> nebo <body> sekci.
   Přidej před načtením JS skriptů:

   <?php
   $commonI18n = [
       'loading' => t('loading', 'Načítání...'),
       'error' => t('error', 'Chyba'),
       'save' => t('save', 'Uložit'),
       'cancel' => t('cancel', 'Zrušit'),
       'delete' => t('delete', 'Smazat'),
       'confirm_delete' => t('confirm_delete', 'Opravdu smazat?'),
       'speed' => t('speed', 'Rychlost'),
       'gps_jumps' => t('gps_jumps', 'GPS skoky'),
       'stationary' => t('stationary', 'Stojící'),
       'elevation' => t('elevation', 'Převýšení'),
       'duration' => t('duration', 'Doba'),
       'distance' => t('distance', 'Vzdálenost'),
       // ... další podle potřeby
   ];
   ?>
   <script>
       window.gpxI18n = <?= js_safe_json($commonI18n) ?>;
   </script>

2) V `js/detail-weather.js` najdi hardcoded CS/EN object (řádky ~51-57).
   Smaž a použij window.gpxI18n:

   const t = (key, fallback) => (window.gpxI18n && window.gpxI18n[key]) || fallback || key;
   const txtCloudy = t('weather_cloudy', 'Zataženo');

3) V `js/filter-ui.js` najdi hardcoded labels (řádky ~237-244 `"Rychlost"`,
   `"GPS skoky"` atd.). Použij stejný t() helper.

4) V `js/detail-elevation.js` zachovej existující `window.gpxDetailData.i18n`
   pattern (je správný), případně sjednoť s `window.gpxI18n`.

5) Spusť `php scripts/lint_lang.php`. Pokud nahlásí chybějící klíče:

   - cs/en mají 369 klíčů.
   - de/sk/es/fr/it/pl/it mají 368 — chybí `back_to_table` a možná další.

   Najdi chybějící klíče, přidej je do non-cs/en souborů (přelož ze zdroje cs).

   Pokud cs.php má orphan klíče (`tras`, `trasa`, `trasy`), buď je přidej
   do en/de/sk/es/fr/it/pl, nebo z cs odstraň.

   Pokud en.php má `track`/`tracks` které cs nemá, stejně.

6) Sweep raw Czech v PHP:

   Otevři `photos.php`, `photo_import.php`, `admin.php`, `setup.php`.
   Najdi raw Czech texty mimo `t()` (např. "Fotografie tras", "Bez GPS",
   "Načítání", "Skrýt přehled"). Nahraď za `t('key', 'fallback Czech')`.

   Pro každý nový klíč přidej do všech 8 lang souborů (alespoň jako kopii cs).

7) Aktivity types — rozhodnutí:

   V DB sloupec `activity_type` obsahuje hodnoty jako `'Pěšky'`, `'Auto'`,
   `'Turistika'` (české). To znemožňuje překlad UI.

   OPTION A (recommended): Nech jak je, vytvoř `activity_type_label($val): string`
   helper, který pro DB hodnotu vrátí přeloženou label:

   function activity_type_label(string $val): string {
       $map = [
           'Pěšky' => t('act_walking', 'Pěšky'),
           'Turistika' => t('act_hiking', 'Turistika'),
           'Běh' => t('act_running', 'Běh'),
           'Kolo' => t('act_biking', 'Kolo'),
           'E-bike' => t('act_ebiking', 'E-bike'),
           'Auto' => t('act_car', 'Auto'),
       ];
       return $map[$val] ?? $val;
   }

   Použij v `activityBadge()` a všude, kde se vykresluje aktivita.

   OPTION B: Migrate DB. Vytvoř migration:

   UPDATE tracks SET activity_type = CASE activity_type
       WHEN 'Pěšky' THEN 'walking'
       WHEN 'Turistika' THEN 'hiking'
       WHEN 'Běh' THEN 'running'
       WHEN 'Kolo' THEN 'biking'
       WHEN 'E-bike' THEN 'ebiking'
       WHEN 'Auto' THEN 'car'
       ELSE activity_type
   END;

   Plus aktualizuj `detectActivityType()` aby vracela klíče (`walking`,
   `hiking`, atd.) místo českých hodnot. Updatuj všechny WHERE filtry.

   Pro tuto task zvol OPTION A — méně risku, žádná migrace dat.

VERIFIKACE:
- `php scripts/lint_lang.php` projde.
- Změň lang na en → photos.php zobrazí EN labely (po sweep).
- DevTools Console — žádné undefined v window.gpxI18n.

Hotovo? Shrnutí + commit.
```

---

KONEC FÁZE 5.

## TASK-26 — A11y quick wins (lang, skip link, reduced-motion, kontrast, ARIA basics)

**Fáze:** 6
**Pokrývá nálezy:** A11Y-001, A11Y-002, A11Y-016, A11Y-017, A11Y-018, A11Y-019, A11Y-020, A11Y-025, A11Y-027, A11Y-028, A11Y-029
**Odhad:** 2 h
**Závislosti:** TASK-24 (kontrast fix).

### Cíl
11 jednoduchých a11y oprav (< 30 min každá) najednou.

### Acceptance criteria
- [ ] `<html lang="<?= app_lang() ?>">` na všech stránkách (layout_header.php, login.php, photo_import.php, rebuild_thumbs.php).
- [ ] Skip link jako první focusable v `<body>` v `layout_header.php`.
- [ ] `@media (prefers-reduced-motion: reduce)` blok v `assets/css/input.css` (a v `app.css` po buildu).
- [ ] Filter badge "stationary" a "speed" kontrast opraveny.
- [ ] Save message kontrast opraven + `role="status"`.
- [ ] Import status: kromě barvy přidána ikona/text.
- [ ] Lucide `<i data-lucide="...">` placeholdery mají `aria-hidden="true"` (kromě těch v icon-only buttonech).
- [ ] `<input type="file">` mají `aria-label`.
- [ ] Chip filter v `index_view.php` nahrazen `aria-pressed` za `aria-current`.

### Prompt k zadání
```
Implementuj TASK-26 z AUDIT_REPORT.md. Cíl: 11 a11y quick fixů.

KROKY:

1) A11Y-001: V `includes/layout_header.php:23` nahraď:
   <html lang="cs">
   za:
   <html lang="<?= htmlspecialchars(function_exists('app_lang') ? app_lang() : 'cs') ?>">

   Stejně v: `login.php:50`, `photo_import.php:223`, `rebuild_thumbs.php:64`.

2) A11Y-002: V `layout_header.php` hned za otevírací <body> tag přidej:

   <a href="#main-content" class="skip-link">
       <?= htmlspecialchars(t('skip_to_content', 'Přeskočit na obsah')) ?>
   </a>

   A přidej id na <main> (řádek ~229):
   <main id="main-content" ...>

   Do css/style.css přidej styly:

   .skip-link {
       position: absolute;
       left: -9999px;
       z-index: 10000;
       padding: 8px 16px;
       background: #003366;
       color: #fff;
       text-decoration: none;
   }
   .skip-link:focus {
       left: 8px;
       top: 8px;
       outline: 2px solid #fff;
   }

   I18n klíče (8 jazyků):
   'skip_to_content' => 'Přeskočit na obsah',
   en: 'Skip to main content', de: 'Zum Inhalt springen', ...

3) A11Y-020: V `assets/css/input.css` přidej na konec:

   @media (prefers-reduced-motion: reduce) {
       *, *::before, *::after {
           animation-duration: 0.01ms !important;
           animation-iteration-count: 1 !important;
           transition-duration: 0.01ms !important;
           scroll-behavior: auto !important;
       }
   }

   Stejné přidej i do `css/style.css` (pro stránky, které nepoužívají Tailwind).

   Spusť `npm run build` pro regeneraci `assets/css/app.css`.

4) A11Y-018: V `css/filter.css` najdi `.removal-badge.stationary` (~řádek 373).
   Změň color na `#6e6b00` (6.5:1 na bílé).
   Pro `.removal-badge.speed` `#e53935` změň na `#c62828`.

   Přidej i ikonu nebo symbol prefix pro každý badge type:
   .removal-badge.speed::before { content: "⚡ "; }
   .removal-badge.stationary::before { content: "⏸ "; }
   .removal-badge.spike::before { content: "📍 "; }

5) A11Y-019: V `settings.php` (řádky ~184-186) najdi:
   .save-msg { color: #28a745; }
   Změň na: color: #1a7431; (4.6:1)

   V HTML kde se vykresluje save message přidej:
   <div class="save-msg" role="status" aria-live="polite">...</div>

6) A11Y-029: V `css/style.css` najdi `.item.status-*` (~řádky 524-527).
   Přidej ikony jako content nebo background:

   .item.status-inserted::before { content: "✓ "; color: #2e7d32; font-weight: bold; }
   .item.status-overwritten::before { content: "→ "; color: #e65100; }
   .item.status-skipped::before { content: "○ "; color: #757575; }
   .item.status-error::before { content: "✗ "; color: #c62828; font-weight: bold; }

   Případně přidej do JS, který nastavuje status, plný textový label:
   item.querySelector('.status').textContent = result.status_label;

7) A11Y-025: Najdi všechna `<i data-lucide="...">` v PHP a HTML.
   Přidej `aria-hidden="true"` všude, KROMĚ míst kde je ikona JEDINÝ obsah
   interactive elementu (button bez textu) — tam musí být aria-label
   na buttonu, a ikonu označit aria-hidden:

   <button aria-label="Settings">
       <i data-lucide="settings" aria-hidden="true"></i>
   </button>

   Použij Grep `data-lucide=` napříč *.php a includes/*.php.
   Aplikuj systematicky.

8) A11Y-027: Najdi `<input type="file">` (Grep `type="file"`).
   Přidej aria-label (nebo zkontroluj, že existuje <label for=...>):

   <input type="file" id="fileInput" accept=".gpx" multiple
          aria-label="<?= htmlspecialchars(t('upload_gpx_label', 'Vybrat GPX soubory')) ?>">

9) A11Y-028: V `includes/index_view.php` (řádky ~145-165) najdi
   <a class="chip-filter" ... aria-pressed="true">. Nahraď aria-pressed
   za aria-current:

   <a class="chip-filter" href="?cat=hiking"
      <?= $activeCategory === 'hiking' ? 'aria-current="true"' : '' ?>>
       Turistika
   </a>

10) Doplň jazykové klíče (skip_to_content, atd.) do všech 8 lang/*.php souborů.

VERIFIKACE:
- `php scripts/lint_lang.php` projde.
- Otevři aplikaci. První Tab → zobrazí se "Přeskočit na obsah" link.
- Změň jazyk na en → <html lang="en"> v page source.
- DevTools accessibility tree — všechny dekorativní ikony aria-hidden.
- axe DevTools nebo WAVE → menší počet errorů než předtím.
- Otevři macOS System Preferences → Reduce motion → otevři filter — žádné animace.

Hotovo? Shrnutí + commit.
```

---

## TASK-27 — A11y forms (login, settings radio, edit, setup)

**Fáze:** 6
**Pokrývá nálezy:** A11Y-007, A11Y-008, A11Y-009, A11Y-015
**Odhad:** 1.5 h

### Cíl
Form labely, autocomplete, error stavy, screen reader announcements.

### Prompt k zadání
```
Implementuj TASK-27 z AUDIT_REPORT.md. Cíl: a11y na formulářích.

KROKY:

1) A11Y-007: V `login.php` (řádky ~93-103) přepiš form:

   <form method="post">
       <?= csrf_field() ?>
       <label for="login-user"><?= t('username', 'Uživatel') ?></label>
       <input type="text" id="login-user" name="user" required autofocus
              autocomplete="username" aria-describedby="login-error">

       <label for="login-pass"><?= t('password', 'Heslo') ?></label>
       <input type="password" id="login-pass" name="pass" required
              autocomplete="current-password" aria-describedby="login-error">

       <div id="login-error" role="alert" aria-live="assertive">
           <?php if ($error): ?><?= htmlspecialchars($error) ?><?php endif; ?>
       </div>

       <button type="submit"><?= t('login', 'Přihlásit se') ?></button>
   </form>

2) A11Y-015: V `settings.php` (~řádek 131, 165) najdi:
   .radio-group-inline input { display: none; }
   .lang-grid input { display: none; }

   Nahraď za sr-only pattern (zachovává input v accessibility tree):

   .radio-group-inline input,
   .lang-grid input {
       position: absolute;
       width: 1px;
       height: 1px;
       padding: 0;
       margin: -1px;
       overflow: hidden;
       clip: rect(0, 0, 0, 0);
       white-space: nowrap;
       border: 0;
   }
   /* Visible focus ring na label keď input má focus */
   .radio-group-inline input:focus-visible + label,
   .lang-grid input:focus-visible + label {
       outline: 2px solid var(--accent-color);
       outline-offset: 2px;
   }

3) A11Y-008: V `setup.php` (~řádky 357-413) najdi všechny <label> bez for/id.
   Přidej pár pro každé pole:

   <label for="setup-db-host">DB Host</label>
   <input type="text" id="setup-db-host" name="dbHost" required>

   Stejně pro: dbName, dbUser, dbPass, adminUser, adminPass, adminPass2,
   adminIps, tfKey, mapyKey, mapillaryTok.

   API klíče: použij type="password" nebo přidej autocomplete="off".

   Errors blok (řádky ~349-352):
   <div class="errors" role="alert"><?= ... ?></div>

4) A11Y-009: V `edit.php` (~řádky 304-308) flash message:

   <?php if ($flash): ?>
       <div role="<?= str_starts_with($flash, 'Chyba') ? 'alert' : 'status' ?>"
            aria-live="<?= str_starts_with($flash, 'Chyba') ? 'assertive' : 'polite' ?>"
            class="flash <?= str_starts_with($flash, 'Chyba') ? 'flash-error' : 'flash-ok' ?>">
           <?= htmlspecialchars($flash) ?>
       </div>
   <?php endif; ?>

   V všech <input>/<select>/<textarea>:
   - Přidej `<label for=>` + `id=` pokud chybí
   - U povinných polí přidej `required` a `aria-required="true"`
   - Při error stavu (validace selhala): `aria-invalid="true"` a
     `aria-describedby="field-id-error"` na input + `<span id="field-id-error" class="field-error">...</span>` blízko.

5) Aktualizuj 8 lang souborů s novými klíči.

VERIFIKACE:
- Login: klikni na visible label "Uživatel" — focus přejde do inputu.
- Settings: Tab přes radio buttony — focus ring na labely, lze vybrat klávesnicí.
- Edit: ulož s chybou (nech prázdné povinné pole) — screen reader oznámí chybu.
- axe DevTools — žádné form-related errory.

Hotovo? Shrnutí + commit.
```

---

## TASK-28 — A11y tables (track table + sortable headers + landmarks)

**Fáze:** 6
**Pokrývá nálezy:** A11Y-005, A11Y-006, A11Y-023, A11Y-024
**Odhad:** 2 h
**Závislosti:** TASK-16 (sort_th vrací aria-sort).

### Cíl
Tabulka tras kompletně přístupná. Sortable headers s `aria-sort`. Sjednocení landmarků.

### Prompt k zadání
```
Implementuj TASK-28 z AUDIT_REPORT.md. Cíl: a11y na tabulkách + landmarcích.

KROKY:

1) A11Y-005: V `includes/table_tracks.php` (~řádky 4-209) přepiš strukturu:

   <table class="track-table" aria-label="<?= htmlspecialchars(t('table_tracks_caption', 'Seznam tras')) ?>">
       <caption class="sr-only"><?= t('table_tracks_caption', 'Seznam tras') ?></caption>
       <thead>
           <tr>
               <th scope="col" class="col-compare">
                   <input type="checkbox" id="select-all"
                          aria-label="<?= htmlspecialchars(t('select_all', 'Vybrat vše')) ?>">
               </th>
               <th scope="col" class="col-photos">
                   <span aria-label="<?= htmlspecialchars(t('th_photos', 'Fotky')) ?>"
                         title="<?= htmlspecialchars(t('th_photos', 'Fotky')) ?>">📸</span>
               </th>
               <th scope="col" class="col-favorite">
                   <span aria-label="<?= htmlspecialchars(t('th_favorite', 'Oblíbené')) ?>"
                         title="<?= htmlspecialchars(t('th_favorite', 'Oblíbené')) ?>">⭐</span>
               </th>
               <!-- Sortable headers — použij sort_th() z TASK-16 -->
               <?= sort_th('track_name', t('th_name', 'Název'), $sort_by, $sort_dir) ?>
               <?= sort_th('date_start', t('th_date', 'Datum'), $sort_by, $sort_dir) ?>
               <?= sort_th('distance_km', t('th_distance', 'Vzdálenost'), $sort_by, $sort_dir) ?>
               <!-- atd. pro všechny sortable sloupce -->
           </tr>
       </thead>
       <tbody>
           <?php foreach ($tracks as $t): ?>
               <tr>
                   <td>...</td>
                   <!-- buňky -->
               </tr>
           <?php endforeach; ?>
       </tbody>
   </table>

   Přidej do CSS:
   .sr-only {
       position: absolute;
       width: 1px;
       height: 1px;
       padding: 0;
       margin: -1px;
       overflow: hidden;
       clip: rect(0, 0, 0, 0);
       white-space: nowrap;
       border: 0;
   }

2) A11Y-006: Ověř, že `sort_th()` v `includes/sort_query.php` (z TASK-16)
   vrací <th> s `aria-sort="ascending|descending|none"`. Pokud ne, doplň.

3) A11Y-023: V `includes/filter_view.php:238` najdi `<main class="filter-main">`.
   Nahraď za `<div class="filter-main" role="region" aria-label="...">` aby
   nebyly dva <main> na stránce.

4) A11Y-024: Najdi `#sidebarToggle` (pravděpodobně v `index_view.php` nebo
   `index_legacy_view.php`). Přidej atributy:

   <button id="sidebarToggle"
           aria-expanded="false"
           aria-controls="indexSidebar"
           aria-label="<?= htmlspecialchars(t('toggle_filters', 'Otevřít filtry')) ?>">
       <!-- ikona -->
   </button>

   V JS (js/mobile-init.js nebo podobném) updateuj `aria-expanded` při open/close.

   Sidebar element:
   <aside id="indexSidebar"
          role="region"
          aria-label="<?= htmlspecialchars(t('filters_panel', 'Panel filtrů')) ?>">
       ...
   </aside>

5) Doplň i18n klíče do všech 8 lang souborů:
   - table_tracks_caption
   - select_all
   - th_photos, th_favorite, th_name, th_date, th_distance, atd.
   - toggle_filters
   - filters_panel

VERIFIKACE:
- Screen reader (NVDA/VoiceOver) na tabulku tras — slyšíš "tabulka, Seznam tras,
  X řádků, X sloupců", header column announcements.
- Klikni na sortable column — aria-sort se mění.
- Filter page nemá dva <main> elementy (DevTools accessibility tree).
- Mobile sidebar toggle — aria-expanded reflects state.

Hotovo? Shrnutí + commit.
```

---

## TASK-29 — A11y modals (lightbox, mobile nav, lang switcher) — focus management

**Fáze:** 6
**Pokrývá nálezy:** A11Y-003, A11Y-004, A11Y-012, A11Y-013, A11Y-026
**Odhad:** 2.5 h

### Cíl
Focus trap, return-focus, dialog ARIA semantika pro 3 modal-like UI prvky.

### Acceptance criteria
- [ ] Lightbox (`js/lightbox.js`) má focus trap, return-focus, dialog role + aria-label.
- [ ] Mobile drawer (Alpine.js v `layout_header.php`) má focus trap, return-focus, aria-modal.
- [ ] Language switcher dropdown má role="menu", arrow key nav, aria-expanded.
- [ ] `prompt()` v `js/filter-ui.js:438` pro preset name nahrazeno inline form/modal.

### Prompt k zadání
```
Implementuj TASK-29 z AUDIT_REPORT.md. Cíl: focus management v modalech.

KROKY:

1) A11Y-012: V `js/lightbox.js` najdi `open()` (~řádek 71). Uprav:

   open(photos, index, triggerEl) {
       this._photos = photos;
       this._index = index;
       this._triggerEl = triggerEl;  // store trigger pro return-focus

       this._render();
       this._overlay.classList.add('open');
       this._overlay.setAttribute('role', 'dialog');
       this._overlay.setAttribute('aria-modal', 'true');
       const photo = photos[index];
       this._overlay.setAttribute('aria-label',
           'Fotografie ' + (index + 1) + ' z ' + photos.length +
           (photo.caption ? ' — ' + photo.caption : ''));

       // Focus na close button
       const closeBtn = this._overlay.querySelector('.lightbox-close');
       if (closeBtn) closeBtn.focus();

       // Focus trap
       this._boundTrap = this._trapFocus.bind(this);
       this._overlay.addEventListener('keydown', this._boundTrap);

       document.body.style.overflow = 'hidden';
   }

   close() {
       this._overlay.classList.remove('open');
       this._overlay.removeEventListener('keydown', this._boundTrap);
       document.body.style.overflow = '';
       if (this._triggerEl && typeof this._triggerEl.focus === 'function') {
           this._triggerEl.focus();
       }
       this._triggerEl = null;
   }

   _trapFocus(e) {
       if (e.key !== 'Tab') return;
       const focusable = Array.from(
           this._overlay.querySelectorAll('button:not([disabled]), a[href]')
       );
       if (!focusable.length) return;
       const first = focusable[0];
       const last = focusable[focusable.length - 1];
       if (e.shiftKey && document.activeElement === first) {
           e.preventDefault();
           last.focus();
       } else if (!e.shiftKey && document.activeElement === last) {
           e.preventDefault();
           first.focus();
       }
   }

   V všech volajících `gpxLightbox.open(photos, idx)` přidej trigger argument:
   gpxLightbox.open(photos, idx, event.currentTarget);

2) A11Y-013: V `lightbox.js` najdi caption assignment. Uprav aby alt
   obsahoval popis nebo aspoň filename:

   this._img.alt = p.caption || p.filename || ('Photo ' + (this._index + 1));
   // Caption element:
   this._captionEl.textContent = [p.caption, p.taken_at].filter(Boolean).join(' — ');

3) A11Y-003: V `includes/layout_header.php` (~řádky 165-221) najdi mobile drawer
   <aside> a hamburger button. Přidej ARIA + focus trap přes Alpine.js:

   <button x-data="{}" x-on:click="$store.mobileNav.toggle()"
           :aria-expanded="$store.mobileNav.open"
           aria-controls="mobile-nav-drawer"
           aria-label="<?= htmlspecialchars(t('toggle_nav', 'Otevřít navigaci')) ?>">
       ...
   </button>

   <aside id="mobile-nav-drawer"
          role="dialog"
          aria-modal="true"
          aria-label="<?= htmlspecialchars(t('navigation', 'Navigace')) ?>"
          x-show="$store.mobileNav.open"
          x-trap.noscroll="$store.mobileNav.open"
          x-on:keydown.escape.window="$store.mobileNav.open = false">
       <button @click="$store.mobileNav.open = false"
               aria-label="<?= htmlspecialchars(t('close_nav', 'Zavřít navigaci')) ?>">✕</button>
       ...
   </aside>

   POZN: `x-trap` plugin Alpine.js (`@alpinejs/focus`) implementuje focus trap.
   Pokud ho nemáš, přidej do <head>:

   <script src="https://unpkg.com/@alpinejs/focus@3.14.1/dist/cdn.min.js"
           integrity="sha384-..." crossorigin defer></script>

   Plus return-focus přes onclose listener (volitelné — focus trap to pokrývá).

4) A11Y-004: V `includes/layout_header.php` (~řádky 115-140) language switcher.
   Přidej role/aria:

   <div x-data="{ open: false }" class="lang-switcher">
       <button @click="open = !open" :aria-expanded="open"
               aria-haspopup="menu"
               aria-label="<?= htmlspecialchars(t('language', 'Jazyk')) ?>">
           <!-- aktuální jazyk + ikona -->
       </button>
       <div x-show="open" @click.outside="open = false"
            role="menu"
            x-trap.noscroll="open">
           <?php foreach (available_langs() as $lang): ?>
               <a href="?app_lang=<?= $lang ?>"
                  role="menuitem"
                  <?= app_lang() === $lang ? 'aria-current="true"' : '' ?>>
                   <?= strtoupper($lang) ?>
               </a>
           <?php endforeach; ?>
       </div>
   </div>

   Arrow key navigation: Alpine.js může mít manual handler, nebo použij
   Alpine focus plugin keyboard helpers.

5) A11Y-026: V `js/filter-ui.js` najdi `prompt("Nazev presetu:")` (~řádek 438).
   Nahraď inline form modalem nebo prostě HTML5 input dialogem:

   PŘED:
   const name = prompt("Nazev presetu:");

   PO (nejjednodušší — použij existující modal pattern v projektu, nebo
   přidej skrytý <dialog> element):

   const dialog = document.getElementById('preset-name-dialog');
   const input = dialog.querySelector('input[name="preset-name"]');
   input.value = '';
   dialog.showModal();
   const submitHandler = (e) => {
       e.preventDefault();
       const name = input.value.trim();
       if (!name) return;
       dialog.close();
       savePreset(name);
   };
   dialog.querySelector('form').addEventListener('submit', submitHandler, { once: true });

   V `includes/filter_view.php` přidej HTML pro dialog:

   <dialog id="preset-name-dialog">
       <form method="dialog">
           <label for="preset-name-input"><?= t('preset_name', 'Název presetu') ?></label>
           <input type="text" name="preset-name" id="preset-name-input" required>
           <button type="submit"><?= t('save', 'Uložit') ?></button>
           <button type="button" onclick="this.closest('dialog').close()">
               <?= t('cancel', 'Zrušit') ?>
           </button>
       </form>
   </dialog>

   POZN: HTML <dialog> má built-in focus management — focus trap, esc to close.

VERIFIKACE:
- Otevři lightbox z fotky — focus přejde na close button. Tab cykluje uvnitř.
  Esc zavře, focus se vrátí na původní thumbnail.
- Mobile drawer (zúžit okno) — Tab uvnitř, Esc zavře.
- Language switcher — Tab + Enter otevře menu, šipky procházejí.
- Preset save v filter — místo browser prompt() vidíš inline dialog.

Hotovo? Shrnutí + commit.
```

---

## TASK-30 — A11y mapy + grafy (text alternatives + skip links)

**Fáze:** 6
**Pokrývá nálezy:** A11Y-021, A11Y-022
**Odhad:** 2 h
**Závislosti:** žádné.

### Cíl
Mapy a Chart.js canvasy musí mít text alternative pro screen readery.

### Acceptance criteria
- [ ] Všechny `<div id="map">` a `<div id="filterMap">` mají `role="img"`, `aria-label`.
- [ ] Před každou mapou: skip link na klíčová data (stats card, table).
- [ ] Chart.js `<canvas id="elev">` má `role="img"` a `aria-label`.
- [ ] Pod každým grafem: skrytá `<table class="sr-only">` s číselnými daty.

### Prompt k zadání
```
Implementuj TASK-30 z AUDIT_REPORT.md. Cíl: a11y na mapách a grafech.

KROKY:

1) A11Y-021: Najdi všechna místa s `<div id="map">` nebo `<div id="filterMap">`.

   V `includes/detail_view.php:222`:

   <a href="#track-stats" class="skip-link sr-only-focusable">
       <?= htmlspecialchars(t('skip_to_data', 'Přejít na data trasy')) ?>
   </a>
   <div id="map"
        role="img"
        aria-label="<?= htmlspecialchars(t('map_aria',
            sprintf('Mapa trasy: %s', $track['track_name'] ?? $track['filename']))) ?>">
   </div>

   Stejně v `filter_view.php:273`, `nearby_view.php:34`, `compare_view.php:36`,
   `heatmap.php:182`, `map_search.php:144`.

   Přidej k `<main>` nebo stat sekci `id="track-stats"`, aby skip link fungoval.

   Skip link styles (přidej do CSS):
   .sr-only-focusable {
       position: absolute;
       width: 1px;
       height: 1px;
       overflow: hidden;
   }
   .sr-only-focusable:focus {
       position: static;
       width: auto;
       height: auto;
       padding: 8px 12px;
       background: var(--accent-color);
       color: #fff;
   }

2) A11Y-022: V `includes/detail_view.php:272` <canvas id="elev">:

   <canvas id="elev"
           role="img"
           aria-label="<?= htmlspecialchars(t('elev_chart_aria', 'Výškový profil trasy')) ?>">
   </canvas>

   <details class="sr-only" id="elev-data-table-container">
       <summary><?= htmlspecialchars(t('elev_data_table', 'Tabulka dat výškového profilu')) ?></summary>
       <table id="elev-data-table">
           <caption><?= htmlspecialchars(t('elev_data_caption', 'Hodnoty nadmořské výšky podél trasy')) ?></caption>
           <thead>
               <tr>
                   <th scope="col"><?= t('distance', 'Vzdálenost') ?> (km)</th>
                   <th scope="col"><?= t('elevation', 'Nadm. výška') ?> (m)</th>
               </tr>
           </thead>
           <tbody id="elev-data-tbody"></tbody>
       </table>
   </details>

   V `js/detail-elevation.js` po vykreslení grafu naplň tabulku:

   const tbody = document.getElementById('elev-data-tbody');
   if (tbody && data) {
       const sampled = window.GpxGeo.subsampleArrays([data.distances, data.elevations], 50);
       tbody.innerHTML = sampled[0].map((d, i) =>
           `<tr><td>${d.toFixed(2)}</td><td>${sampled[1][i].toFixed(0)}</td></tr>`
       ).join('');
   }

   Stejně pro `<canvas id="filterElev">` v `filter_view.php:291`.

3) Heatmap — A11Y-021 dodatek. `heatmap.php` má mapu s heatmapovou
   vrstvou. Přidej krátký textový summary:

   <p class="sr-only" aria-live="polite" id="heatmap-summary">
       <?= htmlspecialchars(t('heatmap_summary',
           sprintf('Heatmapa zobrazuje %d tras na ploše Česká republika.',
                   $tracksCount))) ?>
   </p>

4) Doplň i18n klíče.

VERIFIKACE:
- Screen reader na detail page → slyšíš "image, Mapa trasy: [název]".
- Tab → před mapou se zobrazí "Přejít na data trasy" link, Enter skočí na statistiky.
- Otevři `<details>` pod chartem — uvidíš data jako tabulku.
- axe DevTools — žádné canvas-related errory.

Hotovo? Shrnutí + commit.
```

---

KONEC FÁZE 6.

---

# ČÁST D — Přílohy

## D.1 Referenční `.htaccess` (kompletní)

Viz **TASK-12** výše pro plnou verzi.

## D.2 Referenční GitHub Actions CI workflow

Viz **TASK-14** výše.

## D.3 Doporučený `docker-compose.yml` pro lokální dev

```yaml
services:
  php:
    image: php:8.2-apache
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
    environment:
      APP_ENV: local
    depends_on:
      - db
    command: >
      bash -c "
        docker-php-ext-install pdo_mysql simplexml gd exif zip &&
        a2enmod rewrite headers expires deflate &&
        apache2-foreground
      "
  db:
    image: mysql:8.0
    ports: ["3307:3306"]
    environment:
      MYSQL_DATABASE: gpx_manager
      MYSQL_USER: gpx
      MYSQL_PASSWORD: gpx_dev
      MYSQL_ROOT_PASSWORD: root_dev
    volumes:
      - db_data:/var/lib/mysql
      - ./install.sql:/docker-entrypoint-initdb.d/install.sql

volumes:
  db_data:
```

`.env` pro Docker:
```
DB_HOST=db
DB_NAME=gpx_manager
DB_USER=gpx
DB_PASS=gpx_dev
```

## D.4 Mapování TASK → soubory (rychlá orientace)

| Task | Hlavní soubory |
|---|---|
| TASK-01 | `includes/gpx_parser.php`, `export_*.php`, `heatmap.php`, `includes/nearby_data.php`, `includes/generate_thumb.php`, `uploads/.htaccess`, `logs/`, `config.php` |
| TASK-02 | `api_toggle_favorite.php`, `admin.php`, `photos.php`, `includes/layout_header.php` |
| TASK-03 | `includes/helpers.php`, `edit.php`, `api_bulk_action.php`, `recalc_activity.php`, `includes/filter_data.php` |
| TASK-04 | `js/detail-map.js`, `photos.php`, `includes/helpers.php`, `includes/*_view.php` |
| TASK-05 | `setup.php`, `INSTALL.md`, `instructions/*.md`, `.gitignore` |
| TASK-06 | `login.php`, `includes/auth.php`, `includes/public_access.php`, migrace |
| TASK-07 | `export.php`, `heatmap.php`, `includes/nearby_data.php`, `includes/photo_helper.php`, `photos.php` |
| TASK-08 | `includes/security.php`, všechna `*_view.php` s CDN, `includes/layout_header.php` |
| TASK-09 | `includes/db.php`, `migrations/*.sql`, `migrate.php`, `INSTALL.md` |
| TASK-10 | `migrations/0011_indexes.sql`, `migrations/0012_file_hash_sha256.sql` |
| TASK-11 | `migrations/0013_centroid.sql`, `includes/detail_data.php`, `includes/nearby_data.php`, `edit.php`, `import.php` |
| TASK-12 | `.htaccess`, `uploads/.htaccess` |
| TASK-13 | `composer.json`, `config.php`, `includes/*.php` (strict_types), `phpunit.xml.dist`, `tests/` |
| TASK-14 | `.github/workflows/ci.yml`, `package.json`, `scripts/lint_lang.php` |
| TASK-15 | nový `includes/app_constants.php`, 15 souborů s theme/lang duplikací |
| TASK-16 | `includes/helpers.php` + 6 nových souborů v `includes/` |
| TASK-17 | nový `includes/partials/admin_banner.php`, `includes/auth.php`, `includes/public_access.php`, `lang/*.php` |
| TASK-18 | `photos.php`, nový `includes/photos_data.php`, `includes/photos_view.php`, `api/photos/*.php`, `js/photos.js` |
| TASK-19 | `photo_import.php`, `includes/photo_import_*.php`, `api/photo_import/*.php`, `css/photo-import.css`, `js/photo-import.js` |
| TASK-20 | `includes/generate_thumb.php`, volitelně `thumb.php` |
| TASK-21 | `includes/gpx_parser.php` |
| TASK-22 | `js/detail-map.js`, `js/index-ajax.js`, `js/index-ui.js`, `js/index-data.js` (delete), all CDN scripts |
| TASK-23 | nové `js/lib/*.js`, `js/*-map.js`, `js/filter-core.js`, `js/detail-elevation.js`, `*_view.php` script tags |
| TASK-24 | `css/theme-*.css`, `includes/layout_header.php`, `js/theme.js`, `js/theme-tester.js`, `js/detail-elevation.js`, `css/variables-reference.css` |
| TASK-25 | `includes/layout_header.php`, `js/detail-weather.js`, `js/filter-ui.js`, `lang/*.php`, `photos.php`, `photo_import.php`, `admin.php`, `setup.php` |
| TASK-26 | `includes/layout_header.php`, `login.php`, `photo_import.php`, `rebuild_thumbs.php`, `assets/css/input.css`, `css/style.css`, `css/filter.css`, `settings.php`, `includes/index_view.php`, all `data-lucide=`, all file inputs |
| TASK-27 | `login.php`, `settings.php`, `setup.php`, `edit.php`, `lang/*.php` |
| TASK-28 | `includes/table_tracks.php`, `includes/sort_query.php`, `includes/filter_view.php`, `includes/index_view.php` nebo `index_legacy_view.php` |
| TASK-29 | `js/lightbox.js`, `includes/layout_header.php`, `js/filter-ui.js`, `includes/filter_view.php` |
| TASK-30 | `includes/detail_view.php`, `includes/filter_view.php`, `includes/nearby_view.php`, `includes/compare_view.php`, `heatmap.php`, `map_search.php`, `js/detail-elevation.js` |

## D.5 Doporučený commit / PR workflow pro implementátora

1. **Pro každou TASK vytvoř samostatnou branch:**
   ```
   git checkout -b task-01-xxe-uploads-hardening
   ```

2. **Po dokončení TASK:**
   ```
   git add -A
   git commit -m "TASK-01: XXE protection + uploads/.htaccess + error log relocation

   - Add safe_load_gpx() helper with LIBXML_NONET and DOCTYPE pre-check
   - Apply to gpx_parser.php, export_*.php, heatmap.php, nearby_data.php, generate_thumb.php
   - Block PHP execution in uploads/ via .htaccess
   - Move error log from uploads/ to logs/ (denied via .htaccess)
   - Covers: SEC-001, SEC-002, SEC-019, SEC-020"
   git push origin task-01-xxe-uploads-hardening
   ```

3. **Otevři PR**, popis odkazuje na sekci TASK v AUDIT_REPORT.md, CI projde, mergni do `develop`.

4. **Po každé fázi**: mergni `develop` do `main` a otestuj v stage prostředí.

## D.6 Sign-off kritéria před nasazením do produkce

- [ ] Všech 19 Critical nálezů opraveno (SEC × 3, A11Y × 9, DB × 2, PERF × 1, OPS × 1, BE × 2, QR × 1)
- [ ] Všech 51 High nálezů opraveno nebo riziko explicitně akceptováno
- [ ] `php scripts/lint_lang.php` projde
- [ ] CI workflow zelený
- [ ] Manuální smoke test: login, import GPX, edit, delete, photo upload, filter, compare, export CSV/KML/GeoJSON
- [ ] Screen reader test: NVDA+Firefox NEBO VoiceOver+Safari na top 5 stránek (index, detail, filter, photos, settings)
- [ ] Penetration test (alespoň manuální): zkus visitor escalation, XSS payloads, CSRF na admin endpointy
- [ ] Backup strategie zavedena (DB + uploads/)
- [ ] HSTS header naživo na HTTPS produkci
- [ ] Setup.php smazán / blokován po instalaci

---

# Závěr

Tento audit identifikoval **166 konkrétních nálezů** napříč 8 dimenzemi. Aplikace je **funkční a feature-rich**, ale obsahuje **19 kritických problémů** (zejména XXE, missing auth na photo endpoints, runtime auto-migrations a 9 a11y blockerů), které musí být vyřešeny před produkcí pro veřejné použití.

Plán je rozdělen do **30 samostatných úkolů** uspořádaných do 7 fází podle priority. Každý úkol má hotový prompt vhodný pro **Claude Code Pro** (single session, vejde se do 5h okna, nepotřebuje Max tier).

**Doporučení implementačního pořadí:**
1. FÁZE 0 (security P0) — **musí být první**, jinak je aplikace nebezpečná.
2. FÁZE 1 → 2 → 3 paralelně možno (různé části systému).
3. FÁZE 4 (refactoring) jako fundament pro FÁZE 5 + 6.
4. FÁZE 5 + 6 paralelně možné.

Po dokončení **TASK-01 až TASK-08** (FÁZE 0+1) je aplikace bezpečná pro produkci. Zbytek je quality-of-life a maintainability — důležitý, ale ne blokující.

**Předpokládaný objem práce pro Claude Code Pro:**
- Quick win sessions (1–1.5 h): 8 tasků
- Střední (1.5–2.5 h): 16 tasků
- Velké (2.5–3 h): 6 tasků
- Celkem ~45–60 hodin práce / ~30+ Pro sessions.

Pro autora — pokud něco nesedí (priorita, scope, technické rozhodnutí), můžeš se zeptat nebo upravit konkrétní TASK před zadáním. Tento dokument je živý — průběžně do něj zapisuj progress (např. checkboxy ✓ u dokončených tasků).

