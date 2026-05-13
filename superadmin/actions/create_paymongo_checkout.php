<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/super_admin_auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../config/config.php';

requireSuperAdmin();
require_once __DIR__ . '/../../includes/superadmin_redirects.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mechanix_superadmin_non_post_redirect_url());
    exit;
}

$billingRequestId = (int) ($_POST['billing_request_id'] ?? 0);
$registrationId = (int) ($_POST['registration_id'] ?? 0);

if ($billingRequestId <= 0 || $registrationId <= 0) {
    $_SESSION['super_admin_error'] = 'A valid billing request is required before creating a PayMongo checkout.';
    header('Location: ' . mechanix_superadmin_registration_redirect_url($registrationId));
    exit;
}

if (PAYMONGO_SECRET_KEY === '') {
    $_SESSION['super_admin_error'] = 'Set your PayMongo secret key in config/config.php before creating a checkout session.';
    header('Location: ' . mechanix_superadmin_registration_redirect_url($registrationId));
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        SELECT
            br.billing_request_id,
            br.registration_id,
            br.total_amount,
            br.currency,
            tr.business_name,
            tr.owner_full_name,
            tr.email
        FROM billing_requests br
        INNER JOIN tenant_registrations tr ON tr.registration_id = br.registration_id
        WHERE br.billing_request_id = :billing_request_id
          AND br.registration_id = :registration_id
        LIMIT 1
    ");
    $stmt->execute([
        'billing_request_id' => $billingRequestId,
        'registration_id' => $registrationId,
    ]);
    $billing = $stmt->fetch();

    if (!$billing) {
        throw new RuntimeException('Billing request not found.');
    }

    $registrationStateStmt = $pdo->prepare("
        SELECT registration_status
        FROM tenant_registrations
        WHERE registration_id = :registration_id
        LIMIT 1
    ");
    $registrationStateStmt->execute(['registration_id' => $registrationId]);
    $registrationStatus = (string) ($registrationStateStmt->fetchColumn() ?: '');

    if (!in_array($registrationStatus, ['approved', 'billing_sent'], true)) {
        throw new RuntimeException('PayMongo checkout can only be created for approved registrations that are still awaiting payment.');
    }

    $existingCheckoutSessionId = $pdo->prepare("
        SELECT paymongo_checkout_session_id, billing_status
        FROM billing_requests
        WHERE billing_request_id = :billing_request_id
        LIMIT 1
    ");
    $existingCheckoutSessionId->execute(['billing_request_id' => $billingRequestId]);
    $existingCheckout = $existingCheckoutSessionId->fetch();

    if (!empty($existingCheckout['billing_status']) && $existingCheckout['billing_status'] === 'paid') {
        throw new RuntimeException('This billing request is already marked as paid.');
    }

    $amountInCentavos = (int) round(((float) $billing['total_amount']) * 100);

    if ($amountInCentavos <= 0) {
        throw new RuntimeException('Billing total must be greater than zero before creating a checkout session.');
    }

    $payload = [
        'data' => [
            'attributes' => [
                'billing' => [
                    'name' => $billing['owner_full_name'],
                    'email' => $billing['email'],
                ],
                'send_email_receipt' => true,
                'show_line_items' => true,
                'show_description' => true,
                'description' => 'MECHANIX subscription billing for ' . $billing['business_name'],
                'line_items' => [
                    [
                        'currency' => $billing['currency'],
                        'amount' => $amountInCentavos,
                        'name' => 'MECHANIX Subscription Billing',
                        'quantity' => 1,
                        'description' => 'Billing request #' . $billing['billing_request_id'] . ' for ' . $billing['business_name'],
                    ],
                ],
                'payment_method_types' => ['card', 'gcash', 'grab_pay', 'paymaya'],
                'success_url' => PAYMONGO_CHECKOUT_SUCCESS_URL,
                'cancel_url' => PAYMONGO_CHECKOUT_CANCEL_URL,
                'reference_number' => 'billing_' . $billing['billing_request_id'],
                'metadata' => [
                    'billing_request_id' => (string) $billing['billing_request_id'],
                    'registration_id' => (string) $billing['registration_id'],
                    'business_name' => $billing['business_name'],
                ],
            ],
        ],
    ];

    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('PayMongo request failed: ' . $curlError);
    }

    $decoded = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300 || empty($decoded['data']['id']) || empty($decoded['data']['attributes']['checkout_url'])) {
        $errorDetail = $decoded['errors'][0]['detail'] ?? $decoded['errors'][0]['title'] ?? 'Unexpected PayMongo response.';
        throw new RuntimeException('PayMongo checkout creation failed: ' . $errorDetail);
    }

    $checkoutSessionId = $decoded['data']['id'];
    $checkoutUrl = $decoded['data']['attributes']['checkout_url'];
    $paymongoStatus = $decoded['data']['attributes']['status'] ?? 'active';

    $updateStmt = $pdo->prepare("
        UPDATE billing_requests
        SET paymongo_checkout_session_id = :paymongo_checkout_session_id,
            paymongo_checkout_url = :paymongo_checkout_url,
            paymongo_status = :paymongo_status,
            billing_status = 'sent',
            updated_at = CURRENT_TIMESTAMP
        WHERE billing_request_id = :billing_request_id
    ");
    $updateStmt->execute([
        'paymongo_checkout_session_id' => $checkoutSessionId,
        'paymongo_checkout_url' => $checkoutUrl,
        'paymongo_status' => $paymongoStatus,
        'billing_request_id' => $billingRequestId,
    ]);

    $registrationUpdateStmt = $pdo->prepare("
        UPDATE tenant_registrations
        SET registration_status = 'billing_sent',
            reviewed_by_super_admin_id = :reviewed_by_super_admin_id,
            reviewed_at = NOW()
        WHERE registration_id = :registration_id
    ");
    $registrationUpdateStmt->execute([
        'reviewed_by_super_admin_id' => (int) $_SESSION['super_admin_id'],
        'registration_id' => $registrationId,
    ]);

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
            'MECHANIX PayMongo checkout created',
            :body,
            'billing_sent',
            'pending'
        )
    ");
    $emailLogStmt->execute([
        'registration_id' => $registrationId,
        'recipient_email' => $billing['email'],
        'body' => 'A PayMongo checkout session has been created for billing request #' . $billingRequestId . '. URL: ' . $checkoutUrl,
    ]);

    $_SESSION['super_admin_success'] = 'PayMongo checkout created successfully.';
    header('Location: ' . mechanix_superadmin_registration_redirect_url($registrationId));
    exit;
} catch (Throwable $e) {
    $_SESSION['super_admin_error'] = 'PayMongo checkout creation failed: ' . $e->getMessage();
    header('Location: ' . mechanix_superadmin_registration_redirect_url($registrationId));
    exit;
}
?>
