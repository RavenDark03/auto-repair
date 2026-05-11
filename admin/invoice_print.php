<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';

requireTenantRoles(['admin', 'cashier']);
requireTenantFeature('invoicing');

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$invoice = null;
$invoiceItems = [];
$invoicePayments = [];
$errorMessage = null;

if ($invoiceId <= 0) {
    $errorMessage = 'A valid invoice is required for printing.';
}

try {
    if ($errorMessage === null) {
        $pdo = Database::getInstance();

        $tenantStmt = $pdo->prepare("
            SELECT business_name
            FROM tenants
            WHERE tenant_id = :tenant_id
            LIMIT 1
        ");
        $tenantStmt->execute(['tenant_id' => $tenantId]);
        $tenantRow = $tenantStmt->fetch();

        if ($tenantRow && !empty($tenantRow['business_name'])) {
            $businessName = $tenantRow['business_name'];
            $_SESSION['business_name'] = $businessName;
        }

        $invoiceStmt = $pdo->prepare("
            SELECT
                i.invoice_id,
                i.invoice_no,
                i.total,
                i.status,
                i.due_date,
                i.amount_paid,
                i.notes,
                i.created_at,
                c.name AS customer_name,
                c.contact AS customer_contact,
                c.email AS customer_email,
                c.address AS customer_address,
                v.make,
                v.model,
                v.year_model,
                v.plate
            FROM invoices i
            INNER JOIN jobs j
                ON j.job_id = i.job_id
               AND j.tenant_id = i.tenant_id
            INNER JOIN appointments a
                ON a.appointment_id = j.appointment_id
               AND a.tenant_id = j.tenant_id
            INNER JOIN customers c
                ON c.customer_id = a.customer_id
               AND c.tenant_id = a.tenant_id
            INNER JOIN vehicles v
                ON v.vehicle_id = a.vehicle_id
               AND v.tenant_id = a.tenant_id
            WHERE i.invoice_id = :invoice_id
              AND i.tenant_id = :tenant_id
            LIMIT 1
        ");
        $invoiceStmt->execute([
            'invoice_id' => $invoiceId,
            'tenant_id' => $tenantId,
        ]);
        $invoice = $invoiceStmt->fetch();

        if (!$invoice) {
            $errorMessage = 'That invoice could not be found in this tenant.';
        } else {
            $itemStmt = $pdo->prepare("
                SELECT item_type, description, quantity, unit_price, line_total
                FROM invoice_items
                WHERE tenant_id = :tenant_id
                  AND invoice_id = :invoice_id
                ORDER BY invoice_item_id ASC
            ");
            $itemStmt->execute([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoiceId,
            ]);
            $invoiceItems = $itemStmt->fetchAll();

            $paymentStmt = $pdo->prepare("
                SELECT amount, payment_method, reference_no, notes, payment_date
                FROM payments
                WHERE tenant_id = :tenant_id
                  AND invoice_id = :invoice_id
                ORDER BY payment_date ASC, payment_id ASC
            ");
            $paymentStmt->execute([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoiceId,
            ]);
            $invoicePayments = $paymentStmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    $errorMessage = 'Printable invoice could not be loaded: ' . $e->getMessage();
}

function printableDate($date) {
    if (!$date) {
        return 'No date';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y', $timestamp) : $date;
}

function printableDateTime($date) {
    if (!$date) {
        return 'No date';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y h:i A', $timestamp) : $date;
}

function printableCurrency($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function printableVehicleLabel($invoice) {
    $label = trim(($invoice['make'] ?? '') . ' ' . ($invoice['model'] ?? ''));

    if (!empty($invoice['year_model'])) {
        $label .= ' (' . $invoice['year_model'] . ')';
    }

    if (!empty($invoice['plate'])) {
        $label .= ' - ' . $invoice['plate'];
    }

    return trim($label) !== '' ? $label : 'Vehicle record';
}

function printableBalance($invoice) {
    return max(0, (float) ($invoice['total'] ?? 0) - (float) ($invoice['amount_paid'] ?? 0));
}

function printableSettlementLabel($invoice) {
    $balance = printableBalance($invoice);
    $amountPaid = (float) ($invoice['amount_paid'] ?? 0);

    if ($balance <= 0.009 && $amountPaid > 0) {
        return 'Paid Receipt Summary';
    }

    if ($amountPaid > 0) {
        return 'Partial Payment Summary';
    }

    return 'Unpaid Invoice Summary';
}

function printableSettlementTone($invoice) {
    $balance = printableBalance($invoice);
    $amountPaid = (float) ($invoice['amount_paid'] ?? 0);

    if ($balance <= 0.009 && $amountPaid > 0) {
        return 'paid';
    }

    if ($amountPaid > 0) {
        return 'partial';
    }

    return 'unpaid';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printable Invoice</title>
    <style>
        :root {
            color-scheme: light;
            --paper: #ffffff;
            --ink: #111111;
            --muted: #5f5f5f;
            --line: #d9d9d9;
            --soft: #f4f4f4;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #ececec;
            color: var(--ink);
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 24px;
            border-bottom: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px);
        }

        .toolbar-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .toolbar-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 18px;
            border: 1px solid #111111;
            border-radius: 999px;
            background: #111111;
            color: #ffffff;
            text-decoration: none;
            font-size: 0.95rem;
            cursor: pointer;
        }

        .toolbar-button.secondary {
            background: #ffffff;
            color: #111111;
        }

        .print-shell {
            max-width: 960px;
            margin: 28px auto;
            padding: 0 18px 32px;
        }

        .invoice-paper {
            background: var(--paper);
            border: 1px solid var(--line);
            box-shadow: 0 18px 44px rgba(0, 0, 0, 0.08);
            border-radius: 24px;
            overflow: hidden;
        }

        .invoice-head {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 24px;
            padding: 32px;
            border-bottom: 1px solid var(--line);
            background:
                linear-gradient(135deg, #fbfbfb, #f1f1f1);
        }

        .invoice-head h1 {
            margin: 0 0 10px;
            font-size: 2rem;
            letter-spacing: -0.04em;
        }

        .invoice-head h2 {
            margin: 0 0 8px;
            font-size: 1rem;
        }

        .invoice-head p {
            margin: 0 0 6px;
            color: var(--muted);
            line-height: 1.45;
        }

        .invoice-meta {
            display: grid;
            gap: 12px;
        }

        .meta-card {
            padding: 16px 18px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.92);
        }

        .meta-card span {
            display: block;
            margin-bottom: 6px;
            color: var(--muted);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .meta-card strong {
            font-size: 1rem;
        }

        .invoice-body {
            padding: 32px;
        }

        .receipt-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
            padding: 18px 20px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: #fafafa;
        }

        .receipt-banner p {
            margin: 6px 0 0;
            color: var(--muted);
            line-height: 1.5;
        }

        .receipt-banner strong {
            font-size: 1rem;
        }

        .receipt-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 180px;
            min-height: 42px;
            padding: 0 18px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #ffffff;
            font-size: 0.92rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .receipt-status.paid {
            background: #111111;
            color: #ffffff;
            border-color: #111111;
        }

        .receipt-status.partial {
            background: #f3f3f3;
            color: #111111;
        }

        .receipt-status.unpaid {
            background: #ffffff;
            color: #111111;
        }

        .section-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
            margin-bottom: 26px;
        }

        .section-card {
            padding: 18px 20px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: var(--soft);
        }

        .section-card h3 {
            margin: 0 0 10px;
            font-size: 0.96rem;
        }

        .section-card p {
            margin: 0 0 6px;
            color: var(--muted);
            line-height: 1.5;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .line-items {
            margin-top: 10px;
            border: 1px solid var(--line);
            border-radius: 18px;
            overflow: hidden;
        }

        .line-items th,
        .line-items td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }

        .line-items th {
            background: #f6f6f6;
            font-size: 0.86rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .line-items tr:last-child td {
            border-bottom: none;
        }

        .line-items .amount {
            text-align: right;
            white-space: nowrap;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 18px;
            margin-top: 24px;
        }

        .notes-card,
        .totals-card,
        .payments-card {
            padding: 18px 20px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: #ffffff;
        }

        .totals-table td {
            padding: 8px 0;
        }

        .totals-table td:last-child {
            text-align: right;
        }

        .totals-table tr.total-row td {
            padding-top: 14px;
            border-top: 1px solid var(--line);
            font-weight: 700;
        }

        .settlement-card {
            margin-bottom: 18px;
            padding: 18px 20px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: var(--soft);
        }

        .settlement-card h3 {
            margin: 0 0 12px;
            font-size: 0.96rem;
        }

        .settlement-card p {
            margin: 0 0 6px;
            color: var(--muted);
            line-height: 1.45;
        }

        .payments-card {
            margin-top: 18px;
        }

        .payments-list {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .payment-row {
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--soft);
        }

        .payment-row strong {
            display: block;
            margin-bottom: 4px;
        }

        .payment-row p {
            margin: 0;
            color: var(--muted);
            line-height: 1.45;
        }

        .invoice-foot {
            padding: 22px 32px 30px;
            color: var(--muted);
            font-size: 0.92rem;
        }

        @media (max-width: 900px) {
            .invoice-head,
            .section-grid,
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            body {
                background: #ffffff;
            }

            .print-toolbar {
                display: none;
            }

            .print-shell {
                max-width: none;
                margin: 0;
                padding: 0;
            }

            .invoice-paper {
                border: none;
                border-radius: 0;
                box-shadow: none;
            }

            @page {
                margin: 14mm;
            }
        }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <div>
            <strong>Printable Invoice</strong>
        </div>
        <div class="toolbar-actions">
            <?php if ($invoice): ?>
                <a href="invoices.php?invoice_id=<?= (int) $invoice['invoice_id'] ?>" class="toolbar-button secondary">Back to Invoice</a>
            <?php else: ?>
                <a href="invoices.php" class="toolbar-button secondary">Back to Invoices</a>
            <?php endif; ?>
            <button type="button" class="toolbar-button" onclick="window.print()">Print Invoice</button>
        </div>
    </div>

    <div class="print-shell">
        <?php if ($errorMessage !== null): ?>
            <div class="invoice-paper">
                <div class="invoice-body">
                    <h1>Print Error</h1>
                    <p><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        <?php elseif ($invoice !== null): ?>
            <div class="invoice-paper">
                <div class="invoice-head">
                    <div>
                        <h1><?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?></h1>
                        <p>Tenant auto repair billing document</p>
                        <p>Issued to <?= htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p><?= htmlspecialchars(printableVehicleLabel($invoice), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>

                    <div class="invoice-meta">
                        <div class="meta-card">
                            <span>Invoice Number</span>
                            <strong><?= htmlspecialchars($invoice['invoice_no'] ?: ('INV-' . $invoice['invoice_id']), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="meta-card">
                            <span>Status</span>
                            <strong><?= htmlspecialchars(ucfirst($invoice['status']), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="meta-card">
                            <span>Issue Date</span>
                            <strong><?= htmlspecialchars(printableDate($invoice['created_at']), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="meta-card">
                            <span>Due Date</span>
                            <strong><?= htmlspecialchars(printableDate($invoice['due_date']), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                    </div>
                </div>

                <div class="invoice-body">
                    <div class="receipt-banner">
                        <div>
                            <strong><?= htmlspecialchars(printableSettlementLabel($invoice), ENT_QUOTES, 'UTF-8') ?></strong>
                            <p>
                                <?php if (printableBalance($invoice) <= 0.009 && (float) ($invoice['amount_paid'] ?? 0) > 0): ?>
                                    This document can be handed off as a settled customer billing receipt with full payment already recorded.
                                <?php elseif ((float) ($invoice['amount_paid'] ?? 0) > 0): ?>
                                    This document shows the current payment progress and remaining balance still due from the customer.
                                <?php else: ?>
                                    This document is currently an open customer invoice with no recorded payments yet.
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="receipt-status <?= htmlspecialchars(printableSettlementTone($invoice), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(printableSettlementTone($invoice), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <div class="section-grid">
                        <div class="section-card">
                            <h3>Bill To</h3>
                            <p><strong><?= htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong></p>
                            <p><?= htmlspecialchars($invoice['customer_contact'] ?: 'No contact number', ENT_QUOTES, 'UTF-8') ?></p>
                            <p><?= htmlspecialchars($invoice['customer_email'] ?: 'No email address', ENT_QUOTES, 'UTF-8') ?></p>
                            <p><?= htmlspecialchars($invoice['customer_address'] ?: 'No customer address', ENT_QUOTES, 'UTF-8') ?></p>
                        </div>

                        <div class="section-card">
                            <h3>Vehicle</h3>
                            <p><strong><?= htmlspecialchars(printableVehicleLabel($invoice), ENT_QUOTES, 'UTF-8') ?></strong></p>
                            <p>Invoice Date: <?= htmlspecialchars(printableDate($invoice['created_at']), ENT_QUOTES, 'UTF-8') ?></p>
                            <p>Balance Due: <?= htmlspecialchars(printableCurrency(printableBalance($invoice)), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>

                    <div class="line-items">
                        <table>
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th>Type</th>
                                    <th class="amount">Quantity</th>
                                    <th class="amount">Unit Price</th>
                                    <th class="amount">Line Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoiceItems as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars(ucfirst($item['item_type']), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="amount"><?= htmlspecialchars(number_format((float) $item['quantity'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="amount"><?= htmlspecialchars(printableCurrency($item['unit_price']), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="amount"><?= htmlspecialchars(printableCurrency($item['line_total']), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="summary-grid">
                        <div>
                            <div class="notes-card">
                                <h3>Invoice Notes</h3>
                                <p><?= nl2br(htmlspecialchars($invoice['notes'] ?: 'No invoice notes were provided.', ENT_QUOTES, 'UTF-8')) ?></p>
                            </div>

                            <div class="payments-card">
                                <h3>Payment History</h3>
                                <?php if (!empty($invoicePayments)): ?>
                                    <div class="payments-list">
                                        <?php foreach ($invoicePayments as $payment): ?>
                                            <div class="payment-row">
                                                <strong><?= htmlspecialchars(printableCurrency($payment['amount']), ENT_QUOTES, 'UTF-8') ?></strong>
                                                <p>
                                                    <?= htmlspecialchars(printableDateTime($payment['payment_date']), ENT_QUOTES, 'UTF-8') ?>
                                                    <?php if (!empty($payment['payment_method'])): ?>
                                                        | <?= htmlspecialchars($payment['payment_method'], ENT_QUOTES, 'UTF-8') ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($payment['reference_no'])): ?>
                                                        | Ref <?= htmlspecialchars($payment['reference_no'], ENT_QUOTES, 'UTF-8') ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p>No payments have been recorded for this invoice yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <div class="settlement-card">
                                <h3>Settlement Snapshot</h3>
                                <p>Total Invoice: <strong><?= htmlspecialchars(printableCurrency($invoice['total']), ENT_QUOTES, 'UTF-8') ?></strong></p>
                                <p>Total Paid: <strong><?= htmlspecialchars(printableCurrency($invoice['amount_paid']), ENT_QUOTES, 'UTF-8') ?></strong></p>
                                <p>Remaining Balance: <strong><?= htmlspecialchars(printableCurrency(printableBalance($invoice)), ENT_QUOTES, 'UTF-8') ?></strong></p>
                                <p>Status: <strong><?= htmlspecialchars(ucfirst($invoice['status']), ENT_QUOTES, 'UTF-8') ?></strong></p>
                            </div>

                            <div class="totals-card">
                                <h3>Invoice Totals</h3>
                                <table class="totals-table">
                                    <tr>
                                        <td>Subtotal</td>
                                        <td><?= htmlspecialchars(printableCurrency($invoice['total']), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                    <tr>
                                        <td>Amount Paid</td>
                                        <td><?= htmlspecialchars(printableCurrency($invoice['amount_paid']), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                    <tr class="total-row">
                                        <td>Balance Due</td>
                                        <td><?= htmlspecialchars(printableCurrency(printableBalance($invoice)), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="invoice-foot">
                    This document was generated inside the tenant admin workspace for <?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?>.
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
