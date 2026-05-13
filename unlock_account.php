<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/mechanix_urls.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['tenant_id'])) {
    die("You must be logged in to unlock your account. <a href='login.php'>Login here</a>");
}

try {
    $pdo = Database::getInstance();
    $tenantId = (int) $_SESSION['tenant_id'];
    
    $pdo->beginTransaction();

    // 1. Force the tenant to be fully active
    $pdo->prepare("UPDATE tenants SET status = 'active' WHERE tenant_id = :tenant_id")->execute(['tenant_id' => $tenantId]);
    
    // 2. Mark the billing request as paid so it doesn't loop anymore
    $pdo->prepare("
        UPDATE billing_requests br
        INNER JOIN tenant_registrations tr ON br.registration_id = tr.registration_id
        SET br.billing_status = 'paid'
        WHERE tr.provisioned_tenant_id = :tenant_id
    ")->execute(['tenant_id' => $tenantId]);
    
    // 3. Mark the registration as paid and converted
    $pdo->prepare("
        UPDATE tenant_registrations 
        SET registration_status = 'converted'
        WHERE provisioned_tenant_id = :tenant_id
    ")->execute(['tenant_id' => $tenantId]);

    // 4. Create a dummy subscription just to be safe
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE tenant_id = :tenant_id");
    $stmt->execute(['tenant_id' => $tenantId]);
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("
            INSERT INTO subscriptions (tenant_id, plan, start_date, end_date, status)
            VALUES (:tenant_id, 'Pro Plan', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'active')
        ")->execute(['tenant_id' => $tenantId]);
    }
    
    $pdo->commit();
    
    echo "<h1>Account Unlocked Successfully!</h1>";
    echo "<p>Your dashboard should now be fully accessible.</p>";
    echo "<p><a href='admin/dashboard.php'>Click here to go to your Dashboard</a></p>";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<h1>Error</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
