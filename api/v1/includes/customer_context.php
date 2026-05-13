<?php
declare(strict_types=1);

function api_resolve_customer_id(PDO $pdo, int $tenantId, string $username, ?int $userId = null): ?int {
    if ($userId !== null && $userId > 0) {
        $ust = $pdo->prepare('
            SELECT customer_id
            FROM users
            WHERE tenant_id = :t
              AND user_id = :u
              AND role = \'customer\'
              AND customer_id IS NOT NULL
            LIMIT 1
        ');
        $ust->execute(['t' => $tenantId, 'u' => $userId]);
        $linkedId = $ust->fetchColumn();

        if ($linkedId !== false) {
            return (int) $linkedId;
        }
    }

    $st = $pdo->prepare('
        SELECT customer_id
        FROM customers
        WHERE tenant_id = :t
          AND status = \'active\'
          AND (email = :u OR contact = :u)
        LIMIT 1
    ');
    $st->execute(['t' => $tenantId, 'u' => $username]);
    $id = $st->fetchColumn();
    return $id !== false ? (int) $id : null;
}
