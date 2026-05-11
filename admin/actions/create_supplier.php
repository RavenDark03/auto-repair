<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/feature_access.php';

requireAdmin();
requireTenantFeature('inventory', '../inventory.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../inventory.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$supplierName = trim($_POST['supplier_name'] ?? '');
$contactPerson = trim($_POST['contact_person'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');

$_SESSION['supplier_old_input'] = [
    'supplier_name' => $supplierName,
    'contact_person' => $contactPerson,
    'phone' => $phone,
    'email' => $email,
    'address' => $address,
];

if ($supplierName === '') {
    $_SESSION['supplier_error'] = 'Supplier name is required.';
    header('Location: ../inventory.php');
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['supplier_error'] = 'Please provide a valid supplier email address.';
    header('Location: ../inventory.php');
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        INSERT INTO suppliers (
            tenant_id,
            supplier_name,
            contact_person,
            phone,
            email,
            address,
            status
        ) VALUES (
            :tenant_id,
            :supplier_name,
            :contact_person,
            :phone,
            :email,
            :address,
            'active'
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'supplier_name' => $supplierName,
        'contact_person' => $contactPerson !== '' ? $contactPerson : null,
        'phone' => $phone !== '' ? $phone : null,
        'email' => $email !== '' ? $email : null,
        'address' => $address !== '' ? $address : null,
    ]);

    $supplierId = (int) $pdo->lastInsertId();
    unset($_SESSION['supplier_old_input']);
    $_SESSION['supplier_success'] = 'Supplier created successfully.';
    header('Location: ../inventory.php?supplier_id=' . $supplierId);
    exit;
} catch (PDOException $e) {
    $_SESSION['supplier_error'] = 'Supplier creation failed: ' . $e->getMessage();
    header('Location: ../inventory.php');
    exit;
}
?>
