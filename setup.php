<?php
/**
 * GPX Manager — Instalační průvodce / Setup Wizard / Installationsassistent
 * Tento soubor po úspěšné instalaci sám sebe smaže.
 * This file deletes itself after successful installation.
 */

// Blokace pokud .env již existuje
if (is_file(__DIR__ . '/.env')) {
    $msg = [
        'cs' => '⚠ Soubor .env již existuje — aplikace je již nainstalována.<br><br><a href="index.php">→ Přejít na aplikaci</a>',
        'en' => '⚠ The .env file already exists — the application is already installed.<br><br><a href="index.php">→ Go to application</a>',
        'de' => '⚠ Die Datei .env existiert bereits — die Anwendung ist bereits installiert.<br><br><a href="index.php">→ Zur Anwendung</a>',
    ];
    $l = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en', 0, 2);
    if (!isset($msg[$l])) $l = 'en';
    die('<h2 style="font-family:Arial;color:#c00;padding:20px;">' . $msg[$l] . '</h2>');
}

session_start();

// ── Detekce jazyka ─────────────────────────────────────────
$supportedLangs = ['cs', 'en', 'de'];
$lang = $_GET['lang'] ?? $_SESSION['setup_lang'] ?? null;
if (!$lang || !in_array($lang, $supportedLangs)) {
    $browser = strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en', 0, 2));
    $lang = in_array($browser, $supportedLangs) ? $browser : 'en';
}
$_SESSION['setup_lang'] = $lang;

// ── Překlady ───────────────────────────────────────────────
$t = [
    'cs' => [
        'page_title'      => 'GPX Manager — Instalace',
        'heading'         => '🗺 GPX Manager',
        'step_of'         => 'Instalační průvodce — krok %d ze 3',
        'errors_title'    => 'Chyby:',
        // Krok 1
        'step1_title'     => 'Připojení k databázi',
        'db_host'         => 'Databázový server',
        'db_host_hint'    => 'Obvykle "localhost". Na sdíleném hostingu může být jiný (viz panel hostingu).',
        'db_name'         => 'Název databáze',
        'db_name_hint'    => 'Databáze musí již existovat — vytvoř ji v phpMyAdmin.',
        'db_user'         => 'Uživatel databáze',
        'db_pass'         => 'Heslo databáze',
        'db_pass_hint'    => 'Prázdné = WampServer výchozí (bez hesla).',
        'btn_test'        => 'Otestovat připojení →',
        'err_db_name'     => 'Název databáze je povinný.',
        'err_db_user'     => 'Uživatel databáze je povinný.',
        'err_db_conn'     => 'Nelze se připojit k databázi: ',
        // Krok 2
        'step2_title'     => 'Admin účet',
        'admin_user'      => 'Uživatelské jméno',
        'admin_pass'      => 'Heslo',
        'admin_pass_hint' => 'Minimálně 8 znaků.',
        'admin_pass2'     => 'Heslo znovu',
        'btn_next'        => 'Pokračovat →',
        'err_user'        => 'Uživatelské jméno je povinné.',
        'err_pass_short'  => 'Heslo musí mít alespoň 8 znaků.',
        'err_pass_match'  => 'Hesla se neshodují.',
        // Krok 3
        'step3_title'     => 'Přístup a API klíče',
        'admin_ips'       => 'Admin IP adresy',
        'admin_ips_hint'  => 'Čárkou oddělené IP adresy s admin přístupem. Tvoje aktuální IP: <strong>%s</strong><br>Localhost (127.0.0.1, ::1) je vždy povolen.',
        'tf_key'          => 'Thunderforest API klíč',
        'tf_hint'         => 'Pro turistické a cyklo mapové podklady — <a href="https://www.thunderforest.com/" target="_blank">thunderforest.com</a>',
        'mapy_key'        => 'Mapy.cz API klíč',
        'mapy_hint'       => 'Pro českou leteckou mapu — <a href="https://developer.mapy.cz/" target="_blank">developer.mapy.cz</a>',
        'mapillary_tok'   => 'Mapillary token',
        'mapillary_hint'  => 'Pro street-level fotky na mapě — <a href="https://www.mapillary.com/developer" target="_blank">mapillary.com/developer</a>',
        'optional'        => '(volitelné)',
        'btn_finish'      => '✅ Dokončit instalaci',
        'err_env_write'   => 'Nelze zapsat soubor .env — zkontroluj oprávnění ke složce.',
        'err_db_tables'   => 'Chyba při vytváření tabulek: ',
        'err_db_conn2'    => 'Chyba připojení k databázi: ',
        'err_session'     => 'Session vypršela — začni znovu od kroku 1.',
        // Výsledek
        'success_title'   => '✅ Instalace dokončena!',
        'success_msg'     => 'Soubor <code>.env</code> byl vytvořen a databázové tabulky jsou připraveny.<br>Soubor <code>setup.php</code> byl z bezpečnostních důvodů smazán.',
        'success_link'    => '→ Přejít na přihlášení',
    ],
    'en' => [
        'page_title'      => 'GPX Manager — Setup',
        'heading'         => '🗺 GPX Manager',
        'step_of'         => 'Setup Wizard — step %d of 3',
        'errors_title'    => 'Errors:',
        'step1_title'     => 'Database Connection',
        'db_host'         => 'Database server',
        'db_host_hint'    => 'Usually "localhost". On shared hosting it may differ (check your hosting panel).',
        'db_name'         => 'Database name',
        'db_name_hint'    => 'The database must already exist — create it in phpMyAdmin first.',
        'db_user'         => 'Database user',
        'db_pass'         => 'Database password',
        'db_pass_hint'    => 'Leave empty if using WampServer defaults.',
        'btn_test'        => 'Test connection →',
        'err_db_name'     => 'Database name is required.',
        'err_db_user'     => 'Database user is required.',
        'err_db_conn'     => 'Cannot connect to database: ',
        'step2_title'     => 'Admin Account',
        'admin_user'      => 'Username',
        'admin_pass'      => 'Password',
        'admin_pass_hint' => 'At least 8 characters.',
        'admin_pass2'     => 'Repeat password',
        'btn_next'        => 'Continue →',
        'err_user'        => 'Username is required.',
        'err_pass_short'  => 'Password must be at least 8 characters.',
        'err_pass_match'  => 'Passwords do not match.',
        'step3_title'     => 'Access & API Keys',
        'admin_ips'       => 'Admin IP addresses',
        'admin_ips_hint'  => 'Comma-separated IP addresses with admin access. Your current IP: <strong>%s</strong><br>Localhost (127.0.0.1, ::1) is always allowed.',
        'tf_key'          => 'Thunderforest API key',
        'tf_hint'         => 'For hiking and cycling map tiles — <a href="https://www.thunderforest.com/" target="_blank">thunderforest.com</a>',
        'mapy_key'        => 'Mapy.cz API key',
        'mapy_hint'       => 'For Czech aerial maps — <a href="https://developer.mapy.cz/" target="_blank">developer.mapy.cz</a>',
        'mapillary_tok'   => 'Mapillary token',
        'mapillary_hint'  => 'For street-level photos on the map — <a href="https://www.mapillary.com/developer" target="_blank">mapillary.com/developer</a>',
        'optional'        => '(optional)',
        'btn_finish'      => '✅ Complete installation',
        'err_env_write'   => 'Cannot write .env file — check folder permissions.',
        'err_db_tables'   => 'Error creating tables: ',
        'err_db_conn2'    => 'Database connection error: ',
        'err_session'     => 'Session expired — please start again from step 1.',
        'success_title'   => '✅ Installation complete!',
        'success_msg'     => 'The <code>.env</code> file has been created and the database tables are ready.<br>The <code>setup.php</code> file has been deleted for security.',
        'success_link'    => '→ Go to login',
    ],
    'de' => [
        'page_title'      => 'GPX Manager — Installation',
        'heading'         => '🗺 GPX Manager',
        'step_of'         => 'Installationsassistent — Schritt %d von 3',
        'errors_title'    => 'Fehler:',
        'step1_title'     => 'Datenbankverbindung',
        'db_host'         => 'Datenbankserver',
        'db_host_hint'    => 'Normalerweise "localhost". Bei Shared Hosting kann es abweichen (siehe Hosting-Panel).',
        'db_name'         => 'Datenbankname',
        'db_name_hint'    => 'Die Datenbank muss bereits vorhanden sein — erstelle sie zuerst in phpMyAdmin.',
        'db_user'         => 'Datenbankbenutzer',
        'db_pass'         => 'Datenbankpasswort',
        'db_pass_hint'    => 'Leer lassen bei WampServer-Standardeinstellungen.',
        'btn_test'        => 'Verbindung testen →',
        'err_db_name'     => 'Datenbankname ist erforderlich.',
        'err_db_user'     => 'Datenbankbenutzer ist erforderlich.',
        'err_db_conn'     => 'Verbindung zur Datenbank fehlgeschlagen: ',
        'step2_title'     => 'Admin-Konto',
        'admin_user'      => 'Benutzername',
        'admin_pass'      => 'Passwort',
        'admin_pass_hint' => 'Mindestens 8 Zeichen.',
        'admin_pass2'     => 'Passwort wiederholen',
        'btn_next'        => 'Weiter →',
        'err_user'        => 'Benutzername ist erforderlich.',
        'err_pass_short'  => 'Das Passwort muss mindestens 8 Zeichen lang sein.',
        'err_pass_match'  => 'Die Passwörter stimmen nicht überein.',
        'step3_title'     => 'Zugang & API-Schlüssel',
        'admin_ips'       => 'Admin-IP-Adressen',
        'admin_ips_hint'  => 'Kommagetrennte IP-Adressen mit Admin-Zugang. Deine aktuelle IP: <strong>%s</strong><br>Localhost (127.0.0.1, ::1) ist immer erlaubt.',
        'tf_key'          => 'Thunderforest API-Schlüssel',
        'tf_hint'         => 'Für Wander- und Radkarten — <a href="https://www.thunderforest.com/" target="_blank">thunderforest.com</a>',
        'mapy_key'        => 'Mapy.cz API-Schlüssel',
        'mapy_hint'       => 'Für tschechische Luftbildkarten — <a href="https://developer.mapy.cz/" target="_blank">developer.mapy.cz</a>',
        'mapillary_tok'   => 'Mapillary-Token',
        'mapillary_hint'  => 'Für Street-Level-Fotos auf der Karte — <a href="https://www.mapillary.com/developer" target="_blank">mapillary.com/developer</a>',
        'optional'        => '(optional)',
        'btn_finish'      => '✅ Installation abschließen',
        'err_env_write'   => 'Datei .env kann nicht geschrieben werden — überprüfe die Ordnerberechtigungen.',
        'err_db_tables'   => 'Fehler beim Erstellen der Tabellen: ',
        'err_db_conn2'    => 'Datenbankverbindungsfehler: ',
        'err_session'     => 'Sitzung abgelaufen — bitte beginne erneut ab Schritt 1.',
        'success_title'   => '✅ Installation abgeschlossen!',
        'success_msg'     => 'Die Datei <code>.env</code> wurde erstellt und die Datenbanktabellen sind bereit.<br>Die Datei <code>setup.php</code> wurde aus Sicherheitsgründen gelöscht.',
        'success_link'    => '→ Zur Anmeldung',
    ],
];

// Zkratka pro překlad
function s(string $key) : string {
    global $t, $lang;
    return $t[$lang][$key] ?? $t['en'][$key] ?? $key;
}

$step   = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
$errors = [];
$success = false;

// ── Krok 1: Test DB ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1) {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';

    if (!$dbName) $errors[] = s('err_db_name');
    if (!$dbUser) $errors[] = s('err_db_user');

    if (empty($errors)) {
        try {
            new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $_SESSION['setup'] = compact('dbHost', 'dbName', 'dbUser', 'dbPass');
            header('Location: setup.php?step=2&lang=' . $lang);
            exit;
        } catch (PDOException $e) {
            $errors[] = s('err_db_conn') . htmlspecialchars($e->getMessage());
        }
    }

// ── Krok 2: Admin účet ─────────────────────────────────────
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $adminUser  = trim($_POST['admin_user'] ?? '');
    $adminPass  = $_POST['admin_pass']  ?? '';
    $adminPass2 = $_POST['admin_pass2'] ?? '';

    if (!$adminUser)                $errors[] = s('err_user');
    if (strlen($adminPass) < 8)     $errors[] = s('err_pass_short');
    if ($adminPass !== $adminPass2) $errors[] = s('err_pass_match');

    if (empty($errors)) {
        $_SESSION['setup']['adminUser'] = $adminUser;
        $_SESSION['setup']['adminHash'] = password_hash($adminPass, PASSWORD_DEFAULT);
        header('Location: setup.php?step=3&lang=' . $lang);
        exit;
    }

// ── Krok 3: IP + API + zápis .env ─────────────────────────
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    if (empty($_SESSION['setup'])) {
        $errors[] = s('err_session');
        $step = 1;
    } else {
        $adminIPs     = trim($_POST['admin_ips']    ?? '127.0.0.1,::1');
        $tfKey        = trim($_POST['tf_key']        ?? '');
        $mapyKey      = trim($_POST['mapy_key']      ?? '');
        $mapillaryTok = trim($_POST['mapillary_tok'] ?? '');
        $s            = $_SESSION['setup'];

        $envContent = "# GPX Manager — configuration\n"
            . "# Generated by setup wizard " . date('Y-m-d H:i:s') . "\n\n"
            . "# Database\nDB_HOST={$s['dbHost']}\nDB_NAME={$s['dbName']}\n"
            . "DB_USER={$s['dbUser']}\nDB_PASS={$s['dbPass']}\n\n"
            . "# Admin account\nADMIN_USER={$s['adminUser']}\nADMIN_PASS_HASH={$s['adminHash']}\n\n"
            . "# Admin IPs (comma-separated)\nADMIN_IPS=$adminIPs\n\n"
            . "# API keys (optional)\nTF_API_KEY=$tfKey\nMAPYCOM_API_KEY=$mapyKey\nMAPILLARY_TOKEN=$mapillaryTok\n";

        if (file_put_contents(__DIR__ . '/.env', $envContent) === false) {
            $errors[] = s('err_env_write');
        } else {
            try {
                $pdo = new PDO(
                    "mysql:host={$s['dbHost']};dbname={$s['dbName']};charset=utf8mb4",
                    $s['dbUser'], $s['dbPass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $sql = preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/install.sql'));
                $dbErrors = [];
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    try { $pdo->exec($stmt); }
                    catch (PDOException $e) { if ($e->getCode() !== '42S01') $dbErrors[] = htmlspecialchars($e->getMessage()); }
                }
                if ($dbErrors) {
                    @unlink(__DIR__ . '/.env');
                    $errors[] = s('err_db_tables') . implode('; ', $dbErrors);
                } else {
                    session_destroy();
                    $success = true;
                }
            } catch (PDOException $e) {
                @unlink(__DIR__ . '/.env');
                $errors[] = s('err_db_conn2') . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Kontrola session při GET přesměrování
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $step > 1 && empty($_SESSION['setup'])) {
    header('Location: setup.php?lang=' . $lang);
    exit;
}

if ($success) @unlink(__FILE__);

// ── Přepínač jazyků ───────────────────────────────────────
$langNames  = ['cs' => '🇨🇿 Česky', 'en' => '🇬🇧 English', 'de' => '🇩🇪 Deutsch'];
$currentUrl = '?step=' . $step;
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= s('page_title') ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; background: #f0f2f5; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; }
.lang-bar { display: flex; gap: 10px; margin-bottom: 14px; }
.lang-bar a { font-size: 13px; text-decoration: none; color: #555; padding: 3px 8px; border-radius: 4px; border: 1px solid #dde; background: white; }
.lang-bar a.active { background: #003366; color: white; border-color: #003366; }
.card { background: white; border-radius: 12px; padding: 36px 40px; max-width: 520px; width: 100%; box-shadow: 0 4px 24px rgba(0,0,0,.10); }
h1 { font-size: 22px; color: #003366; margin-bottom: 6px; }
.subtitle { color: #666; font-size: 14px; margin-bottom: 28px; }
.steps { display: flex; gap: 8px; margin-bottom: 28px; }
.step-dot { flex: 1; height: 4px; border-radius: 2px; background: #dde; }
.step-dot.done { background: #28a745; }
.step-dot.active { background: #003366; }
label { display: block; font-size: 13px; font-weight: 600; color: #333; margin-bottom: 4px; margin-top: 16px; }
input[type=text], input[type=password] { width: 100%; padding: 9px 12px; border: 1px solid #ccd; border-radius: 6px; font-size: 14px; }
input:focus { outline: none; border-color: #003366; box-shadow: 0 0 0 2px rgba(0,51,102,.15); }
.hint { font-size: 11px; color: #888; margin-top: 3px; line-height: 1.5; }
.hint a { color: #003366; }
.opt { font-weight: normal; color: #999; font-size: 12px; }
.errors { background: #fff0f0; border: 1px solid #f5c6c6; border-radius: 6px; padding: 12px 16px; margin-bottom: 20px; color: #c00; font-size: 13px; }
.errors li { margin-left: 16px; margin-top: 4px; }
.btn { display: block; width: 100%; margin-top: 28px; padding: 12px; background: #003366; color: white; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; }
.btn:hover { background: #002244; }
.success { text-align: center; }
.success h2 { color: #28a745; font-size: 26px; margin-bottom: 12px; }
.success p { color: #555; margin-bottom: 20px; font-size: 14px; line-height: 1.7; }
.success a { display: inline-block; background: #003366; color: white; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; }
</style>
</head>
<body>

<!-- Přepínač jazyků -->
<div class="lang-bar">
<?php foreach ($langNames as $code => $label): ?>
    <a href="?step=<?= $step ?>&lang=<?= $code ?>"
       class="<?= $code === $lang ? 'active' : '' ?>"><?= $label ?></a>
<?php endforeach; ?>
</div>

<div class="card">
<?php if ($success): ?>
    <div class="success">
        <h2><?= s('success_title') ?></h2>
        <p><?= s('success_msg') ?></p>
        <a href="login.php"><?= s('success_link') ?></a>
    </div>

<?php else: ?>
    <h1><?= s('heading') ?></h1>
    <p class="subtitle"><?= sprintf(s('step_of'), $step) ?></p>

    <div class="steps">
        <div class="step-dot <?= $step > 1 ? 'done' : ($step === 1 ? 'active' : '') ?>"></div>
        <div class="step-dot <?= $step > 2 ? 'done' : ($step === 2 ? 'active' : '') ?>"></div>
        <div class="step-dot <?= $step === 3 ? 'active' : '' ?>"></div>
    </div>

    <?php if ($errors): ?>
    <div class="errors"><strong><?= s('errors_title') ?></strong><ul>
        <?php foreach ($errors as $err): ?><li><?= $err ?></li><?php endforeach; ?>
    </ul></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
    <form method="post" action="setup.php?step=1&lang=<?= $lang ?>">
        <input type="hidden" name="step" value="1">
        <label><?= s('db_host') ?></label>
        <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
        <p class="hint"><?= s('db_host_hint') ?></p>

        <label><?= s('db_name') ?></label>
        <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'gpx_manager') ?>" required>
        <p class="hint"><?= s('db_name_hint') ?></p>

        <label><?= s('db_user') ?></label>
        <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required>

        <label><?= s('db_pass') ?></label>
        <input type="password" name="db_pass" placeholder="">
        <p class="hint"><?= s('db_pass_hint') ?></p>

        <button class="btn" type="submit"><?= s('btn_test') ?></button>
    </form>

    <?php elseif ($step === 2): ?>
    <form method="post" action="setup.php?step=2&lang=<?= $lang ?>">
        <input type="hidden" name="step" value="2">
        <label><?= s('admin_user') ?></label>
        <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required autofocus>

        <label><?= s('admin_pass') ?></label>
        <input type="password" name="admin_pass" placeholder="<?= s('admin_pass_hint') ?>" required>
        <p class="hint"><?= s('admin_pass_hint') ?></p>

        <label><?= s('admin_pass2') ?></label>
        <input type="password" name="admin_pass2" required>

        <button class="btn" type="submit"><?= s('btn_next') ?></button>
    </form>

    <?php elseif ($step === 3): ?>
    <form method="post" action="setup.php?step=3&lang=<?= $lang ?>">
        <input type="hidden" name="step" value="3">

        <label><?= s('admin_ips') ?></label>
        <input type="text" name="admin_ips"
               value="<?= htmlspecialchars('127.0.0.1,::1,' . ($_SERVER['REMOTE_ADDR'] ?? '')) ?>">
        <p class="hint"><?= sprintf(s('admin_ips_hint'), htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '')) ?></p>

        <label><?= s('tf_key') ?> <span class="opt"><?= s('optional') ?></span></label>
        <input type="text" name="tf_key" placeholder="">
        <p class="hint"><?= s('tf_hint') ?></p>

        <label><?= s('mapy_key') ?> <span class="opt"><?= s('optional') ?></span></label>
        <input type="text" name="mapy_key" placeholder="">
        <p class="hint"><?= s('mapy_hint') ?></p>

        <label><?= s('mapillary_tok') ?> <span class="opt"><?= s('optional') ?></span></label>
        <input type="text" name="mapillary_tok" placeholder="">
        <p class="hint"><?= s('mapillary_hint') ?></p>

        <button class="btn" type="submit"><?= s('btn_finish') ?></button>
    </form>
    <?php endif; ?>

<?php endif; ?>
</div>
</body>
</html>
