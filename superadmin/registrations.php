<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mechanix_ui.php';
require_once __DIR__ . '/../includes/superadmin_sidebar.php';

requireSuperAdmin();

$mechanixSuperadminLoadOpts = [
    'auto_select_tenant' => false,
    'auto_select_registration' => true,
];
require_once __DIR__ . '/includes/superadmin_command_data.php';

$mechanixSuperadminRegistrationPage = 'registrations.php';
$mechanixSuperadminTenantPage = 'tenant_operations.php';
$mechanixSuperadminContext = 'registrations';
$mechanixSuperadminRegListFragment = $mechanixSuperadminRegistrationPage === 'dashboard.php' ? '#registrations' : '';
$mechanixSuperadminTenantListFragment = $mechanixSuperadminTenantPage === 'dashboard.php' ? '#features' : '';

$pendingCount = (int) ($summary['pending_registrations'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrations - MECHANIX</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap">
    <link rel="stylesheet" href="../assets/css/tabler-mechanix-bridge.css">
    <link rel="stylesheet" href="../assets/css/superadmin-landing-theme.css">
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body class="page-shell antialiased superadmin-app superadmin-platform">
<div class="dashboard">
    <?php mechanix_superadmin_render_sidebar('registrations', ['pending_registrations' => $pendingCount]); ?>

    <main class="dashboard-main sa-dashboard-main" id="main-content" tabindex="-1">
        <div class="dashboard-topbar">
            <div class="dashboard-title">
                <span class="sa-eyebrow">Super Admin</span>
                <h2>Registrations</h2>
                <p>Review, approve, bill, and convert applicants without leaving this workspace.</p>
            </div>
            <div class="nav-actions"><?= mechanix_theme_toggle_button() ?></div>
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

                <?php require __DIR__ . '/partials/section_registration_workspace.php'; ?>
            </div>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/js/tabler.min.js"></script>
<script src="../assets/js/theme.js?v=2"></script>
</body>
</html>
