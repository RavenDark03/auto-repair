<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/super_admin_auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/platform_rules.php';

require_once __DIR__ . '/../../includes/superadmin_redirects.php';

requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mechanix_superadmin_non_post_redirect_url());
    exit;
}

$registrationId = (int) ($_POST['registration_id'] ?? 0);
$billingRequestId = (int) ($_POST['billing_request_id'] ?? 0);
$notes = trim($_POST['payment_reference_check_notes'] ?? '');

if ($registrationId <= 0 || $billingRequestId <= 0) {
    $_SESSION['super_admin_error'] = 'A valid billing request is required for verification.';
    header('Location: ' . mechanix_superadmin_registration_redirect_url($registrationId));
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        SELECT billing_request_id, payment_reference, payment_reference_check_status
        FROM billing_requests
        WHERE billing_request_id = :billing_request_id
          AND registration_id = :registration_id
        LIMIT 1
    ");
    $stmt->execute([
        'billing_request_id' => $billingRequestId,
        'registration_id' => $registrationId,
    ]);
    $billingRequest = $stmt->fetch();

    if (!$billingRequest) {
        throw new RuntimeException('The selected billing request could not be found.');
    }

    $normalizedReference = normalizePaymentReference($billingRequest['payment_reference'] ?? '');
    $duplicateCount = 0;

    if ($normalizedReference !== '') {
        $duplicateStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM billing_requests
            WHERE REPLACE(REPLACE(REPLACE(UPPER(COALESCE(payment_reference, '')), '-', ''), ' ', ''), '.', '') = :payment_reference
        ");
        $duplicateStmt->execute(['payment_reference' => $normalizedReference]);
        $duplicateCount = (int) $duplicateStmt->fetchColumn();
    }

    $evaluation = evaluateNgReference($billingRequest, $duplicateCount);
    $status = $evaluation['status'] === 'verified' ? 'verified' : ($evaluation['status'] === 'ready' ? 'verified' : $evaluation['status']);

    $update = $pdo->prepare("
        UPDATE billing_requests
        SET payment_reference_check_status = :status,
            payment_reference_checked_at = NOW(),
            payment_reference_checked_by = :checked_by,
            payment_reference_check_notes = :notes
        WHERE billing_request_id = :billing_request_id
    ");
    $update->execute([
        'status' => $status,
        'checked_by' => (int) ($_SESSION['super_admin_id'] ?? 0),
        'notes' => $notes !== '' ? $notes : $evaluation['detail'],
        'billing_request_id' => $billingRequestId,
    ]);

    $_SESSION['super_admin_success'] = 'Payment reference review saved with status: ' . $evaluation['label'] . '.';
} catch (Throwable $e) {
    $_SESSION['super_admin_error'] = 'Payment verification failed: ' . $e->getMessage();
}

header('Location: ' . mechanix_superadmin_registration_redirect_url($registrationId));
exit;
