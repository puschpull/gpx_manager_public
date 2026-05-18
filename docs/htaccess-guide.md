# 🔧 GPX Manager — `.htaccess` Configuration Guide

> Version 2026-05 &nbsp;|&nbsp; [Česká verze](htaccess-navod.md)

This document explains what each `.htaccess` file in the project does and
how to adapt it for different environments — localhost (WAMP, XAMPP) and
shared web hosting, including installation via **Alias** or **VirtualHost**
and installation in a **subdirectory**.

---

## Contents

1. [Introduction — what the `.htaccess` files are for](#1-introduction--what-the-htaccess-files-are-for)
2. [Prerequisites — Apache modules and `AllowOverride`](#2-prerequisites--apache-modules-and-allowoverride)
3. [Root `.htaccess` analysis, block by block](#3-root-htaccess-analysis-block-by-block)
4. [`uploads/.htaccess` and `logs/.htaccess`](#4-uploadshtaccess-and-logshtaccess)
5. [Localhost installation — WAMP](#5-localhost-installation--wamp)
6. [Localhost installation — XAMPP](#6-localhost-installation--xampp)
7. [Web hosting installation](#7-web-hosting-installation)
8. [Ready-made `.htaccess` variants](#8-ready-made-htaccess-variants)
9. [Troubleshooting](#9-troubleshooting)

---

## 1. Introduction — what the `.htaccess` files are for

`.htaccess` is a configuration file for the **Apache** web server. It lets
you change server behaviour for a single directory and its subdirectories
without touching the server's main configuration. The file is applied
automatically — it just has to sit in the right folder.

> **Note:** `.htaccess` works **only on Apache**. If you use **Nginx**,
> these files are ignored and the equivalent rules must be rewritten into
> the Nginx configuration (outside the scope of this guide).

GPX Manager contains **three** `.htaccess` files:

| File | Purpose |
|---|---|
| `.htaccess` (project root) | Security, HTTPS, compression, cache, upload limits |
| `uploads/.htaccess` | Protects the folder with uploaded files (GPX, photos) |
| `logs/.htaccess` | Fully blocks access to logs |

The application does **not** use "pretty URLs" or routing — every link
points directly to a specific PHP file (`index.php`, `detail.php?id=123`).
That is why `.htaccess` contains no rewrite rules for routing; the only
`RewriteRule` is used to redirect to HTTPS.

The application also uses **relative paths** for all assets
(`href="assets/css/app.css"`), so it runs both at the domain root and in
any subdirectory without changes.

---

## 2. Prerequisites — Apache modules and `AllowOverride`

### 2.1 `AllowOverride` must be `All`

For Apache to read `.htaccess` at all, the directory must have
`AllowOverride All` set (or at least `AllowOverride FileInfo Options Limit
Indexes`). With the default `AllowOverride None` the `.htaccess` file is
**silently ignored** — the server then won't enforce HTTPS, won't hide
sensitive files and won't apply caching.

Where this is set:

- **WAMP / XAMPP:** the `<Directory>` block for `www`/`htdocs` in
  `httpd.conf` usually already has `AllowOverride All` by default — you
  change nothing.
- **Alias / VirtualHost:** the `<Directory>` block matching the
  application path must have `AllowOverride All` (see sections 5 and 6).
- **Web hosting:** shared hosts almost always have `AllowOverride All`
  enabled — `.htaccess` is their primary configuration mechanism.

### 2.2 Required Apache modules

The project's `.htaccess` uses these modules:

| Module | Used for | What happens without it |
|---|---|---|
| `mod_rewrite` | HTTPS redirect | The HTTPS redirect won't happen |
| `mod_headers` | Security headers, cache, uploads protection | Headers won't be sent |
| `mod_deflate` | Transfer compression (gzip) | Pages are sent uncompressed |
| `mod_expires` | Cache lifetime of static assets | Cache isn't driven by `Expires` |

Every block that uses these modules is wrapped in `<IfModule ...>` inside
`.htaccess`. That means a **missing module will not cause a 500 error** —
the feature simply won't activate. Still, it's best to have all four
modules enabled.

**How to check/enable modules:**

- **WAMP:** left-click the WAMP tray icon → *Apache → Apache modules* →
  tick `rewrite_module`, `headers_module`, `deflate_module`,
  `expires_module`. Apache restarts itself.
- **XAMPP:** open `apache/conf/httpd.conf`, find the lines
  `LoadModule rewrite_module ...` etc. and remove the leading `#`.
  Then restart Apache from the XAMPP Control Panel.
- **Web hosting:** modules are usually enabled; if not, ask hosting
  support or enable them in the panel (if it allows that).

---

## 3. Root `.htaccess` analysis, block by block

Below is an explanation of every section of the `.htaccess` file in the
project root. Each block states **when to change it**.

### 3.1 Upload limits

```apache
<IfModule mod_php.c>
    php_value upload_max_filesize 200M
    php_value post_max_size 210M
    php_value max_execution_time 300
    php_value memory_limit 256M
</IfModule>
```

Raises PHP limits so large GPX files and photo ZIP archives can be
uploaded. The `php_value` directive works **only** when Apache runs PHP
as a module (`mod_php`) — which is why the block is wrapped in
`<IfModule mod_php.c>`.

- **WAMP/XAMPP:** typically run mod_php → the limits apply.
- **Web hosting:** often runs PHP via **FastCGI / PHP-FPM**. There
  `php_value` has no effect; thanks to `<IfModule>` the block is just
  skipped (no error). The limits must then be set elsewhere — see
  [section 7.3](#73-php-limits-on-fastcgi--php-fpm).

**When to change:** lower the values if your host caps them, or raise
them if you upload truly large archives.

### 3.2 Charset

```apache
AddDefaultCharset UTF-8
AddCharset UTF-8 .txt .css .js .html
```

Ensures the server reports UTF-8 encoding. The application works with
Czech and other languages — without this, accented characters could
display as garbage. **Do not change.**

### 3.3 Directory listing disabled

```apache
Options -Indexes
```

Stops Apache from listing a folder's contents when it has no `index.*`
file. Prevents visitors from browsing the project structure.
**Do not change.**

### 3.4 HTTPS redirect ⚠️ — the only block changed for localhost

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteCond %{HTTP:X-Forwarded-Proto} !=https
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</IfModule>
```

If a visitor arrives over unencrypted `http://`, the server permanently
(code 301) redirects them to `https://`. The second condition
(`X-Forwarded-Proto`) prevents a redirect loop when the host terminates
HTTPS at a load balancer.

> ⚠️ **Disable this block on localhost.** Both WAMP and XAMPP run by
> default on `http://localhost` **without an SSL certificate**. Forcing
> HTTPS makes the site unreachable (`localhost refused to connect`) or
> drops it into a redirect loop.

**How to disable:** comment out all six lines (prefix each with `#`), or
simply use the prepared `.htaccess.localhost.example` variant
(see [section 8](#8-ready-made-htaccess-variants)).

On web hosting with HTTPS, **keep the block enabled**.

### 3.5 Dotfile blocking

```apache
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
```

Denies access to all files starting with a dot — `.env` (DB passwords!),
`.git`, `.htaccess`, `.gitignore` etc. A key safety net.
**Do not change.**

> The `Require all denied` syntax is from **Apache 2.4**. On very old
> Apache 2.2, `Order allow,deny` / `Deny from all` was used — see
> [section 7.4](#74-very-old-apache-22).

### 3.6 Blocking PHP configuration files

```apache
<FilesMatch "^(config\.php|phpinfo\.php|info\.php|test\.php)$">
    Require all denied
</FilesMatch>
```

`config.php` handles loading the DB credentials — direct access to it is
denied (PHP itself can still `require` it; only HTTP access is blocked).
`phpinfo.php`, `info.php`, `test.php` are typical helper files that could
leak sensitive server information. **Do not change.**

### 3.7 Blocking installation files

```apache
<FilesMatch "^(install\.php|migrate\.php)$">
    Require all denied
</FilesMatch>
```

`install.php` and `migrate.php` are run exclusively from the command line
(CLI), so HTTP access is denied.

> **Important:** `setup.php` (the install wizard) is **deliberately NOT**
> here — if it were blocked, the application could not be installed via
> the browser. `setup.php` is protected by its own logic: it requires a
> `.setup-allowed` marker and deletes itself after a successful install.

**Do not change** — otherwise you either break installation or expose
the migrations.

### 3.8 Blocking SQL, logs, backups, docs, scripts

```apache
<FilesMatch "\.(sql|log|bak|md|sh|lock)$">
    Require all denied
</FilesMatch>
```

Hides database dumps (`.sql`), logs (`.log`), backups (`.bak`), Markdown
documentation (`.md`), shell scripts (`.sh`) and lock files.
**Do not change.**

### 3.9 Blocking package and build configs

```apache
<FilesMatch "^(composer\.(json|lock)|package(-lock)?\.json|tailwind\.config\.js)$">
    Require all denied
</FilesMatch>
```

Hides tooling configs (`composer.json`, `package.json`,
`tailwind.config.js`) so they don't disclose dependency versions.
**Do not change.**

### 3.10 Security headers

```apache
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
```

`X-Content-Type-Options: nosniff` stops the browser from "guessing" a
file's type. `Referrer-Policy` limits how much of the URL is sent in the
`Referer` header.

> The other headers (`X-Frame-Options`, `Content-Security-Policy`, `HSTS`,
> `Permissions-Policy`, `COOP`) are set by the **`security.php` PHP file**
> as the single source of truth. These are only static defaults for
> non-PHP requests. **Do not change.**

### 3.11 Compression (`mod_deflate`)

```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css
    AddOutputFilterByType DEFLATE application/javascript application/json
    AddOutputFilterByType DEFLATE image/svg+xml application/xml
</IfModule>
```

Enables gzip compression of textual content — smaller transfer, faster
loading. **Do not change.**

### 3.12 Static asset caching

```apache
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 30 days"
    ... (images 30 days, fonts 1 year)
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

Static files (CSS, JS, images, fonts) are cached by the browser for 30
days (fonts for a year). PHP and HTML are never cached — always fresh
content.

> **Tip for localhost development:** the 30-day cache can be annoying
> while editing CSS/JS — the browser holds the old version. Either cache
> for a shorter time, or during development use a hard refresh (Ctrl+F5)
> or disable the cache in the developer tools. There is no need to change
> the file.

---

## 4. `uploads/.htaccess` and `logs/.htaccess`

These two files **are not edited in any environment** — they work the
same on localhost and on web hosting.

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

The `uploads/` folder holds user-uploaded files (GPX, photos). That is a
security-sensitive spot — if someone uploaded a PHP script and the server
ran it, it would mean a site takeover. Therefore:

- **PHP execution is blocked** — even if a `.php` file landed in
  `uploads/`, the server won't run it;
- **sensitive extensions are blocked** (`.env`, `.sql`, `.ini` …);
- `Options -Indexes` — the folder contents cannot be browsed;
- `Content-Disposition: attachment` for HTML/SVG/XML — the browser
  **downloads them instead of displaying them**, which prevents an XSS
  attack via uploaded content.

### 4.2 `logs/.htaccess`

```apache
Require all denied
```

A single line — a complete ban on HTTP access to the whole logs folder.
The application writes to logs server-side; they must not be visible
externally.

---

## 5. Localhost installation — WAMP

Common to all three scenarios below:

1. Copy the project into a folder under `C:\wamp64\www\` (e.g.
   `C:\wamp64\www\gpx_manager\`).
2. **Use the localhost `.htaccess` variant** — rename
   `.htaccess.localhost.example` to `.htaccess` (it has the HTTPS
   redirect disabled). If you keep the production `.htaccess`, the site
   won't work.
3. Verify the Apache modules from
   [section 2.2](#22-required-apache-modules) are running.

### 5a. Directly inside the `www/` folder

The simplest way. The project sits straight in `www`:

```
C:\wamp64\www\gpx_manager\
```

The application then runs at:

```
http://localhost/gpx_manager/
```

No Apache configuration change is needed — `www` has `AllowOverride All`
by default. The localhost `.htaccess` variant is enough.

### 5b. Via Alias

An Alias lets you keep the project outside `www` and still expose it via
a short URL. WAMP has separate files for aliases in `C:\wamp64\alias\`.
Create, for example, the file `C:\wamp64\alias\gpx.conf`:

```apache
Alias /gpx "C:/wamp64/www/_web_/puschpull_web/gpx_manager_public-main/"

<Directory "C:/wamp64/www/_web_/puschpull_web/gpx_manager_public-main/">
    Options +Indexes +FollowSymLinks +MultiViews
    AllowOverride All
    Require local
</Directory>
```

> The key line is **`AllowOverride All`** — without it the project's
> `.htaccess` is ignored. `Require local` limits access to your computer
> only.

Restart Apache (WAMP icon → *Restart Service*). The application runs at:

```
http://localhost/gpx/
```

Here too use the localhost `.htaccess` variant (HTTPS redirect disabled).
The application's relative paths work under an alias without changes.

### 5c. Via VirtualHost

A VirtualHost gives the project its own "domain", e.g. `gpx.local`.

**Step 1 — VirtualHost.** WAMP has a file for virtual hosts at
`C:\wamp64\bin\apache\apache<version>\conf\extra\httpd-vhosts.conf`.
Add:

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

> WAMP has a wizard: on the `http://localhost` page → the
> *"Add a Virtual Host"* link — it does the same via a form.

**Step 2 — hosts file.** For `gpx.local` to work in the browser, add a
line to `C:\Windows\System32\drivers\etc\hosts` (run the editor as
administrator):

```
127.0.0.1 gpx.local
```

**Step 3 — restart Apache** (WAMP icon → *Restart Service*).

The application then runs at:

```
http://gpx.local/
```

Here too use the localhost `.htaccess` variant. Because the project is at
the virtual host's root, the production variant would also work — but
**only if you disable the HTTPS redirect** in it (you have no certificate
locally).

---

## 6. Localhost installation — XAMPP

XAMPP works the same as WAMP, just with different paths:

- the web root is `C:\xampp\htdocs\` (instead of `C:\wamp64\www\`),
- the Apache configuration is `C:\xampp\apache\conf\httpd.conf`,
- virtual hosts are in `C:\xampp\apache\conf\extra\httpd-vhosts.conf`.

### 6a. Directly in `htdocs/`

Copy the project into `C:\xampp\htdocs\gpx_manager\`, rename
`.htaccess.localhost.example` to `.htaccess`. The application runs at:

```
http://localhost/gpx_manager/
```

### 6b. Alias

XAMPP has no separate files for aliases — add the block straight into
`httpd.conf` (or into `apache/conf/extra/httpd-xampp.conf`):

```apache
Alias /gpx "C:/path/to/gpx_manager_public-main/"

<Directory "C:/path/to/gpx_manager_public-main/">
    Options +Indexes +FollowSymLinks +MultiViews
    AllowOverride All
    Require local
</Directory>
```

Restart Apache in the XAMPP Control Panel. The application runs at
`http://localhost/gpx/`.

### 6c. VirtualHost

Into `httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName gpx.local
    DocumentRoot "C:/path/to/gpx_manager_public-main"
    <Directory "C:/path/to/gpx_manager_public-main">
        Options +Indexes +FollowSymLinks +MultiViews
        AllowOverride All
        Require local
    </Directory>
</VirtualHost>
```

With the default XAMPP setup, verify that the line
`Include conf/extra/httpd-vhosts.conf` in `httpd.conf` is not commented
out (it must have no `#`). Then add `127.0.0.1 gpx.local` to the `hosts`
file (see 5c) and restart Apache. The application runs at
`http://gpx.local/`.

In all three cases, use the localhost `.htaccess` variant.

---

## 7. Web hosting installation

On shared web hosting you usually don't know in advance exactly where the
application will be installed. Good news: GPX Manager is ready for that —
it uses relative paths and the `.htaccess` is written defensively
(`<IfModule>` wrappers).

### 7.1 Installing at the domain root

If the application runs directly at `https://example.com/`, upload the
project contents into the root web folder (often `public_html/`, `www/`
or `htdocs/` — depending on the host) and **keep the production
`.htaccess`** (the one shipped in the project by default, with the HTTPS
redirect enabled).

### 7.2 Installing in a subdirectory

If the application runs in a subdirectory, e.g.:

```
https://example.com/gpx/
https://example.com/apps/gpx-manager/
```

upload the project into the corresponding subfolder. The application uses
exclusively **relative paths** for CSS/JS/images
(`href="assets/css/app.css"`), so it works in a subdirectory **without
any change** — the `RewriteBase` directive does not need to be set.

Use the `.htaccess.subdir.example` variant (its content is identical to
the production one, only with comments about subdirectories). The HTTPS
redirect works correctly in a subdirectory too, because `%{REQUEST_URI}`
contains the full path.

### 7.3 PHP limits on FastCGI / PHP-FPM

Most modern hosts run PHP as **FastCGI / PHP-FPM**, not as an Apache
module. In that case the `php_value` directives from the upload-limits
block (section 3.1) **do not apply**. Thanks to the `<IfModule mod_php.c>`
wrapper this **does not cause a 500 error** — the block is simply skipped.

If you need to raise upload limits and `php_value` has no effect, set
them elsewhere:

- a **`.user.ini` file** in the project root (PHP-FPM reads it):
  ```ini
  upload_max_filesize = 200M
  post_max_size = 210M
  max_execution_time = 300
  memory_limit = 256M
  ```
- or via the **hosting control panel** (cPanel, Plesk, DirectAdmin
  usually have a "PHP settings" / "PHP Selector" section).

### 7.4 Very old Apache 2.2

`.htaccess` uses the `Require all denied` directive from **Apache 2.4**.
On the now rare Apache 2.2, this directive returns a **500** error. If
you end up on such a server, replace in all `.htaccess` files:

```apache
Require all denied
```

with:

```apache
Order allow,deny
Deny from all
```

Apache 2.4 was released in 2012, so in practice you almost never need
this — the vast majority of hosts run 2.4+.

### 7.5 Summary — web hosting

| Situation | `.htaccess` variant | HTTPS redirect |
|---|---|---|
| Domain root, HTTPS | production (project default) | enabled |
| Subdirectory, HTTPS | `.htaccess.subdir.example` | enabled |
| HTTP-only hosting | any, comment out the redirect | disabled |

---

## 8. Ready-made `.htaccess` variants

The project ships three variants of the root `.htaccess`:

| File | For whom | HTTPS redirect |
|---|---|---|
| `.htaccess` | Production, domain root | enabled |
| `.htaccess.localhost.example` | WAMP, XAMPP (localhost) | **disabled** |
| `.htaccess.subdir.example` | Web hosting in a subdirectory | enabled |

**Using an example variant:** pick the matching file and rename it to
`.htaccess` (optionally back up the original, e.g. to
`.htaccess.production`).

In Windows Explorer you can rename a file starting with a dot without
issues. From the command line:

```powershell
# PowerShell — localhost variant
Rename-Item .htaccess .htaccess.production
Rename-Item .htaccess.localhost.example .htaccess
```

The `uploads/.htaccess` and `logs/.htaccess` files are never changed and
have no variants.

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `This site can't be reached` / `localhost refused to connect` on localhost | HTTPS redirect enabled without an SSL certificate | Use `.htaccess.localhost.example` or comment out the HTTPS redirect block (sec. 3.4) |
| Too many redirects / redirect loop | HTTPS redirect on a server without working SSL, or HTTPS terminated at a proxy | Localhost: disable the redirect. Hosting: check the SSL certificate; the `X-Forwarded-Proto` condition handles the proxy loop |
| **500 Internal Server Error** | A directive unsupported by the server (`Require` on Apache 2.2, `php_value` outside `<IfModule>`) | Apache 2.2 → replace `Require all denied` (sec. 7.4). Otherwise check the server `error.log` |
| `.htaccess` has no effect at all (site runs, but without HTTPS/protection) | `AllowOverride None` for that directory | Set `AllowOverride All` in the `<Directory>` block (sec. 2.1) and restart Apache |
| **403 Forbidden** on the whole site | An overly aggressive rule or `Require local` on hosting | Check that no `<FilesMatch>` blocks `index.php`; do not use `Require local` on hosting |
| HTTPS redirect doesn't work | `mod_rewrite` disabled | Enable `rewrite_module` (sec. 2.2) |
| Security headers missing | `mod_headers` disabled | Enable `headers_module` (sec. 2.2) |
| Large file upload fails | `php_value` has no effect (FastCGI/PHP-FPM) | Set limits via `.user.ini` or the hosting panel (sec. 7.3) |
| After editing CSS/JS the old version shows | 30-day static asset cache | Hard refresh Ctrl+F5, or disable the cache in developer tools |
| Assets (CSS/JS) don't load in a subdirectory | Incorrectly entered absolute paths | The application uses relative paths — verify you didn't change `href`/`src`; `RewriteBase` is not set |
| `setup.php` reports access denied during installation | `setup.php` was mistakenly added to a `<FilesMatch>` block | `setup.php` must not be blocked in `.htaccess` (sec. 3.7) |

> **Where to find the cause of a 500 error:** look at Apache's `error.log`.
> WAMP: icon → *Apache → Apache error log*. XAMPP: the *Logs* button next
> to Apache in the Control Panel. Web hosting: the "Logs" section in the
> panel.

---

> This guide covers only `.htaccess` configuration. For the full
> application installation procedure (database, `.env`, the `setup.php`
> wizard) see [instructions/en.md](../instructions/en.md).
