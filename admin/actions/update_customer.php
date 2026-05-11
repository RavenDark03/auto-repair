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
$name = trim($_POST['name'] ?? '');
$contact = trim($_POST['contact'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$status = $_POST['status'] ?? '';
$allowedStatuses = ['active', 'inactive'];

$_SESSION['customer_old_input'] = [
    'name' => $name,
    'contact' => $contact,
    'email' => $email,
    'address' => $address,
    'status' => $status,
];

if ($customerId <= 0 || $name === '') {
    $_SESSION['customer_error'] = 'A valid customer and name are required.';
    header('Location: ../customers.php' . ($customerId > 0 ? '?customer_id=' . $customerId : ''));
    exit;
}

if (!in_array($status, $allowedStatuses, true)) {
    $_SESSION['customer_error'] = 'Please choose a valid customer status.';
    header('Location: ../customers.php?customer_id=' . $customerId);
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['customer_error'] = 'Please enter a valid email address.';
    header('Location: ../customers.php?customer_id=' . $customerId);
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        UPDATE customers
        SET name = :name,
            contact = :contact,
            email = :email,
            address = :address,
            status = :status
        WHERE customer_id = :customer_id
          AND tenant_id = :tenant_id
    ");
    $stmt->execute([
        'name' => $name,
        'contact' => $contact !== '' ? $contact : null,
        'email' => $email !== '' ? $email : null,
        'address' => $address !== '' ? $address : null,
        'status' => $status,
        'customer_id' => $customerId,
        'tenant_id' => $tenantId,
    ]);

    if ($stmt->rowCount() === 0) {
        $existsStmt = $pdo->prepare("
            SELECT customer_id
            FROM customers
            WHERE customer_id = :customer_id
              AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $existsStmt->execute([
            'customer_id' => $customerId,
            'tenant_id' => $tenantId,
        ]);

        if (!$existsStmt->fetch()) {
            $_SESSION['customer_error'] = 'That customer could not be found in this tenant.';
            header('Location: ../customers.php');
            exit;
        }
    }

    unset($_SESSION['customer_old_input']);
    $_SESSION['customer_success'] = 'Customer updated successfully.';
    header('Location: ../customers.php?customer_id=' . $customerId);
    exit;
} catch (PDOException $e) {
    $_SESSION['customer_error'] = 'Customer update failed: ' . $e->getMessage();
    header('Location: ../customers.php?customer_id=' . $customerId);
    exit;
}
?>
