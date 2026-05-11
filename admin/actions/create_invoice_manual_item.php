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
$description = trim($_POST['description'] ?? '');
$quantity = trim($_POST['quantity'] ?? '');
$unitPrice = trim($_POST['unit_price'] ?? '');

$_SESSION['invoice_item_old_input'] = [
    'description' => $description,
    'quantity' => $quantity,
    'unit_price' => $unitPrice,
];

if ($invoiceId <= 0 || $description === '' || $quantity === '' || $unitPrice === '') {
    $_SESSION['invoice_item_error'] = 'A valid invoice, description, quantity, and unit price are required.';
    header('Location: ../invoices.php' . ($invoiceId > 0 ? '?invoice_id=' . $invoiceId : ''));
    exit;
}

if (!is_numeric($quantity) || (float) $quantity <= 0 || !is_numeric($unitPrice)) {
    $_SESSION['invoice_item_error'] = 'Quantity must be greater than zero and unit price must be a valid amount.';
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
}

$lineTotal = (float) $quantity * (float) $unitPrice;

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $invoiceStmt = $pdo->prepare("
        SELECT invoice_id, total, amount_paid, status
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
        $_SESSION['invoice_item_error'] = 'Manual invoice items cannot be added to paid or cancelled invoices.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    $newTotal = (float) $invoice['total'] + $lineTotal;
    if ($newTotal <= 0) {
        $pdo->rollBack();
        $_SESSION['invoice_item_error'] = 'This adjustment would make the invoice total zero or negative.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    if ($newTotal + 0.00001 < (float) $invoice['amount_paid']) {
        $pdo->rollBack();
        $_SESSION['invoice_item_error'] = 'This adjustment would make the invoice total lower than the amount already paid.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    $itemStmt = $pdo->prepare("
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
            'manual',
            NULL,
            :description,
            :quantity,
            :unit_price,
            :line_total
        )
    ");
    $itemStmt->execute([
        'tenant_id' => $tenantId,
        'invoice_id' => $invoiceId,
        'description' => $description,
        'quantity' => number_format((float) $quantity, 2, '.', ''),
        'unit_price' => number_format((float) $unitPrice, 2, '.', ''),
        'line_total' => number_format($lineTotal, 2, '.', ''),
    ]);

    $newStatus = $invoice['status'];
    if ((float) $invoice['amount_paid'] > 0) {
        $newStatus = ((float) $invoice['amount_paid'] >= $newTotal) ? 'paid' : 'partial';
    }

    $updateStmt = $pdo->prepare("
        UPDATE invoices
        SET total = :total,
            status = :status
        WHERE invoice_id = :invoice_id
          AND tenant_id = :tenant_id
    ");
    $updateStmt->execute([
        'total' => number_format($newTotal, 2, '.', ''),
        'status' => $newStatus,
        'invoice_id' => $invoiceId,
        'tenant_id' => $tenantId,
    ]);

    $pdo->commit();
    unset($_SESSION['invoice_item_old_input']);
    $_SESSION['invoice_item_success'] = 'Manual invoice item added successfully.';
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['invoice_item_error'] = 'Manual invoice item could not be added: ' . $e->getMessage();
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
}
?>
