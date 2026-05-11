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
$name = trim($_POST['name'] ?? '');
$contact = trim($_POST['contact'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');

$_SESSION['customer_old_input'] = [
    'name' => $name,
    'contact' => $contact,
    'email' => $email,
    'address' => $address,
];

if ($name === '') {
    $_SESSION['customer_error'] = 'Customer name is required.';
    header('Location: ../customers.php');
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['customer_error'] = 'Please enter a valid email address.';
    header('Location: ../customers.php');
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        INSERT INTO customers (tenant_id, name, contact, email, address, status)
        VALUES (:tenant_id, :name, :contact, :email, :address, 'active')
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'name' => $name,
        'contact' => $contact !== '' ? $contact : null,
        'email' => $email !== '' ? $email : null,
        'address' => $address !== '' ? $address : null,
    ]);

    $customerId = (int) $pdo->lastInsertId();
    unset($_SESSION['customer_old_input']);
    $_SESSION['customer_success'] = 'Customer created for ' . $name . '.';
    header('Location: ../customers.php?customer_id=' . $customerId);
    exit;
} catch (PDOException $e) {
    $_SESSION['customer_error'] = 'Customer creation failed: ' . $e->getMessage();
    header('Location: ../customers.php');
    exit;
}
?>
