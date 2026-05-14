<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/platform_rules.php';

requireAdmin();
requireTenantFeature('subscription_center');

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$subscriptionNotice = null;
$tenantStatus = '';
$subscriptionHistory = [];
$loadError = null;

try {
    $pdo = Database::getInstance();

    $tenantStmt = $pdo->prepare("
        SELECT
            t.business_name,
            t.status AS tenant_status,
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
    }

    if ($tenantRow) {
        $tenantStatus = (string) ($tenantRow['tenant_status'] ?? '');
        $subscriptionNotice = getTenantSubscriptionNotice(
            $tenantRow['plan'] ?? null,
            $tenantRow['subscription_status'] ?? null,
            $tenantRow['start_date'] ?? null,
            $tenantRow['end_date'] ?? null
        );
    }

    $historyStmt = $pdo->prepare("
        SELECT subscription_id, plan, start_date, end_date, status
        FROM subscriptions
        WHERE tenant_id = :tenant_id
        ORDER BY subscription_id DESC
    ");
    $historyStmt->execute(['tenant_id' => $tenantId]);
    $subscriptionHistory = $historyStmt->fetchAll();
} catch (PDOException $e) {
    $loadError = 'Subscription information could not be loaded: ' . $e->getMessage();
}

$showAnalytics = tenantHasFeature('reports', $tenantId);
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription - MECHANIX</title>
    <?= mechanix_link_styles_tabler_workspace('../assets/css/') ?>
</head>
<body class="page-shell antialiased tenant-app">
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'subscriptions.php', $showAnalytics) ?>

        <main class="dashboard-main" id="main-content" tabindex="-1">
            <?= renderTenantAdminTopbar(
                'Subscription',
                'Current SaaS plan and term for ' . htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') . '. Billing changes are handled by MECHANIX platform support.'
            ) ?>

            <?= renderTenantAccessModeNotice() ?>

            <?php if ($loadError !== null): ?>
                <div class="alert alert-error" role="alert"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($tenantStatus === 'pending_payment'): ?>
                <div class="alert alert-warning" role="alert">
                    <div class="d-flex align-items-start gap-2">
                        <div><i class="ti ti-alert-circle icon alert-icon"></i></div>
                        <div>
                            <strong>Payment pending</strong><br>
                            Complete subscription payment to unlock the full workspace.
                            <a href="../pending_payment.php" class="alert-link">Open payment status</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($subscriptionNotice): ?>
                <?php include __DIR__ . '/../includes/partials/subscription_notice_card.php'; ?>
            <?php endif; ?>

            <section class="content-grid" style="margin-top: 1rem;">
                <article class="content-card">
                    <h3>Subscription timeline</h3>
                    <p class="text-muted small">Each row is a subscription window stored for your workspace. The top row is the latest record.</p>

                    <?php if (empty($subscriptionHistory)): ?>
                        <div class="empty empty-sm">
                            <p class="empty-title">No subscription on file</p>
                            <p class="empty-subtitle text-muted">If you recently finished registration, allow a short time for records to sync. Contact support if this persists.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-vcenter">
                                <thead>
                                    <tr>
                                        <th>Period</th>
                                        <th>Plan</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subscriptionHistory as $idx => $row): ?>
                                        <?php
                                        $isLatest = $idx === 0;
                                        $status = (string) ($row['status'] ?? '');
                                        $badge = $status === 'active' ? 'status-chip status-active' : 'status-chip status-inactive';
                                        ?>
                                        <tr>
                                            <td>
                                                <?= htmlspecialchars((string) ($row['start_date'] ?: '—'), ENT_QUOTES, 'UTF-8') ?>
                                                &nbsp;→&nbsp;
                                                <?= htmlspecialchars((string) ($row['end_date'] ?: '—'), ENT_QUOTES, 'UTF-8') ?>
                                                <?php if ($isLatest): ?>
                                                    <span class="metric-pill ms-1">Latest</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars((string) ($row['plan'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><span class="<?= $badge ?>"><?= htmlspecialchars(ucfirst($status ?: 'unknown'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3>Included modules</h3>
                    <p class="text-muted small">Modules enabled for your tenant (same flags used across the admin and API).</p>
                    <?php
                    $featureMap = getTenantFeatureMap($tenantId);
                    ksort($featureMap);
                    ?>
                    <?php if (empty($featureMap)): ?>
                        <p class="text-muted">No feature metadata found.</p>
                    <?php else: ?>
                        <ul class="dashboard-list">
                            <?php foreach ($featureMap as $name => $enabled): ?>
                                <li class="dashboard-list-item" style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">
                                    <span><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $name)), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="status-chip <?= $enabled ? 'status-active' : 'status-inactive' ?>">
                                        <?= $enabled ? 'On' : 'Off' ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>
            </section>
        </main>
    </div>
    <?= renderTenantAdminFooterScripts() ?>
</body>
</html>
