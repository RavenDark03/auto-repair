<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/bearer.php';
require_once __DIR__ . '/../includes/tenant_features_api.php';
require_once __DIR__ . '/../includes/customer_context.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    api_error('method_not_allowed', 'Use GET.', 405);
}

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'customer') {
    api_error('forbidden', 'Customer role required.', 403);
}

$cid = api_resolve_customer_id($pdo, $ctx['tenant_id'], $ctx['username'], $ctx['user_id']);
if ($cid === null) {
    api_json(['ok' => true, 'item' => [
        'activeRepairJobs' => 0,
        'ownedVehicles' => 0,
        'outstandingBalance' => 0,
        'recentServiceHistory' => [],
    ]]);
}

$summaryStmt = $pdo->prepare('
    SELECT
        (SELECT COUNT(*)
         FROM jobs j
         INNER JOIN appointments a ON a.appointment_id = j.appointment_id AND a.tenant_id = j.tenant_id
         WHERE j.tenant_id = :tid AND a.customer_id = :cid AND j.status IN (\'pending_inspection\', \'in_repair\', \'waiting_for_parts\', \'ongoing\')) AS active_jobs,
        (SELECT COUNT(*) FROM vehicles WHERE tenant_id = :tid AND customer_id = :cid AND status = \'active\') AS vehicles,
        (SELECT COALESCE(SUM(i.total - i.amount_paid), 0)
         FROM invoices i
         INNER JOIN jobs j ON j.job_id = i.job_id AND j.tenant_id = i.tenant_id
         INNER JOIN appointments a ON a.appointment_id = j.appointment_id AND a.tenant_id = j.tenant_id
         WHERE i.tenant_id = :tid AND a.customer_id = :cid AND i.status IN (\'unpaid\', \'partial\')) AS balance
');
$summaryStmt->execute(['tid' => $ctx['tenant_id'], 'cid' => $cid]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$historyStmt = $pdo->prepare('
    SELECT j.job_id, j.status, a.appointment_date, v.make, v.model, v.plate
    FROM jobs j
    INNER JOIN appointments a ON a.appointment_id = j.appointment_id AND a.tenant_id = j.tenant_id
    INNER JOIN vehicles v ON v.vehicle_id = a.vehicle_id AND v.tenant_id = j.tenant_id
    WHERE j.tenant_id = :tid
      AND a.customer_id = :cid
      AND j.status = \'completed\'
    ORDER BY COALESCE(j.completed_at, j.updated_at) DESC, j.job_id DESC
    LIMIT 5
');
$historyStmt->execute(['tid' => $ctx['tenant_id'], 'cid' => $cid]);
$history = array_map(static function (array $row): array {
    return [
        'jobId' => (string) $row['job_id'],
        'status' => (string) $row['status'],
        'serviceDate' => (string) ($row['appointment_date'] ?? ''),
        'vehicle' => trim((string) ($row['make'] ?? '') . ' ' . (string) ($row['model'] ?? '')),
        'plate' => (string) ($row['plate'] ?? ''),
    ];
}, $historyStmt->fetchAll(PDO::FETCH_ASSOC));

api_json(['ok' => true, 'item' => [
    'activeRepairJobs' => (int) ($summary['active_jobs'] ?? 0),
    'ownedVehicles' => (int) ($summary['vehicles'] ?? 0),
    'outstandingBalance' => (float) ($summary['balance'] ?? 0),
    'recentServiceHistory' => $history,
]]);
