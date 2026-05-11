<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_error('method_not_allowed', 'Use POST.', 405);
}

$body = api_input_json();
$code = trim((string) ($body['invite_code'] ?? $body['code'] ?? ''));

if ($code === '') {
    api_error('validation_error', 'invite code is required.', 422);
}

api_json(['ok' => true, 'message' => 'Invite handling not implemented; code accepted.']);
