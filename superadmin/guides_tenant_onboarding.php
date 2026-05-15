<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mechanix_ui.php';
require_once __DIR__ . '/../includes/superadmin_sidebar.php';

requireSuperAdmin();

$pdo = Database::getInstance();
$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM tenant_registrations WHERE registration_status = 'pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant onboarding playbook - MECHANIX</title>
    <?= mechanix_link_styles_plain_workspace('../assets/css/') ?>
</head>
<body class="page-shell antialiased superadmin-app superadmin-platform">
<div class="dashboard">
    <?php mechanix_superadmin_render_sidebar('guide_onboarding', ['pending_registrations' => $pendingCount]); ?>

    <main class="dashboard-main sa-dashboard-main" id="main-content" tabindex="-1">
        <div class="dashboard-topbar">
            <div class="dashboard-title">
                <span class="sa-eyebrow">Super Admin</span>
                <h2>Tenant onboarding playbook</h2>
                <p>Repeatable flow from registration to first tenant login, aligned with isolated tools in the sidebar.</p>
            </div>
            <div class="nav-actions"><?= mechanix_theme_toggle_button() ?></div>
        </div>

        <div class="page-body sa-superadmin-page-body">
            <div class="sa-onboarding-guide-wrap">
            <section class="content-grid superadmin-grid sa-onboarding-guide-grid">
                <article class="content-card">
                    <h3>Happy path</h3>
                    <ol class="sa-guide-list">
                        <li>Review the queue in <a href="registrations.php">Registrations</a>; verify business and owner details.</li>
                        <li>Approve or request changes; generate billing when pricing is confirmed.</li>
                        <li>After payment is marked paid, convert to tenant and confirm credentials in Command Center.</li>
                        <li>Open <a href="../login.php">tenant login</a> and sign in with the issued admin username and temporary password.</li>
                        <li>Validate modules match the plan in <a href="tenant_operations.php">Tenant operations</a>.</li>
                    </ol>
                </article>
                <article class="content-card">
                    <h3>Where to work</h3>
                    <div class="dashboard-list compact-list">
                        <a class="dashboard-list-item dashboard-link-card" href="registrations.php">
                            <div><strong>Registrations</strong><p>Approvals, billing drafts, checkout, conversion</p></div>
                            <span class="metric-pill">Open</span>
                        </a>
                        <a class="dashboard-list-item dashboard-link-card" href="tenant_status.php">
                            <div><strong>Tenant status</strong><p>Directory health after go-live</p></div>
                            <span class="metric-pill">Open</span>
                        </a>
                        <a class="dashboard-list-item dashboard-link-card" href="subscriptions.php">
                            <div><strong>Subscriptions</strong><p>Windows and renewals</p></div>
                            <span class="metric-pill">Open</span>
                        </a>
                    </div>
                </article>
            </section>
            </div>
        </div>
    </main>
</div>
<script src="../assets/js/theme.js?v=3"></script>
<script src="../assets/js/mechanix-logout-dialog.js"></script>
</body>
</html>
