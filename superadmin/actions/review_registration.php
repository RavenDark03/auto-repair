<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/super_admin_auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/registration_provision.php';
require_once __DIR__ . '/../../includes/superadmin_redirects.php';
require_once __DIR__ . '/../../includes/email_helper.php';

requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mechanix_superadmin_non_post_redirect_url());
    exit;
}

$registrationId = (int) ($_POST['registration_id'] ?? 0);
$decision       = $_POST['decision'] ?? '';
$notes          = trim($_POST['notes'] ?? '');

// Only approve and reject remain as valid decisions; billing_sent is auto-handled
$allowedDecisions = [
    'approve' => 'approved',
    'reject'  => 'rejected',
];

if ($registrationId <= 0 || !isset($allowedDecisions[$decision])) {
    $_SESSION['super_admin_error'] = 'A valid registration and action (approve or reject) are required.';
    header('Location: ' . mechanix_superadmin_registration_redirect_url($registrationId));
    exit;
}

/**
 * Auto-generate a billing draft for the given registration.
 * Returns the billing_request_id (new or updated).
 */
function _auto_generate_billing(PDO $pdo, int $registrationId): int
{
    $regStmt = $pdo->prepare("
        SELECT
            tr.registration_id,
            tr.billing_cycle,
            sp.monthly_price,
            sp.yearly_price
        FROM tenant_registrations tr
        INNER JOIN subscription_plans sp ON sp.plan_id = tr.selected_plan_id
        WHERE tr.registration_id = :registration_id
        LIMIT 1
    ");
    $regStmt->execute(['registration_id' => $registrationId]);
    $reg = $regStmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        throw new RuntimeException('Registration not found while generating billing draft.');
    }

    $planAmount = $reg['billing_cycle'] === 'yearly'
        ? (float) $reg['yearly_price']
        : (float) $reg['monthly_price'];

    $addonStmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN tr.billing_cycle = 'yearly' THEN COALESCE(fp.yearly_addon_price, 0)
                ELSE COALESCE(fp.monthly_addon_price, 0)
            END
        ), 0) AS addon_total
        FROM tenant_registrations tr
        LEFT JOIN registration_requested_features rrf
            ON rrf.registration_id = tr.registration_id
           AND rrf.is_requested = 1
        LEFT JOIN feature_pricing fp
            ON fp.feature_id = rrf.feature_id
           AND fp.is_active = 1
        WHERE tr.registration_id = :registration_id
        GROUP BY tr.registration_id
    ");
    $addonStmt->execute(['registration_id' => $registrationId]);
    $addonAmount = (float) ($addonStmt->fetchColumn() ?: 0);
    $totalAmount = $planAmount + $addonAmount;

    $existingStmt = $pdo->prepare("
        SELECT billing_request_id
        FROM billing_requests
        WHERE registration_id = :registration_id
        ORDER BY billing_request_id DESC
        LIMIT 1
    ");
    $existingStmt->execute(['registration_id' => $registrationId]);
    $existingId = (int) ($existingStmt->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $pdo->prepare("
            UPDATE billing_requests
            SET plan_amount   = :plan_amount,
                addon_amount  = :addon_amount,
                total_amount  = :total_amount,
                billing_status = 'draft',
                currency       = 'PHP',
                updated_at     = CURRENT_TIMESTAMP
            WHERE billing_request_id = :billing_request_id
        ")->execute([
            'plan_amount'        => $planAmount,
            'addon_amount'       => $addonAmount,
            'total_amount'       => $totalAmount,
            'billing_request_id' => $existingId,
        ]);
        return $existingId;
    }

    $pdo->prepare("
        INSERT INTO billing_requests
            (registration_id, plan_amount, addon_amount, total_amount, currency, billing_status)
        VALUES
            (:registration_id, :plan_amount, :addon_amount, :total_amount, 'PHP', 'draft')
    ")->execute([
        'registration_id' => $registrationId,
        'plan_amount'     => $planAmount,
        'addon_amount'    => $addonAmount,
        'total_amount'    => $totalAmount,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Create a PayMongo checkout session for the given billing request.
 * Returns the checkout URL string, or throws on failure.
 */
function _auto_create_paymongo_checkout(PDO $pdo, int $billingRequestId, int $registrationId): string
{
    if (!defined('PAYMONGO_SECRET_KEY') || PAYMONGO_SECRET_KEY === '') {
        throw new RuntimeException('PayMongo secret key is not configured.');
    }

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
          AND br.registration_id    = :registration_id
        LIMIT 1
    ");
    $stmt->execute([
        'billing_request_id' => $billingRequestId,
        'registration_id'    => $registrationId,
    ]);
    $billing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$billing) {
        throw new RuntimeException('Billing request not found while creating PayMongo checkout.');
    }

    $amountInCentavos = (int) round(((float) $billing['total_amount']) * 100);
    if ($amountInCentavos <= 0) {
        throw new RuntimeException('Billing total must be greater than zero to create a checkout.');
    }

    $payload = [
        'data' => [
            'attributes' => [
                'billing' => [
                    'name'  => $billing['owner_full_name'],
                    'email' => $billing['email'],
                ],
                'send_email_receipt'  => true,
                'show_line_items'     => true,
                'show_description'    => true,
                'description'         => 'MECHANIX subscription billing for ' . $billing['business_name'],
                'line_items'          => [
                    [
                        'currency'    => $billing['currency'],
                        'amount'      => $amountInCentavos,
                        'name'        => 'MECHANIX Subscription',
                        'quantity'    => 1,
                        'description' => 'Billing #' . $billing['billing_request_id'] . ' – ' . $billing['business_name'],
                    ],
                ],
                'payment_method_types' => ['card', 'gcash', 'grab_pay', 'paymaya'],
                'success_url'          => PAYMONGO_CHECKOUT_SUCCESS_URL,
                'cancel_url'           => PAYMONGO_CHECKOUT_CANCEL_URL,
                'reference_number'     => 'billing_' . $billing['billing_request_id'],
                'metadata'             => [
                    'billing_request_id' => (string) $billing['billing_request_id'],
                    'registration_id'    => (string) $billing['registration_id'],
                    'business_name'      => $billing['business_name'],
                ],
            ],
        ],
    ];

    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
    $checkoutUrl       = $decoded['data']['attributes']['checkout_url'];
    $paymongoStatus    = $decoded['data']['attributes']['status'] ?? 'active';

    $pdo->prepare("
        UPDATE billing_requests
        SET paymongo_checkout_session_id = :paymongo_checkout_session_id,
            paymongo_checkout_url        = :paymongo_checkout_url,
            paymongo_status              = :paymongo_status,
            billing_status               = 'sent',
            updated_at                   = CURRENT_TIMESTAMP
        WHERE billing_request_id = :billing_request_id
    ")->execute([
        'paymongo_checkout_session_id' => $checkoutSessionId,
        'paymongo_checkout_url'        => $checkoutUrl,
        'paymongo_status'              => $paymongoStatus,
        'billing_request_id'           => $billingRequestId,
    ]);

    // Mark registration as billing_sent
    $pdo->prepare("
        UPDATE tenant_registrations
        SET registration_status = 'billing_sent',
            reviewed_at         = NOW()
        WHERE registration_id = :registration_id
    ")->execute(['registration_id' => $registrationId]);

    return $checkoutUrl;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $registrationStmt = $pdo->prepare("
        SELECT registration_id, email, business_name, owner_full_name, registration_status
        FROM tenant_registrations
        WHERE registration_id = :registration_id
        LIMIT 1
    ");
    $registrationStmt->execute(['registration_id' => $registrationId]);
    $registration = $registrationStmt->fetch(PDO::FETCH_ASSOC);

    if (!$registration) {
        throw new RuntimeException('The selected registration could not be found.');
    }

    if ($registration['registration_status'] === 'converted') {
        throw new RuntimeException('Converted registrations can no longer be reviewed.');
    }

    if ($registration['registration_status'] === 'paid') {
        throw new RuntimeException('Paid registrations should be converted to tenants, not reviewed again.');
    }

    $newStatus = $allowedDecisions[$decision];

    $updateStmt = $pdo->prepare("
        UPDATE tenant_registrations
        SET registration_status         = :registration_status,
            notes                       = :notes,
            reviewed_by_super_admin_id  = :reviewed_by_super_admin_id,
            reviewed_at                 = NOW()
        WHERE registration_id = :registration_id
    ");
    $updateStmt->execute([
        'registration_status'        => $newStatus,
        'notes'                      => $notes !== '' ? $notes : null,
        'reviewed_by_super_admin_id' => (int) $_SESSION['super_admin_id'],
        'registration_id'            => $registrationId,
    ]);

    $checkoutUrl    = null;
    $magicLoginUrl  = null;
    $billingAutoError = null;

    if ($newStatus === 'approved') {
        // 1. Provision tenant shell (pending_payment) + admin user
        mechanix_provision_registration_tenant($pdo, $registrationId);

        // 2. Auto-generate billing draft
        try {
            $billingRequestId = _auto_generate_billing($pdo, $registrationId);

            // 3. Auto-create PayMongo checkout session
            $checkoutUrl = _auto_create_paymongo_checkout($pdo, $billingRequestId, $registrationId);
        } catch (Throwable $billingEx) {
            // Non-fatal — we still approve, but note the error
            $billingAutoError = $billingEx->getMessage();
        }

        // 4. Create magic login token for the provisioned admin user
        $adminUserStmt = $pdo->prepare("
            SELECT u.user_id, u.username
            FROM users u
            INNER JOIN tenant_registrations tr ON tr.provisioned_tenant_id = u.tenant_id
            WHERE tr.registration_id = :registration_id
              AND u.role = 'admin'
            ORDER BY u.user_id ASC
            LIMIT 1
        ");
        $adminUserStmt->execute(['registration_id' => $registrationId]);
        $adminUser = $adminUserStmt->fetch(PDO::FETCH_ASSOC);

        if ($adminUser) {
            // Re-fetch provisioned_tenant_id
            $ptStmt = $pdo->prepare("SELECT provisioned_tenant_id FROM tenant_registrations WHERE registration_id = :id LIMIT 1");
            $ptStmt->execute(['id' => $registrationId]);
            $provisionedTenantId = (int) ($ptStmt->fetchColumn() ?: 0);

            if ($provisionedTenantId > 0) {
                $magicToken    = mechanix_create_login_token($pdo, $provisionedTenantId, (int) $adminUser['user_id'], 72);
                $appUrl        = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
                $basePath      = defined('BASE_URL') ? BASE_URL : '';
                $magicLoginUrl = $appUrl . $basePath . '/token_login.php?token=' . urlencode($magicToken);
            }
        }
    }

    $pdo->commit();

    // --- Send email AFTER commit ---
    if ($newStatus === 'approved') {
        $checkoutBtnHtml = '';
        if ($checkoutUrl !== null) {
            $checkoutBtnHtml = '<a href="' . htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8') . '" class="btn">Pay Subscription Now</a>';
        } elseif ($billingAutoError !== null) {
            $checkoutBtnHtml = '<p style="color:#f87171;font-size:13px;">Note: Checkout link could not be auto-created (' . htmlspecialchars($billingAutoError, ENT_QUOTES, 'UTF-8') . '). Please log in and use the subscription page, or contact support.</p>';
        }

        $magicBtnHtml = '';
        if ($magicLoginUrl !== null) {
            $magicBtnHtml = '
                <p>Click the button below to log in and complete your subscription payment:</p>
                <p><a href="' . htmlspecialchars($magicLoginUrl, ENT_QUOTES, 'UTF-8') . '" class="btn">Log In to Your Account</a></p>
                <p style="font-size:12px;color:#64748b">This link is valid for 72 hours and can only be used once. After that, log in at <a href="' . (defined('APP_URL') ? APP_URL . (defined('BASE_URL') ? BASE_URL : '') : '') . '/login.php" style="color:#818cf8;">' . (defined('APP_URL') ? APP_URL . (defined('BASE_URL') ? BASE_URL : '') : '') . '/login.php</a> with your chosen username and password.</p>
            ';
        } else {
            $magicBtnHtml = '<p>Log in at <strong>' . (defined('APP_URL') ? APP_URL . (defined('BASE_URL') ? BASE_URL : '') : 'the MECHANIX platform') . '/login.php</strong> with your chosen username and password.</p>';
        }

        $approvalHtml = mechanix_email_html('
            <h2>🎉 Your Business is Verified!</h2>
            <p>Hi <strong>' . htmlspecialchars($registration['owner_full_name'], ENT_QUOTES, 'UTF-8') . '</strong>,</p>
            <p>Great news! <strong>' . htmlspecialchars($registration['business_name'], ENT_QUOTES, 'UTF-8') . '</strong> has been verified and your MECHANIX workspace is ready.</p>
            ' . $magicBtnHtml . '
            <hr class="divider">
            <p>Once logged in, you will be prompted to complete your subscription payment to unlock the full dashboard.</p>
            ' . $checkoutBtnHtml . '
            ' . ($notes !== '' ? '<div class="info-box"><p>Note from our team:</p><div class="val">' . htmlspecialchars($notes, ENT_QUOTES, 'UTF-8') . '</div></div>' : '') . '
            <p style="font-size:13px;color:#64748b;margin-top:24px">If you have any questions, please contact MECHANIX support.</p>
        ');

        mechanix_send_email(
            $registration['email'],
            'MECHANIX – Your Business is Verified! 🎉',
            $approvalHtml,
            $registrationId,
            'approval_notice',
            $pdo
        );
    } elseif ($newStatus === 'rejected') {
        $rejectedHtml = mechanix_email_html('
            <h2>Registration Update</h2>
            <p>Hi <strong>' . htmlspecialchars($registration['owner_full_name'], ENT_QUOTES, 'UTF-8') . '</strong>,</p>
            <p>Thank you for your interest in MECHANIX. After reviewing your application for <strong>' . htmlspecialchars($registration['business_name'], ENT_QUOTES, 'UTF-8') . '</strong>, we are unable to approve it at this time.</p>
            ' . ($notes !== '' ? '<div class="info-box"><p>Reason:</p><div class="val">' . htmlspecialchars($notes, ENT_QUOTES, 'UTF-8') . '</div></div>' : '') . '
            <p>If you believe this is an error or would like to provide additional information, please contact MECHANIX support.</p>
        ');

        mechanix_send_email(
            $registration['email'],
            'MECHANIX – Registration Update',
            $rejectedHtml,
            $registrationId,
            'rejection_notice',
            $pdo
        );
    }

    $statusLabel = $newStatus === 'approved' ? 'approved' : 'rejected';
    $_SESSION['super_admin_success'] = 'Registration for ' . $registration['business_name'] . ' has been ' . $statusLabel . '.' . ($billingAutoError !== null ? ' (Billing/checkout auto-creation failed: ' . $billingAutoError . ')' : '');
    header('Location: ' . mechanix_superadmin_registration_redirect_url($registrationId));
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['super_admin_error'] = 'Registration review failed: ' . $e->getMessage();
    header('Location: ' . mechanix_superadmin_registration_redirect_url($registrationId));
    exit;
}
?>
