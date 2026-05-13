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
$appointmentDate = trim($body['appointmentDate'] ?? $body['appointment_date'] ?? '');
$concern = trim($body['concern'] ?? '');

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'customer') {
    api_error('forbidden', 'Customer role required.', 403);
}

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'appointments')) {
    api_error('feature_disabled', 'Appointments are not enabled for this tenant.', 403);
}

$cid = api_resolve_customer_id($pdo, $ctx['tenant_id'], $ctx['username'], $ctx['user_id']);
if ($cid === null) {
    api_error('customer_profile_missing', 'No customer record linked to this login.', 403);
}

if ($vehicleId <= 0) {
    api_error('validation_error', 'vehicleId is required.', 422);
}

if ($appointmentDate === '') {
    api_error('validation_error', 'appointmentDate is required.', 422);
}

$dateTime = DateTime::createFromFormat('Y-m-d', $appointmentDate);
if (!$dateTime || $dateTime->format('Y-m-d') !== $appointmentDate) {
    api_error('validation_error', 'Invalid appointmentDate format. Use YYYY-MM-DD.', 422);
}

$today = new DateTime('today');
if ($dateTime < $today) {
    api_error('validation_error', 'Appointment date cannot be in the past.', 422);
}

$chk = $pdo->prepare('
    SELECT vehicle_id, make, model, plate, year_model
    FROM vehicles
    WHERE tenant_id = :t AND customer_id = :c AND vehicle_id = :v AND status = \'active\'
    LIMIT 1
');
$chk->execute(['t' => $ctx['tenant_id'], 'c' => $cid, 'v' => $vehicleId]);
$vehicle = $chk->fetch(PDO::FETCH_ASSOC);
if (!$vehicle) {
    api_error('validation_error', 'Vehicle not found for this customer.', 422);
}

$ins = $pdo->prepare('
    INSERT INTO appointments (tenant_id, customer_id, vehicle_id, appointment_date, concern, status)
    VALUES (:t, :c, :v, :ad, :concern, \'pending\')
');
$ins->execute([
    't' => $ctx['tenant_id'],
    'c' => $cid,
    'v' => $vehicleId,
    'ad' => $appointmentDate,
    'concern' => $concern !== '' ? $concern : null,
]);
$newId = (int) $pdo->lastInsertId();

$created = (int) (strtotime($appointmentDate) * 1000);
api_json([
    'ok' => true,
    'item' => [
        'id' => (string) $newId,
        'vehicleId' => (string) $vehicleId,
        'vehicle' => trim((string) ($vehicle['make'] ?? '') . ' ' . (string) ($vehicle['model'] ?? '')),
        'plate' => (string) ($vehicle['plate'] ?? ''),
        'requestedAtEpochMs' => $created,
        'appointmentDate' => $appointmentDate,
        'status' => 'Pending',
        'concern' => $concern,
        'notes' => null,
    ],
]);
