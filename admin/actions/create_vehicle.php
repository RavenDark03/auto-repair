<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/feature_access.php';

requireAdmin();
requireTenantFeature('customer_module', '../vehicles.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../vehicles.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$customerId = (int) ($_POST['customer_id'] ?? 0);
$make = trim($_POST['make'] ?? '');
$model = trim($_POST['model'] ?? '');
$yearModel = trim($_POST['year_model'] ?? '');
$color = trim($_POST['color'] ?? '');
$plate = trim($_POST['plate'] ?? '');
$mileage = trim($_POST['mileage'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$_SESSION['vehicle_old_input'] = [
    'customer_id' => $customerId,
    'make' => $make,
    'model' => $model,
    'year_model' => $yearModel,
    'color' => $color,
    'plate' => $plate,
    'mileage' => $mileage,
    'notes' => $notes,
];

if ($customerId <= 0 || $make === '' || $model === '') {
    $_SESSION['vehicle_error'] = 'Customer, make, and model are required.';
    header('Location: ../vehicles.php');
    exit;
}

if ($mileage !== '' && (!ctype_digit($mileage) || (int) $mileage < 0)) {
    $_SESSION['vehicle_error'] = 'Mileage must be a valid non-negative number.';
    header('Location: ../vehicles.php');
    exit;
}

try {
    $pdo = Database::getInstance();

    $customerStmt = $pdo->prepare("
        SELECT customer_id
        FROM customers
        WHERE customer_id = :customer_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $customerStmt->execute([
        'customer_id' => $customerId,
        'tenant_id' => $tenantId,
    ]);

    if (!$customerStmt->fetch()) {
        $_SESSION['vehicle_error'] = 'The selected customer does not belong to this tenant.';
        header('Location: ../vehicles.php');
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO vehicles (
            tenant_id,
            customer_id,
            make,
            model,
            year_model,
            color,
            plate,
            mileage,
            notes,
            status
        ) VALUES (
            :tenant_id,
            :customer_id,
            :make,
            :model,
            :year_model,
            :color,
            :plate,
            :mileage,
            :notes,
            'active'
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'customer_id' => $customerId,
        'make' => $make,
        'model' => $model,
        'year_model' => $yearModel !== '' ? $yearModel : null,
        'color' => $color !== '' ? $color : null,
        'plate' => $plate !== '' ? $plate : null,
        'mileage' => $mileage !== '' ? (int) $mileage : null,
        'notes' => $notes !== '' ? $notes : null,
    ]);

    $vehicleId = (int) $pdo->lastInsertId();
    unset($_SESSION['vehicle_old_input']);
    $_SESSION['vehicle_success'] = 'Vehicle created successfully.';
    header('Location: ../vehicles.php?vehicle_id=' . $vehicleId);
    exit;
} catch (PDOException $e) {
    $_SESSION['vehicle_error'] = 'Vehicle creation failed: ' . $e->getMessage();
    header('Location: ../vehicles.php');
    exit;
}
?>
