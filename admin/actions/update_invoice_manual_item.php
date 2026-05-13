<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/feature_access.php';

requireAdmin();
requireTenantFeature('invoicing', '../invoices.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../invoices.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$invoiceItemId = (int) ($_POST['invoice_item_id'] ?? 0);
$description = trim($_POST['description'] ?? '');
$quantity = trim($_POST['quantity'] ?? '');
$unitPrice = trim($_POST['unit_price'] ?? '');

$_SESSION['invoice_item_edit_old_input'] = [
    'description' => $description,
    'quantity' => $quantity,
    'unit_price' => $unitPrice,
];

if ($invoiceId <= 0 || $invoiceItemId <= 0 || $description === '' || $quantity === '' || $unitPrice === '') {
    $_SESSION['invoice_item_error'] = 'A valid manual invoice item update is required.';
    header('Location: ../invoices.php?invoice_id=' . $invoiceId . '&edit_invoice_item_id=' . $invoiceItemId);
    exit;
}

if (!is_numeric($quantity) || (float) $quantity <= 0 || !is_numeric($unitPrice)) {
    $_SESSION['invoice_item_error'] = 'Quantity must be greater than zero and unit price must be a valid amount.';
    header('Location: ../invoices.php?invoice_id=' . $invoiceId . '&edit_invoice_item_id=' . $invoiceItemId);
    exit;
}

$newLineTotal = (float) $quantity * (float) $unitPrice;

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $invoiceStmt = $pdo->prepare("
        SELECT invoice_id, subtotal, tax_rate, total, amount_paid, status
        FROM invoices
        WHERE invoice_id = :invoice_id
          AND tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $invoiceStmt->execute([
        'invoice_id' => $invoiceId,
        'tenant_id' => $tenantId,
    ]);
    $invoice = $invoiceStmt->fetch();

    if (!$invoice) {
        $pdo->rollBack();
        $_SESSION['invoice_item_error'] = 'That invoice could not be found in this tenant.';
        header('Location: ../invoices.php');
        exit;
    }

    if (in_array($invoice['status'], ['paid', 'cancelled'], true)) {
        $pdo->rollBack();
        $_SESSION['invoice_item_error'] = 'Manual invoice items cannot be edited on paid or cancelled invoices.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    $itemStmt = $pdo->prepare("
        SELECT invoice_item_id, item_type, line_total
        FROM invoice_items
        WHERE invoice_item_id = :invoice_item_id
          AND invoice_id = :invoice_id
          AND tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $itemStmt->execute([
        'invoice_item_id' => $invoiceItemId,
        'invoice_id' => $invoiceId,
        'tenant_id' => $tenantId,
    ]);
    $item = $itemStmt->fetch();

    if (!$item) {
        $pdo->rollBack();
        $_SESSION['invoice_item_error'] = 'That invoice item could not be found in this tenant invoice.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    if ($item['item_type'] !== 'manual') {
        $pdo->rollBack();
        $_SESSION['invoice_item_error'] = 'Only manual invoice items can be edited directly from the invoice screen.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    $baseSubtotal = (float) ($invoice['subtotal'] ?? 0) > 0 ? (float) $invoice['subtotal'] : (float) $invoice['total'];
    $newSubtotal = $baseSubtotal - (float) $item['line_total'] + $newLineTotal;
    $newTaxAmount = $newSubtotal * ((float) ($invoice['tax_rate'] ?? 0) / 100);
    $newTotal = $newSubtotal + $newTaxAmount;
    if ($newTotal <= 0) {
        $pdo->rollBack();
        $_SESSION['invoice_item_error'] = 'This change would make the invoice total zero or negative.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId . '&edit_invoice_item_id=' . $invoiceItemId);
        exit;
    }

    if ($newTotal + 0.00001 < (float) $invoice['amount_paid']) {
        $pdo->rollBack();
        $_SESSION['invoice_item_error'] = 'This change would make the invoice total lower than the amount already paid.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId . '&edit_invoice_item_id=' . $invoiceItemId);
        exit;
    }

    $updateItemStmt = $pdo->prepare("
        UPDATE invoice_items
        SET description = :description,
            quantity = :quantity,
            unit_price = :unit_price,
            line_total = :line_total
        WHERE invoice_item_id = :invoice_item_id
          AND invoice_id = :invoice_id
          AND tenant_id = :tenant_id
    ");
    $updateItemStmt->execute([
        'description' => $description,
        'quantity' => number_format((float) $quantity, 2, '.', ''),
        'unit_price' => number_format((float) $unitPrice, 2, '.', ''),
        'line_total' => number_format($newLineTotal, 2, '.', ''),
        'invoice_item_id' => $invoiceItemId,
        'invoice_id' => $invoiceId,
        'tenant_id' => $tenantId,
    ]);

    $newStatus = $invoice['status'];
    if ((float) $invoice['amount_paid'] > 0) {
        $newStatus = ((float) $invoice['amount_paid'] >= $newTotal) ? 'paid' : 'partial';
    } elseif (!in_array($newStatus, ['draft', 'unpaid'], true)) {
        $newStatus = 'unpaid';
    }

    $updateInvoiceStmt = $pdo->prepare("
        UPDATE invoices
        SET subtotal = :subtotal,
            tax_amount = :tax_amount,
            total = :total,
            status = :status
        WHERE invoice_id = :invoice_id
          AND tenant_id = :tenant_id
    ");
    $updateInvoiceStmt->execute([
        'subtotal' => number_format($newSubtotal, 2, '.', ''),
        'tax_amount' => number_format($newTaxAmount, 2, '.', ''),
        'total' => number_format($newTotal, 2, '.', ''),
        'status' => $newStatus,
        'invoice_id' => $invoiceId,
        'tenant_id' => $tenantId,
    ]);

    $pdo->commit();
    unset($_SESSION['invoice_item_edit_old_input']);
    $_SESSION['invoice_item_success'] = 'Manual invoice item updated successfully.';
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['invoice_item_error'] = 'Manual invoice item update failed: ' . $e->getMessage();
    header('Location: ../invoices.php?invoice_id=' . $invoiceId . '&edit_invoice_item_id=' . $invoiceItemId);
    exit;
}
?>
