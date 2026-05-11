<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/feature_access.php';

requireAdmin();
requireTenantFeature('jobs', '../jobs.php');
requireTenantFeature('inventory', '../jobs.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../jobs.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$jobId = (int) ($_POST['job_id'] ?? 0);
$jobPartUsageId = (int) ($_POST['job_part_usage_id'] ?? 0);

if ($jobId <= 0 || $jobPartUsageId <= 0) {
    $_SESSION['job_error'] = 'A valid parts usage removal request is required.';
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
        $_SESSION['job_error'] = 'Parts usage can only be removed while a job is ongoing.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    if (!empty($job['invoice_id'])) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'Parts usage cannot be changed after an invoice has already been created for this job.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $usageStmt = $pdo->prepare("
        SELECT id, inventory_id, quantity_used
        FROM job_parts_used
        WHERE id = :usage_id
          AND job_id = :job_id
          AND tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $usageStmt->execute([
        'usage_id' => $jobPartUsageId,
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
    ]);
    $usage = $usageStmt->fetch();

    if (!$usage) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'That parts usage record could not be found for this tenant job.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $inventoryStmt = $pdo->prepare("
        SELECT inventory_id
        FROM inventory
        WHERE inventory_id = :inventory_id
          AND tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $inventoryStmt->execute([
        'inventory_id' => $usage['inventory_id'],
        'tenant_id' => $tenantId,
    ]);

    if (!$inventoryStmt->fetch()) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'The linked inventory item could not be found in this tenant.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $deleteStmt = $pdo->prepare("
        DELETE FROM job_parts_used
        WHERE id = :usage_id
          AND job_id = :job_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $deleteStmt->execute([
        'usage_id' => $jobPartUsageId,
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
    ]);

    if ($deleteStmt->rowCount() !== 1) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'That parts usage record could not be removed.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $restoreStmt = $pdo->prepare("
        UPDATE inventory
        SET quantity = quantity + :quantity_used
        WHERE inventory_id = :inventory_id
          AND tenant_id = :tenant_id
    ");
    $restoreStmt->execute([
        'quantity_used' => (int) $usage['quantity_used'],
        'inventory_id' => (int) $usage['inventory_id'],
        'tenant_id' => $tenantId,
    ]);

    $pdo->commit();
    $_SESSION['job_success'] = 'Parts usage removed and stock restored successfully.';
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['job_error'] = 'Parts usage removal failed: ' . $e->getMessage();
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
}
?>
