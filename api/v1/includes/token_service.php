<?php
declare(strict_types=1);

function api_token_hash(string $plain): string {
    return hash('sha256', $plain);
}

/** @return array{access_token: string, refresh_token: string, expires_in: int} */
function api_issue_tokens(PDO $pdo, int $userId, int $tenantId): array {
    $access = bin2hex(random_bytes(32));
    $refresh = bin2hex(random_bytes(32));
    $accessHash = api_token_hash($access);
    $refreshHash = api_token_hash($refresh);

    $accessTtl = 3600;
    $refreshTtl = 86400 * 30;

    $upd = $pdo->prepare('UPDATE mobile_api_tokens SET revoked = 1 WHERE user_id = :uid');
    $upd->execute(['uid' => $userId]);

    $accessExp = date('Y-m-d H:i:s', time() + $accessTtl);
    $refreshExp = date('Y-m-d H:i:s', time() + $refreshTtl);

    $ins = $pdo->prepare('
        INSERT INTO mobile_api_tokens
            (user_id, tenant_id, access_token_hash, refresh_token_hash, access_expires_at, refresh_expires_at)
        VALUES
            (:user_id, :tenant_id, :ah, :rh, :ae, :re)
    ');
    $ins->execute([
        'user_id' => $userId,
        'tenant_id' => $tenantId,
        'ah' => $accessHash,
        'rh' => $refreshHash,
        'ae' => $accessExp,
        're' => $refreshExp,
    ]);

    return [
        'access_token' => $access,
        'refresh_token' => $refresh,
        'expires_in' => $accessTtl,
    ];
}

/** @return array{user_id: int, tenant_id: int, role: string, username: string}|null */
function api_validate_access_token(PDO $pdo, string $bearerPlain): ?array {
    if ($bearerPlain === '') {
        return null;
    }
    $hash = api_token_hash($bearerPlain);
    $sql = '
        SELECT t.user_id, t.tenant_id, u.role, u.username, u.status AS user_status, te.status AS tenant_status
        FROM mobile_api_tokens t
        INNER JOIN users u ON u.user_id = t.user_id
        INNER JOIN tenants te ON te.tenant_id = t.tenant_id
        WHERE t.access_token_hash = :h
          AND t.revoked = 0
          AND t.access_expires_at > NOW()
        LIMIT 1
    ';
    $st = $pdo->prepare($sql);
    $st->execute(['h' => $hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    if (($row['user_status'] ?? '') !== 'active' || ($row['tenant_status'] ?? '') !== 'active') {
        return null;
    }
    return [
        'user_id' => (int) $row['user_id'],
        'tenant_id' => (int) $row['tenant_id'],
        'role' => (string) $row['role'],
        'username' => (string) $row['username'],
    ];
}

/** @return array{access_token: string, refresh_token: string, expires_in: int}|null */
function api_refresh_tokens(PDO $pdo, string $refreshPlain): ?array {
    $hash = api_token_hash($refreshPlain);
    $sel = $pdo->prepare('
        SELECT token_id, user_id, tenant_id
        FROM mobile_api_tokens
        WHERE refresh_token_hash = :h AND revoked = 0 AND refresh_expires_at > NOW()
        LIMIT 1
    ');
    $sel->execute(['h' => $hash]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $userId = (int) $row['user_id'];
    $tenantId = (int) $row['tenant_id'];
    $rev = $pdo->prepare('UPDATE mobile_api_tokens SET revoked = 1 WHERE token_id = :id');
    $rev->execute(['id' => (int) $row['token_id']]);
    return api_issue_tokens($pdo, $userId, $tenantId);
}
