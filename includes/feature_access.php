<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

function getTenantFeatureMap($tenantId) {
    static $cache = [];

    $tenantId = (int) $tenantId;

    if ($tenantId <= 0) {
        return [];
    }

    if (isset($cache[$tenantId])) {
        return $cache[$tenantId];
    }

    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        SELECT
            f.feature_name,
            tf.is_enabled
        FROM features f
        LEFT JOIN tenant_features tf
            ON tf.feature_id = f.feature_id
           AND tf.tenant_id = :tenant_id
        ORDER BY f.feature_name ASC
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $rows = $stmt->fetchAll();

    $featureMap = [];
    $hasExplicitSettings = false;

    foreach ($rows as $row) {
        if ($row['is_enabled'] !== null) {
            $hasExplicitSettings = true;
            break;
        }
    }

    foreach ($rows as $row) {
        $isEnabled = $row['is_enabled'] !== null
            ? (int) $row['is_enabled'] === 1
            : !$hasExplicitSettings;

        $featureMap[$row['feature_name']] = $isEnabled;
    }

    if (!$hasExplicitSettings) {
        foreach ($featureMap as $featureName => $unused) {
            $featureMap[$featureName] = true;
        }
    }

    $cache[$tenantId] = $featureMap;
    return $cache[$tenantId];
}

function tenantHasExplicitFeatureSettings($tenantId) {
    static $cache = [];

    $tenantId = (int) $tenantId;

    if ($tenantId <= 0) {
        return false;
    }

    if (isset($cache[$tenantId])) {
        return $cache[$tenantId];
    }

    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tenant_features
        WHERE tenant_id = :tenant_id
    ");
    $stmt->execute(['tenant_id' => $tenantId]);

    $cache[$tenantId] = (int) $stmt->fetchColumn() > 0;
    return $cache[$tenantId];
}

function tenantHasFeature($featureName, $tenantId = null) {
    if ($tenantId === null) {
        $tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
    }

    $featureMap = getTenantFeatureMap($tenantId);

    return !empty($featureMap[$featureName]);
}

function requireTenantFeature($featureName, $redirectPath = '../admin/dashboard.php') {
    $tenantId = (int) ($_SESSION['tenant_id'] ?? 0);

    if (!tenantHasFeature($featureName, $tenantId)) {
        $_SESSION['error_message'] = 'This module is not enabled for your tenant.';
        $_SESSION['auth_error'] = 'This module is not enabled for your tenant.';
        header('Location: ' . $redirectPath);
        exit;
    }
}

function getTenantEnabledFeatureCount($tenantId) {
    return count(array_filter(getTenantFeatureMap($tenantId)));
}
?>
