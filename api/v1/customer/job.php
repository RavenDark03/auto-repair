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

$cid = api_resolve_customer_id($pdo, $ctx['tenant_id'], $ctx['username'], $ctx['user_id']);
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
$item = api_job_row_to_payload($row);

$notesStmt = $pdo->prepare('
    SELECT note_id, note, created_at
    FROM job_notes
    WHERE tenant_id = :tid
      AND job_id = :jid
      AND is_customer_visible = 1
    ORDER BY created_at DESC, note_id DESC
');
$notesStmt->execute(['tid' => $ctx['tenant_id'], 'jid' => $jobId]);
$item['notes'] = array_map(static function (array $note): array {
    return [
        'id' => (string) $note['note_id'],
        'note' => (string) $note['note'],
        'createdAt' => (string) $note['created_at'],
    ];
}, $notesStmt->fetchAll(PDO::FETCH_ASSOC));

$partsStmt = $pdo->prepare('
    SELECT i.part_name, i.sku, jpu.quantity_used, i.unit_cost
    FROM job_parts_used jpu
    INNER JOIN inventory i
        ON i.inventory_id = jpu.inventory_id
       AND i.tenant_id = jpu.tenant_id
    WHERE jpu.tenant_id = :tid
      AND jpu.job_id = :jid
    ORDER BY jpu.id ASC
');
$partsStmt->execute(['tid' => $ctx['tenant_id'], 'jid' => $jobId]);
$item['partsUsed'] = array_map(static function (array $part): array {
    $quantity = (int) $part['quantity_used'];
    $unitCost = (float) $part['unit_cost'];
    return [
        'name' => (string) $part['part_name'],
        'sku' => (string) ($part['sku'] ?? ''),
        'quantity' => $quantity,
        'unitCost' => $unitCost,
        'lineTotal' => $quantity * $unitCost,
    ];
}, $partsStmt->fetchAll(PDO::FETCH_ASSOC));

$invoiceStmt = $pdo->prepare('
    SELECT invoice_id, invoice_no, services_subtotal, parts_subtotal, labor_fee, inspection_fee, subtotal, tax_rate, tax_amount, total, amount_paid, status, due_date
    FROM invoices
    WHERE tenant_id = :tid
      AND job_id = :jid
      AND status <> \'cancelled\'
    LIMIT 1
');
$invoiceStmt->execute(['tid' => $ctx['tenant_id'], 'jid' => $jobId]);
$invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
$item['invoice'] = $invoice ? [
    'id' => (string) $invoice['invoice_id'],
    'invoiceNo' => (string) ($invoice['invoice_no'] ?? ''),
    'servicesSubtotal' => (float) ($invoice['services_subtotal'] ?? 0),
    'partsSubtotal' => (float) ($invoice['parts_subtotal'] ?? 0),
    'laborFee' => (float) ($invoice['labor_fee'] ?? 0),
    'inspectionFee' => (float) ($invoice['inspection_fee'] ?? 0),
    'subtotal' => (float) ($invoice['subtotal'] ?? 0),
    'taxRate' => (float) ($invoice['tax_rate'] ?? 0),
    'taxAmount' => (float) ($invoice['tax_amount'] ?? 0),
    'total' => (float) ($invoice['total'] ?? 0),
    'amountPaid' => (float) ($invoice['amount_paid'] ?? 0),
    'balance' => max(0, (float) ($invoice['total'] ?? 0) - (float) ($invoice['amount_paid'] ?? 0)),
    'status' => (string) ($invoice['status'] ?? ''),
    'dueDate' => (string) ($invoice['due_date'] ?? ''),
] : null;

api_json(['ok' => true, 'item' => $item]);
