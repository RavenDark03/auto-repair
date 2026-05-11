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
$vehicleId = (int) ($body['vehicleId'] ?? $body['vehicle_id'] ?? 0);

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'customer') {
    api_error('forbidden', 'Customer role required.', 403);
}

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'appointments')) {
    api_error('feature_disabled', 'Appointments are not enabled for this tenant.', 403);
}

$cid = api_resolve_customer_id($pdo, $ctx['tenant_id'], $ctx['username']);
if ($cid === null) {
    api_error('customer_profile_missing', 'No customer record linked to this login.', 403);
}

if ($vehicleId <= 0) {
    api_error('validation_error', 'vehicleId is required.', 422);
}

$chk = $pdo->prepare('
    SELECT vehicle_id FROM vehicles
    WHERE tenant_id = :t AND customer_id = :c AND vehicle_id = :v AND status = \'active\'
    LIMIT 1
');
$chk->execute(['t' => $ctx['tenant_id'], 'c' => $cid, 'v' => $vehicleId]);
if (!$chk->fetch()) {
    api_error('validation_error', 'Vehicle not found for this customer.', 422);
}

$ins = $pdo->prepare('
    INSERT INTO appointments (tenant_id, customer_id, vehicle_id, appointment_date, status)
    VALUES (:t, :c, :v, CURDATE(), \'pending\')
');
$ins->execute(['t' => $ctx['tenant_id'], 'c' => $cid, 'v' => $vehicleId]);
$newId = (int) $pdo->lastInsertId();

$created = (int) (time() * 1000);
api_json([
    'ok' => true,
    'item' => [
        'id' => (string) $newId,
        'vehicleId' => (string) $vehicleId,
        'requestedAtEpochMs' => $created,
        'status' => 'Pending',
        'notes' => null,
    ],
]);
