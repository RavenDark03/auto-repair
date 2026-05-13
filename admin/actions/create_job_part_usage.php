<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/feature_access.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAdmin();
requireTenantFeature('jobs', '../jobs.php');
requireTenantFeature('inventory', '../jobs.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../jobs.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$jobId = (int) ($_POST['job_id'] ?? 0);
$inventoryId = (int) ($_POST['inventory_id'] ?? 0);
$quantityUsed = trim($_POST['quantity_used'] ?? '');

$_SESSION['job_old_input'] = [
    'inventory_id' => $inventoryId,
    'quantity_used' => $quantityUsed,
];

if ($jobId <= 0 || $inventoryId <= 0 || $quantityUsed === '') {
    $_SESSION['job_error'] = 'A valid job, inventory item, and quantity are required.';
    header('Location: ../jobs.php' . ($jobId > 0 ? '?job_id=' . $jobId : ''));
    exit;
}

if (!ctype_digit($quantityUsed) || (int) $quantityUsed <= 0) {
    $_SESSION['job_error'] = 'Quantity used must be a whole number greater than zero.';
    header('Location: ../jobs.php?job_id=' . $jobId);
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

    if (!isJobOpenStatus($job['status'])) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'Parts can only be added while a job is still active.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    if (!empty($job['invoice_id'])) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'Parts usage cannot be added after an invoice has already been created for this job.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $inventoryStmt = $pdo->prepare("
        SELECT inventory_id, quantity, status
        FROM inventory
        WHERE inventory_id = :inventory_id
          AND tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $inventoryStmt->execute([
        'inventory_id' => $inventoryId,
        'tenant_id' => $tenantId,
    ]);
    $inventory = $inventoryStmt->fetch();

    if (!$inventory) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'That inventory item could not be found in this tenant.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    if ($inventory['status'] !== 'active') {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'Only active inventory items can be used in jobs.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    if ((int) $inventory['quantity'] < (int) $quantityUsed) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'Not enough stock is available for that inventory item.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $quantityBefore = (int) $inventory['quantity'];
    $quantityAfter = $quantityBefore - (int) $quantityUsed;

    $insertStmt = $pdo->prepare("
        INSERT INTO job_parts_used (
            tenant_id,
            job_id,
            inventory_id,
            quantity_used,
            created_by
        ) VALUES (
            :tenant_id,
            :job_id,
            :inventory_id,
            :quantity_used,
            :created_by
        )
    ");
    $insertStmt->execute([
        'tenant_id' => $tenantId,
        'job_id' => $jobId,
        'inventory_id' => $inventoryId,
        'quantity_used' => (int) $quantityUsed,
        'created_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
    ]);

    $usageId = (int) $pdo->lastInsertId();

    $movementStmt = $pdo->prepare("
        INSERT INTO inventory_movements (
            tenant_id,
            inventory_id,
            job_id,
            user_id,
            movement_type,
            quantity_change,
            quantity_before,
            quantity_after,
            reference_type,
            reference_id,
            notes
        ) VALUES (
            :tenant_id,
            :inventory_id,
            :job_id,
            :user_id,
            'deduct',
            :quantity_change,
            :quantity_before,
            :quantity_after,
            'job_part_usage',
            :reference_id,
            :notes
        )
    ");
    $movementStmt->execute([
        'tenant_id' => $tenantId,
        'inventory_id' => $inventoryId,
        'job_id' => $jobId,
        'user_id' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
        'quantity_change' => -1 * (int) $quantityUsed,
        'quantity_before' => $quantityBefore,
        'quantity_after' => $quantityAfter,
        'reference_id' => $usageId,
        'notes' => 'Deducted from job #' . $jobId,
    ]);

    $updatedInventoryStmt = $pdo->prepare("
        SELECT quantity
        FROM inventory
        WHERE inventory_id = :inventory_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $updatedInventoryStmt->execute([
        'inventory_id' => $inventoryId,
        'tenant_id' => $tenantId,
    ]);
    $remainingStock = (int) $updatedInventoryStmt->fetchColumn();

    $pdo->commit();
    unset($_SESSION['job_old_input']);
    $_SESSION['job_success'] = 'Parts usage recorded successfully. Remaining stock: ' . $remainingStock . '.';
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['job_error'] = 'Parts usage could not be recorded: ' . $e->getMessage();
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
}
?>
