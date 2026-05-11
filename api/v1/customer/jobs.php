<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/bearer.php';
require_once __DIR__ . '/../includes/tenant_features_api.php';
require_once __DIR__ . '/../includes/customer_context.php';
require_once __DIR__ . '/../includes/job_queries.php';
require_once __DIR__ . '/../includes/job_format.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    api_error('method_not_allowed', 'Use GET.', 405);
}

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'customer') {
    api_error('forbidden', 'Customer role required.', 403);
}

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'jobs')) {
    api_error('feature_disabled', 'Jobs module is not enabled for this tenant.', 403);
}

$cid = api_resolve_customer_id($pdo, $ctx['tenant_id'], $ctx['username']);
if ($cid === null) {
    api_json(['ok' => true, 'items' => []]);
}

$sql = '
    SELECT ' . api_job_select_sql() . '
    FROM jobs j
    INNER JOIN appointments a ON a.appointment_id = j.appointment_id AND a.tenant_id = j.tenant_id
    INNER JOIN customers c ON c.customer_id = a.customer_id AND c.tenant_id = j.tenant_id
    INNER JOIN vehicles v ON v.vehicle_id = a.vehicle_id AND v.tenant_id = j.tenant_id
    WHERE j.tenant_id = :tid
      AND a.customer_id = :cid
    ORDER BY j.job_id DESC
';
$st = $pdo->prepare($sql);
$st->execute(['tid' => $ctx['tenant_id'], 'cid' => $cid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$items = [];
foreach ($rows as $row) {
    $row['has_invoice'] = (int) ($row['has_invoice'] ?? 0) > 0;
    $items[] = api_job_row_to_payload($row);
}

api_json(['ok' => true, 'items' => $items]);
