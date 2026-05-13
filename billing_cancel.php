<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/mechanix_ui.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled - MECHANIX</title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="page-shell">
    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <div class="brand-mark">M</div>
                <div class="brand-text">
                    <h1>MECHANIX</h1>
                    <p>Subscription-based auto repair SaaS</p>
                </div>
            </div>
            <div class="nav-actions">
                <?= mechanix_theme_toggle_button() ?>
                <?= mechanix_back_icon_link('index.php', 'Back to home') ?>
            </div>
        </div>
    </header>
    <main class="auth-page auth-page--brand">
        <div class="auth-card">
            <h2>Payment cancelled</h2>
            <p>The PayMongo checkout was cancelled or not completed. You can return to the platform and generate a new checkout link later.</p>
            <a href="index.php" class="btn btn-secondary btn-full">Return to Landing Page</a>
        </div>
    </main>

    <script src="assets/js/theme.js"></script>
</body>
</html>
