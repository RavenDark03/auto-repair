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
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Not found</title></head>
<body style="font-family:system-ui,sans-serif;padding:2rem;text-align:center;">Workspace not found.</body></html>
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $name ?> — MECHANIX</title>
    <style>
        :root {
            --tb-primary: <?= $primary ?>;
            --tb-accent: <?= $accent ?>;
            --tb-bg: <?= $bg ?>;
            --tb-text: <?= $fg ?>;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            background: var(--tb-bg);
            color: var(--tb-text);
            line-height: 1.5;
        }
        .wrap {
            max-width: 560px;
            margin: 0 auto;
            padding: clamp(1.5rem, 4vw, 2.5rem) 1.25rem 3rem;
        }
        .logo {
            width: 96px;
            height: 96px;
            object-fit: contain;
            border-radius: 16px;
            background: color-mix(in srgb, var(--tb-text) 6%, transparent);
            padding: 8px;
            margin-bottom: 1rem;
        }
        .mark {
            width: 96px;
            height: 96px;
            border-radius: 16px;
            background: color-mix(in srgb, var(--tb-primary) 22%, var(--tb-bg));
            color: var(--tb-primary);
            display: grid;
            place-items: center;
            font-weight: 800;
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        h1 { font-size: clamp(1.5rem, 4vw, 2rem); letter-spacing: -0.03em; margin: 0 0 0.35rem; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin: 1.5rem 0; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 18px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid color-mix(in srgb, var(--tb-text) 14%, transparent);
        }
        .btn-primary {
            background: var(--tb-primary);
            color: #111;
            border-color: transparent;
        }
        .btn-secondary {
            background: transparent;
            color: var(--tb-text);
            border-color: color-mix(in srgb, var(--tb-accent) 55%, transparent);
        }
        .stores { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 1rem; }
        .stores a {
            font-size: 0.9rem;
            color: var(--tb-primary);
            font-weight: 600;
        }
        ul.services { margin: 1rem 0 0; padding-left: 1.2rem; }
        ul.services li { margin-bottom: 0.35rem; }
        .muted { opacity: 0.72; font-size: 0.92rem; }
        footer { margin-top: 2rem; font-size: 0.82rem; opacity: 0.55; }
    </style>
</head>
<body>
<div class="wrap">
    <?php if ($logoHref !== null): ?>
        <img class="logo" src="<?= htmlspecialchars($logoHref, ENT_QUOTES, 'UTF-8') ?>" alt="">
    <?php else: ?>
        <div class="mark" aria-hidden="true"><?= htmlspecialchars($letterMark, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <h1><?= $name ?></h1>
    <p class="muted">Staff workspace · Powered by MECHANIX</p>

    <div class="actions">
        <a class="btn btn-primary" href="<?= htmlspecialchars($urls['pretty_login'], ENT_QUOTES, 'UTF-8') ?>">Staff login</a>
        <?php if ($android !== '' || $ios !== ''): ?>
            <span class="btn btn-secondary" style="cursor:default;border-style:dashed;">Download app</span>
        <?php endif; ?>
    </div>

    <?php if ($android !== '' || $ios !== ''): ?>
        <div class="stores">
            <?php if ($ios !== ''): ?>
                <a href="<?= htmlspecialchars($ios, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">App Store</a>
            <?php endif; ?>
            <?php if ($android !== ''): ?>
                <a href="<?= htmlspecialchars($android, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Google Play</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($services !== []): ?>
        <h2 style="font-size:1.05rem;margin-top:2rem;">Services</h2>
        <ul class="services">
            <?php foreach ($services as $svc): ?>
                <li>
                    <strong><?= htmlspecialchars($svc['service_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php if ($svc['service_description'] !== null): ?>
                        — <?= htmlspecialchars($svc['service_description'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <footer>Public link also works as <?= htmlspecialchars($urls['compat_home'], ENT_QUOTES, 'UTF-8') ?></footer>
</div>
</body>
</html>
