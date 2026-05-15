<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/tenant_branding.php';

$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';

try {
    $pdo = Database::getInstance();
    $brand = tenant_branding_resolve_public($pdo, $slug);
    $services = $brand ? tenant_branding_services_for($pdo, (int) $brand['tenant_id']) : [];
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Unable to load this workspace.';
    exit;
}

if (!$brand) {
    http_response_code(404);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Workspace not found</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 24px; font-family: system-ui, sans-serif; background: #f4f4f5; color: #18181b; }
        .card { max-width: 360px; text-align: center; padding: 2rem 1.75rem; border-radius: 20px; background: #fff; border: 1px solid #e4e4e7; box-shadow: 0 24px 60px rgba(24, 24, 27, 0.06); }
        p { margin: 0; font-size: 0.95rem; color: #52525b; line-height: 1.55; }
        strong { display: block; font-size: 1rem; margin-bottom: 0.35rem; color: #18181b; letter-spacing: -0.02em; }
    </style>
</head>
<body>
    <div class="card">
        <strong>This workspace could not be found.</strong>
        <p>Check the link or ask your shop admin for an updated URL.</p>
    </div>
</body>
</html>
    <?php
    exit;
}

$urls = tenant_branding_public_urls((string) $brand['public_slug']);
$logoHref = tenant_branding_logo_public_href(isset($brand['logo_path']) ? (string) $brand['logo_path'] : null);

$bnInitial = (string) $brand['business_name'];
$letterMark = function_exists('mb_substr') ? mb_substr($bnInitial, 0, 1, 'UTF-8') : substr($bnInitial, 0, 1);

$primary = htmlspecialchars((string) $brand['primary_color'], ENT_QUOTES, 'UTF-8');
$accent = htmlspecialchars((string) $brand['accent_color'], ENT_QUOTES, 'UTF-8');
$bg = htmlspecialchars((string) $brand['background_color'], ENT_QUOTES, 'UTF-8');
$fg = htmlspecialchars((string) $brand['text_color'], ENT_QUOTES, 'UTF-8');
$name = htmlspecialchars((string) $brand['business_name'], ENT_QUOTES, 'UTF-8');

$ios = trim((string) ($brand['mobile_app_ios_url'] ?? ''));
$android = trim((string) ($brand['mobile_app_android_url'] ?? ''));

$loginHref = htmlspecialchars($urls['pretty_login'], ENT_QUOTES, 'UTF-8');
$hasMobile = ($ios !== '' || $android !== '');

$androidLabel = 'Download for Android';
if ($android !== '') {
    $au = strtolower($android);
    if (str_contains($au, 'play.google.com')) {
        $androidLabel = 'Google Play';
    } elseif (str_contains($au, '.apk') || str_contains($au, '/apk')) {
        $androidLabel = 'Download APK';
    }
}

$pageTitle = $name . ' — Workspace';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=Fraunces:ital,opsz,wght@0,9..144,600;0,9..144,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --p: <?= $primary ?>;
            --a: <?= $accent ?>;
            --bg: <?= $bg ?>;
            --fg: <?= $fg ?>;
            --fg-muted: color-mix(in srgb, var(--fg) 72%, var(--bg));
            --surface-raised: color-mix(in srgb, var(--fg) 7%, var(--bg));
            --border-soft: color-mix(in srgb, var(--fg) 14%, var(--bg));
            --btn-solid-fg: #141414;
            --font-display: "Fraunces", ui-serif, Georgia, serif;
            --font-ui: "DM Sans", ui-sans-serif, system-ui, sans-serif;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html {
            scroll-behavior: smooth;
        }
        body {
            margin: 0;
            min-height: 100dvh;
            font-family: var(--font-ui);
            background: var(--bg);
            color: var(--fg);
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
        }

        /* Top bar — breathing room from viewport top (safe areas) */
        .tb-nav {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            max-width: 1120px;
            margin: 0 auto;
            padding:
                calc(14px + env(safe-area-inset-top, 0px))
                clamp(20px, 4vw, 32px)
                18px;
            background: color-mix(in srgb, var(--bg) 88%, transparent);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid color-mix(in srgb, var(--border-soft) 55%, transparent);
        }
        .tb-brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            min-height: 44px;
        }
        .tb-brand:focus-visible {
            outline: 2px solid var(--p);
            outline-offset: 4px;
            border-radius: 12px;
        }
        .tb-brand__logo {
            width: 44px;
            height: 44px;
            object-fit: contain;
            border-radius: 12px;
            background: color-mix(in srgb, var(--fg) 8%, var(--bg));
            padding: 6px;
            flex-shrink: 0;
        }
        .tb-brand__mark {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: -0.03em;
            color: var(--p);
            background: color-mix(in srgb, var(--p) 14%, var(--bg));
            flex-shrink: 0;
        }
        .tb-brand__name {
            font-weight: 700;
            font-size: 1.05rem;
            letter-spacing: -0.02em;
            color: var(--fg);
        }

        .tb-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            padding: 0 1.15rem;
            border-radius: 999px;
            font-family: var(--font-ui);
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 2px solid transparent;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease;
        }
        .tb-btn:focus-visible {
            outline: 2px solid var(--p);
            outline-offset: 3px;
        }
        .tb-btn--solid {
            background: var(--p);
            color: var(--btn-solid-fg);
            box-shadow: 0 2px 12px color-mix(in srgb, var(--p) 35%, transparent);
        }
        .tb-btn--solid:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 22px color-mix(in srgb, var(--p) 38%, transparent);
        }
        .tb-btn--outline {
            background: transparent;
            color: var(--fg);
            border-color: color-mix(in srgb, var(--fg) 38%, var(--bg));
        }
        .tb-btn--outline:hover {
            border-color: color-mix(in srgb, var(--fg) 55%, var(--bg));
            background: color-mix(in srgb, var(--fg) 6%, transparent);
        }

        .tb-wrap {
            max-width: 1120px;
            margin: 0 auto;
            padding: clamp(28px, 6vw, 56px) clamp(20px, 4vw, 32px) clamp(48px, 8vw, 80px);
        }

        .tb-hero {
            max-width: 640px;
            text-align: left;
        }
        .tb-powered {
            margin: 0 0 12px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            color: var(--fg-muted);
        }
        .tb-hero h1 {
            margin: 0 0 16px;
            font-family: var(--font-display);
            font-size: clamp(2rem, 5.5vw, 2.85rem);
            font-weight: 700;
            letter-spacing: -0.03em;
            line-height: 1.12;
            color: var(--fg);
        }
        .tb-lead {
            margin: 0 0 28px;
            font-size: 1.05rem;
            color: var(--fg-muted);
            max-width: 52ch;
        }
        .tb-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }

        /* Mobile access panel — reference layout */
        .tb-mobile {
            margin-top: clamp(40px, 8vw, 72px);
            border-radius: 24px;
            padding: clamp(22px, 4vw, 28px) clamp(22px, 4vw, 32px);
            background: var(--surface-raised);
            border: 1px solid var(--border-soft);
            box-shadow: 0 24px 48px color-mix(in srgb, var(--fg) 6%, transparent);
        }
        .tb-mobile__grid {
            display: grid;
            gap: 22px;
            align-items: center;
        }
        @media (min-width: 820px) {
            .tb-mobile__grid {
                grid-template-columns: 1fr auto;
                gap: 32px;
            }
        }
        .tb-mobile__kicker {
            margin: 0 0 8px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--fg-muted);
        }
        .tb-mobile h2 {
            margin: 0 0 12px;
            font-family: var(--font-display);
            font-size: clamp(1.35rem, 3vw, 1.65rem);
            font-weight: 700;
            letter-spacing: -0.025em;
            color: var(--fg);
        }
        .tb-mobile p {
            margin: 0;
            font-size: 0.94rem;
            color: var(--fg-muted);
            max-width: 48ch;
            line-height: 1.6;
        }
        .tb-mobile__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-start;
        }
        @media (min-width: 820px) {
            .tb-mobile__actions {
                justify-content: flex-end;
            }
        }

        .tb-services {
            margin-top: clamp(48px, 9vw, 88px);
            padding-top: clamp(32px, 6vw, 48px);
            border-top: 1px solid var(--border-soft);
        }
        .tb-services__label {
            margin: 0 0 14px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--fg-muted);
        }
        .tb-services ul {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 0;
        }
        .tb-services li {
            padding: 14px 0;
            border-bottom: 1px solid var(--border-soft);
            font-size: 0.92rem;
        }
        .tb-services li:last-child { border-bottom: none; }
        .tb-services strong {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--fg);
        }
        .tb-services span.desc {
            color: var(--fg-muted);
            font-size: 0.88rem;
        }

        .tb-footer {
            margin-top: clamp(40px, 8vw, 64px);
            padding-bottom: calc(16px + env(safe-area-inset-bottom, 0px));
            text-align: center;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: color-mix(in srgb, var(--fg-muted) 65%, var(--bg));
        }

        @media (prefers-reduced-motion: reduce) {
            .tb-btn { transition: none; }
            .tb-btn--solid:hover { transform: none; }
        }
    </style>
</head>
<body>
    <header class="tb-nav">
        <a class="tb-brand" href="#main" aria-label="<?= $name ?> — top of page">
            <?php if ($logoHref !== null): ?>
                <img class="tb-brand__logo" src="<?= htmlspecialchars($logoHref, ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php else: ?>
                <span class="tb-brand__mark" aria-hidden="true"><?= htmlspecialchars($letterMark, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <span class="tb-brand__name"><?= $name ?></span>
        </a>
        <a class="tb-btn tb-btn--solid" href="<?= $loginHref ?>">Staff login</a>
    </header>

    <div class="tb-wrap">
        <main id="main">
            <section class="tb-hero" aria-labelledby="welcome-heading">
                <p class="tb-powered">Powered by MECHANIX</p>
                <h1 id="welcome-heading">Welcome to <?= $name ?></h1>
                <p class="tb-lead">
                    This is your shop&rsquo;s branded portal for team access, operations, and customer experiences.
                </p>
                <div class="tb-hero-actions">
                    <a class="tb-btn tb-btn--solid" href="<?= $loginHref ?>">Staff sign in</a>
                    <?php if ($hasMobile): ?>
                        <a class="tb-btn tb-btn--outline" href="#mobile-access">Download app</a>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($hasMobile): ?>
                <section id="mobile-access" class="tb-mobile" aria-labelledby="mobile-heading">
                    <div class="tb-mobile__grid">
                        <div>
                            <p class="tb-mobile__kicker">Mobile access</p>
                            <h2 id="mobile-heading">Download the app</h2>
                            <p>
                                Install from the App Store or Google Play, or use your team&rsquo;s direct Android link (including APK).
                                When you&rsquo;re ready, sign in with staff credentials from this workspace.
                            </p>
                        </div>
                        <div class="tb-mobile__actions">
                            <?php if ($ios !== ''): ?>
                                <a class="tb-btn tb-btn--solid" href="<?= htmlspecialchars($ios, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">App Store</a>
                            <?php endif; ?>
                            <?php if ($android !== ''): ?>
                                <a class="tb-btn tb-btn--solid" href="<?= htmlspecialchars($android, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($androidLabel, ENT_QUOTES, 'UTF-8') ?></a>
                            <?php endif; ?>
                            <a class="tb-btn tb-btn--outline" href="<?= $loginHref ?>">Staff sign in</a>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($services !== []): ?>
                <section class="tb-services" aria-labelledby="svc-heading">
                    <p id="svc-heading" class="tb-services__label">Services</p>
                    <ul>
                        <?php foreach ($services as $svc): ?>
                            <li>
                                <strong><?= htmlspecialchars($svc['service_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($svc['service_description'] !== null): ?>
                                    <span class="desc"><?= htmlspecialchars($svc['service_description'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </main>

        <footer class="tb-footer">MECHANIX</footer>
    </div>
</body>
</html>
