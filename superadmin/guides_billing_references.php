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
    <title>Billing references playbook - MECHANIX</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/superadmin-landing-theme.css">
</head>
<body class="page-shell antialiased superadmin-app superadmin-platform">
<div class="dashboard">
    <?php mechanix_superadmin_render_sidebar('guide_billing', ['pending_registrations' => $pendingCount]); ?>

    <main class="dashboard-main sa-dashboard-main" id="main-content" tabindex="-1">
        <div class="dashboard-topbar">
            <div class="dashboard-title">
                <span class="sa-eyebrow">Super Admin</span>
                <h2>Billing references playbook</h2>
                <p>Verify payment references before conversion and keep PayMongo manual flows auditable.</p>
            </div>
            <div class="nav-actions"><?= mechanix_theme_toggle_button() ?></div>
        </div>

        <div class="page-body sa-superadmin-page-body">
            <section class="content-grid superadmin-grid">
                <article class="content-card">
                    <h3>Verification checklist</h3>
                    <ol class="sa-guide-list">
                        <li>Open <a href="registrations.php">Registrations</a> and pick the row with a paid or pending reference.</li>
                        <li>Compare the recorded reference and amount with your payment provider or bank memo.</li>
                        <li>Use the Command Center <strong>Reference verification queue</strong> on <a href="dashboard.php">dashboard</a> for quick jumps.</li>
                        <li>Only mark paid or convert when the reference reconciles; otherwise leave a clear note for the applicant.</li>
                        <li>Export detail and trends from <a href="earnings.php">Earnings</a> after close.</li>
                    </ol>
                </article>
                <article class="content-card">
                    <h3>Tools</h3>
                    <div class="dashboard-list compact-list">
                        <a class="dashboard-list-item dashboard-link-card" href="dashboard.php">
                            <div><strong>Command Center</strong><p>Reference queue snapshot</p></div>
                            <span class="metric-pill">Open</span>
                        </a>
                        <a class="dashboard-list-item dashboard-link-card" href="registrations.php">
                            <div><strong>Registrations</strong><p>Deep review and billing actions</p></div>
                            <span class="metric-pill">Open</span>
                        </a>
                        <a class="dashboard-list-item dashboard-link-card" href="earnings.php">
                            <div><strong>Earnings</strong><p>Paid requests and monthly trend</p></div>
                            <span class="metric-pill">Open</span>
                        </a>
                    </div>
                </article>
            </section>
        </div>
    </main>
</div>
<script src="../assets/js/theme.js?v=3"></script>
<script src="../assets/js/mechanix-logout-dialog.js"></script>
</body>
</html>
