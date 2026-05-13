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
    api_json(['ok' => true, 'items' => []]);
}

$vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
$sql = '
    SELECT j.job_id, j.status, a.appointment_date, v.vehicle_id, v.make, v.model, v.plate,
           COALESCE((
                SELECT GROUP_CONCAT(CONCAT(i.part_name, \' x\', jpu.quantity_used) ORDER BY jpu.id SEPARATOR \', \')
                FROM job_parts_used jpu
                INNER JOIN inventory i ON i.inventory_id = jpu.inventory_id AND i.tenant_id = jpu.tenant_id
                WHERE jpu.tenant_id = j.tenant_id AND jpu.job_id = j.job_id
           ), \'\') AS parts_used
    FROM jobs j
    INNER JOIN appointments a ON a.appointment_id = j.appointment_id AND a.tenant_id = j.tenant_id
    INNER JOIN vehicles v ON v.vehicle_id = a.vehicle_id AND v.tenant_id = j.tenant_id
    WHERE j.tenant_id = :tid
      AND a.customer_id = :cid
      AND j.status = \'completed\'
';
$params = ['tid' => $ctx['tenant_id'], 'cid' => $cid];
if ($vehicleId > 0) {
    $sql .= ' AND v.vehicle_id = :vid ';
    $params['vid'] = $vehicleId;
}
$sql .= ' ORDER BY a.appointment_date DESC, j.job_id DESC ';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$items = array_map(static function (array $row): array {
    return [
        'jobId' => (string) $row['job_id'],
        'status' => (string) $row['status'],
        'serviceDate' => (string) ($row['appointment_date'] ?? ''),
        'vehicleId' => (string) $row['vehicle_id'],
        'vehicle' => trim((string) ($row['make'] ?? '') . ' ' . (string) ($row['model'] ?? '')),
        'plate' => (string) ($row['plate'] ?? ''),
        'partsUsed' => (string) ($row['parts_used'] ?? ''),
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

api_json(['ok' => true, 'items' => $items]);
