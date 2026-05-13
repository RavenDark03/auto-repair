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
$selectedVehicleId = (int) ($_GET['vehicle_id'] ?? 0);
$selectedCustomerContextId = (int) ($_GET['customer_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$flashMessage = $_SESSION['vehicle_success'] ?? null;
$errorMessage = $_SESSION['vehicle_error'] ?? null;
$oldInput = $_SESSION['vehicle_old_input'] ?? [];
unset($_SESSION['vehicle_success'], $_SESSION['vehicle_error'], $_SESSION['vehicle_old_input']);

$vehicles = [];
$selectedVehicle = null;
$customerOptions = [];
$selectedCustomerContext = null;
$vehicleServiceHistory = [];
$vehicleSummary = [
    'total_vehicles' => 0,
    'active_vehicles' => 0,
    'inactive_vehicles' => 0,
    'new_this_month' => 0,
];
$vehicleInsights = [
    'appointment_count' => 0,
    'completed_jobs' => 0,
    'last_service_date' => null,
    'outstanding_balance' => 0,
];
$vehicleDeactivationWarnings = [
    'active_appointments' => 0,
    'ongoing_jobs' => 0,
    'outstanding_balance' => 0,
];


try {
    $pdo = Database::getInstance();

    $tenantStmt = $pdo->prepare("
        SELECT business_name
        FROM tenants
        WHERE tenant_id = :tenant_id
        LIMIT 1
    ");
    $tenantStmt->execute(['tenant_id' => $tenantId]);
    $tenantRow = $tenantStmt->fetch();

    if ($tenantRow && !empty($tenantRow['business_name'])) {
        $businessName = $tenantRow['business_name'];
        $_SESSION['business_name'] = $businessName;
    }

    $customerOptionsStmt = $pdo->prepare("
        SELECT customer_id, name, status
        FROM customers
        WHERE tenant_id = :tenant_id
        ORDER BY name ASC, customer_id ASC
    ");
    $customerOptionsStmt->execute(['tenant_id' => $tenantId]);
    $customerOptions = $customerOptionsStmt->fetchAll();

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

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_vehicles,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_vehicles,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_vehicles,
            SUM(CASE WHEN DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN 1 ELSE 0 END) AS new_this_month
        FROM vehicles
        WHERE tenant_id = :tenant_id
    ");
    $summaryStmt->execute(['tenant_id' => $tenantId]);
    $summaryRow = $summaryStmt->fetch();

    if ($summaryRow) {
        foreach ($vehicleSummary as $key => $unused) {
            $vehicleSummary[$key] = (int) ($summaryRow[$key] ?? 0);
        }
    }

    $vehicleSql = "
        SELECT
            v.vehicle_id,
            v.customer_id,
            v.make,
            v.model,
            v.year_model,
            v.color,
            v.plate,
            v.mileage,
            v.notes,
            v.status,
            v.created_at,
            c.name AS customer_name
        FROM vehicles v
        INNER JOIN customers c
            ON c.customer_id = v.customer_id
           AND c.tenant_id = v.tenant_id
        WHERE v.tenant_id = :tenant_id
    ";
    $params = ['tenant_id' => $tenantId];

    if ($selectedCustomerContextId > 0) {
        $vehicleSql .= " AND v.customer_id = :customer_id ";
        $params['customer_id'] = $selectedCustomerContextId;
    }

    if ($search !== '') {
        $vehicleSql .= "
          AND (
                c.name LIKE :search
             OR v.make LIKE :search
             OR v.model LIKE :search
             OR v.year_model LIKE :search
             OR v.color LIKE :search
             OR v.plate LIKE :search
          )
        ";
        $params['search'] = '%' . $search . '%';
    }

    $vehicleSql .= " ORDER BY c.name ASC, v.make ASC, v.model ASC, v.vehicle_id ASC";
    $vehicleStmt = $pdo->prepare($vehicleSql);
    $vehicleStmt->execute($params);
    $vehicles = $vehicleStmt->fetchAll();

    if ($selectedVehicleId === 0 && !empty($vehicles)) {
        $selectedVehicleId = (int) $vehicles[0]['vehicle_id'];
    }

    if ($selectedVehicleId > 0) {
        $selectedVehicleStmt = $pdo->prepare("
            SELECT
                v.vehicle_id,
                v.customer_id,
                v.make,
                v.model,
                v.year_model,
                v.color,
                v.plate,
                v.mileage,
                v.notes,
                v.status,
                v.created_at,
                c.name AS customer_name
            FROM vehicles v
            INNER JOIN customers c
                ON c.customer_id = v.customer_id
               AND c.tenant_id = v.tenant_id
            WHERE v.vehicle_id = :vehicle_id
              AND v.tenant_id = :tenant_id
            LIMIT 1
        ");
        $selectedVehicleStmt->execute([
            'vehicle_id' => $selectedVehicleId,
            'tenant_id' => $tenantId,
        ]);
        $selectedVehicle = $selectedVehicleStmt->fetch();

        if ($selectedVehicle && $selectedCustomerContextId === 0) {
            $selectedCustomerContextId = (int) $selectedVehicle['customer_id'];
            $selectedCustomerContext = [
                'customer_id' => $selectedVehicle['customer_id'],
                'name' => $selectedVehicle['customer_name'],
                'status' => 'active',
            ];
        }

        if ($selectedVehicle) {
            $appointmentCountStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM appointments
                WHERE tenant_id = :tenant_id
                  AND vehicle_id = :vehicle_id
            ");
            $appointmentCountStmt->execute([
                'tenant_id' => $tenantId,
                'vehicle_id' => $selectedVehicleId,
            ]);
            $vehicleInsights['appointment_count'] = (int) $appointmentCountStmt->fetchColumn();

            $activeAppointmentCountStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM appointments
                WHERE tenant_id = :tenant_id
                  AND vehicle_id = :vehicle_id
                  AND status IN ('pending', 'approved')
            ");
            $activeAppointmentCountStmt->execute([
                'tenant_id' => $tenantId,
                'vehicle_id' => $selectedVehicleId,
            ]);
            $vehicleDeactivationWarnings['active_appointments'] = (int) $activeAppointmentCountStmt->fetchColumn();

            $completedJobsStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM jobs j
                INNER JOIN appointments a
                    ON a.appointment_id = j.appointment_id
                   AND a.tenant_id = j.tenant_id
                WHERE j.tenant_id = :tenant_id
                  AND a.vehicle_id = :vehicle_id
                  AND j.status = 'completed'
            ");
            $completedJobsStmt->execute([
                'tenant_id' => $tenantId,
                'vehicle_id' => $selectedVehicleId,
            ]);
            $vehicleInsights['completed_jobs'] = (int) $completedJobsStmt->fetchColumn();

            $ongoingJobsStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM jobs j
                INNER JOIN appointments a
                    ON a.appointment_id = j.appointment_id
                   AND a.tenant_id = j.tenant_id
                WHERE j.tenant_id = :tenant_id
                  AND a.vehicle_id = :vehicle_id
                  AND j.status IN ('pending_inspection', 'in_repair', 'waiting_for_parts', 'ongoing')
            ");
            $ongoingJobsStmt->execute([
                'tenant_id' => $tenantId,
                'vehicle_id' => $selectedVehicleId,
            ]);
            $vehicleDeactivationWarnings['ongoing_jobs'] = (int) $ongoingJobsStmt->fetchColumn();

            $lastServiceStmt = $pdo->prepare("
                SELECT MAX(appointment_date)
                FROM appointments
                WHERE tenant_id = :tenant_id
                  AND vehicle_id = :vehicle_id
            ");
            $lastServiceStmt->execute([
                'tenant_id' => $tenantId,
                'vehicle_id' => $selectedVehicleId,
            ]);
            $vehicleInsights['last_service_date'] = $lastServiceStmt->fetchColumn() ?: null;

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
                  AND a.vehicle_id = :vehicle_id
                  AND i.status IN ('unpaid', 'partial')
            ");
            $outstandingBalanceStmt->execute([
                'tenant_id' => $tenantId,
                'vehicle_id' => $selectedVehicleId,
            ]);
            $vehicleInsights['outstanding_balance'] = (float) $outstandingBalanceStmt->fetchColumn();
            $vehicleDeactivationWarnings['outstanding_balance'] = $vehicleInsights['outstanding_balance'];

            $vehicleHistoryStmt = $pdo->prepare("
                SELECT
                    a.appointment_id,
                    a.appointment_date,
                    a.status AS appointment_status,
                    j.job_id,
                    j.status AS job_status,
                    i.invoice_id,
                    i.status AS invoice_status,
                    i.total,
                    i.amount_paid
                FROM appointments a
                LEFT JOIN jobs j
                    ON j.appointment_id = a.appointment_id
                   AND j.tenant_id = a.tenant_id
                LEFT JOIN invoices i
                    ON i.job_id = j.job_id
                   AND i.tenant_id = j.tenant_id
                WHERE a.tenant_id = :tenant_id
                  AND a.vehicle_id = :vehicle_id
                ORDER BY a.appointment_date DESC, a.appointment_id DESC
                LIMIT 6
            ");
            $vehicleHistoryStmt->execute([
                'tenant_id' => $tenantId,
                'vehicle_id' => $selectedVehicleId,
            ]);
            $vehicleServiceHistory = $vehicleHistoryStmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    $errorMessage = 'Vehicle module could not be loaded: ' . $e->getMessage();
}

$showAnalytics = tenantHasFeature('reports', $tenantId);
$showAppointments = tenantHasFeature('appointments', $tenantId);
$showJobs = tenantHasFeature('jobs', $tenantId);
$showInvoicing = tenantHasFeature('invoicing', $tenantId);
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId);

function vehicleDate($date) {
    if (!$date) {
        return 'No date';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y', $timestamp) : $date;
}

function vehicleCurrency($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function vehicleLabel(array $vehicle) {
    return formatVehicleLabel($vehicle, true, false);
}

function vehicleJobUrl($jobId) {
    return buildPageUrl('jobs.php', ['job_id' => (int) $jobId]);
}

function vehicleJobsContextUrl($vehicleId, $customerId = 0, $jobId = 0) {
    $params = ['vehicle_id' => (int) $vehicleId];

    if ($customerId > 0) {
        $params['customer_id'] = (int) $customerId;
    }

    if ($jobId > 0) {
        $params['job_id'] = (int) $jobId;
    }

    return buildPageUrl('jobs.php', $params);
}

function vehicleAppointmentsContextUrl($vehicleId, $customerId = 0, $appointmentId = 0) {
    $params = ['vehicle_id' => (int) $vehicleId];

    if ($customerId > 0) {
        $params['customer_id'] = (int) $customerId;
    }

    if ($appointmentId > 0) {
        $params['appointment_id'] = (int) $appointmentId;
    }

    return buildPageUrl('appointments.php', $params);
}

function vehicleInvoiceUrl($invoiceId, $jobId = 0) {
    $params = [];

    if ($invoiceId > 0) {
        $params['invoice_id'] = (int) $invoiceId;
    }

    if ($jobId > 0) {
        $params['job_id'] = (int) $jobId;
    }

    return buildPageUrl('invoices.php', $params);
}

function vehicleInvoicesContextUrl($vehicleId, $customerId = 0, $invoiceId = 0, $jobId = 0) {
    $params = ['vehicle_id' => (int) $vehicleId];

    if ($customerId > 0) {
        $params['customer_id'] = (int) $customerId;
    }

    if ($invoiceId > 0) {
        $params['invoice_id'] = (int) $invoiceId;
    }

    if ($jobId > 0) {
        $params['job_id'] = (int) $jobId;
    }

    return buildPageUrl('invoices.php', $params);
}

function vehicleContextLabel(array $vehicle) {
    return vehicleLabel($vehicle);
}

function vehicleContextUrl($vehicleId = 0, $customerId = 0, $search = '') {
    $params = [];

    if ($vehicleId > 0) {
        $params['vehicle_id'] = (int) $vehicleId;
    }

    if ($customerId > 0) {
        $params['customer_id'] = (int) $customerId;
    }

    if ($search !== '') {
        $params['search'] = $search;
    }

    return buildPageUrl('vehicles.php', $params);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicles - MECHANIX</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="../assets/css/tabler-mechanix-bridge.css">
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/superadmin-landing-theme.css">
</head>
<body class="page-shell antialiased tenant-app">
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'vehicles.php', $showAnalytics) ?>

        <main class="dashboard-main" id="main-content" tabindex="-1">
            <?= renderTenantAdminTopbar(
                'Vehicles',
                "Manage customer-linked vehicles for {$businessName} with tenant-safe records.",
                ($selectedVehicle || $selectedCustomerContext)
                    ? renderContextStrip(
                        array_values(array_filter([
                            ['label' => 'Dashboard', 'href' => 'dashboard.php'],
                            $selectedCustomerContext ? ['label' => $selectedCustomerContext['name'], 'href' => buildPageUrl('customers.php', ['customer_id' => (int) $selectedCustomerContext['customer_id']])] : null,
                            ['label' => 'Vehicles', 'href' => vehicleContextUrl(0, $selectedCustomerContextId, '')],
                        ])),
                        $selectedVehicle ? vehicleContextLabel($selectedVehicle) : 'Customer Fleet',
                        $selectedCustomerContextId > 0 ? [['label' => 'Clear Context', 'href' => 'vehicles.php']] : []
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

            <section class="dashboard-grid">
                <article class="metric-card">
                    <span>Total Vehicles</span>
                    <h3><?= number_format($vehicleSummary['total_vehicles']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Active Vehicles</span>
                    <h3><?= number_format($vehicleSummary['active_vehicles']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Inactive Vehicles</span>
                    <h3><?= number_format($vehicleSummary['inactive_vehicles']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>New This Month</span>
                    <h3><?= number_format($vehicleSummary['new_this_month']) ?></h3>
                </article>
            </section>

            <section class="content-grid vehicle-grid">
                <article class="content-card">
                    <h3>Vehicle Directory</h3>
                    <p><?= $selectedCustomerContext ? 'Showing only vehicles linked to ' . htmlspecialchars($selectedCustomerContext['name'], ENT_QUOTES, 'UTF-8') . '.' : 'Search vehicles by customer, make, model, year, color, or plate number.' ?></p>

                    <form action="vehicles.php" method="GET" class="feature-toggle-form">
                        <?php if ($selectedCustomerContextId > 0): ?>
                            <input type="hidden" name="customer_id" value="<?= (int) $selectedCustomerContextId ?>">
                        <?php endif; ?>
                        <div class="form-grid customer-search-grid">
                            <div class="form-group">
                                <label for="search">Search Vehicles</label>
                                <input class="form-control" type="text" id="search" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search by customer, make, model, year, color, or plate">
                            </div>
                            <div class="feature-toggle-actions">
                                <button type="submit" class="btn btn-primary">Search</button>
                                <a href="vehicles.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($vehicles)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($vehicles as $vehicle): ?>
                                <?php $isSelected = (int) $vehicle['vehicle_id'] === $selectedVehicleId; ?>
                                <a class="dashboard-list-item dashboard-link-card<?= $isSelected ? ' is-selected' : '' ?>" href="<?= htmlspecialchars(vehicleContextUrl((int) $vehicle['vehicle_id'], $selectedCustomerContextId, $search), ENT_QUOTES, 'UTF-8') ?>">
                                    <div>
                                        <strong>
                                            <?= htmlspecialchars(trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')), ENT_QUOTES, 'UTF-8') ?: 'Vehicle record' ?>
                                            <?php if (!empty($vehicle['year_model'])): ?>
                                                (<?= htmlspecialchars($vehicle['year_model'], ENT_QUOTES, 'UTF-8') ?>)
                                            <?php endif; ?>
                                        </strong>
                                        <p>
                                            <?= htmlspecialchars($vehicle['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($vehicle['plate'])): ?>
                                                | Plate: <?= htmlspecialchars($vehicle['plate'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <span class="status-chip status-<?= htmlspecialchars($vehicle['status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($vehicle['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <strong>No vehicles in this view</strong>
                            <p><?= $selectedCustomerContext ? 'No vehicles matched this customer context and current filter.' : 'No vehicles matched your current filter.' ?></p>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3><?= $selectedVehicle ? 'Edit Vehicle' : 'Add Vehicle' ?></h3>
                    <p><?= $selectedVehicle ? 'Update this vehicle profile without leaving the tenant workspace.' : 'Create a new vehicle record linked to an existing customer.' ?></p>

                    <?php if (empty($customerOptions)): ?>
                        <div class="table-placeholder">
                            Add at least one customer first before creating a vehicle record.
                        </div>
                    <?php else: ?>
                        <form action="<?= $selectedVehicle ? 'actions/update_vehicle.php' : 'actions/create_vehicle.php' ?>" method="POST" class="feature-toggle-form">
                            <?php if ($selectedVehicle): ?>
                                <input type="hidden" name="vehicle_id" value="<?= (int) $selectedVehicle['vehicle_id'] ?>">
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="customer_id">Customer</label>
                                <select class="form-control" id="customer_id" name="customer_id" required>
                                    <option value="">Select customer</option>
                                    <?php foreach ($customerOptions as $customer): ?>
                                        <option value="<?= (int) $customer['customer_id'] ?>"<?= ((int) ($oldInput['customer_id'] ?? ($selectedVehicle['customer_id'] ?? 0)) === (int) $customer['customer_id']) ? ' selected' : '' ?>>
                                            <?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?><?= $customer['status'] !== 'active' ? ' (Inactive)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="make">Make</label>
                                    <input class="form-control" type="text" id="make" name="make" value="<?= htmlspecialchars($oldInput['make'] ?? ($selectedVehicle['make'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="model">Model</label>
                                    <input class="form-control" type="text" id="model" name="model" value="<?= htmlspecialchars($oldInput['model'] ?? ($selectedVehicle['model'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="year_model">Year Model</label>
                                    <input class="form-control" type="text" id="year_model" name="year_model" value="<?= htmlspecialchars($oldInput['year_model'] ?? ($selectedVehicle['year_model'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="color">Color</label>
                                    <input class="form-control" type="text" id="color" name="color" value="<?= htmlspecialchars($oldInput['color'] ?? ($selectedVehicle['color'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="plate">Plate Number</label>
                                    <input class="form-control" type="text" id="plate" name="plate" value="<?= htmlspecialchars($oldInput['plate'] ?? ($selectedVehicle['plate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="mileage">Mileage</label>
                                    <input class="form-control" type="number" id="mileage" name="mileage" min="0" value="<?= htmlspecialchars((string) ($oldInput['mileage'] ?? ($selectedVehicle['mileage'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea class="form-control form-textarea" id="notes" name="notes"><?= htmlspecialchars($oldInput['notes'] ?? ($selectedVehicle['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>

                            <?php if ($selectedVehicle): ?>
                                <div class="form-group">
                                    <label for="status">Status</label>
                                    <select class="form-control" id="status" name="status" required>
                                        <option value="active"<?= (($oldInput['status'] ?? $selectedVehicle['status']) === 'active') ? ' selected' : '' ?>>Active</option>
                                        <option value="inactive"<?= (($oldInput['status'] ?? $selectedVehicle['status']) === 'inactive') ? ' selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="approval-actions">
                                <button type="submit" class="btn btn-primary"><?= $selectedVehicle ? 'Save Vehicle Changes' : 'Create Vehicle' ?></button>
                                <?php if ($selectedVehicle): ?>
                                    <a href="vehicles.php" class="btn btn-secondary">New Vehicle</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($selectedVehicle): ?>
                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Vehicle Status</strong>
                                    <p>Owner: <?= htmlspecialchars($selectedVehicle['customer_name'], ENT_QUOTES, 'UTF-8') ?> | Added <?= htmlspecialchars(vehicleDate($selectedVehicle['created_at']), ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="status-chip status-<?= htmlspecialchars($selectedVehicle['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucfirst($selectedVehicle['status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </div>

                        <?php if (
                            $selectedVehicle['status'] === 'active'
                            && (
                                $vehicleDeactivationWarnings['active_appointments'] > 0
                                || $vehicleDeactivationWarnings['ongoing_jobs'] > 0
                                || $vehicleDeactivationWarnings['outstanding_balance'] > 0
                            )
                        ): ?>
                            <div class="alert alert-error">
                                Deactivation warning:
                                <?= $vehicleDeactivationWarnings['active_appointments'] > 0 ? htmlspecialchars((string) $vehicleDeactivationWarnings['active_appointments'], ENT_QUOTES, 'UTF-8') . ' active appointment(s)' : '' ?>
                                <?= ($vehicleDeactivationWarnings['active_appointments'] > 0 && ($vehicleDeactivationWarnings['ongoing_jobs'] > 0 || $vehicleDeactivationWarnings['outstanding_balance'] > 0)) ? ', ' : '' ?>
                                <?= $vehicleDeactivationWarnings['ongoing_jobs'] > 0 ? htmlspecialchars((string) $vehicleDeactivationWarnings['ongoing_jobs'], ENT_QUOTES, 'UTF-8') . ' ongoing job(s)' : '' ?>
                                <?= ($vehicleDeactivationWarnings['ongoing_jobs'] > 0 && $vehicleDeactivationWarnings['outstanding_balance'] > 0) ? ', ' : '' ?>
                                <?= $vehicleDeactivationWarnings['outstanding_balance'] > 0 ? 'PHP ' . htmlspecialchars(number_format((float) $vehicleDeactivationWarnings['outstanding_balance'], 2), ENT_QUOTES, 'UTF-8') . ' in open receivables' : '' ?> still depend on this vehicle.
                            </div>
                        <?php endif; ?>

                        <form action="actions/update_vehicle_status.php" method="POST" class="feature-toggle-form">
                            <input type="hidden" name="vehicle_id" value="<?= (int) $selectedVehicle['vehicle_id'] ?>">
                            <input type="hidden" name="status" value="<?= $selectedVehicle['status'] === 'active' ? 'inactive' : 'active' ?>">
                            <div class="approval-actions">
                                <button type="submit" class="btn btn-secondary"><?= $selectedVehicle['status'] === 'active' ? 'Mark Inactive' : 'Mark Active' ?></button>
                            </div>
                        </form>
                    <?php endif; ?>
                </article>
            </section>

            <?php if ($selectedVehicle): ?>
                <section class="content-grid vehicle-grid">
                    <article class="content-card">
                        <h3>Vehicle Snapshot</h3>
                        <p>Quick reference for this vehicle's service volume and current receivable exposure.</p>

                        <div class="entity-stat-grid">
                            <?php if ($showAppointments): ?>
                                <div class="entity-stat-card">
                                    <span>Appointments</span>
                                    <strong><?= number_format($vehicleInsights['appointment_count']) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if ($showJobs): ?>
                                <div class="entity-stat-card">
                                    <span>Completed Jobs</span>
                                    <strong><?= number_format($vehicleInsights['completed_jobs']) ?></strong>
                                </div>
                            <?php endif; ?>
                            <div class="entity-stat-card">
                                <span>Last Service</span>
                                <strong><?= htmlspecialchars(vehicleDate($vehicleInsights['last_service_date']), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <?php if ($showInvoicing): ?>
                                <div class="entity-stat-card">
                                    <span>Outstanding</span>
                                    <strong><?= htmlspecialchars(vehicleCurrency($vehicleInsights['outstanding_balance']), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="dashboard-list compact-list">
                            <a class="dashboard-list-item dashboard-link-card" href="customers.php?customer_id=<?= (int) $selectedVehicle['customer_id'] ?>">
                                <div>
                                    <strong>Vehicle Owner</strong>
                                    <p><?= htmlspecialchars($selectedVehicle['customer_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="metric-pill">Open</span>
                            </a>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Current Vehicle Record</strong>
                                    <p>
                                        <?= htmlspecialchars(vehicleLabel($selectedVehicle), ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($selectedVehicle['plate'])): ?>
                                            | Plate: <?= htmlspecialchars($selectedVehicle['plate'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <span class="status-chip status-<?= htmlspecialchars($selectedVehicle['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucfirst($selectedVehicle['status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </div>
                    </article>

                    <article class="content-card">
                        <h3>Recent Service Activity</h3>
                        <p>Latest appointment, job, and invoice progression for this vehicle.</p>

                        <?php if ($showAppointments && !empty($vehicleServiceHistory)): ?>
                            <div class="dashboard-list">
                                <?php foreach ($vehicleServiceHistory as $history): ?>
                                    <div class="dashboard-list-item entity-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars(vehicleDate($history['appointment_date']), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p>
                                                Appointment #<?= (int) $history['appointment_id'] ?>
                                                <?php if ($showJobs && !empty($history['job_id'])): ?>
                                                    | Job #<?= (int) $history['job_id'] ?>
                                                <?php endif; ?>
                                                <?php if ($showInvoicing && !empty($history['invoice_id'])): ?>
                                                    | Invoice #<?= (int) $history['invoice_id'] ?>
                                                <?php endif; ?>
                                            </p>
                                            <?php if ($showInvoicing && !empty($history['invoice_id'])): ?>
                                                <p>
                                                    Invoice total <?= htmlspecialchars(vehicleCurrency($history['total']), ENT_QUOTES, 'UTF-8') ?>
                                                    | Paid <?= htmlspecialchars(vehicleCurrency($history['amount_paid']), ENT_QUOTES, 'UTF-8') ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="entity-status-group">
                                            <span class="status-chip status-<?= htmlspecialchars($history['appointment_status'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars(ucfirst($history['appointment_status']), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <?php if ($showJobs && !empty($history['job_status'])): ?>
                                                <span class="status-chip status-<?= htmlspecialchars(jobStatusClass($history['job_status']), ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars(jobStatusLabel($history['job_status']), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($showInvoicing && !empty($history['invoice_status'])): ?>
                                                <span class="status-chip status-<?= htmlspecialchars($history['invoice_status'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars(ucfirst($history['invoice_status']), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            <?php endif; ?>
                                            <div class="entity-action-group">
                                                <?php if ($showAppointments): ?>
                                                    <a href="<?= htmlspecialchars(vehicleAppointmentsContextUrl((int) $selectedVehicle['vehicle_id'], (int) $selectedVehicle['customer_id'], (int) $history['appointment_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Open Appointment</a>
                                                <?php endif; ?>
                                                <?php if ($showJobs && !empty($history['job_id'])): ?>
                                                    <a href="<?= htmlspecialchars(vehicleJobsContextUrl((int) $selectedVehicle['vehicle_id'], (int) $selectedVehicle['customer_id'], (int) $history['job_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Open Job</a>
                                                <?php endif; ?>
                                                <?php if ($showInvoicing && (!empty($history['invoice_id']) || !empty($history['job_id']))): ?>
                                                    <a href="<?= htmlspecialchars(vehicleInvoicesContextUrl((int) $selectedVehicle['vehicle_id'], (int) $selectedVehicle['customer_id'], (int) ($history['invoice_id'] ?? 0), (int) ($history['job_id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">
                                                        <?= !empty($history['invoice_id']) ? 'Open Invoice' : 'Create Invoice' ?>
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
                                No service activity has been recorded for this vehicle yet.
                            </div>
                        <?php endif; ?>
                    </article>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <?= renderTenantAdminFooterScripts() ?>
</body>
</html>
