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

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'appointments')) {
    api_error('feature_disabled', 'Appointments are not enabled for this tenant.', 403);
}

$cid = api_resolve_customer_id($pdo, $ctx['tenant_id'], $ctx['username'], $ctx['user_id']);
if ($cid === null) {
    api_json(['ok' => true, 'items' => []]);
}

$st = $pdo->prepare('
    SELECT
        a.appointment_id,
        a.vehicle_id,
        a.appointment_date,
        a.status,
        a.concern,
        a.cancellation_reason,
        v.make,
        v.model,
        v.plate,
        v.year_model
    FROM appointments a
    INNER JOIN vehicles v
        ON v.vehicle_id = a.vehicle_id
       AND v.tenant_id = a.tenant_id
    WHERE a.tenant_id = :t AND a.customer_id = :c
    ORDER BY a.appointment_id DESC
');
$st->execute(['t' => $ctx['tenant_id'], 'c' => $cid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$items = [];
foreach ($rows as $r) {
    $ad = $r['appointment_date'] ?? null;
    $created = $ad
        ? (int) (strtotime((string) $ad) * 1000)
        : (int) (time() * 1000);
    $items[] = [
        'id' => (string) $r['appointment_id'],
        'vehicleId' => (string) $r['vehicle_id'],
        'vehicle' => trim((string) ($r['make'] ?? '') . ' ' . (string) ($r['model'] ?? '')),
        'plate' => (string) ($r['plate'] ?? ''),
        'requestedAtEpochMs' => $created,
        'appointmentDate' => (string) ($r['appointment_date'] ?? ''),
        'status' => api_appt_status_mobile((string) $r['status']),
        'concern' => (string) ($r['concern'] ?? ''),
        'cancellationReason' => (string) ($r['cancellation_reason'] ?? ''),
        'notes' => null,
    ];
}

api_json(['ok' => true, 'items' => $items]);
