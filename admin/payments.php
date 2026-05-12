<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';

requireTenantRoles(['admin', 'cashier']);
requireTenantFeature('payments');

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$currentRole = $_SESSION['role'] ?? 'admin';
$canManagePayments = in_array($currentRole, ['admin', 'cashier'], true);
$subscriptionNotice = null;
$selectedPaymentId = (int) ($_GET['payment_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$dateFilter = $_GET['date_filter'] ?? '';
$search = trim($_GET['search'] ?? '');
$sourcePage = $_GET['source_page'] ?? '';
$authErrorMessage = $_SESSION['auth_error'] ?? null;
if ($authErrorMessage === null) {
    $authErrorMessage = $_SESSION['error_message'] ?? null;
}
unset($_SESSION['auth_error'], $_SESSION['error_message']);
$flashMessages = [];
foreach (['payment_success'] as $key) {
    if (!empty($_SESSION[$key])) {
        $flashMessages[] = $_SESSION[$key];
    }
    unset($_SESSION[$key]);
}
$errorMessages = [];
foreach (['payment_error'] as $key) {
    if (!empty($_SESSION[$key])) {
        $errorMessages[] = $_SESSION[$key];
    }
    unset($_SESSION[$key]);
}
$paymentRows = [];
$selectedPayment = null;
$systemMessage = null;
$summary = [
    'total_payments' => 0,
    'payments_this_month' => 0,
    'amount_collected' => 0,
    'open_receivables' => 0,
];

$allowedStatuses = ['draft', 'unpaid', 'partial', 'paid', 'cancelled'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$allowedDateFilters = ['today', 'month'];
if (!in_array($dateFilter, $allowedDateFilters, true)) {
    $dateFilter = '';
}

if (!in_array($sourcePage, ['cashier_dashboard'], true)) {
    $sourcePage = '';
}

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

    $summaryStmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM payments WHERE tenant_id = :tenant_id) AS total_payments,
            (
                SELECT COUNT(*)
                FROM payments
                WHERE tenant_id = :tenant_id
                  AND DATE_FORMAT(payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
            ) AS payments_this_month,
            (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE tenant_id = :tenant_id) AS amount_collected,
            (
                SELECT COALESCE(SUM(total - amount_paid), 0)
                FROM invoices
                WHERE tenant_id = :tenant_id
                  AND status IN ('unpaid', 'partial')
            ) AS open_receivables
    ");
    $summaryStmt->execute(['tenant_id' => $tenantId]);
    $row = $summaryStmt->fetch();
    if ($row) {
        $summary['total_payments'] = (int) ($row['total_payments'] ?? 0);
        $summary['payments_this_month'] = (int) ($row['payments_this_month'] ?? 0);
        $summary['amount_collected'] = (float) ($row['amount_collected'] ?? 0);
        $summary['open_receivables'] = (float) ($row['open_receivables'] ?? 0);
    }

    $sql = "
        SELECT
            p.payment_id,
            p.amount,
            p.payment_method,
            p.reference_no,
            p.notes,
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
    ";
    $params = ['tenant_id' => $tenantId];

    if ($search !== '') {
        $sql .= "
            AND (
                c.name LIKE :search
                OR i.invoice_no LIKE :search
                OR p.reference_no LIKE :search
                OR p.payment_method LIKE :search
                OR CAST(p.payment_id AS CHAR) LIKE :search
                OR CAST(p.amount AS CHAR) LIKE :search
            )
        ";
        $params['search'] = '%' . $search . '%';
    }

    if ($statusFilter !== '') {
        $sql .= " AND i.status = :status ";
        $params['status'] = $statusFilter;
    }

    if ($dateFilter === 'today') {
        $sql .= " AND DATE(p.payment_date) = CURDATE() ";
    } elseif ($dateFilter === 'month') {
        $sql .= " AND DATE_FORMAT(p.payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') ";
    }

    $sql .= " ORDER BY p.payment_date DESC, p.payment_id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $paymentRows = $stmt->fetchAll();

    if ($selectedPaymentId === 0 && !empty($paymentRows)) {
        $selectedPaymentId = (int) $paymentRows[0]['payment_id'];
    }

    foreach ($paymentRows as $paymentRow) {
        if ((int) $paymentRow['payment_id'] === $selectedPaymentId) {
            $selectedPayment = $paymentRow;
            break;
        }
    }
} catch (PDOException $e) {
    $paymentRows = [];
    $selectedPayment = null;
    $systemMessage = 'Payments data could not be loaded: ' . $e->getMessage();
}

$showAnalytics = $currentRole === 'admin' && tenantHasFeature('reports', $tenantId);
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId, $currentRole);

function paymentsDate($date) {
    if (!$date) {
        return 'No date';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y h:i A', $timestamp) : $date;
}

function paymentsCurrency($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function paymentsUrl($paymentId = 0, $status = '', $search = '', $dateFilter = '', $extra = []) {
    $params = $extra;

    if ($paymentId > 0) {
        $params['payment_id'] = $paymentId;
    }

    if ($status !== '') {
        $params['status'] = $status;
    }

    if ($search !== '') {
        $params['search'] = $search;
    }

    if ($dateFilter !== '') {
        $params['date_filter'] = $dateFilter;
    }

    return 'payments.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}

function paymentsInvoiceUrl($invoiceId, $editPaymentId = 0, $status = '', $search = '', $dateFilter = '', $paymentId = 0) {
    $params = [
        'invoice_id' => (int) $invoiceId,
        'source_page' => 'payments',
    ];

    if ($editPaymentId > 0) {
        $params['edit_payment_id'] = (int) $editPaymentId;
    }

    if ($paymentId > 0) {
        $params['return_payment_id'] = (int) $paymentId;
    }

    if ($status !== '') {
        $params['return_status'] = $status;
    }

    if ($search !== '') {
        $params['return_search'] = $search;
    }

    if ($dateFilter !== '') {
        $params['return_date_filter'] = $dateFilter;
    }

    return 'invoices.php?' . http_build_query($params);
}

$paymentsReturnExtras = [];
if ($sourcePage === 'cashier_dashboard') {
    $paymentsReturnExtras['source_page'] = 'cashier_dashboard';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments - MECHANIX</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body class="page-shell">
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'payments.php', $showAnalytics) ?>

        <main class="dashboard-main" id="main-content" tabindex="-1">
            <?= renderTenantAdminTopbar(
                'Customer Payments',
                $currentRole === 'cashier'
                    ? 'Review recorded customer payments and jump into customer invoice correction or print flow.'
                    : 'Review customer payment entries already recorded against tenant-scoped customer invoices.',
                $sourcePage === 'cashier_dashboard'
                    ? renderContextStrip(
                        [['label' => 'Dashboard', 'href' => 'cashier_dashboard.php'], ['label' => 'Customer Payments', 'href' => paymentsUrl(0, $statusFilter, $search, $dateFilter, $paymentsReturnExtras)]],
                        $selectedPayment ? 'Payment Ledger' : 'Customer Payments'
                    )
                    : ''
            ) ?>

            <?php foreach ($flashMessages as $message): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endforeach; ?>

            <?php if ($authErrorMessage !== null): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($authErrorMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php foreach ($errorMessages as $message): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endforeach; ?>

            <?php if ($systemMessage !== null): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($systemMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($subscriptionNotice): ?>
                <?php include __DIR__ . '/../includes/partials/subscription_notice_card.php'; ?>
            <?php endif; ?>

            <section class="dashboard-grid">
                <article class="metric-card">
                    <span>Total Payments</span>
                    <h3><?= number_format($summary['total_payments']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>This Month</span>
                    <h3><?= number_format($summary['payments_this_month']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Collected</span>
                    <h3><?= htmlspecialchars(paymentsCurrency($summary['amount_collected']), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
                <article class="metric-card">
                    <span>Open Receivables</span>
                    <h3><?= htmlspecialchars(paymentsCurrency($summary['open_receivables']), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
            </section>

            <?php if ($currentRole === 'cashier'): ?>
                <section class="content-card">
                    <h3>Cashier Shortcuts</h3>
                    <p>Switch quickly between today's collections, monthly payment history, and the main customer invoice follow-up queues.</p>

                    <div class="approval-actions">
                        <a href="<?= htmlspecialchars(paymentsUrl(0, $statusFilter, '', 'today', $paymentsReturnExtras), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">Today's Payments</a>
                        <a href="<?= htmlspecialchars(paymentsUrl(0, $statusFilter, '', 'month', $paymentsReturnExtras), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">This Month</a>
                        <a href="invoices.php?status=unpaid&due_filter=overdue&source_page=cashier_dashboard" class="btn btn-secondary">Overdue Invoices</a>
                        <a href="invoices.php?status=partial&source_page=cashier_dashboard" class="btn btn-secondary">Partial Invoices</a>
                        <a href="<?= htmlspecialchars(paymentsUrl(0, '', '', '', $paymentsReturnExtras), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Full Ledger</a>
                    </div>
                </section>
            <?php endif; ?>

            <section class="content-grid vehicle-grid">
                <article class="content-card">
                    <h3>Customer Payment Ledger</h3>
                    <p>Each payment below is tenant-filtered and linked back to its customer invoice.</p>

                    <form action="payments.php" method="GET" class="feature-toggle-form">
                        <div class="form-grid customer-search-grid">
                            <div class="form-group">
                                <label for="search">Search Payments</label>
                                <input class="form-control" type="text" id="search" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Customer, invoice no., reference, method, payment ID, or amount">
                            </div>
                            <div class="form-group">
                                <label for="status">Invoice Status Filter</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="">All invoice statuses</option>
                                    <?php foreach ($allowedStatuses as $status): ?>
                                        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"<?= $statusFilter === $status ? ' selected' : '' ?>>
                                            <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="date_filter">Date Filter</label>
                                <select class="form-control" id="date_filter" name="date_filter">
                                    <option value="">All dates</option>
                                    <option value="today"<?= $dateFilter === 'today' ? ' selected' : '' ?>>Today only</option>
                                    <option value="month"<?= $dateFilter === 'month' ? ' selected' : '' ?>>This month</option>
                                </select>
                            </div>
                            <div class="feature-toggle-actions">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="payments.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($paymentRows)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($paymentRows as $payment): ?>
                                <?php $isSelected = (int) $payment['payment_id'] === $selectedPaymentId; ?>
                                <a class="dashboard-list-item dashboard-link-card<?= $isSelected ? ' is-selected' : '' ?>" href="<?= htmlspecialchars(paymentsUrl((int) $payment['payment_id'], $statusFilter, $search, $dateFilter, $paymentsReturnExtras), ENT_QUOTES, 'UTF-8') ?>">
                                    <div>
                                        <strong><?= htmlspecialchars(paymentsCurrency($payment['amount']), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars($payment['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                                            | <?= htmlspecialchars($payment['invoice_no'] ?: ('INV-' . $payment['invoice_id']), ENT_QUOTES, 'UTF-8') ?>
                                            | <?= htmlspecialchars(paymentsDate($payment['payment_date']), ENT_QUOTES, 'UTF-8') ?>
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
                                    <span class="status-chip status-<?= htmlspecialchars($payment['invoice_status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($payment['invoice_status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <strong>No payments in this view</strong>
                            <p>No customer payments matched your current filter.</p>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3><?= $selectedPayment ? 'Payment Details' : 'Customer Billing Flow' ?></h3>
                    <p><?= $selectedPayment ? 'Review the selected ledger entry and jump straight into the matching customer invoice correction tools.' : 'Use the Customer Invoices module to create new invoices from completed jobs, then record customer payments there as they are received.' ?></p>

                    <?php if ($selectedPayment): ?>
                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Selected Payment</strong>
                                    <p>
                                        <?= htmlspecialchars($selectedPayment['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                                        | <?= htmlspecialchars($selectedPayment['invoice_no'] ?: ('INV-' . $selectedPayment['invoice_id']), ENT_QUOTES, 'UTF-8') ?>
                                        | <?= htmlspecialchars(paymentsDate($selectedPayment['payment_date']), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <span class="metric-pill"><?= htmlspecialchars(paymentsCurrency($selectedPayment['amount']), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Payment Method</strong>
                                    <p><?= htmlspecialchars($selectedPayment['payment_method'] ?: 'No method provided', ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Reference</strong>
                                    <p><?= htmlspecialchars($selectedPayment['reference_no'] ?: 'No reference number', ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Notes</strong>
                                    <p><?= htmlspecialchars($selectedPayment['notes'] ?: 'No payment notes recorded.', ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="approval-actions">
                            <a href="<?= htmlspecialchars(paymentsInvoiceUrl((int) $selectedPayment['invoice_id'], 0, $statusFilter, $search, $dateFilter, (int) $selectedPayment['payment_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Open Invoice</a>
                            <a href="invoice_print.php?invoice_id=<?= (int) $selectedPayment['invoice_id'] ?>" class="btn btn-secondary" target="_blank" rel="noopener noreferrer">Open Print View</a>
                            <?php if ($canManagePayments && $selectedPayment['invoice_status'] !== 'cancelled'): ?>
                                <a href="<?= htmlspecialchars(paymentsInvoiceUrl((int) $selectedPayment['invoice_id'], (int) $selectedPayment['payment_id'], $statusFilter, $search, $dateFilter, (int) $selectedPayment['payment_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">Edit Payment</a>
                            <?php endif; ?>
                            <?php if ($sourcePage === 'cashier_dashboard'): ?>
                                <a href="cashier_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                            <?php endif; ?>
                        </div>

                        <div class="table-placeholder">
                            Payment corrections still run through the invoice detail screen so invoice balance, amount paid, and status stay synchronized.
                        </div>
                    <?php else: ?>
                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Invoice creation</strong>
                                    <p>Completed jobs become invoice candidates once the repair work is finished.</p>
                                </div>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Payment recording</strong>
                                    <p>Customer payments update invoice totals, remaining balances, and invoice status automatically.</p>
                                </div>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Cashier-safe workflow</strong>
                                    <p>Use this ledger to find a payment quickly, then jump into invoice correction or print without exposing admin-only setup controls.</p>
                                </div>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Tenant isolation</strong>
                                    <p>Every invoice and payment on this page is filtered by <code>tenant_id = <?= $tenantId ?></code>.</p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        </main>
    </div>

    <?= renderTenantAdminFooterScripts() ?>
</body>
</html>
