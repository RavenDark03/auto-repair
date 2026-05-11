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
$status = $_POST['status'] ?? '';
$allowedStatuses = ['ongoing', 'completed'];

if ($jobId <= 0 || !in_array($status, $allowedStatuses, true)) {
    $_SESSION['job_error'] = 'A valid job status action is required.';
    header('Location: ../jobs.php');
    exit;
}

try {
    $pdo = Database::getInstance();

    $jobStmt = $pdo->prepare("
        SELECT j.job_id, j.status, i.invoice_id, a.status AS appointment_status
        FROM jobs j
        INNER JOIN appointments a
            ON a.appointment_id = j.appointment_id
           AND a.tenant_id = j.tenant_id
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

    if (($job['appointment_status'] ?? '') !== 'approved') {
        $_SESSION['job_error'] = 'Job status changes are blocked because the source appointment is no longer in an approved state.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    if ($job['status'] === $status) {
        $_SESSION['job_success'] = 'Job status is already ' . $status . '.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    if (!empty($job['invoice_id'])) {
        $_SESSION['job_error'] = 'Job status cannot be changed after an invoice has already been created for this job.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE jobs
        SET status = :status
        WHERE job_id = :job_id
          AND tenant_id = :tenant_id
    ");
    $stmt->execute([
        'status' => $status,
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
    ]);

    $_SESSION['job_success'] = 'Job status updated to ' . $status . '.';
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
} catch (PDOException $e) {
    $_SESSION['job_error'] = 'Job status update failed: ' . $e->getMessage();
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
}
?>
