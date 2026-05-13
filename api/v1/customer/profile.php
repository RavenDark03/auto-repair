<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/bearer.php';
require_once __DIR__ . '/../includes/customer_context.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    api_error('method_not_allowed', 'Use GET or POST.', 405);
}

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'customer') {
    api_error('forbidden', 'Customer role required.', 403);
}

$cid = api_resolve_customer_id($pdo, $ctx['tenant_id'], $ctx['username'], $ctx['user_id']);

if ($method === 'POST') {
    if ($cid === null) {
        api_error('customer_profile_missing', 'No customer record linked to this login.', 403);
    }

    $body = api_input_json();
    $name = trim((string) ($body['displayName'] ?? $body['name'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $phone = trim((string) ($body['phone'] ?? $body['contact'] ?? ''));
    $address = trim((string) ($body['address'] ?? ''));

    if ($name === '') {
        api_error('validation_error', 'displayName is required.', 422);
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('validation_error', 'email must be valid.', 422);
    }

    $upd = $pdo->prepare('
        UPDATE customers
        SET name = :name,
            email = :email,
            contact = :contact,
            address = :address
        WHERE tenant_id = :t
          AND customer_id = :c
    ');
    $upd->execute([
        'name' => $name,
        'email' => $email !== '' ? $email : null,
        'contact' => $phone !== '' ? $phone : null,
        'address' => $address !== '' ? $address : null,
        't' => $ctx['tenant_id'],
        'c' => $cid,
    ]);

    $userUpd = $pdo->prepare('
        UPDATE users
        SET full_name = :name
        WHERE tenant_id = :t
          AND user_id = :u
    ');
    $userUpd->execute(['name' => $name, 't' => $ctx['tenant_id'], 'u' => $ctx['user_id']]);
}

$display = (string) ($ctx['username'] ?? '');
$email = '';
$phone = '';
$address = '';

if ($cid !== null) {
    $st = $pdo->prepare('SELECT name, email, contact, address FROM customers WHERE tenant_id = :t AND customer_id = :c LIMIT 1');
    $st->execute(['t' => $ctx['tenant_id'], 'c' => $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if ($c) {
        $display = (string) ($c['name'] ?? $display);
        $email = (string) ($c['email'] ?? '');
        $phone = (string) ($c['contact'] ?? '');
        $address = (string) ($c['address'] ?? '');
    }
}

api_json([
    'ok' => true,
    'item' => [
        'displayName' => $display,
        'email' => $email !== '' ? $email : (string) $ctx['username'],
        'phone' => $phone,
        'address' => $address,
    ],
]);
