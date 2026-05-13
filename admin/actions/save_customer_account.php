<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/feature_access.php';

requireAdmin();
requireTenantFeature('customer_module', '../customers.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../customers.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$customerId = (int) ($_POST['customer_id'] ?? 0);
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
$status = $_POST['status'] ?? 'active';
$mustChangePassword = !empty($_POST['must_change_password']) ? 1 : 0;
$allowedStatuses = ['active', 'inactive'];

if ($customerId <= 0 || $username === '' || !in_array($status, $allowedStatuses, true)) {
    $_SESSION['customer_error'] = 'A valid customer account username and status are required.';
    header('Location: ../customers.php' . ($customerId > 0 ? '?customer_id=' . $customerId : ''));
    exit;
}

if (($password !== '' || $confirmPassword !== '') && strlen($password) < 8) {
    $_SESSION['customer_error'] = 'Customer temporary passwords must be at least 8 characters long.';
    header('Location: ../customers.php?customer_id=' . $customerId);
    exit;
}

if ($password !== $confirmPassword) {
    $_SESSION['customer_error'] = 'The customer password confirmation does not match.';
    header('Location: ../customers.php?customer_id=' . $customerId);
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $customerStmt = $pdo->prepare("
        SELECT customer_id, name, status
        FROM customers
        WHERE customer_id = :customer_id
          AND tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $customerStmt->execute([
        'customer_id' => $customerId,
        'tenant_id' => $tenantId,
    ]);
    $customer = $customerStmt->fetch();

    if (!$customer) {
        $pdo->rollBack();
        $_SESSION['customer_error'] = 'That customer could not be found in this tenant.';
        header('Location: ../customers.php');
        exit;
    }

    $accountStmt = $pdo->prepare("
        SELECT user_id
        FROM users
        WHERE tenant_id = :tenant_id
          AND role = 'customer'
          AND customer_id = :customer_id
        LIMIT 1
        FOR UPDATE
    ");
    $accountStmt->execute([
        'tenant_id' => $tenantId,
        'customer_id' => $customerId,
    ]);
    $accountId = (int) ($accountStmt->fetchColumn() ?: 0);

    if ($accountId === 0 && $password === '') {
        $pdo->rollBack();
        $_SESSION['customer_error'] = 'A temporary password is required when creating a customer login.';
        header('Location: ../customers.php?customer_id=' . $customerId);
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
        'user_id' => $accountId,
    ]);

    if ($duplicateStmt->fetch()) {
        $pdo->rollBack();
        $_SESSION['customer_error'] = 'That username is already being used in this tenant.';
        header('Location: ../customers.php?customer_id=' . $customerId);
        exit;
    }

    if ($accountId > 0) {
        $sql = "
            UPDATE users
            SET full_name = :full_name,
                username = :username,
                status = :status,
                must_change_password = :must_change_password
        ";
        $params = [
            'full_name' => $customer['name'],
            'username' => $username,
            'status' => $status,
            'must_change_password' => $mustChangePassword,
            'user_id' => $accountId,
            'tenant_id' => $tenantId,
        ];

        if ($password !== '') {
            $sql .= ", password_hash = :password_hash";
            $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $sql .= "
            WHERE user_id = :user_id
              AND tenant_id = :tenant_id
        ";
        $updateStmt = $pdo->prepare($sql);
        $updateStmt->execute($params);
        $message = 'Customer login updated for ' . $customer['name'] . '.';
    } else {
        $createStmt = $pdo->prepare("
            INSERT INTO users (
                tenant_id,
                customer_id,
                full_name,
                username,
                password_hash,
                must_change_password,
                role,
                status
            ) VALUES (
                :tenant_id,
                :customer_id,
                :full_name,
                :username,
                :password_hash,
                :must_change_password,
                'customer',
                :status
            )
        ");
        $createStmt->execute([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'full_name' => $customer['name'],
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => $mustChangePassword,
            'status' => $status,
        ]);
        $message = 'Customer login created for ' . $customer['name'] . '.';
    }

    $pdo->commit();
    $_SESSION['customer_success'] = $message;
    header('Location: ../customers.php?customer_id=' . $customerId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['customer_error'] = 'Customer login could not be saved: ' . $e->getMessage();
    header('Location: ../customers.php?customer_id=' . $customerId);
    exit;
}
?>
