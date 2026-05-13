<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/super_admin_auth.php';
require_once __DIR__ . '/../../includes/db.php';

requireSuperAdmin();
require_once __DIR__ . '/../../includes/superadmin_redirects.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mechanix_superadmin_non_post_redirect_url());
    exit;
}

$tenantId = (int) ($_POST['tenant_id'] ?? 0);
$status = $_POST['status'] ?? '';
$allowedStatuses = ['active', 'inactive', 'pending_payment'];

if ($tenantId <= 0 || !in_array($status, $allowedStatuses, true)) {
    $_SESSION['super_admin_error'] = 'A valid tenant status update is required.';
    header('Location: ' . mechanix_superadmin_tenant_redirect_url($tenantId));
    exit;
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
    $tenant = $tenantStmt->fetch();

    if (!$tenant) {
        throw new RuntimeException('The selected tenant could not be found.');
    }

    $updateStmt = $pdo->prepare("
        UPDATE tenants
        SET status = :status
        WHERE tenant_id = :tenant_id
    ");
    $updateStmt->execute([
        'status' => $status,
        'tenant_id' => $tenantId,
    ]);

    $_SESSION['super_admin_success'] = 'Tenant status for ' . $tenant['business_name'] . ' updated to ' . $status . '.';
    header('Location: ' . mechanix_superadmin_tenant_redirect_url($tenantId));
    exit;
} catch (Throwable $e) {
    $_SESSION['super_admin_error'] = 'Tenant status update failed: ' . $e->getMessage();
    header('Location: ' . mechanix_superadmin_tenant_redirect_url($tenantId));
    exit;
}
?>
