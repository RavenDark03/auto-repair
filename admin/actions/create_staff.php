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
$fullName = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$role = $_POST['role'] ?? '';
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
$mustChangePassword = !empty($_POST['must_change_password']) ? 1 : 0;
$allowedRoles = ['admin', 'cashier', 'mechanic'];

$_SESSION['staff_old_input'] = [
    'full_name' => $fullName,
    'username' => $username,
    'role' => $role,
    'must_change_password' => $mustChangePassword,
];

if ($fullName === '' || $username === '' || $password === '' || $confirmPassword === '') {
    $_SESSION['staff_error'] = 'Please complete all required staff fields.';
    header('Location: ../staff.php');
    exit;
}

if (!in_array($role, $allowedRoles, true)) {
    $_SESSION['staff_error'] = 'Please choose a valid staff role.';
    header('Location: ../staff.php');
    exit;
}

if (strlen($password) < 8) {
    $_SESSION['staff_error'] = 'Staff passwords must be at least 8 characters long.';
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

    $existingUserStmt = $pdo->prepare("
        SELECT user_id
        FROM users
        WHERE tenant_id = :tenant_id
          AND username = :username
        LIMIT 1
    ");
    $existingUserStmt->execute([
        'tenant_id' => $tenantId,
        'username' => $username,
    ]);

    if ($existingUserStmt->fetch()) {
        $_SESSION['staff_error'] = 'That username is already being used in this tenant.';
        header('Location: ../staff.php');
        exit;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $createStmt = $pdo->prepare("
        INSERT INTO users (tenant_id, full_name, username, password_hash, must_change_password, role, status)
        VALUES (:tenant_id, :full_name, :username, :password_hash, :must_change_password, :role, 'active')
    ");
    $createStmt->execute([
        'tenant_id' => $tenantId,
        'full_name' => $fullName,
        'username' => $username,
        'password_hash' => $passwordHash,
        'must_change_password' => $mustChangePassword,
        'role' => $role,
    ]);

    unset($_SESSION['staff_old_input']);
    $_SESSION['staff_success'] = ucfirst($role) . ' account created for ' . $fullName . '.';
    header('Location: ../staff.php');
    exit;
} catch (PDOException $e) {
    $_SESSION['staff_error'] = 'Staff creation failed: ' . $e->getMessage();
    header('Location: ../staff.php');
    exit;
}
?>
