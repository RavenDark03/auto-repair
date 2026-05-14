<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mechanix_paymongo_activation.php';
require_once __DIR__ . '/includes/mechanix_ui.php';

requireLogin();

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);

$paymentCheckoutUrl = null;
$tenantStatus = 'pending_payment';
$planName = '';
$billingCycle = '';
$businessName = $_SESSION['business_name'] ?? '';

try {
    $pdo = Database::getInstance();

    mechanix_reconcile_paymongo_for_pending_tenant($pdo, $tenantId, false);

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

    $tenantStatus = $tenant['status'] ?? 'pending_payment';
    $businessName = $tenant['business_name'] ?? $businessName;

    // If already active, redirect to dashboard
    if ($tenantStatus === 'active') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }

    // Fetch checkout URL from billing request
    $billingStmt = $pdo->prepare("
        SELECT
            br.paymongo_checkout_url,
            br.billing_status,
            br.total_amount,
            br.currency,
            sp.plan_name,
            tr.billing_cycle
        FROM tenant_registrations tr
        INNER JOIN subscription_plans sp ON sp.plan_id = tr.selected_plan_id
        LEFT JOIN billing_requests br
            ON br.registration_id = tr.registration_id
           AND br.billing_status != 'paid'
        WHERE tr.provisioned_tenant_id = :tenant_id
           OR tr.converted_tenant_id   = :tenant_id
        ORDER BY br.billing_request_id DESC
        LIMIT 1
    ");
    $billingStmt->execute(['tenant_id' => $tenantId]);
    $billing = $billingStmt->fetch(PDO::FETCH_ASSOC);

    if ($billing) {
        $paymentCheckoutUrl = $billing['paymongo_checkout_url'] ?? null;
        $planName           = $billing['plan_name'] ?? '';
        $billingCycle       = $billing['billing_cycle'] ?? '';
    }
} catch (PDOException $e) {
    // Fall through — show the page with whatever info we have
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Payment - MECHANIX</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL . '/assets/css/styles.css', ENT_QUOTES, 'UTF-8') ?>">
    <style>
        .pending-payment-shell .payment-gate-page {
            min-height: calc(100vh - 76px);
            background: color-mix(in srgb, var(--bg) 96%, transparent);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .payment-gate-card {
            background: color-mix(in srgb, var(--bg-soft) 96%, transparent);
            border: 1px solid var(--border-strong);
            border-radius: var(--card-radius);
            padding: 48px 44px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            box-shadow: var(--shadow);
            animation: pg-in .35s cubic-bezier(.34,1.56,.64,1) both;
        }
        @keyframes pg-in {
            from { opacity:0; transform:translateY(20px); }
            to   { opacity:1; transform:none; }
        }
        .pg-brand-mark {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, var(--border-strong), var(--text-soft));
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 800;
            color: var(--bg-soft);
            margin: 0 auto 20px;
            box-shadow: var(--shadow-soft);
        }
        .pg-brand-name {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 3px;
            color: var(--text-soft);
            text-transform: uppercase;
            margin-bottom: 24px;
        }
        .pg-card-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            margin: 0 0 10px;
        }
        .pg-card-body {
            font-size: 14px;
            color: var(--text-soft);
            line-height: 1.7;
            margin: 0 0 28px;
        }
        .pg-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            color: var(--text-soft);
        }
        .pg-info-row:last-of-type { border-bottom: none; }
        .pg-info-row span { color: var(--text); font-weight: 600; }
        .pg-info-box {
            background: color-mix(in srgb, var(--bg-soft) 90%, transparent);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            margin: 20px 0 28px;
        }
        .pg-pay-btn {
            display: inline-block;
            padding: 15px 40px;
            background: var(--button-bg);
            color: var(--button-text) !important;
            text-decoration: none !important;
            border-radius: var(--radius-md);
            font-size: 16px;
            font-weight: 700;
            box-shadow: var(--shadow-soft);
            transition: transform .15s, box-shadow .15s, background var(--transition);
            border: none; cursor: pointer;
        }
        .pg-pay-btn:hover {
            transform: translateY(-2px);
            background: var(--button-hover);
        }
        .pg-logout {
            display: block;
            margin-top: 20px;
            font-size: 13px;
            color: var(--text-soft);
            text-decoration: none;
        }
        .pg-logout:hover { color: var(--text); }
    </style>
</head>
<body class="page-shell pending-payment-shell">
    <?php
    $mechanixPublicTopbarVariant = 'billing_pending';
    require __DIR__ . '/includes/partials/mechanix_public_topbar.php';
    ?>
    <div class="payment-gate-page">
        <div class="payment-gate-card">
            <div class="pg-brand-mark">M</div>
            <div class="pg-brand-name">MECHANIX</div>
            <h1 class="pg-card-title">Complete Your Subscription</h1>
            <p class="pg-card-body">
                Your business has been verified! Pay your subscription to unlock your full MECHANIX workspace.
            </p>

            <?php if ($businessName || $planName): ?>
            <div class="pg-info-box">
                <?php if ($businessName): ?>
                <div class="pg-info-row">
                    Business <span><?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endif; ?>
                <?php if ($planName): ?>
                <div class="pg-info-row">
                    Plan <span><?= htmlspecialchars($planName, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endif; ?>
                <?php if ($billingCycle): ?>
                <div class="pg-info-row">
                    Billing <span><?= htmlspecialchars(ucfirst($billingCycle), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($paymentCheckoutUrl)): ?>
                <a href="<?= htmlspecialchars($paymentCheckoutUrl, ENT_QUOTES, 'UTF-8') ?>"
                   class="pg-pay-btn"
                   id="pay-subscription-btn"
                   rel="noopener noreferrer">
                    Pay subscription
                </a>
            <?php else: ?>
                <p style="color:#f87171;font-size:13px;margin-bottom:16px;">
                    Your payment link is being prepared. Please check your email or refresh this page shortly.
                </p>
                <button onclick="location.reload()" class="pg-pay-btn">Refresh page</button>
            <?php endif; ?>

            <a href="<?= htmlspecialchars(BASE_URL . '/logout.php', ENT_QUOTES, 'UTF-8') ?>" class="pg-logout">
                Log out
            </a>
        </div>
    </div>
    <script src="<?= htmlspecialchars(BASE_URL . '/assets/js/theme.js?v=2', ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
