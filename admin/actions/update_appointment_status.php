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
$cancellationReason = trim($_POST['cancellation_reason'] ?? '');
$allowedStatuses = ['pending', 'approved', 'cancelled'];

if ($appointmentId <= 0 || !in_array($status, $allowedStatuses, true)) {
    $_SESSION['appointment_error'] = 'A valid appointment status action is required.';
    header('Location: ../appointments.php');
    exit;
}

if ($status === 'cancelled' && $cancellationReason === '') {
    $_SESSION['appointment_error'] = 'A cancellation reason is required when cancelling an appointment.';
    header('Location: ../appointments.php?appointment_id=' . $appointmentId);
    exit;
}

try {
    $pdo = Database::getInstance();

    $existingStmt = $pdo->prepare("
        SELECT appointment_id, status, mechanic_id, concern
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
        SET status = :status,
            cancellation_reason = :cancellation_reason,
            cancelled_at = :cancelled_at,
            cancelled_by = :cancelled_by
        WHERE appointment_id = :appointment_id
          AND tenant_id = :tenant_id
    ");
    $stmt->execute([
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
            'mechanic_id' => !empty($existingAppointment['mechanic_id']) ? (int) $existingAppointment['mechanic_id'] : null,
            'concern' => (string) ($existingAppointment['concern'] ?? ''),
            'tenant_id' => $tenantId,
            'appointment_id' => $appointmentId,
        ]);
    }

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
