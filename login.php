<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/helpers.php';

start_secure_session();
send_security_headers();

$error = '';

// Odhlášení
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
$_SESSION['login_lockout'] = $_SESSION['login_lockout'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = t('err_invalid_csrf');
    } elseif (time() < $_SESSION['login_lockout']) {
        $remaining = $_SESSION['login_lockout'] - time();
        $error = t('err_too_many') . " {$remaining} s.";
    } else {
        $user = trim($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';
        if ($user === ADMIN_USER && password_verify($pass, ADMIN_PASS_HASH)) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            $_SESSION['admin_via'] = 'login';
            $_SESSION['login_attempts'] = 0;
            header('Location: import.php');
            exit;
        } else {
            $_SESSION['login_attempts']++;
            if ($_SESSION['login_attempts'] >= 5) {
                $_SESSION['login_lockout'] = time() + 60;
                $_SESSION['login_attempts'] = 0;
                $error = t('err_too_many') . ' 60 s.';
            } else {
                $error = t('err_invalid_creds');
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="cs" x-data="{ dark: localStorage.getItem('gpx-theme') === 'dark' || (!localStorage.getItem('gpx-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches) }" x-init="document.documentElement.classList.toggle('dark', dark)">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('page_title_login') ?></title>
    <script>(function(){var t=localStorage.getItem('gpx-theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark');})();</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
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
                <i data-lucide="lock" class="w-5 h-5"></i>
                <?= t('btn_login') ?>
            </h2>

            <label class="block mb-3">
                <span class="block text-xs uppercase tracking-wider text-forest-700/65 dark:text-sand-100/65 mb-1.5"><?= t('login_user') ?></span>
                <input type="text" name="user" required autofocus
                       class="w-full px-3 py-2.5 rounded-md bg-white dark:bg-forest-700 border border-sand-200 dark:border-forest-600 focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500/30 transition">
            </label>

            <label class="block mb-4">
                <span class="block text-xs uppercase tracking-wider text-forest-700/65 dark:text-sand-100/65 mb-1.5"><?= t('login_pass') ?></span>
                <input type="password" name="pass" required
                       class="w-full px-3 py-2.5 rounded-md bg-white dark:bg-forest-700 border border-sand-200 dark:border-forest-600 focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500/30 transition">
            </label>

            <button type="submit" class="btn-outdoor btn-outdoor-primary w-full justify-center py-2.5">
                <i data-lucide="log-in" class="w-4 h-4"></i>
                <?= t('btn_login') ?>
            </button>

            <?php if ($error): ?>
                <div class="mt-4 px-3 py-2 rounded-md bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm flex items-start gap-2">
                    <i data-lucide="alert-circle" class="w-4 h-4 mt-0.5 shrink-0"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <div class="mt-5 text-center">
                <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-600 hover:text-terracotta-500 transition-colors">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    <?= t('back_short') ?>
                </a>
            </div>
        </form>
    </div>
</div>

<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
