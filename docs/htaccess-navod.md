# 🔧 GPX Manager — Návod ke konfiguraci `.htaccess`

> Verze 2026-05 &nbsp;|&nbsp; [English version](htaccess-guide.md)

Tento dokument vysvětluje, co jednotlivé `.htaccess` soubory v projektu
dělají, a jak je upravit pro různá prostředí — localhost (WAMP, XAMPP)
i sdílený webhosting, včetně instalace přes **Alias** nebo **VirtualHost**
a instalace v **podadresáři**.

---

## Obsah

1. [Úvod — k čemu jsou `.htaccess` soubory](#1-úvod--k-čemu-jsou-htaccess-soubory)
2. [Předpoklady — Apache moduly a `AllowOverride`](#2-předpoklady--apache-moduly-a-allowoverride)
3. [Rozbor kořenového `.htaccess` blok po bloku](#3-rozbor-kořenového-htaccess-blok-po-bloku)
4. [`uploads/.htaccess` a `logs/.htaccess`](#4-uploadshtaccess-a-logshtaccess)
5. [Instalace na localhost — WAMP](#5-instalace-na-localhost--wamp)
6. [Instalace na localhost — XAMPP](#6-instalace-na-localhost--xampp)
7. [Instalace na webhosting](#7-instalace-na-webhosting)
8. [Hotové varianty `.htaccess`](#8-hotové-varianty-htaccess)
9. [Řešení problémů](#9-řešení-problémů)

---

## 1. Úvod — k čemu jsou `.htaccess` soubory

`.htaccess` je konfigurační soubor webového serveru **Apache**. Umožňuje
měnit chování serveru pro jednotlivý adresář a jeho podadresáře, aniž by
bylo nutné zasahovat do hlavní konfigurace serveru. Soubor se aplikuje
automaticky — stačí, aby ležel ve správné složce.

> **Pozor:** `.htaccess` funguje **pouze na Apache**. Pokud používáš
> **Nginx**, tyto soubory se ignorují a ekvivalentní pravidla je nutné
> přepsat do konfigurace Nginx (mimo rozsah tohoto návodu).

GPX Manager obsahuje **tři** `.htaccess` soubory:

| Soubor | Účel |
|---|---|
| `.htaccess` (kořen projektu) | Bezpečnost, HTTPS, komprese, cache, upload limity |
| `uploads/.htaccess` | Ochrana složky s nahranými soubory (GPX, fotky) |
| `logs/.htaccess` | Úplné zablokování přístupu k logům |

Aplikace **nepoužívá** „pretty URL" ani routing — všechny odkazy míří
přímo na konkrétní PHP soubory (`index.php`, `detail.php?id=123`).
Proto v `.htaccess` nejsou žádná přepisovací pravidla pro směrování;
jediná `RewriteRule` slouží k přesměrování na HTTPS.

Aplikace také používá výhradně **relativní cesty** k assetům
(`href="assets/css/app.css"`), takže běží jak v kořeni domény, tak
v libovolném podadresáři bez úprav.

---

## 2. Předpoklady — Apache moduly a `AllowOverride`

### 2.1 `AllowOverride` musí být `All`

Aby Apache `.htaccess` vůbec načetl, musí mít pro daný adresář
nastaveno `AllowOverride All` (nebo aspoň `AllowOverride FileInfo Options
Limit Indexes`). Při výchozím `AllowOverride None` se `.htaccess`
**tiše ignoruje** — server pak nevynutí HTTPS, neskryje citlivé soubory
ani neaplikuje cache.

Kde se to nastavuje:

- **WAMP / XAMPP:** v `httpd.conf` v bloku `<Directory>` pro `www`/`htdocs`
  je obvykle `AllowOverride All` už ve výchozím stavu — nic neměníš.
- **Alias / VirtualHost:** v bloku `<Directory>` příslušejícím k cestě
  aplikace musí být `AllowOverride All` (viz kapitoly 5 a 6).
- **Webhosting:** sdílené hostingy mají `AllowOverride All` zapnuto téměř
  vždy — `.htaccess` je u nich hlavní způsob konfigurace.

### 2.2 Potřebné Apache moduly

`.htaccess` v projektu využívá tyto moduly:

| Modul | K čemu | Co se stane bez něj |
|---|---|---|
| `mod_rewrite` | HTTPS redirect | Přesměrování na HTTPS neproběhne |
| `mod_headers` | Bezpečnostní hlavičky, cache, ochrana uploads | Hlavičky se neodešlou |
| `mod_deflate` | Komprese (gzip) přenosu | Stránky se přenášejí nekomprimované |
| `mod_expires` | Doba platnosti cache statických assetů | Cache se neřídí přes `Expires` |

Všechny bloky, které tyto moduly používají, jsou v `.htaccess` obalené
do `<IfModule ...>`. To znamená, že **chybějící modul nezpůsobí chybu 500**
— příslušná funkce se prostě neaktivuje. Přesto je vhodné mít všechny
čtyři moduly zapnuté.

**Jak ověřit/zapnout moduly:**

- **WAMP:** levým tlačítkem na ikonu WAMPu v hlavním panelu →
  *Apache → Apache modules* → zaškrtni `rewrite_module`, `headers_module`,
  `deflate_module`, `expires_module`. Apache se sám restartuje.
- **XAMPP:** otevři `apache/conf/httpd.conf`, najdi řádky
  `LoadModule rewrite_module ...` atd. a odstraň před nimi `#`.
  Pak restartuj Apache v XAMPP Control Panelu.
- **Webhosting:** moduly bývají zapnuté; pokud ne, požádej podporu
  hostingu nebo je zapni v panelu (pokud to umožňuje).

---

## 3. Rozbor kořenového `.htaccess` blok po bloku

Následuje vysvětlení každé sekce souboru `.htaccess` v kořeni projektu.
U každého bloku je uvedeno, **kdy ho měnit**.

### 3.1 Upload limity

```apache
<IfModule mod_php.c>
    php_value upload_max_filesize 200M
    php_value post_max_size 210M
    php_value max_execution_time 300
    php_value memory_limit 256M
</IfModule>
```

Zvyšuje PHP limity, aby šly nahrát velké GPX soubory a ZIP archivy fotek.
Direktiva `php_value` funguje **pouze**, když Apache spouští PHP jako
modul (`mod_php`) — proto je blok obalen `<IfModule mod_php.c>`.

- **WAMP/XAMPP:** typicky běží mod_php → limity se uplatní.
- **Webhosting:** často běží PHP přes **FastCGI / PHP-FPM** (např. PHP
  jako CGI handler). Tam `php_value` neplatí; blok se díky `<IfModule>`
  jen přeskočí (bez chyby). Limity je pak nutné nastavit jinak — viz
  [kapitola 7.3](#73-php-limity-na-fastcgi--php-fpm).

**Kdy měnit:** hodnoty můžeš snížit, pokud je tvůj hosting omezuje, nebo
zvýšit, pokud nahráváš opravdu velké archivy.

### 3.2 Charset

```apache
AddDefaultCharset UTF-8
AddCharset UTF-8 .txt .css .js .html
```

Zajišťuje, že server hlásí kódování UTF-8. Aplikace pracuje s češtinou
a dalšími jazyky — bez toho by se mohla zobrazit „rozsypaná" diakritika.
**Neměň.**

### 3.3 Zákaz výpisu adresářů

```apache
Options -Indexes
```

Zakáže Apache vypisovat obsah složky, když v ní není `index.*` soubor.
Brání tomu, aby návštěvník procházel strukturu projektu. **Neměň.**

### 3.4 HTTPS redirect ⚠️ — jediný blok, který se na localhostu mění

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteCond %{HTTP:X-Forwarded-Proto} !=https
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</IfModule>
```

Pokud návštěvník přijde přes nešifrované `http://`, server ho trvale
(kód 301) přesměruje na `https://`. Druhá podmínka
(`X-Forwarded-Proto`) zajistí, že redirect nevznikne ve smyčce, když
hosting ukončuje HTTPS na load-balanceru.

> ⚠️ **Na localhostu tento blok VYPNI.** WAMP i XAMPP běží ve výchozím
> stavu na `http://localhost` **bez SSL certifikátu**. Vynucení HTTPS
> způsobí, že web bude nedostupný (`localhost se nepřipojil`) nebo
> spadne do redirect smyčky.

**Jak vypnout:** zakomentuj všech šest řádků (před každý dej `#`), nebo
rovnou použij připravenou variantu `.htaccess.localhost.example`
(viz [kapitola 8](#8-hotové-varianty-htaccess)).

Na webhostingu s HTTPS naopak blok **nech zapnutý**.

### 3.5 Blokace dotfiles

```apache
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
```

Zakáže přístup ke všem souborům začínajícím tečkou — `.env` (DB hesla!),
`.git`, `.htaccess`, `.gitignore` apod. Klíčová bezpečnostní pojistka.
**Neměň.**

> Syntaxe `Require all denied` je z **Apache 2.4**. Na velmi starém
> Apache 2.2 se používalo `Order allow,deny` / `Deny from all` — viz
> [kapitola 7.4](#74-velmi-starý-apache-22).

### 3.6 Blokace PHP konfiguračních souborů

```apache
<FilesMatch "^(config\.php|phpinfo\.php|info\.php|test\.php)$">
    Require all denied
</FilesMatch>
```

`config.php` obsahuje načítání DB údajů — přímý přístup k němu je zakázán
(samotné PHP ho stále může `require`ovat, blokuje se jen HTTP přístup).
`phpinfo.php`, `info.php`, `test.php` jsou typické pomocné soubory, které
by mohly prozradit citlivé informace o serveru. **Neměň.**

### 3.7 Blokace instalačních souborů

```apache
<FilesMatch "^(install\.php|migrate\.php)$">
    Require all denied
</FilesMatch>
```

`install.php` a `migrate.php` se spouštějí výhradně z příkazové řádky
(CLI), proto je HTTP přístup zakázán.

> **Důležité:** `setup.php` (průvodce instalací) zde **záměrně NENÍ** —
> kdyby byl blokován, nešlo by aplikaci nainstalovat přes prohlížeč.
> `setup.php` je chráněn vlastní logikou: vyžaduje marker `.setup-allowed`
> a po úspěšné instalaci se sám smaže.

**Neměň** — jinak znemožníš instalaci nebo naopak vystavíš migrace.

### 3.8 Blokace SQL, logů, záloh, dokumentace, skriptů

```apache
<FilesMatch "\.(sql|log|bak|md|sh|lock)$">
    Require all denied
</FilesMatch>
```

Skryje databázové dumpy (`.sql`), logy (`.log`), zálohy (`.bak`),
Markdown dokumentaci (`.md`), shell skripty (`.sh`) a lock soubory.
**Neměň.**

### 3.9 Blokace package a build konfigurací

```apache
<FilesMatch "^(composer\.(json|lock)|package(-lock)?\.json|tailwind\.config\.js)$">
    Require all denied
</FilesMatch>
```

Skryje konfigurace nástrojů (`composer.json`, `package.json`,
`tailwind.config.js`), aby neprozrazovaly verze závislostí. **Neměň.**

### 3.10 Bezpečnostní hlavičky

```apache
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
```

`X-Content-Type-Options: nosniff` zabrání prohlížeči „hádat" typ
souboru. `Referrer-Policy` omezí odesílání URL v hlavičce `Referer`.

> Ostatní hlavičky (`X-Frame-Options`, `Content-Security-Policy`, `HSTS`,
> `Permissions-Policy`, `COOP`) nastavuje **PHP soubor `security.php`**
> jako jediný zdroj pravdy. Zde jsou jen statické výchozí hodnoty pro
> ne-PHP požadavky. **Neměň.**

### 3.11 Komprese (`mod_deflate`)

```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css
    AddOutputFilterByType DEFLATE application/javascript application/json
    AddOutputFilterByType DEFLATE image/svg+xml application/xml
</IfModule>
```

Zapne gzip kompresi textového obsahu — menší přenos, rychlejší načítání.
**Neměň.**

### 3.12 Cache statických assetů

```apache
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 30 days"
    ... (obrázky 30 dní, fonty 1 rok)
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
```

Statické soubory (CSS, JS, obrázky, fonty) si prohlížeč uloží do cache
na 30 dní (fonty na rok). PHP a HTML se naopak nikdy necachují — vždy
čerstvý obsah.

> **Tip pro vývoj na localhostu:** 30denní cache může při úpravách CSS/JS
> obtěžovat — prohlížeč drží starou verzi. Buď cachuj kratší dobu, nebo
> při vývoji používej tvrdé obnovení (Ctrl+F5) či vypnutou cache
> v nástrojích pro vývojáře. Není nutné soubor měnit.

---

## 4. `uploads/.htaccess` a `logs/.htaccess`

Tyto dva soubory **se needitují v žádném prostředí** — fungují stejně
na localhostu i na webhostingu.

### 4.1 `uploads/.htaccess`

```apache
<FilesMatch "\.(php[3-9]?|phtml|phps|pht|phar)$">
    Require all denied
</FilesMatch>
<FilesMatch "\.(log|sql|env|ini|sh|bak)$">
    Require all denied
</FilesMatch>
Options -Indexes
<IfModule mod_headers.c>
    <FilesMatch "\.(html?|svg|xml)$">
        Header set Content-Disposition "attachment"
    </FilesMatch>
</IfModule>
```

Složka `uploads/` obsahuje soubory nahrané uživateli (GPX, fotky). To je
bezpečnostně citlivé místo — kdyby tam někdo nahrál PHP skript a server
ho spustil, znamenalo by to převzetí webu. Proto:

- **blokace spuštění PHP** — i kdyby se do `uploads/` dostal `.php`
  soubor, server ho nespustí;
- **blokace citlivých přípon** (`.env`, `.sql`, `.ini` …);
- `Options -Indexes` — nelze procházet obsah složky;
- `Content-Disposition: attachment` u HTML/SVG/XML — prohlížeč je
  **stáhne místo zobrazení**, což brání XSS útoku přes nahraný obsah.

### 4.2 `logs/.htaccess`

```apache
Require all denied
```

Jediný řádek — kompletní zákaz HTTP přístupu k celé složce s logy.
Aplikace do logů zapisuje na straně serveru, navenek nemají být vidět.

---

## 5. Instalace na localhost — WAMP

Společné pro všechny tři scénáře níže:

1. Zkopíruj projekt do složky pod `C:\wamp64\www\` (např.
   `C:\wamp64\www\gpx_manager\`).
2. **Použij localhost variantu `.htaccess`** — přejmenuj
   `.htaccess.localhost.example` na `.htaccess` (má vypnutý HTTPS
   redirect). Pokud necháš produkční `.htaccess`, web nebude fungovat.
3. Ověř, že běží Apache moduly z [kapitoly 2.2](#22-potřebné-apache-moduly).

### 5a. Přímo ve složce `www/`

Nejjednodušší způsob. Projekt leží přímo v `www`:

```
C:\wamp64\www\gpx_manager\
```

Aplikace pak běží na:

```
http://localhost/gpx_manager/
```

Žádná úprava konfigurace Apache není potřeba — `www` má `AllowOverride All`
ve výchozím stavu. Stačí localhost varianta `.htaccess`.

### 5b. Přes Alias

Alias umožní mít projekt mimo `www` a přesto ho zpřístupnit přes
krátkou URL. WAMP má pro aliasy samostatné soubory v
`C:\wamp64\alias\`. Vytvoř například soubor `C:\wamp64\alias\gpx.conf`:

```apache
Alias /gpx "C:/wamp64/www/_web_/puschpull_web/gpx_manager_public-main/"

<Directory "C:/wamp64/www/_web_/puschpull_web/gpx_manager_public-main/">
    Options +Indexes +FollowSymLinks +MultiViews
    AllowOverride All
    Require local
</Directory>
```

> Klíčový je řádek **`AllowOverride All`** — bez něj se `.htaccess`
> projektu ignoruje. `Require local` omezí přístup jen na tvůj počítač.

Restartuj Apache (ikona WAMP → *Restart Service*). Aplikace běží na:

```
http://localhost/gpx/
```

I zde použij localhost variantu `.htaccess` (vypnutý HTTPS redirect).
Relativní cesty aplikace fungují v aliasu bez úprav.

### 5c. Přes VirtualHost

VirtualHost dá projektu vlastní „doménu", např. `gpx.local`.

**Krok 1 — VirtualHost.** WAMP má pro virtuální hosty soubor
`C:\wamp64\bin\apache\apache<verze>\conf\extra\httpd-vhosts.conf`.
Přidej:

```apache
<VirtualHost *:80>
    ServerName gpx.local
    DocumentRoot "C:/wamp64/www/_web_/puschpull_web/gpx_manager_public-main"
    <Directory "C:/wamp64/www/_web_/puschpull_web/gpx_manager_public-main">
        Options +Indexes +FollowSymLinks +MultiViews
        AllowOverride All
        Require local
    </Directory>
</VirtualHost>
```

> WAMP má průvodce: na stránce `http://localhost` → odkaz
> *„Add a Virtual Host"* — udělá totéž přes formulář.

**Krok 2 — soubor hosts.** Aby `gpx.local` v prohlížeči fungovalo,
přidej do `C:\Windows\System32\drivers\etc\hosts` (editor spusť jako
správce) řádek:

```
127.0.0.1 gpx.local
```

**Krok 3 — restart Apache** (ikona WAMP → *Restart Service*).

Aplikace pak běží na:

```
http://gpx.local/
```

I zde použij localhost variantu `.htaccess`. Protože projekt je
v kořeni virtuálního hostu, fungovala by i produkční varianta — ale
**jen pokud z ní vypneš HTTPS redirect** (lokálně nemáš certifikát).

---

## 6. Instalace na localhost — XAMPP

XAMPP funguje stejně jako WAMP, jen s jinými cestami:

- kořen webu je `C:\xampp\htdocs\` (místo `C:\wamp64\www\`),
- konfigurace Apache je `C:\xampp\apache\conf\httpd.conf`,
- virtuální hosty `C:\xampp\apache\conf\extra\httpd-vhosts.conf`.

### 6a. Přímo v `htdocs/`

Zkopíruj projekt do `C:\xampp\htdocs\gpx_manager\`, přejmenuj
`.htaccess.localhost.example` na `.htaccess`. Aplikace běží na:

```
http://localhost/gpx_manager/
```

### 6b. Alias

XAMPP nemá oddělené soubory pro aliasy — přidej blok přímo do
`httpd.conf` (nebo do `apache/conf/extra/httpd-xampp.conf`):

```apache
Alias /gpx "C:/cesta/k/gpx_manager_public-main/"

<Directory "C:/cesta/k/gpx_manager_public-main/">
    Options +Indexes +FollowSymLinks +MultiViews
    AllowOverride All
    Require local
</Directory>
```

Restartuj Apache v XAMPP Control Panelu. Aplikace běží na
`http://localhost/gpx/`.

### 6c. VirtualHost

Do `httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName gpx.local
    DocumentRoot "C:/cesta/k/gpx_manager_public-main"
    <Directory "C:/cesta/k/gpx_manager_public-main">
        Options +Indexes +FollowSymLinks +MultiViews
        AllowOverride All
        Require local
    </Directory>
</VirtualHost>
```

Ve výchozím nastavení XAMPP je potřeba ověřit, že v `httpd.conf` není
zakomentovaný řádek `Include conf/extra/httpd-vhosts.conf` (musí být bez
`#`). Pak doplň `127.0.0.1 gpx.local` do souboru `hosts` (viz 5c) a
restartuj Apache. Aplikace běží na `http://gpx.local/`.

Ve všech třech případech používej localhost variantu `.htaccess`.

---

## 7. Instalace na webhosting

Na sdíleném webhostingu většinou neznáš dopředu, kam přesně se aplikace
nainstaluje. Dobrá zpráva: GPX Manager je na to připraven — používá
relativní cesty a `.htaccess` je psaný defenzivně (`<IfModule>` obaly).

### 7.1 Instalace do kořene domény

Pokud aplikace běží přímo na `https://example.com/`, nahraj obsah
projektu do kořenové webové složky (bývá `public_html/`, `www/` nebo
`htdocs/` — podle hostingu) a **ponech produkční `.htaccess`** (ten,
který je v projektu standardně, se zapnutým HTTPS redirectem).

### 7.2 Instalace do podadresáře

Pokud aplikace běží v podadresáři, např.:

```
https://example.com/gpx/
https://example.com/aplikace/gpx-manager/
```

nahraj projekt do odpovídající podsložky. Aplikace používá výhradně
**relativní cesty** k CSS/JS/obrázkům (`href="assets/css/app.css"`),
takže funguje v podadresáři **bez jakékoli úpravy** — direktivu
`RewriteBase` není potřeba nastavovat.

Použij variantu `.htaccess.subdir.example` (je obsahově shodná
s produkční, jen má komentáře k podadresáři). HTTPS redirect funguje
i v podadresáři správně, protože `%{REQUEST_URI}` obsahuje celou cestu.

### 7.3 PHP limity na FastCGI / PHP-FPM

Většina moderních hostingů spouští PHP jako **FastCGI / PHP-FPM**, ne
jako Apache modul. V takovém případě direktivy `php_value` z bloku
upload limitů (kapitola 3.1) **neplatí**. Díky obalu `<IfModule mod_php.c>`
to ale **nezpůsobí chybu 500** — blok se jen přeskočí.

Pokud potřebuješ zvednout upload limity a `php_value` neúčinkuje,
nastav je jinde:

- **soubor `.user.ini`** v kořeni projektu (PHP-FPM ho čte):
  ```ini
  upload_max_filesize = 200M
  post_max_size = 210M
  max_execution_time = 300
  memory_limit = 256M
  ```
- nebo přes **administrační panel hostingu** (cPanel, Plesk, DirectAdmin
  mívají sekci „PHP nastavení" / „PHP Selector").

### 7.4 Velmi starý Apache 2.2

`.htaccess` používá direktivu `Require all denied` z **Apache 2.4**.
Na dnes už vzácném Apache 2.2 vrací tato direktiva chybu **500**.
Pokud na takovém serveru skončíš, nahraď ve všech `.htaccess` souborech:

```apache
Require all denied
```

za:

```apache
Order allow,deny
Deny from all
```

Apache 2.4 vyšel v roce 2012, takže to v praxi téměř nepotřebuješ —
naprostá většina hostingů běží na 2.4+.

### 7.5 Shrnutí — webhosting

| Situace | `.htaccess` varianta | HTTPS redirect |
|---|---|---|
| Kořen domény, HTTPS | produkční (výchozí v projektu) | zapnutý |
| Podadresář, HTTPS | `.htaccess.subdir.example` | zapnutý |
| Hosting jen na HTTP | libovolná, redirect zakomentuj | vypnutý |

---

## 8. Hotové varianty `.htaccess`

Projekt obsahuje tři varianty kořenového `.htaccess`:

| Soubor | Pro koho | HTTPS redirect |
|---|---|---|
| `.htaccess` | Produkce, kořen domény | zapnutý |
| `.htaccess.localhost.example` | WAMP, XAMPP (localhost) | **vypnutý** |
| `.htaccess.subdir.example` | Webhosting v podadresáři | zapnutý |

**Použití ukázkové varianty:** vyber odpovídající soubor a přejmenuj ho
na `.htaccess` (původní si případně zazálohuj, např. na
`.htaccess.production`).

Ve Windows Průzkumníku soubor začínající tečkou přejmenuješ bez problémů.
Z příkazové řádky:

```powershell
# PowerShell — localhost varianta
Rename-Item .htaccess .htaccess.production
Rename-Item .htaccess.localhost.example .htaccess
```

Soubory `uploads/.htaccess` a `logs/.htaccess` se nikdy nemění a žádné
varianty nemají.

---

## 9. Řešení problémů

| Příznak | Příčina | Řešení |
|---|---|---|
| `Tato stránka nefunguje` / `localhost se nepřipojil` na localhostu | Zapnutý HTTPS redirect bez SSL certifikátu | Použij `.htaccess.localhost.example` nebo zakomentuj blok HTTPS redirectu (kap. 3.4) |
| Příliš mnoho přesměrování / redirect smyčka | HTTPS redirect na serveru bez funkčního SSL, nebo HTTPS ukončené na proxy | Localhost: vypni redirect. Hosting: zkontroluj SSL certifikát; podmínka `X-Forwarded-Proto` smyčku z proxy řeší |
| Chyba **500 Internal Server Error** | Direktiva nepodporovaná serverem (`Require` na Apache 2.2, `php_value` mimo `<IfModule>`) | Apache 2.2 → nahraď `Require all denied` (kap. 7.4). Jinak zkontroluj `error.log` serveru |
| `.htaccess` se vůbec neuplatní (web jede, ale bez HTTPS/ochrany) | `AllowOverride None` pro daný adresář | Nastav `AllowOverride All` v `<Directory>` bloku (kap. 2.1) a restartuj Apache |
| **403 Forbidden** na celý web | Příliš agresivní pravidlo nebo `Require local` na hostingu | Zkontroluj, že žádný `<FilesMatch>` neblokuje `index.php`; na hostingu nepoužívej `Require local` |
| HTTPS redirect nefunguje | Vypnutý `mod_rewrite` | Zapni `rewrite_module` (kap. 2.2) |
| Bezpečnostní hlavičky chybí | Vypnutý `mod_headers` | Zapni `headers_module` (kap. 2.2) |
| Upload velkého souboru selže | `php_value` neúčinkuje (FastCGI/PHP-FPM) | Nastav limity přes `.user.ini` nebo panel hostingu (kap. 7.3) |
| Po úpravě CSS/JS se projeví stará verze | 30denní cache statických assetů | Tvrdé obnovení Ctrl+F5, nebo vypni cache v nástrojích pro vývojáře |
| Assety (CSS/JS) se nenačítají v podadresáři | Špatně zadané absolutní cesty | Aplikace používá relativní cesty — zkontroluj, že jsi neměnil `href`/`src`; `RewriteBase` se nenastavuje |
| Při instalaci `setup.php` hlásí zákaz přístupu | `setup.php` byl omylem přidán do `<FilesMatch>` blokace | `setup.php` nesmí být blokován v `.htaccess` (kap. 3.7) |

> **Kde hledat příčinu chyby 500:** podívej se do souboru `error.log`
> Apache. WAMP: ikona → *Apache → Apache error log*. XAMPP: tlačítko
> *Logs* u Apache v Control Panelu. Webhosting: sekce „Logy" v panelu.

---

> Tento návod se týká pouze konfigurace `.htaccess`. Kompletní postup
> instalace aplikace (databáze, `.env`, průvodce `setup.php`) najdeš
> v [instructions/cs.md](../instructions/cs.md).
