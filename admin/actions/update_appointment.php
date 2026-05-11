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
$appointmentId = (int) ($_POST['appointment_id'] ?? 0);
$customerId = (int) ($_POST['customer_id'] ?? 0);
$vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
$appointmentDate = trim($_POST['appointment_date'] ?? '');
$status = $_POST['status'] ?? '';
$allowedStatuses = ['pending', 'approved', 'cancelled'];

$_SESSION['appointment_old_input'] = [
    'customer_id' => $customerId,
    'vehicle_id' => $vehicleId,
    'appointment_date' => $appointmentDate,
    'status' => $status,
];

if ($appointmentId <= 0 || $customerId <= 0 || $vehicleId <= 0 || $appointmentDate === '') {
    $_SESSION['appointment_error'] = 'A valid appointment, customer, vehicle, and date are required.';
    header('Location: ../appointments.php' . ($appointmentId > 0 ? '?appointment_id=' . $appointmentId : ''));
    exit;
}

if (!in_array($status, $allowedStatuses, true)) {
    $_SESSION['appointment_error'] = 'Please choose a valid appointment status.';
    header('Location: ../appointments.php?appointment_id=' . $appointmentId);
    exit;
}

$dateTime = DateTime::createFromFormat('Y-m-d', $appointmentDate);
if (!$dateTime || $dateTime->format('Y-m-d') !== $appointmentDate) {
    $_SESSION['appointment_error'] = 'Please provide a valid appointment date.';
    header('Location: ../appointments.php?appointment_id=' . $appointmentId);
    exit;
}

try {
    $pdo = Database::getInstance();

    $existingStmt = $pdo->prepare("
        SELECT appointment_id, status
        FROM appointments
        WHERE appointment_id = :appointment_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $existingStmt->execute([
        'appointment_id' => $appointmentId,
        'tenant_id' => $tenantId,
    ]);
    $existingAppointment = $existingStmt->fetch();

    if (!$existingAppointment) {
        $_SESSION['appointment_error'] = 'That appointment could not be found in this tenant.';
        header('Location: ../appointments.php');
        exit;
    }

    if ($existingAppointment['status'] === 'approved' && $status !== 'approved') {
        $_SESSION['appointment_error'] = 'Approved appointments cannot be moved back to another status because a job may already exist.';
        header('Location: ../appointments.php?appointment_id=' . $appointmentId);
        exit;
    }

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
        header('Location: ../appointments.php?appointment_id=' . $appointmentId);
        exit;
    }

    if (($customer['status'] ?? '') !== 'active') {
        $_SESSION['appointment_error'] = 'Appointments can only be updated to active customers.';
        header('Location: ../appointments.php?appointment_id=' . $appointmentId);
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
        header('Location: ../appointments.php?appointment_id=' . $appointmentId);
        exit;
    }

    if (($vehicle['status'] ?? '') !== 'active') {
        $_SESSION['appointment_error'] = 'Appointments can only be updated to active vehicles.';
        header('Location: ../appointments.php?appointment_id=' . $appointmentId);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE appointments
        SET customer_id = :customer_id,
            vehicle_id = :vehicle_id,
            appointment_date = :appointment_date,
            status = :status
        WHERE appointment_id = :appointment_id
          AND tenant_id = :tenant_id
    ");
    $stmt->execute([
        'customer_id' => $customerId,
        'vehicle_id' => $vehicleId,
        'appointment_date' => $appointmentDate,
        'status' => $status,
        'appointment_id' => $appointmentId,
        'tenant_id' => $tenantId,
    ]);

    unset($_SESSION['appointment_old_input']);
    $_SESSION['appointment_success'] = $status === 'approved' && $existingAppointment['status'] !== 'approved'
        ? 'Appointment approved successfully. A job record should now be created automatically.'
        : 'Appointment updated successfully.';
    header('Location: ../appointments.php?appointment_id=' . $appointmentId);
    exit;
} catch (PDOException $e) {
    $_SESSION['appointment_error'] = 'Appointment update failed: ' . $e->getMessage();
    header('Location: ../appointments.php?appointment_id=' . $appointmentId);
    exit;
}
?>
