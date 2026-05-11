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
$status = $_POST['status'] ?? '';
$allowedStatuses = ['active', 'inactive'];

if ($vehicleId <= 0 || !in_array($status, $allowedStatuses, true)) {
    $_SESSION['vehicle_error'] = 'A valid vehicle status action is required.';
    header('Location: ../vehicles.php');
    exit;
}

try {
    $pdo = Database::getInstance();

    $vehicleStmt = $pdo->prepare("
        SELECT vehicle_id, status
        FROM vehicles
        WHERE vehicle_id = :vehicle_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $vehicleStmt->execute([
        'vehicle_id' => $vehicleId,
        'tenant_id' => $tenantId,
    ]);
    $vehicle = $vehicleStmt->fetch();

    if (!$vehicle) {
        $_SESSION['vehicle_error'] = 'That vehicle could not be found in this tenant.';
        header('Location: ../vehicles.php');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE vehicles
        SET status = :status
        WHERE vehicle_id = :vehicle_id
          AND tenant_id = :tenant_id
    ");
    $stmt->execute([
        'status' => $status,
        'vehicle_id' => $vehicleId,
        'tenant_id' => $tenantId,
    ]);

    $warningSuffix = '';

    if ($status === 'inactive' && ($vehicle['status'] ?? '') !== 'inactive') {
        $dependencyStmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM appointments WHERE tenant_id = :tenant_id AND vehicle_id = :vehicle_id AND status IN ('pending', 'approved')) AS active_appointments,
                (
                    SELECT COUNT(*)
                    FROM jobs j
                    INNER JOIN appointments a
                        ON a.appointment_id = j.appointment_id
                       AND a.tenant_id = j.tenant_id
                    WHERE j.tenant_id = :tenant_id
                      AND a.vehicle_id = :vehicle_id
                      AND j.status = 'ongoing'
                ) AS ongoing_jobs,
                (
                    SELECT COALESCE(SUM(i.total - i.amount_paid), 0)
                    FROM invoices i
                    INNER JOIN jobs j
                        ON j.job_id = i.job_id
                       AND j.tenant_id = i.tenant_id
                    INNER JOIN appointments a
                        ON a.appointment_id = j.appointment_id
                       AND a.tenant_id = j.tenant_id
                    WHERE i.tenant_id = :tenant_id
                      AND a.vehicle_id = :vehicle_id
                      AND i.status IN ('unpaid', 'partial')
                ) AS outstanding_balance
        ");
        $dependencyStmt->execute([
            'tenant_id' => $tenantId,
            'vehicle_id' => $vehicleId,
        ]);
        $dependency = $dependencyStmt->fetch();

        $warningParts = [];
        if (!empty($dependency['active_appointments'])) {
            $warningParts[] = (int) $dependency['active_appointments'] . ' active appointment(s)';
        }
        if (!empty($dependency['ongoing_jobs'])) {
            $warningParts[] = (int) $dependency['ongoing_jobs'] . ' ongoing job(s)';
        }
        if (!empty($dependency['outstanding_balance']) && (float) $dependency['outstanding_balance'] > 0) {
            $warningParts[] = 'PHP ' . number_format((float) $dependency['outstanding_balance'], 2) . ' in open receivables';
        }

        if (!empty($warningParts)) {
            $warningSuffix = ' Warning: this vehicle still has ' . implode(', ', $warningParts) . '.';
        }
    }

    $_SESSION['vehicle_success'] = 'Vehicle status updated to ' . $status . '.' . $warningSuffix;
    header('Location: ../vehicles.php?vehicle_id=' . $vehicleId);
    exit;
} catch (PDOException $e) {
    $_SESSION['vehicle_error'] = 'Vehicle status update failed: ' . $e->getMessage();
    header('Location: ../vehicles.php?vehicle_id=' . $vehicleId);
    exit;
}
?>
