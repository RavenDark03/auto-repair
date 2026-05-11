<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/bearer.php';
require_once __DIR__ . '/../includes/customer_context.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    api_error('method_not_allowed', 'Use GET.', 405);
}

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'customer') {
    api_error('forbidden', 'Customer role required.', 403);
}

$cid = api_resolve_customer_id($pdo, $ctx['tenant_id'], $ctx['username']);
$display = (string) ($ctx['username'] ?? '');
$email = '';
$phone = '';

if ($cid !== null) {
    $st = $pdo->prepare('SELECT name, email, contact FROM customers WHERE tenant_id = :t AND customer_id = :c LIMIT 1');
    $st->execute(['t' => $ctx['tenant_id'], 'c' => $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if ($c) {
        $display = (string) ($c['name'] ?? $display);
        $email = (string) ($c['email'] ?? '');
        $phone = (string) ($c['contact'] ?? '');
    }
}

api_json([
    'ok' => true,
    'item' => [
        'displayName' => $display,
        'email' => $email !== '' ? $email : (string) $ctx['username'],
        'phone' => $phone,
    ],
]);
