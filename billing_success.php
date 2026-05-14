<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mechanix_paymongo_activation.php';
require_once __DIR__ . '/includes/mechanix_ui.php';

if (isset($_SESSION['tenant_id']) && PAYMONGO_SECRET_KEY !== '') {
    try {
        $pdoReco = Database::getInstance();
        mechanix_reconcile_paymongo_for_pending_tenant($pdoReco, (int) $_SESSION['tenant_id'], true);
    } catch (Throwable $ignoredReco) {}
}

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
    <?php
    $mechanixPublicTopbarVariant = 'back_link';
    $mechanixPublicTopbarBackHref = $redirectUrl;
    $mechanixPublicTopbarBackLabel = 'Back';
    require __DIR__ . '/includes/partials/mechanix_public_topbar.php';
    ?>
    <main class="auth-page auth-page--brand">
        <div class="auth-card">
            <h2>Payment submitted</h2>
            <p>Your payment was submitted through PayMongo. If you are signed in, we are finalizing your subscription now. Open your dashboard; if you still see a payment prompt, wait a few seconds and refresh once.</p>
            <a href="<?= htmlspecialchars($redirectUrl) ?>" class="btn btn-primary btn-full"><?= htmlspecialchars($btnText) ?></a>
        </div>
    </main>

    <script src="<?= htmlspecialchars(mechanix_url_path('/assets/js/theme.js')) ?>"></script>
</body>
</html>
