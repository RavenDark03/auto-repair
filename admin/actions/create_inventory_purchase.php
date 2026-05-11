<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/feature_access.php';

requireAdmin();
requireTenantFeature('inventory', '../inventory.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../inventory.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$supplierId = (int) ($_POST['supplier_id'] ?? 0);
$inventoryId = (int) ($_POST['inventory_id'] ?? 0);
$referenceNo = trim($_POST['reference_no'] ?? '');
$purchaseDate = trim($_POST['purchase_date'] ?? '');
$dueDate = trim($_POST['due_date'] ?? '');
$quantity = trim($_POST['quantity'] ?? '');
$unitCost = trim($_POST['unit_cost'] ?? '');
$amountPaid = trim($_POST['amount_paid'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$_SESSION['purchase_old_input'] = [
    'supplier_id' => $supplierId,
    'inventory_id' => $inventoryId,
    'reference_no' => $referenceNo,
    'purchase_date' => $purchaseDate,
    'due_date' => $dueDate,
    'quantity' => $quantity,
    'unit_cost' => $unitCost,
    'amount_paid' => $amountPaid,
    'notes' => $notes,
];

if ($supplierId <= 0 || $inventoryId <= 0 || $purchaseDate === '' || $quantity === '' || $unitCost === '' || $amountPaid === '') {
    $_SESSION['purchase_error'] = 'Supplier, inventory item, purchase date, quantity, unit cost, and amount paid are required.';
    header('Location: ../inventory.php');
    exit;
}

$purchaseDateObj = DateTime::createFromFormat('Y-m-d', $purchaseDate);
$dueDateObj = $dueDate !== '' ? DateTime::createFromFormat('Y-m-d', $dueDate) : null;

if (!$purchaseDateObj || $purchaseDateObj->format('Y-m-d') !== $purchaseDate) {
    $_SESSION['purchase_error'] = 'Please provide a valid purchase date.';
    header('Location: ../inventory.php');
    exit;
}

if ($dueDate !== '' && (!$dueDateObj || $dueDateObj->format('Y-m-d') !== $dueDate)) {
    $_SESSION['purchase_error'] = 'Please provide a valid due date.';
    header('Location: ../inventory.php');
    exit;
}

if ($dueDate !== '' && $dueDate < $purchaseDate) {
    $_SESSION['purchase_error'] = 'Due date cannot be earlier than the purchase date.';
    header('Location: ../inventory.php');
    exit;
}

if (!ctype_digit($quantity) || (int) $quantity <= 0) {
    $_SESSION['purchase_error'] = 'Purchase quantity must be a valid whole number greater than zero.';
    header('Location: ../inventory.php');
    exit;
}

if (!is_numeric($unitCost) || (float) $unitCost < 0 || !is_numeric($amountPaid) || (float) $amountPaid < 0) {
    $_SESSION['purchase_error'] = 'Unit cost and amount paid must be valid non-negative amounts.';
    header('Location: ../inventory.php');
    exit;
}

$lineTotal = (float) $quantity * (float) $unitCost;
if ((float) $amountPaid > $lineTotal) {
    $_SESSION['purchase_error'] = 'Amount paid cannot be greater than the total purchase amount.';
    header('Location: ../inventory.php');
    exit;
}

$payableStatus = 'unpaid';
if ((float) $amountPaid > 0 && (float) $amountPaid < $lineTotal) {
    $payableStatus = 'partial';
} elseif ((float) $amountPaid >= $lineTotal && $lineTotal > 0) {
    $payableStatus = 'paid';
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $supplierStmt = $pdo->prepare("
        SELECT supplier_id, status
        FROM suppliers
        WHERE supplier_id = :supplier_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $supplierStmt->execute([
        'supplier_id' => $supplierId,
        'tenant_id' => $tenantId,
    ]);

    $supplier = $supplierStmt->fetch();

    if (!$supplier) {
        $pdo->rollBack();
        $_SESSION['purchase_error'] = 'The selected supplier does not belong to this tenant.';
        header('Location: ../inventory.php');
        exit;
    }

    if (($supplier['status'] ?? '') !== 'active') {
        $pdo->rollBack();
        $_SESSION['purchase_error'] = 'Stock purchases can only be recorded against active suppliers.';
        header('Location: ../inventory.php');
        exit;
    }

    $inventoryStmt = $pdo->prepare("
        SELECT inventory_id, status
        FROM inventory
        WHERE inventory_id = :inventory_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $inventoryStmt->execute([
        'inventory_id' => $inventoryId,
        'tenant_id' => $tenantId,
    ]);

    $inventory = $inventoryStmt->fetch();

    if (!$inventory) {
        $pdo->rollBack();
        $_SESSION['purchase_error'] = 'The selected inventory item does not belong to this tenant.';
        header('Location: ../inventory.php');
        exit;
    }

    if (($inventory['status'] ?? '') !== 'active') {
        $pdo->rollBack();
        $_SESSION['purchase_error'] = 'Stock purchases can only be recorded against active inventory items.';
        header('Location: ../inventory.php');
        exit;
    }

    $purchaseStmt = $pdo->prepare("
        INSERT INTO inventory_purchases (
            tenant_id,
            supplier_id,
            reference_no,
            purchase_date,
            due_date,
            total_amount,
            amount_paid,
            payable_status,
            notes
        ) VALUES (
            :tenant_id,
            :supplier_id,
            :reference_no,
            :purchase_date,
            :due_date,
            :total_amount,
            :amount_paid,
            :payable_status,
            :notes
        )
    ");
    $purchaseStmt->execute([
        'tenant_id' => $tenantId,
        'supplier_id' => $supplierId,
        'reference_no' => $referenceNo !== '' ? $referenceNo : null,
        'purchase_date' => $purchaseDate,
        'due_date' => $dueDate !== '' ? $dueDate : null,
        'total_amount' => number_format($lineTotal, 2, '.', ''),
        'amount_paid' => number_format((float) $amountPaid, 2, '.', ''),
        'payable_status' => $payableStatus,
        'notes' => $notes !== '' ? $notes : null,
    ]);

    $purchaseId = (int) $pdo->lastInsertId();

    $purchaseItemStmt = $pdo->prepare("
        INSERT INTO inventory_purchase_items (
            tenant_id,
            purchase_id,
            inventory_id,
            quantity,
            unit_cost,
            line_total
        ) VALUES (
            :tenant_id,
            :purchase_id,
            :inventory_id,
            :quantity,
            :unit_cost,
            :line_total
        )
    ");
    $purchaseItemStmt->execute([
        'tenant_id' => $tenantId,
        'purchase_id' => $purchaseId,
        'inventory_id' => $inventoryId,
        'quantity' => (int) $quantity,
        'unit_cost' => number_format((float) $unitCost, 2, '.', ''),
        'line_total' => number_format($lineTotal, 2, '.', ''),
    ]);

    $stockStmt = $pdo->prepare("
        UPDATE inventory
        SET quantity = quantity + :quantity,
            unit_cost = :unit_cost
        WHERE inventory_id = :inventory_id
          AND tenant_id = :tenant_id
    ");
    $stockStmt->execute([
        'quantity' => (int) $quantity,
        'unit_cost' => number_format((float) $unitCost, 2, '.', ''),
        'inventory_id' => $inventoryId,
        'tenant_id' => $tenantId,
    ]);

    if ((float) $amountPaid > 0) {
        $paymentStmt = $pdo->prepare("
            INSERT INTO supplier_payments (
                tenant_id,
                purchase_id,
                payment_date,
                amount,
                payment_method,
                reference_no,
                notes
            ) VALUES (
                :tenant_id,
                :purchase_id,
                :payment_date,
                :amount,
                'opening payment',
                :reference_no,
                :notes
            )
        ");
        $paymentStmt->execute([
            'tenant_id' => $tenantId,
            'purchase_id' => $purchaseId,
            'payment_date' => $purchaseDate,
            'amount' => number_format((float) $amountPaid, 2, '.', ''),
            'reference_no' => $referenceNo !== '' ? $referenceNo : null,
            'notes' => $notes !== '' ? $notes : null,
        ]);
    }

    $pdo->commit();
    unset($_SESSION['purchase_old_input']);
    $_SESSION['purchase_success'] = 'Stock purchase recorded successfully.';
    header('Location: ../inventory.php?purchase_id=' . $purchaseId . '&inventory_id=' . $inventoryId . '&supplier_id=' . $supplierId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['purchase_error'] = 'Stock purchase recording failed: ' . $e->getMessage();
    header('Location: ../inventory.php');
    exit;
}
?>
