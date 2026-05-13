<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/platform_rules.php';

require_once __DIR__ . '/../includes/mechanix_ui.php';

requireSuperAdmin();

$summary = [
    'current_month' => 0,
    'last_month' => 0,
    'all_time' => 0,
    'paid_requests' => 0,
];
$trend = [];
$recentPayments = [];
$errorMessage = null;

try {
    $pdo = Database::getInstance();

    $summaryStmt = $pdo->query("
        SELECT
            COALESCE(SUM(CASE WHEN DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN total_amount ELSE 0 END), 0) AS current_month,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m') THEN total_amount ELSE 0 END), 0) AS last_month,
            COALESCE(SUM(total_amount), 0) AS all_time,
            COUNT(*) AS paid_requests
        FROM billing_requests
        WHERE billing_status = 'paid'
    ");
    $summary = array_merge($summary, $summaryStmt->fetch() ?: []);

    $trendStmt = $pdo->query("
        SELECT
            DATE_FORMAT(paid_at, '%Y-%m') AS earnings_month,
            COALESCE(SUM(total_amount), 0) AS month_total,
            COUNT(*) AS paid_count
        FROM billing_requests
        WHERE billing_status = 'paid'
          AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
        GROUP BY DATE_FORMAT(paid_at, '%Y-%m')
        ORDER BY earnings_month ASC
    ");
    $trend = $trendStmt->fetchAll();

    $recentStmt = $pdo->query("
        SELECT
            br.billing_request_id,
            tr.business_name,
            br.total_amount,
            br.currency,
            br.payment_reference,
            br.payment_reference_check_status,
            br.paid_at
        FROM billing_requests br
        INNER JOIN tenant_registrations tr
            ON tr.registration_id = br.registration_id
        WHERE br.billing_status = 'paid'
        ORDER BY br.paid_at DESC, br.billing_request_id DESC
        LIMIT 12
    ");
    $recentPayments = $recentStmt->fetchAll();
} catch (PDOException $e) {
    $errorMessage = 'Unable to load earnings report: ' . $e->getMessage();
}

function earningsCurrency($amount): string
{
    return 'PHP ' . number_format((float) $amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Earnings Reports - MECHANIX</title>
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
                        <p>Platform operations</p>
                    </div>
                </div>
                <p class="sidebar-meta">Logged in as <?= htmlspecialchars($_SESSION['super_admin_username'], ENT_QUOTES, 'UTF-8') ?>.</p>
            </div>

            <div class="sidebar-section-title">Platform</div>
            <nav class="sidebar-menu">
                <a href="dashboard.php#registrations"><span>Registrations</span><span class="sidebar-hint">Queue</span></a>
                <a href="dashboard.php#features"><span>Tenant Operations</span><span class="sidebar-hint">Live</span></a>
                <a href="dashboard.php#catalog"><span>Plan Catalog</span><span class="sidebar-hint">Dynamic</span></a>
            </nav>

            <div class="sidebar-section-title">Reports</div>
            <nav class="sidebar-menu">
                <a href="earnings.php" class="active"><span>Earnings Reports</span><span class="badge">Now</span></a>
            </nav>

            <div class="sidebar-footer">
                <a href="../logout.php" class="btn btn-secondary btn-full">Log Out</a>
            </div>
        </aside>

        <main class="dashboard-main" id="main-content" tabindex="-1">
            <div class="dashboard-topbar">
                <div class="dashboard-title">
                    <h2>Earnings Reports</h2>
                    <p>Track platform income, monthly trends, and payment-reference health from one dedicated page.</p>
                </div>
                <div class="nav-actions">
                    <?= mechanix_theme_toggle_button() ?>
                </div>
            </div>

            <?php if ($errorMessage !== null): ?>
                <div class="alert alert-error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <section class="dashboard-grid">
                <article class="metric-card">
                    <span>This Month</span>
                    <h3><?= htmlspecialchars(earningsCurrency($summary['current_month'] ?? 0), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
                <article class="metric-card">
                    <span>Last Month</span>
                    <h3><?= htmlspecialchars(earningsCurrency($summary['last_month'] ?? 0), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
                <article class="metric-card">
                    <span>All-Time</span>
                    <h3><?= htmlspecialchars(earningsCurrency($summary['all_time'] ?? 0), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
                <article class="metric-card">
                    <span>Paid Requests</span>
                    <h3><?= number_format((int) ($summary['paid_requests'] ?? 0)) ?></h3>
                </article>
            </section>

            <section class="content-grid superadmin-grid">
                <article class="content-card">
                    <h3>Monthly Earnings Trend</h3>
                    <p>Use this view to spot momentum, flat periods, and recent billing growth.</p>

                    <?php if (!empty($trend)): ?>
                        <div class="dashboard-list compact-list">
                            <?php foreach ($trend as $month): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars(date('M Y', strtotime(($month['earnings_month'] ?? '') . '-01')), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p><?= number_format((int) ($month['paid_count'] ?? 0)) ?> paid request(s)</p>
                                    </div>
                                    <span class="metric-pill"><?= htmlspecialchars(earningsCurrency($month['month_total'] ?? 0), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">No paid billing data is available yet.</div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3>Recent Paid Billing</h3>
                    <p>Keep an eye on paid requests and whether their NG references have already been reviewed.</p>

                    <?php if (!empty($recentPayments)): ?>
                        <div class="dashboard-list compact-list">
                            <?php foreach ($recentPayments as $payment): ?>
                                <?php $referenceMeta = evaluateNgReference($payment); ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars($payment['business_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars($payment['payment_reference'] ?: 'No reference recorded', ENT_QUOTES, 'UTF-8') ?>
                                            | Paid <?= htmlspecialchars($payment['paid_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                    </div>
                                    <div class="tenant-directory-meta">
                                        <span class="status-chip <?= htmlspecialchars($referenceMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($referenceMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="metric-pill"><?= htmlspecialchars(earningsCurrency($payment['total_amount'] ?? 0), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">No paid billing records are available yet.</div>
                    <?php endif; ?>
                </article>
            </section>
        </main>
    </div>

    <script src="../assets/js/theme.js"></script>
</body>
</html>
