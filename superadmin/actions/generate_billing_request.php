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
$dueDate = trim($_POST['due_date'] ?? '');

if ($registrationId <= 0) {
    $_SESSION['super_admin_error'] = 'A valid registration is required before generating billing.';
    header('Location: ' . buildRegistrationRedirect($registrationId));
    exit;
}

if ($dueDate !== '') {
    $dueDateObject = DateTime::createFromFormat('Y-m-d', $dueDate);

    if (!$dueDateObject || $dueDateObject->format('Y-m-d') !== $dueDate) {
        $_SESSION['super_admin_error'] = 'Please provide a valid billing due date.';
        header('Location: ' . buildRegistrationRedirect($registrationId));
        exit;
    }
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $registrationStmt = $pdo->prepare("
        SELECT
            tr.registration_id,
            tr.business_name,
            tr.email,
            tr.billing_cycle,
            tr.registration_status,
            sp.plan_name,
            sp.monthly_price,
            sp.yearly_price
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

    if (!in_array($registration['registration_status'], ['approved', 'billing_sent', 'paid'], true)) {
        throw new RuntimeException('Billing drafts can only be generated after a registration has been approved.');
    }

    if ($registration['registration_status'] === 'converted') {
        throw new RuntimeException('Billing drafts cannot be changed after tenant conversion is complete.');
    }

    $planAmount = $registration['billing_cycle'] === 'yearly'
        ? (float) $registration['yearly_price']
        : (float) $registration['monthly_price'];

    $addonStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(
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

    $existingBillingStmt = $pdo->prepare("
        SELECT billing_request_id
        FROM billing_requests
        WHERE registration_id = :registration_id
        ORDER BY billing_request_id DESC
        LIMIT 1
    ");
    $existingBillingStmt->execute(['registration_id' => $registrationId]);
    $existingBillingId = (int) ($existingBillingStmt->fetchColumn() ?: 0);

    if ($existingBillingId > 0) {
        $billingRequestStmt = $pdo->prepare("
            UPDATE billing_requests
            SET plan_amount = :plan_amount,
                addon_amount = :addon_amount,
                total_amount = :total_amount,
                due_date = :due_date,
                billing_status = 'draft',
                currency = 'PHP',
                updated_at = CURRENT_TIMESTAMP
            WHERE billing_request_id = :billing_request_id
        ");
        $billingRequestStmt->execute([
            'plan_amount' => $planAmount,
            'addon_amount' => $addonAmount,
            'total_amount' => $totalAmount,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'billing_request_id' => $existingBillingId,
        ]);
    } else {
        $billingRequestStmt = $pdo->prepare("
            INSERT INTO billing_requests (
                registration_id,
                plan_amount,
                addon_amount,
                total_amount,
                currency,
                billing_status,
                due_date
            ) VALUES (
                :registration_id,
                :plan_amount,
                :addon_amount,
                :total_amount,
                'PHP',
                'draft',
                :due_date
            )
        ");
        $billingRequestStmt->execute([
            'registration_id' => $registrationId,
            'plan_amount' => $planAmount,
            'addon_amount' => $addonAmount,
            'total_amount' => $totalAmount,
            'due_date' => $dueDate !== '' ? $dueDate : null,
        ]);
    }

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
            'MECHANIX billing draft prepared',
            :body,
            'billing_sent',
            'pending'
        )
    ");
    $emailLogStmt->execute([
        'registration_id' => $registrationId,
        'recipient_email' => $registration['email'],
        'body' => 'A billing draft has been prepared for ' . $registration['business_name'] . ' under the ' . $registration['plan_name'] . ' plan.',
    ]);

    $pdo->commit();

    $_SESSION['super_admin_success'] = 'Billing draft generated for ' . $registration['business_name'] . '.';
    header('Location: ' . buildRegistrationRedirect($registrationId));
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['super_admin_error'] = 'Billing generation failed: ' . $e->getMessage();
    header('Location: ' . buildRegistrationRedirect($registrationId));
    exit;
}
?>
