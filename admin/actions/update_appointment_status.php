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
$status = $_POST['status'] ?? '';
$allowedStatuses = ['pending', 'approved', 'cancelled'];

if ($appointmentId <= 0 || !in_array($status, $allowedStatuses, true)) {
    $_SESSION['appointment_error'] = 'A valid appointment status action is required.';
    header('Location: ../appointments.php');
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
        $_SESSION['appointment_error'] = 'Approved appointments cannot be moved back because a linked job may already exist.';
        header('Location: ../appointments.php?appointment_id=' . $appointmentId);
        exit;
    }

    if ($existingAppointment['status'] === $status) {
        $_SESSION['appointment_success'] = 'Appointment status is already ' . $status . '.';
        header('Location: ../appointments.php?appointment_id=' . $appointmentId);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE appointments
        SET status = :status
        WHERE appointment_id = :appointment_id
          AND tenant_id = :tenant_id
    ");
    $stmt->execute([
        'status' => $status,
        'appointment_id' => $appointmentId,
        'tenant_id' => $tenantId,
    ]);

    $_SESSION['appointment_success'] = $status === 'approved'
        ? 'Appointment approved successfully. A job record should now be created automatically.'
        : 'Appointment status updated to ' . $status . '.';
    header('Location: ../appointments.php?appointment_id=' . $appointmentId);
    exit;
} catch (PDOException $e) {
    $_SESSION['appointment_error'] = 'Appointment status update failed: ' . $e->getMessage();
    header('Location: ../appointments.php?appointment_id=' . $appointmentId);
    exit;
}
?>
