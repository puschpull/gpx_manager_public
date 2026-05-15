# 🗺 GPX Manager — Instalační a uživatelský návod

> Verze 2026-05 &nbsp;|&nbsp; [English version](en.md)

---

## Obsah

1. [Požadavky na server](#1-požadavky-na-server)
2. [Příprava databáze](#2-příprava-databáze)
3. [Nahrání souborů na server](#3-nahrání-souborů-na-server)
4. [Instalace průvodcem (setup.php)](#4-instalace-průvodcem-setupphp)
5. [Ruční instalace](#5-ruční-instalace)
6. [Oprávnění složky uploads/](#6-oprávnění-složky-uploads)
7. [Jak funguje přihlášení a přístup](#7-jak-funguje-přihlášení-a-přístup)
8. [První kroky po instalaci](#8-první-kroky-po-instalaci)
9. [API klíče pro mapy](#9-api-klíče-pro-mapy)
10. [Zálohování a správa](#10-zálohování-a-správa)
11. [Řešení problémů](#11-řešení-problémů)

---

## 1. Požadavky na server

### Minimální verze

| Komponenta | Minimální verze | Doporučeno |
|---|---|---|
| **PHP** | 8.0 | 8.2 nebo novější |
| **MySQL** | 5.7 | 8.0 nebo novější |
| **MariaDB** | 10.3 | 10.11 nebo novější |
| **Apache** | 2.4 | libovolná aktuální |
| **Nginx** | 1.18 | libovolná aktuální |

> **Proč PHP 8.0+?** Aplikace využívá `match()` výraz a arrow funkce (`fn()`), které jsou dostupné od PHP 8.0.

### Požadovaná PHP rozšíření

| Rozšíření | K čemu slouží | Povinné |
|---|---|---|
| `pdo_mysql` | Připojení k databázi | ✅ Ano |
| `simplexml` | Parsování GPX souborů | ✅ Ano |
| `gd` | Generování náhledů tras | ✅ Ano |
| `exif` | Čtení GPS dat z fotografií | ✅ Ano |
| `zip` | Import tras ze ZIP archivů | ✅ Ano |
| `json` | Interní formát dat | ✅ Ano (součást PHP) |
| `mbstring` | Správné zpracování UTF-8 | ⚠️ Doporučeno |

**Jak zkontrolovat rozšíření?** Vytvoř soubor `info.php` s obsahem `<?php phpinfo();`, nahraj na server, otevři v prohlížeči a hledej sekce s názvy rozšíření. Po kontrole soubor smaž.

### Lokální provoz (Windows)

- **WampServer** 3.3+ — stáhnout na [wampserver.com](https://www.wampserver.com/)
- **XAMPP** — stáhnout na [apachefriends.org](https://www.apachefriends.org/)

### Webhosting

Aplikace funguje na běžném sdíleném webhostingu s PHP 8.0+ a MySQL. Ověř si podporu u svého poskytovatele — většina moderních hostingů (Wedos, Forpsi, Stable.cz, Bluehost aj.) tyto požadavky splňuje.

---

## 2. Příprava databáze

Databáze musí existovat **před** spuštěním instalace.

### Přes phpMyAdmin (webhosting nebo WampServer)

1. Otevři phpMyAdmin
   - WampServer: klikni na ikonu v systémové liště → **phpMyAdmin**
   - Webhosting: přihlašovací odkaz najdeš v panelu správy hostingu
2. V levém panelu klikni na **Nová databáze** (nebo **New**)
3. Název databáze: `gpx_manager`
4. Kolace: `utf8mb4_unicode_ci`
5. Klikni **Vytvořit**

### Přes příkazový řádek (MySQL CLI)

```sql
CREATE DATABASE gpx_manager
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

> **Webhosting:** Název databáze a uživatele obvykle zadáváš v administraci hostingu (cPanel, ISPConfig apod.). Hosting ti vygeneruje přihlašovací údaje — poznamenej si je pro instalaci.

---

## 3. Nahrání souborů na server

### WampServer / XAMPP (lokálně)

Rozbal stažený ZIP do složky:
- WampServer: `C:\wamp64\www\gpx_manager\`
- XAMPP: `C:\xampp\htdocs\gpx_manager\`

### Webhosting (přes FTP)

1. Použij FTP klienta, např. [FileZilla](https://filezilla-project.org/) (zdarma)
2. Připoj se na hosting (FTP přihlašovací údaje najdeš v panelu hostingu)
3. Nahraj obsah složky do kořenového adresáře webu (`public_html/`, `www/` apod.)
   nebo do podsložky, např. `public_html/gpx/`

> **Tip:** Soubor `.env` **nikdy** nenahrávej — vznikne automaticky při instalaci. Na GitHubu není (chrání ho `.gitignore`).

---

## 4. Instalace průvodcem (setup.php)

Nejjednodušší způsob — doporučeno pro začátečníky.

### Před spuštěním setup.php — vytvoř soubor `.setup-allowed`

PŘED otevřením `setup.php` v prohlížeči vytvoř v kořenové složce projektu prázdný soubor s názvem `.setup-allowed`.

**WampServer / XAMPP (Windows):**
```
C:\wamp64\www\gpx_manager\.setup-allowed
```
Stačí vytvořit prázdný soubor — klikni pravým tlačítkem v průzkumníku → Nový → Textový dokument, pojmenuj ho `.setup-allowed` (bez přípony `.txt`).

**Webhosting (přes FTP / SSH):**
```bash
touch .setup-allowed
```

> **Proč?** Soubor `.setup-allowed` slouží jako ochranný marker. Bez něj `setup.php` odmítne spustit — chrání tě před nechtěným přeinstalováním existující aplikace. Po úspěšné instalaci ho průvodce sám smaže.

### Krok 1 — Databázové připojení

Otevři v prohlížeči:
- WampServer: `http://localhost/gpx_manager/setup.php`
- Webhosting: instalace průvodcem je dostupná **pouze z localhostu** (127.0.0.1 nebo ::1) — spusť ji přes SSH tunnel nebo lokálně

Vyplň:
- **Databázový server:** obvykle `localhost` (na webhostingu může být jiný — viz panel hostingu)
- **Název databáze:** `gpx_manager` (nebo název, který jsi vytvořil)
- **Uživatel:** `root` (WampServer výchozí) nebo uživatel z hostingu
- **Heslo:** prázdné (WampServer výchozí) nebo heslo z hostingu

Klikni **Otestovat připojení →**

### Krok 2 — Admin účet

- **Uživatelské jméno:** libovolné (např. `admin`)
- **Heslo:** minimálně 8 znaků — zvol silné heslo

### Krok 3 — Přístup a API klíče

- **Admin IP adresy:** průvodce předvyplní tvou aktuální IP adresu. Uprav podle potřeby (více IP adres odděluj čárkou).
  - Svou veřejnou IP zjistíš na [whatismyip.com](https://www.whatismyip.com/)
- **API klíče:** volitelné, viz sekci [9. API klíče pro mapy](#9-api-klíče-pro-mapy). Lze přidat i později.

Klikni **Dokončit instalaci** — průvodce vytvoří soubor `.env`, naimportuje databázové tabulky a sám sebe smaže.

---

## 5. Ruční instalace

Pokud průvodce nelze použít nebo preferuješ ruční nastavení.

### Krok 1 — Vytvoř soubor `.env`

Zkopíruj šablonu:
```
.env.example  →  .env
```

Otevři `.env` v textovém editoru a vyplň hodnoty:

```env
# Databáze
DB_HOST=localhost
DB_NAME=gpx_manager
DB_USER=root
DB_PASS=

# Admin účet
ADMIN_USER=admin
ADMIN_PASS_HASH=        # viz níže jak vygenerovat

# Admin IP adresy (čárkou oddělené, localhost je vždy povolen)
ADMIN_IPS=127.0.0.1,::1,123.456.789.0

# API klíče (volitelné, lze přidat kdykoli)
TF_API_KEY=
MAPYCOM_API_KEY=
MAPILLARY_TOKEN=
```

### Krok 2 — Vygeneruj bcrypt hash hesla

Otevři příkazový řádek a spusť:

```bash
php -r "echo password_hash('TvéHeslo123', PASSWORD_DEFAULT);"
```

Výsledek (začínající `$2y$...`) zkopíruj do `ADMIN_PASS_HASH=` v souboru `.env`.

### Krok 3 — Importuj databázové schéma

**Přes phpMyAdmin:**
1. Vyber svou databázi
2. Záložka **Import**
3. Vyber soubor `install.sql`
4. Klikni **Importovat**

**Přes příkazový řádek:**
```bash
mysql -u root -p gpx_manager < install.sql
```

### Krok 4 — Spusť migrace databáze

Po importu základního schématu spusť migration runner, který zajistí aktuálnost struktury databáze:

```bash
php migrate.php
```

Skript vypíše stav každé migrace (`OK`, `SKIP`) a na konci celkový počet aplikovaných migrací.
Druhé spuštění bezpečně nevykoná nic: `Done. 0 migration(s) applied.`

> **Webový přístup:** `migrate.php` lze spustit také z prohlížeče, ale **pouze z localhostu** (`127.0.0.1` nebo `::1`). Z jiné IP vrátí HTTP 403.

---

## 6. Oprávnění složky uploads/

Složka `uploads/` a její podsložky musí být **zapisovatelné** webovým serverem.

### WampServer / XAMPP (Windows)

Obvykle není potřeba nic měnit — práva jsou nastavena automaticky.

### Webhosting / Linux (VPS)

**Přes příkazový řádek (SSH):**
```bash
chmod 755 uploads/
chmod 755 uploads/thumbs/
chmod 755 uploads/photos/
chmod 755 uploads/photos/thumbs/
```

**Přes FTP (FileZilla):**
1. Pravý klik na složku `uploads/` → **Oprávnění souboru**
2. Zadej `755`, zaškrtni **Použít rekurzivně na podsložky**
3. Potvrď

---

## 7. Jak funguje přihlášení a přístup

Aplikace rozlišuje tři role:

| Role | Jak se přihlásí | Co může dělat |
|---|---|---|
| **Admin (IP)** | Přistoupí z povolené IP adresy | Vše — správa tras, import, mazání, nastavení |
| **Admin (heslo)** | Přihlásí se přes `login.php` | Vše — správa tras, import, mazání, nastavení |
| **Návštěvník** | Nepřihlašuje se | Jen prohlížení povolených stránek |

### Automatické přihlášení z IP adresy

Pokud přistupuješ z IP adresy uvedené v `ADMIN_IPS`, jsi automaticky přihlášen jako admin — bez zadávání hesla. Vhodné pro přístup z domácí sítě.

### Přihlášení heslem

Kdokoli s přihlašovacím jménem a heslem se může přihlásit jako admin z libovolné IP adresy — proto používej silné heslo a sdílej ho jen důvěryhodným osobám.

### Návštěvnický režim

Nepřihlášení uživatelé vidí jen stránky, které admin povolí v nastavení. Ve výchozím stavu jsou viditelné: statistiky, kalendář, heatmapa, hledání na mapě, trasy v okolí, filtr, porovnání.

### Náhled jako návštěvník

Admin může kliknout na tlačítko **👁 Náhled jako návštěvník** (v horním modrém banneru) a vidět aplikaci přesně tak, jak ji vidí nepřihlášený uživatel.

---

## 8. První kroky po instalaci

### Přihlášení

1. Otevři `http://localhost/gpx_manager/` (nebo URL tvého webu)
2. Klikni na **Přihlásit se** nebo jdi na `login.php`
3. Zadej přihlašovací jméno a heslo

Přistupuješ-li z povolené IP adresy, jsi přihlášen automaticky a v horní části stránky uvidíš modrý admin banner.

### Import první trasy

1. Klikni na **Import** v navigaci
2. Přetáhni GPX soubor (nebo klikni a vyber ze složky)
3. Klikni **Spustit import** — aplikace soubor analyzuje, uloží a vygeneruje náhled
4. Trasa se zobrazí v přehledu na úvodní stránce

> **Tip:** Lze importovat více souborů najednou nebo celý ZIP archív s GPX soubory.

### Nastavení jazyka a tématu

Aplikace nabízí:
- **8 jazyků:** čeština, angličtina, němčina, slovenština, španělština, francouzština, italština, polština
- **9 barevných témat:** classic, dark, darkblue, darkgreen, blue, green, minimal, lightgray, brown

Přepínač jazyka a tématu najdeš v pravém horním rohu každé stránky.

### Nastavení viditelnosti stránek pro návštěvníky

V menu **Nastavení** (dostupné jen pro admina) můžeš zapnout nebo vypnout jednotlivé stránky pro nepřihlášené návštěvníky:

- Statistiky
- Kalendář aktivit
- Heatmapa
- Hledání na mapě
- Trasy v okolí
- Filtr / čistič GPX
- Porovnání tras

### Úprava tras

Po kliknutí na trasu v přehledu se otevře detailní stránka s mapou, výškovým profilem a statistikami. Přes tlačítko **Upravit** (jen pro admina) lze nastavit:

- Název a poznámku
- Typ aktivity (pěšky, turistika, cyklistika, auto, běh…)
- Obtížnost (1–5)
- Kategorii
- Oblíbenou trasu (hvězdička)

---

## 9. API klíče pro mapy

Aplikace funguje **bez API klíčů** — výchozí mapa je OpenStreetMap (zdarma, bez omezení). API klíče přidávají pouze volitelné mapové podklady.

Klíče zadáš buď při instalaci (setup.php, krok 3) nebo kdykoli později v souboru `.env`.

---

### Thunderforest — turistické a cyklistické mapy

Poskytuje turistické, cyklo, terénní a dopravní mapové podklady.

**Jak získat klíč:**
1. Jdi na [thunderforest.com](https://www.thunderforest.com/docs/apikeys/)
2. Klikni **Get API key** → registrace (stačí e-mail)
3. Bezplatný plán: **150 000 dlaždic / měsíc** (pro osobní použití dostačuje)
4. Po registraci zkopíruj API klíč ze sekce **API Keys**
5. Vlož do `.env`: `TF_API_KEY=tvůj_klíč_zde`

---

### Mapy.cz — česká letecká a turistická mapa

Poskytuje letecké snímky ČR, turistickou mapu a základní mapu Mapy.cz.

**Jak získat klíč:**
1. Jdi na [developer.mapy.cz](https://developer.mapy.cz/)
2. Přihlas se nebo zaregistruj (stačí seznam.cz nebo Google účet)
3. V sekci **Moje projekty** klikni **Vytvořit nový projekt**
4. Pojmenuj projekt (např. `GPX Manager`) a potvrď
5. Zkopíruj vygenerovaný **API klíč**
6. Vlož do `.env`: `MAPYCOM_API_KEY=tvůj_klíč_zde`

> **Bezplatná kvóta:** dostatečná pro osobní provoz (desetitisíce zobrazení/měsíc).

---

### Mapillary — fotografie ulic a stezek přímo na mapě

Zobrazuje fotografie pořízené přímo na trasách, jako Street View.

**Jak získat token:**
1. Jdi na [mapillary.com](https://www.mapillary.com/) a zaregistruj se (zdarma)
2. Přejdi na [mapillary.com/dashboard/developers](https://www.mapillary.com/dashboard/developers)
3. Klikni **Register Application**
4. Vyplň název aplikace (např. `GPX Manager`) a potvrď
5. Zkopíruj **Client Token**
6. Vlož do `.env`: `MAPILLARY_TOKEN=tvůj_token_zde`

---

### Aplikování klíčů

Po úpravě souboru `.env` stačí stránku znovu načíst — klíče se aplikují ihned, restart serveru není potřeba.

---

## 10. Zálohování a správa

### Co zálohovat

| Co | Kde | Jak |
|---|---|---|
| **Databáze** | MySQL | Export přes phpMyAdmin (záložka Export → formát SQL) nebo `mysqldump` |
| **GPX soubory** | `uploads/*.gpx` | Zkopíruj celou složku `uploads/` |
| **Fotografie** | `uploads/photos/` | Zkopíruj celou složku |
| **Konfigurace** | `.env` | Zkopíruj soubor na bezpečné místo |

### Export databáze přes příkazový řádek

```bash
mysqldump -u root -p gpx_manager > zaloha_gpx_manager.sql
```

### Aktualizace aplikace

1. Zazálohuj databázi a složku `uploads/`
2. Nahraj nové soubory na server (přepíšou staré)
3. Soubor `.env` **nepřepisuj** — zůstane zachován
4. Spusť `php migrate.php` — aplikuje nové migrace automaticky

### Správa uložiště

Nahraný GPX soubory a náhledy tras se ukládají do `uploads/`. Na sdíleném hostingu sleduj volné místo — každá trasa zabírá desítky až stovky kB.

---

## 11. Řešení problémů

### „Database connection failed" / „Chyba připojení k databázi"

- Ověř přihlašovací údaje v `.env`
- Zkontroluj zda MySQL server běží (WampServer: zelená ikona v systémové liště)
- Na webhostingu: zkontroluj hostname databáze v panelu hostingu (může být jiný než `localhost`)
- Ověř zda databáze existuje a uživatel má k ní přístup

### Stránka se nezobrazuje / chyba 500

- Zkontroluj zda soubor `.env` existuje a obsahuje správné hodnoty
- Zkontroluj logy PHP chyb (WampServer: `C:\wamp64\logs\php_error.log`)
- Na produkci jsou chyby logovány do `uploads/_errors.log`

### Admin přístup nefunguje (šedý, ne modrý banner)

- Ověř svou aktuální IP adresu na [whatismyip.com](https://www.whatismyip.com/)
- Přidej ji do `ADMIN_IPS=` v `.env` (odděleno čárkou, bez mezer)
- Po úpravě `.env` se odhlás (smaž cookies nebo otevři anonymní okno) a načti znovu

### Fotografie se nezobrazují nebo se nenahrávají

- Zkontroluj zda je složka `uploads/photos/` zapisovatelná (chmod 755)
- Ověř zda je povoleno PHP rozšíření `exif` a `gd`

### Import GPX selže

- Ověř zda je soubor validní GPX (XML formát s příponou `.gpx`)
- Zkontroluj zda má soubor alespoň jeden segment `<trkseg>` s body `<trkpt>`
- Velké soubory (>50 MB): zvyš limity v `.htaccess` nebo `php.ini`

### Stránka .htaccess nefunguje / mod_rewrite chyba

- WampServer: klikni na ikonu → Apache → Moduly Apache → zaškrtni `rewrite`
- Webhosting: požádej podporu hostingu o povolení `mod_rewrite`

### „ZipArchive není dostupný na serveru"

- PHP rozšíření `zip` není povoleno
- WampServer: v `php.ini` odkomentuj řádek `extension=zip`
- Webhosting: požádej podporu o povolení `php-zip`

---

*GPX Manager — Instalační a uživatelský návod &nbsp;|&nbsp; Verze 2026-05*
