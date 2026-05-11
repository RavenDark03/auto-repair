<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/bearer.php';
require_once __DIR__ . '/../includes/tenant_features_api.php';
require_once __DIR__ . '/../includes/job_queries.php';
require_once __DIR__ . '/../includes/job_format.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    api_error('method_not_allowed', 'Use GET.', 405);
}

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'mechanic') {
    api_error('forbidden', 'Mechanic role required.', 403);
}

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'jobs')) {
    api_error('feature_disabled', 'Jobs module is not enabled for this tenant.', 403);
}

$tid = $ctx['tenant_id'];
$mid = $ctx['user_id'];

$sql = '
    SELECT ' . api_job_select_sql() . '
    FROM jobs j
    INNER JOIN appointments a ON a.appointment_id = j.appointment_id AND a.tenant_id = j.tenant_id
    INNER JOIN customers c ON c.customer_id = a.customer_id AND c.tenant_id = j.tenant_id
    INNER JOIN vehicles v ON v.vehicle_id = a.vehicle_id AND v.tenant_id = j.tenant_id
    WHERE j.tenant_id = :tid
      AND j.mechanic_id = :mid
    ORDER BY j.job_id DESC
';
$st = $pdo->prepare($sql);
$st->execute(['tid' => $tid, 'mid' => $mid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$items = [];
foreach ($rows as $row) {
    $row['has_invoice'] = (int) ($row['has_invoice'] ?? 0) > 0;
    $items[] = api_job_row_to_payload($row);
}

api_json(['ok' => true, 'items' => $items]);
