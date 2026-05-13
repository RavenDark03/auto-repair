<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mechanix_ui.php';

requireLogin();

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$flash = $_SESSION['pending_payment_notice'] ?? null;
unset($_SESSION['pending_payment_notice']);

$registration = null;
$billing = null;

try {
    $pdo = Database::getInstance();

    $tenantStmt = $pdo->prepare("
        SELECT tenant_id, status, business_name
        FROM tenants
        WHERE tenant_id = :tenant_id
        LIMIT 1
    ");
    $tenantStmt->execute(['tenant_id' => $tenantId]);
    $tenant = $tenantStmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    if (($tenant['status'] ?? '') === 'active') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }

    if (($tenant['status'] ?? '') !== 'pending_payment') {
        $_SESSION['error_message'] = 'Your workspace is not awaiting payment.';
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    $regStmt = $pdo->prepare("
        SELECT
            tr.registration_id,
            tr.registration_status,
            tr.business_name,
            tr.billing_cycle,
            sp.plan_name
        FROM tenant_registrations tr
        INNER JOIN subscription_plans sp ON sp.plan_id = tr.selected_plan_id
        WHERE tr.provisioned_tenant_id = :tenant_id
        ORDER BY tr.registration_id DESC
        LIMIT 1
    ");
    $regStmt->execute(['tenant_id' => $tenantId]);
    $registration = $regStmt->fetch(PDO::FETCH_ASSOC);

    if ($registration) {
        $billStmt = $pdo->prepare("
            SELECT
                billing_request_id,
                billing_status,
                total_amount,
                currency,
                paymongo_checkout_url,
                due_date
            FROM billing_requests
            WHERE registration_id = :registration_id
            ORDER BY billing_request_id DESC
            LIMIT 1
        ");
        $billStmt->execute(['registration_id' => (int) $registration['registration_id']]);
        $billing = $billStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (PDOException $e) {
    $flash = 'Could not load billing status. Please try again shortly.';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete payment - MECHANIX</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL . '/assets/css/styles.css', ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="page-shell">
    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <div class="brand-mark">M</div>
                <div class="brand-text">
                    <h1>MECHANIX</h1>
                    <p>Complete your subscription</p>
                </div>
            </div>
            <div class="nav-actions">
                <?= mechanix_theme_toggle_button() ?>
            </div>
        </div>
    </header>

    <main class="register-page">
        <div class="container" style="max-width: 640px; margin: 2rem auto;">
            <?php if ($flash !== null): ?>
                <div class="reg-alert reg-alert-error"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="form-section">
                <div class="form-section-header">
                    <span class="form-section-num">!</span>
                    <div>
                        <h3>Payment required</h3>
                        <p>Your application was approved. Pay the subscription invoice to activate the full admin dashboard.</p>
                    </div>
                </div>

                <?php if ($registration): ?>
                    <p><strong><?= htmlspecialchars((string) $registration['business_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        &middot; <?= htmlspecialchars((string) $registration['plan_name'], ENT_QUOTES, 'UTF-8') ?>
                        (<?= htmlspecialchars($registration['billing_cycle'] === 'yearly' ? 'Yearly' : 'Monthly', ENT_QUOTES, 'UTF-8') ?>)</p>
                    <p class="text-muted small">Registration status: <strong><?= htmlspecialchars((string) $registration['registration_status'], ENT_QUOTES, 'UTF-8') ?></strong></p>
                <?php else: ?>
                    <p class="text-muted">No registration record is linked to this workspace yet. Contact MECHANIX support.</p>
                <?php endif; ?>

                <?php if ($billing): ?>
                    <div class="reg-alert reg-alert-success" style="margin-top: 1rem;">
                        <p><strong>Invoice total:</strong>
                            <?= htmlspecialchars((string) $billing['currency'], ENT_QUOTES, 'UTF-8') ?>
                            <?= number_format((float) $billing['total_amount'], 2) ?></p>
                        <?php if (!empty($billing['due_date'])): ?>
                            <p class="small" style="margin: 0;">Due <?= htmlspecialchars((string) $billing['due_date'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($billing['paymongo_checkout_url']) && ($billing['billing_status'] ?? '') !== 'paid'): ?>
                        <p style="margin-top: 1.25rem;">
                            <a class="btn btn-primary" href="<?= htmlspecialchars((string) $billing['paymongo_checkout_url'], ENT_QUOTES, 'UTF-8') ?>" rel="noopener noreferrer">Pay with PayMongo</a>
                        </p>
                    <?php elseif (($billing['billing_status'] ?? '') === 'paid'): ?>
                        <p class="small">Payment received. The super admin will finalize your workspace shortly.</p>
                    <?php else: ?>
                        <p class="small text-muted">Your invoice is being prepared. Refresh this page after MECHANIX sends checkout, or contact support if it takes more than one business day.</p>
                    <?php endif; ?>
                <?php elseif ($registration): ?>
                    <p class="small text-muted" style="margin-top: 1rem;">No billing draft yet. MECHANIX will generate your invoice after plan review.</p>
                <?php endif; ?>

                <p style="margin-top: 2rem;">
                    <a class="btn btn-secondary" href="<?= htmlspecialchars(BASE_URL . '/logout.php', ENT_QUOTES, 'UTF-8') ?>">Log out</a>
                </p>
            </div>
        </div>
    </main>
    <script src="<?= htmlspecialchars(BASE_URL . '/assets/js/theme.js?v=2', ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
