# Migrace databáze

Změny schématu databáze GPX Manageru. Spouští je `php migrate.php` (jen z příkazové řádky
nebo z localhostu); použité se zapisují do tabulky `schema_migrations`.

## Pravidla

- **Jméno:** `NNNN_popis.sql`, čtyřmístné číslo o jedno vyšší než poslední. Spouští se v pořadí
  podle jména a **jen soubory `.sql`** — cokoli jiného (tento README, `0014_centroid_backfill.php`)
  `migrate.php` ignoruje.
- **Idempotentní:** druhé spuštění nesmí nic rozbít. Existenci sloupce / indexu / tabulky
  ověřit přes `information_schema` a ALTER poskládat podmíněně — vzor je `0019_track_place_name.sql`.
- **Jen přidávat** (sloupce, tabulky, indexy). Nasazovací skript při selhání migrace vrátí kód
  na předchozí verzi a ta musí s rozšířenou databází dál fungovat. Mazání či přejmenování
  sloupců se dělá ručně, ve dvou krocích (nejdřív kód, který starý sloupec nepotřebuje, pak migrace).
- **Kompatibilita:** MySQL 5.7+ / MariaDB 10.3+, `SET NAMES utf8mb4;` na začátku.
- **Po každé migraci srovnat `install.sql`** (výchozí schéma pro čistou instalaci).
- `ALTER TABLE` nikdy do `includes/db.php` — jen sem.

## Nasazení na produkci (gpx.puschpull.org)

Commit, který mění tuto složku (nebo `composer.json` / `composer.lock`), nasazovací cron na
serveru **nenasadí sám** — podrží ho a zapíše `ČEKÁ NA RUČNÍ NASAZENÍ` (vidět v Administraci
na kartě „Nasazení"). Nasadí se ikonou **„Nasadit GPX"** na PC majitele: záloha databáze →
nový kód → composer → migrace. Když migrace selže, kód se sám vrátí na předchozí verzi.
