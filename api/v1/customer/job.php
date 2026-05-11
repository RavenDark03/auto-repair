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

$jobId = (int) ($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    api_error('validation_error', 'job_id is required.', 422);
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
    api_error('not_found', 'Job not found.', 404);
}

$sql = '
    SELECT ' . api_job_select_sql() . '
    FROM jobs j
    INNER JOIN appointments a ON a.appointment_id = j.appointment_id AND a.tenant_id = j.tenant_id
    INNER JOIN customers c ON c.customer_id = a.customer_id AND c.tenant_id = j.tenant_id
    INNER JOIN vehicles v ON v.vehicle_id = a.vehicle_id AND v.tenant_id = j.tenant_id
    WHERE j.tenant_id = :tid
      AND j.job_id = :jid
      AND a.customer_id = :cid
    LIMIT 1
';
$st = $pdo->prepare($sql);
$st->execute(['tid' => $ctx['tenant_id'], 'jid' => $jobId, 'cid' => $cid]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    api_error('not_found', 'Job not found.', 404);
}

$row['has_invoice'] = (int) ($row['has_invoice'] ?? 0) > 0;
api_json(['ok' => true, 'item' => api_job_row_to_payload($row)]);
