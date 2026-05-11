<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/token_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_error('method_not_allowed', 'Use POST.', 405);
}

$body = api_input_json();
$refresh = (string) ($body['refresh_token'] ?? $body['refreshToken'] ?? '');
if ($refresh === '') {
    api_error('validation_error', 'refresh_token is required.', 422);
}

try {
    $pdo = Database::getInstance();
    $tokens = api_refresh_tokens($pdo, $refresh);
    if ($tokens === null) {
        api_error('invalid_refresh', 'Invalid or expired refresh token.', 401);
    }
    api_json([
        'ok' => true,
        'accessToken' => $tokens['access_token'],
        'refreshToken' => $tokens['refresh_token'],
        'expiresIn' => $tokens['expires_in'],
        'tokenType' => 'Bearer',
    ]);
} catch (PDOException $e) {
    api_error('server_error', 'Refresh failed.', 500);
}
