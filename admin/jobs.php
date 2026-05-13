<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
requireTenantFeature('jobs');

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$fullName = $_SESSION['full_name'] ?? 'Admin User';
$subscriptionNotice = null;
$selectedJobId = (int) ($_GET['job_id'] ?? 0);
$selectedCustomerContextId = (int) ($_GET['customer_id'] ?? 0);
$selectedVehicleContextId = (int) ($_GET['vehicle_id'] ?? 0);
$editServiceId = (int) ($_GET['edit_service_id'] ?? 0);
$editPartUsageId = (int) ($_GET['edit_part_usage_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$flashMessage = $_SESSION['job_success'] ?? null;
$errorMessage = $_SESSION['job_error'] ?? null;
$oldInput = $_SESSION['job_old_input'] ?? [];
$serviceEditOldInput = $_SESSION['job_service_edit_old_input'] ?? [];
$partEditOldInput = $_SESSION['job_part_edit_old_input'] ?? [];
unset(
    $_SESSION['job_success'],
    $_SESSION['job_error'],
    $_SESSION['job_old_input'],
    $_SESSION['job_service_edit_old_input'],
    $_SESSION['job_part_edit_old_input']
);

$jobs = [];
$selectedJob = null;
$selectedCustomerContext = null;
$selectedVehicleContext = null;
$mechanicOptions = [];
$inventoryOptions = [];
$jobLogs = [];
$jobPartsUsed = [];
$jobServices = [];
$jobHasInvoice = false;
$jobInvoiceId = 0;
$jobSourceAppointmentLocked = false;
$editingService = null;
$editingPartUsage = null;
$allowedStatuses = ['ongoing', 'completed'];
$jobSummary = [
    'total_jobs' => 0,
    'ongoing_jobs' => 0,
    'completed_jobs' => 0,
    'unassigned_jobs' => 0,
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
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

    $mechanicStmt = $pdo->prepare("
        SELECT user_id, full_name, status
        FROM users
        WHERE tenant_id = :tenant_id
          AND role = 'mechanic'
          AND status = 'active'
        ORDER BY full_name ASC, user_id ASC
    ");
    $mechanicStmt->execute(['tenant_id' => $tenantId]);
    $mechanicOptions = $mechanicStmt->fetchAll();

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
            COUNT(*) AS total_jobs,
            SUM(CASE WHEN j.status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing_jobs,
            SUM(CASE WHEN j.status = 'completed' THEN 1 ELSE 0 END) AS completed_jobs,
            SUM(CASE WHEN j.mechanic_id IS NULL THEN 1 ELSE 0 END) AS unassigned_jobs
        FROM jobs j
        INNER JOIN appointments a
            ON a.appointment_id = j.appointment_id
           AND a.tenant_id = j.tenant_id
        WHERE j.tenant_id = :tenant_id
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
        foreach ($jobSummary as $key => $unused) {
            $jobSummary[$key] = (int) ($summaryRow[$key] ?? 0);
        }
    }

    $jobSql = "
            SELECT
                j.job_id,
                j.appointment_id,
                j.mechanic_id,
                j.status,
                a.appointment_date,
                a.status AS appointment_status,
                c.name AS customer_name,
                c.contact AS customer_contact,
                v.make,
            v.model,
            v.year_model,
            v.plate,
            u.full_name AS mechanic_name
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
        LEFT JOIN users u
            ON u.user_id = j.mechanic_id
           AND u.tenant_id = j.tenant_id
        WHERE j.tenant_id = :tenant_id
    ";
    $params = ['tenant_id' => $tenantId];

    if ($selectedCustomerContextId > 0) {
        $jobSql .= " AND a.customer_id = :customer_id ";
        $params['customer_id'] = $selectedCustomerContextId;
    }

    if ($selectedVehicleContextId > 0) {
        $jobSql .= " AND a.vehicle_id = :vehicle_id ";
        $params['vehicle_id'] = $selectedVehicleContextId;
    }

    if ($search !== '') {
        $jobSql .= "
          AND (
                c.name LIKE :search
             OR c.contact LIKE :search
             OR v.make LIKE :search
             OR v.model LIKE :search
             OR v.plate LIKE :search
             OR u.full_name LIKE :search
             OR CAST(j.job_id AS CHAR) LIKE :search
          )
        ";
        $params['search'] = '%' . $search . '%';
    }

    if ($statusFilter !== '') {
        $jobSql .= " AND j.status = :status ";
        $params['status'] = $statusFilter;
    }

    $jobSql .= " ORDER BY j.status ASC, a.appointment_date DESC, j.job_id DESC";
    $jobStmt = $pdo->prepare($jobSql);
    $jobStmt->execute($params);
    $jobs = $jobStmt->fetchAll();

    if (tenantHasFeature('inventory', $tenantId)) {
        $inventoryOptionsStmt = $pdo->prepare("
            SELECT inventory_id, part_name, sku, quantity, unit_cost, status
            FROM inventory
            WHERE tenant_id = :tenant_id
              AND status = 'active'
            ORDER BY part_name ASC, inventory_id ASC
        ");
        $inventoryOptionsStmt->execute(['tenant_id' => $tenantId]);
        $inventoryOptions = $inventoryOptionsStmt->fetchAll();
    }

    if ($selectedJobId === 0 && !empty($jobs)) {
        $selectedJobId = (int) $jobs[0]['job_id'];
    }

    if ($selectedJobId > 0) {
        $selectedJobStmt = $pdo->prepare("
            SELECT
                j.job_id,
                j.appointment_id,
                j.mechanic_id,
                j.status,
                a.appointment_date,
                a.status AS appointment_status,
                a.customer_id,
                a.vehicle_id,
                c.name AS customer_name,
                c.contact AS customer_contact,
                v.make,
                v.model,
                v.year_model,
                v.plate,
                u.full_name AS mechanic_name,
                inv.invoice_id
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
            LEFT JOIN users u
                ON u.user_id = j.mechanic_id
               AND u.tenant_id = j.tenant_id
            LEFT JOIN invoices inv
                ON inv.job_id = j.job_id
               AND inv.tenant_id = j.tenant_id
            WHERE j.job_id = :job_id
              AND j.tenant_id = :tenant_id
            LIMIT 1
        ");
        $selectedJobStmt->execute([
            'job_id' => $selectedJobId,
            'tenant_id' => $tenantId,
        ]);
        $selectedJob = $selectedJobStmt->fetch();

        if ($selectedJob) {
            if ($selectedCustomerContextId === 0) {
                $selectedCustomerContextId = (int) $selectedJob['customer_id'];
                $selectedCustomerContext = [
                    'customer_id' => $selectedJob['customer_id'],
                    'name' => $selectedJob['customer_name'],
                    'status' => 'active',
                ];
            }

            if ($selectedVehicleContextId === 0) {
                $selectedVehicleContextId = (int) $selectedJob['vehicle_id'];
                $selectedVehicleContext = [
                    'vehicle_id' => $selectedJob['vehicle_id'],
                    'customer_id' => $selectedJob['customer_id'],
                    'make' => $selectedJob['make'],
                    'model' => $selectedJob['model'],
                    'year_model' => $selectedJob['year_model'],
                    'plate' => $selectedJob['plate'],
                    'customer_name' => $selectedJob['customer_name'],
                ];
            }

            $jobHasInvoice = !empty($selectedJob['invoice_id']);
            $jobInvoiceId = (int) ($selectedJob['invoice_id'] ?? 0);
            $jobSourceAppointmentLocked = ($selectedJob['appointment_status'] ?? 'approved') !== 'approved';

            $jobLogStmt = $pdo->prepare("
                SELECT status, changed_at
                FROM job_status_logs
                WHERE tenant_id = :tenant_id
                  AND job_id = :job_id
                ORDER BY changed_at DESC, log_id DESC
                LIMIT 10
            ");
            $jobLogStmt->execute([
                'tenant_id' => $tenantId,
                'job_id' => $selectedJobId,
            ]);
            $jobLogs = $jobLogStmt->fetchAll();

            $jobServicesStmt = $pdo->prepare("
                SELECT job_service_id, service_name, description, quantity, unit_price, line_total, created_at
                FROM job_services
                WHERE tenant_id = :tenant_id
                  AND job_id = :job_id
                ORDER BY job_service_id DESC
            ");
            $jobServicesStmt->execute([
                'tenant_id' => $tenantId,
                'job_id' => $selectedJobId,
            ]);
            $jobServices = $jobServicesStmt->fetchAll();

            if (tenantHasFeature('inventory', $tenantId)) {
                $jobPartsStmt = $pdo->prepare("
                    SELECT
                        jpu.id,
                        jpu.quantity_used,
                        i.inventory_id,
                        i.part_name,
                        i.sku,
                        i.unit_cost
                    FROM job_parts_used jpu
                    INNER JOIN inventory i
                        ON i.inventory_id = jpu.inventory_id
                       AND i.tenant_id = jpu.tenant_id
                    WHERE jpu.tenant_id = :tenant_id
                      AND jpu.job_id = :job_id
                    ORDER BY jpu.id DESC
                ");
                $jobPartsStmt->execute([
                    'tenant_id' => $tenantId,
                    'job_id' => $selectedJobId,
                ]);
                $jobPartsUsed = $jobPartsStmt->fetchAll();
            }
        }
    }
} catch (PDOException $e) {
    $errorMessage = 'Jobs module could not be loaded: ' . $e->getMessage();
}

$showAnalytics = tenantHasFeature('reports', $tenantId);
$showInventory = tenantHasFeature('inventory', $tenantId);
$showInvoicing = tenantHasFeature('invoicing', $tenantId);
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId);

function jobDate($date) {
    if (!$date) {
        return 'No date';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y', $timestamp) : $date;
}

function jobDateTime($date) {
    if (!$date) {
        return 'No history yet';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y h:i A', $timestamp) : $date;
}

function jobCurrency($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function jobVehicleLabel($job) {
    return formatVehicleLabel($job, true, true);
}

function jobUrl($jobId, $search = '', $status = '', $extra = [], $customerId = 0, $vehicleId = 0) {
    $params = array_merge(['job_id' => (int) $jobId], $extra);

    if ($customerId > 0) {
        $params['customer_id'] = (int) $customerId;
    }

    if ($vehicleId > 0) {
        $params['vehicle_id'] = (int) $vehicleId;
    }

    if ($search !== '') {
        $params['search'] = $search;
    }

    if ($status !== '') {
        $params['status'] = $status;
    }

    return buildPageUrl('jobs.php', $params);
}

function jobContextUrl($jobId = 0, $customerId = 0, $vehicleId = 0, $search = '', $status = '', $extra = []) {
    $params = $extra;

    if ($jobId > 0) {
        $params['job_id'] = (int) $jobId;
    }

    if ($customerId > 0) {
        $params['customer_id'] = (int) $customerId;
    }

    if ($vehicleId > 0) {
        $params['vehicle_id'] = (int) $vehicleId;
    }

    if ($search !== '') {
        $params['search'] = $search;
    }

    if ($status !== '') {
        $params['status'] = $status;
    }

    return buildPageUrl('jobs.php', $params);
}

function jobCustomerUrl($customerId) {
    return buildPageUrl('customers.php', ['customer_id' => (int) $customerId]);
}

function jobVehicleUrl($vehicleId) {
    return buildPageUrl('vehicles.php', ['vehicle_id' => (int) $vehicleId]);
}

function jobInvoiceUrl($invoiceId = 0, $jobId = 0) {
    $params = [];

    if ($invoiceId > 0) {
        $params['invoice_id'] = (int) $invoiceId;
    }

    if ($jobId > 0) {
        $params['job_id'] = (int) $jobId;
    }

    return buildPageUrl('invoices.php', $params);
}

function jobContextLabel(array $job) {
    return 'Job #' . (int) ($job['job_id'] ?? 0);
}

if ($editServiceId > 0) {
    foreach ($jobServices as $service) {
        if ((int) $service['job_service_id'] === $editServiceId) {
            $editingService = $service;
            break;
        }
    }
}

if ($editPartUsageId > 0) {
    foreach ($jobPartsUsed as $partUsage) {
        if ((int) $partUsage['id'] === $editPartUsageId) {
            $editingPartUsage = $partUsage;
            break;
        }
    }
}

$selectedMechanicId = (int) ($oldInput['mechanic_id'] ?? ($selectedJob['mechanic_id'] ?? 0));
$selectedStatus = $oldInput['status'] ?? ($selectedJob['status'] ?? 'ongoing');
$selectedInventoryUsageId = (int) ($oldInput['inventory_id'] ?? 0);
$selectedUsageQuantity = (int) ($oldInput['quantity_used'] ?? 1);
$selectedServiceName = $oldInput['service_name'] ?? '';
$selectedServiceDescription = $oldInput['description'] ?? '';
$selectedServiceQuantity = $oldInput['service_quantity'] ?? '1.00';
$selectedServiceUnitPrice = $oldInput['service_unit_price'] ?? '0.00';
$editServiceName = $serviceEditOldInput['service_name'] ?? ($editingService['service_name'] ?? '');
$editServiceDescription = $serviceEditOldInput['description'] ?? ($editingService['description'] ?? '');
$editServiceQuantity = $serviceEditOldInput['service_quantity'] ?? ($editingService['quantity'] ?? '1.00');
$editServiceUnitPrice = $serviceEditOldInput['service_unit_price'] ?? ($editingService['unit_price'] ?? '0.00');
$editPartInventoryId = (int) ($partEditOldInput['inventory_id'] ?? ($editingPartUsage['inventory_id'] ?? 0));
$editPartQuantity = (int) ($partEditOldInput['quantity_used'] ?? ($editingPartUsage['quantity_used'] ?? 1));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jobs - MECHANIX</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body class="page-shell">
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'jobs.php', $showAnalytics) ?>

        <main class="dashboard-main" id="main-content" tabindex="-1">
            <?= renderTenantAdminTopbar(
                'Jobs',
                'Track approved appointment work, assign mechanics, and update job progress inside the current tenant.',
                ($selectedJob || $selectedCustomerContext || $selectedVehicleContext)
                    ? renderContextStrip(
                        array_values(array_filter([
                            ['label' => 'Dashboard', 'href' => 'dashboard.php'],
                            $selectedCustomerContext ? ['label' => $selectedCustomerContext['name'], 'href' => buildPageUrl('customers.php', ['customer_id' => (int) $selectedCustomerContext['customer_id']])] : null,
                            $selectedVehicleContext ? ['label' => jobVehicleLabel($selectedVehicleContext), 'href' => buildPageUrl('vehicles.php', ['vehicle_id' => (int) $selectedVehicleContext['vehicle_id'], 'customer_id' => (int) $selectedVehicleContext['customer_id']])] : null,
                            ['label' => 'Jobs', 'href' => jobContextUrl(0, $selectedCustomerContextId, $selectedVehicleContextId)],
                        ])),
                        $selectedJob ? jobContextLabel($selectedJob) : ($selectedVehicleContext ? 'Vehicle Jobs' : 'Customer Jobs'),
                        array_values(array_filter([
                            ($showInvoicing && $selectedJob) ? ['label' => $jobHasInvoice ? 'Open Invoice' : 'Create Invoice', 'href' => jobInvoiceUrl($jobInvoiceId, (int) $selectedJob['job_id'])] : null,
                            ($selectedCustomerContextId > 0 || $selectedVehicleContextId > 0) ? ['label' => 'Clear Context', 'href' => 'jobs.php'] : null,
                        ]))
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
                <?php include __DIR__ . '/../includes/partials/subscription_notice_card.php'; ?>
            <?php endif; ?>

            <section class="dashboard-grid">
                <article class="metric-card">
                    <span>Total Jobs</span>
                    <h3><?= number_format($jobSummary['total_jobs']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Ongoing Jobs</span>
                    <h3><?= number_format($jobSummary['ongoing_jobs']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Completed Jobs</span>
                    <h3><?= number_format($jobSummary['completed_jobs']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Unassigned Jobs</span>
                    <h3><?= number_format($jobSummary['unassigned_jobs']) ?></h3>
                </article>
            </section>

            <section class="content-grid vehicle-grid">
                <article class="content-card">
                    <h3>Job Queue</h3>
                    <p>
                        <?php if ($selectedVehicleContext): ?>
                            Showing only jobs for <?= htmlspecialchars(jobVehicleLabel($selectedVehicleContext), ENT_QUOTES, 'UTF-8') ?>.
                        <?php elseif ($selectedCustomerContext): ?>
                            Showing only jobs for <?= htmlspecialchars($selectedCustomerContext['name'], ENT_QUOTES, 'UTF-8') ?>.
                        <?php else: ?>
                            Search by customer, vehicle, plate number, mechanic, or job number, then filter by current job status.
                        <?php endif; ?>
                    </p>

                    <form action="jobs.php" method="GET" class="feature-toggle-form">
                        <?php if ($selectedCustomerContextId > 0): ?>
                            <input type="hidden" name="customer_id" value="<?= (int) $selectedCustomerContextId ?>">
                        <?php endif; ?>
                        <?php if ($selectedVehicleContextId > 0): ?>
                            <input type="hidden" name="vehicle_id" value="<?= (int) $selectedVehicleContextId ?>">
                        <?php endif; ?>
                        <div class="form-grid customer-search-grid">
                            <div class="form-group">
                                <label for="search">Search Jobs</label>
                                <input class="form-control" type="text" id="search" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search by customer, vehicle, mechanic, plate, or job ID">
                            </div>
                            <div class="form-group">
                                <label for="status">Status Filter</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="">All statuses</option>
                                    <option value="ongoing"<?= $statusFilter === 'ongoing' ? ' selected' : '' ?>>Ongoing</option>
                                    <option value="completed"<?= $statusFilter === 'completed' ? ' selected' : '' ?>>Completed</option>
                                </select>
                            </div>
                            <div class="feature-toggle-actions">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="jobs.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($jobs)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($jobs as $job): ?>
                                <?php $isSelected = (int) $job['job_id'] === $selectedJobId; ?>
                                <a class="dashboard-list-item dashboard-link-card<?= $isSelected ? ' is-selected' : '' ?>" href="<?= htmlspecialchars(jobUrl((int) $job['job_id'], $search, $statusFilter, [], $selectedCustomerContextId, $selectedVehicleContextId), ENT_QUOTES, 'UTF-8') ?>">
                                    <div>
                                        <strong>Job #<?= (int) $job['job_id'] ?> - <?= htmlspecialchars($job['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars(jobVehicleLabel($job), ENT_QUOTES, 'UTF-8') ?>
                                            | <?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                    </div>
                                    <span class="status-chip status-<?= htmlspecialchars($job['status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($job['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <strong>No jobs in this view</strong>
                            <p><?= ($selectedCustomerContext || $selectedVehicleContext) ? 'No jobs matched this context and current filter.' : 'No jobs matched your current filter.' ?></p>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3><?= $selectedJob ? 'Update Job' : 'Job Details' ?></h3>
                    <p><?= $selectedJob ? 'Assign or reassign a mechanic, then keep the job status updated as work moves forward.' : 'Approved appointments create jobs automatically, so the next job will appear here once one exists.' ?></p>

                    <?php if ($selectedJob): ?>
                        <div class="job-detail-tabs" data-job-tabs>
                            <div class="panel-tabs" role="tablist" aria-label="Job sections">
                                <button type="button" class="panel-tab is-active" role="tab" aria-selected="true" data-job-tab="overview" id="job-tab-overview">Overview</button>
                                <button type="button" class="panel-tab" role="tab" aria-selected="false" data-job-tab="activity" id="job-tab-activity" tabindex="-1">Activity log</button>
                                <button type="button" class="panel-tab" role="tab" aria-selected="false" data-job-tab="services" id="job-tab-services" tabindex="-1">Services</button>
                                <?php if ($showInventory): ?>
                                    <button type="button" class="panel-tab" role="tab" aria-selected="false" data-job-tab="parts" id="job-tab-parts" tabindex="-1">Parts</button>
                                <?php endif; ?>
                            </div>
                            <div class="panel-tab-panels">
                                <div class="panel-tab-panel is-active" role="tabpanel" id="job-panel-overview" aria-labelledby="job-tab-overview" data-job-panel="overview">
                        <?php if ($jobSourceAppointmentLocked): ?>
                            <div class="alert alert-error">
                                This job is linked to an appointment that is no longer in the approved state. Job reassignment and progress updates are locked until the source appointment data is corrected.
                            </div>
                        <?php endif; ?>

                        <?php if (!$jobSourceAppointmentLocked): ?>
                            <form action="actions/update_job.php" method="POST" class="feature-toggle-form">
                                <input type="hidden" name="job_id" value="<?= (int) $selectedJob['job_id'] ?>">

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="mechanic_id">Assigned Mechanic</label>
                                        <select class="form-control" id="mechanic_id" name="mechanic_id">
                                            <option value="0">Unassigned</option>
                                            <?php foreach ($mechanicOptions as $mechanic): ?>
                                                <option value="<?= (int) $mechanic['user_id'] ?>"<?= $selectedMechanicId === (int) $mechanic['user_id'] ? ' selected' : '' ?>>
                                                    <?= htmlspecialchars($mechanic['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="job_status">Job Status</label>
                                        <select class="form-control" id="job_status" name="status" required>
                                            <option value="ongoing"<?= $selectedStatus === 'ongoing' ? ' selected' : '' ?>>Ongoing</option>
                                            <option value="completed"<?= $selectedStatus === 'completed' ? ' selected' : '' ?>>Completed</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="approval-actions">
                                    <button type="submit" class="btn btn-primary">Save Job Changes</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Job Summary</strong>
                                    <p>
                                        Appointment <?= htmlspecialchars(jobDate($selectedJob['appointment_date']), ENT_QUOTES, 'UTF-8') ?>
                                        | <?= htmlspecialchars($selectedJob['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                                        | <?= htmlspecialchars(jobVehicleLabel($selectedJob), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <div class="list-meta">
                                    <span class="status-chip status-<?= htmlspecialchars($selectedJob['status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($selectedJob['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="status-chip status-<?= htmlspecialchars($selectedJob['appointment_status'], ENT_QUOTES, 'UTF-8') ?>">
                                        Appointment <?= htmlspecialchars(ucfirst($selectedJob['appointment_status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Related Records</strong>
                                    <p>Open the linked customer, vehicle, and billing record for this selected job.</p>
                                </div>
                                <div class="entity-action-group">
                                    <a href="<?= htmlspecialchars(jobCustomerUrl((int) $selectedJob['customer_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Open Customer</a>
                                    <a href="<?= htmlspecialchars(jobVehicleUrl((int) $selectedJob['vehicle_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Open Vehicle</a>
                                    <?php if ($showInvoicing): ?>
                                        <a href="<?= htmlspecialchars(jobInvoiceUrl($jobInvoiceId, (int) $selectedJob['job_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">
                                            <?= $jobHasInvoice ? 'Open Invoice' : 'Create Invoice' ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!$jobSourceAppointmentLocked): ?>
                            <form action="actions/update_job_status.php" method="POST" class="feature-toggle-form">
                                <input type="hidden" name="job_id" value="<?= (int) $selectedJob['job_id'] ?>">
                                <div class="approval-actions">
                                    <?php if ($selectedJob['status'] !== 'ongoing'): ?>
                                        <button type="submit" name="status" value="ongoing" class="btn btn-secondary">Mark Ongoing</button>
                                    <?php endif; ?>
                                    <?php if ($selectedJob['status'] !== 'completed'): ?>
                                        <button type="submit" name="status" value="completed" class="btn btn-primary">Mark Completed</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php endif; ?>

                                </div>
                                <div class="panel-tab-panel" role="tabpanel" id="job-panel-activity" aria-labelledby="job-tab-activity" data-job-panel="activity" hidden>
                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Recent Status History</strong>
                                    <p>The database trigger records each job update into the job status log.</p>
                                </div>
                            </div>

                            <?php if (!empty($jobLogs)): ?>
                                <?php foreach ($jobLogs as $log): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars(ucfirst($log['status']), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p><?= htmlspecialchars(jobDateTime($log['changed_at']), ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <strong>No activity yet</strong>
                                    <p>Status changes will appear here once the job is updated.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                                </div>
                                <div class="panel-tab-panel" role="tabpanel" id="job-panel-services" aria-labelledby="job-tab-services" data-job-panel="services" hidden>
                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Service Lines</strong>
                                    <p>Record labor, diagnostics, and service charges that should flow into the invoice total later.</p>
                                </div>
                            </div>

                            <?php if (!empty($jobServices)): ?>
                                <?php foreach ($jobServices as $service): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                    <strong><?= htmlspecialchars($service['service_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p>
                                                Qty <?= htmlspecialchars(number_format((float) $service['quantity'], 2), ENT_QUOTES, 'UTF-8') ?>
                                                | Unit <?= htmlspecialchars(jobCurrency($service['unit_price']), ENT_QUOTES, 'UTF-8') ?>
                                                | Total <?= htmlspecialchars(jobCurrency($service['line_total']), ENT_QUOTES, 'UTF-8') ?>
                                            </p>
                                            <?php if (!empty($service['description'])): ?>
                                                <p><?= htmlspecialchars($service['description'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="list-meta">
                                            <span class="metric-pill"><?= htmlspecialchars(jobCurrency($service['line_total']), ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if ($selectedJob['status'] === 'ongoing' && !$jobHasInvoice && !$jobSourceAppointmentLocked): ?>
                                                <a href="<?= htmlspecialchars(jobUrl((int) $selectedJob['job_id'], $search, $statusFilter, ['edit_service_id' => (int) $service['job_service_id']], $selectedCustomerContextId, $selectedVehicleContextId), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Edit</a>
                                                <form action="actions/delete_job_service.php" method="POST" class="inline-form">
                                                    <input type="hidden" name="job_id" value="<?= (int) $selectedJob['job_id'] ?>">
                                                    <input type="hidden" name="job_service_id" value="<?= (int) $service['job_service_id'] ?>">
                                                    <button type="submit" class="btn btn-secondary btn-small">Remove</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="table-placeholder">
                                    No service lines have been recorded for this job yet.
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($editingService && $selectedJob['status'] === 'ongoing' && !$jobHasInvoice && !$jobSourceAppointmentLocked): ?>
                            <form action="actions/update_job_service.php" method="POST" class="feature-toggle-form">
                                <input type="hidden" name="job_id" value="<?= (int) $selectedJob['job_id'] ?>">
                                <input type="hidden" name="job_service_id" value="<?= (int) $editingService['job_service_id'] ?>">

                                <div class="dashboard-list compact-list">
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong>Edit Service Line</strong>
                                            <p>Update the service details before this job is invoiced.</p>
                                        </div>
                                        <a href="<?= htmlspecialchars(jobUrl((int) $selectedJob['job_id'], $search, $statusFilter, [], $selectedCustomerContextId, $selectedVehicleContextId), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Cancel</a>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="edit_service_name">Service Name</label>
                                    <input class="form-control" type="text" id="edit_service_name" name="service_name" value="<?= htmlspecialchars($editServiceName, ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="edit_service_description">Description</label>
                                    <textarea class="form-control form-textarea" id="edit_service_description" name="description"><?= htmlspecialchars($editServiceDescription, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="edit_service_quantity">Quantity</label>
                                        <input class="form-control" type="number" id="edit_service_quantity" name="service_quantity" min="0.01" step="0.01" value="<?= htmlspecialchars((string) $editServiceQuantity, ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label for="edit_service_unit_price">Unit Price</label>
                                        <input class="form-control" type="number" id="edit_service_unit_price" name="service_unit_price" min="0" step="0.01" value="<?= htmlspecialchars((string) $editServiceUnitPrice, ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                </div>

                                <div class="approval-actions">
                                    <button type="submit" class="btn btn-primary">Update Service Line</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <?php if ($selectedJob['status'] === 'ongoing' && !$jobHasInvoice && !$jobSourceAppointmentLocked): ?>
                            <form action="actions/create_job_service.php" method="POST" class="feature-toggle-form">
                                <input type="hidden" name="job_id" value="<?= (int) $selectedJob['job_id'] ?>">

                                <div class="form-group">
                                    <label for="service_name">Service Name</label>
                                    <input class="form-control" type="text" id="service_name" name="service_name" value="<?= htmlspecialchars($selectedServiceName, ENT_QUOTES, 'UTF-8') ?>" placeholder="Labor, oil change, diagnostics, installation, etc." required>
                                </div>

                                <div class="form-group">
                                    <label for="service_description">Description</label>
                                    <textarea class="form-control form-textarea" id="service_description" name="description"><?= htmlspecialchars($selectedServiceDescription, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="service_quantity">Quantity</label>
                                        <input class="form-control" type="number" id="service_quantity" name="service_quantity" min="0.01" step="0.01" value="<?= htmlspecialchars((string) $selectedServiceQuantity, ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label for="service_unit_price">Unit Price</label>
                                        <input class="form-control" type="number" id="service_unit_price" name="service_unit_price" min="0" step="0.01" value="<?= htmlspecialchars((string) $selectedServiceUnitPrice, ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                </div>

                                <div class="approval-actions">
                                    <button type="submit" class="btn btn-primary">Add Service Line</button>
                                </div>
                            </form>
                        <?php elseif ($jobSourceAppointmentLocked): ?>
                            <div class="table-placeholder">
                                Service line entry is locked because the source appointment is no longer in the approved state.
                            </div>
                        <?php elseif ($jobHasInvoice): ?>
                            <div class="table-placeholder">
                                Service line entry is locked because this job already has an invoice.
                                <a href="<?= htmlspecialchars(jobInvoiceUrl($jobInvoiceId, (int) $selectedJob['job_id']), ENT_QUOTES, 'UTF-8') ?>">Open the invoice</a> to continue billing work safely.
                            </div>
                        <?php else: ?>
                            <div class="table-placeholder">
                                Service line entry is locked because this job is already marked completed.
                            </div>
                        <?php endif; ?>

                                </div>
                        <?php if ($showInventory): ?>
                                <div class="panel-tab-panel" role="tabpanel" id="job-panel-parts" aria-labelledby="job-tab-parts" data-job-panel="parts" hidden>
                            <div class="dashboard-list compact-list">
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong>Parts Used</strong>
                                        <p>Record stock consumption for this job using tenant-safe inventory items.</p>
                                    </div>
                                </div>

                                <?php if (!empty($jobPartsUsed)): ?>
                                    <?php foreach ($jobPartsUsed as $partUsage): ?>
                                        <div class="dashboard-list-item">
                                            <div>
                                                <strong><?= htmlspecialchars($partUsage['part_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <p>
                                                    <?= htmlspecialchars($partUsage['sku'] ?: 'No SKU', ENT_QUOTES, 'UTF-8') ?>
                                                    | Qty used <?= number_format((int) $partUsage['quantity_used']) ?>
                                                    | Est. <?= htmlspecialchars(jobCurrency((float) $partUsage['unit_cost'] * (int) $partUsage['quantity_used']), ENT_QUOTES, 'UTF-8') ?>
                                                </p>
                                            </div>
                                            <div class="list-meta">
                                                <span class="metric-pill"><?= number_format((int) $partUsage['quantity_used']) ?></span>
                                                <?php if ($selectedJob['status'] === 'ongoing' && !$jobHasInvoice && !$jobSourceAppointmentLocked): ?>
                                                    <a href="<?= htmlspecialchars(jobUrl((int) $selectedJob['job_id'], $search, $statusFilter, ['edit_part_usage_id' => (int) $partUsage['id']], $selectedCustomerContextId, $selectedVehicleContextId), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Edit</a>
                                                    <form action="actions/delete_job_part_usage.php" method="POST" class="inline-form">
                                                        <input type="hidden" name="job_id" value="<?= (int) $selectedJob['job_id'] ?>">
                                                        <input type="hidden" name="job_part_usage_id" value="<?= (int) $partUsage['id'] ?>">
                                                        <button type="submit" class="btn btn-secondary btn-small">Remove</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="table-placeholder">
                                        No inventory items have been consumed for this job yet.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($editingPartUsage && $selectedJob['status'] === 'ongoing' && !$jobHasInvoice && !$jobSourceAppointmentLocked): ?>
                                <form action="actions/update_job_part_usage.php" method="POST" class="feature-toggle-form">
                                    <input type="hidden" name="job_id" value="<?= (int) $selectedJob['job_id'] ?>">
                                    <input type="hidden" name="job_part_usage_id" value="<?= (int) $editingPartUsage['id'] ?>">

                                    <div class="dashboard-list compact-list">
                                        <div class="dashboard-list-item">
                                            <div>
                                                <strong>Edit Parts Usage</strong>
                                                <p>Adjust the part item or quantity while the job is still open and not yet invoiced.</p>
                                            </div>
                                            <a href="<?= htmlspecialchars(jobUrl((int) $selectedJob['job_id'], $search, $statusFilter, [], $selectedCustomerContextId, $selectedVehicleContextId), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-small">Cancel</a>
                                        </div>
                                    </div>

                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label for="edit_inventory_id">Inventory Item</label>
                                            <select class="form-control" id="edit_inventory_id" name="inventory_id" required>
                                                <option value="">Select inventory item</option>
                                                <?php foreach ($inventoryOptions as $inventoryItem): ?>
                                                    <option value="<?= (int) $inventoryItem['inventory_id'] ?>"<?= $editPartInventoryId === (int) $inventoryItem['inventory_id'] ? ' selected' : '' ?>>
                                                        <?= htmlspecialchars($inventoryItem['part_name'], ENT_QUOTES, 'UTF-8') ?>
                                                        <?= !empty($inventoryItem['sku']) ? ' - ' . htmlspecialchars($inventoryItem['sku'], ENT_QUOTES, 'UTF-8') : '' ?>
                                                        (Stock: <?= number_format((int) $inventoryItem['quantity']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="form-group">
                                            <label for="edit_quantity_used">Quantity Used</label>
                                            <input class="form-control" type="number" id="edit_quantity_used" name="quantity_used" min="1" value="<?= htmlspecialchars((string) $editPartQuantity, ENT_QUOTES, 'UTF-8') ?>" required>
                                        </div>
                                    </div>

                                    <div class="approval-actions">
                                        <button type="submit" class="btn btn-primary">Update Parts Usage</button>
                                    </div>
                                </form>
                            <?php endif; ?>

                            <?php if ($selectedJob['status'] === 'ongoing' && !$jobHasInvoice && !$jobSourceAppointmentLocked): ?>
                                <?php if (!empty($inventoryOptions)): ?>
                                    <form action="actions/create_job_part_usage.php" method="POST" class="feature-toggle-form">
                                        <input type="hidden" name="job_id" value="<?= (int) $selectedJob['job_id'] ?>">

                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label for="inventory_id">Inventory Item</label>
                                                <select class="form-control" id="inventory_id" name="inventory_id" required>
                                                    <option value="">Select inventory item</option>
                                                    <?php foreach ($inventoryOptions as $inventoryItem): ?>
                                                        <option value="<?= (int) $inventoryItem['inventory_id'] ?>"<?= $selectedInventoryUsageId === (int) $inventoryItem['inventory_id'] ? ' selected' : '' ?>>
                                                            <?= htmlspecialchars($inventoryItem['part_name'], ENT_QUOTES, 'UTF-8') ?>
                                                            <?= !empty($inventoryItem['sku']) ? ' - ' . htmlspecialchars($inventoryItem['sku'], ENT_QUOTES, 'UTF-8') : '' ?>
                                                            (Stock: <?= number_format((int) $inventoryItem['quantity']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="form-group">
                                                <label for="quantity_used">Quantity Used</label>
                                                <input class="form-control" type="number" id="quantity_used" name="quantity_used" min="1" value="<?= htmlspecialchars((string) $selectedUsageQuantity, ENT_QUOTES, 'UTF-8') ?>" required>
                                            </div>
                                        </div>

                                        <div class="approval-actions">
                                            <button type="submit" class="btn btn-primary">Add Parts Usage</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="table-placeholder">
                                        No active inventory items are available yet. Add stock items in the Inventory module first.
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($jobSourceAppointmentLocked): ?>
                                <div class="table-placeholder">
                                    Parts usage is locked because the source appointment is no longer in the approved state.
                                </div>
                            <?php elseif ($jobHasInvoice): ?>
                                <div class="table-placeholder">
                                    Parts usage is locked because this job already has an invoice.
                                    <a href="<?= htmlspecialchars(jobInvoiceUrl($jobInvoiceId, (int) $selectedJob['job_id']), ENT_QUOTES, 'UTF-8') ?>">Open the invoice</a> to review the billing record.
                                </div>
                            <?php else: ?>
                                <div class="table-placeholder">
                                    Parts usage is locked because this job is already marked completed.
                                </div>
                            <?php endif; ?>
                                </div>
                        <?php endif; ?>
                        </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <strong>Select a job</strong>
                            <p>Signed in as <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>. Choose a job from the queue to view details. Data is scoped to <code>tenant_id = <?= $tenantId ?></code>.</p>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        </main>
    </div>

    <?= renderTenantAdminFooterScripts() ?>
    <script src="../assets/js/job-tabs.js"></script>
</body>
</html>
