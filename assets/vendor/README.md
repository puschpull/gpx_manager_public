# assets/vendor — knihovny třetích stran (self-host)

Do 26. 11. 2026 se načítaly z CDN (unpkg, jsdelivr, cdnjs). Teď je web servuje sám,
takže výpadek ani kompromitace CDN stránky neovlivní a `script-src` může být jen `'self'`.

Soubory jsou **beze změny** — stažené z uvedených adres a ověřené proti SRI otiskům,
které šablony používaly u CDN (otisky v šablonách zůstaly, prohlížeč je ověřuje i tady).
Složky mají verzi v názvu, takže nová verze = nová složka a cache se řeší sama.

| Složka | Zdroj | Licence |
|---|---|---|
| `leaflet-1.9.4/` (+ `images/`) | unpkg.com/leaflet@1.9.4/dist/ | BSD-2-Clause |
| `leaflet.fullscreen-1.6.0/` | unpkg.com/leaflet.fullscreen@1.6.0/ | MIT (LICENSE) |
| `leaflet.vectorgrid-1.3.0/` | unpkg.com/leaflet.vectorgrid@1.3.0/dist/ | Beerware (přibalené části MIT) |
| `leaflet.heat-0.2.0/` | unpkg.com/leaflet.heat@0.2.0/dist/ | BSD-2-Clause |
| `leaflet-gpx-1.7.0/` | cdnjs …/leaflet-gpx/1.7.0/gpx.min.js, špendlíky z unpkg.com/leaflet-gpx@1.7.0/ | BSD-2-Clause (LICENSE) |
| `chart.js-4.4.1/` | cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/ | MIT |
| `lucide-0.469.0/` | cdn.jsdelivr.net/npm/lucide@0.469.0/dist/umd/ | ISC |
| `alpinejs-3.14.1/` | unpkg.com/@alpinejs/csp@3.14.1 a @alpinejs/focus@3.14.1 (`dist/cdn.min.js`, přejmenováno) | MIT |

## Aktualizace knihovny

1. Stáhnout novou verzi do nové složky `<název>-<verze>/` (i obrázky, na které odkazuje CSS).
2. Spočítat otisk: `openssl dgst -sha384 -binary soubor | openssl base64 -A` a porovnat
   s otiskem, který zveřejňuje CDN / jsdelivr (`https://www.jsdelivr.com/package/npm/<balík>`).
3. Přepsat cestu i `integrity` ve všech šablonách (`grep -rn "assets/vendor/<název>-"`).
4. Otestovat na localhostu všechny stránky s mapou / grafy, starou složku smazat.
