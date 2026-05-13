<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/feature_access.php';

requireAdmin();
requireTenantFeature('appointments', '../appointments.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../appointments.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$customerId = (int) ($_POST['customer_id'] ?? 0);
$vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
$mechanicId = (int) ($_POST['mechanic_id'] ?? 0);
$appointmentDate = trim($_POST['appointment_date'] ?? '');
$concern = trim($_POST['concern'] ?? '');

$_SESSION['appointment_old_input'] = [
    'customer_id' => $customerId,
    'vehicle_id' => $vehicleId,
    'mechanic_id' => $mechanicId,
    'appointment_date' => $appointmentDate,
    'concern' => $concern,
];

if ($customerId <= 0 || $vehicleId <= 0 || $appointmentDate === '') {
    $_SESSION['appointment_error'] = 'Customer, vehicle, and appointment date are required.';
    header('Location: ../appointments.php');
    exit;
}

$dateTime = DateTime::createFromFormat('Y-m-d', $appointmentDate);
if (!$dateTime || $dateTime->format('Y-m-d') !== $appointmentDate) {
    $_SESSION['appointment_error'] = 'Please provide a valid appointment date.';
    header('Location: ../appointments.php');
    exit;
}

try {
    $pdo = Database::getInstance();

    $customerStmt = $pdo->prepare("
        SELECT customer_id, status
        FROM customers
        WHERE customer_id = :customer_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $customerStmt->execute([
        'customer_id' => $customerId,
        'tenant_id' => $tenantId,
    ]);

    $customer = $customerStmt->fetch();

    if (!$customer) {
        $_SESSION['appointment_error'] = 'The selected customer does not belong to this tenant.';
        header('Location: ../appointments.php');
        exit;
    }

    if (($customer['status'] ?? '') !== 'active') {
        $_SESSION['appointment_error'] = 'Appointments can only be created for active customers.';
        header('Location: ../appointments.php');
        exit;
    }

    $vehicleStmt = $pdo->prepare("
        SELECT vehicle_id, status
        FROM vehicles
        WHERE vehicle_id = :vehicle_id
          AND customer_id = :customer_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $vehicleStmt->execute([
        'vehicle_id' => $vehicleId,
        'customer_id' => $customerId,
        'tenant_id' => $tenantId,
    ]);

    $vehicle = $vehicleStmt->fetch();

    if (!$vehicle) {
        $_SESSION['appointment_error'] = 'The selected vehicle does not match the selected customer for this tenant.';
        header('Location: ../appointments.php');
        exit;
    }

    if (($vehicle['status'] ?? '') !== 'active') {
        $_SESSION['appointment_error'] = 'Appointments can only be created for active vehicles.';
        header('Location: ../appointments.php');
        exit;
    }

    if ($mechanicId > 0) {
        $mechanicStmt = $pdo->prepare("
            SELECT user_id
            FROM users
            WHERE user_id = :user_id
              AND tenant_id = :tenant_id
              AND role = 'mechanic'
              AND status = 'active'
            LIMIT 1
        ");
        $mechanicStmt->execute([
            'user_id' => $mechanicId,
            'tenant_id' => $tenantId,
        ]);

        if (!$mechanicStmt->fetch()) {
            $_SESSION['appointment_error'] = 'The assigned mechanic must be an active mechanic in this tenant.';
            header('Location: ../appointments.php');
            exit;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO appointments (
            tenant_id,
            customer_id,
            vehicle_id,
            mechanic_id,
            appointment_date,
            concern,
            status
        ) VALUES (
            :tenant_id,
            :customer_id,
            :vehicle_id,
            :mechanic_id,
            :appointment_date,
            :concern,
            'pending'
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'customer_id' => $customerId,
        'vehicle_id' => $vehicleId,
        'mechanic_id' => $mechanicId > 0 ? $mechanicId : null,
        'appointment_date' => $appointmentDate,
        'concern' => $concern !== '' ? $concern : null,
    ]);

    $appointmentId = (int) $pdo->lastInsertId();
    unset($_SESSION['appointment_old_input']);
    $_SESSION['appointment_success'] = 'Appointment created successfully.';
    header('Location: ../appointments.php?appointment_id=' . $appointmentId);
    exit;
} catch (PDOException $e) {
    $_SESSION['appointment_error'] = 'Appointment creation failed: ' . $e->getMessage();
    header('Location: ../appointments.php');
    exit;
}
?>
