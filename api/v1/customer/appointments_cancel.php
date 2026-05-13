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
$id = (int) ($body['id'] ?? $body['appointmentId'] ?? 0);
$reason = trim($body['reason'] ?? $body['cancellation_reason'] ?? '');

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'customer') {
    api_error('forbidden', 'Customer role required.', 403);
}

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'appointments')) {
    api_error('feature_disabled', 'Appointments are not enabled for this tenant.', 403);
}

$cid = api_resolve_customer_id($pdo, $ctx['tenant_id'], $ctx['username'], $ctx['user_id']);
if ($cid === null || $id <= 0) {
    api_error('validation_error', 'Invalid request.', 422);
}

$sel = $pdo->prepare('
    SELECT appointment_id, status FROM appointments
    WHERE tenant_id = :t AND customer_id = :c AND appointment_id = :id
    LIMIT 1
');
$sel->execute(['t' => $ctx['tenant_id'], 'c' => $cid, 'id' => $id]);
$row = $sel->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    api_error('not_found', 'Appointment not found.', 404);
}
if (($row['status'] ?? '') !== 'pending') {
    api_error('not_pending', 'Only pending appointments can be cancelled.', 409);
}

$upd = $pdo->prepare('
    UPDATE appointments
    SET status = \'cancelled\', cancellation_reason = :reason, cancelled_at = NOW()
    WHERE appointment_id = :id AND tenant_id = :t AND customer_id = :c
');
$upd->execute(['id' => $id, 't' => $ctx['tenant_id'], 'c' => $cid, 'reason' => $reason]);

api_json(['ok' => true, 'message' => 'Appointment cancelled successfully.']);
