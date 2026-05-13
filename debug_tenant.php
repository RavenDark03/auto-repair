<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = Database::getInstance();
    
    // Fetch the 5 most recently added users and their tenant status
    $stmt = $pdo->query("
        SELECT 
            u.username, 
            u.status AS user_status, 
            t.business_name, 
            t.status AS tenant_status,
            t.tenant_id
        FROM users u
        LEFT JOIN tenants t ON u.tenant_id = t.tenant_id
        ORDER BY u.user_id DESC
        LIMIT 5
    ");
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Recent Users and Tenant Status:</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Username</th><th>User Status</th><th>Business Name</th><th>Tenant Status</th></tr>";
    
    foreach ($users as $u) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($u['username']) . "</td>";
        echo "<td>" . htmlspecialchars($u['user_status']) . "</td>";
        echo "<td>" . htmlspecialchars((string)$u['business_name']) . "</td>";
        echo "<td>'" . htmlspecialchars((string)$u['tenant_status']) . "' (length: " . strlen((string)$u['tenant_status']) . ")</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
