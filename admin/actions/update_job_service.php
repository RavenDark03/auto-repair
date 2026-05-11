<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/feature_access.php';

requireAdmin();
requireTenantFeature('jobs', '../jobs.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../jobs.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$jobId = (int) ($_POST['job_id'] ?? 0);
$jobServiceId = (int) ($_POST['job_service_id'] ?? 0);
$serviceName = trim($_POST['service_name'] ?? '');
$description = trim($_POST['description'] ?? '');
$quantity = trim($_POST['service_quantity'] ?? '');
$unitPrice = trim($_POST['service_unit_price'] ?? '');

$_SESSION['job_service_edit_old_input'] = [
    'service_name' => $serviceName,
    'description' => $description,
    'service_quantity' => $quantity,
    'service_unit_price' => $unitPrice,
];

if ($jobId <= 0 || $jobServiceId <= 0 || $serviceName === '' || $quantity === '' || $unitPrice === '') {
    $_SESSION['job_error'] = 'A valid service line update is required.';
    header('Location: ../jobs.php?job_id=' . $jobId . '&edit_service_id=' . $jobServiceId);
    exit;
}

if (!is_numeric($quantity) || (float) $quantity <= 0 || !is_numeric($unitPrice) || (float) $unitPrice < 0) {
    $_SESSION['job_error'] = 'Service quantity must be greater than zero and unit price must be a valid non-negative amount.';
    header('Location: ../jobs.php?job_id=' . $jobId . '&edit_service_id=' . $jobServiceId);
    exit;
}

$lineTotal = (float) $quantity * (float) $unitPrice;

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $jobStmt = $pdo->prepare("
        SELECT j.job_id, j.status, i.invoice_id
        FROM jobs j
        LEFT JOIN invoices i
            ON i.job_id = j.job_id
           AND i.tenant_id = j.tenant_id
        WHERE j.job_id = :job_id
          AND j.tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $jobStmt->execute([
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
    ]);
    $job = $jobStmt->fetch();

    if (!$job) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'That job could not be found in this tenant.';
        header('Location: ../jobs.php');
        exit;
    }

    if ($job['status'] !== 'ongoing') {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'Service lines can only be updated while a job is ongoing.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    if (!empty($job['invoice_id'])) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'Service lines cannot be changed after an invoice has already been created for this job.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $serviceStmt = $pdo->prepare("
        SELECT job_service_id
        FROM job_services
        WHERE job_service_id = :job_service_id
          AND job_id = :job_id
          AND tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $serviceStmt->execute([
        'job_service_id' => $jobServiceId,
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
    ]);

    if (!$serviceStmt->fetch()) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'That service line could not be found for this tenant job.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $updateStmt = $pdo->prepare("
        UPDATE job_services
        SET service_name = :service_name,
            description = :description,
            quantity = :quantity,
            unit_price = :unit_price,
            line_total = :line_total
        WHERE job_service_id = :job_service_id
          AND job_id = :job_id
          AND tenant_id = :tenant_id
    ");
    $updateStmt->execute([
        'service_name' => $serviceName,
        'description' => $description !== '' ? $description : null,
        'quantity' => number_format((float) $quantity, 2, '.', ''),
        'unit_price' => number_format((float) $unitPrice, 2, '.', ''),
        'line_total' => number_format($lineTotal, 2, '.', ''),
        'job_service_id' => $jobServiceId,
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
    ]);

    $pdo->commit();
    unset($_SESSION['job_service_edit_old_input']);
    $_SESSION['job_success'] = 'Service line updated successfully.';
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['job_error'] = 'Service line update failed: ' . $e->getMessage();
    header('Location: ../jobs.php?job_id=' . $jobId . '&edit_service_id=' . $jobServiceId);
    exit;
}
?>
