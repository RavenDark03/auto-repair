<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
requireTenantFeature('customer_module');

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$fullName = $_SESSION['full_name'] ?? 'Admin User';
$subscriptionNotice = null;
$selectedCustomerId = (int) ($_GET['customer_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$flashMessage = $_SESSION['customer_success'] ?? null;
$errorMessage = $_SESSION['customer_error'] ?? null;
$oldInput = $_SESSION['customer_old_input'] ?? [];
unset($_SESSION['customer_success'], $_SESSION['customer_error'], $_SESSION['customer_old_input']);

$customers = [];
$selectedCustomer = null;
$customerVehicles = [];
$customerActivity = [];
$customerSummary = [
    'total_customers' => 0,
    'active_customers' => 0,
    'inactive_customers' => 0,
    'new_this_month' => 0,
];
$customerInsights = [
    'vehicle_count' => 0,
    'appointment_count' => 0,
    'completed_jobs' => 0,
    'outstanding_balance' => 0,
];
$customerDeactivationWarnings = [
    'active_appointments' => 0,
    'ongoing_jobs' => 0,
    'outstanding_balance' => 0,
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

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_customers,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_customers,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_customers,
            SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN 1 ELSE 0 END) AS new_this_month
        FROM customers
        WHERE tenant_id = :tenant_id
    ");
    $summaryStmt->execute(['tenant_id' => $tenantId]);
    $summaryRow = $summaryStmt->fetch();

    if ($summaryRow) {
        foreach ($customerSummary as $key => $unused) {
            $customerSummary[$key] = (int) ($summaryRow[$key] ?? 0);
        }
    }

    $customerSql = "
        SELECT customer_id, name, contact, email, address, status, created_at
        FROM customers
        WHERE tenant_id = :tenant_id
    ";
    $params = ['tenant_id' => $tenantId];

    if ($search !== '') {
        $customerSql .= "
          AND (
                name LIKE :search
             OR contact LIKE :search
             OR email LIKE :search
             OR address LIKE :search
          )
        ";
        $params['search'] = '%' . $search . '%';
    }

    $customerSql .= " ORDER BY name ASC, customer_id ASC";
    $customerStmt = $pdo->prepare($customerSql);
    $customerStmt->execute($params);
    $customers = $customerStmt->fetchAll();

    if ($selectedCustomerId === 0 && !empty($customers)) {
        $selectedCustomerId = (int) $customers[0]['customer_id'];
    }

    if ($selectedCustomerId > 0) {
        $selectedCustomerStmt = $pdo->prepare("
            SELECT customer_id, name, contact, email, address, status, created_at
            FROM customers
            WHERE customer_id = :customer_id
              AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $selectedCustomerStmt->execute([
            'customer_id' => $selectedCustomerId,
            'tenant_id' => $tenantId,
        ]);
        $selectedCustomer = $selectedCustomerStmt->fetch();

        if ($selectedCustomer) {
            $customerVehiclesStmt = $pdo->prepare("
                SELECT vehicle_id, make, model, year_model, plate, status
                FROM vehicles
                WHERE tenant_id = :tenant_id
                  AND customer_id = :customer_id
                ORDER BY created_at DESC, vehicle_id DESC
            ");
            $customerVehiclesStmt->execute([
                'tenant_id' => $tenantId,
                'customer_id' => $selectedCustomerId,
            ]);
            $customerVehicles = $customerVehiclesStmt->fetchAll();

            $vehicleCountStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM vehicles
                WHERE tenant_id = :tenant_id
                  AND customer_id = :customer_id
            ");
            $vehicleCountStmt->execute([
                'tenant_id' => $tenantId,
                'customer_id' => $selectedCustomerId,
            ]);
            $customerInsights['vehicle_count'] = (int) $vehicleCountStmt->fetchColumn();

            $appointmentCountStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM appointments
                WHERE tenant_id = :tenant_id
                  AND customer_id = :customer_id
            ");
            $appointmentCountStmt->execute([
                'tenant_id' => $tenantId,
                'customer_id' => $selectedCustomerId,
            ]);
            $customerInsights['appointment_count'] = (int) $appointmentCountStmt->fetchColumn();

            $activeAppointmentCountStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM appointments
                WHERE tenant_id = :tenant_id
                  AND customer_id = :customer_id
                  AND status IN ('pending', 'approved')
            ");
            $activeAppointmentCountStmt->execute([
                'tenant_id' => $tenantId,
                'customer_id' => $selectedCustomerId,
            ]);
            $customerDeactivationWarnings['active_appointments'] = (int) $activeAppointmentCountStmt->fetchColumn();

            $completedJobsStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM jobs j
                INNER JOIN appointments a
                    ON a.appointment_id = j.appointment_id
                   AND a.tenant_id = j.tenant_id
                WHERE j.tenant_id = :tenant_id
                  AND a.customer_id = :customer_id
                  AND j.status = 'completed'
            ");
            $completedJobsStmt->execute([
                'tenant_id' => $tenantId,
                'customer_id' => $selectedCustomerId,
            ]);
            $customerInsights['completed_jobs'] = (int) $completedJobsStmt->fetchColumn();

            $ongoingJobsStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM jobs j
                INNER JOIN appointments a
                    ON a.appointment_id = j.appointment_id
                   AND a.tenant_id = j.tenant_id
                WHERE j.tenant_id = :tenant_id
                  AND a.customer_id = :customer_id
                  AND j.status = 'ongoing'
            ");
            $ongoingJobsStmt->execute([
                'tenant_id' => $tenantId,
                'customer_id' => $selectedCustomerId,
            ]);
            $customerDeactivationWarnings['ongoing_jobs'] = (int) $ongoingJobsStmt->fetchColumn();

            $outstandingBalanceStmt = $pdo->prepare("
                SELECT COALESCE(SUM(i.total - i.amount_paid), 0)
                FROM invoices i
                INNER JOIN jobs j
                    ON j.job_id = i.job_id
                   AND j.tenant_id = i.tenant_id
                INNER JOIN appointments a
                    ON a.appointment_id = j.appointment_id
                   AND a.tenant_id = j.tenant_id
                WHERE i.tenant_id = :tenant_id
                  AND a.customer_id = :customer_id
                  AND i.status IN ('unpaid', 'partial')
            ");
            $outstandingBalanceStmt->execute([
                'tenant_id' => $tenantId,
                'customer_id' => $selectedCustomerId,
            ]);
            $customerInsights['outstanding_balance'] = (float) $outstandingBalanceStmt->fetchColumn();
            $customerDeactivationWarnings['outstanding_balance'] = $customerInsights['outstanding_balance'];

            $customerActivityStmt = $pdo->prepare("
                SELECT
                    a.appointment_id,
                    a.appointment_date,
                    a.status AS appointment_status,
                    j.job_id,
                    j.status AS job_status,
                    i.invoice_id,
                    i.status AS invoice_status,
                    i.total,
                    i.amount_paid,
                    v.vehicle_id,
                    v.make,
                    v.model,
                    v.plate
                FROM appointments a
                INNER JOIN vehicles v
                    ON v.vehicle_id = a.vehicle_id
                   AND v.tenant_id = a.tenant_id
                LEFT JOIN jobs j
                    ON j.appointment_id = a.appointment_id
                   AND j.tenant_id = a.tenant_id
                LEFT JOIN invoices i
                    ON i.job_id = j.job_id
                   AND i.tenant_id = j.tenant_id
                WHERE a.tenant_id = :tenant_id
                  AND a.customer_id = :customer_id
                ORDER BY a.appointment_date DESC, a.appointment_id DESC
                LIMIT 6
            ");
            $customerActivityStmt->execute([
                'tenant_id' => $tenantId,
                'customer_id' => $selectedCustomerId,
            ]);
            $customerActivity = $customerActivityStmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    $errorMessage = 'Customer module could not be loaded: ' . $e->getMessage();
}

$showAnalytics = tenantHasFeature('reports', $tenantId);
$showAppointments = tenantHasFeature('appointments', $tenantId);
$showJobs = tenantHasFeature('jobs', $tenantId);
$showInvoicing = tenantHasFeature('invoicing', $tenantId);
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId);

function customerDate($date) {
    if (!$date) {
        return 'No date';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y', $timestamp) : $date;
}

function customerCurrency($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function customerVehicleLabel(array $vehicle) {
    return formatVehicleLabel($vehicle, true, false);
}

function customerJobUrl($jobId) {
    return buildPageUrl('jobs.php', ['job_id' => (int) $jobId]);
}

function customerJobsContextUrl($customerId, $jobId = 0) {
    $params = ['customer_id' => (int) $customerId];

    if ($jobId > 0) {
        $params['job_id'] = (int) $jobId;
    }

    return buildPageUrl('jobs.php', $params);
}

function customerAppointmentsContextUrl($customerId, $appointmentId = 0) {
    $params = ['customer_id' => (int) $customerId];

    if ($appointmentId > 0) {
        $params['appointment_id'] = (int) $appointmentId;
    }

    return buildPageUrl('appointments.php', $params);
}

function customerInvoiceUrl($invoiceId, $jobId = 0) {
    $params = [];

    if ($invoiceId > 0) {
        $params['invoice_id'] = (int) $invoiceId;
    }

    if ($jobId > 0) {
        $params['job_id'] = (int) $jobId;
    }

    return buildPageUrl('invoices.php', $params);
}

function customerInvoicesContextUrl($customerId, $invoiceId = 0, $jobId = 0) {
    $params = ['customer_id' => (int) $customerId];

    if ($invoiceId > 0) {
        $params['invoice_id'] = (int) $invoiceId;
    }

    if ($jobId > 0) {
        $params['job_id'] = (int) $jobId;
    }

    return buildPageUrl('invoices.php', $params);
}

function customerContextLabel(array $customer) {
    return !empty($customer['name']) ? $customer['name'] : 'Selected customer';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - MECHANIX</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body class="page-shell">
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'customers.php', $showAnalytics) ?>

        <main class="dashboard-main">
            <?= renderTenantAdminTopbar(
                'Customers',
                "Manage customer profiles for {$businessName} with strict tenant isolation.",
                $selectedCustomer
                    ? renderContextStrip(
                        [
                            ['label' => 'Dashboard', 'href' => 'dashboard.php'],
                            ['label' => 'Customers', 'href' => 'customers.php'],
                        ],
                        customerContextLabel($selectedCustomer),
                        !empty($customerVehicles) ? [['label' => 'Open Customer Fleet', 'href' => buildPageUrl('vehicles.php', ['customer_id' => (int) $selectedCustomer['customer_id']])]] : []
                    )
                    : ''
            ) ?>

            <?php if ($flashMessage !== null): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== null): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($subscriptionNotice): ?>
                <section class="content-card">
                    <h3>Business Subscription</h3>
                    <p>Your shop can monitor subscription timing here, while SaaS billing visibility and controls remain exclusive to the super admin.</p>

                    <div class="dashboard-list compact-list">
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Current Subscription</strong>
                                <p><?= htmlspecialchars($subscriptionNotice['summary'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <span class="status-chip <?= htmlspecialchars($subscriptionNotice['class'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($subscriptionNotice['label'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Renewal Reminder</strong>
                                <p><?= htmlspecialchars($subscriptionNotice['detail'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <span class="metric-pill">Subscription</span>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="dashboard-grid">
                <article class="metric-card">
                    <span>Total Customers</span>
                    <h3><?= number_format($customerSummary['total_customers']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Active Customers</span>
                    <h3><?= number_format($customerSummary['active_customers']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Inactive Customers</span>
                    <h3><?= number_format($customerSummary['inactive_customers']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>New This Month</span>
                    <h3><?= number_format($customerSummary['new_this_month']) ?></h3>
                </article>
            </section>

            <section class="content-grid customer-grid">
                <article class="content-card">
                    <h3>Customer Directory</h3>
                    <p>Search and review customer records already scoped to the signed-in tenant.</p>

                    <form action="customers.php" method="GET" class="feature-toggle-form">
                        <div class="form-grid customer-search-grid">
                            <div class="form-group">
                                <label for="search">Search Customers</label>
                                <input class="form-control" type="text" id="search" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search by name, contact, email, or address">
                            </div>
                            <div class="feature-toggle-actions">
                                <button type="submit" class="btn btn-primary">Search</button>
                                <a href="customers.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($customers)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($customers as $customer): ?>
                                <?php $isSelected = (int) $customer['customer_id'] === $selectedCustomerId; ?>
                                <a class="dashboard-list-item dashboard-link-card<?= $isSelected ? ' is-selected' : '' ?>" href="customers.php?customer_id=<?= (int) $customer['customer_id'] ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>">
                                    <div>
                                        <strong><?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars($customer['contact'] ?: 'No contact provided', ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($customer['email'])): ?>
                                                | <?= htmlspecialchars($customer['email'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <span class="status-chip status-<?= htmlspecialchars($customer['status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($customer['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            No customers matched your current filter.
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3><?= $selectedCustomer ? 'Edit Customer' : 'Add Customer' ?></h3>
                    <p><?= $selectedCustomer ? 'Update this customer profile without leaving the tenant workspace.' : 'Create a new customer record for service intake and future vehicle tracking.' ?></p>

                    <form action="<?= $selectedCustomer ? 'actions/update_customer.php' : 'actions/create_customer.php' ?>" method="POST" class="feature-toggle-form">
                        <?php if ($selectedCustomer): ?>
                            <input type="hidden" name="customer_id" value="<?= (int) $selectedCustomer['customer_id'] ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="name">Customer Name</label>
                            <input
                                class="form-control"
                                type="text"
                                id="name"
                                name="name"
                                value="<?= htmlspecialchars($oldInput['name'] ?? ($selectedCustomer['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="contact">Contact Number</label>
                                <input
                                    class="form-control"
                                    type="text"
                                    id="contact"
                                    name="contact"
                                    value="<?= htmlspecialchars($oldInput['contact'] ?? ($selectedCustomer['contact'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>

                            <div class="form-group">
                                <label for="email">Email</label>
                                <input
                                    class="form-control"
                                    type="email"
                                    id="email"
                                    name="email"
                                    value="<?= htmlspecialchars($oldInput['email'] ?? ($selectedCustomer['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea class="form-control form-textarea" id="address" name="address"><?= htmlspecialchars($oldInput['address'] ?? ($selectedCustomer['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>

                        <?php if ($selectedCustomer): ?>
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select class="form-control" id="status" name="status" required>
                                    <option value="active"<?= (($oldInput['status'] ?? $selectedCustomer['status']) === 'active') ? ' selected' : '' ?>>Active</option>
                                    <option value="inactive"<?= (($oldInput['status'] ?? $selectedCustomer['status']) === 'inactive') ? ' selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="approval-actions">
                            <button type="submit" class="btn btn-primary"><?= $selectedCustomer ? 'Save Customer Changes' : 'Create Customer' ?></button>
                            <?php if ($selectedCustomer): ?>
                                <a href="customers.php" class="btn btn-secondary">New Customer</a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if ($selectedCustomer): ?>
                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Customer Status</strong>
                                    <p>Added <?= htmlspecialchars(customerDate($selectedCustomer['created_at']), ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="status-chip status-<?= htmlspecialchars($selectedCustomer['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucfirst($selectedCustomer['status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </div>

                        <?php if (
                            $selectedCustomer['status'] === 'active'
                            && (
                                $customerDeactivationWarnings['active_appointments'] > 0
                                || $customerDeactivationWarnings['ongoing_jobs'] > 0
                                || $customerDeactivationWarnings['outstanding_balance'] > 0
                            )
                        ): ?>
                            <div class="alert alert-error">
                                Deactivation warning:
                                <?= $customerDeactivationWarnings['active_appointments'] > 0 ? htmlspecialchars((string) $customerDeactivationWarnings['active_appointments'], ENT_QUOTES, 'UTF-8') . ' active appointment(s)' : '' ?>
                                <?= ($customerDeactivationWarnings['active_appointments'] > 0 && ($customerDeactivationWarnings['ongoing_jobs'] > 0 || $customerDeactivationWarnings['outstanding_balance'] > 0)) ? ', ' : '' ?>
                                <?= $customerDeactivationWarnings['ongoing_jobs'] > 0 ? htmlspecialchars((string) $customerDeactivationWarnings['ongoing_jobs'], ENT_QUOTES, 'UTF-8') . ' ongoing job(s)' : '' ?>
                                <?= ($customerDeactivationWarnings['ongoing_jobs'] > 0 && $customerDeactivationWarnings['outstanding_balance'] > 0) ? ', ' : '' ?>
                                <?= $customerDeactivationWarnings['outstanding_balance'] > 0 ? 'PHP ' . htmlspecialchars(number_format((float) $customerDeactivationWarnings['outstanding_balance'], 2), ENT_QUOTES, 'UTF-8') . ' in open receivables' : '' ?> still depend on this customer.
                            </div>
                        <?php endif; ?>

                        <form action="actions/update_customer_status.php" method="POST" class="feature-toggle-form">
                            <input type="hidden" name="customer_id" value="<?= (int) $selectedCustomer['customer_id'] ?>">
                            <input type="hidden" name="status" value="<?= $selectedCustomer['status'] === 'active' ? 'inactive' : 'active' ?>">

                            <div class="approval-actions">
                                <button type="submit" class="btn btn-secondary">
                                    <?= $selectedCustomer['status'] === 'active' ? 'Mark Inactive' : 'Mark Active' ?>
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="table-placeholder">
                            <strong>Current tenant admin</strong><br>
                            Signed in as <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>. Every customer record on this page is filtered by <code>tenant_id = <?= $tenantId ?></code>.
                        </div>
                    <?php endif; ?>
                </article>
            </section>

            <?php if ($selectedCustomer): ?>
                <section class="content-grid customer-grid">
                    <article class="content-card">
                        <h3>Customer Fleet</h3>
                        <p>Vehicles linked to this customer inside the current tenant workspace.</p>

                        <div class="entity-stat-grid">
                            <div class="entity-stat-card">
                                <span>Vehicles</span>
                                <strong><?= number_format($customerInsights['vehicle_count']) ?></strong>
                            </div>
                            <?php if ($showAppointments): ?>
                                <div class="entity-stat-card">
                                    <span>Appointments</span>
                                    <strong><?= number_format($customerInsights['appointment_count']) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if ($showJobs): ?>
                                <div class="entity-stat-card">
                                    <span>Completed Jobs</span>
                                    <strong><?= number_format($customerInsights['completed_jobs']) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if ($showInvoicing): ?>
                                <div class="entity-stat-card">
                                    <span>Outstanding</span>
                                    <strong><?= htmlspecialchars(customerCurrency($customerInsights['outstanding_balance']), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($customerVehicles)): ?>
                            <div class="dashboard-list">
                                <?php foreach ($customerVehicles as $vehicle): ?>
                                    <a class="dashboard-list-item dashboard-link-card" href="vehicles.php?vehicle_id=<?= (int) $vehicle['vehicle_id'] ?>&customer_id=<?= (int) $selectedCustomer['customer_id'] ?>">
                                        <div>
                                            <strong><?= htmlspecialchars(customerVehicleLabel($vehicle), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p>
                                                <?= !empty($vehicle['plate']) ? 'Plate: ' . htmlspecialchars($vehicle['plate'], ENT_QUOTES, 'UTF-8') : 'No plate number recorded' ?>
                                            </p>
                                        </div>
                                        <span class="status-chip status-<?= htmlspecialchars($vehicle['status'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(ucfirst($vehicle['status']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-placeholder">
                                No vehicles are linked to this customer yet.
                            </div>
                        <?php endif; ?>
                    </article>

                    <article class="content-card">
                        <h3>Recent Service Activity</h3>
                        <p>Latest appointments and downstream job or invoice progress for this customer.</p>

                        <?php if ($showAppointments && !empty($customerActivity)): ?>
                            <div class="dashboard-list">
                                <?php foreach ($customerActivity as $activity): ?>
                                    <div class="dashboard-list-item entity-list-item">
                                        <div>
                                            <strong>
                                                <?= htmlspecialchars(customerVehicleLabel($activity), ENT_QUOTES, 'UTF-8') ?>
                                            </strong>
                                            <p>
                                                Appointment <?= htmlspecialchars(customerDate($activity['appointment_date']), ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($activity['plate'])): ?>
                                                    | Plate: <?= htmlspecialchars($activity['plate'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </p>
                                            <p>
                                                Appointment #<?= (int) $activity['appointment_id'] ?>
                                                <?php if ($showJobs && !empty($activity['job_id'])): ?>
                                                    | Job #<?= (int) $activity['job_id'] ?>
                                                <?php endif; ?>
                                                <?php if ($showInvoicing && !empty($activity['invoice_id'])): ?>
                                                    | Invoice #<?= (int) $activity['invoice_id'] ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="entity-status-group">
                                            <span class="status-chip status-<?= htmlspecialchars($activity['appointment_status'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars(ucfirst($activity['appointment_status']), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <?php if ($showJobs && !empty($activity['job_status'])): ?>
                                                <span class="status-chip status-<?= htmlspecialchars($activity['job_status'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars(ucfirst($activity['job_status']), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($showInvoicing && !empty($activity['invoice_status'])): ?>
                                                <span class="status-chip status-<?= htmlspecialchars($activity['invoice_status'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars(ucfirst($activity['invoice_status']), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            <?php endif; ?>
                                            <div class="entity-action-group">
                                                <?php if ($showAppointments): ?>
                                                    <a href="<?= htmlspecialchars(customerAppointmentsContextUrl((int) $selectedCustomer['customer_id'], (int) $activity['appointment_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Open Appointment</a>
                                                <?php endif; ?>
                                                <?php if ($showJobs && !empty($activity['job_id'])): ?>
                                                    <a href="<?= htmlspecialchars(customerJobsContextUrl((int) $selectedCustomer['customer_id'], (int) $activity['job_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Open Job</a>
                                                <?php endif; ?>
                                                <?php if ($showInvoicing && (!empty($activity['invoice_id']) || !empty($activity['job_id']))): ?>
                                                    <a href="<?= htmlspecialchars(customerInvoicesContextUrl((int) $selectedCustomer['customer_id'], (int) ($activity['invoice_id'] ?? 0), (int) ($activity['job_id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">
                                                        <?= !empty($activity['invoice_id']) ? 'Open Invoice' : 'Create Invoice' ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif (!$showAppointments): ?>
                            <div class="table-placeholder">
                                Appointment history is hidden because the appointments feature is disabled for this tenant.
                            </div>
                        <?php else: ?>
                            <div class="table-placeholder">
                                No service activity has been recorded for this customer yet.
                            </div>
                        <?php endif; ?>
                    </article>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <script src="../assets/js/theme.js"></script>
</body>
</html>
