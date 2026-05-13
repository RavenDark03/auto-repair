<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/platform_rules.php';
require_once __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../includes/mechanix_ui.php';
require_once __DIR__ . '/../includes/superadmin_sidebar.php';

requireSuperAdmin();

$mechanixSuperadminLoadOpts = [
    'auto_select_tenant' => true,
    'auto_select_registration' => true,
];
require_once __DIR__ . '/includes/superadmin_command_data.php';

$mechanixSuperadminRegistrationPage = 'registrations.php';
$mechanixSuperadminTenantPage = 'tenant_operations.php';
$mechanixSuperadminContext = 'dashboard';

?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - MECHANIX</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/superadmin-landing-theme.css">
</head>
<body class="page-shell antialiased superadmin-app superadmin-platform">
<div class="dashboard">
    <?php mechanix_superadmin_render_sidebar('overview', ['pending_registrations' => (int) ($summary['pending_registrations'] ?? 0)]); ?>

    <main class="dashboard-main sa-dashboard-main" id="main-content" tabindex="-1">
        <div class="dashboard-topbar">
            <div class="dashboard-title">
                <span class="sa-eyebrow">Super Admin</span>
                <h2>Command Center</h2>
                <p>High-level platform health, revenue pulse, and shortcuts into each isolated workspace.</p>
            </div>
            <div class="nav-actions">
                <?= mechanix_theme_toggle_button() ?>
            </div>
        </div>

        <div class="page-body sa-superadmin-page-body">
            <?php if ($flashMessage !== null): ?>
                <div class="alert alert-success" role="alert"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($errorMessage !== null): ?>
                <div class="alert alert-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="sa-status-bar">
                <span class="badge"><?= $isPaymongoConfigured ? 'PayMongo ready' : 'PayMongo incomplete' ?></span>
                <span class="sa-status-bar-note"><?= $isPaymongoConfigured
                    ? 'Checkout and webhook verification available.'
                    : 'Manual billing works; complete PayMongo keys for checkout.' ?></span>
            </div>

            <?php if (!empty($operationalAlerts)): ?>
                <section class="content-card sa-cmd-alerts">
                    <h3>Operational alerts</h3>
                    <p>Items that often block onboarding, billing, or tenant access.</p>
                    <?php foreach ($operationalAlerts as $alert): ?>
                        <?php $isErr = ($alert['class'] ?? '') === 'alert-error'; ?>
                        <div class="dashboard-list-item sa-cmd-alert-row<?= $isErr ? ' sa-cmd-alert-row--danger' : ' sa-cmd-alert-row--ok' ?>">
                            <div>
                                <strong><?= htmlspecialchars($alert['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <p><?= htmlspecialchars($alert['detail'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if (is_array($tenantOnboarding)): ?>
                <section class="onboarding-panel">
                    <div class="onboarding-panel-head">
                        <div>
                            <h3>Tenant onboarding ready</h3>
                            <p>
                                <?= htmlspecialchars($tenantOnboarding['business_name'], ENT_QUOTES, 'UTF-8') ?>
                                is live for first-login validation.
                            </p>
                        </div>
                        <a href="../login.php" class="btn btn-secondary">Open tenant login</a>
                    </div>

                    <div class="onboarding-grid">
                        <div class="onboarding-card">
                            <span>Tenant ID</span>
                            <strong><?= number_format((int) $tenantOnboarding['tenant_id']) ?></strong>
                        </div>
                        <div class="onboarding-card">
                            <span>Admin username</span>
                            <strong><?= htmlspecialchars($tenantOnboarding['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="onboarding-card">
                            <span>Temporary password</span>
                            <strong><?= htmlspecialchars($tenantOnboarding['temporary_password'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="onboarding-card">
                            <span>Subscription window</span>
                            <strong>
                                <?= htmlspecialchars($tenantOnboarding['subscription_start'], ENT_QUOTES, 'UTF-8') ?>
                                –
                                <?= htmlspecialchars($tenantOnboarding['subscription_end'], ENT_QUOTES, 'UTF-8') ?>
                            </strong>
                        </div>
                    </div>

                    <div class="dashboard-list compact-list">
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Plan and billing</strong>
                                <p>
                                    <?= htmlspecialchars($tenantOnboarding['plan_name'], ENT_QUOTES, 'UTF-8') ?>
                                    · <?= htmlspecialchars(ucfirst($tenantOnboarding['billing_cycle']), ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            </div>
                            <span class="metric-pill">Active</span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Primary admin</strong>
                                <p><?= htmlspecialchars($tenantOnboarding['admin_name'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <span class="metric-pill">Ready</span>
                        </div>
                    </div>

                    <h3 class="sa-onboarding-subhead">Assigned feature set</h3>
                    <p>Modules applied during conversion.</p>
                    <div class="dashboard-list compact-list">
                        <?php if (!empty($tenantOnboarding['assigned_features']) && is_array($tenantOnboarding['assigned_features'])): ?>
                            <?php foreach ($tenantOnboarding['assigned_features'] as $feature): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $feature['feature_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p><?= htmlspecialchars(($feature['description'] ?? '') !== '' ? $feature['description'] : 'Enabled at conversion.', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <span class="metric-pill"><?= htmlspecialchars(ucwords((string) ($feature['source'] ?? 'enabled')), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="table-placeholder">No features recorded for this conversion.</p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <h3 class="sa-kpi-section-title">Registrations and tenants</h3>
            <section class="dashboard-grid sa-cmd-kpi-grid" aria-label="Registration and tenant KPIs">
                <article class="metric-card sa-metric-fixed"><span>Pending registrations</span><h3><?= number_format($summary['pending_registrations']) ?></h3></article>
                <article class="metric-card sa-metric-fixed"><span>Total tenants</span><h3><?= number_format($summary['tenants']) ?></h3></article>
                <article class="metric-card sa-metric-fixed"><span>Active tenants</span><h3><?= number_format($summary['active_tenants']) ?></h3></article>
                <article class="metric-card sa-metric-fixed"><span>Inactive tenants</span><h3><?= number_format($summary['inactive_tenants']) ?></h3></article>
            </section>

            <h3 class="sa-kpi-section-title">Subscriptions</h3>
            <section class="dashboard-grid sa-cmd-kpi-grid" aria-label="Subscription KPIs">
                <article class="metric-card sa-metric-fixed"><span>Feature links</span><h3><?= number_format($summary['enabled_feature_links']) ?></h3></article>
                <article class="metric-card sa-metric-fixed"><span>Active subscriptions</span><h3><?= number_format($summary['subscriptions']) ?></h3></article>
                <article class="metric-card sa-metric-fixed"><span>Expired / overdue</span><h3><?= number_format($summary['subscription_attention']) ?></h3></article>
                <article class="metric-card sa-metric-fixed"><span>Due within 7 days</span><h3><?= number_format($summary['subscriptions_due_soon']) ?></h3></article>
                <article class="metric-card sa-metric-fixed"><span>Tenants without sub</span><h3><?= number_format($summary['tenants_without_subscription']) ?></h3></article>
                <article class="metric-card sa-metric-fixed"><span>Awaiting billing</span><h3><?= number_format($summary['awaiting_billing_registrations']) ?></h3></article>
            </section>

            <h3 class="sa-kpi-section-title">Billing pipeline</h3>
            <section class="dashboard-grid sa-cmd-kpi-grid" aria-label="Billing KPIs">
                <article class="metric-card sa-metric-fixed"><span>Billing sent</span><h3><?= number_format($summary['billing_sent_registrations']) ?></h3></article>
                <article class="metric-card sa-metric-fixed"><span>Awaiting conversion</span><h3><?= number_format($summary['awaiting_conversion_registrations']) ?></h3></article>
            </section>

            <section class="content-grid superadmin-grid sa-next-actions">
                <article class="content-card">
                    <h3>Next actions</h3>
                    <p>Open the dedicated pages for deep work—each area is isolated in the sidebar.</p>
                    <div class="dashboard-list compact-list">
                        <a class="dashboard-list-item dashboard-link-card" href="registrations.php">
                            <div><strong>Registrations</strong><p>Queue, billing, conversion</p></div>
                            <span class="metric-pill">Open</span>
                        </a>
                        <a class="dashboard-list-item dashboard-link-card" href="tenant_operations.php">
                            <div><strong>Tenant operations</strong><p>Access, features, lifecycle</p></div>
                            <span class="metric-pill">Open</span>
                        </a>
                        <a class="dashboard-list-item dashboard-link-card" href="plan_catalog.php">
                            <div><strong>Plan catalog</strong><p>Pricing and plan flags</p></div>
                            <span class="metric-pill">Open</span>
                        </a>
                        <a class="dashboard-list-item dashboard-link-card" href="tenant_status.php">
                            <div><strong>Tenant status</strong><p>Directory and health</p></div>
                            <span class="metric-pill">Open</span>
                        </a>
                        <a class="dashboard-list-item dashboard-link-card" href="subscriptions.php">
                            <div><strong>Subscriptions</strong><p>Renewals and terms</p></div>
                            <span class="metric-pill">Open</span>
                        </a>
                        <a class="dashboard-list-item dashboard-link-card" href="earnings.php">
                            <div><strong>Earnings</strong><p>Full revenue report</p></div>
                            <span class="metric-pill">Open</span>
                        </a>
                        <a class="dashboard-list-item dashboard-link-card" href="guides_tenant_onboarding.php">
                            <div><strong>Onboarding playbook</strong><p>Operator checklist</p></div>
                            <span class="metric-pill">Guide</span>
                        </a>
                        <a class="dashboard-list-item dashboard-link-card" href="guides_billing_references.php">
                            <div><strong>Billing references</strong><p>Reference verification flow</p></div>
                            <span class="metric-pill">Guide</span>
                        </a>
                    </div>
                </article>

                <article class="content-card">
                    <h3>Reference verification queue</h3>
                    <p>Jump into registrations with recent billing references.</p>
                    <?php if (!empty($referenceReviewQueue)): ?>
                        <div class="dashboard-list compact-list">
                            <?php foreach ($referenceReviewQueue as $referenceItem): ?>
                                <?php $referenceMeta = evaluateNgReference($referenceItem); ?>
                                <a class="dashboard-list-item dashboard-link-card" href="<?= htmlspecialchars($mechanixSuperadminRegistrationPage, ENT_QUOTES, 'UTF-8') ?>?registration_id=<?= (int) $referenceItem['registration_id'] ?>">
                                    <div>
                                        <strong><?= htmlspecialchars($referenceItem['business_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars($referenceItem['payment_reference'] ?: 'No reference', ENT_QUOTES, 'UTF-8') ?>
                                            · <?= htmlspecialchars($referenceItem['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $referenceItem['total_amount'], 2) ?>
                                        </p>
                                    </div>
                                    <span class="status-chip <?= htmlspecialchars($referenceMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($referenceMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="table-placeholder">No references waiting for review.</p>
                    <?php endif; ?>
                </article>
            </section>

            <section class="content-card sa-revenue-teaser">
                <h3>Revenue snapshot</h3>
                <p>Totals from paid billing requests. See trends and detail on the earnings page.</p>
                <div class="dashboard-grid sa-cmd-kpi-grid">
                    <article class="metric-card sa-metric-fixed"><span>This month</span><h3>PHP <?= number_format($earnings['current_month'], 2) ?></h3></article>
                    <article class="metric-card sa-metric-fixed"><span>Last month</span><h3>PHP <?= number_format($earnings['last_month'], 2) ?></h3></article>
                    <article class="metric-card sa-metric-fixed"><span>All-time</span><h3>PHP <?= number_format($earnings['all_time'], 2) ?></h3></article>
                    <article class="metric-card sa-metric-fixed"><span>Paid requests</span><h3><?= number_format($earnings['paid_requests']) ?></h3></article>
                </div>
                <p style="margin-top:12px"><a class="btn btn-primary" href="earnings.php">Open earnings report</a></p>
            </section>
        </div>
    </main>
</div>

<script src="../assets/js/theme.js?v=3"></script>
<script src="../assets/js/mechanix-logout-dialog.js"></script>
</body>
</html>
