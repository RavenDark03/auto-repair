<?php
declare(strict_types=1);

/**
 * Completes tenant activation after a PayMongo checkout session is paid.
 * Used by the webhook handler and by page loads (race with webhook latency).
 */

function mechanix_paymongo_checkout_attrs_indicate_payment(array $attrs): bool
{
    if (!empty($attrs['paid_at'])) {
        return true;
    }
    $payments = $attrs['payments'] ?? [];
    if (is_array($payments) && $payments !== []) {
        return true;
    }
    return strtolower((string) ($attrs['status'] ?? '')) === 'paid';
}

function mechanix_paymongo_retrieve_checkout_session(string $sessionId): ?array
{
    if ($sessionId === '' || PAYMONGO_SECRET_KEY === '') {
        return null;
    }

    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($sessionId));
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $decoded = json_decode($response, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Core conversion body (billing row must exist for this checkout session ID).
 *
 * @return array{outcome:string,tenant_id?:int,registration?:array<string,mixed>,start_date?:string,end_date?:string}
 */
function mechanix_apply_checkout_session_payment_paid(PDO $pdo, string $checkoutSessionId, array $checkoutSessionAttrs): array
{
    $payments = $checkoutSessionAttrs['payments'] ?? [];

    $paymentIntent = null;
    if (isset($checkoutSessionAttrs['payment_intent'])) {
        $pi = $checkoutSessionAttrs['payment_intent'];
        if (is_string($pi)) {
            $paymentIntent = $pi;
        } elseif (is_array($pi)) {
            $paymentIntent = $pi['id'] ?? null;
        }
    }

    $paidAtUnix = $checkoutSessionAttrs['paid_at'] ?? null;
    $paymongoStatus = $checkoutSessionAttrs['status'] ?? 'paid';

    $paymentId = null;
    $paymentReference = null;

    if (!empty($payments[0]['id'])) {
        $paymentId = $payments[0]['id'];
        $paymentReference = $payments[0]['attributes']['external_reference_number'] ?? null;
    }

    $billingStmt = $pdo->prepare("
        SELECT billing_request_id, registration_id
        FROM billing_requests
        WHERE paymongo_checkout_session_id = :paymongo_checkout_session_id
        LIMIT 1
    ");
    $billingStmt->execute(['paymongo_checkout_session_id' => $checkoutSessionId]);
    $billing = $billingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$billing) {
        throw new RuntimeException('Matching billing request not found for checkout session.');
    }

    $paidAt = null;
    if (!empty($paidAtUnix) && ctype_digit((string) $paidAtUnix)) {
        $paidAt = date('Y-m-d H:i:s', (int) $paidAtUnix);
    }

    $pdo->prepare("
        UPDATE billing_requests
        SET billing_status              = 'paid',
            payment_reference           = COALESCE(:payment_reference, payment_reference),
            paymongo_payment_intent_id  = COALESCE(:paymongo_payment_intent_id, paymongo_payment_intent_id),
            paymongo_payment_id         = COALESCE(:paymongo_payment_id, paymongo_payment_id),
            paymongo_status             = :paymongo_status,
            paid_at                     = COALESCE(:paid_at, paid_at),
            updated_at                  = CURRENT_TIMESTAMP
        WHERE billing_request_id = :billing_request_id
    ")->execute([
        'payment_reference'          => $paymentReference,
        'paymongo_payment_intent_id' => is_string($paymentIntent) ? $paymentIntent : null,
        'paymongo_payment_id'        => is_string($paymentId) ? $paymentId : null,
        'paymongo_status'            => $paymongoStatus,
        'paid_at'                    => $paidAt,
        'billing_request_id'         => (int) $billing['billing_request_id'],
    ]);

    $registrationStmt = $pdo->prepare("
        SELECT
            tr.registration_id,
            tr.business_name,
            tr.owner_full_name,
            tr.email,
            tr.billing_cycle,
            tr.provisioned_tenant_id,
            tr.registration_status,
            tr.bir_tin,
            tr.owner_id_number,
            tr.owner_id_document_path,
            sp.plan_id,
            sp.plan_name
        FROM tenant_registrations tr
        INNER JOIN subscription_plans sp ON sp.plan_id = tr.selected_plan_id
        WHERE tr.registration_id = :registration_id
        LIMIT 1
        FOR UPDATE
    ");
    $registrationStmt->execute(['registration_id' => (int) $billing['registration_id']]);
    $registration = $registrationStmt->fetch(PDO::FETCH_ASSOC);

    if (!$registration) {
        throw new RuntimeException('Registration not found for webhook conversion.');
    }

    if ($registration['registration_status'] === 'converted') {
        return ['outcome' => 'already_converted'];
    }

    $pdo->prepare("
        UPDATE tenant_registrations
        SET registration_status = 'paid',
            reviewed_at         = NOW()
        WHERE registration_id = :registration_id
    ")->execute(['registration_id' => (int) $registration['registration_id']]);

    $provisionedTenantId = (int) ($registration['provisioned_tenant_id'] ?? 0);
    $tenantId = 0;

    if ($provisionedTenantId > 0) {
        $tenantId = $provisionedTenantId;

        $pdo->prepare("
            UPDATE tenants
            SET status               = 'active',
                bir_tin              = :bir_tin,
                owner_id_number      = :owner_id_number,
                owner_id_document_path = :owner_id_document_path
            WHERE tenant_id = :tenant_id
        ")->execute([
            'tenant_id'              => $tenantId,
            'bir_tin'                => $registration['bir_tin'] ?? null,
            'owner_id_number'        => $registration['owner_id_number'] ?? null,
            'owner_id_document_path' => $registration['owner_id_document_path'] ?? null,
        ]);
    } else {
        $pdo->prepare("
            INSERT INTO tenants (business_name, status, bir_tin, owner_id_number, owner_id_document_path)
            VALUES (:business_name, 'active', :bir_tin, :owner_id_number, :owner_id_document_path)
        ")->execute([
            'business_name'          => $registration['business_name'],
            'bir_tin'                => $registration['bir_tin'] ?? null,
            'owner_id_number'        => $registration['owner_id_number'] ?? null,
            'owner_id_document_path' => $registration['owner_id_document_path'] ?? null,
        ]);
        $tenantId = (int) $pdo->lastInsertId();
    }

    $startDate = !empty($paidAt) ? date('Y-m-d', strtotime($paidAt)) : date('Y-m-d');
    $endDate   = $registration['billing_cycle'] === 'yearly'
        ? date('Y-m-d', strtotime($startDate . ' +1 year'))
        : date('Y-m-d', strtotime($startDate . ' +1 month'));

    $pdo->prepare("
        INSERT INTO subscriptions (tenant_id, plan, start_date, end_date, status)
        VALUES (:tenant_id, :plan, :start_date, :end_date, 'active')
    ")->execute([
        'tenant_id'  => $tenantId,
        'plan'       => $registration['plan_name'],
        'start_date' => $startDate,
        'end_date'   => $endDate,
    ]);

    $planFeatureStmt = $pdo->prepare("
        SELECT feature_id FROM plan_features
        WHERE plan_id = :plan_id AND is_included = 1
    ");
    $planFeatureStmt->execute(['plan_id' => (int) $registration['plan_id']]);
    $featureIds = array_map('intval', $planFeatureStmt->fetchAll(PDO::FETCH_COLUMN));

    $addonStmt = $pdo->prepare("
        SELECT feature_id FROM registration_requested_features
        WHERE registration_id = :registration_id AND is_requested = 1
    ");
    $addonStmt->execute(['registration_id' => (int) $registration['registration_id']]);
    foreach ($addonStmt->fetchAll(PDO::FETCH_COLUMN) as $fid) {
        $featureIds[] = (int) $fid;
    }

    $featureIds = array_values(array_unique($featureIds));

    if (!empty($featureIds)) {
        $tenantFeatureStmt = $pdo->prepare("
            INSERT IGNORE INTO tenant_features (tenant_id, feature_id, is_enabled)
            VALUES (:tenant_id, :feature_id, 1)
        ");
        foreach ($featureIds as $fid) {
            $tenantFeatureStmt->execute(['tenant_id' => $tenantId, 'feature_id' => $fid]);
        }
    }

    $pdo->prepare("
        UPDATE tenant_registrations
        SET registration_status  = 'converted',
            converted_tenant_id  = :converted_tenant_id,
            reviewed_at          = NOW()
        WHERE registration_id = :registration_id
    ")->execute([
        'converted_tenant_id' => $tenantId,
        'registration_id'     => (int) $registration['registration_id'],
    ]);

    return [
        'outcome'      => 'processed',
        'tenant_id'    => $tenantId,
        'registration' => $registration,
        'start_date'   => $startDate,
        'end_date'     => $endDate,
    ];
}

function mechanix_send_paymongo_activation_email(PDO $pdo, array $registration, string $startDate, string $endDate): void
{
    require_once __DIR__ . '/email_helper.php';
    $loginUrl = (defined('APP_URL') ? rtrim((string) APP_URL, '/') : '')
        . (defined('BASE_URL') ? (string) BASE_URL : '')
        . '/login.php';

    $activatedHtml = mechanix_email_html('
        <h2>🚀 Your Subscription is Active!</h2>
        <p>Hi <strong>' . htmlspecialchars((string) $registration['owner_full_name'], ENT_QUOTES, 'UTF-8') . '</strong>,</p>
        <p>Payment confirmed! Your MECHANIX workspace for <strong>' . htmlspecialchars((string) $registration['business_name'], ENT_QUOTES, 'UTF-8') . '</strong> is now fully active.</p>
        <div class="info-box">
            <p>Subscription Details</p>
            <div class="val">Plan: ' . htmlspecialchars((string) $registration['plan_name'], ENT_QUOTES, 'UTF-8') . '</div>
            <div class="val">Active: ' . htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') . ' → ' . htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') . '</div>
        </div>
        <p><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" class="btn">Go to Your Dashboard</a></p>
        <p style="font-size:13px;color:#64748b">Log in with the username and password you chose during registration.</p>
    ');

    mechanix_send_email(
        (string) $registration['email'],
        'MECHANIX – Your Subscription is Active! 🚀',
        $activatedHtml,
        (int) $registration['registration_id'],
        'approval_notice',
        $pdo
    );
}

function mechanix_reconcile_paymongo_for_pending_tenant(PDO $pdo, int $tenantId, bool $force = false): void
{
    if ($tenantId <= 0) {
        return;
    }

    if (!$force) {
        $_SESSION['_mechanix_pm_reconcile'] = $_SESSION['_mechanix_pm_reconcile'] ?? [];
        $last = $_SESSION['_mechanix_pm_reconcile'][$tenantId] ?? 0;
        if (time() - (int) $last < 12) {
            return;
        }
    }

    $tenantStmt = $pdo->prepare("SELECT status FROM tenants WHERE tenant_id = :tenant_id LIMIT 1");
    $tenantStmt->execute(['tenant_id' => $tenantId]);
    $status = (string) ($tenantStmt->fetchColumn() ?: '');

    if ($status !== 'pending_payment') {
        return;
    }

    $_SESSION['_mechanix_pm_reconcile'][$tenantId] = time();

    $brStmt = $pdo->prepare("
        SELECT
            br.paymongo_checkout_session_id,
            br.billing_status,
            br.billing_request_id
        FROM billing_requests br
        INNER JOIN tenant_registrations tr ON tr.registration_id = br.registration_id
        WHERE (tr.provisioned_tenant_id = :tenant_id OR tr.converted_tenant_id = :tenant_id_b)
          AND br.paymongo_checkout_session_id <> ''
          AND br.paymongo_checkout_session_id IS NOT NULL
        ORDER BY br.billing_request_id DESC
        LIMIT 1
    ");
    $brStmt->execute(['tenant_id' => $tenantId, 'tenant_id_b' => $tenantId]);
    $row = $brStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return;
    }

    $checkoutSessionId = (string) $row['paymongo_checkout_session_id'];
    if ($checkoutSessionId === '') {
        return;
    }

    $decoded = mechanix_paymongo_retrieve_checkout_session($checkoutSessionId);
    if (!is_array($decoded)) {
        return;
    }

    $attrs = $decoded['data']['attributes'] ?? [];

    if (!mechanix_paymongo_checkout_attrs_indicate_payment($attrs)) {
        return;
    }

    try {
        $pdo->beginTransaction();
        $result = mechanix_apply_checkout_session_payment_paid($pdo, $checkoutSessionId, $attrs);
        $pdo->commit();

        if (
            ($result['outcome'] ?? '') === 'processed'
            && !empty($result['registration'])
            && isset($result['start_date'], $result['end_date'])
        ) {
            mechanix_send_paymongo_activation_email(
                $pdo,
                $result['registration'],
                (string) $result['start_date'],
                (string) $result['end_date']
            );
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[mechanix_reconcile_paymongo_for_pending_tenant] ' . $e->getMessage());
    }
}
