<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/bearer.php';
require_once __DIR__ . '/../includes/tenant_features_api.php';
require_once __DIR__ . '/../includes/customer_context.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_error('method_not_allowed', 'Use POST.', 405);
}

$body = api_input_json();
$invoiceId = (int) ($body['invoice_id'] ?? $body['invoiceId'] ?? 0);
$amountRaw = $body['amount'] ?? null;
$paymentMethod = trim((string) ($body['paymentMethod'] ?? $body['payment_method'] ?? 'mobile'));
$referenceNo = trim((string) ($body['referenceNo'] ?? $body['reference_no'] ?? ''));

if ($invoiceId <= 0) {
    api_error('validation_error', 'invoice_id is required.', 422);
}

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'customer') {
    api_error('forbidden', 'Customer role required.', 403);
}

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'invoicing') || !api_tenant_has_feature($pdo, $ctx['tenant_id'], 'payments')) {
    api_error('feature_disabled', 'Invoicing and payments are required.', 403);
}

$cid = api_resolve_customer_id($pdo, $ctx['tenant_id'], $ctx['username'], $ctx['user_id']);
if ($cid === null) {
    api_error('customer_profile_missing', 'No customer record linked to this login.', 403);
}

try {
    $pdo->beginTransaction();

    $invoiceStmt = $pdo->prepare('
        SELECT i.invoice_id, i.total, i.amount_paid, i.status
        FROM invoices i
        INNER JOIN jobs j ON j.job_id = i.job_id AND j.tenant_id = i.tenant_id
        INNER JOIN appointments a ON a.appointment_id = j.appointment_id AND a.tenant_id = j.tenant_id
        WHERE i.invoice_id = :iid
          AND i.tenant_id = :tid
          AND a.customer_id = :cid
        LIMIT 1
        FOR UPDATE
    ');
    $invoiceStmt->execute(['iid' => $invoiceId, 'tid' => $ctx['tenant_id'], 'cid' => $cid]);
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        $pdo->rollBack();
        api_error('not_found', 'Invoice not found.', 404);
    }

    if (!in_array((string) $invoice['status'], ['unpaid', 'partial'], true)) {
        $pdo->rollBack();
        api_error('not_payable', 'Only unpaid or partial invoices can be paid.', 409);
    }

    $remaining = max(0, (float) $invoice['total'] - (float) $invoice['amount_paid']);
    $amount = $amountRaw === null ? $remaining : (float) $amountRaw;

    if ($amount <= 0 || $amount - $remaining > 0.00001) {
        $pdo->rollBack();
        api_error('validation_error', 'Payment amount must be greater than zero and not exceed the remaining balance.', 422);
    }

    $paymentStmt = $pdo->prepare('
        INSERT INTO payments (tenant_id, invoice_id, amount, payment_method, reference_no, notes, payment_date)
        VALUES (:tid, :iid, :amount, :method, :reference, \'Customer mobile payment\', NOW())
    ');
    $paymentStmt->execute([
        'tid' => $ctx['tenant_id'],
        'iid' => $invoiceId,
        'amount' => number_format($amount, 2, '.', ''),
        'method' => $paymentMethod !== '' ? $paymentMethod : 'mobile',
        'reference' => $referenceNo !== '' ? $referenceNo : null,
    ]);

    $newPaid = (float) $invoice['amount_paid'] + $amount;
    $newStatus = $newPaid + 0.00001 >= (float) $invoice['total'] ? 'paid' : 'partial';

    $updateStmt = $pdo->prepare('
        UPDATE invoices
        SET amount_paid = :paid,
            status = :status
        WHERE invoice_id = :iid
          AND tenant_id = :tid
    ');
    $updateStmt->execute([
        'paid' => number_format($newPaid, 2, '.', ''),
        'status' => $newStatus,
        'iid' => $invoiceId,
        'tid' => $ctx['tenant_id'],
    ]);

    $pdo->commit();
    api_json(['ok' => true, 'status' => $newStatus, 'amountPaid' => $newPaid, 'balance' => max(0, (float) $invoice['total'] - $newPaid)], 201);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error('server_error', 'Payment could not be completed.', 500);
}
