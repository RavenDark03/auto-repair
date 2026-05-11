<?php
declare(strict_types=1);

require_once __DIR__ . '/token_service.php';

/** @return array{user_id: int, tenant_id: int, role: string, username: string} */
function api_require_bearer(PDO $pdo): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
        api_error('unauthorized', 'Missing or invalid Authorization header.', 401);
    }
    $ctx = api_validate_access_token($pdo, $m[1]);
    if ($ctx === null) {
        api_error('unauthorized', 'Invalid or expired access token.', 401);
    }
    return $ctx;
}
