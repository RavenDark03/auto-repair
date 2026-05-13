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
$fullName = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$role = $_POST['role'] ?? '';
$allowedRoles = ['admin', 'cashier', 'mechanic'];

if ($userId <= 0 || $fullName === '' || $username === '' || !in_array($role, $allowedRoles, true)) {
    $_SESSION['staff_error'] = 'A valid staff name, username, and role are required.';
    header('Location: ../staff.php');
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $staffStmt = $pdo->prepare("
        SELECT user_id, full_name, username, role
        FROM users
        WHERE user_id = :user_id
          AND tenant_id = :tenant_id
          AND role IN ('admin', 'cashier', 'mechanic')
        LIMIT 1
        FOR UPDATE
    ");
    $staffStmt->execute([
        'user_id' => $userId,
        'tenant_id' => $tenantId,
    ]);
    $staff = $staffStmt->fetch();

    if (!$staff) {
        $pdo->rollBack();
        $_SESSION['staff_error'] = 'That staff account could not be found in this tenant.';
        header('Location: ../staff.php');
        exit;
    }

    if ($userId === $currentUserId && $role !== $staff['role']) {
        $pdo->rollBack();
        $_SESSION['staff_error'] = 'You cannot change the role of the account you are currently using.';
        header('Location: ../staff.php');
        exit;
    }

    $duplicateStmt = $pdo->prepare("
        SELECT user_id
        FROM users
        WHERE tenant_id = :tenant_id
          AND username = :username
          AND user_id <> :user_id
        LIMIT 1
    ");
    $duplicateStmt->execute([
        'tenant_id' => $tenantId,
        'username' => $username,
        'user_id' => $userId,
    ]);

    if ($duplicateStmt->fetch()) {
        $pdo->rollBack();
        $_SESSION['staff_error'] = 'That username is already being used in this tenant.';
        header('Location: ../staff.php');
        exit;
    }

    $updateStmt = $pdo->prepare("
        UPDATE users
        SET full_name = :full_name,
            username = :username,
            role = :role
        WHERE user_id = :user_id
          AND tenant_id = :tenant_id
    ");
    $updateStmt->execute([
        'full_name' => $fullName,
        'username' => $username,
        'role' => $role,
        'user_id' => $userId,
        'tenant_id' => $tenantId,
    ]);

    if ($userId === $currentUserId) {
        $_SESSION['full_name'] = $fullName;
        $_SESSION['username'] = $username;
    }

    $pdo->commit();
    $_SESSION['staff_success'] = 'Staff details updated for ' . $fullName . '.';
    header('Location: ../staff.php');
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['staff_error'] = 'Staff update failed: ' . $e->getMessage();
    header('Location: ../staff.php');
    exit;
}
?>
