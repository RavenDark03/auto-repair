<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/feature_access.php';

requireTenantRoles(['admin', 'cashier']);
requireTenantFeature('invoicing', '../invoices.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../invoices.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$jobId = (int) ($_POST['job_id'] ?? 0);
$invoiceNo = trim($_POST['invoice_no'] ?? '');
$status = $_POST['status'] ?? 'draft';
$dueDate = trim($_POST['due_date'] ?? '');
$laborFee = trim($_POST['labor_fee'] ?? '0');
$inspectionFee = trim($_POST['inspection_fee'] ?? '0');
$taxRate = trim($_POST['tax_rate'] ?? '0');
$notes = trim($_POST['notes'] ?? '');
$allowedStatuses = ['draft', 'unpaid'];

$_SESSION['invoice_old_input'] = [
    'job_id' => $jobId,
    'invoice_no' => $invoiceNo,
    'status' => $status,
    'due_date' => $dueDate,
    'labor_fee' => $laborFee,
    'inspection_fee' => $inspectionFee,
    'tax_rate' => $taxRate,
    'notes' => $notes,
];

if ($jobId <= 0) {
    $_SESSION['invoice_error'] = 'A completed job is required.';
    header('Location: ../invoices.php');
    exit;
}

if (!in_array($status, $allowedStatuses, true)) {
    $_SESSION['invoice_error'] = 'Please choose a valid opening invoice status.';
    header('Location: ../invoices.php');
    exit;
}

if ($dueDate !== '') {
    $dueDateObj = DateTime::createFromFormat('Y-m-d', $dueDate);
    if (!$dueDateObj || $dueDateObj->format('Y-m-d') !== $dueDate) {
        $_SESSION['invoice_error'] = 'Please provide a valid due date.';
        header('Location: ../invoices.php');
        exit;
    }
}

if (
    !is_numeric($laborFee) || (float) $laborFee < 0
    || !is_numeric($inspectionFee) || (float) $inspectionFee < 0
    || !is_numeric($taxRate) || (float) $taxRate < 0 || (float) $taxRate > 100
) {
    $_SESSION['invoice_error'] = 'Labor fee, inspection fee, and tax rate must be valid non-negative values.';
    header('Location: ../invoices.php');
    exit;
}

$laborFeeAmount = (float) $laborFee;
$inspectionFeeAmount = (float) $inspectionFee;
$taxRateAmount = (float) $taxRate;

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $jobStmt = $pdo->prepare("
        SELECT job_id
        FROM jobs
        WHERE job_id = :job_id
          AND tenant_id = :tenant_id
          AND status = 'completed'
        LIMIT 1
        FOR UPDATE
    ");
    $jobStmt->execute([
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
    ]);

    if (!$jobStmt->fetch()) {
        $pdo->rollBack();
        $_SESSION['invoice_error'] = 'Only completed jobs in this tenant can be invoiced.';
        header('Location: ../invoices.php');
        exit;
    }

    $existingStmt = $pdo->prepare("
        SELECT invoice_id
        FROM invoices
        WHERE tenant_id = :tenant_id
          AND job_id = :job_id
        LIMIT 1
    ");
    $existingStmt->execute([
        'tenant_id' => $tenantId,
        'job_id' => $jobId,
    ]);
    $existingInvoiceId = $existingStmt->fetchColumn();

    if ($existingInvoiceId) {
        $pdo->rollBack();
        $_SESSION['invoice_error'] = 'That completed job already has an invoice.';
        header('Location: ../invoices.php?invoice_id=' . (int) $existingInvoiceId);
        exit;
    }

    if ($invoiceNo === '') {
        $invoiceNo = 'INV-' . $tenantId . '-' . date('YmdHis');
    }

    $invoiceNoCheckStmt = $pdo->prepare("
        SELECT invoice_id
        FROM invoices
        WHERE tenant_id = :tenant_id
          AND invoice_no = :invoice_no
        LIMIT 1
    ");
    $invoiceNoCheckStmt->execute([
        'tenant_id' => $tenantId,
        'invoice_no' => $invoiceNo,
    ]);

    if ($invoiceNoCheckStmt->fetch()) {
        $pdo->rollBack();
        $_SESSION['invoice_error'] = 'That invoice number is already being used in this tenant.';
        header('Location: ../invoices.php');
        exit;
    }

    $serviceItemsStmt = $pdo->prepare("
        SELECT
            job_service_id AS source_id,
            'service' AS item_type,
            service_name,
            description,
            quantity,
            unit_price,
            line_total
        FROM job_services
        WHERE tenant_id = :tenant_id
          AND job_id = :job_id
        ORDER BY job_service_id ASC
    ");
    $serviceItemsStmt->execute([
        'tenant_id' => $tenantId,
        'job_id' => $jobId,
    ]);
    $serviceItems = $serviceItemsStmt->fetchAll();

    $partItemsStmt = $pdo->prepare("
        SELECT
            jpu.id AS source_id,
            'part' AS item_type,
            i.part_name,
            i.sku,
            jpu.quantity_used AS quantity,
            i.unit_cost AS unit_price,
            (jpu.quantity_used * i.unit_cost) AS line_total
        FROM job_parts_used jpu
        INNER JOIN inventory i
            ON i.inventory_id = jpu.inventory_id
           AND i.tenant_id = jpu.tenant_id
        WHERE jpu.tenant_id = :tenant_id
          AND jpu.job_id = :job_id
        ORDER BY jpu.id ASC
    ");
    $partItemsStmt->execute([
        'tenant_id' => $tenantId,
        'job_id' => $jobId,
    ]);
    $partItems = $partItemsStmt->fetchAll();

    $invoiceItems = [];
    $servicesSubtotal = 0.0;
    $partsSubtotal = 0.0;

    foreach ($serviceItems as $serviceItem) {
        $description = $serviceItem['service_name'];
        if (!empty($serviceItem['description'])) {
            $description .= ' - ' . $serviceItem['description'];
        }

        $lineTotal = (float) $serviceItem['line_total'];
        $invoiceItems[] = [
            'item_type' => 'service',
            'source_id' => (int) $serviceItem['source_id'],
            'description' => $description,
            'quantity' => number_format((float) $serviceItem['quantity'], 2, '.', ''),
            'unit_price' => number_format((float) $serviceItem['unit_price'], 2, '.', ''),
            'line_total' => number_format($lineTotal, 2, '.', ''),
        ];
        $servicesSubtotal += $lineTotal;
    }

    foreach ($partItems as $partItem) {
        $description = $partItem['part_name'];
        if (!empty($partItem['sku'])) {
            $description .= ' - ' . $partItem['sku'];
        }

        $lineTotal = (float) $partItem['line_total'];
        $invoiceItems[] = [
            'item_type' => 'part',
            'source_id' => (int) $partItem['source_id'],
            'description' => $description,
            'quantity' => number_format((float) $partItem['quantity'], 2, '.', ''),
            'unit_price' => number_format((float) $partItem['unit_price'], 2, '.', ''),
            'line_total' => number_format($lineTotal, 2, '.', ''),
        ];
        $partsSubtotal += $lineTotal;
    }

    if ($laborFeeAmount > 0) {
        $invoiceItems[] = [
            'item_type' => 'manual',
            'source_id' => null,
            'description' => 'Labor fee',
            'quantity' => '1.00',
            'unit_price' => number_format($laborFeeAmount, 2, '.', ''),
            'line_total' => number_format($laborFeeAmount, 2, '.', ''),
        ];
    }

    if ($inspectionFeeAmount > 0) {
        $invoiceItems[] = [
            'item_type' => 'manual',
            'source_id' => null,
            'description' => 'Inspection fee',
            'quantity' => '1.00',
            'unit_price' => number_format($inspectionFeeAmount, 2, '.', ''),
            'line_total' => number_format($inspectionFeeAmount, 2, '.', ''),
        ];
    }

    $subtotal = $servicesSubtotal + $partsSubtotal + $laborFeeAmount + $inspectionFeeAmount;
    $taxAmount = $subtotal * ($taxRateAmount / 100);
    $total = $subtotal + $taxAmount;

    if (empty($invoiceItems) || $total <= 0) {
        $pdo->rollBack();
        $_SESSION['invoice_error'] = 'This invoice has no billable service lines, parts, labor, inspection fee, or tax total yet.';
        header('Location: ../invoices.php');
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO invoices (
            tenant_id,
            job_id,
            invoice_no,
            services_subtotal,
            parts_subtotal,
            labor_fee,
            inspection_fee,
            subtotal,
            tax_rate,
            tax_amount,
            total,
            status,
            due_date,
            amount_paid,
            notes
        ) VALUES (
            :tenant_id,
            :job_id,
            :invoice_no,
            :services_subtotal,
            :parts_subtotal,
            :labor_fee,
            :inspection_fee,
            :subtotal,
            :tax_rate,
            :tax_amount,
            :total,
            :status,
            :due_date,
            0.00,
            :notes
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'job_id' => $jobId,
        'invoice_no' => $invoiceNo,
        'services_subtotal' => number_format($servicesSubtotal, 2, '.', ''),
        'parts_subtotal' => number_format($partsSubtotal, 2, '.', ''),
        'labor_fee' => number_format($laborFeeAmount, 2, '.', ''),
        'inspection_fee' => number_format($inspectionFeeAmount, 2, '.', ''),
        'subtotal' => number_format($subtotal, 2, '.', ''),
        'tax_rate' => number_format($taxRateAmount, 2, '.', ''),
        'tax_amount' => number_format($taxAmount, 2, '.', ''),
        'total' => number_format($total, 2, '.', ''),
        'status' => $status,
        'due_date' => $dueDate !== '' ? $dueDate : null,
        'notes' => $notes !== '' ? $notes : null,
    ]);

    $invoiceId = (int) $pdo->lastInsertId();

    $itemInsertStmt = $pdo->prepare("
        INSERT INTO invoice_items (
            tenant_id,
            invoice_id,
            item_type,
            source_id,
            description,
            quantity,
            unit_price,
            line_total
        ) VALUES (
            :tenant_id,
            :invoice_id,
            :item_type,
            :source_id,
            :description,
            :quantity,
            :unit_price,
            :line_total
        )
    ");

    foreach ($invoiceItems as $invoiceItem) {
        $itemInsertStmt->execute([
            'tenant_id' => $tenantId,
            'invoice_id' => $invoiceId,
            'item_type' => $invoiceItem['item_type'],
            'source_id' => $invoiceItem['source_id'],
            'description' => $invoiceItem['description'],
            'quantity' => $invoiceItem['quantity'],
            'unit_price' => $invoiceItem['unit_price'],
            'line_total' => $invoiceItem['line_total'],
        ]);
    }

    $pdo->commit();

    unset($_SESSION['invoice_old_input']);
    $_SESSION['invoice_success'] = 'Invoice created successfully with ' . count($invoiceItems) . ' line item(s).';
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['invoice_error'] = 'Invoice creation failed: ' . $e->getMessage();
    header('Location: ../invoices.php');
    exit;
}
?>
