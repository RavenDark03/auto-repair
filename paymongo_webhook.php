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

if (PAYMONGO_WEBHOOK_SECRET === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Webhook secret is not configured']);
    exit;
}

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

    $billingStmt = $pdo->prepare("
        SELECT billing_request_id, registration_id
        FROM billing_requests
        WHERE paymongo_checkout_session_id = :paymongo_checkout_session_id
        LIMIT 1
    ");
    $billingStmt->execute(['paymongo_checkout_session_id' => $checkoutSessionId]);
    $billing = $billingStmt->fetch();

    if (!$billing) {
        throw new RuntimeException('Matching billing request not found for checkout session.');
    }

    $paidAt = null;
    if (!empty($paidAtUnix) && ctype_digit((string) $paidAtUnix)) {
        $paidAt = date('Y-m-d H:i:s', (int) $paidAtUnix);
    }

    $updateBillingStmt = $pdo->prepare("
        UPDATE billing_requests
        SET billing_status = 'paid',
            payment_reference = COALESCE(:payment_reference, payment_reference),
            paymongo_payment_intent_id = COALESCE(:paymongo_payment_intent_id, paymongo_payment_intent_id),
            paymongo_payment_id = COALESCE(:paymongo_payment_id, paymongo_payment_id),
            paymongo_status = :paymongo_status,
            paid_at = :paid_at,
            updated_at = CURRENT_TIMESTAMP
        WHERE billing_request_id = :billing_request_id
    ");
    $updateBillingStmt->execute([
        'payment_reference' => $paymentReference,
        'paymongo_payment_intent_id' => $paymentIntent,
        'paymongo_payment_id' => $paymentId,
        'paymongo_status' => $paymongoStatus,
        'paid_at' => $paidAt,
        'billing_request_id' => (int) $billing['billing_request_id'],
    ]);

    $registrationStmt = $pdo->prepare("
        SELECT registration_id, business_name, email
        FROM tenant_registrations
        WHERE registration_id = :registration_id
        LIMIT 1
    ");
    $registrationStmt->execute(['registration_id' => (int) $billing['registration_id']]);
    $registration = $registrationStmt->fetch();

    if ($registration) {
        $updateRegistrationStmt = $pdo->prepare("
            UPDATE tenant_registrations
            SET registration_status = 'paid',
                reviewed_at = NOW()
            WHERE registration_id = :registration_id
        ");
        $updateRegistrationStmt->execute(['registration_id' => (int) $registration['registration_id']]);

        $emailLogStmt = $pdo->prepare("
            INSERT INTO email_logs (
                registration_id,
                recipient_email,
                subject,
                body,
                email_type,
                send_status,
                sent_at
            ) VALUES (
                :registration_id,
                :recipient_email,
                'MECHANIX payment confirmed',
                :body,
                'approval_notice',
                'sent',
                NOW()
            )
        ");
        $emailLogStmt->execute([
            'registration_id' => (int) $registration['registration_id'],
            'recipient_email' => $registration['email'],
            'body' => 'Payment has been confirmed for ' . $registration['business_name'] . '.',
        ]);
    }

    $pdo->commit();

    http_response_code(200);
    echo json_encode(['received' => true, 'processed' => true]);
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
