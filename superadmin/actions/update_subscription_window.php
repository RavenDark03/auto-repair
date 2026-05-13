<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/super_admin_auth.php';
require_once __DIR__ . '/../../includes/db.php';

requireSuperAdmin();
require_once __DIR__ . '/../../includes/superadmin_redirects.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mechanix_superadmin_non_post_redirect_url());
    exit;
}

$tenantId = (int) ($_POST['tenant_id'] ?? 0);
$actionType = $_POST['action_type'] ?? '';
$allowedActions = ['save_dates', 'extend_month', 'extend_year'];

if ($tenantId <= 0 || !in_array($actionType, $allowedActions, true)) {
    $_SESSION['super_admin_error'] = 'A valid subscription window action is required.';
    header('Location: ' . mechanix_superadmin_tenant_redirect_url($tenantId));
    exit;
}

try {
    $pdo = Database::getInstance();

    $subscriptionStmt = $pdo->prepare("
        SELECT
            s.subscription_id,
            s.start_date,
            s.end_date,
            t.business_name
        FROM subscriptions s
        INNER JOIN tenants t
            ON t.tenant_id = s.tenant_id
        WHERE s.tenant_id = :tenant_id
        ORDER BY s.subscription_id DESC
        LIMIT 1
    ");
    $subscriptionStmt->execute(['tenant_id' => $tenantId]);
    $subscription = $subscriptionStmt->fetch();

    if (!$subscription) {
        throw new RuntimeException('No subscription record exists for this tenant yet.');
    }

    $subscriptionId = (int) $subscription['subscription_id'];
    $businessName = $subscription['business_name'];

    if ($actionType === 'save_dates') {
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');

        if ($startDate === '' || $endDate === '') {
            throw new RuntimeException('Both subscription dates are required.');
        }

        $startDateObject = DateTime::createFromFormat('Y-m-d', $startDate);
        $endDateObject = DateTime::createFromFormat('Y-m-d', $endDate);

        if (!$startDateObject || $startDateObject->format('Y-m-d') !== $startDate) {
            throw new RuntimeException('Subscription start date must be a valid date.');
        }

        if (!$endDateObject || $endDateObject->format('Y-m-d') !== $endDate) {
            throw new RuntimeException('Subscription end date must be a valid date.');
        }

        if (strtotime($endDate) < strtotime($startDate)) {
            throw new RuntimeException('Subscription end date cannot be earlier than the start date.');
        }

        $updateStmt = $pdo->prepare("
            UPDATE subscriptions
            SET start_date = :start_date,
                end_date = :end_date
            WHERE subscription_id = :subscription_id
        ");
        $updateStmt->execute([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'subscription_id' => $subscriptionId,
        ]);

        $_SESSION['super_admin_success'] = 'Subscription window for ' . $businessName . ' updated successfully.';
        header('Location: ' . mechanix_superadmin_tenant_redirect_url($tenantId));
        exit;
    }

    $currentEndDate = !empty($subscription['end_date']) ? $subscription['end_date'] : date('Y-m-d');
    $baseTimestamp = strtotime($currentEndDate) >= strtotime(date('Y-m-d'))
        ? strtotime($currentEndDate)
        : strtotime(date('Y-m-d'));

    $newEndDate = $actionType === 'extend_year'
        ? date('Y-m-d', strtotime('+1 year', $baseTimestamp))
        : date('Y-m-d', strtotime('+1 month', $baseTimestamp));

    $updateStmt = $pdo->prepare("
        UPDATE subscriptions
        SET end_date = :end_date
        WHERE subscription_id = :subscription_id
    ");
    $updateStmt->execute([
        'end_date' => $newEndDate,
        'subscription_id' => $subscriptionId,
    ]);

    $actionLabel = $actionType === 'extend_year' ? '1 year' : '1 month';
    $_SESSION['super_admin_success'] = 'Subscription for ' . $businessName . ' extended by ' . $actionLabel . '.';
    header('Location: ' . mechanix_superadmin_tenant_redirect_url($tenantId));
    exit;
} catch (Throwable $e) {
    $_SESSION['super_admin_error'] = 'Subscription window update failed: ' . $e->getMessage();
    header('Location: ' . mechanix_superadmin_tenant_redirect_url($tenantId));
    exit;
}
?>
