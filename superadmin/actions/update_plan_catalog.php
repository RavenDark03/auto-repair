<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/super_admin_auth.php';
require_once __DIR__ . '/../../includes/db.php';

require_once __DIR__ . '/../../includes/superadmin_redirects.php';

requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mechanix_superadmin_non_post_redirect_url());
    exit;
}

$planId = (int) ($_POST['plan_id'] ?? 0);
$planName = trim($_POST['plan_name'] ?? '');
$monthlyPrice = trim($_POST['monthly_price'] ?? '');
$yearlyPrice = trim($_POST['yearly_price'] ?? '');
$description = trim($_POST['description'] ?? '');
$isActive = isset($_POST['is_active']) ? 1 : 0;

if ($planId <= 0 || $planName === '' || $monthlyPrice === '' || $yearlyPrice === '') {
    $_SESSION['super_admin_error'] = 'Plan name and pricing fields are required.';
    header('Location: ' . mechanix_superadmin_plan_catalog_redirect_url());
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        UPDATE subscription_plans
        SET plan_name = :plan_name,
            monthly_price = :monthly_price,
            yearly_price = :yearly_price,
            description = :description,
            is_active = :is_active
        WHERE plan_id = :plan_id
    ");
    $stmt->execute([
        'plan_name' => $planName,
        'monthly_price' => (float) $monthlyPrice,
        'yearly_price' => (float) $yearlyPrice,
        'description' => $description !== '' ? $description : null,
        'is_active' => $isActive,
        'plan_id' => $planId,
    ]);

    $_SESSION['super_admin_success'] = 'Plan catalog updated successfully.';
} catch (Throwable $e) {
    $_SESSION['super_admin_error'] = 'Plan catalog update failed: ' . $e->getMessage();
}

header('Location: ' . mechanix_superadmin_plan_catalog_redirect_url());
exit;
