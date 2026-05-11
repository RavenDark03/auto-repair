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
$vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
$customerId = (int) ($_POST['customer_id'] ?? 0);
$make = trim($_POST['make'] ?? '');
$model = trim($_POST['model'] ?? '');
$yearModel = trim($_POST['year_model'] ?? '');
$color = trim($_POST['color'] ?? '');
$plate = trim($_POST['plate'] ?? '');
$mileage = trim($_POST['mileage'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$status = $_POST['status'] ?? '';
$allowedStatuses = ['active', 'inactive'];

$_SESSION['vehicle_old_input'] = [
    'customer_id' => $customerId,
    'make' => $make,
    'model' => $model,
    'year_model' => $yearModel,
    'color' => $color,
    'plate' => $plate,
    'mileage' => $mileage,
    'notes' => $notes,
    'status' => $status,
];

if ($vehicleId <= 0 || $customerId <= 0 || $make === '' || $model === '') {
    $_SESSION['vehicle_error'] = 'A valid vehicle, customer, make, and model are required.';
    header('Location: ../vehicles.php' . ($vehicleId > 0 ? '?vehicle_id=' . $vehicleId : ''));
    exit;
}

if (!in_array($status, $allowedStatuses, true)) {
    $_SESSION['vehicle_error'] = 'Please choose a valid vehicle status.';
    header('Location: ../vehicles.php?vehicle_id=' . $vehicleId);
    exit;
}

if ($mileage !== '' && (!ctype_digit($mileage) || (int) $mileage < 0)) {
    $_SESSION['vehicle_error'] = 'Mileage must be a valid non-negative number.';
    header('Location: ../vehicles.php?vehicle_id=' . $vehicleId);
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
        header('Location: ../vehicles.php?vehicle_id=' . $vehicleId);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE vehicles
        SET customer_id = :customer_id,
            make = :make,
            model = :model,
            year_model = :year_model,
            color = :color,
            plate = :plate,
            mileage = :mileage,
            notes = :notes,
            status = :status
        WHERE vehicle_id = :vehicle_id
          AND tenant_id = :tenant_id
    ");
    $stmt->execute([
        'customer_id' => $customerId,
        'make' => $make,
        'model' => $model,
        'year_model' => $yearModel !== '' ? $yearModel : null,
        'color' => $color !== '' ? $color : null,
        'plate' => $plate !== '' ? $plate : null,
        'mileage' => $mileage !== '' ? (int) $mileage : null,
        'notes' => $notes !== '' ? $notes : null,
        'status' => $status,
        'vehicle_id' => $vehicleId,
        'tenant_id' => $tenantId,
    ]);

    if ($stmt->rowCount() === 0) {
        $existsStmt = $pdo->prepare("
            SELECT vehicle_id
            FROM vehicles
            WHERE vehicle_id = :vehicle_id
              AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $existsStmt->execute([
            'vehicle_id' => $vehicleId,
            'tenant_id' => $tenantId,
        ]);

        if (!$existsStmt->fetch()) {
            $_SESSION['vehicle_error'] = 'That vehicle could not be found in this tenant.';
            header('Location: ../vehicles.php');
            exit;
        }
    }

    unset($_SESSION['vehicle_old_input']);
    $_SESSION['vehicle_success'] = 'Vehicle updated successfully.';
    header('Location: ../vehicles.php?vehicle_id=' . $vehicleId);
    exit;
} catch (PDOException $e) {
    $_SESSION['vehicle_error'] = 'Vehicle update failed: ' . $e->getMessage();
    header('Location: ../vehicles.php?vehicle_id=' . $vehicleId);
    exit;
}
?>
