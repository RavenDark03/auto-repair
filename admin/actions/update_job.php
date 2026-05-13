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
$mechanicId = (int) ($_POST['mechanic_id'] ?? 0);
$status = normalizeJobStatus($_POST['status'] ?? '');
$priority = $_POST['priority'] ?? 'normal';
$description = trim($_POST['description'] ?? '');
$issueConcern = trim($_POST['issue_concern'] ?? '');
$customerVisibleNotes = trim($_POST['customer_visible_notes'] ?? '');
$progressNote = trim($_POST['progress_note'] ?? '');
$allowedStatuses = array_keys(getJobStatusOptions());
$allowedPriorities = ['low', 'normal', 'high', 'urgent'];

$_SESSION['job_old_input'] = [
    'mechanic_id' => $mechanicId,
    'status' => $status,
    'priority' => $priority,
    'description' => $description,
    'issue_concern' => $issueConcern,
    'customer_visible_notes' => $customerVisibleNotes,
    'progress_note' => $progressNote,
];

if ($jobId <= 0 || !in_array($status, $allowedStatuses, true)) {
    $_SESSION['job_error'] = 'A valid job update is required.';
    header('Location: ../jobs.php' . ($jobId > 0 ? '?job_id=' . $jobId : ''));
    exit;
}

if (!in_array($priority, $allowedPriorities, true)) {
    $_SESSION['job_error'] = 'Please choose a valid job priority.';
    header('Location: ../jobs.php?job_id=' . $jobId);
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
        $_SESSION['job_error'] = 'Job changes are blocked because the source appointment is no longer in an approved state.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $existingStatus = normalizeJobStatus($job['status'] ?? '');

    if (!empty($job['invoice_id']) && $status !== $existingStatus) {
        $_SESSION['job_error'] = 'Job status cannot be changed after an invoice has already been created for this job.';
        header('Location: ../jobs.php?job_id=' . $jobId);
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
            $_SESSION['job_error'] = 'The selected mechanic does not belong to this tenant or is not active.';
            header('Location: ../jobs.php?job_id=' . $jobId);
            exit;
        }
    }

    $stmt = $pdo->prepare("
        UPDATE jobs
        SET mechanic_id = :mechanic_id,
            priority = :priority,
            description = :description,
            issue_concern = :issue_concern,
            customer_visible_notes = :customer_visible_notes,
            status = :status,
            completed_at = CASE WHEN :status_completed = 'completed' THEN COALESCE(completed_at, NOW()) ELSE completed_at END,
            ready_for_invoice_at = CASE WHEN :status_ready = 'completed' THEN COALESCE(ready_for_invoice_at, NOW()) ELSE ready_for_invoice_at END
        WHERE job_id = :job_id
          AND tenant_id = :tenant_id
    ");
    $stmt->execute([
        'mechanic_id' => $mechanicId > 0 ? $mechanicId : null,
        'priority' => $priority,
        'description' => $description !== '' ? $description : null,
        'issue_concern' => $issueConcern !== '' ? $issueConcern : null,
        'customer_visible_notes' => $customerVisibleNotes !== '' ? $customerVisibleNotes : null,
        'status' => $status,
        'status_completed' => $status,
        'status_ready' => $status,
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
    ]);

    if ($progressNote !== '') {
        $noteStmt = $pdo->prepare("
            INSERT INTO job_notes (
                tenant_id,
                job_id,
                user_id,
                note_type,
                note,
                is_customer_visible
            ) VALUES (
                :tenant_id,
                :job_id,
                :user_id,
                'admin',
                :note,
                0
            )
        ");
        $noteStmt->execute([
            'tenant_id' => $tenantId,
            'job_id' => $jobId,
            'user_id' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
            'note' => $progressNote,
        ]);
    }

    unset($_SESSION['job_old_input']);
    $_SESSION['job_success'] = 'Job updated successfully.';
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
} catch (PDOException $e) {
    $_SESSION['job_error'] = 'Job update failed: ' . $e->getMessage();
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
}
?>
