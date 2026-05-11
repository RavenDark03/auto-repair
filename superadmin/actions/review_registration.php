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

$registrationId = (int) ($_POST['registration_id'] ?? 0);
$decision = $_POST['decision'] ?? '';
$notes = trim($_POST['notes'] ?? '');
$allowedDecisions = [
    'approve' => 'approved',
    'reject' => 'rejected',
    'billing_sent' => 'billing_sent',
];

if ($registrationId <= 0 || !isset($allowedDecisions[$decision])) {
    $_SESSION['super_admin_error'] = 'A valid registration and action are required.';
    header('Location: ' . buildRegistrationRedirect($registrationId));
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $registrationStmt = $pdo->prepare("
        SELECT registration_id, email, business_name, registration_status
        FROM tenant_registrations
        WHERE registration_id = :registration_id
        LIMIT 1
    ");
    $registrationStmt->execute(['registration_id' => $registrationId]);
    $registration = $registrationStmt->fetch();

    if (!$registration) {
        throw new RuntimeException('The selected registration could not be found.');
    }

    $newStatus = $allowedDecisions[$decision];

    if ($registration['registration_status'] === 'converted') {
        throw new RuntimeException('Converted registrations can no longer be reviewed.');
    }

    if ($registration['registration_status'] === 'paid') {
        throw new RuntimeException('Paid registrations should be converted to tenants instead of being reviewed again.');
    }

    if ($decision === 'billing_sent') {
        $billingStmt = $pdo->prepare("
            SELECT billing_request_id
            FROM billing_requests
            WHERE registration_id = :registration_id
            ORDER BY billing_request_id DESC
            LIMIT 1
        ");
        $billingStmt->execute(['registration_id' => $registrationId]);

        if (!$billingStmt->fetchColumn()) {
            throw new RuntimeException('Generate a billing draft before marking billing as sent.');
        }
    }

    $updateStmt = $pdo->prepare("
        UPDATE tenant_registrations
        SET registration_status = :registration_status,
            notes = :notes,
            reviewed_by_super_admin_id = :reviewed_by_super_admin_id,
            reviewed_at = NOW()
        WHERE registration_id = :registration_id
    ");
    $updateStmt->execute([
        'registration_status' => $newStatus,
        'notes' => $notes !== '' ? $notes : null,
        'reviewed_by_super_admin_id' => (int) $_SESSION['super_admin_id'],
        'registration_id' => $registrationId,
    ]);

    $emailTypeMap = [
        'approved' => 'approval_notice',
        'rejected' => 'rejection_notice',
        'billing_sent' => 'billing_sent',
    ];

    $subjectMap = [
        'approved' => 'MECHANIX registration approved',
        'rejected' => 'MECHANIX registration update',
        'billing_sent' => 'MECHANIX billing instructions',
    ];

    $bodyMap = [
        'approved' => 'Your registration has been approved and is ready for the billing and onboarding process.',
        'rejected' => 'Your registration has been reviewed and is not approved at this time.',
        'billing_sent' => 'Your registration is approved and billing instructions are ready for the next step.',
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
        'recipient_email' => $registration['email'],
        'subject' => $subjectMap[$newStatus],
        'body' => $bodyMap[$newStatus] . ($notes !== '' ? ' Notes: ' . $notes : ''),
        'email_type' => $emailTypeMap[$newStatus],
    ]);

    $pdo->commit();

    $_SESSION['super_admin_success'] = 'Registration for ' . $registration['business_name'] . ' updated to ' . $newStatus . '.';
    header('Location: ' . buildRegistrationRedirect($registrationId));
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['super_admin_error'] = 'Registration review failed: ' . $e->getMessage();
    header('Location: ' . buildRegistrationRedirect($registrationId));
    exit;
}
?>
