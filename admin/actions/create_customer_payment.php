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
$amount = trim($_POST['amount'] ?? '');
$paymentMethod = trim($_POST['payment_method'] ?? '');
$referenceNo = trim($_POST['reference_no'] ?? '');
$paymentDate = trim($_POST['payment_date'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$_SESSION['customer_payment_old_input'] = [
    'amount' => $amount,
    'payment_method' => $paymentMethod,
    'reference_no' => $referenceNo,
    'payment_date' => $paymentDate,
    'notes' => $notes,
];

if ($invoiceId <= 0 || $amount === '' || $paymentDate === '') {
    $_SESSION['payment_error'] = 'A valid invoice, payment amount, and payment date are required.';
    header('Location: ../invoices.php' . ($invoiceId > 0 ? '?invoice_id=' . $invoiceId : ''));
    exit;
}

if (!is_numeric($amount) || (float) $amount <= 0) {
    $_SESSION['payment_error'] = 'Payment amount must be greater than zero.';
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
}

$paymentDateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
if (!$paymentDateObj || $paymentDateObj->format('Y-m-d') !== $paymentDate) {
    $_SESSION['payment_error'] = 'Please provide a valid payment date.';
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
}

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
        $_SESSION['payment_error'] = 'That invoice could not be found in this tenant.';
        header('Location: ../invoices.php');
        exit;
    }

    if (in_array($invoice['status'], ['draft', 'paid', 'cancelled'], true)) {
        $pdo->rollBack();
        $_SESSION['payment_error'] = 'Payments can only be recorded on unpaid or partial invoices.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    $newAmountPaid = (float) $invoice['amount_paid'] + (float) $amount;
    if ($newAmountPaid - (float) $invoice['total'] > 0.00001) {
        $pdo->rollBack();
        $_SESSION['payment_error'] = 'Payment amount cannot be greater than the remaining invoice balance.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    $newStatus = 'unpaid';
    if ($newAmountPaid > 0 && $newAmountPaid < (float) $invoice['total']) {
        $newStatus = 'partial';
    } elseif ($newAmountPaid >= (float) $invoice['total']) {
        $newStatus = 'paid';
    }

    $paymentStmt = $pdo->prepare("
        INSERT INTO payments (
            tenant_id,
            invoice_id,
            amount,
            payment_method,
            reference_no,
            notes,
            payment_date
        ) VALUES (
            :tenant_id,
            :invoice_id,
            :amount,
            :payment_method,
            :reference_no,
            :notes,
            :payment_date
        )
    ");
    $paymentStmt->execute([
        'tenant_id' => $tenantId,
        'invoice_id' => $invoiceId,
        'amount' => number_format((float) $amount, 2, '.', ''),
        'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
        'reference_no' => $referenceNo !== '' ? $referenceNo : null,
        'notes' => $notes !== '' ? $notes : null,
        'payment_date' => $paymentDate . ' 00:00:00',
    ]);

    $updateStmt = $pdo->prepare("
        UPDATE invoices
        SET amount_paid = :amount_paid,
            status = :status
        WHERE invoice_id = :invoice_id
          AND tenant_id = :tenant_id
    ");
    $updateStmt->execute([
        'amount_paid' => number_format($newAmountPaid, 2, '.', ''),
        'status' => $newStatus,
        'invoice_id' => $invoiceId,
        'tenant_id' => $tenantId,
    ]);

    $pdo->commit();
    unset($_SESSION['customer_payment_old_input']);
    $_SESSION['payment_success'] = 'Customer payment recorded successfully.';
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['payment_error'] = 'Customer payment recording failed: ' . $e->getMessage();
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
}
?>
