<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

start_secure_session();
send_security_headers();

// Maximum failed attempts before lockout (within the window below)
const LOGIN_MAX_ATTEMPTS = 5;
// Lockout window in minutes
const LOGIN_WINDOW_MINUTES = 15;

$error = '';

// ----- Logout (POST + CSRF only — GET vector eliminated for SEC-024) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!csrf_verify()) {
        http_response_code(403);
        die('CSRF verification failed.');
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

// ----- IP-based rate limiting (SEC-009, SEC-030) -----
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Úklid: záznamy starší 7 dnů už rate-limit okno (15 min) nikdy nepotřebuje —
// bez tohoto DELETE by tabulka rostla donekonečna. Běží jen na login POSTech.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
}

$stmtCount = $pdo->prepare(
    "SELECT COUNT(*) FROM login_attempts
     WHERE ip = ? AND success = 0
       AND attempted_at > DATE_SUB(NOW(), INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)"
);
$stmtCount->execute([$ip]);
$failedCount = (int) $stmtCount->fetchColumn();

if ($failedCount >= LOGIN_MAX_ATTEMPTS) {
    $error = t('err_too_many') . ' ' . LOGIN_WINDOW_MINUTES . ' min.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ----- Login attempt -----
    if (!csrf_verify()) {
        $error = t('err_invalid_csrf');
    } else {
        $user = trim($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';
        $valid = ($user === ADMIN_USER) && password_verify($pass, ADMIN_PASS_HASH);

        // Record attempt before acting on result (prevents enumeration via timing)
        $pdo->prepare("INSERT INTO login_attempts (ip, success) VALUES (?, ?)")
            ->execute([$ip, $valid ? 1 : 0]);

        if ($valid) {
            // SEC-009: regenerate session ID after successful authentication
            session_regenerate_id(true);
            $_SESSION['is_admin']  = true;
            $_SESSION['admin_via'] = 'login';
            // Clean up failed attempts for this IP to reset the lockout counter
            $pdo->prepare("DELETE FROM login_attempts WHERE ip = ? AND success = 0")
                ->execute([$ip]);
            header('Location: index.php');
            exit;
        } else {
            $error = t('err_invalid_creds');
        }
    }
}
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars(function_exists('app_lang') ? app_lang() : 'cs') ?>" x-data="{ dark: localStorage.getItem('gpx-theme') === 'dark' || (!localStorage.getItem('gpx-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches) }" x-init="document.documentElement.classList.toggle('dark', dark)">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('page_title_login') ?></title>
    <script>(function(){var t=localStorage.getItem('gpx-theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark');})();</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
    <script defer
            src="https://unpkg.com/alpinejs@3.14.1/dist/cdn.min.js"
            integrity="sha384-l8f0VcPi/M1iHPv8egOnY/15TDwqgbOR1anMIJWvU6nLRgZVLTLSaNqi/TOoT5Fh"
            crossorigin="anonymous"></script>
    <!-- lucide@0.516.0 nemá UMD build (404) — sjednoceno na 0.469.0 jako v layout_header.php -->
    <script defer
            src="https://cdn.jsdelivr.net/npm/lucide@0.469.0/dist/umd/lucide.min.js"
            integrity="sha384-hJnF5AwidE18GSWTAGHv3ByzzvfNZ1Tcx5y1UUV3WkauuMCEzBJBMSwSt/PUPXnM"
            crossorigin="anonymous"></script>
    <link rel="icon" type="image/svg+xml" href="assets/img/logo-mountain.svg">
</head>
<body class="font-[Inter] antialiased">

<div class="relative min-h-screen flex items-center justify-center px-4 bg-sand-50 dark:bg-forest-900 overflow-hidden">
    <!-- Topo background -->
    <div class="absolute inset-0 opacity-[0.06] pointer-events-none topo-bg" aria-hidden="true"></div>
    <div class="absolute inset-0 bg-gradient-to-br from-sand-50 via-sand-50 to-forest-50 dark:from-forest-900 dark:via-forest-900 dark:to-forest-800 pointer-events-none" aria-hidden="true"></div>

    <div class="relative w-full max-w-md">
        <!-- Logo + brand -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-forest-600 to-forest-800 text-white shadow-hover mb-4">
                <svg viewBox="0 0 32 32" fill="currentColor" class="w-10 h-10">
                    <path d="M2 26 L11 12 L17 20 L22 14 L30 26 Z" opacity="0.95"/>
                    <path d="M11 12 L14 16 L13 17 L10 13 Z" fill="white" opacity="0.6"/>
                    <path d="M22 14 L24 17 L23 18 L21 15 Z" fill="white" opacity="0.6"/>
                    <circle cx="24" cy="7" r="2" opacity="0.7"/>
                </svg>
            </div>
            <h1 class="font-[Manrope] text-2xl font-bold text-forest-700 dark:text-sand-100">GPX Manager</h1>
            <p class="mt-1 text-sm text-forest-700/65 dark:text-sand-100/65">Správa GPS tras a fotografií</p>
        </div>

        <form method="post" class="card-outdoor p-6 sm:p-8">
            <?= csrf_field() ?>
            <h2 class="font-[Manrope] text-xl font-semibold text-forest-700 dark:text-sand-100 mb-5 flex items-center gap-2">
                <i data-lucide="lock" class="w-5 h-5" aria-hidden="true"></i>
                <?= t('btn_login') ?>
            </h2>

            <div class="block mb-3">
                <label for="login-user" class="block text-xs uppercase tracking-wider text-forest-700/65 dark:text-sand-100/65 mb-1.5"><?= t('login_user') ?></label>
                <input type="text" id="login-user" name="user" required autofocus
                       autocomplete="username"
                       class="w-full px-3 py-2.5 rounded-md bg-white dark:bg-forest-700 border border-sand-200 dark:border-forest-600 focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500/30 transition">
            </div>

            <div class="block mb-4">
                <label for="login-pass" class="block text-xs uppercase tracking-wider text-forest-700/65 dark:text-sand-100/65 mb-1.5"><?= t('login_pass') ?></label>
                <input type="password" id="login-pass" name="pass" required
                       autocomplete="current-password"
                       class="w-full px-3 py-2.5 rounded-md bg-white dark:bg-forest-700 border border-sand-200 dark:border-forest-600 focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500/30 transition">
            </div>

            <button type="submit" class="btn-outdoor btn-outdoor-primary w-full justify-center py-2.5">
                <i data-lucide="log-in" class="w-4 h-4" aria-hidden="true"></i>
                <?= t('btn_login') ?>
            </button>

            <?php if ($error): ?>
                <div role="alert" class="mt-4 px-3 py-2 rounded-md bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm flex items-start gap-2">
                    <i data-lucide="alert-circle" class="w-4 h-4 mt-0.5 shrink-0" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <div class="mt-5 text-center">
                <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-600 hover:text-terracotta-500 transition-colors">
                    <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
                    <?= t('back_short') ?>
                </a>
            </div>
        </form>
    </div>
</div>

<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
