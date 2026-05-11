<?php
declare(strict_types=1);

/** Same semantics as includes/feature_access.php without loading PHP sessions. */
function api_get_tenant_feature_map(PDO $pdo, int $tenantId): array {
    if ($tenantId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('
        SELECT f.feature_name, tf.is_enabled
        FROM features f
        LEFT JOIN tenant_features tf
            ON tf.feature_id = f.feature_id AND tf.tenant_id = :tenant_id
        ORDER BY f.feature_name ASC
    ');
    $stmt->execute(['tenant_id' => $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasExplicit = false;
    foreach ($rows as $row) {
        if ($row['is_enabled'] !== null) {
            $hasExplicit = true;
            break;
        }
    }

    $map = [];
    foreach ($rows as $row) {
        $enabled = $row['is_enabled'] !== null
            ? ((int) $row['is_enabled'] === 1)
            : !$hasExplicit;
        $map[$row['feature_name']] = $enabled;
    }
    if (!$hasExplicit) {
        foreach (array_keys($map) as $k) {
            $map[$k] = true;
        }
    }
    return $map;
}

function api_tenant_has_feature(PDO $pdo, int $tenantId, string $featureName): bool {
    $m = api_get_tenant_feature_map($pdo, $tenantId);
    return !empty($m[$featureName]);
}
