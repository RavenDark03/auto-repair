<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/mechanix_ui.php';

$redirectUrl = mechanix_url_path('/login.php');
$btnText = 'Return to Login';

if (isset($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        $redirectUrl = mechanix_url_path('/admin/dashboard.php');
        $btnText = 'Go to Dashboard';
    } elseif (($_SESSION['role'] ?? '') === 'cashier') {
        $redirectUrl = mechanix_url_path('/admin/cashier_dashboard.php');
        $btnText = 'Go to Dashboard';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Success - MECHANIX</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mechanix_url_path('/assets/css/styles.css')) ?>">
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
                <?= mechanix_back_icon_link($redirectUrl, 'Back') ?>
            </div>
        </div>
    </header>
    <main class="auth-page auth-page--brand">
        <div class="auth-card">
            <h2>Payment submitted</h2>
            <p>Your payment was submitted through PayMongo. Final confirmation will appear once the payment status is verified by the platform.</p>
            <a href="<?= htmlspecialchars($redirectUrl) ?>" class="btn btn-primary btn-full"><?= htmlspecialchars($btnText) ?></a>
        </div>
    </main>

    <script src="<?= htmlspecialchars(mechanix_url_path('/assets/js/theme.js')) ?>"></script>
</body>
</html>
