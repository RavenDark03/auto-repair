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
$mechanicId = (int) ($_POST['mechanic_id'] ?? 0);
$appointmentDate = trim($_POST['appointment_date'] ?? '');
$concern = trim($_POST['concern'] ?? '');
$cancellationReason = trim($_POST['cancellation_reason'] ?? '');
$status = $_POST['status'] ?? '';
$allowedStatuses = ['pending', 'approved', 'cancelled'];

$_SESSION['appointment_old_input'] = [
    'customer_id' => $customerId,
    'vehicle_id' => $vehicleId,
    'mechanic_id' => $mechanicId,
    'appointment_date' => $appointmentDate,
    'concern' => $concern,
    'cancellation_reason' => $cancellationReason,
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

if ($status === 'cancelled' && $cancellationReason === '') {
    $_SESSION['appointment_error'] = 'A cancellation reason is required when cancelling an appointment.';
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
            header('Location: ../appointments.php?appointment_id=' . $appointmentId);
            exit;
        }
    }

    $stmt = $pdo->prepare("
        UPDATE appointments
        SET customer_id = :customer_id,
            vehicle_id = :vehicle_id,
            mechanic_id = :mechanic_id,
            appointment_date = :appointment_date,
            concern = :concern,
            status = :status,
            cancellation_reason = :cancellation_reason,
            cancelled_at = :cancelled_at,
            cancelled_by = :cancelled_by
        WHERE appointment_id = :appointment_id
          AND tenant_id = :tenant_id
    ");
    $stmt->execute([
        'customer_id' => $customerId,
        'vehicle_id' => $vehicleId,
        'mechanic_id' => $mechanicId > 0 ? $mechanicId : null,
        'appointment_date' => $appointmentDate,
        'concern' => $concern !== '' ? $concern : null,
        'status' => $status,
        'cancellation_reason' => $status === 'cancelled' ? $cancellationReason : null,
        'cancelled_at' => $status === 'cancelled' ? date('Y-m-d H:i:s') : null,
        'cancelled_by' => $status === 'cancelled' ? (int) ($_SESSION['user_id'] ?? 0) : null,
        'appointment_id' => $appointmentId,
        'tenant_id' => $tenantId,
    ]);

    if ($status === 'approved') {
        $jobUpdateStmt = $pdo->prepare("
            UPDATE jobs
            SET mechanic_id = :mechanic_id,
                issue_concern = COALESCE(NULLIF(:concern, ''), issue_concern)
            WHERE tenant_id = :tenant_id
              AND appointment_id = :appointment_id
        ");
        $jobUpdateStmt->execute([
            'mechanic_id' => $mechanicId > 0 ? $mechanicId : null,
            'concern' => $concern,
            'tenant_id' => $tenantId,
            'appointment_id' => $appointmentId,
        ]);
    }

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
