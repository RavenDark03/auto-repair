<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/super_admin_auth.php';
require_once __DIR__ . '/../../includes/db.php';

require_once __DIR__ . '/../../includes/superadmin_redirects.php';

requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mechanix_superadmin_non_post_redirect_url());
    exit;
}

$registrationId = (int) ($_POST['registration_id'] ?? 0);

if ($registrationId <= 0) {
    $_SESSION['super_admin_error'] = 'A valid paid registration is required before tenant conversion.';
    header('Location: ' . mechanix_superadmin_registration_redirect_url($registrationId));
    exit;
}

function buildUsernameBase($value) {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '', $value);

    if ($value === '') {
        return 'admin';
    }

    return substr($value, 0, 30);
}

function createTemporaryPassword($length = 12) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $maxIndex = strlen($alphabet) - 1;
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $maxIndex)];
    }

    return $password;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $registrationStmt = $pdo->prepare("
        SELECT
            tr.registration_id,
            tr.business_name,
            tr.owner_full_name,
            tr.email,
            tr.preferred_username,
            tr.billing_cycle,
            tr.registration_status,
            tr.provisioned_tenant_id,
            tr.bir_tin,
            tr.owner_id_number,
            tr.owner_id_document_path,
            tr.password_hash,
            sp.plan_id,
            sp.plan_name
        FROM tenant_registrations tr
        INNER JOIN subscription_plans sp ON sp.plan_id = tr.selected_plan_id
        WHERE tr.registration_id = :registration_id
        LIMIT 1
    ");
    $registrationStmt->execute(['registration_id' => $registrationId]);
    $registration = $registrationStmt->fetch();

    if (!$registration) {
        throw new RuntimeException('The selected registration could not be found.');
    }

    if ($registration['registration_status'] === 'converted') {
        throw new RuntimeException('This registration has already been converted.');
    }

    if ($registration['registration_status'] !== 'paid') {
        throw new RuntimeException('Only paid registrations can be converted into live tenants.');
    }

    $billingStmt = $pdo->prepare("
        SELECT billing_request_id, billing_status, paid_at
        FROM billing_requests
        WHERE registration_id = :registration_id
        ORDER BY billing_request_id DESC
        LIMIT 1
    ");
    $billingStmt->execute(['registration_id' => $registrationId]);
    $billing = $billingStmt->fetch();

    if (!$billing || $billing['billing_status'] !== 'paid') {
        throw new RuntimeException('The latest billing request is not marked as paid yet.');
    }

    $provisionedTenantId = (int) ($registration['provisioned_tenant_id'] ?? 0);
    $usedPreProvision = $provisionedTenantId > 0;
    $temporaryPassword = null;
    $candidateUsername = '';

    if ($usedPreProvision) {
        $tenantId = $provisionedTenantId;
        $tenantRowStmt = $pdo->prepare("
            SELECT tenant_id, status
            FROM tenants
            WHERE tenant_id = :tenant_id
            LIMIT 1
        ");
        $tenantRowStmt->execute(['tenant_id' => $tenantId]);
        $tenantRow = $tenantRowStmt->fetch(PDO::FETCH_ASSOC);

        if (!$tenantRow) {
            throw new RuntimeException('Provisioned tenant record is missing.');
        }

        if (($tenantRow['status'] ?? '') !== 'pending_payment') {
            throw new RuntimeException('Provisioned tenant is not in the pending payment state.');
        }

        $identityUpdate = $pdo->prepare("
            UPDATE tenants
            SET status = 'active',
                bir_tin = :bir_tin,
                owner_id_number = :owner_id_number,
                owner_id_document_path = :owner_id_document_path
            WHERE tenant_id = :tenant_id
        ");
        $identityUpdate->execute([
            'tenant_id' => $tenantId,
            'bir_tin' => $registration['bir_tin'] ?? null,
            'owner_id_number' => $registration['owner_id_number'] ?? null,
            'owner_id_document_path' => $registration['owner_id_document_path'] ?? null,
        ]);

        $adminUserStmt = $pdo->prepare("
            SELECT username
            FROM users
            WHERE tenant_id = :tenant_id
              AND role = 'admin'
            ORDER BY user_id ASC
            LIMIT 1
        ");
        $adminUserStmt->execute(['tenant_id' => $tenantId]);
        $candidateUsername = (string) ($adminUserStmt->fetchColumn() ?: 'admin');
    } else {
        $tenantInsertStmt = $pdo->prepare("
            INSERT INTO tenants (business_name, status, bir_tin, owner_id_number, owner_id_document_path)
            VALUES (:business_name, 'active', :bir_tin, :owner_id_number, :owner_id_document_path)
        ");
        $tenantInsertStmt->execute([
            'business_name' => $registration['business_name'],
            'bir_tin' => $registration['bir_tin'] ?? null,
            'owner_id_number' => $registration['owner_id_number'] ?? null,
            'owner_id_document_path' => $registration['owner_id_document_path'] ?? null,
        ]);
        $tenantId = (int) $pdo->lastInsertId();
    }

    $startDate = !empty($billing['paid_at']) ? date('Y-m-d', strtotime((string) $billing['paid_at'])) : date('Y-m-d');
    $endDate = $registration['billing_cycle'] === 'yearly'
        ? date('Y-m-d', strtotime($startDate . ' +1 year'))
        : date('Y-m-d', strtotime($startDate . ' +1 month'));

    $subscriptionInsertStmt = $pdo->prepare("
        INSERT INTO subscriptions (tenant_id, plan, start_date, end_date, status)
        VALUES (:tenant_id, :plan, :start_date, :end_date, 'active')
    ");
    $subscriptionInsertStmt->execute([
        'tenant_id' => $tenantId,
        'plan' => $registration['plan_name'],
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    $featureIds = [];
    $featureCatalog = [];

    $planFeatureStmt = $pdo->prepare("
        SELECT
            f.feature_id,
            f.feature_name,
            f.description
        FROM plan_features
        INNER JOIN features f ON f.feature_id = plan_features.feature_id
        WHERE plan_id = :plan_id
          AND is_included = 1
    ");
    $planFeatureStmt->execute(['plan_id' => (int) $registration['plan_id']]);
    foreach ($planFeatureStmt->fetchAll() as $row) {
        $featureId = (int) $row['feature_id'];
        $featureIds[$featureId] = true;
        $featureCatalog[$featureId] = [
            'feature_name' => (string) $row['feature_name'],
            'description' => (string) ($row['description'] ?? ''),
            'source' => 'plan',
        ];
    }

    $addonFeatureStmt = $pdo->prepare("
        SELECT
            f.feature_id,
            f.feature_name,
            f.description
        FROM registration_requested_features
        INNER JOIN features f ON f.feature_id = registration_requested_features.feature_id
        WHERE registration_id = :registration_id
          AND is_requested = 1
    ");
    $addonFeatureStmt->execute(['registration_id' => $registrationId]);
    foreach ($addonFeatureStmt->fetchAll() as $row) {
        $featureId = (int) $row['feature_id'];
        $featureIds[$featureId] = true;

        if (isset($featureCatalog[$featureId])) {
            $featureCatalog[$featureId]['source'] = 'plan + add-on';
            continue;
        }

        $featureCatalog[$featureId] = [
            'feature_name' => (string) $row['feature_name'],
            'description' => (string) ($row['description'] ?? ''),
            'source' => 'add-on',
        ];
    }

    if (!empty($featureIds)) {
        $tenantFeatureStmt = $pdo->prepare("
            INSERT INTO tenant_features (tenant_id, feature_id, is_enabled)
            VALUES (:tenant_id, :feature_id, 1)
        ");

        foreach (array_keys($featureIds) as $featureId) {
            $tenantFeatureStmt->execute([
                'tenant_id' => $tenantId,
                'feature_id' => (int) $featureId,
            ]);
        }
    }

    if (!$usedPreProvision) {
        $usernameBase = buildUsernameBase($registration['preferred_username'] ?: 'admin');
        $candidateUsername = $usernameBase;
        $usernameSuffix = 1;

        $usernameCheckStmt = $pdo->prepare("
            SELECT user_id
            FROM users
            WHERE tenant_id = :tenant_id
              AND username = :username
            LIMIT 1
        ");

        do {
            $usernameCheckStmt->execute([
                'tenant_id' => $tenantId,
                'username' => $candidateUsername,
            ]);
            $exists = $usernameCheckStmt->fetchColumn();

            if ($exists) {
                $usernameSuffix++;
                $candidateUsername = substr($usernameBase, 0, 26) . $usernameSuffix;
            }
        } while ($exists);

        $registrationPasswordHash = trim((string) ($registration['password_hash'] ?? ''));
        if ($registrationPasswordHash !== '') {
            $passwordHash = $registrationPasswordHash;
            $temporaryPassword = null;
            $mustChangePassword = 0;
        } else {
            $temporaryPassword = createTemporaryPassword();
            $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
            $mustChangePassword = 1;
        }

        $userInsertStmt = $pdo->prepare("
            INSERT INTO users (tenant_id, full_name, username, password_hash, must_change_password, role, status)
            VALUES (:tenant_id, :full_name, :username, :password_hash, :must_change_password, 'admin', 'active')
        ");
        $userInsertStmt->execute([
            'tenant_id' => $tenantId,
            'full_name' => $registration['owner_full_name'],
            'username' => $candidateUsername,
            'password_hash' => $passwordHash,
            'must_change_password' => $mustChangePassword,
        ]);
    }

    $registrationUpdateStmt = $pdo->prepare("
        UPDATE tenant_registrations
        SET registration_status = 'converted',
            converted_tenant_id = :converted_tenant_id,
            reviewed_by_super_admin_id = :reviewed_by_super_admin_id,
            reviewed_at = NOW(),
            notes = CONCAT(
                COALESCE(notes, ''),
                CASE
                    WHEN notes IS NULL OR notes = '' THEN ''
                    ELSE '\n'
                END,
                :conversion_note
            )
        WHERE registration_id = :registration_id
    ");
    $registrationUpdateStmt->execute([
        'converted_tenant_id' => $tenantId,
        'reviewed_by_super_admin_id' => (int) $_SESSION['super_admin_id'],
        'conversion_note' => $usedPreProvision
            ? ('Activated pre-provisioned tenant #' . $tenantId . ' (admin "' . $candidateUsername . '").')
            : ('Converted to tenant #' . $tenantId . ' with admin username "' . $candidateUsername . '".'),
        'registration_id' => $registrationId,
    ]);

    $emailBody = $temporaryPassword !== null
        ? 'Your tenant account is ready. Username: ' . $candidateUsername . '. Temporary password: ' . $temporaryPassword . '.'
        : 'Your MECHANIX subscription is now active. Admin username: ' . $candidateUsername . '. Sign in with the password you chose during registration.';

    $emailLogStmt = $pdo->prepare("
        INSERT INTO email_logs (
            registration_id,
            recipient_email,
            subject,
            body,
            email_type,
            send_status
        ) VALUES (
            :registration_id,
            :recipient_email,
            'MECHANIX tenant account created',
            :body,
            'approval_notice',
            'pending'
        )
    ");
    $emailLogStmt->execute([
        'registration_id' => $registrationId,
        'recipient_email' => $registration['email'],
        'body' => $emailBody,
    ]);

    $pdo->commit();

    $onboarding = [
        'registration_id' => $registrationId,
        'tenant_id' => $tenantId,
        'business_name' => $registration['business_name'],
        'plan_name' => $registration['plan_name'],
        'billing_cycle' => $registration['billing_cycle'],
        'admin_name' => $registration['owner_full_name'],
        'username' => $candidateUsername,
        'subscription_start' => $startDate,
        'subscription_end' => $endDate,
        'assigned_features' => array_values(array_map(
            static function (array $feature): array {
                return [
                    'feature_name' => $feature['feature_name'],
                    'description' => $feature['description'],
                    'source' => $feature['source'],
                ];
            },
            $featureCatalog
        )),
    ];
    if ($temporaryPassword !== null) {
        $onboarding['temporary_password'] = $temporaryPassword;
    }
    $_SESSION['tenant_onboarding'] = $onboarding;

    $_SESSION['super_admin_success'] = 'Tenant created for ' . $registration['business_name'] . '.';
    header('Location: ' . mechanix_superadmin_registration_redirect_url($registrationId, $tenantId));
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['super_admin_error'] = 'Tenant conversion failed: ' . $e->getMessage();
    header('Location: ' . mechanix_superadmin_registration_redirect_url($registrationId));
    exit;
}
?>
