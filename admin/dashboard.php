<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/platform_rules.php';

requireAdmin();

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$fullName = $_SESSION['full_name'] ?? 'Admin User';
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$currentRole = 'admin';
$authErrorMessage = $_SESSION['auth_error'] ?? null;
if ($authErrorMessage === null) {
    $authErrorMessage = $_SESSION['error_message'] ?? null;
}
unset($_SESSION['auth_error']);
unset($_SESSION['error_message']);
$subscriptionNotice = null;

$metrics = [
    'customers' => 0,
    'vehicles' => 0,
    'pending_appointments' => 0,
    'ongoing_jobs' => 0,
    'completed_jobs' => 0,
    'monthly_revenue' => 0,
    'payments_received' => 0,
    'low_stock_items' => 0,
    'active_staff' => 0,
    'enabled_features' => 0,
];

$recentAppointments = [];
$mechanicWorkload = [];
$systemMessage = null;
$featureMap = [];

try {
    $pdo = Database::getInstance();

    $scalarQueries = [
        'customers' => "SELECT COUNT(*) FROM customers WHERE tenant_id = :tenant_id",
        'vehicles' => "SELECT COUNT(*) FROM vehicles WHERE tenant_id = :tenant_id",
        'pending_appointments' => "SELECT COUNT(*) FROM appointments WHERE tenant_id = :tenant_id AND status = 'pending'",
        'ongoing_jobs' => "SELECT COUNT(*) FROM jobs WHERE tenant_id = :tenant_id AND status = 'ongoing'",
        'completed_jobs' => "SELECT COUNT(*) FROM jobs WHERE tenant_id = :tenant_id AND status = 'completed'",
        'monthly_revenue' => "
            SELECT COALESCE(SUM(total), 0)
            FROM invoices
            WHERE tenant_id = :tenant_id
              AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        ",
        'payments_received' => "
            SELECT COALESCE(SUM(amount), 0)
            FROM payments
            WHERE tenant_id = :tenant_id
              AND DATE_FORMAT(payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        ",
        'low_stock_items' => "SELECT COUNT(*) FROM inventory WHERE tenant_id = :tenant_id AND quantity <= 5",
        'active_staff' => "
            SELECT COUNT(*)
            FROM users
            WHERE tenant_id = :tenant_id
              AND status = 'active'
              AND role IN ('admin', 'cashier', 'mechanic')
        ",
    ];

    foreach ($scalarQueries as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);
        $metrics[$key] = $stmt->fetchColumn() ?: 0;
    }

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

    $featureMap = getTenantFeatureMap($tenantId);
    $metrics['enabled_features'] = getTenantEnabledFeatureCount($tenantId);

    $recentAppointmentsStmt = $pdo->prepare("
        SELECT
            a.appointment_id,
            a.appointment_date,
            a.status,
            c.name AS customer_name,
            v.model AS vehicle_model,
            v.plate
        FROM appointments a
        INNER JOIN customers c
            ON c.customer_id = a.customer_id
           AND c.tenant_id = a.tenant_id
        INNER JOIN vehicles v
            ON v.vehicle_id = a.vehicle_id
           AND v.tenant_id = a.tenant_id
        WHERE a.tenant_id = :tenant_id
        ORDER BY a.appointment_date DESC, a.appointment_id DESC
        LIMIT 5
    ");
    $recentAppointmentsStmt->execute(['tenant_id' => $tenantId]);
    $recentAppointments = $recentAppointmentsStmt->fetchAll();

    $mechanicWorkloadStmt = $pdo->prepare("
        SELECT
            u.full_name,
            COUNT(j.job_id) AS assigned_jobs,
            SUM(CASE WHEN j.status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing_jobs
        FROM users u
        LEFT JOIN jobs j
            ON j.mechanic_id = u.user_id
           AND j.tenant_id = u.tenant_id
        WHERE u.tenant_id = :tenant_id
          AND u.role = 'mechanic'
          AND u.status = 'active'
        GROUP BY u.user_id, u.full_name
        ORDER BY ongoing_jobs DESC, assigned_jobs DESC, u.full_name ASC
        LIMIT 5
    ");
    $mechanicWorkloadStmt->execute(['tenant_id' => $tenantId]);
    $mechanicWorkload = $mechanicWorkloadStmt->fetchAll();
} catch (PDOException $e) {
    $systemMessage = 'Dashboard data could not be loaded: ' . $e->getMessage();
}

$showAnalytics = $currentRole === 'admin' && tenantHasFeature('reports', $tenantId);
$showAppointments = tenantHasFeature('appointments', $tenantId);
$showJobs = tenantHasFeature('jobs', $tenantId);
$showInventory = tenantHasFeature('inventory', $tenantId);
$showInvoicing = tenantHasFeature('invoicing', $tenantId);
$showPayments = tenantHasFeature('payments', $tenantId);
$showCustomerModule = tenantHasFeature('customer_module', $tenantId);
$showMechanicModule = tenantHasFeature('mechanic_module', $tenantId);
$hasExplicitFeatureSettings = tenantHasExplicitFeatureSettings($tenantId);
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId, $currentRole);

function dashboardCurrency($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function dashboardDate($date) {
    if (!$date) {
        return 'No date';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y', $timestamp) : $date;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - MECHANIX</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
</head>
<body class="antialiased">
<div class="page">
    <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'dashboard.php', $showAnalytics) ?>

    <div class="page-wrapper">
        <?= renderTenantAdminTopbar('Dashboard', "Welcome back, {$fullName}. Here's a live overview of {$businessName}.") ?>

        <div class="page-body">
            <div class="container-xl">

                <?php if ($systemMessage !== null): ?>
                    <div class="alert alert-danger" role="alert">
                        <div class="d-flex">
                            <div><i class="ti ti-alert-circle icon alert-icon"></i></div>
                            <div><?= htmlspecialchars($systemMessage, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($authErrorMessage !== null): ?>
                    <div class="alert alert-danger" role="alert">
                        <div class="d-flex">
                            <div><i class="ti ti-alert-circle icon alert-icon"></i></div>
                            <div><?= htmlspecialchars($authErrorMessage, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?= renderTenantAccessModeNotice() ?>

                <!-- Primary metrics -->
                <div class="row row-deck row-cards mb-4">
                    <?php if ($showCustomerModule): ?>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <span class="bg-blue text-white avatar"><i class="ti ti-users icon"></i></span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium">Total Customers</div>
                                            <div class="text-muted"><?= number_format((int) $metrics['customers']) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <span class="bg-azure text-white avatar"><i class="ti ti-car icon"></i></span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium">Vehicles Tracked</div>
                                            <div class="text-muted"><?= number_format((int) $metrics['vehicles']) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($showAppointments): ?>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <span class="bg-orange text-white avatar"><i class="ti ti-calendar icon"></i></span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium">Pending Appointments</div>
                                            <div class="text-muted"><?= number_format((int) $metrics['pending_appointments']) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($showJobs): ?>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <span class="bg-red text-white avatar"><i class="ti ti-tool icon"></i></span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium">Ongoing Jobs</div>
                                            <div class="text-muted"><?= number_format((int) $metrics['ongoing_jobs']) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Secondary metrics -->
                <div class="row row-deck row-cards mb-4">
                    <?php if ($showJobs): ?>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <span class="bg-green text-white avatar"><i class="ti ti-circle-check icon"></i></span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium">Completed Jobs</div>
                                            <div class="text-muted"><?= number_format((int) $metrics['completed_jobs']) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($showInvoicing): ?>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <span class="bg-purple text-white avatar"><i class="ti ti-cash icon"></i></span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium">Monthly Revenue</div>
                                            <div class="text-muted"><?= htmlspecialchars(dashboardCurrency($metrics['monthly_revenue']), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($showPayments): ?>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <span class="bg-teal text-white avatar"><i class="ti ti-credit-card icon"></i></span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium">Payments Received</div>
                                            <div class="text-muted"><?= htmlspecialchars(dashboardCurrency($metrics['payments_received']), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($showInventory): ?>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <span class="bg-yellow text-white avatar"><i class="ti ti-alert-triangle icon"></i></span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium">Low Stock Items</div>
                                            <div class="text-muted"><?= number_format((int) $metrics['low_stock_items']) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($subscriptionNotice): ?>
                    <?php include __DIR__ . '/../includes/partials/subscription_notice_card.php'; ?>
                <?php endif; ?>

                <!-- Middle row: Operational Snapshot + Mechanic Workload -->
                <div class="row row-deck row-cards mb-4">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="ti ti-info-circle me-2 text-muted"></i>Operational Snapshot</h3>
                            </div>
                            <div class="card-body pb-0">
                                <p class="text-muted small">Quick checks across staff access, feature flags, and current tenant readiness.</p>
                            </div>
                            <div class="list-group list-group-flush">
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Active Staff Accounts</div>
                                            <div class="text-muted small">Admins, cashiers, and mechanics currently marked active.</div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-blue-lt text-blue"><?= number_format((int) $metrics['active_staff']) ?></span></div>
                                    </div>
                                </div>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Enabled Tenant Features</div>
                                            <div class="text-muted small">Effective modules currently available for this tenant.</div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-blue-lt text-blue"><?= number_format((int) $metrics['enabled_features']) ?></span></div>
                                    </div>
                                </div>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Feature Mode</div>
                                            <div class="text-muted small"><?= !$hasExplicitFeatureSettings ? 'Using default access (no feature records yet).' : 'Feature toggles are actively controlling visible modules.' ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-secondary-lt"><?= !$hasExplicitFeatureSettings ? 'Default' : 'Custom' ?></span></div>
                                    </div>
                                </div>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Tenant Scope</div>
                                            <div class="text-muted small">All numbers are filtered by <code>tenant_id = <?= $tenantId ?></code>.</div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-secondary-lt">Scoped</span></div>
                                    </div>
                                </div>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Workspace Mode</div>
                                            <div class="text-muted small"><?= (($_SESSION['access_mode'] ?? 'full_access') === 'read_only') ? 'Editing is locked while the tenant is on the Read-Only plan.' : 'The tenant currently has full operational access.' ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <?php if (($_SESSION['access_mode'] ?? 'full_access') === 'read_only'): ?>
                                                <span class="badge bg-orange-lt text-orange">Read-Only</span>
                                            <?php else: ?>
                                                <span class="badge bg-green-lt text-green">Full</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="ti ti-tool me-2 text-muted"></i>Mechanic Workload</h3>
                            </div>
                            <div class="card-body pb-0">
                                <p class="text-muted small">Current job distribution for active mechanics in this tenant.</p>
                            </div>
                            <?php if (!$showMechanicModule): ?>
                                <div class="card-body">
                                    <div class="empty empty-sm">
                                        <p class="empty-title">Mechanic module disabled</p>
                                        <p class="empty-subtitle text-muted">The mechanic module is currently disabled for this tenant.</p>
                                    </div>
                                </div>
                            <?php elseif (!empty($mechanicWorkload)): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($mechanicWorkload as $mechanic): ?>
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <div class="font-weight-medium"><?= htmlspecialchars($mechanic['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="text-muted small"><?= number_format((int) $mechanic['assigned_jobs']) ?> assigned &middot; <?= number_format((int) $mechanic['ongoing_jobs']) ?> ongoing</div>
                                                </div>
                                                <div class="col-auto"><span class="badge bg-red-lt text-red"><?= number_format((int) $mechanic['ongoing_jobs']) ?></span></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="card-body">
                                    <div class="empty empty-sm">
                                        <p class="empty-title">No mechanic assignments</p>
                                        <p class="empty-subtitle text-muted">Workload data will appear once mechanics and jobs are added.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Bottom row: Recent Appointments + Build Queue -->
                <div class="row row-deck row-cards">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="ti ti-calendar me-2 text-muted"></i>Recent Appointments</h3>
                            </div>
                            <div class="card-body pb-0">
                                <p class="text-muted small">Latest bookings for this tenant, ordered by schedule date.</p>
                            </div>
                            <?php if (!$showAppointments): ?>
                                <div class="card-body">
                                    <div class="empty empty-sm">
                                        <p class="empty-title">Appointments disabled</p>
                                        <p class="empty-subtitle text-muted">The appointments module is currently disabled for this tenant.</p>
                                    </div>
                                </div>
                            <?php elseif (!empty($recentAppointments)): ?>
                                <div class="list-group list-group-flush">
                                    <?php
                                    $statusBadge = [
                                        'pending'   => 'bg-orange-lt text-orange',
                                        'confirmed' => 'bg-blue-lt text-blue',
                                        'ongoing'   => 'bg-yellow-lt text-yellow',
                                        'completed' => 'bg-green-lt text-green',
                                        'cancelled' => 'bg-red-lt text-red',
                                        'rejected'  => 'bg-red-lt text-red',
                                    ];
                                    foreach ($recentAppointments as $appointment):
                                        $status = $appointment['status'];
                                        $badgeClass = $statusBadge[$status] ?? 'bg-secondary-lt';
                                    ?>
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <div class="font-weight-medium">#<?= (int) $appointment['appointment_id'] ?> &mdash; <?= htmlspecialchars($appointment['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="text-muted small">
                                                        <?= htmlspecialchars($appointment['vehicle_model'] ?: 'Vehicle pending', ENT_QUOTES, 'UTF-8') ?>
                                                        <?php if (!empty($appointment['plate'])): ?> &middot; <?= htmlspecialchars($appointment['plate'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                                        &middot; <?= htmlspecialchars(dashboardDate($appointment['appointment_date']), ENT_QUOTES, 'UTF-8') ?>
                                                    </div>
                                                </div>
                                                <div class="col-auto">
                                                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="card-body">
                                    <div class="empty empty-sm">
                                        <p class="empty-title">No appointments yet</p>
                                        <p class="empty-subtitle text-muted">No appointments have been recorded for this tenant yet.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="ti ti-list-check me-2 text-muted"></i>Build Queue</h3>
                            </div>
                            <div class="card-body pb-0">
                                <p class="text-muted small">Recommended next admin modules based on the current project state.</p>
                            </div>
                            <div class="list-group list-group-flush">
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Staff Management</div>
                                            <div class="text-muted small">Manage tenant users, roles, and active status with tenant-aware queries.</div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-secondary-lt">1</span></div>
                                    </div>
                                </div>
                                <?php if ($showCustomerModule): ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium">Customers &amp; Vehicles</div>
                                                <div class="text-muted small">Create the main CRUD flow for service records and tenant-scoped lookups.</div>
                                            </div>
                                            <div class="col-auto"><span class="badge bg-secondary-lt">2</span></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($showAppointments || $showJobs): ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium">Appointments &amp; Jobs</div>
                                                <div class="text-muted small">Link booking intake to workshop execution and mechanic assignments.</div>
                                            </div>
                                            <div class="col-auto"><span class="badge bg-secondary-lt">3</span></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($showAnalytics): ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium">Analytics</div>
                                                <div class="text-muted small">Add date-range filters and compute summaries across core modules.</div>
                                            </div>
                                            <div class="col-auto"><span class="badge bg-secondary-lt">4</span></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?= renderTenantAdminFooterScripts() ?>
</body>
</html>
