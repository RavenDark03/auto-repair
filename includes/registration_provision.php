<?php
declare(strict_types=1);

/**
 * Create shell tenant + admin user after super admin approval so the owner can log in and complete payment.
 */

function mechanix_registration_username_base(string $preferred, string $email, string $businessName): string
{
    $base = strtolower(trim($preferred));
    if ($base === '') {
        $base = strtolower(trim(explode('@', $email)[0] ?? ''));
    }
    if ($base === '') {
        $base = strtolower(trim($businessName));
    }
    $base = preg_replace('/[^a-z0-9]+/', '', $base) ?? '';
    if ($base === '') {
        $base = 'admin';
    }

    return substr($base, 0, 30);
}

/**
 * @throws RuntimeException
 */
function mechanix_provision_registration_tenant(PDO $pdo, int $registrationId): void
{
    $stmt = $pdo->prepare("
        SELECT
            tr.registration_id,
            tr.business_name,
            tr.owner_full_name,
            tr.email,
            tr.preferred_username,
            tr.password_hash,
            tr.registration_status,
            tr.provisioned_tenant_id
        FROM tenant_registrations tr
        WHERE tr.registration_id = :registration_id
        LIMIT 1
    ");
    $stmt->execute(['registration_id' => $registrationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('Registration not found for provisioning.');
    }

    if (!empty($row['provisioned_tenant_id'])) {
        return;
    }

    if (($row['registration_status'] ?? '') !== 'approved') {
        return;
    }

    $passwordHash = (string) ($row['password_hash'] ?? '');
    if ($passwordHash === '') {
        throw new RuntimeException(
            'This registration has no owner password on file. Ask the applicant to re-submit with a password, or reject the application.'
        );
    }

    $pdo->prepare("
        INSERT INTO tenants (business_name, status)
        VALUES (:business_name, 'pending_payment')
    ")->execute(['business_name' => $row['business_name']]);
    $tenantId = (int) $pdo->lastInsertId();

    $usernameBase = mechanix_registration_username_base(
        (string) ($row['preferred_username'] ?? ''),
        (string) ($row['email'] ?? ''),
        (string) ($row['business_name'] ?? '')
    );
    $candidateUsername = $usernameBase;
    $usernameSuffix = 1;

    $usernameCheckStmt = $pdo->prepare("
        SELECT user_id
        FROM users
        WHERE username = :username
        LIMIT 1
    ");

    do {
        $usernameCheckStmt->execute(['username' => $candidateUsername]);
        $exists = $usernameCheckStmt->fetchColumn();

        if ($exists) {
            $usernameSuffix++;
            $candidateUsername = substr($usernameBase, 0, 26) . $usernameSuffix;
        }
    } while ($exists);

    $pdo->prepare("
        INSERT INTO users (tenant_id, full_name, username, password_hash, must_change_password, role, status)
        VALUES (:tenant_id, :full_name, :username, :password_hash, 0, 'admin', 'active')
    ")->execute([
        'tenant_id' => $tenantId,
        'full_name' => $row['owner_full_name'],
        'username' => $candidateUsername,
        'password_hash' => $passwordHash,
    ]);

    $pdo->prepare("
        UPDATE tenant_registrations
        SET provisioned_tenant_id = :tenant_id
        WHERE registration_id = :registration_id
    ")->execute([
        'tenant_id' => $tenantId,
        'registration_id' => $registrationId,
    ]);
}
