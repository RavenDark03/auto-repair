<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/super_admin_auth.php';
require_once __DIR__ . '/../../includes/db.php';

requireSuperAdmin();

function buildDashboardRedirect(int $tenantId): string
{
    $query = ['tenant_id' => $tenantId];

    $tenantSearch = trim($_POST['tenant_search'] ?? '');
    $tenantStatusFilter = trim($_POST['tenant_status_filter'] ?? '');
    $subscriptionStatusFilter = trim($_POST['subscription_status_filter'] ?? '');

    if ($tenantSearch !== '') {
        $query['tenant_search'] = $tenantSearch;
    }

    if ($tenantStatusFilter !== '') {
        $query['tenant_status'] = $tenantStatusFilter;
    }

    if ($subscriptionStatusFilter !== '') {
        $query['subscription_status'] = $subscriptionStatusFilter;
    }

    return '../dashboard.php?' . http_build_query($query) . '#features';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit;
}

$tenantId = (int) ($_POST['tenant_id'] ?? 0);
$status = $_POST['status'] ?? '';
$allowedStatuses = ['active', 'expired'];

if ($tenantId <= 0 || !in_array($status, $allowedStatuses, true)) {
    $_SESSION['super_admin_error'] = 'A valid subscription status update is required.';
    header('Location: ' . buildDashboardRedirect($tenantId));
    exit;
}

try {
    $pdo = Database::getInstance();

    $subscriptionStmt = $pdo->prepare("
        SELECT
            s.subscription_id,
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

    $updateStmt = $pdo->prepare("
        UPDATE subscriptions
        SET status = :status
        WHERE subscription_id = :subscription_id
    ");
    $updateStmt->execute([
        'status' => $status,
        'subscription_id' => (int) $subscription['subscription_id'],
    ]);

    $_SESSION['super_admin_success'] = 'Subscription status for ' . $subscription['business_name'] . ' updated to ' . $status . '.';
    header('Location: ' . buildDashboardRedirect($tenantId));
    exit;
} catch (Throwable $e) {
    $_SESSION['super_admin_error'] = 'Subscription status update failed: ' . $e->getMessage();
    header('Location: ' . buildDashboardRedirect($tenantId));
    exit;
}
?>
