<?php
declare(strict_types=1);

/**
 * Security helpers: session, CSRF tokens, HTTP headers
 */

// --- Secure session startup ---

/**
 * Starts a session with hardened cookie parameters.
 * Safe to call multiple times — no-ops if session is already active.
 *
 * Cookie flags:
 *   - HttpOnly: JS cannot read the session cookie (mitigates XSS token theft)
 *   - SameSite=Lax: cookie not sent on cross-site POST (CSRF defense in depth)
 *   - Secure: cookie only sent over HTTPS (detected dynamically — allows local HTTP dev)
 */
function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    // HTTPS detection — on production (Webglobe) we force secure=true,
    // because the reverse proxy doesn't always set $_SERVER['HTTPS'].
    // On local (WAMP) we only enable it for real HTTPS so dev still works.
    $secure = (defined('APP_ENV') && APP_ENV !== 'local') || (
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// --- CSRF ---

function csrf_token(): string {
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify(): bool {
    start_secure_session();
    $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    // Prázdný token nesmí projít nikdy. Nová session token ještě nemá — a právě
    // ji dostane cizí web: cross-site POST jde kvůli SameSite=Lax bez cookie,
    // public_access.php nové session z povolené IP nastaví is_admin, a dřív
    // hash_equals('', '') vrátilo true (ověřeno 26. 9. 2026 curl bez cookies).
    return is_string($token) && $expected !== '' && hash_equals($expected, $token);
}

// --- CSP nonce ---

/**
 * Jednorázový kód pro vložené <script> bloky — nový pro každý požadavek.
 * Každý vložený skript v šablonách MUSÍ mít  <script nonce="<?= csp_nonce() ?>">,
 * jinak ho prohlížeč s CSP bez 'unsafe-inline' nespustí. Obsluhy v HTML
 * atributech (onclick="…") se s nonce nespouštějí vůbec — viz js/csp-handlers.js.
 * Funguje jen proto, že se HTML stránky necachují (Cache-Control: no-store).
 */
function csp_nonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(18));
    }
    return $nonce;
}

// --- HTTP Security Headers ---

function send_security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    // X-XSS-Protection intentionally omitted — deprecated and harmful in some browsers (SEC-021)
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');

    // Osobní archiv výletů nepatří do vyhledávačů. Samotný robots.txt na to
    // nestačí — ten říká „neprocházej", ale stránka se do výsledků může dostat
    // i tak, pokud na ni někdo odkáže zvenčí. Tahle hlavička říká „nezobrazuj".
    //
    // Není to zámek, jen prosba, kterou slušné vyhledávače respektují. Kdo zná
    // adresu, dostane se dál — na to je omezení viditelných stránek
    // v Administraci, případně přihlášení.
    header('X-Robots-Tag: noindex, nofollow, noarchive, noimageindex');

    // HSTS only on production — local dev runs plain HTTP (SEC-021)
    if (defined('APP_ENV') && APP_ENV !== 'local') {
        header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
    }

    // CSP — covers all CDN domains used in the application (SEC-021)
    // 'unsafe-eval' NENÍ potřeba (od 9/2026): Alpine běží jako CSP build
    // (@alpinejs/csp) a logika komponent je v js/alpine-components.js.
    // Měřeno na 17 stránkách — žádná jiná knihovna eval nepoužívá. Kdo by ho
    // chtěl vrátit, musí nejdřív najít, co ho potřebuje.
    // 'unsafe-inline' ve script-src NENÍ (od 24. 9. 2026): vložené skripty smějí
    // běžet jen s nonce (csp_nonce()), obsluhy v HTML atributech (onclick="…")
    // neběží vůbec — náhrada je js/csp-handlers.js. Přechod ověřen dvěma dny
    // v režimu Report-Only na produkci bez jediného hlášení.
    // Ve style-src 'unsafe-inline' zůstává záměrně: 200+ atributů style=""
    // a vložené CSS je výrazně menší riziko než vložený skript.

    // Odkud se smějí načítat obrázky. Dřív „https:" = odkudkoli.
    // Seznam ověřen 9/2026 v režimu Report-Only: ukázková dlaždice ze všech
    // 15 dlaždicových vrstev map + radar, Wikimedia, QR a ikony — nic mimo.
    // NOVÁ MAPOVÁ VRSTVA = přidat sem jejího poskytovatele, jinak zůstane
    // prázdná (bez chyby, jen se nezobrazí — v konzoli „Refused to load image").
    $imgSrc = implode(' ', [
        "'self'", 'data:', 'blob:',
        'https://unpkg.com',                        // ikony Leafletu (vrstvy, celá obrazovka), špendlíky start/cíl
        'https://*.tile.openstreetmap.org',         // OSM
        'https://*.tile.opentopomap.org',           // Topo
        'https://server.arcgisonline.com',          // satelit + stínování terénu (Esri)
        'https://api.mapy.com',                     // Mapy.com (4 mapy + popisky)
        'https://*.tile.thunderforest.com',         // Thunderforest
        'https://*.tile-cyclosm.openstreetmap.fr',  // CyclOSM
        'https://ags.cuzk.cz',                      // ZTM ČÚZK
        'https://tile.waymarkedtrails.org',         // turistické / cyklo / MTB trasy
        'https://opendata.chmi.cz',                 // aktuální radar (Plánovač)
        'https://upload.wikimedia.org',             // náhledy fotek Wikimedia
        'https://api.qrserver.com',                 // QR kód pro sdílení trasy
    ]);

    // NOVÝ VLOŽENÝ SKRIPT musí mít atribut nonce z csp_nonce(), jinak neběží
    // (vzor v docbloku csp_nonce() výše; v // komentáři nesmí být PHP koncová značka).
    // 'report-sample' posílá v hlášení začátek zablokovaného kódu — podle něj
    // se pozná, který skript nebo obsluha chybí.
    $scriptSrc = "script-src 'self' 'nonce-" . csp_nonce() . "' 'report-sample' "
               . "https://unpkg.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; ";

    $csp = "default-src 'self'; "
         . $scriptSrc
         . "style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.googleapis.com; "
         . "img-src " . $imgSrc . "; "
         . "font-src 'self' https://fonts.gstatic.com; "
         . "connect-src 'self' "
         . "https://commons.wikimedia.org "
         . "https://*.tile.openstreetmap.org https://*.tile.opentopomap.org "
         . "https://server.arcgisonline.com https://api.mapy.com "
         . "https://*.tile.thunderforest.com https://tiles.mapillary.com "
         . "https://api.open-meteo.com https://archive-api.open-meteo.com; "
         . "frame-ancestors 'none'; "
         . "base-uri 'self'; "
         . "form-action 'self'; "
         // Hlášení o zablokovaném obsahu → api/csp_report.php → logs/csp.log.
         // Relativní adresa funguje v kořeni webu i v podsložce (localhost/gpx/).
         . "report-uri api/csp_report.php;";
    header("Content-Security-Policy: $csp");
}
