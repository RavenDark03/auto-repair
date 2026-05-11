<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/super_admin_auth.php';
require_once __DIR__ . '/../../includes/db.php';

requireSuperAdmin();

function buildRegistrationRedirect(int $registrationId): string
{
    $query = ['registration_id' => $registrationId];

    $registrationSearch = trim($_POST['registration_search'] ?? '');
    $registrationStatusFilter = trim($_POST['registration_status_filter'] ?? '');
    $registrationBillingStatusFilter = trim($_POST['registration_billing_status_filter'] ?? '');

    if ($registrationSearch !== '') {
        $query['registration_search'] = $registrationSearch;
    }

    if ($registrationStatusFilter !== '') {
        $query['registration_status'] = $registrationStatusFilter;
    }

    if ($registrationBillingStatusFilter !== '') {
        $query['registration_billing_status'] = $registrationBillingStatusFilter;
    }

    return '../dashboard.php?' . http_build_query($query) . '#registrations';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit;
}

$billingRequestId = (int) ($_POST['billing_request_id'] ?? 0);
$registrationId = (int) ($_POST['registration_id'] ?? 0);
$billingStatus = $_POST['billing_status'] ?? '';
$paymentReference = trim($_POST['payment_reference'] ?? '');
$allowedStatuses = ['draft', 'sent', 'paid', 'cancelled'];

if ($billingRequestId <= 0 || $registrationId <= 0 || !in_array($billingStatus, $allowedStatuses, true)) {
    $_SESSION['super_admin_error'] = 'A valid billing action is required.';
    header('Location: ' . buildRegistrationRedirect($registrationId));
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $billingStmt = $pdo->prepare("
        SELECT br.billing_request_id, br.registration_id, tr.business_name, tr.email
        FROM billing_requests br
        INNER JOIN tenant_registrations tr ON tr.registration_id = br.registration_id
        WHERE br.billing_request_id = :billing_request_id
          AND br.registration_id = :registration_id
        LIMIT 1
    ");
    $billingStmt->execute([
        'billing_request_id' => $billingRequestId,
        'registration_id' => $registrationId,
    ]);
    $billingRecord = $billingStmt->fetch();

    if (!$billingRecord) {
        throw new RuntimeException('The selected billing request could not be found.');
    }

    $updateBillingStmt = $pdo->prepare("
        UPDATE billing_requests
        SET billing_status = :billing_status,
            payment_reference = :payment_reference,
            paid_at = CASE
                WHEN :billing_status = 'paid' AND paid_at IS NULL THEN NOW()
                WHEN :billing_status <> 'paid' THEN NULL
                ELSE paid_at
            END,
            updated_at = CURRENT_TIMESTAMP
        WHERE billing_request_id = :billing_request_id
    ");
    $updateBillingStmt->execute([
        'billing_status' => $billingStatus,
        'payment_reference' => $paymentReference !== '' ? $paymentReference : null,
        'billing_request_id' => $billingRequestId,
    ]);

    $registrationStatusMap = [
        'draft' => 'approved',
        'sent' => 'billing_sent',
        'paid' => 'paid',
        'cancelled' => 'rejected',
    ];

    $registrationUpdateStmt = $pdo->prepare("
        UPDATE tenant_registrations
        SET registration_status = :registration_status,
            reviewed_by_super_admin_id = :reviewed_by_super_admin_id,
            reviewed_at = NOW()
        WHERE registration_id = :registration_id
    ");
    $registrationUpdateStmt->execute([
        'registration_status' => $registrationStatusMap[$billingStatus],
        'reviewed_by_super_admin_id' => (int) $_SESSION['super_admin_id'],
        'registration_id' => $registrationId,
    ]);

    $emailTypeMap = [
        'draft' => 'billing_sent',
        'sent' => 'billing_sent',
        'paid' => 'approval_notice',
        'cancelled' => 'rejection_notice',
    ];

    $subjectMap = [
        'draft' => 'MECHANIX billing draft updated',
        'sent' => 'MECHANIX billing sent',
        'paid' => 'MECHANIX payment received',
        'cancelled' => 'MECHANIX billing cancelled',
    ];

    $bodyMap = [
        'draft' => 'A billing draft has been updated for your registration.',
        'sent' => 'Your billing request has been sent and is ready for payment.',
        'paid' => 'Your payment has been marked as received.',
        'cancelled' => 'Your billing request has been cancelled.',
    ];

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
            :subject,
            :body,
            :email_type,
            'pending'
        )
    ");
    $emailLogStmt->execute([
        'registration_id' => $registrationId,
        'recipient_email' => $billingRecord['email'],
        'subject' => $subjectMap[$billingStatus],
        'body' => $bodyMap[$billingStatus] . ($paymentReference !== '' ? ' Reference: ' . $paymentReference : ''),
        'email_type' => $emailTypeMap[$billingStatus],
    ]);

    $pdo->commit();

    $_SESSION['super_admin_success'] = 'Billing status for ' . $billingRecord['business_name'] . ' updated to ' . $billingStatus . '.';
    header('Location: ' . buildRegistrationRedirect($registrationId));
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['super_admin_error'] = 'Billing status update failed: ' . $e->getMessage();
    header('Location: ' . buildRegistrationRedirect($registrationId));
    exit;
}
?>
