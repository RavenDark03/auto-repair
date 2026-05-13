<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawPayload = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if ($rawPayload === false || $rawPayload === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

$event = json_decode($rawPayload, true);

if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

if (PAYMONGO_WEBHOOK_SECRET !== '') {
    function parsePaymongoSignature($signatureHeader) {
        $parts = [];
        foreach (explode(',', $signatureHeader) as $piece) {
            $segments = explode('=', trim($piece), 2);
            if (count($segments) === 2) {
                $parts[$segments[0]] = $segments[1];
            }
        }
        return $parts;
    }

    $signatureParts = parsePaymongoSignature($signatureHeader);
    $timestamp = $signatureParts['t'] ?? '';
    $testSignature = $signatureParts['te'] ?? '';
    $liveSignature = $signatureParts['li'] ?? '';
    $livemode = !empty($event['data']['attributes']['livemode']);
    $providedSignature = $livemode ? $liveSignature : $testSignature;

    if ($timestamp === '' || $providedSignature === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing PayMongo signature fields']);
        exit;
    }

    $signedPayload = $timestamp . '.' . $rawPayload;
    $computedSignature = hash_hmac('sha256', $signedPayload, PAYMONGO_WEBHOOK_SECRET);

    if (!hash_equals($computedSignature, $providedSignature)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}
// If PAYMONGO_WEBHOOK_SECRET is empty, we completely bypass signature verification for testing.

$eventType = $event['data']['attributes']['type'] ?? '';

if ($eventType !== 'checkout_session.payment.paid') {
    http_response_code(200);
    echo json_encode(['received' => true, 'ignored' => true]);
    exit;
}

$checkoutSession = $event['data']['attributes']['data']['attributes'] ?? [];
$checkoutSessionId = $event['data']['attributes']['data']['id'] ?? '';
$payments = $checkoutSession['payments'] ?? [];
$paymentIntent = $checkoutSession['payment_intent']['id'] ?? null;
$paidAtUnix = $checkoutSession['paid_at'] ?? null;
$paymongoStatus = $checkoutSession['status'] ?? 'paid';

$paymentId = null;
$paymentReference = null;

if (!empty($payments[0]['id'])) {
    $paymentId = $payments[0]['id'];
    $paymentReference = $payments[0]['attributes']['external_reference_number'] ?? null;
}

if ($checkoutSessionId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing checkout session ID']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    // 1. Find the matching billing request
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

    // 2. Mark billing as paid
    $pdo->prepare("
        UPDATE billing_requests
        SET billing_status              = 'paid',
            payment_reference           = COALESCE(:payment_reference, payment_reference),
            paymongo_payment_intent_id  = COALESCE(:paymongo_payment_intent_id, paymongo_payment_intent_id),
            paymongo_payment_id         = COALESCE(:paymongo_payment_id, paymongo_payment_id),
            paymongo_status             = :paymongo_status,
            paid_at                     = :paid_at,
            updated_at                  = CURRENT_TIMESTAMP
        WHERE billing_request_id = :billing_request_id
    ")->execute([
        'payment_reference'          => $paymentReference,
        'paymongo_payment_intent_id' => $paymentIntent,
        'paymongo_payment_id'        => $paymentId,
        'paymongo_status'            => $paymongoStatus,
        'paid_at'                    => $paidAt,
        'billing_request_id'         => (int) $billing['billing_request_id'],
    ]);

    // 3. Fetch registration + plan info
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
    ");
    $registrationStmt->execute(['registration_id' => (int) $billing['registration_id']]);
    $registration = $registrationStmt->fetch(PDO::FETCH_ASSOC);

    if (!$registration) {
        throw new RuntimeException('Registration not found for webhook conversion.');
    }

    // Idempotency: already converted, skip
    if ($registration['registration_status'] === 'converted') {
        $pdo->commit();
        http_response_code(200);
        echo json_encode(['received' => true, 'already_converted' => true]);
        exit;
    }

    // 4. Mark registration as paid
    $pdo->prepare("
        UPDATE tenant_registrations
        SET registration_status = 'paid',
            reviewed_at         = NOW()
        WHERE registration_id = :registration_id
    ")->execute(['registration_id' => (int) $registration['registration_id']]);

    // 5. Auto-convert to active tenant
    $provisionedTenantId = (int) ($registration['provisioned_tenant_id'] ?? 0);
    $tenantId = 0;
    $candidateUsername = '';

    if ($provisionedTenantId > 0) {
        // Activate the pre-provisioned tenant
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

        $adminUserStmt = $pdo->prepare("
            SELECT username FROM users
            WHERE tenant_id = :tenant_id AND role = 'admin'
            ORDER BY user_id ASC LIMIT 1
        ");
        $adminUserStmt->execute(['tenant_id' => $tenantId]);
        $candidateUsername = (string) ($adminUserStmt->fetchColumn() ?: 'admin');
    } else {
        // Create a new tenant from scratch (legacy path: no pre-provision)
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

    // 6. Create subscription record
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

    // 7. Enable plan features
    $planFeatureStmt = $pdo->prepare("
        SELECT feature_id FROM plan_features
        WHERE plan_id = :plan_id AND is_included = 1
    ");
    $planFeatureStmt->execute(['plan_id' => (int) $registration['plan_id']]);
    $featureIds = array_map('intval', $planFeatureStmt->fetchAll(PDO::FETCH_COLUMN));

    // Add requested add-ons
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

    // 8. Mark registration converted
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

    $pdo->commit();

    // 9. Send "account activated" email (after commit)
    require_once __DIR__ . '/includes/email_helper.php';
    $loginUrl = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . (defined('BASE_URL') ? BASE_URL : '') . '/login.php';

    $activatedHtml = mechanix_email_html('
        <h2>🚀 Your Subscription is Active!</h2>
        <p>Hi <strong>' . htmlspecialchars($registration['owner_full_name'], ENT_QUOTES, 'UTF-8') . '</strong>,</p>
        <p>Payment confirmed! Your MECHANIX workspace for <strong>' . htmlspecialchars($registration['business_name'], ENT_QUOTES, 'UTF-8') . '</strong> is now fully active.</p>
        <div class="info-box">
            <p>Subscription Details</p>
            <div class="val">Plan: ' . htmlspecialchars($registration['plan_name'], ENT_QUOTES, 'UTF-8') . '</div>
            <div class="val">Active: ' . $startDate . ' → ' . $endDate . '</div>
        </div>
        <p><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" class="btn">Go to Your Dashboard</a></p>
        <p style="font-size:13px;color:#64748b">Log in with the username and password you chose during registration.</p>
    ');

    mechanix_send_email(
        $registration['email'],
        'MECHANIX – Your Subscription is Active! 🚀',
        $activatedHtml,
        (int) $registration['registration_id'],
        'approval_notice',
        $pdo
    );

    http_response_code(200);
    echo json_encode(['received' => true, 'processed' => true, 'tenant_activated' => $tenantId]);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>

