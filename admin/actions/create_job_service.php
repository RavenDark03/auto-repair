<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/feature_access.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAdmin();
requireTenantFeature('jobs', '../jobs.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../jobs.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$jobId = (int) ($_POST['job_id'] ?? 0);
$serviceName = trim($_POST['service_name'] ?? '');
$description = trim($_POST['description'] ?? '');
$quantity = trim($_POST['service_quantity'] ?? '');
$unitPrice = trim($_POST['service_unit_price'] ?? '');

$_SESSION['job_old_input'] = [
    'service_name' => $serviceName,
    'description' => $description,
    'service_quantity' => $quantity,
    'service_unit_price' => $unitPrice,
];

if ($jobId <= 0 || $serviceName === '' || $quantity === '' || $unitPrice === '') {
    $_SESSION['job_error'] = 'A valid job, service name, quantity, and unit price are required.';
    header('Location: ../jobs.php' . ($jobId > 0 ? '?job_id=' . $jobId : ''));
    exit;
}

if (!is_numeric($quantity) || (float) $quantity <= 0 || !is_numeric($unitPrice) || (float) $unitPrice < 0) {
    $_SESSION['job_error'] = 'Service quantity must be greater than zero and unit price must be a valid non-negative amount.';
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
}

$lineTotal = (float) $quantity * (float) $unitPrice;

try {
    $pdo = Database::getInstance();

    $jobStmt = $pdo->prepare("
        SELECT j.job_id, j.status, i.invoice_id
        FROM jobs j
        LEFT JOIN invoices i
            ON i.job_id = j.job_id
           AND i.tenant_id = j.tenant_id
        WHERE j.job_id = :job_id
          AND j.tenant_id = :tenant_id
        LIMIT 1
    ");
    $jobStmt->execute([
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
    ]);
    $job = $jobStmt->fetch();

    if (!$job) {
        $_SESSION['job_error'] = 'That job could not be found in this tenant.';
        header('Location: ../jobs.php');
        exit;
    }

    if (!isJobOpenStatus($job['status'])) {
        $_SESSION['job_error'] = 'Service lines can only be added while a job is still active.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    if (!empty($job['invoice_id'])) {
        $_SESSION['job_error'] = 'Service lines cannot be added after an invoice has already been created for this job.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO job_services (
            tenant_id,
            job_id,
            service_name,
            description,
            quantity,
            unit_price,
            line_total
        ) VALUES (
            :tenant_id,
            :job_id,
            :service_name,
            :description,
            :quantity,
            :unit_price,
            :line_total
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'job_id' => $jobId,
        'service_name' => $serviceName,
        'description' => $description !== '' ? $description : null,
        'quantity' => number_format((float) $quantity, 2, '.', ''),
        'unit_price' => number_format((float) $unitPrice, 2, '.', ''),
        'line_total' => number_format($lineTotal, 2, '.', ''),
    ]);

    unset($_SESSION['job_old_input']);
    $_SESSION['job_success'] = 'Service line added successfully.';
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
} catch (PDOException $e) {
    $_SESSION['job_error'] = 'Service line could not be added: ' . $e->getMessage();
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
}
?>
