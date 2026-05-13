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
$selectedFeatures = $_POST['features'] ?? [];
$selectedFeatureIds = [];

foreach ($selectedFeatures as $featureId) {
    $selectedFeatureIds[] = (int) $featureId;
}

$selectedFeatureIds = array_values(array_unique(array_filter($selectedFeatureIds)));

if ($tenantId <= 0) {
    $_SESSION['super_admin_error'] = 'A valid tenant is required before saving feature toggles.';
    header('Location: ' . mechanix_superadmin_tenant_redirect_url($tenantId));
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $tenantCheckStmt = $pdo->prepare("
        SELECT tenant_id
        FROM tenants
        WHERE tenant_id = :tenant_id
        LIMIT 1
    ");
    $tenantCheckStmt->execute(['tenant_id' => $tenantId]);

    if (!$tenantCheckStmt->fetch()) {
        throw new RuntimeException('The selected tenant does not exist.');
    }

    $featureIds = $pdo->query("SELECT feature_id FROM features")->fetchAll(PDO::FETCH_COLUMN);

    $upsertStmt = $pdo->prepare("
        INSERT INTO tenant_features (tenant_id, feature_id, is_enabled)
        VALUES (:tenant_id, :feature_id, :is_enabled)
        ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)
    ");

    foreach ($featureIds as $featureId) {
        $upsertStmt->execute([
            'tenant_id' => $tenantId,
            'feature_id' => (int) $featureId,
            'is_enabled' => in_array((int) $featureId, $selectedFeatureIds, true) ? 1 : 0,
        ]);
    }

    $pdo->commit();

    $_SESSION['super_admin_success'] = 'Tenant features updated successfully.';
    header('Location: ' . mechanix_superadmin_tenant_redirect_url($tenantId));
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['super_admin_error'] = 'Feature toggle update failed: ' . $e->getMessage();
    header('Location: ' . mechanix_superadmin_tenant_redirect_url($tenantId));
    exit;
}
?>
