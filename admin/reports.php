<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';

requireAdmin();
requireTenantFeature('reports');

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$fromDate = trim($_GET['from'] ?? date('Y-m-01'));
$toDate = trim($_GET['to'] ?? date('Y-m-d'));
$errorMessage = null;

$summary = [
    'completed_jobs' => 0,
    'invoice_total' => 0,
    'payments_collected' => 0,
    'supplier_spend' => 0,
    'supplier_payments' => 0,
    'net_collections' => 0,
];
$receivablesAging = [
    'current' => 0,
    'days_1_30' => 0,
    'days_31_60' => 0,
    'days_61_plus' => 0,
];
$payablesAging = [
    'current' => 0,
    'days_1_30' => 0,
    'days_31_60' => 0,
    'days_61_plus' => 0,
];
$lowStockItems = [];
$monthlyCollections = [];
$monthlyPayables = [];

$moduleLinks = [
    ['label' => 'Staff Management', 'feature' => null, 'hint' => 'Live', 'href' => 'staff.php'],
    ['label' => 'Customers', 'feature' => 'customer_module', 'hint' => 'Live', 'href' => 'customers.php'],
    ['label' => 'Vehicles', 'feature' => 'customer_module', 'hint' => 'Live', 'href' => 'vehicles.php'],
    ['label' => 'Appointments', 'feature' => 'appointments', 'hint' => 'Live', 'href' => 'appointments.php'],
    ['label' => 'Jobs', 'feature' => 'jobs', 'hint' => 'Live', 'href' => 'jobs.php'],
    ['label' => 'Inventory', 'feature' => 'inventory', 'hint' => 'Live', 'href' => 'inventory.php'],
    ['label' => 'Invoices', 'feature' => 'invoicing', 'hint' => 'Live', 'href' => 'invoices.php'],
    ['label' => 'Payments', 'feature' => 'payments', 'hint' => 'Live', 'href' => 'payments.php'],
];

$fromDateObj = DateTime::createFromFormat('Y-m-d', $fromDate);
$toDateObj = DateTime::createFromFormat('Y-m-d', $toDate);

if (!$fromDateObj || $fromDateObj->format('Y-m-d') !== $fromDate || !$toDateObj || $toDateObj->format('Y-m-d') !== $toDate) {
    $errorMessage = 'Please provide valid report dates.';
    $fromDate = date('Y-m-01');
    $toDate = date('Y-m-d');
} elseif ($fromDate > $toDate) {
    $errorMessage = 'The report start date cannot be later than the end date.';
    $fromDate = date('Y-m-01');
    $toDate = date('Y-m-d');
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

    $summaryQueries = [
        'completed_jobs' => "
            SELECT COUNT(*)
            FROM jobs
            WHERE tenant_id = :tenant_id
              AND status = 'completed'
              AND job_id IN (
                    SELECT i.job_id
                    FROM invoices i
                    WHERE i.tenant_id = :tenant_id
                      AND i.status <> 'cancelled'
                      AND DATE(i.created_at) BETWEEN :from_date AND :to_date
              )
        ",
        'invoice_total' => "
            SELECT COALESCE(SUM(total), 0)
            FROM invoices
            WHERE tenant_id = :tenant_id
              AND status <> 'cancelled'
              AND DATE(created_at) BETWEEN :from_date AND :to_date
        ",
        'payments_collected' => "
            SELECT COALESCE(SUM(amount), 0)
            FROM payments
            WHERE tenant_id = :tenant_id
              AND DATE(payment_date) BETWEEN :from_date AND :to_date
        ",
        'supplier_spend' => "
            SELECT COALESCE(SUM(total_amount), 0)
            FROM inventory_purchases
            WHERE tenant_id = :tenant_id
              AND purchase_date BETWEEN :from_date AND :to_date
        ",
        'supplier_payments' => "
            SELECT COALESCE(SUM(amount), 0)
            FROM supplier_payments
            WHERE tenant_id = :tenant_id
              AND payment_date BETWEEN :from_date AND :to_date
        ",
    ];

    foreach ($summaryQueries as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'tenant_id' => $tenantId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);
        $summary[$key] = $stmt->fetchColumn() ?: 0;
    }

    $summary['net_collections'] = (float) $summary['payments_collected'] - (float) $summary['supplier_payments'];

    $receivablesStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, DATE(created_at))) <= 0 THEN total - amount_paid ELSE 0 END) AS current_bucket,
            SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, DATE(created_at))) BETWEEN 1 AND 30 THEN total - amount_paid ELSE 0 END) AS bucket_1_30,
            SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, DATE(created_at))) BETWEEN 31 AND 60 THEN total - amount_paid ELSE 0 END) AS bucket_31_60,
            SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, DATE(created_at))) >= 61 THEN total - amount_paid ELSE 0 END) AS bucket_61_plus
        FROM invoices
        WHERE tenant_id = :tenant_id
          AND status IN ('unpaid', 'partial')
    ");
    $receivablesStmt->execute(['tenant_id' => $tenantId]);
    $receivablesRow = $receivablesStmt->fetch();

    if ($receivablesRow) {
        $receivablesAging['current'] = (float) ($receivablesRow['current_bucket'] ?? 0);
        $receivablesAging['days_1_30'] = (float) ($receivablesRow['bucket_1_30'] ?? 0);
        $receivablesAging['days_31_60'] = (float) ($receivablesRow['bucket_31_60'] ?? 0);
        $receivablesAging['days_61_plus'] = (float) ($receivablesRow['bucket_61_plus'] ?? 0);
    }

    $payablesStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, purchase_date)) <= 0 THEN total_amount - amount_paid ELSE 0 END) AS current_bucket,
            SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, purchase_date)) BETWEEN 1 AND 30 THEN total_amount - amount_paid ELSE 0 END) AS bucket_1_30,
            SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, purchase_date)) BETWEEN 31 AND 60 THEN total_amount - amount_paid ELSE 0 END) AS bucket_31_60,
            SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(due_date, purchase_date)) >= 61 THEN total_amount - amount_paid ELSE 0 END) AS bucket_61_plus
        FROM inventory_purchases
        WHERE tenant_id = :tenant_id
          AND payable_status IN ('unpaid', 'partial')
    ");
    $payablesStmt->execute(['tenant_id' => $tenantId]);
    $payablesRow = $payablesStmt->fetch();

    if ($payablesRow) {
        $payablesAging['current'] = (float) ($payablesRow['current_bucket'] ?? 0);
        $payablesAging['days_1_30'] = (float) ($payablesRow['bucket_1_30'] ?? 0);
        $payablesAging['days_31_60'] = (float) ($payablesRow['bucket_31_60'] ?? 0);
        $payablesAging['days_61_plus'] = (float) ($payablesRow['bucket_61_plus'] ?? 0);
    }

    $lowStockStmt = $pdo->prepare("
        SELECT part_name, sku, quantity, reorder_level, unit_cost
        FROM inventory
        WHERE tenant_id = :tenant_id
          AND status = 'active'
          AND quantity <= reorder_level
        ORDER BY quantity ASC, part_name ASC
        LIMIT 12
    ");
    $lowStockStmt->execute(['tenant_id' => $tenantId]);
    $lowStockItems = $lowStockStmt->fetchAll();

    $monthlyCollectionsStmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(payment_date, '%Y-%m') AS month_key,
            COALESCE(SUM(amount), 0) AS total_amount
        FROM payments
        WHERE tenant_id = :tenant_id
          AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
        ORDER BY month_key ASC
    ");
    $monthlyCollectionsStmt->execute(['tenant_id' => $tenantId]);
    $monthlyCollections = $monthlyCollectionsStmt->fetchAll();

    $monthlyPayablesStmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(payment_date, '%Y-%m') AS month_key,
            COALESCE(SUM(amount), 0) AS total_amount
        FROM supplier_payments
        WHERE tenant_id = :tenant_id
          AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
        ORDER BY month_key ASC
    ");
    $monthlyPayablesStmt->execute(['tenant_id' => $tenantId]);
    $monthlyPayables = $monthlyPayablesStmt->fetchAll();
} catch (PDOException $e) {
    $errorMessage = 'Reports could not be loaded: ' . $e->getMessage();
}

$visibleModuleLinks = array_filter($moduleLinks, function ($module) use ($tenantId) {
    return $module['feature'] === null || tenantHasFeature($module['feature'], $tenantId);
});

function reportsCurrency($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function reportsMonthLabel($monthKey) {
    $timestamp = strtotime($monthKey . '-01');
    return $timestamp ? date('M Y', $timestamp) : $monthKey;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - MECHANIX</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body class="page-shell">
    <div class="dashboard">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="brand">
                    <div class="brand-mark">M</div>
                    <div class="brand-text">
                        <h2>MECHANIX</h2>
                        <p><?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                <p class="sidebar-meta">Tenant admin workspace for daily repair operations.</p>
            </div>

            <div class="sidebar-section-title">Overview</div>
            <nav class="sidebar-menu">
                <a href="dashboard.php"><span>Dashboard</span><span class="badge">Now</span></a>
                <a href="reports.php" class="active"><span>Analytics</span><span class="sidebar-hint">Live</span></a>
            </nav>

            <div class="sidebar-section-title">Operations</div>
            <nav class="sidebar-menu">
                <?php foreach ($visibleModuleLinks as $module): ?>
                    <a href="<?= htmlspecialchars($module['href'], ENT_QUOTES, 'UTF-8') ?>">
                        <span><?= htmlspecialchars($module['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sidebar-hint"><?= htmlspecialchars($module['hint'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sidebar-footer">
                <a href="../logout.php" class="btn btn-secondary btn-full">Log Out</a>
            </div>
        </aside>

        <main class="dashboard-main">
            <div class="dashboard-topbar">
                <div class="dashboard-title">
                    <h2>Reports</h2>
                    <p>Date-range analytics for collections, supplier outflows, receivables, payables, and stock health.</p>
                </div>

                <div class="nav-actions">
                    <button type="button" class="theme-toggle" data-theme-toggle>Dark Mode</button>
                </div>
            </div>

            <?php if ($errorMessage !== null): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <section class="content-card">
                <h3>Report Range</h3>
                <p>Use a From and To date to refresh the operational summary for this tenant.</p>

                <form action="reports.php" method="GET" class="feature-toggle-form">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="from">From</label>
                            <input class="form-control" type="date" id="from" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="to">To</label>
                            <input class="form-control" type="date" id="to" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>
                    <div class="approval-actions">
                        <button type="submit" class="btn btn-primary">Run Report</button>
                        <a href="reports.php" class="btn btn-secondary">Reset</a>
                    </div>
                </form>
            </section>

            <section class="dashboard-grid">
                <article class="metric-card">
                    <span>Completed Jobs</span>
                    <h3><?= number_format((int) $summary['completed_jobs']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Invoice Total</span>
                    <h3><?= htmlspecialchars(reportsCurrency($summary['invoice_total']), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
                <article class="metric-card">
                    <span>Collections</span>
                    <h3><?= htmlspecialchars(reportsCurrency($summary['payments_collected']), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
                <article class="metric-card">
                    <span>Net Collections</span>
                    <h3><?= htmlspecialchars(reportsCurrency($summary['net_collections']), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
            </section>

            <section class="dashboard-grid dashboard-grid-secondary">
                <article class="metric-card">
                    <span>Supplier Spend</span>
                    <h3><?= htmlspecialchars(reportsCurrency($summary['supplier_spend']), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
                <article class="metric-card">
                    <span>Supplier Payments</span>
                    <h3><?= htmlspecialchars(reportsCurrency($summary['supplier_payments']), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
                <article class="metric-card">
                    <span>Receivables Open</span>
                    <h3><?= htmlspecialchars(reportsCurrency(array_sum($receivablesAging)), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
                <article class="metric-card">
                    <span>Payables Open</span>
                    <h3><?= htmlspecialchars(reportsCurrency(array_sum($payablesAging)), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
            </section>

            <section class="content-grid">
                <article class="content-card">
                    <h3>Receivables Aging</h3>
                    <p>Outstanding customer balances grouped by how overdue they are today.</p>

                    <div class="dashboard-list">
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Current</strong>
                                <p>Due today or not yet due.</p>
                            </div>
                            <span class="metric-pill"><?= htmlspecialchars(reportsCurrency($receivablesAging['current']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>1-30 Days</strong>
                                <p>Recently overdue receivables.</p>
                            </div>
                            <span class="metric-pill"><?= htmlspecialchars(reportsCurrency($receivablesAging['days_1_30']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>31-60 Days</strong>
                                <p>Receivables needing follow-up soon.</p>
                            </div>
                            <span class="metric-pill"><?= htmlspecialchars(reportsCurrency($receivablesAging['days_31_60']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>61+ Days</strong>
                                <p>Long-outstanding customer balances.</p>
                            </div>
                            <span class="metric-pill"><?= htmlspecialchars(reportsCurrency($receivablesAging['days_61_plus']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                </article>

                <article class="content-card">
                    <h3>Payables Aging</h3>
                    <p>Outstanding supplier balances grouped by due exposure.</p>

                    <div class="dashboard-list">
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Current</strong>
                                <p>Supplier bills that are not overdue yet.</p>
                            </div>
                            <span class="metric-pill"><?= htmlspecialchars(reportsCurrency($payablesAging['current']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>1-30 Days</strong>
                                <p>Recently overdue supplier balances.</p>
                            </div>
                            <span class="metric-pill"><?= htmlspecialchars(reportsCurrency($payablesAging['days_1_30']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>31-60 Days</strong>
                                <p>Mid-aged supplier obligations.</p>
                            </div>
                            <span class="metric-pill"><?= htmlspecialchars(reportsCurrency($payablesAging['days_31_60']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>61+ Days</strong>
                                <p>Long-outstanding payables.</p>
                            </div>
                            <span class="metric-pill"><?= htmlspecialchars(reportsCurrency($payablesAging['days_61_plus']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                </article>
            </section>

            <section class="content-grid dashboard-lower-grid">
                <article class="content-card">
                    <h3>Low Stock Watch</h3>
                    <p>Inventory items already at or below reorder level.</p>

                    <?php if (!empty($lowStockItems)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($lowStockItems as $item): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars($item['part_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars($item['sku'] ?: 'No SKU', ENT_QUOTES, 'UTF-8') ?>
                                            | Stock <?= number_format((int) $item['quantity']) ?>
                                            | Reorder <?= number_format((int) $item['reorder_level']) ?>
                                        </p>
                                    </div>
                                    <span class="status-chip status-low-stock">Low Stock</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            No low-stock items were found for this tenant right now.
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3>Collections vs Payables</h3>
                    <p>Recent monthly cash-in and supplier payment snapshots for quick trend reading.</p>

                    <div class="dashboard-list">
                        <?php if (!empty($monthlyCollections)): ?>
                            <?php foreach ($monthlyCollections as $row): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars(reportsMonthLabel($row['month_key']), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>Customer collections received during this month.</p>
                                    </div>
                                    <span class="metric-pill"><?= htmlspecialchars(reportsCurrency($row['total_amount']), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="table-placeholder">
                                No customer payment trend data is available yet.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="dashboard-list compact-list">
                        <?php if (!empty($monthlyPayables)): ?>
                            <?php foreach ($monthlyPayables as $row): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars(reportsMonthLabel($row['month_key']), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>Supplier payments released during this month.</p>
                                    </div>
                                    <span class="metric-pill"><?= htmlspecialchars(reportsCurrency($row['total_amount']), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="table-placeholder">
                                No supplier payment trend data is available yet.
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
