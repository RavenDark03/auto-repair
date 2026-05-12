<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';

requireTenantRoles(['admin', 'cashier']);
requireTenantFeature('invoicing');

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$fullName = $_SESSION['full_name'] ?? 'Admin User';
$currentRole = $_SESSION['role'] ?? 'admin';
$dashboardHref = $currentRole === 'cashier' ? 'cashier_dashboard.php' : 'dashboard.php';
$hasPaymentsFeature = tenantHasFeature('payments', $tenantId);
$canCreateInvoices = in_array($currentRole, ['admin', 'cashier'], true);
$canManageInvoices = $currentRole === 'admin';
$canManagePayments = $hasPaymentsFeature && in_array($currentRole, ['admin', 'cashier'], true);
$subscriptionNotice = null;
$selectedInvoiceId = (int) ($_GET['invoice_id'] ?? 0);
$selectedJobId = (int) ($_GET['job_id'] ?? 0);
$selectedCustomerContextId = (int) ($_GET['customer_id'] ?? 0);
$selectedVehicleContextId = (int) ($_GET['vehicle_id'] ?? 0);
$editInvoiceItemId = (int) ($_GET['edit_invoice_item_id'] ?? 0);
$editPaymentId = (int) ($_GET['edit_payment_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$dueFilter = $_GET['due_filter'] ?? '';
$sourcePage = $_GET['source_page'] ?? '';
$returnPaymentId = (int) ($_GET['return_payment_id'] ?? 0);
$returnStatus = $_GET['return_status'] ?? '';
$returnSearch = trim($_GET['return_search'] ?? '');
$returnDateFilter = $_GET['return_date_filter'] ?? '';
$authErrorMessage = $_SESSION['auth_error'] ?? null;
if ($authErrorMessage === null) {
    $authErrorMessage = $_SESSION['error_message'] ?? null;
}
unset($_SESSION['auth_error'], $_SESSION['error_message']);
$flashMessages = [];
foreach (['invoice_success', 'payment_success', 'invoice_item_success'] as $key) {
    if (!empty($_SESSION[$key])) {
        $flashMessages[] = $_SESSION[$key];
    }
    unset($_SESSION[$key]);
}

$errorMessages = [];
foreach (['invoice_error', 'payment_error', 'invoice_item_error'] as $key) {
    if (!empty($_SESSION[$key])) {
        $errorMessages[] = $_SESSION[$key];
    }
    unset($_SESSION[$key]);
}

$oldInvoiceInput = $_SESSION['invoice_old_input'] ?? [];
$oldPaymentInput = $_SESSION['customer_payment_old_input'] ?? [];
$oldInvoiceItemInput = $_SESSION['invoice_item_old_input'] ?? [];
$editPaymentOldInput = $_SESSION['customer_payment_edit_old_input'] ?? [];
$editInvoiceItemOldInput = $_SESSION['invoice_item_edit_old_input'] ?? [];
unset(
    $_SESSION['invoice_old_input'],
    $_SESSION['customer_payment_old_input'],
    $_SESSION['invoice_item_old_input'],
    $_SESSION['customer_payment_edit_old_input'],
    $_SESSION['invoice_item_edit_old_input']
);

$allowedStatuses = ['draft', 'unpaid', 'partial', 'paid', 'cancelled'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$allowedDueFilters = ['overdue', 'today', 'upcoming'];
if (!in_array($dueFilter, $allowedDueFilters, true)) {
    $dueFilter = '';
}

$allowedPaymentReturnDateFilters = ['today', 'month'];
if (!in_array($returnDateFilter, $allowedPaymentReturnDateFilters, true)) {
    $returnDateFilter = '';
}

if (!in_array($returnStatus, $allowedStatuses, true)) {
    $returnStatus = '';
}

if (!in_array($sourcePage, ['payments', 'cashier_dashboard'], true)) {
    $sourcePage = '';
}

if ($sourcePage !== 'payments') {
    $returnPaymentId = 0;
    $returnStatus = '';
    $returnSearch = '';
    $returnDateFilter = '';
}

$invoices = [];
$selectedInvoice = null;
$selectedCustomerContext = null;
$selectedVehicleContext = null;
$invoicePayments = [];
$invoiceItems = [];
$completedJobsWithoutInvoice = [];
$editingInvoiceItem = null;
$editingPayment = null;
$summary = [
    'total_invoices' => 0,
    'unpaid_invoices' => 0,
    'partial_invoices' => 0,
    'paid_invoices' => 0,
    'outstanding_receivables' => 0,
];


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

    if ($selectedCustomerContextId > 0) {
        $selectedCustomerContextStmt = $pdo->prepare("
            SELECT customer_id, name, status
            FROM customers
            WHERE tenant_id = :tenant_id
              AND customer_id = :customer_id
            LIMIT 1
        ");
        $selectedCustomerContextStmt->execute([
            'tenant_id' => $tenantId,
            'customer_id' => $selectedCustomerContextId,
        ]);
        $selectedCustomerContext = $selectedCustomerContextStmt->fetch();

        if (!$selectedCustomerContext) {
            $selectedCustomerContextId = 0;
        }
    }

    if ($selectedVehicleContextId > 0) {
        $selectedVehicleContextStmt = $pdo->prepare("
            SELECT
                v.vehicle_id,
                v.customer_id,
                v.make,
                v.model,
                v.year_model,
                v.plate,
                c.name AS customer_name
            FROM vehicles v
            INNER JOIN customers c
                ON c.customer_id = v.customer_id
               AND c.tenant_id = v.tenant_id
            WHERE v.tenant_id = :tenant_id
              AND v.vehicle_id = :vehicle_id
            LIMIT 1
        ");
        $selectedVehicleContextStmt->execute([
            'tenant_id' => $tenantId,
            'vehicle_id' => $selectedVehicleContextId,
        ]);
        $selectedVehicleContext = $selectedVehicleContextStmt->fetch();

        if (!$selectedVehicleContext) {
            $selectedVehicleContextId = 0;
        } else {
            $selectedCustomerContextId = (int) $selectedVehicleContext['customer_id'];
            $selectedCustomerContext = [
                'customer_id' => $selectedVehicleContext['customer_id'],
                'name' => $selectedVehicleContext['customer_name'],
                'status' => 'active',
            ];
        }
    }

    $summarySql = "
        SELECT
            COUNT(*) AS total_invoices,
            SUM(CASE WHEN i.status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_invoices,
            SUM(CASE WHEN i.status = 'partial' THEN 1 ELSE 0 END) AS partial_invoices,
            SUM(CASE WHEN i.status = 'paid' THEN 1 ELSE 0 END) AS paid_invoices,
            SUM(CASE WHEN i.status IN ('unpaid', 'partial') THEN i.total - i.amount_paid ELSE 0 END) AS outstanding_receivables
        FROM invoices i
        INNER JOIN jobs j
            ON j.job_id = i.job_id
           AND j.tenant_id = i.tenant_id
        INNER JOIN appointments a
            ON a.appointment_id = j.appointment_id
           AND a.tenant_id = j.tenant_id
        WHERE i.tenant_id = :tenant_id
    ";
    $summaryParams = ['tenant_id' => $tenantId];

    if ($selectedCustomerContextId > 0) {
        $summarySql .= " AND a.customer_id = :summary_customer_id ";
        $summaryParams['summary_customer_id'] = $selectedCustomerContextId;
    }

    if ($selectedVehicleContextId > 0) {
        $summarySql .= " AND a.vehicle_id = :summary_vehicle_id ";
        $summaryParams['summary_vehicle_id'] = $selectedVehicleContextId;
    }

    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($summaryParams);
    $summaryRow = $summaryStmt->fetch();

    if ($summaryRow) {
        $summary['total_invoices'] = (int) ($summaryRow['total_invoices'] ?? 0);
        $summary['unpaid_invoices'] = (int) ($summaryRow['unpaid_invoices'] ?? 0);
        $summary['partial_invoices'] = (int) ($summaryRow['partial_invoices'] ?? 0);
        $summary['paid_invoices'] = (int) ($summaryRow['paid_invoices'] ?? 0);
        $summary['outstanding_receivables'] = (float) ($summaryRow['outstanding_receivables'] ?? 0);
    }

    $jobOptionsStmt = $pdo->prepare("
        SELECT
            j.job_id,
            a.appointment_date,
            c.name AS customer_name,
            v.make,
            v.model,
            v.plate
        FROM jobs j
        INNER JOIN appointments a
            ON a.appointment_id = j.appointment_id
           AND a.tenant_id = j.tenant_id
        INNER JOIN customers c
            ON c.customer_id = a.customer_id
           AND c.tenant_id = a.tenant_id
        INNER JOIN vehicles v
            ON v.vehicle_id = a.vehicle_id
           AND v.tenant_id = a.tenant_id
        LEFT JOIN invoices i
            ON i.job_id = j.job_id
           AND i.tenant_id = j.tenant_id
        WHERE j.tenant_id = :tenant_id
          AND j.status = 'completed'
          AND i.invoice_id IS NULL
          " . ($selectedCustomerContextId > 0 ? " AND a.customer_id = :customer_id " : "") . "
          " . ($selectedVehicleContextId > 0 ? " AND a.vehicle_id = :vehicle_id " : "") . "
        ORDER BY a.appointment_date DESC, j.job_id DESC
    ");
    $jobOptionParams = ['tenant_id' => $tenantId];
    if ($selectedCustomerContextId > 0) {
        $jobOptionParams['customer_id'] = $selectedCustomerContextId;
    }
    if ($selectedVehicleContextId > 0) {
        $jobOptionParams['vehicle_id'] = $selectedVehicleContextId;
    }
    $jobOptionsStmt->execute($jobOptionParams);
    $completedJobsWithoutInvoice = $jobOptionsStmt->fetchAll();

    $invoiceSql = "
        SELECT
            i.invoice_id,
            i.job_id,
            i.invoice_no,
            i.total,
            i.status,
            i.due_date,
            i.amount_paid,
            i.notes,
            i.created_at,
            c.name AS customer_name,
            c.contact AS customer_contact,
            v.make,
            v.model,
            v.plate
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
        INNER JOIN vehicles v
            ON v.vehicle_id = a.vehicle_id
           AND v.tenant_id = a.tenant_id
        WHERE i.tenant_id = :tenant_id
    ";
    $invoiceParams = ['tenant_id' => $tenantId];

    if ($selectedCustomerContextId > 0) {
        $invoiceSql .= " AND a.customer_id = :customer_id ";
        $invoiceParams['customer_id'] = $selectedCustomerContextId;
    }

    if ($selectedVehicleContextId > 0) {
        $invoiceSql .= " AND a.vehicle_id = :vehicle_id ";
        $invoiceParams['vehicle_id'] = $selectedVehicleContextId;
    }

    if ($statusFilter !== '') {
        $invoiceSql .= " AND i.status = :status ";
        $invoiceParams['status'] = $statusFilter;
    }

    if ($dueFilter === 'overdue') {
        $invoiceSql .= " AND i.due_date IS NOT NULL AND i.due_date < CURDATE() AND i.status IN ('unpaid', 'partial') ";
    } elseif ($dueFilter === 'today') {
        $invoiceSql .= " AND i.due_date = CURDATE() ";
    } elseif ($dueFilter === 'upcoming') {
        $invoiceSql .= " AND i.due_date IS NOT NULL AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND i.status IN ('unpaid', 'partial') ";
    }

    $invoiceSql .= " ORDER BY i.created_at DESC, i.invoice_id DESC";
    $invoiceStmt = $pdo->prepare($invoiceSql);
    $invoiceStmt->execute($invoiceParams);
    $invoices = $invoiceStmt->fetchAll();

    if ($selectedInvoiceId === 0 && $selectedJobId > 0) {
        $invoiceLookupStmt = $pdo->prepare("
            SELECT invoice_id
            FROM invoices
            WHERE tenant_id = :tenant_id
              AND job_id = :job_id
            LIMIT 1
        ");
        $invoiceLookupStmt->execute([
            'tenant_id' => $tenantId,
            'job_id' => $selectedJobId,
        ]);
        $selectedInvoiceId = (int) ($invoiceLookupStmt->fetchColumn() ?: 0);
    }

    if ($selectedInvoiceId === 0 && !empty($invoices)) {
        $selectedInvoiceId = (int) $invoices[0]['invoice_id'];
    }

    if ($selectedInvoiceId > 0) {
        $selectedInvoiceStmt = $pdo->prepare("
            SELECT
                i.invoice_id,
                i.job_id,
                i.invoice_no,
                i.total,
                i.status,
                i.due_date,
                i.amount_paid,
                i.notes,
                i.created_at,
                a.customer_id,
                a.vehicle_id,
                c.name AS customer_name,
                c.contact AS customer_contact,
                v.make,
                v.model,
                v.plate
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
            INNER JOIN vehicles v
                ON v.vehicle_id = a.vehicle_id
               AND v.tenant_id = a.tenant_id
            WHERE i.invoice_id = :invoice_id
              AND i.tenant_id = :tenant_id
            LIMIT 1
        ");
        $selectedInvoiceStmt->execute([
            'invoice_id' => $selectedInvoiceId,
            'tenant_id' => $tenantId,
        ]);
        $selectedInvoice = $selectedInvoiceStmt->fetch();

        if ($selectedInvoice) {
            if ($selectedCustomerContextId === 0) {
                $selectedCustomerContextId = (int) $selectedInvoice['customer_id'];
                $selectedCustomerContext = [
                    'customer_id' => $selectedInvoice['customer_id'],
                    'name' => $selectedInvoice['customer_name'],
                    'status' => 'active',
                ];
            }

            if ($selectedVehicleContextId === 0) {
                $selectedVehicleContextId = (int) $selectedInvoice['vehicle_id'];
                $selectedVehicleContext = [
                    'vehicle_id' => $selectedInvoice['vehicle_id'],
                    'customer_id' => $selectedInvoice['customer_id'],
                    'make' => $selectedInvoice['make'],
                    'model' => $selectedInvoice['model'],
                    'plate' => $selectedInvoice['plate'],
                    'customer_name' => $selectedInvoice['customer_name'],
                ];
            }

            $itemStmt = $pdo->prepare("
                SELECT invoice_item_id, item_type, source_id, description, quantity, unit_price, line_total
                FROM invoice_items
                WHERE tenant_id = :tenant_id
                  AND invoice_id = :invoice_id
                ORDER BY invoice_item_id ASC
            ");
            $itemStmt->execute([
                'tenant_id' => $tenantId,
                'invoice_id' => $selectedInvoiceId,
            ]);
            $invoiceItems = $itemStmt->fetchAll();

            $paymentStmt = $pdo->prepare("
                SELECT payment_id, amount, payment_method, reference_no, notes, payment_date
                FROM payments
                WHERE tenant_id = :tenant_id
                  AND invoice_id = :invoice_id
                ORDER BY payment_date DESC, payment_id DESC
            ");
            $paymentStmt->execute([
                'tenant_id' => $tenantId,
                'invoice_id' => $selectedInvoiceId,
            ]);
            $invoicePayments = $paymentStmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    $errorMessages[] = 'Invoices module could not be loaded: ' . $e->getMessage();
}

$showAnalytics = $currentRole === 'admin' && tenantHasFeature('reports', $tenantId);
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId, $currentRole);

function invoiceDate($date) {
    if (!$date) {
        return 'No date';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y', $timestamp) : $date;
}

function invoiceDateTime($date) {
    if (!$date) {
        return 'No date';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y h:i A', $timestamp) : $date;
}

function invoiceCurrency($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function invoiceVehicleLabel($row) {
    return formatVehicleLabel($row, false, true);
}

function invoiceBalance($invoice) {
    return max(0, (float) ($invoice['total'] ?? 0) - (float) ($invoice['amount_paid'] ?? 0));
}

function invoiceUrl($invoiceId, $status = '', $dueFilter = '', $extra = []) {
    $params = array_merge(['invoice_id' => (int) $invoiceId], $extra);

    if ($status !== '') {
        $params['status'] = $status;
    }

    if ($dueFilter !== '') {
        $params['due_filter'] = $dueFilter;
    }

    return buildPageUrl('invoices.php', $params);
}

function invoiceContextUrl($invoiceId = 0, $customerId = 0, $vehicleId = 0, $jobId = 0, $status = '', $dueFilter = '', $extra = []) {
    $params = $extra;

    if ($invoiceId > 0) {
        $params['invoice_id'] = (int) $invoiceId;
    }

    if ($customerId > 0) {
        $params['customer_id'] = (int) $customerId;
    }

    if ($vehicleId > 0) {
        $params['vehicle_id'] = (int) $vehicleId;
    }

    if ($jobId > 0) {
        $params['job_id'] = (int) $jobId;
    }

    if ($status !== '') {
        $params['status'] = $status;
    }

    if ($dueFilter !== '') {
        $params['due_filter'] = $dueFilter;
    }

    return buildPageUrl('invoices.php', $params);
}

function invoicePaymentsReturnUrl($paymentId = 0, $status = '', $search = '', $dateFilter = '') {
    $params = [];

    if ($paymentId > 0) {
        $params['payment_id'] = (int) $paymentId;
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

    return buildPageUrl('payments.php', $params);
}

function invoiceJobUrl($jobId) {
    return buildPageUrl('jobs.php', ['job_id' => (int) $jobId]);
}

function invoiceCustomerUrl($customerId) {
    return buildPageUrl('customers.php', ['customer_id' => (int) $customerId]);
}

function invoiceVehicleUrl($vehicleId) {
    return buildPageUrl('vehicles.php', ['vehicle_id' => (int) $vehicleId]);
}

function invoiceContextLabel(array $invoice) {
    return !empty($invoice['invoice_no']) ? $invoice['invoice_no'] : 'INV-' . (int) ($invoice['invoice_id'] ?? 0);
}

$invoiceReturnExtras = [];
if ($sourcePage === 'payments') {
    $invoiceReturnExtras['source_page'] = 'payments';

    if ($returnPaymentId > 0) {
        $invoiceReturnExtras['return_payment_id'] = $returnPaymentId;
    }

    if ($returnStatus !== '') {
        $invoiceReturnExtras['return_status'] = $returnStatus;
    }

    if ($returnSearch !== '') {
        $invoiceReturnExtras['return_search'] = $returnSearch;
    }

    if ($returnDateFilter !== '') {
        $invoiceReturnExtras['return_date_filter'] = $returnDateFilter;
    }
} elseif ($sourcePage === 'cashier_dashboard') {
    $invoiceReturnExtras['source_page'] = 'cashier_dashboard';
}

$paymentsReturnUrl = $sourcePage === 'payments'
    ? invoicePaymentsReturnUrl($returnPaymentId, $returnStatus, $returnSearch, $returnDateFilter)
    : '';

if ($editInvoiceItemId > 0) {
    foreach ($invoiceItems as $item) {
        if ((int) $item['invoice_item_id'] === $editInvoiceItemId && $item['item_type'] === 'manual') {
            $editingInvoiceItem = $item;
            break;
        }
    }
}

if ($editPaymentId > 0) {
    foreach ($invoicePayments as $payment) {
        if ((int) $payment['payment_id'] === $editPaymentId) {
            $editingPayment = $payment;
            break;
        }
    }
}

$selectedManualDescription = $oldInvoiceItemInput['description'] ?? '';
$selectedManualQuantity = $oldInvoiceItemInput['quantity'] ?? '1.00';
$selectedManualUnitPrice = $oldInvoiceItemInput['unit_price'] ?? '0.00';
$selectedEditManualDescription = $editInvoiceItemOldInput['description'] ?? ($editingInvoiceItem['description'] ?? '');
$selectedEditManualQuantity = $editInvoiceItemOldInput['quantity'] ?? ($editingInvoiceItem['quantity'] ?? '1.00');
$selectedEditManualUnitPrice = $editInvoiceItemOldInput['unit_price'] ?? ($editingInvoiceItem['unit_price'] ?? '0.00');
$selectedEditPaymentAmount = $editPaymentOldInput['amount'] ?? ($editingPayment['amount'] ?? '0.00');
$selectedEditPaymentMethod = $editPaymentOldInput['payment_method'] ?? ($editingPayment['payment_method'] ?? '');
$selectedEditReferenceNo = $editPaymentOldInput['reference_no'] ?? ($editingPayment['reference_no'] ?? '');
$selectedEditPaymentDate = $editPaymentOldInput['payment_date'] ?? (!empty($editingPayment['payment_date']) ? date('Y-m-d', strtotime($editingPayment['payment_date'])) : date('Y-m-d'));
$selectedEditPaymentNotes = $editPaymentOldInput['notes'] ?? ($editingPayment['notes'] ?? '');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices - MECHANIX</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body class="page-shell">
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'invoices.php', $showAnalytics) ?>

        <main class="dashboard-main" id="main-content" tabindex="-1">
            <?= renderTenantAdminTopbar(
                'Customer Invoices',
                'Create customer invoices from completed jobs and track tenant-scoped receivables.',
                ($selectedInvoice || $selectedCustomerContext || $selectedVehicleContext)
                    ? renderContextStrip(
                        array_values(array_filter([
                            ['label' => 'Dashboard', 'href' => $dashboardHref],
                            ($canManageInvoices && $selectedCustomerContext) ? ['label' => $selectedCustomerContext['name'], 'href' => buildPageUrl('customers.php', ['customer_id' => (int) $selectedCustomerContext['customer_id']])] : null,
                            ($canManageInvoices && $selectedVehicleContext) ? ['label' => invoiceVehicleLabel($selectedVehicleContext), 'href' => buildPageUrl('vehicles.php', ['vehicle_id' => (int) $selectedVehicleContext['vehicle_id'], 'customer_id' => (int) $selectedVehicleContext['customer_id']])] : null,
                            ($canManageInvoices && $selectedInvoice) ? ['label' => 'Job #' . (int) $selectedInvoice['job_id'], 'href' => buildPageUrl('jobs.php', ['job_id' => (int) $selectedInvoice['job_id'], 'customer_id' => (int) $selectedInvoice['customer_id'], 'vehicle_id' => (int) $selectedInvoice['vehicle_id']])] : null,
                            ['label' => 'Invoices', 'href' => invoiceContextUrl(0, $selectedCustomerContextId, $selectedVehicleContextId, 0, $statusFilter, $dueFilter, $invoiceReturnExtras)],
                        ])),
                        $selectedInvoice ? invoiceContextLabel($selectedInvoice) : ($selectedVehicleContext ? 'Vehicle Invoices' : 'Customer Invoices'),
                        array_values(array_filter([
                            $sourcePage === 'payments' ? ['label' => 'Back to Payments', 'href' => $paymentsReturnUrl] : null,
                            $sourcePage === 'cashier_dashboard' ? ['label' => 'Back to Dashboard', 'href' => 'cashier_dashboard.php'] : null,
                            ($selectedCustomerContextId > 0 || $selectedVehicleContextId > 0) ? ['label' => 'Clear Context', 'href' => 'invoices.php'] : null,
                        ]))
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

            <?php if (!$hasPaymentsFeature): ?>
                <div class="alert alert-error">
                    Customer payments are disabled for this tenant right now. Invoice review stays available, but payment recording and payment correction are hidden until the super admin enables the payments module.
                </div>
            <?php endif; ?>

            <?php if ($subscriptionNotice): ?>
                <?php include __DIR__ . '/../includes/partials/subscription_notice_card.php'; ?>
            <?php endif; ?>

            <section class="dashboard-grid">
                <article class="metric-card">
                    <span>Total Invoices</span>
                    <h3><?= number_format($summary['total_invoices']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Unpaid</span>
                    <h3><?= number_format($summary['unpaid_invoices']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Partial</span>
                    <h3><?= number_format($summary['partial_invoices']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Receivables</span>
                    <h3><?= htmlspecialchars(invoiceCurrency($summary['outstanding_receivables']), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
            </section>

            <?php if ($currentRole === 'cashier'): ?>
                <section class="content-card">
                    <h3>Cashier Shortcuts</h3>
                    <p>Switch quickly between the main collection queues without leaving the invoice screen.</p>

                    <div class="approval-actions">
                        <a href="<?= htmlspecialchars(invoiceContextUrl(0, $selectedCustomerContextId, $selectedVehicleContextId, 0, 'unpaid', 'overdue', $invoiceReturnExtras), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">Overdue</a>
                        <a href="<?= htmlspecialchars(invoiceContextUrl(0, $selectedCustomerContextId, $selectedVehicleContextId, 0, '', 'today', $invoiceReturnExtras), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Due Today</a>
                        <a href="<?= htmlspecialchars(invoiceContextUrl(0, $selectedCustomerContextId, $selectedVehicleContextId, 0, 'unpaid', '', $invoiceReturnExtras), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Unpaid</a>
                        <a href="<?= htmlspecialchars(invoiceContextUrl(0, $selectedCustomerContextId, $selectedVehicleContextId, 0, 'partial', '', $invoiceReturnExtras), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Partial</a>
                        <a href="<?= htmlspecialchars(invoiceContextUrl(0, $selectedCustomerContextId, $selectedVehicleContextId, 0, '', '', $invoiceReturnExtras), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">All Invoices</a>
                    </div>
                </section>
            <?php endif; ?>

            <section class="content-grid vehicle-grid">
                <article class="content-card">
                    <h3>Customer Invoice List</h3>
                    <p>
                        <?php if ($selectedVehicleContext): ?>
                            Showing only customer invoices for <?= htmlspecialchars(invoiceVehicleLabel($selectedVehicleContext), ENT_QUOTES, 'UTF-8') ?>.
                        <?php elseif ($selectedCustomerContext): ?>
                            Showing only customer invoices for <?= htmlspecialchars($selectedCustomerContext['name'], ENT_QUOTES, 'UTF-8') ?>.
                        <?php else: ?>
                            Review tenant-scoped customer invoices and filter by collection status.
                        <?php endif; ?>
                    </p>

                    <form action="invoices.php" method="GET" class="feature-toggle-form">
                        <?php if ($selectedCustomerContextId > 0): ?>
                            <input type="hidden" name="customer_id" value="<?= (int) $selectedCustomerContextId ?>">
                        <?php endif; ?>
                        <?php if ($selectedVehicleContextId > 0): ?>
                            <input type="hidden" name="vehicle_id" value="<?= (int) $selectedVehicleContextId ?>">
                        <?php endif; ?>
                        <div class="form-grid customer-search-grid">
                            <div class="form-group">
                                <label for="status">Status Filter</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="">All statuses</option>
                                    <?php foreach ($allowedStatuses as $status): ?>
                                        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"<?= $statusFilter === $status ? ' selected' : '' ?>>
                                            <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="due_filter">Due Filter</label>
                                <select class="form-control" id="due_filter" name="due_filter">
                                    <option value="">All due dates</option>
                                    <option value="overdue"<?= $dueFilter === 'overdue' ? ' selected' : '' ?>>Overdue only</option>
                                    <option value="today"<?= $dueFilter === 'today' ? ' selected' : '' ?>>Due today</option>
                                    <option value="upcoming"<?= $dueFilter === 'upcoming' ? ' selected' : '' ?>>Due within 7 days</option>
                                </select>
                            </div>
                            <div class="feature-toggle-actions">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="invoices.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($invoices)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($invoices as $invoice): ?>
                                <?php $isSelected = (int) $invoice['invoice_id'] === $selectedInvoiceId; ?>
                                <a class="dashboard-list-item dashboard-link-card<?= $isSelected ? ' is-selected' : '' ?>" href="<?= htmlspecialchars(invoiceContextUrl((int) $invoice['invoice_id'], $selectedCustomerContextId, $selectedVehicleContextId, (int) $invoice['job_id'], $statusFilter, $dueFilter, $invoiceReturnExtras), ENT_QUOTES, 'UTF-8') ?>">
                                    <div>
                                        <strong><?= htmlspecialchars($invoice['invoice_no'] ?: ('INV-' . $invoice['invoice_id']), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                                            | <?= htmlspecialchars(invoiceVehicleLabel($invoice), ENT_QUOTES, 'UTF-8') ?>
                                            | Balance <?= htmlspecialchars(invoiceCurrency(invoiceBalance($invoice)), ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                    </div>
                                    <span class="status-chip status-<?= htmlspecialchars($invoice['status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($invoice['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            <?= ($selectedCustomerContext || $selectedVehicleContext) ? 'No invoices matched this context and current filter.' : 'No invoices matched your current filter.' ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3>Create Customer Invoice</h3>
                    <p>Generate one customer invoice per completed job, then record customer payments against that invoice.</p>

                    <?php if ($canCreateInvoices && !empty($completedJobsWithoutInvoice)): ?>
                        <form action="actions/create_invoice.php" method="POST" class="feature-toggle-form">
                            <div class="form-group">
                                <label for="job_id">Completed Job</label>
                                <select class="form-control" id="job_id" name="job_id" required>
                                    <option value="">Select completed job</option>
                                    <?php foreach ($completedJobsWithoutInvoice as $job): ?>
                                        <option value="<?= (int) $job['job_id'] ?>"<?= ((int) ($oldInvoiceInput['job_id'] ?? $selectedJobId) === (int) $job['job_id']) ? ' selected' : '' ?>>
                                            Job #<?= (int) $job['job_id'] ?> - <?= htmlspecialchars($job['customer_name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars(invoiceVehicleLabel($job), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="invoice_no">Invoice No.</label>
                                    <input class="form-control" type="text" id="invoice_no" name="invoice_no" value="<?= htmlspecialchars($oldInvoiceInput['invoice_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Optional custom reference">
                                </div>
                                <div class="form-group">
                                    <label for="invoice_status">Status</label>
                                    <select class="form-control" id="invoice_status" name="status" required>
                                        <option value="draft"<?= (($oldInvoiceInput['status'] ?? 'draft') === 'draft') ? ' selected' : '' ?>>Draft</option>
                                        <option value="unpaid"<?= (($oldInvoiceInput['status'] ?? '') === 'unpaid') ? ' selected' : '' ?>>Unpaid</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="due_date">Due Date</label>
                                <input class="form-control" type="date" id="due_date" name="due_date" value="<?= htmlspecialchars($oldInvoiceInput['due_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="form-group">
                                <label for="invoice_notes">Notes</label>
                                <textarea class="form-control form-textarea" id="invoice_notes" name="notes"><?= htmlspecialchars($oldInvoiceInput['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>

                            <div class="table-placeholder">
                                Invoice totals are now calculated automatically from job service lines and parts used for the selected completed job.
                            </div>

                            <div class="approval-actions">
                                <button type="submit" class="btn btn-primary">Create Invoice</button>
                            </div>
                        </form>
                    <?php elseif ($canCreateInvoices): ?>
                        <div class="table-placeholder">
                            Every completed job in this tenant already has an invoice, or there are no completed jobs yet.
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            Cashier access on this page is limited to invoice creation from completed jobs, invoice lookup, payment recording, and payment correction.
                        </div>
                    <?php endif; ?>

                    <?php if ($selectedInvoice): ?>
                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Selected Invoice</strong>
                                    <p>
                                        <?= htmlspecialchars($selectedInvoice['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                                        | <?= htmlspecialchars(invoiceVehicleLabel($selectedInvoice), ENT_QUOTES, 'UTF-8') ?>
                                        | Issued <?= htmlspecialchars(invoiceDate($selectedInvoice['created_at']), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <span class="status-chip status-<?= htmlspecialchars($selectedInvoice['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucfirst($selectedInvoice['status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Related Records</strong>
                                    <p><?= $canManageInvoices ? 'Open the source job, customer, vehicle, or print-friendly billing view for this invoice.' : 'Open the print-friendly customer invoice view for this invoice.' ?></p>
                                </div>
                                <div class="entity-action-group">
                                    <?php if ($sourcePage === 'payments'): ?>
                                        <a href="<?= htmlspecialchars($paymentsReturnUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Back to Payments</a>
                                    <?php elseif ($sourcePage === 'cashier_dashboard'): ?>
                                        <a href="cashier_dashboard.php" class="btn btn-secondary btn-small">Back to Dashboard</a>
                                    <?php endif; ?>
                                    <?php if ($canManageInvoices): ?>
                                        <a href="<?= htmlspecialchars(invoiceJobUrl((int) $selectedInvoice['job_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Open Job</a>
                                        <a href="<?= htmlspecialchars(invoiceCustomerUrl((int) $selectedInvoice['customer_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Open Customer</a>
                                        <a href="<?= htmlspecialchars(invoiceVehicleUrl((int) $selectedInvoice['vehicle_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Open Vehicle</a>
                                    <?php endif; ?>
                                    <a href="invoice_print.php?invoice_id=<?= (int) $selectedInvoice['invoice_id'] ?>" class="btn btn-secondary btn-small" target="_blank" rel="noopener noreferrer">Open Print View</a>
                                </div>
                            </div>
                        </div>

                        <?php if ($canManageInvoices): ?>
                        <form action="actions/update_invoice.php" method="POST" class="feature-toggle-form">
                            <input type="hidden" name="invoice_id" value="<?= (int) $selectedInvoice['invoice_id'] ?>">

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="edit_invoice_no">Invoice No.</label>
                                    <input class="form-control" type="text" id="edit_invoice_no" name="invoice_no" value="<?= htmlspecialchars($oldInvoiceInput['invoice_no'] ?? ($selectedInvoice['invoice_no'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="edit_due_date">Due Date</label>
                                    <input class="form-control" type="date" id="edit_due_date" name="due_date" value="<?= htmlspecialchars($oldInvoiceInput['due_date'] ?? ($selectedInvoice['due_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="edit_invoice_notes">Notes</label>
                                <textarea class="form-control form-textarea" id="edit_invoice_notes" name="notes"><?= htmlspecialchars($oldInvoiceInput['notes'] ?? ($selectedInvoice['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="edit_invoice_status">Status</label>
                                <?php if ((float) $selectedInvoice['amount_paid'] > 0): ?>
                                    <input type="hidden" name="status" value="<?= htmlspecialchars($selectedInvoice['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <select class="form-control" id="edit_invoice_status" disabled>
                                        <option selected><?= htmlspecialchars(ucfirst($selectedInvoice['status']), ENT_QUOTES, 'UTF-8') ?></option>
                                    </select>
                                    <p>Invoice status is now controlled by recorded payments because this invoice already has collections applied.</p>
                                <?php else: ?>
                                    <select class="form-control" id="edit_invoice_status" name="status" required>
                                        <option value="draft"<?= (($oldInvoiceInput['status'] ?? $selectedInvoice['status']) === 'draft') ? ' selected' : '' ?>>Draft</option>
                                        <option value="unpaid"<?= (($oldInvoiceInput['status'] ?? $selectedInvoice['status']) === 'unpaid') ? ' selected' : '' ?>>Unpaid</option>
                                        <option value="cancelled"<?= (($oldInvoiceInput['status'] ?? $selectedInvoice['status']) === 'cancelled') ? ' selected' : '' ?>>Cancelled</option>
                                    </select>
                                <?php endif; ?>
                            </div>

                            <div class="approval-actions">
                                <button type="submit" class="btn btn-primary">Save Invoice Details</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Invoice Items</strong>
                                    <p>Every line below was generated from the underlying completed job data.</p>
                                </div>
                            </div>

                            <?php if (!empty($invoiceItems)): ?>
                                <?php foreach ($invoiceItems as $item): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p>
                                                <?= htmlspecialchars(ucfirst($item['item_type']), ENT_QUOTES, 'UTF-8') ?>
                                                | Qty <?= htmlspecialchars(number_format((float) $item['quantity'], 2), ENT_QUOTES, 'UTF-8') ?>
                                                | Unit <?= htmlspecialchars(invoiceCurrency($item['unit_price']), ENT_QUOTES, 'UTF-8') ?>
                                            </p>
                                        </div>
                                        <div class="list-meta">
                                            <span class="metric-pill"><?= htmlspecialchars(invoiceCurrency($item['line_total']), ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if ($canManageInvoices && $item['item_type'] === 'manual' && $selectedInvoice['status'] !== 'paid' && $selectedInvoice['status'] !== 'cancelled'): ?>
                                                <a href="<?= htmlspecialchars(invoiceContextUrl((int) $selectedInvoice['invoice_id'], $selectedCustomerContextId, $selectedVehicleContextId, (int) $selectedInvoice['job_id'], $statusFilter, $dueFilter, $invoiceReturnExtras + ['edit_invoice_item_id' => (int) $item['invoice_item_id']]), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Edit</a>
                                                <form action="actions/delete_invoice_item.php" method="POST" class="inline-form">
                                                    <input type="hidden" name="invoice_id" value="<?= (int) $selectedInvoice['invoice_id'] ?>">
                                                    <input type="hidden" name="invoice_item_id" value="<?= (int) $item['invoice_item_id'] ?>">
                                                    <button type="submit" class="btn btn-secondary btn-small">Remove</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="table-placeholder">
                                    No invoice line items were found for this invoice.
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($canManageInvoices && $editingInvoiceItem && $selectedInvoice['status'] !== 'paid' && $selectedInvoice['status'] !== 'cancelled'): ?>
                            <form action="actions/update_invoice_manual_item.php" method="POST" class="feature-toggle-form">
                                <input type="hidden" name="invoice_id" value="<?= (int) $selectedInvoice['invoice_id'] ?>">
                                <input type="hidden" name="invoice_item_id" value="<?= (int) $editingInvoiceItem['invoice_item_id'] ?>">

                                <div class="dashboard-list compact-list">
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong>Edit Manual Invoice Item</strong>
                                            <p>Adjust manual fees, credits, or discounts while this invoice is still editable.</p>
                                        </div>
                                        <a href="<?= htmlspecialchars(invoiceContextUrl((int) $selectedInvoice['invoice_id'], $selectedCustomerContextId, $selectedVehicleContextId, (int) $selectedInvoice['job_id'], $statusFilter, $dueFilter, $invoiceReturnExtras), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Cancel</a>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="edit_manual_description">Manual Item Description</label>
                                    <input class="form-control" type="text" id="edit_manual_description" name="description" value="<?= htmlspecialchars($selectedEditManualDescription, ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="edit_manual_quantity">Quantity</label>
                                        <input class="form-control" type="number" id="edit_manual_quantity" name="quantity" min="0.01" step="0.01" value="<?= htmlspecialchars((string) $selectedEditManualQuantity, ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="edit_manual_unit_price">Unit Price</label>
                                        <input class="form-control" type="number" id="edit_manual_unit_price" name="unit_price" step="0.01" value="<?= htmlspecialchars((string) $selectedEditManualUnitPrice, ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                </div>

                                <div class="approval-actions">
                                    <button type="submit" class="btn btn-primary">Update Manual Item</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <?php if ($canManageInvoices && $selectedInvoice['status'] !== 'paid' && $selectedInvoice['status'] !== 'cancelled'): ?>
                            <form action="actions/create_invoice_manual_item.php" method="POST" class="feature-toggle-form">
                                <input type="hidden" name="invoice_id" value="<?= (int) $selectedInvoice['invoice_id'] ?>">

                                <div class="form-group">
                                    <label for="manual_description">Manual Item Description</label>
                                    <input class="form-control" type="text" id="manual_description" name="description" value="<?= htmlspecialchars($selectedManualDescription, ENT_QUOTES, 'UTF-8') ?>" placeholder="Extra fee, shop supplies, courtesy discount, etc." required>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="manual_quantity">Quantity</label>
                                        <input class="form-control" type="number" id="manual_quantity" name="quantity" min="0.01" step="0.01" value="<?= htmlspecialchars((string) $selectedManualQuantity, ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="manual_unit_price">Unit Price</label>
                                        <input class="form-control" type="number" id="manual_unit_price" name="unit_price" step="0.01" value="<?= htmlspecialchars((string) $selectedManualUnitPrice, ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                </div>

                                <div class="table-placeholder">
                                    Use a negative unit price for discounts or credits. The invoice total will be recalculated automatically.
                                </div>

                                <div class="approval-actions">
                                    <button type="submit" class="btn btn-secondary">Add Manual Invoice Item</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <?php if ($canManagePayments && in_array($selectedInvoice['status'], ['unpaid', 'partial'], true)): ?>
                            <form action="actions/create_customer_payment.php" method="POST" class="feature-toggle-form">
                                <input type="hidden" name="invoice_id" value="<?= (int) $selectedInvoice['invoice_id'] ?>">

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="payment_amount">Payment Amount</label>
                                        <input class="form-control" type="number" id="payment_amount" name="amount" min="0.01" step="0.01" value="<?= htmlspecialchars((string) ($oldPaymentInput['amount'] ?? invoiceBalance($selectedInvoice)), ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="payment_method">Payment Method</label>
                                        <input class="form-control" type="text" id="payment_method" name="payment_method" value="<?= htmlspecialchars($oldPaymentInput['payment_method'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Cash, card, bank transfer, etc.">
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="reference_no">Reference No.</label>
                                        <input class="form-control" type="text" id="reference_no" name="reference_no" value="<?= htmlspecialchars($oldPaymentInput['reference_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="payment_date">Payment Date</label>
                                        <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= htmlspecialchars($oldPaymentInput['payment_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="payment_notes">Notes</label>
                                    <textarea class="form-control form-textarea" id="payment_notes" name="notes"><?= htmlspecialchars($oldPaymentInput['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>

                                <div class="approval-actions">
                                    <button type="submit" class="btn btn-primary">Record Customer Payment</button>
                                </div>
                            </form>
                        <?php elseif ($hasPaymentsFeature && $selectedInvoice['status'] === 'draft'): ?>
                            <div class="table-placeholder">
                                This invoice is still in draft status. Move it to unpaid before recording customer payments.
                            </div>
                        <?php elseif (!$hasPaymentsFeature): ?>
                            <div class="table-placeholder">
                                Customer payments are disabled for this tenant, so no new payment can be recorded from this invoice right now.
                            </div>
                        <?php endif; ?>

                        <?php if ($canManagePayments && $editingPayment && in_array($selectedInvoice['status'], ['unpaid', 'partial'], true)): ?>
                            <form action="actions/update_customer_payment.php" method="POST" class="feature-toggle-form">
                                <input type="hidden" name="invoice_id" value="<?= (int) $selectedInvoice['invoice_id'] ?>">
                                <input type="hidden" name="payment_id" value="<?= (int) $editingPayment['payment_id'] ?>">

                                <div class="dashboard-list compact-list">
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong>Edit Customer Payment</strong>
                                            <p>Correct the payment details and let the invoice totals recalculate automatically.</p>
                                        </div>
                                        <a href="<?= htmlspecialchars(invoiceContextUrl((int) $selectedInvoice['invoice_id'], $selectedCustomerContextId, $selectedVehicleContextId, (int) $selectedInvoice['job_id'], $statusFilter, $dueFilter, $invoiceReturnExtras), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Cancel</a>
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="edit_payment_amount">Payment Amount</label>
                                        <input class="form-control" type="number" id="edit_payment_amount" name="amount" min="0.01" step="0.01" value="<?= htmlspecialchars((string) $selectedEditPaymentAmount, ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="edit_payment_method">Payment Method</label>
                                        <input class="form-control" type="text" id="edit_payment_method" name="payment_method" value="<?= htmlspecialchars($selectedEditPaymentMethod, ENT_QUOTES, 'UTF-8') ?>" placeholder="Cash, card, bank transfer, etc.">
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="edit_reference_no">Reference No.</label>
                                        <input class="form-control" type="text" id="edit_reference_no" name="reference_no" value="<?= htmlspecialchars($selectedEditReferenceNo, ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="edit_payment_date">Payment Date</label>
                                        <input class="form-control" type="date" id="edit_payment_date" name="payment_date" value="<?= htmlspecialchars($selectedEditPaymentDate, ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="edit_payment_notes">Notes</label>
                                    <textarea class="form-control form-textarea" id="edit_payment_notes" name="notes"><?= htmlspecialchars($selectedEditPaymentNotes, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>

                                <div class="approval-actions">
                                    <button type="submit" class="btn btn-primary">Update Customer Payment</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Payment History</strong>
                                    <p>
                                        Total <?= htmlspecialchars(invoiceCurrency($selectedInvoice['total']), ENT_QUOTES, 'UTF-8') ?>
                                        | Paid <?= htmlspecialchars(invoiceCurrency($selectedInvoice['amount_paid']), ENT_QUOTES, 'UTF-8') ?>
                                        | Balance <?= htmlspecialchars(invoiceCurrency(invoiceBalance($selectedInvoice)), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                            </div>

                            <?php if (!$hasPaymentsFeature): ?>
                                <div class="table-placeholder">
                                    Customer payments are disabled for this tenant. Existing invoice totals remain visible, but payment workflow controls are unavailable from this screen.
                                </div>
                            <?php elseif (!empty($invoicePayments)): ?>
                                <?php foreach ($invoicePayments as $payment): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars(invoiceCurrency($payment['amount']), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p>
                                                <?= htmlspecialchars(invoiceDateTime($payment['payment_date']), ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($payment['payment_method'])): ?>
                                                    | <?= htmlspecialchars($payment['payment_method'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                                <?php if (!empty($payment['reference_no'])): ?>
                                                    | Ref <?= htmlspecialchars($payment['reference_no'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="list-meta">
                                            <?php if ($canManagePayments && in_array($selectedInvoice['status'], ['unpaid', 'partial'], true)): ?>
                                                <a href="<?= htmlspecialchars(invoiceContextUrl((int) $selectedInvoice['invoice_id'], $selectedCustomerContextId, $selectedVehicleContextId, (int) $selectedInvoice['job_id'], $statusFilter, $dueFilter, $invoiceReturnExtras + ['edit_payment_id' => (int) $payment['payment_id']]), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Edit</a>
                                                <form action="actions/delete_customer_payment.php" method="POST" class="inline-form">
                                                    <input type="hidden" name="invoice_id" value="<?= (int) $selectedInvoice['invoice_id'] ?>">
                                                    <input type="hidden" name="payment_id" value="<?= (int) $payment['payment_id'] ?>">
                                                    <button type="submit" class="btn btn-secondary btn-small">Remove</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="table-placeholder">
                                    No customer payments have been recorded for this invoice yet.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            <strong>Current tenant admin</strong><br>
                            Signed in as <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>. Every invoice on this page is filtered by <code>tenant_id = <?= $tenantId ?></code>.
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        </main>
    </div>

    <?= renderTenantAdminFooterScripts() ?>
</body>
</html>
