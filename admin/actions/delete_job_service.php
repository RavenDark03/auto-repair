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

if ($jobId <= 0 || $jobServiceId <= 0) {
    $_SESSION['job_error'] = 'A valid job service removal request is required.';
    header('Location: ../jobs.php' . ($jobId > 0 ? '?job_id=' . $jobId : ''));
    exit;
}

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
        $_SESSION['job_error'] = 'Service lines can only be removed while a job is ongoing.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    if (!empty($job['invoice_id'])) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'Service lines cannot be changed after an invoice has already been created for this job.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $deleteStmt = $pdo->prepare("
        DELETE FROM job_services
        WHERE job_service_id = :job_service_id
          AND job_id = :job_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $deleteStmt->execute([
        'job_service_id' => $jobServiceId,
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
    ]);

    if ($deleteStmt->rowCount() !== 1) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'That service line could not be found for this tenant job.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $pdo->commit();
    $_SESSION['job_success'] = 'Service line removed successfully.';
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['job_error'] = 'Service line removal failed: ' . $e->getMessage();
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
}
?>
