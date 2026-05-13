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

$mechanixSuperadminRegistrationPage = 'dashboard.php';
$mechanixSuperadminTenantPage = 'dashboard.php';
$mechanixSuperadminContext = 'dashboard';

$mechanixSuperadminRegListFragment = $mechanixSuperadminRegistrationPage === 'dashboard.php' ? '#registrations' : '';
$mechanixSuperadminTenantListFragment = $mechanixSuperadminTenantPage === 'dashboard.php' ? '#features' : '';

?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - MECHANIX</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap">
    <link rel="stylesheet" href="../assets/css/tabler-mechanix-bridge.css">
    <link rel="stylesheet" href="../assets/css/superadmin-landing-theme.css">
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body class="page-shell antialiased superadmin-app superadmin-platform">
<div class="dashboard">
    <?php mechanix_superadmin_render_sidebar('overview', ['pending_registrations' => (int) ($summary['pending_registrations'] ?? 0)]); ?>

    <main class="dashboard-main sa-dashboard-main" id="main-content" tabindex="-1">
        <div class="dashboard-topbar">
            <div class="dashboard-title">
                <span class="sa-eyebrow">Super Admin</span>
                <h2>Command Center</h2>
                <p>Manage registrations, billing, tenant activation, subscriptions, and tenant access from one operational workspace.</p>
            </div>
            <div class="nav-actions">
                <?= mechanix_theme_toggle_button() ?>
            </div>
        </div>

        <div class="page-body sa-superadmin-page-body">
            <div class="container-xl">

            <?php if ($flashMessage !== null): ?>
                <div class="alert alert-success" role="alert">
                    <div class="d-flex">
                        <div><i class="ti ti-circle-check icon alert-icon"></i></div>
                        <div><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== null): ?>
                <div class="alert alert-danger" role="alert">
                    <div class="d-flex">
                        <div><i class="ti ti-alert-circle icon alert-icon"></i></div>
                        <div><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="sa-status-bar mb-4 d-flex align-items-center gap-2 flex-wrap">
                <span class="badge <?= $isPaymongoConfigured ? 'bg-green-lt text-green' : 'bg-orange-lt text-orange' ?>">
                    <i class="ti ti-<?= $isPaymongoConfigured ? 'circle-check' : 'alert-circle' ?> me-1"></i>
                    PayMongo <?= $isPaymongoConfigured ? 'Ready' : 'Incomplete' ?>
                </span>
                <span class="text-muted small"><?= $isPaymongoConfigured
                    ? 'Checkout &amp; webhook verification available.'
                    : 'Manual billing works. Checkout &amp; webhooks not fully configured yet.' ?></span>
            </div>

            <?php if (!empty($operationalAlerts)): ?>
                <?php $alertErrorCount = count(array_filter($operationalAlerts, fn($a) => $a['class'] === 'alert-error')); ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="ti ti-alert-triangle me-2"></i>Operational Alerts</h3>
                        <div class="card-options">
                            <?php if ($alertErrorCount > 0): ?>
                                <span class="badge bg-red-lt text-red"><?= $alertErrorCount ?> issue<?= $alertErrorCount !== 1 ? 's' : '' ?></span>
                            <?php else: ?>
                                <span class="badge bg-green-lt text-green">All clear</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($operationalAlerts as $alert): ?>
                            <?php $isError = $alert['class'] === 'alert-error'; ?>
                            <div class="list-group-item sa-op-alert <?= $isError ? 'sa-op-alert--danger' : 'sa-op-alert--success' ?>">
                                <div class="d-flex align-items-start gap-3">
                                    <i class="ti ti-<?= $isError ? 'alert-circle' : 'circle-check' ?> flex-shrink-0 mt-1 <?= $isError ? 'text-danger' : 'text-success' ?>"></i>
                                    <div>
                                        <div class="fw-semibold small"><?= htmlspecialchars($alert['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="text-muted small mt-1"><?= htmlspecialchars($alert['detail'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (is_array($tenantOnboarding)): ?>
                <div class="card card-status-top bg-green mb-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="ti ti-circle-check me-2 text-green"></i>Tenant Onboarding Ready</h3>
                        <div class="card-options">
                            <a href="../login.php" class="btn btn-success btn-sm">Open Tenant Login</a>
                        </div>
                    </div>
                    <div class="card-body pb-0">
                        <p class="text-muted small">
                            <?= htmlspecialchars($tenantOnboarding['business_name'], ENT_QUOTES, 'UTF-8') ?> is now live and ready for first login testing.
                        </p>
                    </div>
                    <div class="row row-cards px-3 pb-3">
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-muted small">Tenant ID</div>
                                    <div class="font-weight-medium"><?= number_format((int) $tenantOnboarding['tenant_id']) ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-muted small">Admin Username</div>
                                    <div class="font-weight-medium"><?= htmlspecialchars($tenantOnboarding['username'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-muted small">Temporary Password</div>
                                    <div class="font-weight-medium"><?= htmlspecialchars($tenantOnboarding['temporary_password'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-muted small">Subscription Window</div>
                                    <div class="font-weight-medium">
                                        <?= htmlspecialchars($tenantOnboarding['subscription_start'], ENT_QUOTES, 'UTF-8') ?>
                                        &mdash;
                                        <?= htmlspecialchars($tenantOnboarding['subscription_end'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="list-group list-group-flush">
                        <div class="list-group-item">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="font-weight-medium">Plan and Billing</div>
                                    <div class="text-muted small">
                                        <?= htmlspecialchars($tenantOnboarding['plan_name'], ENT_QUOTES, 'UTF-8') ?>
                                        &middot;
                                        <?= htmlspecialchars(ucfirst($tenantOnboarding['billing_cycle']), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                                <div class="col-auto"><span class="badge bg-green-lt text-green">Active</span></div>
                            </div>
                        </div>
                        <div class="list-group-item">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="font-weight-medium">Primary Admin</div>
                                    <div class="text-muted small"><?= htmlspecialchars($tenantOnboarding['admin_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="col-auto"><span class="badge bg-green-lt text-green">Ready</span></div>
                            </div>
                        </div>
                    </div>
                    <div class="card-header mt-2">
                        <h3 class="card-title">Assigned Feature Set</h3>
                    </div>
                    <div class="card-body pb-0">
                        <p class="text-muted small">These are the modules applied during conversion from the selected plan and requested add-ons.</p>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php if (!empty($tenantOnboarding['assigned_features']) && is_array($tenantOnboarding['assigned_features'])): ?>
                            <?php foreach ($tenantOnboarding['assigned_features'] as $feature): ?>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $feature['feature_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars(($feature['description'] ?? '') !== '' ? $feature['description'] : 'Enabled during tenant conversion.', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-blue-lt text-blue"><?= htmlspecialchars(ucwords((string) ($feature['source'] ?? 'enabled')), ENT_QUOTES, 'UTF-8') ?></span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="list-group-item">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No features applied</p>
                                    <p class="empty-subtitle text-muted">No plan-linked or add-on features were applied during this conversion.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mb-0" role="alert">
                            <div class="d-flex">
                                <div><i class="ti ti-info-circle icon alert-icon"></i></div>
                                <div>
                                    <strong>Quick Validation Flow</strong><br>
                                    1. Open the tenant login page.<br>
                                    2. Sign in with the admin username and temporary password shown above.<br>
                                    3. Confirm the tenant dashboard opens and only shows the enabled features for this new tenant.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Metric cards row 1: Registrations + Tenants -->
            <div class="row row-deck row-cards mb-3">
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-orange text-white avatar"><i class="ti ti-clock icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Pending Registrations</div>
                                    <div class="text-muted"><?= number_format($summary['pending_registrations']) ?></div>
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
                                    <span class="bg-blue text-white avatar"><i class="ti ti-building icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Total Tenants</div>
                                    <div class="text-muted"><?= number_format($summary['tenants']) ?></div>
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
                                    <span class="bg-green text-white avatar"><i class="ti ti-building-community icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Active Tenants</div>
                                    <div class="text-muted"><?= number_format($summary['active_tenants']) ?></div>
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
                                    <span class="bg-red text-white avatar"><i class="ti ti-building-off icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Inactive Tenants</div>
                                    <div class="text-muted"><?= number_format($summary['inactive_tenants']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Metric cards row 2: Subscriptions + Registration pipeline -->
            <div class="row row-deck row-cards mb-4">
                <div class="col-sm-6 col-lg-2">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-azure text-white avatar"><i class="ti ti-toggle-right icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Feature Links</div>
                                    <div class="text-muted"><?= number_format($summary['enabled_feature_links']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-teal text-white avatar"><i class="ti ti-receipt icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Active Subs</div>
                                    <div class="text-muted"><?= number_format($summary['subscriptions']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-red text-white avatar"><i class="ti ti-alert-triangle icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Expired / Overdue</div>
                                    <div class="text-muted"><?= number_format($summary['subscription_attention']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-orange text-white avatar"><i class="ti ti-calendar-due icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Due 7 Days</div>
                                    <div class="text-muted"><?= number_format($summary['subscriptions_due_soon']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-secondary text-white avatar"><i class="ti ti-receipt-off icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">No Sub</div>
                                    <div class="text-muted"><?= number_format($summary['tenants_without_subscription']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-yellow text-white avatar"><i class="ti ti-file-dollar icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Awaiting Billing</div>
                                    <div class="text-muted"><?= number_format($summary['awaiting_billing_registrations']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Metric cards row 3: Billing pipeline -->
            <div class="row row-deck row-cards mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-blue text-white avatar"><i class="ti ti-send icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Billing Sent</div>
                                    <div class="text-muted"><?= number_format($summary['billing_sent_registrations']) ?></div>
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
                                    <span class="bg-purple text-white avatar"><i class="ti ti-refresh icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Awaiting Conversion</div>
                                    <div class="text-muted"><?= number_format($summary['awaiting_conversion_registrations']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue Snapshot + Reference Verification Queue -->
            <div class="row row-deck row-cards mb-4">
                <div class="col-lg-6">
                    <div class="card sa-dark-card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-currency-peso me-2 text-muted"></i>Revenue Snapshot</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">Current earnings and paid billing volume from the platform billing ledger.</p>
                        </div>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">This Month</div>
                                        <div class="text-muted small">Paid requests collected during the current month.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-teal-lt text-teal">PHP <?= number_format($earnings['current_month'], 2) ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Last Month</div>
                                        <div class="text-muted small">Use this to compare short-term performance.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-blue-lt text-blue">PHP <?= number_format($earnings['last_month'], 2) ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">All-Time Earnings</div>
                                        <div class="text-muted small">Total verified billing collected so far.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-green-lt text-green">PHP <?= number_format($earnings['all_time'], 2) ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Paid Billing Requests</div>
                                        <div class="text-muted small">Total payment events already captured in the system.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt"><?= number_format($earnings['paid_requests']) ?></span></div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer">
                            <a href="earnings.php" class="btn btn-primary btn-sm">Open Earnings Report</a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-scan me-2 text-muted"></i>Reference Verification Queue</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">Cross-check NG references before converting or auditing paid registrations.</p>
                        </div>
                        <?php if (!empty($referenceReviewQueue)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($referenceReviewQueue as $referenceItem): ?>
                                    <?php $referenceMeta = evaluateNgReference($referenceItem); ?>
                                    <?php
                                    $refBadge = match($referenceMeta['class'] ?? '') {
                                        'status-active', 'status-approved' => 'bg-green-lt text-green',
                                        'status-pending' => 'bg-orange-lt text-orange',
                                        'status-rejected', 'status-inactive' => 'bg-red-lt text-red',
                                        'status-converted', 'status-billing_sent' => 'bg-blue-lt text-blue',
                                        'status-paid' => 'bg-teal-lt text-teal',
                                        'status-warning' => 'bg-orange-lt text-orange',
                                        default => 'bg-secondary-lt',
                                    };
                                    ?>
                                    <a class="list-group-item list-group-item-action" href="<?= htmlspecialchars($mechanixSuperadminRegistrationPage, ENT_QUOTES, 'UTF-8') ?>?registration_id=<?= (int) $referenceItem['registration_id'] ?><?= $mechanixSuperadminRegListFragment ?>">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars($referenceItem['business_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small">
                                                    <?= htmlspecialchars($referenceItem['payment_reference'] ?: 'No reference yet', ENT_QUOTES, 'UTF-8') ?>
                                                    &middot; <?= htmlspecialchars($referenceItem['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $referenceItem['total_amount'], 2) ?>
                                                </div>
                                            </div>
                                            <div class="col-auto"><span class="badge <?= $refBadge ?>"><?= htmlspecialchars($referenceMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="card-body">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No pending references</p>
                                    <p class="empty-subtitle text-muted">No billing requests are waiting for reference review.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Plan Catalog + Add-On Catalog -->
            <div id="catalog" class="row g-3 mb-4 align-items-start">
                <div class="col-lg-6">
                    <?php if (!empty($planCatalog)): ?>
                        <div class="sa-catalog-plans d-flex flex-column gap-3">
                            <div class="card sa-dark-card">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="ti ti-packages me-2 text-muted"></i>Plan Catalog</h3>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted small mb-0">Adjust pricing in real time and keep registration, billing drafts, and future conversions aligned with the latest catalog values.</p>
                                </div>
                            </div>
                            <?php foreach ($planCatalog as $plan): ?>
                                <div class="card sa-plan-edit-card">
                                    <div class="card-body">
                                        <form action="actions/update_plan_catalog.php" method="POST">
                                            <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="plan_id" value="<?= (int) $plan['plan_id'] ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Plan Name</label>
                                                <input class="form-control" type="text" name="plan_name" value="<?= htmlspecialchars($plan['plan_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                                            </div>
                                            <div class="row g-2 mb-3">
                                                <div class="col">
                                                    <label class="form-label">Monthly Price</label>
                                                    <input class="form-control" type="number" step="0.01" min="0" name="monthly_price" value="<?= htmlspecialchars((string) $plan['monthly_price'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                                <div class="col">
                                                    <label class="form-label">Yearly Price</label>
                                                    <input class="form-control" type="number" step="0.01" min="0" name="yearly_price" value="<?= htmlspecialchars((string) $plan['yearly_price'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Description</label>
                                                <textarea class="form-control" rows="2" name="description"><?= htmlspecialchars($plan['description'] ?: '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="is_active" value="1" <?= (int) $plan['is_active'] === 1 ? 'checked' : '' ?>>
                                                    <span class="form-check-label">Active in catalog</span>
                                                </label>
                                            </div>
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <span class="badge <?= (int) $plan['is_active'] === 1 ? 'bg-green-lt text-green' : 'bg-red-lt text-red' ?>">
                                                    <?= (int) $plan['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                                </span>
                                                <button type="submit" class="btn btn-primary btn-sm ms-auto">Save Plan</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="ti ti-packages me-2 text-muted"></i>Plan Catalog</h3>
                            </div>
                            <div class="card-body">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No plans configured</p>
                                    <p class="empty-subtitle text-muted">No subscription plans are configured yet.</p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-puzzle me-2 text-muted"></i>Add-On Catalog</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">Reference view of the optional features currently priced for registrations and billing drafts.</p>
                        </div>
                        <?php if (!empty($addonCatalog)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($addonCatalog as $addon): ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $addon['feature_name'])), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small">
                                                    Monthly: PHP <?= number_format((float) $addon['monthly_addon_price'], 2) ?>
                                                    &middot; Yearly: PHP <?= number_format((float) $addon['yearly_addon_price'], 2) ?>
                                                </div>
                                                <div class="text-muted small"><?= htmlspecialchars($addon['description'] ?: 'No add-on description provided.', ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <div class="col-auto">
                                                <span class="badge <?= (int) $addon['is_active'] === 1 ? 'bg-green-lt text-green' : 'bg-red-lt text-red' ?>">
                                                    <?= (int) $addon['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="card-body">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No add-ons configured</p>
                                    <p class="empty-subtitle text-muted">No add-on pricing is configured yet.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php require __DIR__ . '/partials/section_registration_workspace.php'; ?>

            <?php require __DIR__ . '/partials/section_tenant_workspace.php'; ?>


            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/js/tabler.min.js"></script>
<script src="../assets/js/theme.js?v=2"></script>
</body>
</html>
