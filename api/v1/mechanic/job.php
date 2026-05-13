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

$jobId = (int) ($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    api_error('validation_error', 'job_id is required.', 422);
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
      AND j.job_id = :jid
      AND j.mechanic_id = :mid
    LIMIT 1
';
$st = $pdo->prepare($sql);
$st->execute(['tid' => $tid, 'jid' => $jobId, 'mid' => $mid]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    api_error('not_found', 'Job not found.', 404);
}

$row['has_invoice'] = (int) ($row['has_invoice'] ?? 0) > 0;
$item = api_job_row_to_payload($row);

$servicesStmt = $pdo->prepare('
    SELECT job_service_id, service_name, description, quantity, unit_price, line_total, created_at
    FROM job_services
    WHERE tenant_id = :tid
      AND job_id = :jid
    ORDER BY job_service_id ASC
');
$servicesStmt->execute(['tid' => $tid, 'jid' => $jobId]);
$item['services'] = array_map(static function (array $service): array {
    return [
        'id' => (string) $service['job_service_id'],
        'name' => (string) $service['service_name'],
        'description' => (string) ($service['description'] ?? ''),
        'quantity' => (float) $service['quantity'],
        'unitPrice' => (float) $service['unit_price'],
        'lineTotal' => (float) $service['line_total'],
        'createdAt' => (string) $service['created_at'],
    ];
}, $servicesStmt->fetchAll(PDO::FETCH_ASSOC));

$partsStmt = $pdo->prepare('
    SELECT jpu.id, i.inventory_id, i.part_name, i.sku, jpu.quantity_used, i.unit_cost, jpu.created_at
    FROM job_parts_used jpu
    INNER JOIN inventory i
        ON i.inventory_id = jpu.inventory_id
       AND i.tenant_id = jpu.tenant_id
    WHERE jpu.tenant_id = :tid
      AND jpu.job_id = :jid
    ORDER BY jpu.id ASC
');
$partsStmt->execute(['tid' => $tid, 'jid' => $jobId]);
$item['partsUsed'] = array_map(static function (array $part): array {
    $quantity = (int) $part['quantity_used'];
    $unitCost = (float) $part['unit_cost'];
    return [
        'id' => (string) $part['id'],
        'inventoryId' => (string) $part['inventory_id'],
        'name' => (string) $part['part_name'],
        'sku' => (string) ($part['sku'] ?? ''),
        'quantity' => $quantity,
        'unitCost' => $unitCost,
        'lineTotal' => $quantity * $unitCost,
        'createdAt' => (string) $part['created_at'],
    ];
}, $partsStmt->fetchAll(PDO::FETCH_ASSOC));

$notesStmt = $pdo->prepare('
    SELECT n.note_id, n.note_type, n.note, n.is_customer_visible, n.created_at, u.full_name AS author_name
    FROM job_notes n
    LEFT JOIN users u
        ON u.user_id = n.user_id
       AND u.tenant_id = n.tenant_id
    WHERE n.tenant_id = :tid
      AND n.job_id = :jid
    ORDER BY n.created_at DESC, n.note_id DESC
');
$notesStmt->execute(['tid' => $tid, 'jid' => $jobId]);
$item['notes'] = array_map(static function (array $note): array {
    return [
        'id' => (string) $note['note_id'],
        'type' => (string) $note['note_type'],
        'note' => (string) $note['note'],
        'isCustomerVisible' => (int) $note['is_customer_visible'] === 1,
        'authorName' => (string) ($note['author_name'] ?? ''),
        'createdAt' => (string) $note['created_at'],
    ];
}, $notesStmt->fetchAll(PDO::FETCH_ASSOC));

api_json(['ok' => true, 'item' => $item]);
