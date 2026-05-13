<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/platform_rules.php';

requireAdmin();

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$flashMessage = $_SESSION['settings_success'] ?? null;
$errorMessage = $_SESSION['settings_error'] ?? null;
unset($_SESSION['settings_success'], $_SESSION['settings_error']);

$tenant = [
    'business_name' => $businessName,
    'contact_phone' => '',
    'contact_email' => '',
    'address' => '',
    'operating_hours' => '',
    'status' => '',
    'access_mode' => '',
    'subscription_plan' => 'No plan assigned',
    'subscription_status' => 'No subscription',
    'subscription_window' => 'Not set',
];

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT
            t.business_name,
            t.contact_phone,
            t.contact_email,
            t.address,
            t.operating_hours,
            t.status,
            t.access_mode,
            s.plan,
            s.status AS subscription_status,
            s.start_date,
            s.end_date
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
    $stmt->execute(['tenant_id' => $tenantId]);
    $row = $stmt->fetch();

    if ($row) {
        $tenant['business_name'] = $row['business_name'] ?? $businessName;
        $tenant['contact_phone'] = $row['contact_phone'] ?? '';
        $tenant['contact_email'] = $row['contact_email'] ?? '';
        $tenant['address'] = $row['address'] ?? '';
        $tenant['operating_hours'] = $row['operating_hours'] ?? '';
        $tenant['status'] = $row['status'] ?? '';
        $tenant['access_mode'] = $row['access_mode'] ?? '';
        $tenant['subscription_plan'] = $row['plan'] ?: 'No plan assigned';
        $tenant['subscription_status'] = $row['subscription_status'] ?: 'No subscription';
        $tenant['subscription_window'] = ($row['start_date'] ?: 'Not set') . ' to ' . ($row['end_date'] ?: 'Not set');
        $businessName = $tenant['business_name'];
        $_SESSION['business_name'] = $businessName;
    }
} catch (PDOException $e) {
    $errorMessage = 'Settings could not be loaded: ' . $e->getMessage();
}

$showAnalytics = tenantHasFeature('reports', $tenantId);
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Settings - MECHANIX</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="../assets/css/tabler-mechanix-bridge.css">
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/superadmin-landing-theme.css">
</head>
<body class="page-shell antialiased tenant-app">
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'settings.php', $showAnalytics) ?>

        <main class="dashboard-main" id="main-content" tabindex="-1">
            <?= renderTenantAdminTopbar('Tenant Settings', 'Manage shop profile, contact details, operating hours, and account status.') ?>

            <?php if ($flashMessage !== null): ?>
                <div class="alert alert-success"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($errorMessage !== null): ?>
                <div class="alert alert-error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?= renderTenantAccessModeNotice() ?>

            <section class="dashboard-grid">
                <article class="metric-card"><span>Account Status</span><h3><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $tenant['status'])), ENT_QUOTES, 'UTF-8') ?></h3></article>
                <article class="metric-card"><span>Access Mode</span><h3><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $tenant['access_mode'])), ENT_QUOTES, 'UTF-8') ?></h3></article>
                <article class="metric-card"><span>Subscription</span><h3><?= htmlspecialchars($tenant['subscription_status'], ENT_QUOTES, 'UTF-8') ?></h3></article>
                <article class="metric-card"><span>Plan</span><h3><?= htmlspecialchars($tenant['subscription_plan'], ENT_QUOTES, 'UTF-8') ?></h3></article>
            </section>

            <section class="content-grid">
                <article class="content-card">
                    <h3>Shop Profile</h3>
                    <p>These details identify the tenant workspace across the admin and mobile experiences.</p>

                    <form action="actions/update_tenant_settings.php" method="POST" class="feature-toggle-form">
                        <div class="form-group">
                            <label for="business_name">Business Name</label>
                            <input class="form-control" type="text" id="business_name" name="business_name" value="<?= htmlspecialchars($tenant['business_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="contact_phone">Contact Phone</label>
                                <input class="form-control" type="text" id="contact_phone" name="contact_phone" value="<?= htmlspecialchars($tenant['contact_phone'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="form-group">
                                <label for="contact_email">Contact Email</label>
                                <input class="form-control" type="email" id="contact_email" name="contact_email" value="<?= htmlspecialchars($tenant['contact_email'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea class="form-control form-textarea" id="address" name="address"><?= htmlspecialchars($tenant['address'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="operating_hours">Operating Hours</label>
                            <textarea class="form-control form-textarea" id="operating_hours" name="operating_hours"><?= htmlspecialchars($tenant['operating_hours'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="approval-actions">
                            <button type="submit" class="btn btn-primary">Save Settings</button>
                        </div>
                    </form>
                </article>

                <article class="content-card">
                    <h3>Subscription Status</h3>
                    <p>Subscription status is controlled by the platform administrator and shown here for tenant visibility.</p>
                    <div class="dashboard-list">
                        <div class="dashboard-list-item"><div><strong>Plan</strong><p><?= htmlspecialchars($tenant['subscription_plan'], ENT_QUOTES, 'UTF-8') ?></p></div></div>
                        <div class="dashboard-list-item"><div><strong>Status</strong><p><?= htmlspecialchars($tenant['subscription_status'], ENT_QUOTES, 'UTF-8') ?></p></div></div>
                        <div class="dashboard-list-item"><div><strong>Term Window</strong><p><?= htmlspecialchars($tenant['subscription_window'], ENT_QUOTES, 'UTF-8') ?></p></div></div>
                    </div>
                </article>
            </section>
        </main>
    </div>

    <?= renderTenantAdminFooterScripts() ?>
</body>
</html>
