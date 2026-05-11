<?php
declare(strict_types=1);

function api_resolve_customer_id(PDO $pdo, int $tenantId, string $username): ?int {
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
