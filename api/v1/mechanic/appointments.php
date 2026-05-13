<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/bearer.php';
require_once __DIR__ . '/../includes/tenant_features_api.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    api_error('method_not_allowed', 'Use GET.', 405);
}

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'mechanic') {
    api_error('forbidden', 'Mechanic role required.', 403);
}

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'appointments')) {
    api_error('feature_disabled', 'Appointments are not enabled for this tenant.', 403);
}

$stmt = $pdo->prepare('
    SELECT
        a.appointment_id,
        a.appointment_date,
        a.status,
        a.concern,
        c.name AS customer_name,
        c.contact AS customer_contact,
        v.make,
        v.model,
        v.year_model,
        v.plate
    FROM appointments a
    INNER JOIN customers c
        ON c.customer_id = a.customer_id
       AND c.tenant_id = a.tenant_id
    INNER JOIN vehicles v
        ON v.vehicle_id = a.vehicle_id
       AND v.tenant_id = a.tenant_id
    LEFT JOIN jobs j
        ON j.appointment_id = a.appointment_id
       AND j.tenant_id = a.tenant_id
    WHERE a.tenant_id = :tid
      AND (a.mechanic_id = :mid OR j.mechanic_id = :mid)
    ORDER BY a.appointment_date ASC, a.appointment_id ASC
');
$stmt->execute(['tid' => $ctx['tenant_id'], 'mid' => $ctx['user_id']]);

$items = array_map(static function (array $row): array {
    $year = 0;
    $ym = (string) ($row['year_model'] ?? '');
    if (preg_match('/^(\d{4})/', $ym, $m)) {
        $year = (int) $m[1];
    } elseif (ctype_digit($ym)) {
        $year = (int) $ym;
    }

    return [
        'id' => (string) $row['appointment_id'],
        'appointmentDate' => (string) ($row['appointment_date'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'concern' => (string) ($row['concern'] ?? ''),
        'customerName' => (string) ($row['customer_name'] ?? ''),
        'customerContact' => (string) ($row['customer_contact'] ?? ''),
        'vehicle' => [
            'make' => (string) ($row['make'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'plate' => (string) ($row['plate'] ?? ''),
            'year' => $year,
        ],
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

api_json(['ok' => true, 'items' => $items]);
