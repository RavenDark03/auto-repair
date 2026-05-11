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

$map = api_get_tenant_feature_map($pdo, $ctx['tenant_id']);

$out = [
    'appointments' => !empty($map['appointments']),
    'jobs' => !empty($map['jobs']),
    'invoicing' => !empty($map['invoicing']),
    'payments' => !empty($map['payments']),
    'customerModule' => !empty($map['customer_module']),
    'mechanicModule' => !empty($map['mechanic_module']),
    'inventory' => !empty($map['inventory']),
];

api_json(['ok' => true, 'item' => $out]);
