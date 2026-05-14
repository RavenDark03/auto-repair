<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/platform_rules.php';

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
$customerAccount = null;
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
$autoInactivatedCount = 0;
$selectedCustomerLastActivity = null;


try {
    $pdo = Database::getInstance();
    $autoInactivatedCount = syncInactiveCustomers($pdo, $tenantId);

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
        SELECT
            c.customer_id,
            c.name,
            c.contact,
            c.email,
            c.address,
            c.status,
            c.created_at,
            COALESCE(recent_activity.last_activity_at, c.created_at) AS last_activity_at
        FROM customers
        c
        LEFT JOIN (
            SELECT
                customer_id,
                tenant_id,
                MAX(activity_date) AS last_activity_at
            FROM (
                SELECT customer_id, tenant_id, created_at AS activity_date
                FROM customers
                WHERE tenant_id = :tenant_id

                UNION ALL

                SELECT customer_id, tenant_id, CAST(appointment_date AS DATETIME) AS activity_date
                FROM appointments
                WHERE tenant_id = :tenant_id

                UNION ALL

                SELECT
                    a.customer_id,
                    a.tenant_id,
                    CAST(p.payment_date AS DATETIME) AS activity_date
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
                WHERE p.tenant_id = :tenant_id
            ) customer_activity
            GROUP BY customer_id, tenant_id
        ) recent_activity
            ON recent_activity.customer_id = c.customer_id
           AND recent_activity.tenant_id = c.tenant_id
        WHERE c.tenant_id = :tenant_id
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

    $customerSql .= " ORDER BY c.status ASC, last_activity_at DESC, c.name ASC, c.customer_id ASC";
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
            $lastActivityStmt = $pdo->prepare("
                SELECT MAX(activity_date) AS last_activity_at
                FROM (
                    SELECT created_at AS activity_date
                    FROM customers
                    WHERE tenant_id = :tenant_id
                      AND customer_id = :customer_id

                    UNION ALL

                    SELECT CAST(appointment_date AS DATETIME) AS activity_date
                    FROM appointments
                    WHERE tenant_id = :tenant_id
                      AND customer_id = :customer_id

                    UNION ALL

                    SELECT CAST(p.payment_date AS DATETIME) AS activity_date
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
                    WHERE p.tenant_id = :tenant_id
                      AND a.customer_id = :customer_id
                ) customer_activity
            ");
            $lastActivityStmt->execute([
                'tenant_id' => $tenantId,
                'customer_id' => $selectedCustomerId,
            ]);
            $selectedCustomerLastActivity = $lastActivityStmt->fetchColumn() ?: $selectedCustomer['created_at'];

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
                  AND j.status IN ('pending_inspection', 'in_repair', 'waiting_for_parts', 'ongoing')
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

            $customerAccountStmt = $pdo->prepare("
                SELECT user_id, username, status, must_change_password, created_at
                FROM users
                WHERE tenant_id = :tenant_id
                  AND customer_id = :customer_id
                  AND role = 'customer'
                LIMIT 1
            ");
            $customerAccountStmt->execute([
                'tenant_id' => $tenantId,
                'customer_id' => $selectedCustomerId,
            ]);
            $customerAccount = $customerAccountStmt->fetch() ?: null;
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
$mechanixCustomerModalOpen = '';
if ($errorMessage !== null) {
    $mechanixCustomerModalOpen = $selectedCustomer ? 'edit' : 'add';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - MECHANIX</title>
    <?= mechanix_link_styles_tabler_workspace('../assets/css/') ?>
</head>
<body class="page-shell antialiased tenant-app"<?php if ($mechanixCustomerModalOpen !== ''): ?> data-mechanix-open-customer-modal="<?= htmlspecialchars($mechanixCustomerModalOpen, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'customers.php', $showAnalytics) ?>

        <main class="dashboard-main" id="main-content" tabindex="-1">
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

            <?= renderTenantAccessModeNotice() ?>

            <?php if ($autoInactivatedCount > 0): ?>
                <div class="alert alert-warning">
                    <?= number_format($autoInactivatedCount) ?> customer record(s) were automatically marked inactive after more than 3 months without recorded activity.
                </div>
            <?php endif; ?>

            <?php if ($subscriptionNotice): ?>
                <?php include __DIR__ . '/../includes/partials/subscription_notice_card.php'; ?>
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
                <article class="metric-card">
                    <span>Auto-Inactivated</span>
                    <h3><?= number_format($autoInactivatedCount) ?></h3>
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
                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mechanixCustomerModalAdd">
                                    <i class="ti ti-user-plus me-1"></i>Add
                                </button>
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
                                        <p>Last activity: <?= htmlspecialchars(customerDate($customer['last_activity_at'] ?? $customer['created_at']), ENT_QUOTES, 'UTF-8') ?></p>
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
                    <?php if (!$selectedCustomer): ?>
                        <h3>Customer profile</h3>
                        <p>Add a customer from the modal or choose someone from the directory to manage fleet, appointments, and mobile login.</p>
                        <div class="approval-actions flex-wrap gap-2">
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#mechanixCustomerModalAdd">
                                <i class="ti ti-user-plus me-1"></i>Add customer
                            </button>
                        </div>
                        <div class="table-placeholder mt-4 mb-0">
                            <strong>Current tenant admin</strong><br>
                            Signed in as <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>. Every customer record on this page is filtered by <code>tenant_id = <?= $tenantId ?></code>.
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                            <div>
                                <h3 class="mb-1"><?= htmlspecialchars($selectedCustomer['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="text-muted small mb-0">Review status, portal access, and lifecycle actions.</p>
                            </div>
                            <div class="btn-list flex-wrap gap-2">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#mechanixCustomerModalEdit">
                                    <i class="ti ti-edit me-1"></i>Edit profile
                                </button>
                                <a href="customers.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>" class="btn btn-secondary">Clear selection</a>
                            </div>
                        </div>
                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Customer Status</strong>
                                    <p>
                                        Added <?= htmlspecialchars(customerDate($selectedCustomer['created_at']), ENT_QUOTES, 'UTF-8') ?>
                                        | Last activity <?= htmlspecialchars(customerDate($selectedCustomerLastActivity), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <span class="status-chip status-<?= htmlspecialchars($selectedCustomer['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucfirst($selectedCustomer['status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </div>

                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Mobile Login</strong>
                                    <p>
                                        <?= $customerAccount ? 'Username: @' . htmlspecialchars($customerAccount['username'], ENT_QUOTES, 'UTF-8') : 'No customer mobile login has been issued yet.' ?>
                                        <?php if ($customerAccount && (int) $customerAccount['must_change_password'] === 1): ?>
                                            | Password change required
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php if ($customerAccount): ?>
                                    <span class="status-chip status-<?= htmlspecialchars($customerAccount['status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($customerAccount['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <form action="actions/save_customer_account.php" method="POST" class="feature-toggle-form">
                            <input type="hidden" name="customer_id" value="<?= (int) $selectedCustomer['customer_id'] ?>">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="customer_account_username">Login Username</label>
                                    <input
                                        class="form-control"
                                        type="text"
                                        id="customer_account_username"
                                        name="username"
                                        value="<?= htmlspecialchars($customerAccount['username'] ?? ($selectedCustomer['email'] ?: $selectedCustomer['contact']), ENT_QUOTES, 'UTF-8') ?>"
                                        required
                                    >
                                </div>
                                <div class="form-group">
                                    <label for="customer_account_status">Access</label>
                                    <select class="form-control" id="customer_account_status" name="status" required>
                                        <option value="active"<?= (($customerAccount['status'] ?? 'active') === 'active') ? ' selected' : '' ?>>Active</option>
                                        <option value="inactive"<?= (($customerAccount['status'] ?? '') === 'inactive') ? ' selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="customer_account_password">Temporary Password</label>
                                    <input class="form-control" type="password" id="customer_account_password" name="password" minlength="8"<?= $customerAccount ? '' : ' required' ?>>
                                </div>
                                <div class="form-group">
                                    <label for="customer_account_confirm_password">Confirm Password</label>
                                    <input class="form-control" type="password" id="customer_account_confirm_password" name="confirm_password" minlength="8"<?= $customerAccount ? '' : ' required' ?>>
                                </div>
                            </div>
                            <label class="toggle-option" for="customer_account_must_change">
                                <input type="checkbox" id="customer_account_must_change" name="must_change_password" value="1"<?= !$customerAccount || (int) ($customerAccount['must_change_password'] ?? 0) === 1 ? ' checked' : '' ?>>
                                <span>Require password change after login</span>
                            </label>
                            <div class="approval-actions">
                                <button type="submit" class="btn btn-secondary">
                                    <?= $customerAccount ? 'Save Customer Login' : 'Issue Customer Login' ?>
                                </button>
                            </div>
                        </form>

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
                                                <span class="status-chip status-<?= htmlspecialchars(jobStatusClass($activity['job_status']), ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars(jobStatusLabel($activity['job_status']), ENT_QUOTES, 'UTF-8') ?>
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

    <?php include __DIR__ . '/../includes/partials/admin_customers_modals.php'; ?>
    <?= renderTenantAdminFooterScripts() ?>
</body>
</html>
