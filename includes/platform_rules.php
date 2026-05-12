<?php

function currentTenantAccessMode(PDO $pdo, int $tenantId): string
{
    if ($tenantId <= 0) {
        return 'full_access';
    }

    $stmt = $pdo->prepare("
        SELECT access_mode
        FROM tenants
        WHERE tenant_id = :tenant_id
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $mode = (string) $stmt->fetchColumn();

    return $mode !== '' ? $mode : 'full_access';
}

function normalizePaymentReference(?string $reference): string
{
    $reference = strtoupper((string) $reference);
    return preg_replace('/[^A-Z0-9]/', '', $reference);
}

function evaluateNgReference(array $billingRequest, int $duplicateCount = 0): array
{
    $reference = (string) ($billingRequest['payment_reference'] ?? '');
    $normalized = normalizePaymentReference($reference);

    if ($normalized === '') {
        return [
            'status' => 'missing',
            'label' => 'Missing Ref',
            'class' => 'status-cancelled',
            'detail' => 'No NG reference has been recorded yet.',
        ];
    }

    if (!preg_match('/^NG[0-9]{6,20}$/', $normalized)) {
        return [
            'status' => 'invalid',
            'label' => 'Invalid Ref',
            'class' => 'status-warning',
            'detail' => 'The payment reference should start with NG followed by digits.',
        ];
    }

    if ($duplicateCount > 1) {
        return [
            'status' => 'duplicate',
            'label' => 'Duplicate Ref',
            'class' => 'status-rejected',
            'detail' => 'This NG reference appears on more than one billing request.',
        ];
    }

    $storedStatus = (string) ($billingRequest['payment_reference_check_status'] ?? '');

    if ($storedStatus === 'verified') {
        return [
            'status' => 'verified',
            'label' => 'Verified',
            'class' => 'status-approved',
            'detail' => 'This NG reference passed the latest superadmin review.',
        ];
    }

    return [
        'status' => 'ready',
        'label' => 'Ready To Verify',
        'class' => 'status-pending',
        'detail' => 'The NG reference format looks valid and can be confirmed.',
    ];
}

function syncInactiveCustomers(PDO $pdo, int $tenantId): int
{
    if ($tenantId <= 0) {
        return 0;
    }

    $updateStmt = $pdo->prepare("
        UPDATE customers c
        LEFT JOIN (
            SELECT
                customer_id,
                tenant_id,
                MAX(activity_date) AS last_activity_at
            FROM (
                SELECT customer_id, tenant_id, created_at AS activity_date
                FROM customers
                WHERE tenant_id = :tenant_id

                UNION ALL

                SELECT customer_id, tenant_id, CAST(appointment_date AS DATETIME) AS activity_date
                FROM appointments
                WHERE tenant_id = :tenant_id

                UNION ALL

                SELECT
                    a.customer_id,
                    a.tenant_id,
                    CAST(p.payment_date AS DATETIME) AS activity_date
                FROM payments p
                INNER JOIN invoices i
                    ON i.invoice_id = p.invoice_id
                   AND i.tenant_id = p.tenant_id
                INNER JOIN jobs j
                    ON j.job_id = i.job_id
                   AND j.tenant_id = i.tenant_id
                INNER JOIN appointments a
                    ON a.appointment_id = j.appointment_id
                   AND a.tenant_id = j.tenant_id
                WHERE p.tenant_id = :tenant_id

                UNION ALL

                SELECT
                    a.customer_id,
                    a.tenant_id,
                    i.created_at AS activity_date
                FROM invoices i
                INNER JOIN jobs j
                    ON j.job_id = i.job_id
                   AND j.tenant_id = i.tenant_id
                INNER JOIN appointments a
                    ON a.appointment_id = j.appointment_id
                   AND a.tenant_id = j.tenant_id
                WHERE i.tenant_id = :tenant_id
            ) activity_log
            GROUP BY customer_id, tenant_id
        ) recent_activity
            ON recent_activity.customer_id = c.customer_id
           AND recent_activity.tenant_id = c.tenant_id
        SET c.status = 'inactive'
        WHERE c.tenant_id = :tenant_id
          AND c.status = 'active'
          AND COALESCE(recent_activity.last_activity_at, c.created_at) < DATE_SUB(NOW(), INTERVAL 3 MONTH)
    ");
    $updateStmt->execute(['tenant_id' => $tenantId]);

    return $updateStmt->rowCount();
}

function getInventoryMovementSummary(PDO $pdo, int $tenantId): array
{
    $default = [
        'fast_moving' => 0,
        'slow_moving' => 0,
        'idle_items' => 0,
        'tracked_items' => 0,
        'items' => [],
    ];

    if ($tenantId <= 0) {
        return $default;
    }

    $stmt = $pdo->prepare("
        SELECT
            i.inventory_id,
            i.part_name,
            i.sku,
            i.quantity,
            i.reorder_level,
            i.status,
            COALESCE(SUM(CASE WHEN jsl.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN jpu.quantity_used ELSE 0 END), 0) AS used_last_30_days,
            COALESCE(SUM(CASE WHEN jsl.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN jpu.quantity_used ELSE 0 END), 0) AS used_last_90_days
        FROM inventory i
        LEFT JOIN job_parts_used jpu
            ON jpu.inventory_id = i.inventory_id
           AND jpu.tenant_id = i.tenant_id
        LEFT JOIN jobs j
            ON j.job_id = jpu.job_id
           AND j.tenant_id = jpu.tenant_id
        LEFT JOIN job_status_logs jsl
            ON jsl.job_id = j.job_id
           AND jsl.tenant_id = j.tenant_id
        WHERE i.tenant_id = :tenant_id
        GROUP BY i.inventory_id, i.part_name, i.sku, i.quantity, i.reorder_level, i.status
        ORDER BY used_last_30_days DESC, used_last_90_days DESC, i.part_name ASC
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $rows = $stmt->fetchAll();

    $summary = $default;
    $summary['tracked_items'] = count($rows);

    foreach ($rows as $row) {
        $used30 = (int) $row['used_last_30_days'];
        $used90 = (int) $row['used_last_90_days'];
        $band = 'steady';
        $term = 'Keep available';

        if ($used30 >= 8 || $used90 >= 20) {
            $band = 'fast';
            $term = ((int) $row['quantity'] <= (int) $row['reorder_level']) ? 'Restock now' : 'Prioritize availability';
            $summary['fast_moving']++;
        } elseif ($used90 === 0) {
            $band = 'idle';
            $term = 'Consider limited availability';
            $summary['idle_items']++;
        } elseif ($used30 <= 1) {
            $band = 'slow';
            $term = 'Review reorder pace';
            $summary['slow_moving']++;
        }

        $summary['items'][] = [
            'inventory_id' => (int) $row['inventory_id'],
            'part_name' => (string) $row['part_name'],
            'sku' => (string) ($row['sku'] ?? ''),
            'quantity' => (int) $row['quantity'],
            'reorder_level' => (int) $row['reorder_level'],
            'status' => (string) $row['status'],
            'used_last_30_days' => $used30,
            'used_last_90_days' => $used90,
            'movement_band' => $band,
            'availability_term' => $term,
        ];
    }

    return $summary;
}
