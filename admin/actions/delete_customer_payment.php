<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/feature_access.php';

requireTenantRoles(['admin', 'cashier']);
requireTenantFeature('invoicing', '../invoices.php');
requireTenantFeature('payments', '../invoices.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../invoices.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$paymentId = (int) ($_POST['payment_id'] ?? 0);

if ($invoiceId <= 0 || $paymentId <= 0) {
    $_SESSION['payment_error'] = 'A valid payment removal request is required.';
    header('Location: ../invoices.php' . ($invoiceId > 0 ? '?invoice_id=' . $invoiceId : ''));
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $invoiceStmt = $pdo->prepare("
        SELECT invoice_id, total, status
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
        $_SESSION['payment_error'] = 'That invoice could not be found in this tenant.';
        header('Location: ../invoices.php');
        exit;
    }

    if (in_array($invoice['status'], ['draft', 'cancelled'], true)) {
        $pdo->rollBack();
        $_SESSION['payment_error'] = 'Payments can only be removed from unpaid or partial invoices.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    $paymentStmt = $pdo->prepare("
        SELECT payment_id
        FROM payments
        WHERE payment_id = :payment_id
          AND invoice_id = :invoice_id
          AND tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $paymentStmt->execute([
        'payment_id' => $paymentId,
        'invoice_id' => $invoiceId,
        'tenant_id' => $tenantId,
    ]);

    if (!$paymentStmt->fetch()) {
        $pdo->rollBack();
        $_SESSION['payment_error'] = 'That payment could not be found in this tenant invoice.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    $deleteStmt = $pdo->prepare("
        DELETE FROM payments
        WHERE payment_id = :payment_id
          AND invoice_id = :invoice_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $deleteStmt->execute([
        'payment_id' => $paymentId,
        'invoice_id' => $invoiceId,
        'tenant_id' => $tenantId,
    ]);

    $sumStmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM payments
        WHERE invoice_id = :invoice_id
          AND tenant_id = :tenant_id
    ");
    $sumStmt->execute([
        'invoice_id' => $invoiceId,
        'tenant_id' => $tenantId,
    ]);
    $newAmountPaid = (float) $sumStmt->fetchColumn();

    $newStatus = 'unpaid';
    if ($newAmountPaid > 0 && $newAmountPaid < (float) $invoice['total']) {
        $newStatus = 'partial';
    } elseif ($newAmountPaid >= (float) $invoice['total']) {
        $newStatus = 'paid';
    } elseif ($invoice['status'] === 'draft') {
        $newStatus = 'draft';
    }

    $updateInvoiceStmt = $pdo->prepare("
        UPDATE invoices
        SET amount_paid = :amount_paid,
            status = :status
        WHERE invoice_id = :invoice_id
          AND tenant_id = :tenant_id
    ");
    $updateInvoiceStmt->execute([
        'amount_paid' => number_format($newAmountPaid, 2, '.', ''),
        'status' => $newStatus,
        'invoice_id' => $invoiceId,
        'tenant_id' => $tenantId,
    ]);

    $pdo->commit();
    $_SESSION['payment_success'] = 'Customer payment removed successfully.';
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['payment_error'] = 'Customer payment removal failed: ' . $e->getMessage();
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
}
?>
