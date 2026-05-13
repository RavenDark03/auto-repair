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
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
$mustChangePassword = !empty($_POST['must_change_password']) ? 1 : 0;

if ($userId <= 0 || $username === '' || $password === '' || $confirmPassword === '') {
    $_SESSION['staff_error'] = 'A valid staff account, username, and temporary password are required.';
    header('Location: ../staff.php');
    exit;
}

if (strlen($password) < 8) {
    $_SESSION['staff_error'] = 'Temporary passwords must be at least 8 characters long.';
    header('Location: ../staff.php');
    exit;
}

if ($password !== $confirmPassword) {
    $_SESSION['staff_error'] = 'The password confirmation does not match.';
    header('Location: ../staff.php');
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $staffStmt = $pdo->prepare("
        SELECT user_id, full_name
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
        SET username = :username,
            password_hash = :password_hash,
            must_change_password = :must_change_password
        WHERE user_id = :user_id
          AND tenant_id = :tenant_id
    ");
    $updateStmt->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'must_change_password' => $mustChangePassword,
        'user_id' => $userId,
        'tenant_id' => $tenantId,
    ]);

    if ($userId === $currentUserId) {
        $_SESSION['username'] = $username;
        $_SESSION['must_change_password'] = (bool) $mustChangePassword;
    }

    $pdo->commit();
    $_SESSION['staff_success'] = 'Credentials reset for ' . $staff['full_name'] . '.';
    header('Location: ../staff.php');
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['staff_error'] = 'Credential reset failed: ' . $e->getMessage();
    header('Location: ../staff.php');
    exit;
}
?>
