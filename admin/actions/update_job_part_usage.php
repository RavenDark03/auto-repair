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
$inventoryId = (int) ($_POST['inventory_id'] ?? 0);
$quantityUsed = trim($_POST['quantity_used'] ?? '');

$_SESSION['job_part_edit_old_input'] = [
    'inventory_id' => $inventoryId,
    'quantity_used' => $quantityUsed,
];

if ($jobId <= 0 || $jobPartUsageId <= 0 || $inventoryId <= 0 || $quantityUsed === '') {
    $_SESSION['job_error'] = 'A valid parts usage update is required.';
    header('Location: ../jobs.php?job_id=' . $jobId . '&edit_part_usage_id=' . $jobPartUsageId);
    exit;
}

if (!ctype_digit($quantityUsed) || (int) $quantityUsed <= 0) {
    $_SESSION['job_error'] = 'Quantity used must be a whole number greater than zero.';
    header('Location: ../jobs.php?job_id=' . $jobId . '&edit_part_usage_id=' . $jobPartUsageId);
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
        $_SESSION['job_error'] = 'Parts usage can only be updated while a job is ongoing.';
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
    $existingUsage = $usageStmt->fetch();

    if (!$existingUsage) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'That parts usage record could not be found for this tenant job.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    $oldInventoryStmt = $pdo->prepare("
        SELECT inventory_id, quantity, status
        FROM inventory
        WHERE inventory_id = :inventory_id
          AND tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $oldInventoryStmt->execute([
        'inventory_id' => (int) $existingUsage['inventory_id'],
        'tenant_id' => $tenantId,
    ]);
    $oldInventory = $oldInventoryStmt->fetch();

    if (!$oldInventory) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'The original inventory item could not be found in this tenant.';
        header('Location: ../jobs.php?job_id=' . $jobId);
        exit;
    }

    if ((int) $existingUsage['inventory_id'] === $inventoryId) {
        $newInventory = $oldInventory;
    } else {
        $newInventoryStmt = $pdo->prepare("
            SELECT inventory_id, quantity, status
            FROM inventory
            WHERE inventory_id = :inventory_id
              AND tenant_id = :tenant_id
            LIMIT 1
            FOR UPDATE
        ");
        $newInventoryStmt->execute([
            'inventory_id' => $inventoryId,
            'tenant_id' => $tenantId,
        ]);
        $newInventory = $newInventoryStmt->fetch();
    }

    if (!$newInventory) {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'The selected inventory item could not be found in this tenant.';
        header('Location: ../jobs.php?job_id=' . $jobId . '&edit_part_usage_id=' . $jobPartUsageId);
        exit;
    }

    if ($newInventory['status'] !== 'active') {
        $pdo->rollBack();
        $_SESSION['job_error'] = 'Only active inventory items can be used in jobs.';
        header('Location: ../jobs.php?job_id=' . $jobId . '&edit_part_usage_id=' . $jobPartUsageId);
        exit;
    }

    $requestedQuantity = (int) $quantityUsed;
    $oldQuantity = (int) $existingUsage['quantity_used'];
    $oldInventoryId = (int) $existingUsage['inventory_id'];

    if ($inventoryId === $oldInventoryId) {
        $availableQuantity = (int) $newInventory['quantity'] + $oldQuantity;
        if ($availableQuantity < $requestedQuantity) {
            $pdo->rollBack();
            $_SESSION['job_error'] = 'Not enough stock is available for that inventory item.';
            header('Location: ../jobs.php?job_id=' . $jobId . '&edit_part_usage_id=' . $jobPartUsageId);
            exit;
        }

        $inventoryUpdateStmt = $pdo->prepare("
            UPDATE inventory
            SET quantity = :quantity
            WHERE inventory_id = :inventory_id
              AND tenant_id = :tenant_id
        ");
        $inventoryUpdateStmt->execute([
            'quantity' => $availableQuantity - $requestedQuantity,
            'inventory_id' => $inventoryId,
            'tenant_id' => $tenantId,
        ]);
    } else {
        if ((int) $newInventory['quantity'] < $requestedQuantity) {
            $pdo->rollBack();
            $_SESSION['job_error'] = 'Not enough stock is available for the newly selected inventory item.';
            header('Location: ../jobs.php?job_id=' . $jobId . '&edit_part_usage_id=' . $jobPartUsageId);
            exit;
        }

        $restoreOldInventoryStmt = $pdo->prepare("
            UPDATE inventory
            SET quantity = quantity + :quantity
            WHERE inventory_id = :inventory_id
              AND tenant_id = :tenant_id
        ");
        $restoreOldInventoryStmt->execute([
            'quantity' => $oldQuantity,
            'inventory_id' => $oldInventoryId,
            'tenant_id' => $tenantId,
        ]);

        $deductNewInventoryStmt = $pdo->prepare("
            UPDATE inventory
            SET quantity = quantity - :quantity
            WHERE inventory_id = :inventory_id
              AND tenant_id = :tenant_id
        ");
        $deductNewInventoryStmt->execute([
            'quantity' => $requestedQuantity,
            'inventory_id' => $inventoryId,
            'tenant_id' => $tenantId,
        ]);
    }

    $usageUpdateStmt = $pdo->prepare("
        UPDATE job_parts_used
        SET inventory_id = :inventory_id,
            quantity_used = :quantity_used
        WHERE id = :usage_id
          AND job_id = :job_id
          AND tenant_id = :tenant_id
    ");
    $usageUpdateStmt->execute([
        'inventory_id' => $inventoryId,
        'quantity_used' => $requestedQuantity,
        'usage_id' => $jobPartUsageId,
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
    ]);

    $pdo->commit();
    unset($_SESSION['job_part_edit_old_input']);
    $_SESSION['job_success'] = 'Parts usage updated successfully.';
    header('Location: ../jobs.php?job_id=' . $jobId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['job_error'] = 'Parts usage update failed: ' . $e->getMessage();
    header('Location: ../jobs.php?job_id=' . $jobId . '&edit_part_usage_id=' . $jobPartUsageId);
    exit;
}
?>
