<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mechanix_paymongo_activation.php';

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

$eventType = $event['data']['attributes']['type'] ?? '';

if ($eventType !== 'checkout_session.payment.paid') {
    http_response_code(200);
    echo json_encode(['received' => true, 'ignored' => true]);
    exit;
}

$checkoutSession = $event['data']['attributes']['data']['attributes'] ?? [];
$checkoutSessionId = $event['data']['attributes']['data']['id'] ?? '';

if ($checkoutSessionId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing checkout session ID']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $result = mechanix_apply_checkout_session_payment_paid($pdo, $checkoutSessionId, $checkoutSession);

    $pdo->commit();

    if (($result['outcome'] ?? '') === 'already_converted') {
        http_response_code(200);
        echo json_encode(['received' => true, 'already_converted' => true]);
        exit;
    }

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

    http_response_code(200);
    $tenantActivated = isset($result['tenant_id']) ? (int) $result['tenant_id'] : null;
    echo json_encode([
        'received' => true,
        'processed' => ($result['outcome'] ?? '') === 'processed',
        'tenant_activated' => $tenantActivated,
    ]);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
