<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/bearer.php';
require_once __DIR__ . '/../includes/tenant_features_api.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    api_error('method_not_allowed', 'Use GET or POST.', 405);
}

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'mechanic') {
    api_error('forbidden', 'Mechanic role required.', 403);
}

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'jobs') || !api_tenant_has_feature($pdo, $ctx['tenant_id'], 'inventory')) {
    api_error('feature_disabled', 'Jobs and inventory modules are required.', 403);
}

$body = $method === 'POST' ? api_input_json() : [];
$jobId = (int) ($body['job_id'] ?? $body['jobId'] ?? $_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    api_error('validation_error', 'job_id is required.', 422);
}

if ($method === 'GET') {
    $partsStmt = $pdo->prepare('
        SELECT jpu.id, i.inventory_id, i.part_name, i.sku, jpu.quantity_used, i.unit_cost, jpu.created_at
        FROM job_parts_used jpu
        INNER JOIN inventory i
            ON i.inventory_id = jpu.inventory_id
           AND i.tenant_id = jpu.tenant_id
        INNER JOIN jobs j
            ON j.job_id = jpu.job_id
           AND j.tenant_id = jpu.tenant_id
        WHERE jpu.tenant_id = :tid
          AND jpu.job_id = :jid
          AND j.mechanic_id = :mid
        ORDER BY jpu.id DESC
    ');
    $partsStmt->execute(['tid' => $ctx['tenant_id'], 'jid' => $jobId, 'mid' => $ctx['user_id']]);
    $items = array_map(static function (array $part): array {
        $quantity = (int) $part['quantity_used'];
        $unitCost = (float) $part['unit_cost'];
        return [
            'id' => (string) $part['id'],
            'inventoryId' => (string) $part['inventory_id'],
            'name' => (string) $part['part_name'],
            'sku' => (string) ($part['sku'] ?? ''),
            'quantity' => $quantity,
            'unitCost' => $unitCost,
            'lineTotal' => $quantity * $unitCost,
            'createdAt' => (string) $part['created_at'],
        ];
    }, $partsStmt->fetchAll(PDO::FETCH_ASSOC));

    api_json(['ok' => true, 'items' => $items]);
}

$inventoryId = (int) ($body['inventory_id'] ?? $body['inventoryId'] ?? 0);
$quantityUsed = (int) ($body['quantity'] ?? $body['quantityUsed'] ?? 0);

if ($inventoryId <= 0 || $quantityUsed <= 0) {
    api_error('validation_error', 'inventoryId and quantity are required.', 422);
}

try {
    $pdo->beginTransaction();

    $jobStmt = $pdo->prepare('
        SELECT j.job_id, j.status, i.invoice_id, a.status AS appointment_status
        FROM jobs j
        INNER JOIN appointments a
            ON a.appointment_id = j.appointment_id
           AND a.tenant_id = j.tenant_id
        LEFT JOIN invoices i
            ON i.job_id = j.job_id
           AND i.tenant_id = j.tenant_id
        WHERE j.job_id = :jid
          AND j.tenant_id = :tid
          AND j.mechanic_id = :mid
        LIMIT 1
        FOR UPDATE
    ');
    $jobStmt->execute(['jid' => $jobId, 'tid' => $ctx['tenant_id'], 'mid' => $ctx['user_id']]);
    $job = $jobStmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        $pdo->rollBack();
        api_error('not_found', 'Job not found or not assigned to you.', 404);
    }

    if (($job['appointment_status'] ?? '') !== 'approved') {
        $pdo->rollBack();
        api_error('appointment_not_approved', 'Parts cannot be added until the appointment is approved.', 409);
    }

    if (!in_array((string) $job['status'], ['pending_inspection', 'in_repair', 'waiting_for_parts', 'ongoing'], true)) {
        $pdo->rollBack();
        api_error('job_not_active', 'Parts can only be added while a job is active.', 409);
    }

    if (!empty($job['invoice_id'])) {
        $pdo->rollBack();
        api_error('invoice_exists', 'Parts cannot be added after an invoice exists for this job.', 409);
    }

    $inventoryStmt = $pdo->prepare('
        SELECT inventory_id, quantity, status
        FROM inventory
        WHERE tenant_id = :tid
          AND inventory_id = :iid
        LIMIT 1
        FOR UPDATE
    ');
    $inventoryStmt->execute(['tid' => $ctx['tenant_id'], 'iid' => $inventoryId]);
    $inventory = $inventoryStmt->fetch(PDO::FETCH_ASSOC);

    if (!$inventory || ($inventory['status'] ?? '') !== 'active') {
        $pdo->rollBack();
        api_error('inventory_not_found', 'Inventory item not found or inactive.', 404);
    }

    if ((int) $inventory['quantity'] < $quantityUsed) {
        $pdo->rollBack();
        api_error('insufficient_stock', 'Not enough stock is available.', 409);
    }

    $ins = $pdo->prepare('
        INSERT INTO job_parts_used (tenant_id, job_id, inventory_id, quantity_used, created_by)
        VALUES (:tid, :jid, :iid, :qty, :uid)
    ');
    $ins->execute([
        'tid' => $ctx['tenant_id'],
        'jid' => $jobId,
        'iid' => $inventoryId,
        'qty' => $quantityUsed,
        'uid' => $ctx['user_id'],
    ]);
    $usageId = (int) $pdo->lastInsertId();

    $movement = $pdo->prepare('
        INSERT INTO inventory_movements (
            tenant_id, inventory_id, job_id, user_id, movement_type, quantity_change,
            quantity_before, quantity_after, reference_type, reference_id, notes
        ) VALUES (
            :tid, :iid, :jid, :uid, \'deduct\', :change_qty,
            :before_qty, :after_qty, \'job_part_usage\', :rid, :notes
        )
    ');
    $movement->execute([
        'tid' => $ctx['tenant_id'],
        'iid' => $inventoryId,
        'jid' => $jobId,
        'uid' => $ctx['user_id'],
        'change_qty' => -1 * $quantityUsed,
        'before_qty' => (int) $inventory['quantity'],
        'after_qty' => (int) $inventory['quantity'] - $quantityUsed,
        'rid' => $usageId,
        'notes' => 'Mechanic mobile parts usage on job #' . $jobId,
    ]);

    $pdo->commit();
    api_json(['ok' => true, 'id' => (string) $usageId], 201);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error('server_error', 'Parts usage could not be saved.', 500);
}
