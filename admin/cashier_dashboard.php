<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';

requireTenantRoles(['cashier']);

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$fullName = $_SESSION['full_name'] ?? 'Cashier User';
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$currentRole = 'cashier';
$hasInvoicingFeature = tenantHasFeature('invoicing', $tenantId);
$hasPaymentsFeature = tenantHasFeature('payments', $tenantId);
$authErrorMessage = $_SESSION['auth_error'] ?? null;
if ($authErrorMessage === null) {
    $authErrorMessage = $_SESSION['error_message'] ?? null;
}
unset($_SESSION['auth_error']);
unset($_SESSION['error_message']);
$subscriptionNotice = null;

$metrics = [
    'unpaid_invoices' => 0,
    'partial_invoices' => 0,
    'open_receivables' => 0,
    'payments_today' => 0,
    'amount_collected_today' => 0,
    'payments_this_month' => 0,
];

$recentPayments = [];
$attentionInvoices = [];
$systemMessage = null;

try {
    $pdo = Database::getInstance();

    $tenantStmt = $pdo->prepare("
        SELECT
            t.business_name,
            s.plan,
            s.start_date,
            s.end_date,
            s.status AS subscription_status
        FROM tenants t
        LEFT JOIN subscriptions s
            ON s.tenant_id = t.tenant_id
           AND s.subscription_id = (
                SELECT MAX(subscription_id)
                FROM subscriptions
                WHERE tenant_id = t.tenant_id
            )
        WHERE t.tenant_id = :tenant_id
        LIMIT 1
    ");
    $tenantStmt->execute(['tenant_id' => $tenantId]);
    $tenantRow = $tenantStmt->fetch();

    if ($tenantRow && !empty($tenantRow['business_name'])) {
        $businessName = $tenantRow['business_name'];
        $_SESSION['business_name'] = $businessName;
        $subscriptionNotice = getTenantSubscriptionNotice(
            $tenantRow['plan'] ?? null,
            $tenantRow['subscription_status'] ?? null,
            $tenantRow['start_date'] ?? null,
            $tenantRow['end_date'] ?? null
        );
    }

    $metricQueries = [
        'unpaid_invoices' => $hasInvoicingFeature ? "
            SELECT COUNT(*)
            FROM invoices
            WHERE tenant_id = :tenant_id
              AND status = 'unpaid'
        " : null,
        'partial_invoices' => $hasInvoicingFeature ? "
            SELECT COUNT(*)
            FROM invoices
            WHERE tenant_id = :tenant_id
              AND status = 'partial'
        " : null,
        'open_receivables' => $hasInvoicingFeature ? "
            SELECT COALESCE(SUM(total - amount_paid), 0)
            FROM invoices
            WHERE tenant_id = :tenant_id
              AND status IN ('unpaid', 'partial')
        " : null,
        'payments_today' => $hasPaymentsFeature ? "
            SELECT COUNT(*)
            FROM payments
            WHERE tenant_id = :tenant_id
              AND DATE(payment_date) = CURDATE()
        " : null,
        'amount_collected_today' => $hasPaymentsFeature ? "
            SELECT COALESCE(SUM(amount), 0)
            FROM payments
            WHERE tenant_id = :tenant_id
              AND DATE(payment_date) = CURDATE()
        " : null,
        'payments_this_month' => $hasPaymentsFeature ? "
            SELECT COUNT(*)
            FROM payments
            WHERE tenant_id = :tenant_id
              AND DATE_FORMAT(payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        " : null,
    ];

    foreach ($metricQueries as $key => $sql) {
        if ($sql === null) {
            continue;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $metrics[$key] = $stmt->fetchColumn() ?: 0;
    }

    if ($hasPaymentsFeature) {
        $recentPaymentsStmt = $pdo->prepare("
            SELECT
                p.payment_id,
                p.amount,
                p.payment_method,
                p.reference_no,
                p.payment_date,
                i.invoice_id,
                i.invoice_no,
                i.status AS invoice_status,
                c.name AS customer_name
            FROM payments p
            INNER JOIN invoices i
                ON i.invoice_id = p.invoice_id
               AND i.tenant_id = p.tenant_id
            INNER JOIN jobs j
                ON j.job_id = i.job_id
               AND j.tenant_id = i.tenant_id
            INNER JOIN appointments a
                ON a.appointment_id = j.appointment_id
               AND a.tenant_id = j.tenant_id
            INNER JOIN customers c
                ON c.customer_id = a.customer_id
               AND c.tenant_id = a.tenant_id
            WHERE p.tenant_id = :tenant_id
            ORDER BY p.payment_date DESC, p.payment_id DESC
            LIMIT 6
        ");
        $recentPaymentsStmt->execute(['tenant_id' => $tenantId]);
        $recentPayments = $recentPaymentsStmt->fetchAll();
    }

    if ($hasInvoicingFeature) {
        $attentionInvoicesStmt = $pdo->prepare("
            SELECT
                i.invoice_id,
                i.invoice_no,
                i.total,
                i.amount_paid,
                i.status,
                i.due_date,
                i.created_at,
                c.name AS customer_name
            FROM invoices i
            INNER JOIN jobs j
                ON j.job_id = i.job_id
               AND j.tenant_id = i.tenant_id
            INNER JOIN appointments a
                ON a.appointment_id = j.appointment_id
               AND a.tenant_id = j.tenant_id
            INNER JOIN customers c
                ON c.customer_id = a.customer_id
               AND c.tenant_id = a.tenant_id
            WHERE i.tenant_id = :tenant_id
              AND i.status IN ('unpaid', 'partial')
            ORDER BY
                CASE
                    WHEN i.due_date IS NULL THEN 1
                    ELSE 0
                END,
                i.due_date ASC,
                i.invoice_id DESC
            LIMIT 8
        ");
        $attentionInvoicesStmt->execute(['tenant_id' => $tenantId]);
        $attentionInvoices = $attentionInvoicesStmt->fetchAll();
    }
} catch (PDOException $e) {
    $systemMessage = 'Cashier dashboard data could not be loaded: ' . $e->getMessage();
}

$showAnalytics = false;
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId, $currentRole);

function cashierDashboardCurrency($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function cashierDashboardDate($date, $withTime = false) {
    if (!$date) {
        return 'No date';
    }

    $timestamp = strtotime($date);
    if (!$timestamp) {
        return $date;
    }

    return $withTime ? date('M d, Y h:i A', $timestamp) : date('M d, Y', $timestamp);
}

function cashierDashboardBalance(array $invoice) {
    return (float) $invoice['total'] - (float) $invoice['amount_paid'];
}

function cashierDashboardMetricUrl($type) {
    switch ($type) {
        case 'unpaid_invoices':
            return 'invoices.php?status=unpaid&source_page=cashier_dashboard';
        case 'partial_invoices':
            return 'invoices.php?status=partial&source_page=cashier_dashboard';
        case 'open_receivables':
            return 'invoices.php?source_page=cashier_dashboard';
        case 'payments_today':
            return 'payments.php?date_filter=today&source_page=cashier_dashboard';
        case 'amount_collected_today':
            return 'payments.php?date_filter=today&source_page=cashier_dashboard';
        case 'payments_this_month':
            return 'payments.php?date_filter=month&source_page=cashier_dashboard';
        default:
            return 'cashier_dashboard.php';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Dashboard - MECHANIX</title>
    <?= mechanix_link_styles_tabler_workspace('../assets/css/') ?>
</head>
<body class="page-shell antialiased tenant-app">
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'cashier_dashboard.php', $showAnalytics) ?>

        <main class="dashboard-main" id="main-content" tabindex="-1">
            <?= renderTenantAdminTopbar('Cashier Dashboard', "Welcome back, {$fullName}. Here's today's collection view for {$businessName}.") ?>

            <?php if ($systemMessage !== null): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($systemMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($authErrorMessage !== null): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($authErrorMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if (!$hasInvoicingFeature || !$hasPaymentsFeature): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars(
                        !$hasInvoicingFeature && !$hasPaymentsFeature
                            ? 'Customer invoicing and payments are both disabled for this tenant right now. Cashier access is available, but billing operations are limited until the super admin enables those modules.'
                            : (!$hasInvoicingFeature
                                ? 'Customer invoicing is disabled for this tenant right now. Invoice creation and receivable follow-up are limited until the super admin enables the invoicing module.'
                                : 'Customer payments are disabled for this tenant right now. Payment recording and collection summaries are limited until the super admin enables the payments module.'),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
            <?php endif; ?>

            <section class="dashboard-grid">
                <a href="<?= htmlspecialchars($hasInvoicingFeature ? cashierDashboardMetricUrl('unpaid_invoices') : 'cashier_dashboard.php', ENT_QUOTES, 'UTF-8') ?>" class="metric-card metric-card-link">
                    <span>Unpaid Invoices</span>
                    <h3><?= number_format((int) $metrics['unpaid_invoices']) ?></h3>
                </a>
                <a href="<?= htmlspecialchars($hasInvoicingFeature ? cashierDashboardMetricUrl('partial_invoices') : 'cashier_dashboard.php', ENT_QUOTES, 'UTF-8') ?>" class="metric-card metric-card-link">
                    <span>Partial Invoices</span>
                    <h3><?= number_format((int) $metrics['partial_invoices']) ?></h3>
                </a>
                <a href="<?= htmlspecialchars($hasInvoicingFeature ? cashierDashboardMetricUrl('open_receivables') : 'cashier_dashboard.php', ENT_QUOTES, 'UTF-8') ?>" class="metric-card metric-card-link">
                    <span>Open Receivables</span>
                    <h3><?= htmlspecialchars(cashierDashboardCurrency($metrics['open_receivables']), ENT_QUOTES, 'UTF-8') ?></h3>
                </a>
                <a href="<?= htmlspecialchars($hasPaymentsFeature ? cashierDashboardMetricUrl('payments_today') : 'cashier_dashboard.php', ENT_QUOTES, 'UTF-8') ?>" class="metric-card metric-card-link">
                    <span>Payments Today</span>
                    <h3><?= number_format((int) $metrics['payments_today']) ?></h3>
                </a>
            </section>

            <section class="dashboard-grid dashboard-grid-secondary">
                <a href="<?= htmlspecialchars($hasPaymentsFeature ? cashierDashboardMetricUrl('amount_collected_today') : 'cashier_dashboard.php', ENT_QUOTES, 'UTF-8') ?>" class="metric-card metric-card-link">
                    <span>Collected Today</span>
                    <h3><?= htmlspecialchars(cashierDashboardCurrency($metrics['amount_collected_today']), ENT_QUOTES, 'UTF-8') ?></h3>
                </a>
                <a href="<?= htmlspecialchars($hasPaymentsFeature ? cashierDashboardMetricUrl('payments_this_month') : 'cashier_dashboard.php', ENT_QUOTES, 'UTF-8') ?>" class="metric-card metric-card-link">
                    <span>Payments This Month</span>
                    <h3><?= number_format((int) $metrics['payments_this_month']) ?></h3>
                </a>
            </section>

            <?php if ($subscriptionNotice): ?>
                <?php include __DIR__ . '/../includes/partials/subscription_notice_card.php'; ?>
            <?php endif; ?>

            <section class="content-grid">
                <article class="content-card">
                    <h3>Cashier Quick Actions</h3>
                    <p>Jump straight into invoice follow-up, payment lookup, and receipt printing.</p>

                    <div class="approval-actions">
                        <?php if ($hasInvoicingFeature): ?>
                            <a href="invoices.php?source_page=cashier_dashboard" class="btn btn-secondary">Create Invoice</a>
                            <a href="invoices.php?status=unpaid&due_filter=overdue&source_page=cashier_dashboard" class="btn btn-primary">Open Overdue Invoices</a>
                            <a href="invoices.php?status=partial&source_page=cashier_dashboard" class="btn btn-secondary">Open Partial Invoices</a>
                        <?php endif; ?>
                        <?php if ($hasPaymentsFeature): ?>
                            <a href="payments.php?date_filter=today&source_page=cashier_dashboard" class="btn btn-secondary">Open Today's Payments</a>
                        <?php endif; ?>
                    </div>

                    <div class="dashboard-list compact-list">
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Role Scope</strong>
                                <p>Cashier access is limited to creating invoices from completed jobs, invoice lookup, payment recording, payment correction, and print flow.</p>
                            </div>
                            <span class="metric-pill">Cashier</span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Tenant Scope</strong>
                                <p>All records on this dashboard are filtered by <code>tenant_id = <?= $tenantId ?></code>.</p>
                            </div>
                            <span class="metric-pill">Scoped</span>
                        </div>
                    </div>
                </article>

                <article class="content-card">
                    <h3>Customer Invoices Needing Attention</h3>
                    <p>Prioritize unpaid and partial customer invoices that are closest to due.</p>

                    <?php if (!$hasInvoicingFeature): ?>
                        <div class="table-placeholder">
                            Customer invoicing is disabled for this tenant right now.
                        </div>
                    <?php elseif (!empty($attentionInvoices)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($attentionInvoices as $invoice): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars($invoice['invoice_no'] ?: ('INV-' . $invoice['invoice_id']), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            Due: <?= htmlspecialchars(cashierDashboardDate($invoice['due_date']), ENT_QUOTES, 'UTF-8') ?>
                                            | Balance: <?= htmlspecialchars(cashierDashboardCurrency(cashierDashboardBalance($invoice)), ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                    </div>
                                    <div class="list-meta">
                                        <span class="status-chip status-<?= htmlspecialchars($invoice['status'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(ucfirst($invoice['status']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <a href="invoices.php?invoice_id=<?= (int) $invoice['invoice_id'] ?>&status=<?= urlencode($invoice['status']) ?>&source_page=cashier_dashboard" class="btn btn-secondary btn-small">Open</a>
                                        <a href="invoice_print.php?invoice_id=<?= (int) $invoice['invoice_id'] ?>" class="btn btn-secondary btn-small" target="_blank" rel="noopener noreferrer">Print</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            No unpaid or partial invoices need follow-up right now.
                        </div>
                    <?php endif; ?>
                </article>
            </section>

            <section class="content-grid dashboard-lower-grid">
                <article class="content-card">
                    <h3>Recent Customer Payments</h3>
                    <p>Latest tenant-scoped customer collections recorded in the system.</p>

                    <?php if (!$hasPaymentsFeature): ?>
                        <div class="table-placeholder">
                            Customer payments are disabled for this tenant right now.
                        </div>
                    <?php elseif (!empty($recentPayments)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($recentPayments as $payment): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars(cashierDashboardCurrency($payment['amount']), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($payment['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars($payment['invoice_no'] ?: ('INV-' . $payment['invoice_id']), ENT_QUOTES, 'UTF-8') ?>
                                            | <?= htmlspecialchars(cashierDashboardDate($payment['payment_date'], true), ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                        <?php if (!empty($payment['payment_method']) || !empty($payment['reference_no'])): ?>
                                            <p>
                                                <?= htmlspecialchars($payment['payment_method'] ?: 'No method', ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($payment['reference_no'])): ?>
                                                    | Ref <?= htmlspecialchars($payment['reference_no'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="list-meta">
                                        <span class="status-chip status-<?= htmlspecialchars($payment['invoice_status'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(ucfirst($payment['invoice_status']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <a href="payments.php?payment_id=<?= (int) $payment['payment_id'] ?>&date_filter=today&source_page=cashier_dashboard" class="btn btn-secondary btn-small">Open</a>
                                        <a href="invoice_print.php?invoice_id=<?= (int) $payment['invoice_id'] ?>" class="btn btn-secondary btn-small" target="_blank" rel="noopener noreferrer">Print</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            No customer payments have been recorded for this tenant yet.
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3>Collection Flow</h3>
                    <p>Fast reminders for the cashier-side customer billing workflow.</p>

                    <div class="dashboard-list">
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Find the invoice</strong>
                                <p>Open unpaid or partial invoices first, then select the customer record that needs collection.</p>
                            </div>
                            <span class="metric-pill">1</span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Record the payment</strong>
                                <p>Use the invoice detail screen so amount paid, remaining balance, and status recalculate together.</p>
                            </div>
                            <span class="metric-pill">2</span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Print when needed</strong>
                                <p>Open the invoice print view directly from the invoice or payment ledger for receipt handoff.</p>
                            </div>
                            <span class="metric-pill">3</span>
                        </div>
                    </div>
                </article>
            </section>
        </main>
    </div>

    <?= renderTenantAdminFooterScripts() ?>
</body>
</html>
