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

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'invoicing')) {
    api_error('feature_disabled', 'Invoicing is not enabled for this tenant.', 403);
}

$cid = api_resolve_customer_id($pdo, $ctx['tenant_id'], $ctx['username'], $ctx['user_id']);
if ($cid === null) {
    api_json(['ok' => true, 'items' => []]);
}

$stmt = $pdo->prepare('
    SELECT i.invoice_id, i.invoice_no, i.job_id, i.parts_subtotal, i.labor_fee, i.inspection_fee,
           i.subtotal, i.tax_rate, i.tax_amount, i.total, i.amount_paid, i.status, i.due_date, i.created_at,
           v.make, v.model, v.plate
    FROM invoices i
    INNER JOIN jobs j ON j.job_id = i.job_id AND j.tenant_id = i.tenant_id
    INNER JOIN appointments a ON a.appointment_id = j.appointment_id AND a.tenant_id = j.tenant_id
    INNER JOIN vehicles v ON v.vehicle_id = a.vehicle_id AND v.tenant_id = a.tenant_id
    WHERE i.tenant_id = :tid
      AND a.customer_id = :cid
      AND i.status <> \'cancelled\'
    ORDER BY i.created_at DESC, i.invoice_id DESC
');
$stmt->execute(['tid' => $ctx['tenant_id'], 'cid' => $cid]);

$items = array_map(static function (array $row): array {
    $total = (float) ($row['total'] ?? 0);
    $paid = (float) ($row['amount_paid'] ?? 0);
    return [
        'id' => (string) $row['invoice_id'],
        'invoiceNo' => (string) ($row['invoice_no'] ?? ''),
        'jobId' => (string) $row['job_id'],
        'vehicle' => trim((string) ($row['make'] ?? '') . ' ' . (string) ($row['model'] ?? '')),
        'plate' => (string) ($row['plate'] ?? ''),
        'partsSubtotal' => (float) ($row['parts_subtotal'] ?? 0),
        'laborFee' => (float) ($row['labor_fee'] ?? 0),
        'inspectionFee' => (float) ($row['inspection_fee'] ?? 0),
        'subtotal' => (float) ($row['subtotal'] ?? 0),
        'taxRate' => (float) ($row['tax_rate'] ?? 0),
        'taxAmount' => (float) ($row['tax_amount'] ?? 0),
        'total' => $total,
        'amountPaid' => $paid,
        'balance' => max(0, $total - $paid),
        'status' => (string) ($row['status'] ?? ''),
        'dueDate' => (string) ($row['due_date'] ?? ''),
        'createdAt' => (string) ($row['created_at'] ?? ''),
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

api_json(['ok' => true, 'items' => $items]);
