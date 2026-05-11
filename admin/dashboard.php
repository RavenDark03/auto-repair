<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';

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
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - MECHANIX</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body class="page-shell">
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'dashboard.php', $showAnalytics) ?>

        <main class="dashboard-main">
            <?= renderTenantAdminTopbar('Dashboard', "Welcome back, {$fullName}. Here's a live overview of {$businessName}.") ?>

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

            <section class="dashboard-grid">
                <?php if ($showCustomerModule): ?>
                    <article class="metric-card">
                        <span>Total Customers</span>
                        <h3><?= number_format((int) $metrics['customers']) ?></h3>
                    </article>
                    <article class="metric-card">
                        <span>Vehicles Tracked</span>
                        <h3><?= number_format((int) $metrics['vehicles']) ?></h3>
                    </article>
                <?php endif; ?>
                <?php if ($showAppointments): ?>
                    <article class="metric-card">
                        <span>Pending Appointments</span>
                        <h3><?= number_format((int) $metrics['pending_appointments']) ?></h3>
                    </article>
                <?php endif; ?>
                <?php if ($showJobs): ?>
                    <article class="metric-card">
                        <span>Ongoing Jobs</span>
                        <h3><?= number_format((int) $metrics['ongoing_jobs']) ?></h3>
                    </article>
                <?php endif; ?>
            </section>

            <section class="dashboard-grid dashboard-grid-secondary">
                <?php if ($showJobs): ?>
                    <article class="metric-card">
                        <span>Completed Jobs</span>
                        <h3><?= number_format((int) $metrics['completed_jobs']) ?></h3>
                    </article>
                <?php endif; ?>
                <?php if ($showInvoicing): ?>
                    <article class="metric-card">
                        <span>Monthly Invoice Revenue</span>
                        <h3><?= htmlspecialchars(dashboardCurrency($metrics['monthly_revenue']), ENT_QUOTES, 'UTF-8') ?></h3>
                    </article>
                <?php endif; ?>
                <?php if ($showPayments): ?>
                    <article class="metric-card">
                        <span>Payments Received</span>
                        <h3><?= htmlspecialchars(dashboardCurrency($metrics['payments_received']), ENT_QUOTES, 'UTF-8') ?></h3>
                    </article>
                <?php endif; ?>
                <?php if ($showInventory): ?>
                    <article class="metric-card">
                        <span>Low Stock Items</span>
                        <h3><?= number_format((int) $metrics['low_stock_items']) ?></h3>
                    </article>
                <?php endif; ?>
            </section>

            <?php if ($subscriptionNotice): ?>
                <section class="content-card">
                    <h3>Business Subscription</h3>
                    <p>Your shop can monitor subscription timing here, but SaaS billing controls remain available only to the super admin.</p>

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

            <section class="content-grid">
                <article class="content-card">
                    <h3>Operational Snapshot</h3>
                    <p>Quick checks across staff access, feature flags, and current tenant readiness.</p>

                    <div class="dashboard-list">
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Active Staff Accounts</strong>
                                <p>Admins, cashiers, and mechanics currently marked active for this tenant.</p>
                            </div>
                            <span class="metric-pill"><?= number_format((int) $metrics['active_staff']) ?></span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Enabled Tenant Features</strong>
                                <p>Effective modules currently available for this tenant in the admin experience.</p>
                            </div>
                            <span class="metric-pill"><?= number_format((int) $metrics['enabled_features']) ?></span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Feature Mode</strong>
                                <p>
                                    <?= !$hasExplicitFeatureSettings ? 'No feature records found yet, so the tenant is using default access.' : 'Feature toggles are actively controlling visible tenant modules.' ?>
                                </p>
                            </div>
                            <span class="metric-pill"><?= !$hasExplicitFeatureSettings ? 'Default' : 'Custom' ?></span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Tenant Scope</strong>
                                <p>All numbers on this page are filtered by <code>tenant_id = <?= $tenantId ?></code>.</p>
                            </div>
                            <span class="metric-pill">Scoped</span>
                        </div>
                    </div>
                </article>

                <article class="content-card">
                    <h3>Mechanic Workload</h3>
                    <p>Current job distribution for active mechanics in this tenant.</p>

                    <?php if (!$showMechanicModule): ?>
                        <div class="table-placeholder">
                            The mechanic module is currently disabled for this tenant.
                        </div>
                    <?php elseif (!empty($mechanicWorkload)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($mechanicWorkload as $mechanic): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars($mechanic['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= number_format((int) $mechanic['assigned_jobs']) ?> assigned jobs,
                                            <?= number_format((int) $mechanic['ongoing_jobs']) ?> ongoing
                                        </p>
                                    </div>
                                    <span class="metric-pill"><?= number_format((int) $mechanic['ongoing_jobs']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            No active mechanic assignments yet. Once mechanic accounts and jobs are added, workload data will appear here.
                        </div>
                    <?php endif; ?>
                </article>
            </section>

            <section class="content-grid dashboard-lower-grid">
                <article class="content-card">
                    <h3>Recent Appointments</h3>
                    <p>The latest bookings for this tenant, ordered by schedule date.</p>

                    <?php if (!$showAppointments): ?>
                        <div class="table-placeholder">
                            The appointments module is currently disabled for this tenant.
                        </div>
                    <?php elseif (!empty($recentAppointments)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($recentAppointments as $appointment): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong>#<?= (int) $appointment['appointment_id'] ?> - <?= htmlspecialchars($appointment['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars($appointment['vehicle_model'] ?: 'Vehicle pending', ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($appointment['plate'])): ?>
                                                - <?= htmlspecialchars($appointment['plate'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="list-meta">
                                        <span class="status-chip status-<?= htmlspecialchars($appointment['status'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(ucfirst($appointment['status']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <small><?= htmlspecialchars(dashboardDate($appointment['appointment_date']), ENT_QUOTES, 'UTF-8') ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            No appointments have been recorded for this tenant yet.
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3>Build Queue</h3>
                    <p>Recommended next admin modules based on the current project state.</p>

                    <div class="dashboard-list">
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Staff Management</strong>
                                <p>Manage tenant users, roles, and active status with tenant-aware queries.</p>
                            </div>
                            <span class="metric-pill">1</span>
                        </div>
                        <?php if ($showCustomerModule): ?>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Customers & Vehicles</strong>
                                    <p>Create the main CRUD flow for service records and tenant-scoped lookups.</p>
                                </div>
                                <span class="metric-pill">2</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($showAppointments || $showJobs): ?>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Appointments & Jobs</strong>
                                    <p>Link booking intake to workshop execution and mechanic assignments.</p>
                                </div>
                                <span class="metric-pill">3</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($showAnalytics): ?>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Analytics</strong>
                                    <p>Add From and To filters and compute date-range summaries across core modules.</p>
                                </div>
                                <span class="metric-pill">4</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            </section>
        </main>
    </div>

    <script src="../assets/js/theme.js"></script>
</body>
</html>
