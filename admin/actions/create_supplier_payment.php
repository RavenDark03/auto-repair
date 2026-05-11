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
$purchaseId = (int) ($_POST['purchase_id'] ?? 0);
$paymentDate = trim($_POST['payment_date'] ?? '');
$amount = trim($_POST['amount'] ?? '');
$paymentMethod = trim($_POST['payment_method'] ?? '');
$referenceNo = trim($_POST['reference_no'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$_SESSION['payment_old_input'] = [
    'payment_date' => $paymentDate,
    'amount' => $amount,
    'payment_method' => $paymentMethod,
    'reference_no' => $referenceNo,
    'notes' => $notes,
];

if ($purchaseId <= 0 || $paymentDate === '' || $amount === '') {
    $_SESSION['payment_error'] = 'A valid purchase, payment date, and payment amount are required.';
    header('Location: ../inventory.php' . ($purchaseId > 0 ? '?purchase_id=' . $purchaseId : ''));
    exit;
}

$paymentDateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
if (!$paymentDateObj || $paymentDateObj->format('Y-m-d') !== $paymentDate) {
    $_SESSION['payment_error'] = 'Please provide a valid payment date.';
    header('Location: ../inventory.php?purchase_id=' . $purchaseId);
    exit;
}

if (!is_numeric($amount) || (float) $amount <= 0) {
    $_SESSION['payment_error'] = 'Payment amount must be a valid amount greater than zero.';
    header('Location: ../inventory.php?purchase_id=' . $purchaseId);
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $purchaseStmt = $pdo->prepare("
        SELECT purchase_id, total_amount, amount_paid, payable_status, purchase_date
        FROM inventory_purchases
        WHERE purchase_id = :purchase_id
          AND tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $purchaseStmt->execute([
        'purchase_id' => $purchaseId,
        'tenant_id' => $tenantId,
    ]);
    $purchase = $purchaseStmt->fetch();

    if (!$purchase) {
        $pdo->rollBack();
        $_SESSION['payment_error'] = 'That supplier payable could not be found in this tenant.';
        header('Location: ../inventory.php');
        exit;
    }

    if ($purchase['payable_status'] === 'paid' || $purchase['payable_status'] === 'cancelled') {
        $pdo->rollBack();
        $_SESSION['payment_error'] = 'Payments cannot be added to a paid or cancelled supplier payable.';
        header('Location: ../inventory.php?purchase_id=' . $purchaseId);
        exit;
    }

    if ($paymentDate < (string) $purchase['purchase_date']) {
        $pdo->rollBack();
        $_SESSION['payment_error'] = 'Supplier payment date cannot be earlier than the purchase date.';
        header('Location: ../inventory.php?purchase_id=' . $purchaseId);
        exit;
    }

    $newAmountPaid = (float) $purchase['amount_paid'] + (float) $amount;
    if ($newAmountPaid - (float) $purchase['total_amount'] > 0.00001) {
        $pdo->rollBack();
        $_SESSION['payment_error'] = 'Payment amount cannot exceed the remaining supplier balance.';
        header('Location: ../inventory.php?purchase_id=' . $purchaseId);
        exit;
    }

    $newStatus = 'unpaid';
    if ($newAmountPaid > 0 && $newAmountPaid < (float) $purchase['total_amount']) {
        $newStatus = 'partial';
    } elseif ($newAmountPaid >= (float) $purchase['total_amount']) {
        $newStatus = 'paid';
    }

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
            :payment_method,
            :reference_no,
            :notes
        )
    ");
    $paymentStmt->execute([
        'tenant_id' => $tenantId,
        'purchase_id' => $purchaseId,
        'payment_date' => $paymentDate,
        'amount' => number_format((float) $amount, 2, '.', ''),
        'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
        'reference_no' => $referenceNo !== '' ? $referenceNo : null,
        'notes' => $notes !== '' ? $notes : null,
    ]);

    $updateStmt = $pdo->prepare("
        UPDATE inventory_purchases
        SET amount_paid = :amount_paid,
            payable_status = :payable_status
        WHERE purchase_id = :purchase_id
          AND tenant_id = :tenant_id
    ");
    $updateStmt->execute([
        'amount_paid' => number_format($newAmountPaid, 2, '.', ''),
        'payable_status' => $newStatus,
        'purchase_id' => $purchaseId,
        'tenant_id' => $tenantId,
    ]);

    $pdo->commit();
    unset($_SESSION['payment_old_input']);
    $_SESSION['payment_success'] = 'Supplier payment recorded successfully.';
    header('Location: ../inventory.php?purchase_id=' . $purchaseId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['payment_error'] = 'Supplier payment recording failed: ' . $e->getMessage();
    header('Location: ../inventory.php?purchase_id=' . $purchaseId);
    exit;
}
?>
