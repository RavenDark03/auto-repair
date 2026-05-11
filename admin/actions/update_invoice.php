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
$invoiceNo = trim($_POST['invoice_no'] ?? '');
$dueDate = trim($_POST['due_date'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$status = $_POST['status'] ?? '';
$allowedStatuses = ['draft', 'unpaid', 'cancelled'];

$_SESSION['invoice_old_input'] = [
    'invoice_no' => $invoiceNo,
    'due_date' => $dueDate,
    'notes' => $notes,
    'status' => $status,
];

if ($invoiceId <= 0 || $invoiceNo === '') {
    $_SESSION['invoice_error'] = 'A valid invoice and invoice number are required.';
    header('Location: ../invoices.php' . ($invoiceId > 0 ? '?invoice_id=' . $invoiceId : ''));
    exit;
}

if ($dueDate !== '') {
    $dueDateObj = DateTime::createFromFormat('Y-m-d', $dueDate);
    if (!$dueDateObj || $dueDateObj->format('Y-m-d') !== $dueDate) {
        $_SESSION['invoice_error'] = 'Please provide a valid due date.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $invoiceStmt = $pdo->prepare("
        SELECT invoice_id, amount_paid, status
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
        $_SESSION['invoice_error'] = 'That invoice could not be found in this tenant.';
        header('Location: ../invoices.php');
        exit;
    }

    if ((float) $invoice['amount_paid'] > 0 && $status !== '' && $status !== $invoice['status']) {
        $pdo->rollBack();
        $_SESSION['invoice_error'] = 'Invoice status cannot be changed after customer payments have been recorded.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    if ((float) $invoice['amount_paid'] > 0) {
        $status = $invoice['status'];
    } elseif (!in_array($status, $allowedStatuses, true)) {
        $pdo->rollBack();
        $_SESSION['invoice_error'] = 'Please choose a valid invoice status.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    $duplicateStmt = $pdo->prepare("
        SELECT invoice_id
        FROM invoices
        WHERE tenant_id = :tenant_id
          AND invoice_no = :invoice_no
          AND invoice_id <> :invoice_id
        LIMIT 1
    ");
    $duplicateStmt->execute([
        'tenant_id' => $tenantId,
        'invoice_no' => $invoiceNo,
        'invoice_id' => $invoiceId,
    ]);

    if ($duplicateStmt->fetch()) {
        $pdo->rollBack();
        $_SESSION['invoice_error'] = 'That invoice number is already being used in this tenant.';
        header('Location: ../invoices.php?invoice_id=' . $invoiceId);
        exit;
    }

    $updateStmt = $pdo->prepare("
        UPDATE invoices
        SET invoice_no = :invoice_no,
            due_date = :due_date,
            notes = :notes,
            status = :status
        WHERE invoice_id = :invoice_id
          AND tenant_id = :tenant_id
    ");
    $updateStmt->execute([
        'invoice_no' => $invoiceNo,
        'due_date' => $dueDate !== '' ? $dueDate : null,
        'notes' => $notes !== '' ? $notes : null,
        'status' => $status,
        'invoice_id' => $invoiceId,
        'tenant_id' => $tenantId,
    ]);

    $pdo->commit();
    unset($_SESSION['invoice_old_input']);
    $_SESSION['invoice_success'] = 'Invoice details updated successfully.';
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['invoice_error'] = 'Invoice update failed: ' . $e->getMessage();
    header('Location: ../invoices.php?invoice_id=' . $invoiceId);
    exit;
}
?>
