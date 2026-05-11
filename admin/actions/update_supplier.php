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
$supplierId = (int) ($_POST['supplier_id'] ?? 0);
$supplierName = trim($_POST['supplier_name'] ?? '');
$contactPerson = trim($_POST['contact_person'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$status = $_POST['status'] ?? '';
$allowedStatuses = ['active', 'inactive'];

$_SESSION['supplier_old_input'] = [
    'supplier_name' => $supplierName,
    'contact_person' => $contactPerson,
    'phone' => $phone,
    'email' => $email,
    'address' => $address,
    'status' => $status,
];

if ($supplierId <= 0 || $supplierName === '') {
    $_SESSION['supplier_error'] = 'A valid supplier and supplier name are required.';
    header('Location: ../inventory.php' . ($supplierId > 0 ? '?supplier_id=' . $supplierId : ''));
    exit;
}

if (!in_array($status, $allowedStatuses, true)) {
    $_SESSION['supplier_error'] = 'Please choose a valid supplier status.';
    header('Location: ../inventory.php?supplier_id=' . $supplierId);
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['supplier_error'] = 'Please provide a valid supplier email address.';
    header('Location: ../inventory.php?supplier_id=' . $supplierId);
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        UPDATE suppliers
        SET supplier_name = :supplier_name,
            contact_person = :contact_person,
            phone = :phone,
            email = :email,
            address = :address,
            status = :status
        WHERE supplier_id = :supplier_id
          AND tenant_id = :tenant_id
    ");
    $stmt->execute([
        'supplier_name' => $supplierName,
        'contact_person' => $contactPerson !== '' ? $contactPerson : null,
        'phone' => $phone !== '' ? $phone : null,
        'email' => $email !== '' ? $email : null,
        'address' => $address !== '' ? $address : null,
        'status' => $status,
        'supplier_id' => $supplierId,
        'tenant_id' => $tenantId,
    ]);

    if ($stmt->rowCount() === 0) {
        $existsStmt = $pdo->prepare("
            SELECT supplier_id
            FROM suppliers
            WHERE supplier_id = :supplier_id
              AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $existsStmt->execute([
            'supplier_id' => $supplierId,
            'tenant_id' => $tenantId,
        ]);

        if (!$existsStmt->fetch()) {
            $_SESSION['supplier_error'] = 'That supplier could not be found in this tenant.';
            header('Location: ../inventory.php');
            exit;
        }
    }

    unset($_SESSION['supplier_old_input']);
    $_SESSION['supplier_success'] = 'Supplier updated successfully.';
    header('Location: ../inventory.php?supplier_id=' . $supplierId);
    exit;
} catch (PDOException $e) {
    $_SESSION['supplier_error'] = 'Supplier update failed: ' . $e->getMessage();
    header('Location: ../inventory.php?supplier_id=' . $supplierId);
    exit;
}
?>
