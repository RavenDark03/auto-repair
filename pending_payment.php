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

$paymentCheckoutUrl = null;
$tenantStatus = 'pending_payment';
$planName = '';
$billingCycle = '';
$businessName = $_SESSION['business_name'] ?? '';

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
        .payment-gate-page {
            min-height: 100vh;
            background: #0f1117;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .payment-gate-card {
            background: linear-gradient(145deg, #1a1d2e, #12141f);
            border: 1px solid #2d3148;
            border-radius: 20px;
            padding: 48px 44px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            box-shadow: 0 32px 80px rgba(0,0,0,.6);
            animation: pg-in .35s cubic-bezier(.34,1.56,.64,1) both;
        }
        @keyframes pg-in {
            from { opacity:0; transform:translateY(20px); }
            to   { opacity:1; transform:none; }
        }
        .pg-brand-mark {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, #6c63ff, #4f46e5);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 800;
            color: #fff;
            margin: 0 auto 20px;
            box-shadow: 0 8px 24px rgba(108,99,255,.4);
        }
        .pg-brand-name {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 3px;
            color: #6c63ff;
            text-transform: uppercase;
            margin-bottom: 24px;
        }
        .pg-card-title {
            font-size: 24px;
            font-weight: 700;
            color: #f1f5f9;
            margin: 0 0 10px;
        }
        .pg-card-body {
            font-size: 14px;
            color: #94a3b8;
            line-height: 1.7;
            margin: 0 0 28px;
        }
        .pg-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #1e2235;
            font-size: 13px;
            color: #64748b;
        }
        .pg-info-row:last-of-type { border-bottom: none; }
        .pg-info-row span { color: #c7d2fe; font-weight: 600; }
        .pg-info-box {
            background: #12141e;
            border: 1px solid #2d3148;
            border-radius: 10px;
            padding: 12px 16px;
            margin: 20px 0 28px;
        }
        .pg-pay-btn {
            display: inline-block;
            padding: 15px 40px;
            background: linear-gradient(135deg, #6c63ff, #4f46e5);
            color: #fff !important;
            text-decoration: none !important;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            box-shadow: 0 8px 24px rgba(108,99,255,.35);
            transition: transform .15s, box-shadow .15s;
            border: none; cursor: pointer;
        }
        .pg-pay-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(108,99,255,.5);
        }
        .pg-logout {
            display: block;
            margin-top: 20px;
            font-size: 13px;
            color: #475569;
            text-decoration: none;
        }
        .pg-logout:hover { color: #94a3b8; }
    </style>
</head>
<body style="margin:0;background:#0f1117;">
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
                    &#128179; Pay Subscription
                </a>
            <?php else: ?>
                <p style="color:#f87171;font-size:13px;margin-bottom:16px;">
                    Your payment link is being prepared. Please check your email or refresh this page shortly.
                </p>
                <button onclick="location.reload()" class="pg-pay-btn">&#128260; Refresh Page</button>
            <?php endif; ?>

            <a href="<?= htmlspecialchars(BASE_URL . '/logout.php', ENT_QUOTES, 'UTF-8') ?>" class="pg-logout">
                Log out
            </a>
        </div>
    </div>
    <script src="<?= htmlspecialchars(BASE_URL . '/assets/js/theme.js?v=2', ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
