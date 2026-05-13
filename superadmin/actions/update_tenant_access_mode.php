<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/super_admin_auth.php';
require_once __DIR__ . '/../../includes/db.php';

require_once __DIR__ . '/../../includes/superadmin_redirects.php';

requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mechanix_superadmin_non_post_redirect_url());
    exit;
}

$tenantId = (int) ($_POST['tenant_id'] ?? 0);
$accessMode = trim($_POST['access_mode'] ?? '');
$allowedModes = ['full_access', 'read_only'];

if ($tenantId <= 0 || !in_array($accessMode, $allowedModes, true)) {
    $_SESSION['super_admin_error'] = 'A valid tenant access mode is required.';
    header('Location: ' . mechanix_superadmin_tenant_redirect_url($tenantId));
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $subscriptionStmt = $pdo->prepare("
        SELECT s.subscription_id, s.plan, t.business_name, t.access_mode, t.read_only_source_plan
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
    $subscriptionStmt->execute(['tenant_id' => $tenantId]);
    $tenant = $subscriptionStmt->fetch();

    if (!$tenant) {
        throw new RuntimeException('The selected tenant could not be found.');
    }

    $currentPlan = (string) ($tenant['plan'] ?? '');
    $sourcePlan = (string) ($tenant['read_only_source_plan'] ?? '');
    $businessName = (string) $tenant['business_name'];

    if ($accessMode === 'read_only') {
        $updateTenant = $pdo->prepare("
            UPDATE tenants
            SET access_mode = 'read_only',
                read_only_source_plan = CASE
                    WHEN :current_plan <> '' AND :current_plan <> 'Read-Only' THEN :current_plan
                    ELSE read_only_source_plan
                END
            WHERE tenant_id = :tenant_id
        ");
        $updateTenant->execute([
            'current_plan' => $currentPlan,
            'tenant_id' => $tenantId,
        ]);

        if (!empty($tenant['subscription_id'])) {
            $updateSubscription = $pdo->prepare("
                UPDATE subscriptions
                SET plan = 'Read-Only'
                WHERE subscription_id = :subscription_id
            ");
            $updateSubscription->execute(['subscription_id' => (int) $tenant['subscription_id']]);
        }

        $_SESSION['super_admin_success'] = $businessName . ' was downgraded to the Read-Only plan.';
    } else {
        $restoredPlan = $sourcePlan !== '' ? $sourcePlan : 'Starter';

        $updateTenant = $pdo->prepare("
            UPDATE tenants
            SET access_mode = 'full_access',
                read_only_source_plan = NULL
            WHERE tenant_id = :tenant_id
        ");
        $updateTenant->execute(['tenant_id' => $tenantId]);

        if (!empty($tenant['subscription_id'])) {
            $updateSubscription = $pdo->prepare("
                UPDATE subscriptions
                SET plan = :plan
                WHERE subscription_id = :subscription_id
            ");
            $updateSubscription->execute([
                'plan' => $restoredPlan,
                'subscription_id' => (int) $tenant['subscription_id'],
            ]);
        }

        $_SESSION['super_admin_success'] = $businessName . ' was restored to full access.';
    }

    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['super_admin_error'] = 'Tenant access mode update failed: ' . $e->getMessage();
}

header('Location: ' . mechanix_superadmin_tenant_redirect_url($tenantId));
exit;
