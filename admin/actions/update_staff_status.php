<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../staff.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$userId = (int) ($_POST['user_id'] ?? 0);
$status = $_POST['status'] ?? '';
$allowedStatuses = ['active', 'inactive'];

if ($userId <= 0 || !in_array($status, $allowedStatuses, true)) {
    $_SESSION['staff_error'] = 'A valid staff status action is required.';
    header('Location: ../staff.php');
    exit;
}

if ($userId === $currentUserId) {
    $_SESSION['staff_error'] = 'You cannot change the status of the account you are currently using.';
    header('Location: ../staff.php');
    exit;
}

try {
    $pdo = Database::getInstance();

    $staffStmt = $pdo->prepare("
        SELECT user_id, full_name, role
        FROM users
        WHERE user_id = :user_id
          AND tenant_id = :tenant_id
          AND role IN ('admin', 'cashier', 'mechanic')
        LIMIT 1
    ");
    $staffStmt->execute([
        'user_id' => $userId,
        'tenant_id' => $tenantId,
    ]);
    $staff = $staffStmt->fetch();

    if (!$staff) {
        $_SESSION['staff_error'] = 'That staff account could not be found in this tenant.';
        header('Location: ../staff.php');
        exit;
    }

    $updateStmt = $pdo->prepare("
        UPDATE users
        SET status = :status
        WHERE user_id = :user_id
          AND tenant_id = :tenant_id
    ");
    $updateStmt->execute([
        'status' => $status,
        'user_id' => $userId,
        'tenant_id' => $tenantId,
    ]);

    $_SESSION['staff_success'] = $staff['full_name'] . ' is now marked as ' . $status . '.';
    header('Location: ../staff.php');
    exit;
} catch (PDOException $e) {
    $_SESSION['staff_error'] = 'Staff status update failed: ' . $e->getMessage();
    header('Location: ../staff.php');
    exit;
}
?>
