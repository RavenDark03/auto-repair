<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
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
$homeHref = htmlspecialchars($urls['pretty_home'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — <?= $name ?></title>
    <style>
        :root {
            --p: <?= $primary ?>;
            --a: <?= $accent ?>;
            --bg: <?= $bg ?>;
            --fg: <?= $fg ?>;
            --fg-soft: color-mix(in srgb, var(--fg) 56%, var(--bg));
            --line: color-mix(in srgb, var(--fg) 10%, var(--bg));
            --card: color-mix(in srgb, var(--bg) 92%, #fff);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(1.25rem, 5vw, 2.5rem);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background:
                radial-gradient(ellipse 90% 70% at 50% -35%, color-mix(in srgb, var(--p) 16%, transparent), transparent),
                var(--bg);
            color: var(--fg);
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            width: 100%;
            max-width: 380px;
            padding: clamp(1.65rem, 4vw, 2rem);
            border-radius: 22px;
            background: color-mix(in srgb, var(--card) 96%, transparent);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--line);
            box-shadow:
                0 1px 1px color-mix(in srgb, var(--fg) 6%, transparent),
                0 28px 60px color-mix(in srgb, var(--fg) 8%, transparent);
        }
        .brand-mark {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            margin-bottom: 1rem;
            object-fit: contain;
            display: block;
            padding: 8px;
            background: color-mix(in srgb, var(--fg) 5%, var(--bg));
        }
        .brand-letter {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            margin-bottom: 1rem;
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: 1.35rem;
            letter-spacing: -0.03em;
            color: var(--p);
            background: color-mix(in srgb, var(--p) 14%, var(--bg));
        }
        .eyebrow {
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--fg-soft);
            margin-bottom: 0.35rem;
        }
        h1 {
            margin: 0 0 0.35rem;
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: -0.035em;
        }
        .sub {
            margin: 0 0 1.35rem;
            font-size: 0.875rem;
            color: var(--fg-soft);
        }
        .alert {
            padding: 0.65rem 0.85rem;
            border-radius: 12px;
            font-size: 0.84rem;
            margin-bottom: 1rem;
            border: 1px solid color-mix(in srgb, #b42318 28%, var(--line));
            background: color-mix(in srgb, #fef2f2 65%, var(--bg));
            color: #7f1d1d;
        }
        label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--fg-soft);
            margin-bottom: 0.35rem;
        }
        .field {
            margin-bottom: 1rem;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 0.72rem 0.85rem;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: color-mix(in srgb, var(--bg) 75%, #fff);
            color: var(--fg);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        input:focus {
            border-color: color-mix(in srgb, var(--p) 55%, var(--line));
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--p) 22%, transparent);
        }
        .pw-wrap {
            position: relative;
        }
        .pw-wrap input {
            padding-right: 2.75rem;
        }
        .pw-toggle-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            padding: 6px;
            cursor: pointer;
            border-radius: 8px;
            color: var(--fg-soft);
            display: grid;
            place-items: center;
        }
        .pw-toggle-btn:hover {
            background: color-mix(in srgb, var(--fg) 6%, transparent);
            color: var(--fg);
        }
        .pw-toggle-btn svg {
            width: 18px;
            height: 18px;
        }
        button[type="submit"] {
            width: 100%;
            margin-top: 0.25rem;
            padding: 0.88rem 1rem;
            border-radius: 999px;
            border: none;
            font-size: 0.94rem;
            font-weight: 600;
            cursor: pointer;
            background: var(--p);
            color: #141414;
            box-shadow:
                0 1px 2px color-mix(in srgb, var(--fg) 12%, transparent),
                0 12px 28px color-mix(in srgb, var(--p) 32%, transparent);
            transition: transform 0.15s ease;
        }
        button[type="submit"]:hover {
            transform: translateY(-1px);
        }
        button[type="submit"]:active {
            transform: translateY(0);
        }
        .footer-links {
            margin-top: 1.35rem;
            padding-top: 1.1rem;
            border-top: 1px solid var(--line);
            display: flex;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
            font-size: 0.82rem;
        }
        .footer-links a {
            color: var(--p);
            font-weight: 600;
            text-decoration: none;
            border-bottom: 1px solid color-mix(in srgb, var(--p) 30%, transparent);
            padding-bottom: 1px;
        }
        .footer-links a:hover {
            border-bottom-color: var(--p);
        }
        @media (prefers-reduced-motion: reduce) {
            button[type="submit"] { transition: none; }
            button[type="submit"]:hover { transform: none; }
        }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($logoHref !== null): ?>
            <img class="brand-mark" src="<?= htmlspecialchars($logoHref, ENT_QUOTES, 'UTF-8') ?>" alt="">
        <?php else: ?>
            <div class="brand-letter"><?= htmlspecialchars($letterLogin, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <p class="eyebrow"><?= $name ?></p>
        <h1>Staff sign in</h1>
        <p class="sub">Use your shop credentials for this workspace.</p>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert" role="alert"><?= htmlspecialchars((string) $_SESSION['error_message'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <form action="<?= $formAction ?>" method="POST">
            <input type="hidden" name="branding_slug" value="<?= $slugField ?>">

            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autocomplete="username">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="pw-wrap">
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                    <button type="button" class="pw-toggle-btn" data-pw-target="password" aria-label="Show password">
                        <svg class="pw-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="pw-eye-off" hidden xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-8-10-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit">Sign in</button>
        </form>

        <div class="footer-links">
            <a href="<?= $homeHref ?>">← Workspace</a>
            <a href="<?= htmlspecialchars(mechanix_url_path('/login.php'), ENT_QUOTES, 'UTF-8') ?>">Other login</a>
        </div>
    </div>

    <script src="<?= htmlspecialchars(mechanix_url_path('/assets/js/theme.js?v=3'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
