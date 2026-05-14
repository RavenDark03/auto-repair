<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
requireTenantFeature('appointments');

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$selectedAppointmentId = (int) ($_GET['appointment_id'] ?? 0);
$selectedCustomerContextId = (int) ($_GET['customer_id'] ?? 0);
$selectedVehicleContextId = (int) ($_GET['vehicle_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$flashMessage = $_SESSION['appointment_success'] ?? null;
$errorMessage = $_SESSION['appointment_error'] ?? null;
$oldInput = $_SESSION['appointment_old_input'] ?? [];
unset($_SESSION['appointment_success'], $_SESSION['appointment_error'], $_SESSION['appointment_old_input']);

$appointments = [];
$selectedAppointment = null;
$selectedCustomerContext = null;
$selectedVehicleContext = null;
$customerOptions = [];
$vehicleOptions = [];
$mechanicOptions = [];
$allowedStatuses = ['pending', 'approved', 'cancelled'];
$selectedAppointmentHasInactiveLinks = false;
$appointmentSummary = [
    'total_appointments' => 0,
    'pending_appointments' => 0,
    'approved_appointments' => 0,
    'cancelled_appointments' => 0,
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}


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

    $mechanicOptionsStmt = $pdo->prepare("
        SELECT user_id, full_name, status
        FROM users
        WHERE tenant_id = :tenant_id
          AND role = 'mechanic'
          AND status = 'active'
        ORDER BY full_name ASC, user_id ASC
    ");
    $mechanicOptionsStmt->execute(['tenant_id' => $tenantId]);
    $mechanicOptions = $mechanicOptionsStmt->fetchAll();

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

    $vehicleOptionsStmt = $pdo->prepare("
        SELECT
            v.vehicle_id,
            v.customer_id,
            v.make,
            v.model,
            v.year_model,
            v.plate,
            v.status,
            c.name AS customer_name
        FROM vehicles v
        INNER JOIN customers c
            ON c.customer_id = v.customer_id
           AND c.tenant_id = v.tenant_id
        WHERE v.tenant_id = :tenant_id
          " . ($selectedCustomerContextId > 0 ? " AND v.customer_id = :customer_id " : "") . "
        ORDER BY c.name ASC, v.make ASC, v.model ASC, v.vehicle_id ASC
    ");
    $vehicleOptionParams = ['tenant_id' => $tenantId];
    if ($selectedCustomerContextId > 0) {
        $vehicleOptionParams['customer_id'] = $selectedCustomerContextId;
    }
    $vehicleOptionsStmt->execute($vehicleOptionParams);
    $vehicleOptions = $vehicleOptionsStmt->fetchAll();

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
            COUNT(*) AS total_appointments,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_appointments,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_appointments,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_appointments
        FROM appointments
        WHERE tenant_id = :tenant_id
    ";
    $summaryParams = ['tenant_id' => $tenantId];
    if ($selectedCustomerContextId > 0) {
        $summarySql .= " AND customer_id = :summary_customer_id ";
        $summaryParams['summary_customer_id'] = $selectedCustomerContextId;
    }
    if ($selectedVehicleContextId > 0) {
        $summarySql .= " AND vehicle_id = :summary_vehicle_id ";
        $summaryParams['summary_vehicle_id'] = $selectedVehicleContextId;
    }
    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($summaryParams);
    $summaryRow = $summaryStmt->fetch();

    if ($summaryRow) {
        foreach ($appointmentSummary as $key => $unused) {
            $appointmentSummary[$key] = (int) ($summaryRow[$key] ?? 0);
        }
    }

    $appointmentSql = "
        SELECT
            a.appointment_id,
            a.customer_id,
            a.vehicle_id,
            a.mechanic_id,
            a.appointment_date,
            a.concern,
            a.cancellation_reason,
            a.status,
            c.name AS customer_name,
            c.contact AS customer_contact,
            v.make,
            v.model,
            v.year_model,
            v.plate,
            u.full_name AS mechanic_name
        FROM appointments a
        INNER JOIN customers c
            ON c.customer_id = a.customer_id
           AND c.tenant_id = a.tenant_id
        INNER JOIN vehicles v
            ON v.vehicle_id = a.vehicle_id
           AND v.tenant_id = a.tenant_id
        LEFT JOIN users u
            ON u.user_id = a.mechanic_id
           AND u.tenant_id = a.tenant_id
        WHERE a.tenant_id = :tenant_id
    ";
    $params = ['tenant_id' => $tenantId];

    if ($selectedCustomerContextId > 0) {
        $appointmentSql .= " AND a.customer_id = :customer_id ";
        $params['customer_id'] = $selectedCustomerContextId;
    }

    if ($selectedVehicleContextId > 0) {
        $appointmentSql .= " AND a.vehicle_id = :vehicle_id ";
        $params['vehicle_id'] = $selectedVehicleContextId;
    }

    if ($search !== '') {
        $appointmentSql .= "
          AND (
                c.name LIKE :search
             OR c.contact LIKE :search
             OR v.make LIKE :search
             OR v.model LIKE :search
             OR v.plate LIKE :search
             OR DATE_FORMAT(a.appointment_date, '%Y-%m-%d') LIKE :search
          )
        ";
        $params['search'] = '%' . $search . '%';
    }

    if ($statusFilter !== '') {
        $appointmentSql .= " AND a.status = :status ";
        $params['status'] = $statusFilter;
    }

    $appointmentSql .= " ORDER BY a.appointment_date ASC, a.appointment_id ASC";
    $appointmentStmt = $pdo->prepare($appointmentSql);
    $appointmentStmt->execute($params);
    $appointments = $appointmentStmt->fetchAll();

    if ($selectedAppointmentId === 0 && !empty($appointments)) {
        $selectedAppointmentId = (int) $appointments[0]['appointment_id'];
    }

    if ($selectedAppointmentId > 0) {
        $selectedAppointmentStmt = $pdo->prepare("
            SELECT
                a.appointment_id,
                a.customer_id,
                a.vehicle_id,
                a.mechanic_id,
                a.appointment_date,
                a.concern,
                a.cancellation_reason,
                a.cancelled_at,
                a.status,
                c.name AS customer_name,
                c.contact AS customer_contact,
                c.status AS customer_status,
                v.make,
                v.model,
                v.year_model,
                v.plate,
                v.status AS vehicle_status,
                u.full_name AS mechanic_name
            FROM appointments a
            INNER JOIN customers c
                ON c.customer_id = a.customer_id
               AND c.tenant_id = a.tenant_id
            INNER JOIN vehicles v
                ON v.vehicle_id = a.vehicle_id
               AND v.tenant_id = a.tenant_id
            LEFT JOIN users u
                ON u.user_id = a.mechanic_id
               AND u.tenant_id = a.tenant_id
            WHERE a.appointment_id = :appointment_id
              AND a.tenant_id = :tenant_id
            LIMIT 1
        ");
        $selectedAppointmentStmt->execute([
            'appointment_id' => $selectedAppointmentId,
            'tenant_id' => $tenantId,
        ]);
        $selectedAppointment = $selectedAppointmentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$selectedAppointment) {
            $selectedAppointment = null;
        } else {

            $selectedAppointmentHasInactiveLinks =
                ($selectedAppointment['customer_status'] ?? 'active') !== 'active'
                || ($selectedAppointment['vehicle_status'] ?? 'active') !== 'active';

            if ($selectedCustomerContextId === 0) {
                $selectedCustomerContextId = (int) $selectedAppointment['customer_id'];
                $selectedCustomerContext = [
                    'customer_id' => $selectedAppointment['customer_id'],
                    'name' => $selectedAppointment['customer_name'],
                    'status' => $selectedAppointment['customer_status'] ?? 'active',
                ];
            }

            if ($selectedVehicleContextId === 0) {
                $selectedVehicleContextId = (int) $selectedAppointment['vehicle_id'];
                $selectedVehicleContext = [
                    'vehicle_id' => $selectedAppointment['vehicle_id'],
                    'customer_id' => $selectedAppointment['customer_id'],
                    'make' => $selectedAppointment['make'],
                    'model' => $selectedAppointment['model'],
                    'year_model' => $selectedAppointment['year_model'],
                    'plate' => $selectedAppointment['plate'],
                    'customer_name' => $selectedAppointment['customer_name'],
                    'status' => $selectedAppointment['vehicle_status'] ?? 'active',
                ];
            }
        }
    }
} catch (PDOException $e) {
    $errorMessage = 'Appointments module could not be loaded: ' . $e->getMessage();
}

$showAnalytics = tenantHasFeature('reports', $tenantId);
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId);

function appointmentDate($date) {
    if (!$date) {
        return 'No date';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y', $timestamp) : $date;
}

function appointmentVehicleLabel($vehicle) {
    return formatVehicleLabel($vehicle, true, true);
}

function appointmentContextUrl($appointmentId = 0, $customerId = 0, $vehicleId = 0, $search = '', $status = '') {
    $params = [];

    if ($appointmentId > 0) {
        $params['appointment_id'] = (int) $appointmentId;
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

    return buildPageUrl('appointments.php', $params);
}

$selAppt = $selectedAppointment ?? [];
$selectedAppointmentStatus = $oldInput['status'] ?? ($selAppt['status'] ?? 'pending');
$selectedCustomerValue = (int) ($oldInput['customer_id'] ?? ($selAppt['customer_id'] ?? 0));
$selectedVehicleValue = (int) ($oldInput['vehicle_id'] ?? ($selAppt['vehicle_id'] ?? 0));
$selectedMechanicValue = (int) ($oldInput['mechanic_id'] ?? ($selAppt['mechanic_id'] ?? 0));
$approvedAppointmentLocked = is_array($selectedAppointment) && ($selectedAppointment['status'] ?? '') === 'approved';

$mechanixAppointmentModalOpen = '';
if ($errorMessage !== null) {
    $mechanixAppointmentModalOpen = is_array($selectedAppointment) ? 'edit' : 'add';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments - MECHANIX</title>
    <?= mechanix_link_styles_tabler_workspace('../assets/css/') ?>
</head>
<body class="page-shell antialiased tenant-app"<?php if ($mechanixAppointmentModalOpen !== ''): ?> data-mechanix-open-appointment-modal="<?= htmlspecialchars($mechanixAppointmentModalOpen, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'appointments.php', $showAnalytics) ?>

        <main class="dashboard-main" id="main-content" tabindex="-1">
            <?= renderTenantAdminTopbar(
                'Appointments',
                'Schedule and approve tenant-scoped service appointments linked to the right customer and vehicle.',
                ($selectedAppointment || $selectedCustomerContext || $selectedVehicleContext)
                    ? renderContextStrip(
                        array_values(array_filter([
                            ['label' => 'Dashboard', 'href' => 'dashboard.php'],
                            $selectedCustomerContext ? ['label' => $selectedCustomerContext['name'], 'href' => buildPageUrl('customers.php', ['customer_id' => (int) $selectedCustomerContext['customer_id']])] : null,
                            $selectedVehicleContext ? ['label' => appointmentVehicleLabel($selectedVehicleContext), 'href' => buildPageUrl('vehicles.php', ['vehicle_id' => (int) $selectedVehicleContext['vehicle_id'], 'customer_id' => (int) $selectedVehicleContext['customer_id']])] : null,
                            ['label' => 'Appointments', 'href' => appointmentContextUrl(0, $selectedCustomerContextId, $selectedVehicleContextId)],
                        ])),
                        $selectedAppointment ? 'Appointment #' . (int) $selectedAppointment['appointment_id'] : ($selectedVehicleContext ? 'Vehicle Appointments' : 'Customer Appointments'),
                        ($selectedCustomerContextId > 0 || $selectedVehicleContextId > 0) ? [['label' => 'Clear Context', 'href' => 'appointments.php']] : []
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
                    <span>Total Appointments</span>
                    <h3><?= number_format($appointmentSummary['total_appointments']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Pending</span>
                    <h3><?= number_format($appointmentSummary['pending_appointments']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Approved</span>
                    <h3><?= number_format($appointmentSummary['approved_appointments']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Cancelled</span>
                    <h3><?= number_format($appointmentSummary['cancelled_appointments']) ?></h3>
                </article>
            </section>

            <section class="content-grid vehicle-grid">
                <article class="content-card">
                    <h3>Appointment Queue</h3>
                    <p>
                        <?php if ($selectedVehicleContext): ?>
                            Showing only appointments for <?= htmlspecialchars(appointmentVehicleLabel($selectedVehicleContext), ENT_QUOTES, 'UTF-8') ?>.
                        <?php elseif ($selectedCustomerContext): ?>
                            Showing only appointments for <?= htmlspecialchars($selectedCustomerContext['name'], ENT_QUOTES, 'UTF-8') ?>.
                        <?php else: ?>
                            Search by customer, vehicle, plate number, or appointment date, then narrow the queue by status.
                        <?php endif; ?>
                    </p>

                    <form action="appointments.php" method="GET" class="feature-toggle-form">
                        <?php if ($selectedCustomerContextId > 0): ?>
                            <input type="hidden" name="customer_id" value="<?= (int) $selectedCustomerContextId ?>">
                        <?php endif; ?>
                        <?php if ($selectedVehicleContextId > 0): ?>
                            <input type="hidden" name="vehicle_id" value="<?= (int) $selectedVehicleContextId ?>">
                        <?php endif; ?>
                        <div class="form-grid customer-search-grid">
                            <div class="form-group">
                                <label for="search">Search Appointments</label>
                                <input class="form-control" type="text" id="search" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search by customer, vehicle, plate, or date">
                            </div>
                            <div class="form-group">
                                <label for="status">Status Filter</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="">All statuses</option>
                                    <option value="pending"<?= $statusFilter === 'pending' ? ' selected' : '' ?>>Pending</option>
                                    <option value="approved"<?= $statusFilter === 'approved' ? ' selected' : '' ?>>Approved</option>
                                    <option value="cancelled"<?= $statusFilter === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="feature-toggle-actions">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="appointments.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($appointments)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($appointments as $appointment): ?>
                                <?php $isSelected = (int) $appointment['appointment_id'] === $selectedAppointmentId; ?>
                                <a class="dashboard-list-item dashboard-link-card<?= $isSelected ? ' is-selected' : '' ?>" href="<?= htmlspecialchars(appointmentContextUrl((int) $appointment['appointment_id'], $selectedCustomerContextId, $selectedVehicleContextId, $search, $statusFilter), ENT_QUOTES, 'UTF-8') ?>">
                                    <div>
                                        <strong><?= htmlspecialchars($appointment['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars(appointmentVehicleLabel($appointment), ENT_QUOTES, 'UTF-8') ?>
                                            | <?= htmlspecialchars(appointmentDate($appointment['appointment_date']), ENT_QUOTES, 'UTF-8') ?>
                                            | <?= htmlspecialchars($appointment['mechanic_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                        <?php if (!empty($appointment['cancellation_reason'])): ?>
                                            <p>Cancelled: <?= htmlspecialchars($appointment['cancellation_reason'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <span class="status-chip status-<?= htmlspecialchars($appointment['status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($appointment['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <strong>No appointments in this view</strong>
                            <p><?= ($selectedCustomerContext || $selectedVehicleContext) ? 'No appointments matched this context and current filter.' : 'No appointments matched your current filter.' ?></p>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3>Appointment details</h3>
                    <p><?= is_array($selectedAppointment) ? 'Review this booking, open edit to change fields, or use quick actions below for status shortcuts.' : 'Create a new appointment linked to one customer and one vehicle, or browse the queue to edit an existing booking.' ?></p>

                    <?php if (is_array($selectedAppointment) && $selectedAppointmentHasInactiveLinks): ?>
                        <div class="alert alert-error">
                            This appointment is linked to an inactive customer or inactive vehicle. Keep it for history if needed, but switch it back to active records before reusing this booking.
                        </div>
                    <?php endif; ?>

                    <?php $canSchedule = !empty($customerOptions) && !empty($vehicleOptions); ?>

                    <div class="approval-actions flex-wrap mb-3">
                        <button
                            type="button"
                            class="btn btn-primary"
                            data-bs-toggle="modal"
                            data-bs-target="#mechanixAppointmentModalAdd"
                            <?= $canSchedule ? '' : ' disabled title="Add at least one customer and vehicle first"' ?>
                        >
                            Add appointment
                        </button>
                        <?php if (is_array($selectedAppointment)): ?>
                            <?php $canScheduleEdit = $canSchedule || $selectedAppointmentHasInactiveLinks || !empty($oldInput); ?>
                            <button
                                type="button"
                                class="btn btn-secondary"
                                data-bs-toggle="modal"
                                data-bs-target="#mechanixAppointmentModalEdit"
                                <?= $canScheduleEdit ? '' : ' disabled title="Missing customer or vehicle records"' ?>
                            >
                                Edit booking
                            </button>
                            <a href="appointments.php" class="btn btn-secondary">New appointment</a>
                        <?php endif; ?>
                    </div>

                    <?php if (!$canSchedule): ?>
                        <div class="table-placeholder">
                            Add at least one customer and one vehicle first before creating appointments.
                        </div>
                    <?php endif; ?>

                    <?php if (is_array($selectedAppointment)): ?>
                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Appointment status</strong>
                                    <p>
                                        <?= htmlspecialchars((string) $selectedAppointment['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($selectedAppointment['customer_contact'])): ?>
                                            | <?= htmlspecialchars((string) $selectedAppointment['customer_contact'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                        | <?= htmlspecialchars(appointmentVehicleLabel($selectedAppointment), ENT_QUOTES, 'UTF-8') ?>
                                        | <?= htmlspecialchars((string) ($selectedAppointment['mechanic_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                    <?php if (!empty($selectedAppointment['concern'])): ?>
                                        <p>Concern: <?= htmlspecialchars((string) $selectedAppointment['concern'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($selectedAppointment['cancellation_reason'])): ?>
                                        <p>Cancellation reason: <?= htmlspecialchars((string) $selectedAppointment['cancellation_reason'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <?php endif; ?>
                                </div>
                                <span class="status-chip status-<?= htmlspecialchars((string) $selectedAppointment['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucfirst((string) $selectedAppointment['status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($selectedAppointment['status'] === 'approved'): ?>
                            <div class="table-placeholder">
                                This appointment is already approved. The linked job should have been created by the database trigger, so the status is now locked to prevent duplicate job creation.
                            </div>
                        <?php else: ?>
                            <form action="actions/update_appointment_status.php" method="POST" class="feature-toggle-form">
                                <input type="hidden" name="appointment_id" value="<?= (int) $selectedAppointment['appointment_id'] ?>">

                                <div class="form-group">
                                    <label for="quick_cancellation_reason">Cancellation reason</label>
                                    <textarea class="form-control form-textarea" id="quick_cancellation_reason" name="cancellation_reason" placeholder="Required when cancelling"><?= htmlspecialchars((string) ($selectedAppointment['cancellation_reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>

                                <div class="approval-actions">
                                    <?php if ($selectedAppointment['status'] !== 'pending'): ?>
                                        <button type="submit" name="status" value="pending" class="btn btn-secondary">Set pending</button>
                                    <?php endif; ?>
                                    <?php if ($selectedAppointment['status'] !== 'approved'): ?>
                                        <button type="submit" name="status" value="approved" class="btn btn-primary">Approve appointment</button>
                                    <?php endif; ?>
                                    <?php if ($selectedAppointment['status'] !== 'cancelled'): ?>
                                        <button type="submit" name="status" value="cancelled" class="btn btn-secondary">Cancel appointment</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="table-placeholder">
                            <strong>Current tenant admin</strong><br>
                            Signed in as <?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin User', ENT_QUOTES, 'UTF-8') ?>. Every appointment on this page is filtered by <code>tenant_id = <?= $tenantId ?></code>.
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        </main>
    </div>

    <?php include __DIR__ . '/../includes/partials/admin_appointments_modals.php'; ?>

    <?= renderTenantAdminFooterScripts() ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        function syncApptModal(modalRoot) {
            var sync = modalRoot.querySelector('[data-mechanix-appt-sync]');
            if (!sync) {
                return;
            }

            var customerSelect = sync.querySelector('.mechanix-appt-customer');
            var vehicleSelect = sync.querySelector('.mechanix-appt-vehicle');
            if (!customerSelect || !vehicleSelect) {
                return;
            }

            function syncVehicleOptions() {
                var selectedCustomerId = customerSelect.value;
                var hasSelectedVehicle = false;

                Array.prototype.forEach.call(vehicleSelect.options, function (option, index) {
                    if (index === 0) {
                        return;
                    }

                    var optionCustomerId = option.getAttribute('data-customer-id');
                    var shouldShow = selectedCustomerId !== '' && optionCustomerId === selectedCustomerId;

                    option.hidden = !shouldShow;
                    option.disabled = !shouldShow;

                    if (shouldShow && option.selected) {
                        hasSelectedVehicle = true;
                    }
                });

                if (!hasSelectedVehicle) {
                    vehicleSelect.value = '';
                }
            }

            customerSelect.addEventListener('change', syncVehicleOptions);
            syncVehicleOptions();
        }

        ['mechanixAppointmentModalAdd', 'mechanixAppointmentModalEdit'].forEach(function (modalId) {
            var modal = document.getElementById(modalId);
            if (!modal) {
                return;
            }

            modal.addEventListener('shown.bs.modal', function () {
                syncApptModal(modal);
            });
            syncApptModal(modal);
        });
    });
    </script>
</body>
</html>
