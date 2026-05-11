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

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'customer_module')) {
    api_error('feature_disabled', 'Customer module is not enabled for this tenant.', 403);
}

$cid = api_resolve_customer_id($pdo, $ctx['tenant_id'], $ctx['username']);
if ($cid === null) {
    api_json(['ok' => true, 'items' => []]);
}

$st = $pdo->prepare('
    SELECT vehicle_id, make, model, plate, year_model
    FROM vehicles
    WHERE tenant_id = :t AND customer_id = :c AND status = \'active\'
    ORDER BY vehicle_id DESC
');
$st->execute(['t' => $ctx['tenant_id'], 'c' => $cid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$items = [];
foreach ($rows as $r) {
    $year = 0;
    $ym = (string) ($r['year_model'] ?? '');
    if (preg_match('/^(\d{4})/', $ym, $m)) {
        $year = (int) $m[1];
    } elseif (ctype_digit($ym)) {
        $year = (int) $ym;
    }
    $items[] = [
        'id' => (string) $r['vehicle_id'],
        'make' => (string) ($r['make'] ?? ''),
        'model' => (string) ($r['model'] ?? ''),
        'plate' => (string) ($r['plate'] ?? ''),
        'year' => $year,
    ];
}

api_json(['ok' => true, 'items' => $items]);
