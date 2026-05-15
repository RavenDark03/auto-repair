<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mechanix_ui.php';
require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../includes/tenant_branding.php';

if (isSuperAdminLoggedIn()) {
    header('Location: ' . mechanix_url_path('/superadmin/dashboard.php'));
    exit;
}

if (isset($_SESSION['user_id'])) {
    if (!empty($_SESSION['must_change_password'])) {
        header('Location: ' . mechanix_url_path('/change_password.php'));
        exit;
    }
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: ' . mechanix_url_path('/admin/dashboard.php'));
        exit;
    }
}

$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';

try {
    $pdo = Database::getInstance();
    $brand = tenant_branding_resolve_public($pdo, $slug);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Unable to load login.';
    exit;
}

if (!$brand) {
    header('Location: ' . mechanix_url_path('/login.php'));
    exit;
}

$urls = tenant_branding_public_urls((string) $brand['public_slug']);
$logoHref = tenant_branding_logo_public_href(isset($brand['logo_path']) ? (string) $brand['logo_path'] : null);

$bnInitialLogin = (string) $brand['business_name'];
$letterLogin = function_exists('mb_substr') ? mb_substr($bnInitialLogin, 0, 1, 'UTF-8') : substr($bnInitialLogin, 0, 1);

$primary = htmlspecialchars((string) $brand['primary_color'], ENT_QUOTES, 'UTF-8');
$accent = htmlspecialchars((string) $brand['accent_color'], ENT_QUOTES, 'UTF-8');
$bg = htmlspecialchars((string) $brand['background_color'], ENT_QUOTES, 'UTF-8');
$fg = htmlspecialchars((string) $brand['text_color'], ENT_QUOTES, 'UTF-8');
$name = htmlspecialchars((string) $brand['business_name'], ENT_QUOTES, 'UTF-8');
$slugField = htmlspecialchars((string) $brand['public_slug'], ENT_QUOTES, 'UTF-8');
$formAction = htmlspecialchars(mechanix_url_path('/actions/login_process.php'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff login — <?= $name ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mechanix_url_path('/assets/css/styles.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        :root {
            --brand-primary: <?= $primary ?>;
            --brand-accent: <?= $accent ?>;
            --brand-bg: <?= $bg ?>;
            --brand-text: <?= $fg ?>;
        }
        body.page-shell {
            background: var(--brand-bg) !important;
            color: var(--brand-text);
        }
        .auth-page--brand .auth-card {
            border-color: color-mix(in srgb, var(--brand-text) 12%, transparent);
            box-shadow: 0 18px 48px color-mix(in srgb, var(--brand-text) 10%, transparent);
        }
        .tenant-login-logo {
            width: 64px;
            height: 64px;
            object-fit: contain;
            border-radius: 14px;
            margin-bottom: 0.75rem;
        }
        .tenant-login-mark {
            width: 64px;
            height: 64px;
            border-radius: 14px;
            background: color-mix(in srgb, var(--brand-primary) 20%, var(--brand-bg));
            color: var(--brand-primary);
            display: grid;
            place-items: center;
            font-weight: 800;
            font-size: 1.35rem;
            margin-bottom: 0.75rem;
        }
        .btn-primary.btn-full {
            background: var(--brand-primary) !important;
            border-color: var(--brand-primary) !important;
            color: #111 !important;
        }
        .auth-links a { color: var(--brand-primary); font-weight: 600; }
    </style>
</head>
<body class="page-shell">
    <main class="auth-page auth-page--brand">
        <div class="auth-card">
            <?php if ($logoHref !== null): ?>
                <img class="tenant-login-logo" src="<?= htmlspecialchars($logoHref, ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php else: ?>
                <div class="tenant-login-mark"><?= htmlspecialchars($letterLogin, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="auth-card-eyebrow">
                <span class="eyebrow"><?= $name ?></span>
            </div>
            <h2>Staff sign-in</h2>
            <p>Use your shop username and password for this workspace only.</p>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error" style="margin-bottom:1rem;">
                    <?= htmlspecialchars((string) $_SESSION['error_message'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <form action="<?= $formAction ?>" method="POST">
                <input type="hidden" name="branding_slug" value="<?= $slugField ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input class="form-control" type="text" id="username" name="username" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="pw-input-wrap">
                        <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
                        <button type="button" class="pw-toggle-btn" data-pw-target="password" aria-label="Show password">
                            <svg class="pw-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="pw-eye-off" hidden xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-8-10-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-full">Sign in</button>
            </form>

            <div class="auth-links">
                <a href="<?= htmlspecialchars($urls['pretty_home'], ENT_QUOTES, 'UTF-8') ?>">Workspace home</a>
                ·
                <a href="<?= htmlspecialchars(mechanix_url_path('/login.php'), ENT_QUOTES, 'UTF-8') ?>">Global login</a>
            </div>
        </div>
    </main>
    <script src="<?= htmlspecialchars(mechanix_url_path('/assets/js/theme.js?v=3'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
