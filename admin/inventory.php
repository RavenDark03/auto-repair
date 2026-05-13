<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/platform_rules.php';

requireAdmin();
requireTenantFeature('inventory');

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$fullName = $_SESSION['full_name'] ?? 'Admin User';
$selectedInventoryId = (int) ($_GET['inventory_id'] ?? 0);
$selectedSupplierId = (int) ($_GET['supplier_id'] ?? 0);
$selectedPurchaseId = (int) ($_GET['purchase_id'] ?? 0);
$inventorySearch = trim($_GET['inventory_search'] ?? '');
$supplierSearch = trim($_GET['supplier_search'] ?? '');
$purchaseStatus = $_GET['purchase_status'] ?? '';

$flashMessages = [];
foreach (['inventory_success', 'supplier_success', 'purchase_success', 'payment_success'] as $key) {
    if (!empty($_SESSION[$key])) {
        $flashMessages[] = $_SESSION[$key];
    }
    unset($_SESSION[$key]);
}

$errorMessages = [];
foreach (['inventory_error', 'supplier_error', 'purchase_error', 'payment_error'] as $key) {
    if (!empty($_SESSION[$key])) {
        $errorMessages[] = $_SESSION[$key];
    }
    unset($_SESSION[$key]);
}

$oldInventoryInput = $_SESSION['inventory_old_input'] ?? [];
$oldSupplierInput = $_SESSION['supplier_old_input'] ?? [];
$oldPurchaseInput = $_SESSION['purchase_old_input'] ?? [];
$oldPaymentInput = $_SESSION['payment_old_input'] ?? [];
unset(
    $_SESSION['inventory_old_input'],
    $_SESSION['supplier_old_input'],
    $_SESSION['purchase_old_input'],
    $_SESSION['payment_old_input']
);

$allowedPurchaseStatuses = ['unpaid', 'partial', 'paid', 'cancelled'];
if (!in_array($purchaseStatus, $allowedPurchaseStatuses, true)) {
    $purchaseStatus = '';
}

$inventoryItems = [];
$selectedInventory = null;
$suppliers = [];
$selectedSupplier = null;
$purchases = [];
$selectedPurchase = null;
$purchasePayments = [];
$inventoryOptions = [];
$supplierOptions = [];
$summary = [
    'active_parts' => 0,
    'low_stock_parts' => 0,
    'active_suppliers' => 0,
    'outstanding_payables' => 0,
];
$inventoryMovement = [
    'fast_moving' => 0,
    'slow_moving' => 0,
    'idle_items' => 0,
    'tracked_items' => 0,
    'items' => [],
];

try {
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

    $inventoryMovement = getInventoryMovementSummary($pdo, $tenantId);

    $summaryStmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM inventory WHERE tenant_id = :tenant_id AND status = 'active') AS active_parts,
            (SELECT COUNT(*) FROM inventory WHERE tenant_id = :tenant_id AND status = 'active' AND quantity <= reorder_level) AS low_stock_parts,
            (SELECT COUNT(*) FROM suppliers WHERE tenant_id = :tenant_id AND status = 'active') AS active_suppliers,
            (
                SELECT COALESCE(SUM(total_amount - amount_paid), 0)
                FROM inventory_purchases
                WHERE tenant_id = :tenant_id
                  AND payable_status IN ('unpaid', 'partial')
            ) AS outstanding_payables
    ");
    $summaryStmt->execute(['tenant_id' => $tenantId]);
    $summaryRow = $summaryStmt->fetch();

    if ($summaryRow) {
        $summary['active_parts'] = (int) ($summaryRow['active_parts'] ?? 0);
        $summary['low_stock_parts'] = (int) ($summaryRow['low_stock_parts'] ?? 0);
        $summary['active_suppliers'] = (int) ($summaryRow['active_suppliers'] ?? 0);
        $summary['outstanding_payables'] = (float) ($summaryRow['outstanding_payables'] ?? 0);
    }

    $inventoryOptionsStmt = $pdo->prepare("
        SELECT inventory_id, part_name, sku, status
        FROM inventory
        WHERE tenant_id = :tenant_id
        ORDER BY part_name ASC, inventory_id ASC
    ");
    $inventoryOptionsStmt->execute(['tenant_id' => $tenantId]);
    $inventoryOptions = $inventoryOptionsStmt->fetchAll();

    $supplierOptionsStmt = $pdo->prepare("
        SELECT supplier_id, supplier_name, status
        FROM suppliers
        WHERE tenant_id = :tenant_id
        ORDER BY supplier_name ASC, supplier_id ASC
    ");
    $supplierOptionsStmt->execute(['tenant_id' => $tenantId]);
    $supplierOptions = $supplierOptionsStmt->fetchAll();

    $inventorySql = "
        SELECT inventory_id, part_name, sku, quantity, unit_cost, reorder_level, status, created_at
        FROM inventory
        WHERE tenant_id = :tenant_id
    ";
    $inventoryParams = ['tenant_id' => $tenantId];

    if ($inventorySearch !== '') {
        $inventorySql .= "
          AND (
                part_name LIKE :search
             OR sku LIKE :search
          )
        ";
        $inventoryParams['search'] = '%' . $inventorySearch . '%';
    }

    $inventorySql .= " ORDER BY status ASC, part_name ASC, inventory_id ASC";
    $inventoryStmt = $pdo->prepare($inventorySql);
    $inventoryStmt->execute($inventoryParams);
    $inventoryItems = $inventoryStmt->fetchAll();

    if ($selectedInventoryId === 0 && !empty($inventoryItems)) {
        $selectedInventoryId = (int) $inventoryItems[0]['inventory_id'];
    }

    if ($selectedInventoryId > 0) {
        $selectedInventoryStmt = $pdo->prepare("
            SELECT inventory_id, part_name, sku, quantity, unit_cost, reorder_level, status, created_at
            FROM inventory
            WHERE inventory_id = :inventory_id
              AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $selectedInventoryStmt->execute([
            'inventory_id' => $selectedInventoryId,
            'tenant_id' => $tenantId,
        ]);
        $selectedInventory = $selectedInventoryStmt->fetch();
    }

    $supplierSql = "
        SELECT supplier_id, supplier_name, contact_person, phone, email, address, status, created_at
        FROM suppliers
        WHERE tenant_id = :tenant_id
    ";
    $supplierParams = ['tenant_id' => $tenantId];

    if ($supplierSearch !== '') {
        $supplierSql .= "
          AND (
                supplier_name LIKE :search
             OR contact_person LIKE :search
             OR phone LIKE :search
             OR email LIKE :search
          )
        ";
        $supplierParams['search'] = '%' . $supplierSearch . '%';
    }

    $supplierSql .= " ORDER BY status ASC, supplier_name ASC, supplier_id ASC";
    $supplierStmt = $pdo->prepare($supplierSql);
    $supplierStmt->execute($supplierParams);
    $suppliers = $supplierStmt->fetchAll();

    if ($selectedSupplierId === 0 && !empty($suppliers)) {
        $selectedSupplierId = (int) $suppliers[0]['supplier_id'];
    }

    if ($selectedSupplierId > 0) {
        $selectedSupplierStmt = $pdo->prepare("
            SELECT supplier_id, supplier_name, contact_person, phone, email, address, status, created_at
            FROM suppliers
            WHERE supplier_id = :supplier_id
              AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $selectedSupplierStmt->execute([
            'supplier_id' => $selectedSupplierId,
            'tenant_id' => $tenantId,
        ]);
        $selectedSupplier = $selectedSupplierStmt->fetch();
    }

    $purchaseSql = "
        SELECT
            p.purchase_id,
            p.supplier_id,
            p.reference_no,
            p.purchase_date,
            p.due_date,
            p.total_amount,
            p.amount_paid,
            p.payable_status,
            p.notes,
            s.supplier_name,
            i.part_name,
            i.sku,
            pi.quantity AS purchase_quantity,
            pi.unit_cost AS purchase_unit_cost
        FROM inventory_purchases p
        INNER JOIN suppliers s
            ON s.supplier_id = p.supplier_id
           AND s.tenant_id = p.tenant_id
        LEFT JOIN inventory_purchase_items pi
            ON pi.purchase_id = p.purchase_id
           AND pi.tenant_id = p.tenant_id
        LEFT JOIN inventory i
            ON i.inventory_id = pi.inventory_id
           AND i.tenant_id = pi.tenant_id
        WHERE p.tenant_id = :tenant_id
    ";
    $purchaseParams = ['tenant_id' => $tenantId];

    if ($purchaseStatus !== '') {
        $purchaseSql .= " AND p.payable_status = :purchase_status ";
        $purchaseParams['purchase_status'] = $purchaseStatus;
    }

    $purchaseSql .= " ORDER BY p.purchase_date DESC, p.purchase_id DESC";
    $purchaseStmt = $pdo->prepare($purchaseSql);
    $purchaseStmt->execute($purchaseParams);
    $purchases = $purchaseStmt->fetchAll();

    if ($selectedPurchaseId === 0 && !empty($purchases)) {
        $selectedPurchaseId = (int) $purchases[0]['purchase_id'];
    }

    if ($selectedPurchaseId > 0) {
        $selectedPurchaseStmt = $pdo->prepare("
            SELECT
                p.purchase_id,
                p.supplier_id,
                p.reference_no,
                p.purchase_date,
                p.due_date,
                p.total_amount,
                p.amount_paid,
                p.payable_status,
                p.notes,
                s.supplier_name,
                i.part_name,
                i.sku,
                i.inventory_id,
                pi.quantity AS purchase_quantity,
                pi.unit_cost AS purchase_unit_cost,
                pi.line_total
            FROM inventory_purchases p
            INNER JOIN suppliers s
                ON s.supplier_id = p.supplier_id
               AND s.tenant_id = p.tenant_id
            LEFT JOIN inventory_purchase_items pi
                ON pi.purchase_id = p.purchase_id
               AND pi.tenant_id = p.tenant_id
            LEFT JOIN inventory i
                ON i.inventory_id = pi.inventory_id
               AND i.tenant_id = pi.tenant_id
            WHERE p.purchase_id = :purchase_id
              AND p.tenant_id = :tenant_id
            LIMIT 1
        ");
        $selectedPurchaseStmt->execute([
            'purchase_id' => $selectedPurchaseId,
            'tenant_id' => $tenantId,
        ]);
        $selectedPurchase = $selectedPurchaseStmt->fetch();

        if ($selectedPurchase) {
            $paymentStmt = $pdo->prepare("
                SELECT payment_date, amount, payment_method, reference_no, notes, created_at
                FROM supplier_payments
                WHERE tenant_id = :tenant_id
                  AND purchase_id = :purchase_id
                ORDER BY payment_date DESC, supplier_payment_id DESC
            ");
            $paymentStmt->execute([
                'tenant_id' => $tenantId,
                'purchase_id' => $selectedPurchaseId,
            ]);
            $purchasePayments = $paymentStmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    $errorMessages[] = 'Inventory module could not be loaded: ' . $e->getMessage();
}

$showAnalytics = tenantHasFeature('reports', $tenantId);
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId);

function inventoryDate($date) {
    if (!$date) {
        return 'No date';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y', $timestamp) : $date;
}

function inventoryCurrency($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function inventoryOutstandingAmount($purchase) {
    return max(0, (float) ($purchase['total_amount'] ?? 0) - (float) ($purchase['amount_paid'] ?? 0));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - MECHANIX</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body class="page-shell">
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'inventory.php', $showAnalytics) ?>

        <main class="dashboard-main" id="main-content" tabindex="-1">
            <?= renderTenantAdminTopbar(
                'Inventory',
                'Manage parts stock, suppliers, and supplier-side payables for ' . htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') . '.'
            ) ?>

            <?php foreach ($flashMessages as $message): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endforeach; ?>

            <?php foreach ($errorMessages as $message): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endforeach; ?>

            <?= renderTenantAccessModeNotice() ?>

            <section class="dashboard-grid">
                <article class="metric-card">
                    <span>Active Parts</span>
                    <h3><?= number_format($summary['active_parts']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Low Stock Parts</span>
                    <h3><?= number_format($summary['low_stock_parts']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Active Suppliers</span>
                    <h3><?= number_format($summary['active_suppliers']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Outstanding Payables</span>
                    <h3><?= htmlspecialchars(inventoryCurrency($summary['outstanding_payables']), ENT_QUOTES, 'UTF-8') ?></h3>
                </article>
                <article class="metric-card">
                    <span>Fast-Moving Items</span>
                    <h3><?= number_format($inventoryMovement['fast_moving']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Slow / Idle Items</span>
                    <h3><?= number_format($inventoryMovement['slow_moving'] + $inventoryMovement['idle_items']) ?></h3>
                </article>
            </section>

            <section class="content-grid inventory-grid">
                <article class="content-card">
                    <h3>Inventory Analytics</h3>
                    <p>Movement is based on recent parts usage so the team can react faster to demand and dead stock.</p>

                    <div class="dashboard-list compact-list">
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Fast-moving demand</strong>
                                <p><?= number_format($inventoryMovement['fast_moving']) ?> item(s) are moving quickly and may need tighter restocking coverage.</p>
                            </div>
                            <span class="status-chip status-approved">Fast</span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Slow-moving demand</strong>
                                <p><?= number_format($inventoryMovement['slow_moving']) ?> item(s) are moving slowly and should be reviewed before reordering.</p>
                            </div>
                            <span class="status-chip status-warning">Slow</span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>No recent movement</strong>
                                <p><?= number_format($inventoryMovement['idle_items']) ?> item(s) had no recorded movement in the last 90 days.</p>
                            </div>
                            <span class="status-chip status-inactive">Idle</span>
                        </div>
                    </div>
                </article>

                <article class="content-card">
                    <h3>Availability Guidance</h3>
                    <p>Use these recommended availability terms to keep stock settings aligned with real usage patterns.</p>

                    <?php if (!empty($inventoryMovement['items'])): ?>
                        <div class="dashboard-list compact-list">
                            <?php foreach (array_slice($inventoryMovement['items'], 0, 5) as $movementItem): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars($movementItem['part_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            30d: <?= number_format($movementItem['used_last_30_days']) ?>
                                            | 90d: <?= number_format($movementItem['used_last_90_days']) ?>
                                            | <?= htmlspecialchars($movementItem['availability_term'], ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                    </div>
                                    <span class="status-chip status-<?= htmlspecialchars($movementItem['movement_band'] === 'fast' ? 'approved' : ($movementItem['movement_band'] === 'idle' ? 'inactive' : 'warning'), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($movementItem['movement_band']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            Inventory analytics will appear after stock items start getting used in jobs.
                        </div>
                    <?php endif; ?>
                </article>
            </section>

            <section class="content-grid inventory-grid">
                <article class="content-card">
                    <h3>Parts Inventory</h3>
                    <p>Track stock quantity, reorder thresholds, and standard cost per part.</p>

                    <form action="inventory.php" method="GET" class="feature-toggle-form">
                        <div class="form-grid customer-search-grid">
                            <div class="form-group">
                                <label for="inventory_search">Search Parts</label>
                                <input class="form-control" type="text" id="inventory_search" name="inventory_search" value="<?= htmlspecialchars($inventorySearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search by part name or SKU">
                            </div>
                            <div class="feature-toggle-actions">
                                <button type="submit" class="btn btn-primary">Search</button>
                                <a href="inventory.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($inventoryItems)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($inventoryItems as $item): ?>
                                <?php $isSelected = (int) $item['inventory_id'] === $selectedInventoryId; ?>
                                <?php $inventoryChipClass = ((int) $item['quantity'] <= (int) $item['reorder_level'] && $item['status'] === 'active') ? 'low-stock' : $item['status']; ?>
                                <?php $inventoryChipLabel = ((int) $item['quantity'] <= (int) $item['reorder_level'] && $item['status'] === 'active') ? 'Low Stock' : ucfirst($item['status']); ?>
                                <a class="dashboard-list-item dashboard-link-card<?= $isSelected ? ' is-selected' : '' ?>" href="inventory.php?inventory_id=<?= (int) $item['inventory_id'] ?><?= $selectedSupplierId > 0 ? '&supplier_id=' . $selectedSupplierId : '' ?><?= $selectedPurchaseId > 0 ? '&purchase_id=' . $selectedPurchaseId : '' ?><?= $inventorySearch !== '' ? '&inventory_search=' . urlencode($inventorySearch) : '' ?><?= $supplierSearch !== '' ? '&supplier_search=' . urlencode($supplierSearch) : '' ?><?= $purchaseStatus !== '' ? '&purchase_status=' . urlencode($purchaseStatus) : '' ?>">
                                    <div>
                                        <strong><?= htmlspecialchars($item['part_name'] ?: 'Unnamed part', ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars($item['sku'] ?: 'No SKU', ENT_QUOTES, 'UTF-8') ?>
                                            | Qty <?= number_format((int) $item['quantity']) ?>
                                            | Reorder at <?= number_format((int) $item['reorder_level']) ?>
                                        </p>
                                        <?php foreach ($inventoryMovement['items'] as $movementItem): ?>
                                            <?php if ((int) $movementItem['inventory_id'] === (int) $item['inventory_id']): ?>
                                                <p><?= htmlspecialchars($movementItem['availability_term'], ENT_QUOTES, 'UTF-8') ?> | 30d usage <?= number_format($movementItem['used_last_30_days']) ?></p>
                                                <?php break; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <span class="status-chip status-<?= htmlspecialchars($inventoryChipClass, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($inventoryChipLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            No inventory items matched your current filter.
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3><?= $selectedInventory ? 'Edit Inventory Item' : 'Add Inventory Item' ?></h3>
                    <p><?= $selectedInventory ? 'Update the current stock profile without leaving the tenant workspace.' : 'Create a new stock item that can later be restocked and used in jobs.' ?></p>

                    <form action="<?= $selectedInventory ? 'actions/update_inventory_item.php' : 'actions/create_inventory_item.php' ?>" method="POST" class="feature-toggle-form">
                        <?php if ($selectedInventory): ?>
                            <input type="hidden" name="inventory_id" value="<?= (int) $selectedInventory['inventory_id'] ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="part_name">Part Name</label>
                            <input class="form-control" type="text" id="part_name" name="part_name" value="<?= htmlspecialchars($oldInventoryInput['part_name'] ?? ($selectedInventory['part_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="sku">SKU</label>
                                <input class="form-control" type="text" id="sku" name="sku" value="<?= htmlspecialchars($oldInventoryInput['sku'] ?? ($selectedInventory['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="form-group">
                                <label for="quantity">Opening Quantity</label>
                                <input class="form-control" type="number" id="quantity" name="quantity" min="0" value="<?= htmlspecialchars((string) ($oldInventoryInput['quantity'] ?? ($selectedInventory['quantity'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="unit_cost">Unit Cost</label>
                                <input class="form-control" type="number" id="unit_cost" name="unit_cost" min="0" step="0.01" value="<?= htmlspecialchars((string) ($oldInventoryInput['unit_cost'] ?? ($selectedInventory['unit_cost'] ?? '0.00')), ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="reorder_level">Reorder Level</label>
                                <input class="form-control" type="number" id="reorder_level" name="reorder_level" min="0" value="<?= htmlspecialchars((string) ($oldInventoryInput['reorder_level'] ?? ($selectedInventory['reorder_level'] ?? 5)), ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                        </div>

                        <?php if ($selectedInventory): ?>
                            <div class="form-group">
                                <label for="inventory_status">Status</label>
                                <select class="form-control" id="inventory_status" name="status" required>
                                    <option value="active"<?= (($oldInventoryInput['status'] ?? $selectedInventory['status']) === 'active') ? ' selected' : '' ?>>Active</option>
                                    <option value="inactive"<?= (($oldInventoryInput['status'] ?? $selectedInventory['status']) === 'inactive') ? ' selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="approval-actions">
                            <button type="submit" class="btn btn-primary"><?= $selectedInventory ? 'Save Item Changes' : 'Create Inventory Item' ?></button>
                            <?php if ($selectedInventory): ?>
                                <a href="inventory.php" class="btn btn-secondary">New Item</a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if ($selectedInventory): ?>
                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Inventory Status</strong>
                                    <p>
                                        Added <?= htmlspecialchars(inventoryDate($selectedInventory['created_at']), ENT_QUOTES, 'UTF-8') ?>
                                        | Cost <?= htmlspecialchars(inventoryCurrency($selectedInventory['unit_cost']), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <span class="status-chip status-<?= htmlspecialchars($selectedInventory['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucfirst($selectedInventory['status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                </article>
            </section>

            <section class="content-grid inventory-grid">
                <article class="content-card">
                    <h3>Suppliers</h3>
                    <p>Manage vendor contacts used for restocking and tracking payable balances.</p>

                    <form action="inventory.php" method="GET" class="feature-toggle-form">
                        <div class="form-grid customer-search-grid">
                            <div class="form-group">
                                <label for="supplier_search">Search Suppliers</label>
                                <input class="form-control" type="text" id="supplier_search" name="supplier_search" value="<?= htmlspecialchars($supplierSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search by supplier, contact, phone, or email">
                            </div>
                            <div class="feature-toggle-actions">
                                <button type="submit" class="btn btn-primary">Search</button>
                                <a href="inventory.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($suppliers)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($suppliers as $supplier): ?>
                                <?php $isSelected = (int) $supplier['supplier_id'] === $selectedSupplierId; ?>
                                <a class="dashboard-list-item dashboard-link-card<?= $isSelected ? ' is-selected' : '' ?>" href="inventory.php?supplier_id=<?= (int) $supplier['supplier_id'] ?><?= $selectedInventoryId > 0 ? '&inventory_id=' . $selectedInventoryId : '' ?><?= $selectedPurchaseId > 0 ? '&purchase_id=' . $selectedPurchaseId : '' ?><?= $inventorySearch !== '' ? '&inventory_search=' . urlencode($inventorySearch) : '' ?><?= $supplierSearch !== '' ? '&supplier_search=' . urlencode($supplierSearch) : '' ?><?= $purchaseStatus !== '' ? '&purchase_status=' . urlencode($purchaseStatus) : '' ?>">
                                    <div>
                                        <strong><?= htmlspecialchars($supplier['supplier_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars($supplier['contact_person'] ?: 'No contact person', ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($supplier['phone'])): ?>
                                                | <?= htmlspecialchars($supplier['phone'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <span class="status-chip status-<?= htmlspecialchars($supplier['status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($supplier['status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            No suppliers matched your current filter.
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3><?= $selectedSupplier ? 'Edit Supplier' : 'Add Supplier' ?></h3>
                    <p><?= $selectedSupplier ? 'Update vendor details for stock purchasing and payable follow-up.' : 'Create a supplier record before logging purchases on account.' ?></p>

                    <form action="<?= $selectedSupplier ? 'actions/update_supplier.php' : 'actions/create_supplier.php' ?>" method="POST" class="feature-toggle-form">
                        <?php if ($selectedSupplier): ?>
                            <input type="hidden" name="supplier_id" value="<?= (int) $selectedSupplier['supplier_id'] ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="supplier_name">Supplier Name</label>
                            <input class="form-control" type="text" id="supplier_name" name="supplier_name" value="<?= htmlspecialchars($oldSupplierInput['supplier_name'] ?? ($selectedSupplier['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="contact_person">Contact Person</label>
                                <input class="form-control" type="text" id="contact_person" name="contact_person" value="<?= htmlspecialchars($oldSupplierInput['contact_person'] ?? ($selectedSupplier['contact_person'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="form-group">
                                <label for="phone">Phone</label>
                                <input class="form-control" type="text" id="phone" name="phone" value="<?= htmlspecialchars($oldSupplierInput['phone'] ?? ($selectedSupplier['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="supplier_email">Email</label>
                            <input class="form-control" type="email" id="supplier_email" name="email" value="<?= htmlspecialchars($oldSupplierInput['email'] ?? ($selectedSupplier['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="form-group">
                            <label for="supplier_address">Address</label>
                            <textarea class="form-control form-textarea" id="supplier_address" name="address"><?= htmlspecialchars($oldSupplierInput['address'] ?? ($selectedSupplier['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>

                        <?php if ($selectedSupplier): ?>
                            <div class="form-group">
                                <label for="supplier_status">Status</label>
                                <select class="form-control" id="supplier_status" name="status" required>
                                    <option value="active"<?= (($oldSupplierInput['status'] ?? $selectedSupplier['status']) === 'active') ? ' selected' : '' ?>>Active</option>
                                    <option value="inactive"<?= (($oldSupplierInput['status'] ?? $selectedSupplier['status']) === 'inactive') ? ' selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="approval-actions">
                            <button type="submit" class="btn btn-primary"><?= $selectedSupplier ? 'Save Supplier Changes' : 'Create Supplier' ?></button>
                            <?php if ($selectedSupplier): ?>
                                <a href="inventory.php" class="btn btn-secondary">New Supplier</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </article>
            </section>

            <section class="content-grid inventory-grid">
                <article class="content-card">
                    <h3>Supplier Payables</h3>
                    <p>Log stock purchases, monitor outstanding balances, and track which supplier invoices are still unpaid.</p>

                    <form action="inventory.php" method="GET" class="feature-toggle-form">
                        <div class="form-grid customer-search-grid">
                            <div class="form-group">
                                <label for="purchase_status">Payable Status</label>
                                <select class="form-control" id="purchase_status" name="purchase_status">
                                    <option value="">All statuses</option>
                                    <option value="unpaid"<?= $purchaseStatus === 'unpaid' ? ' selected' : '' ?>>Unpaid</option>
                                    <option value="partial"<?= $purchaseStatus === 'partial' ? ' selected' : '' ?>>Partial</option>
                                    <option value="paid"<?= $purchaseStatus === 'paid' ? ' selected' : '' ?>>Paid</option>
                                    <option value="cancelled"<?= $purchaseStatus === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="feature-toggle-actions">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="inventory.php" class="btn btn-secondary">Reset</a>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($purchases)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($purchases as $purchase): ?>
                                <?php $isSelected = (int) $purchase['purchase_id'] === $selectedPurchaseId; ?>
                                <a class="dashboard-list-item dashboard-link-card<?= $isSelected ? ' is-selected' : '' ?>" href="inventory.php?purchase_id=<?= (int) $purchase['purchase_id'] ?><?= $selectedInventoryId > 0 ? '&inventory_id=' . $selectedInventoryId : '' ?><?= $selectedSupplierId > 0 ? '&supplier_id=' . $selectedSupplierId : '' ?><?= $purchaseStatus !== '' ? '&purchase_status=' . urlencode($purchaseStatus) : '' ?>">
                                    <div>
                                        <strong><?= htmlspecialchars($purchase['supplier_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars($purchase['part_name'] ?: 'Stock purchase', ENT_QUOTES, 'UTF-8') ?>
                                            | <?= htmlspecialchars(inventoryDate($purchase['purchase_date']), ENT_QUOTES, 'UTF-8') ?>
                                            | Outstanding <?= htmlspecialchars(inventoryCurrency(inventoryOutstandingAmount($purchase)), ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                    </div>
                                    <span class="status-chip status-<?= htmlspecialchars($purchase['payable_status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($purchase['payable_status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            No supplier purchases matched your current filter.
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3>Record Stock Purchase</h3>
                    <p>Create a supplier purchase that increases stock and opens a payable balance for later settlement.</p>

                    <?php if (empty($supplierOptions) || empty($inventoryOptions)): ?>
                        <div class="table-placeholder">
                            Add at least one supplier and one inventory item first before recording a purchase.
                        </div>
                    <?php else: ?>
                        <form action="actions/create_inventory_purchase.php" method="POST" class="feature-toggle-form">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="purchase_supplier_id">Supplier</label>
                                    <select class="form-control" id="purchase_supplier_id" name="supplier_id" required>
                                        <option value="">Select supplier</option>
                                        <?php foreach ($supplierOptions as $supplier): ?>
                                            <option
                                                value="<?= (int) $supplier['supplier_id'] ?>"
                                                <?= ((int) ($oldPurchaseInput['supplier_id'] ?? ($selectedPurchase['supplier_id'] ?? 0)) === (int) $supplier['supplier_id']) ? ' selected' : '' ?>
                                                <?= ($supplier['status'] !== 'active' && (int) ($oldPurchaseInput['supplier_id'] ?? ($selectedPurchase['supplier_id'] ?? 0)) !== (int) $supplier['supplier_id']) ? ' disabled' : '' ?>
                                            >
                                                <?= htmlspecialchars($supplier['supplier_name'], ENT_QUOTES, 'UTF-8') ?><?= $supplier['status'] !== 'active' ? ' (Inactive)' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="purchase_inventory_id">Inventory Item</label>
                                    <select class="form-control" id="purchase_inventory_id" name="inventory_id" required>
                                        <option value="">Select item</option>
                                        <?php foreach ($inventoryOptions as $item): ?>
                                            <option
                                                value="<?= (int) $item['inventory_id'] ?>"
                                                <?= ((int) ($oldPurchaseInput['inventory_id'] ?? ($selectedPurchase['inventory_id'] ?? 0)) === (int) $item['inventory_id']) ? ' selected' : '' ?>
                                                <?= ($item['status'] !== 'active' && (int) ($oldPurchaseInput['inventory_id'] ?? ($selectedPurchase['inventory_id'] ?? 0)) !== (int) $item['inventory_id']) ? ' disabled' : '' ?>
                                            >
                                                <?= htmlspecialchars($item['part_name'], ENT_QUOTES, 'UTF-8') ?><?= !empty($item['sku']) ? ' - ' . htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') : '' ?><?= $item['status'] !== 'active' ? ' (Inactive)' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="reference_no">Reference No.</label>
                                    <input class="form-control" type="text" id="reference_no" name="reference_no" value="<?= htmlspecialchars($oldPurchaseInput['reference_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="purchase_date">Purchase Date</label>
                                    <input class="form-control" type="date" id="purchase_date" name="purchase_date" value="<?= htmlspecialchars($oldPurchaseInput['purchase_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="due_date">Due Date</label>
                                    <input class="form-control" type="date" id="due_date" name="due_date" value="<?= htmlspecialchars($oldPurchaseInput['due_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="purchase_quantity">Quantity</label>
                                    <input class="form-control" type="number" id="purchase_quantity" name="quantity" min="1" value="<?= htmlspecialchars((string) ($oldPurchaseInput['quantity'] ?? 1), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="purchase_unit_cost">Unit Cost</label>
                                    <input class="form-control" type="number" id="purchase_unit_cost" name="unit_cost" min="0" step="0.01" value="<?= htmlspecialchars((string) ($oldPurchaseInput['unit_cost'] ?? '0.00'), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="amount_paid">Amount Paid Now</label>
                                    <input class="form-control" type="number" id="amount_paid" name="amount_paid" min="0" step="0.01" value="<?= htmlspecialchars((string) ($oldPurchaseInput['amount_paid'] ?? '0.00'), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="purchase_notes">Notes</label>
                                <textarea class="form-control form-textarea" id="purchase_notes" name="notes"><?= htmlspecialchars($oldPurchaseInput['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>

                            <div class="table-placeholder">
                                New purchases can only be recorded against active suppliers and active inventory items. Inactive records stay visible for history but are not available for new payable entries.
                            </div>

                            <div class="approval-actions">
                                <button type="submit" class="btn btn-primary">Record Purchase</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($selectedPurchase): ?>
                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Selected Payable</strong>
                                    <p>
                                        <?= htmlspecialchars($selectedPurchase['supplier_name'], ENT_QUOTES, 'UTF-8') ?>
                                        | Total <?= htmlspecialchars(inventoryCurrency($selectedPurchase['total_amount']), ENT_QUOTES, 'UTF-8') ?>
                                        | Paid <?= htmlspecialchars(inventoryCurrency($selectedPurchase['amount_paid']), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <span class="status-chip status-<?= htmlspecialchars($selectedPurchase['payable_status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucfirst($selectedPurchase['payable_status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($selectedPurchase['payable_status'] !== 'paid' && $selectedPurchase['payable_status'] !== 'cancelled'): ?>
                            <form action="actions/create_supplier_payment.php" method="POST" class="feature-toggle-form">
                                <input type="hidden" name="purchase_id" value="<?= (int) $selectedPurchase['purchase_id'] ?>">

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="payment_date">Payment Date</label>
                                        <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= htmlspecialchars($oldPaymentInput['payment_date'] ?? ($selectedPurchase['purchase_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="payment_amount">Amount</label>
                                        <input class="form-control" type="number" id="payment_amount" name="amount" min="0.01" step="0.01" value="<?= htmlspecialchars((string) ($oldPaymentInput['amount'] ?? inventoryOutstandingAmount($selectedPurchase)), ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="payment_method">Payment Method</label>
                                        <input class="form-control" type="text" id="payment_method" name="payment_method" value="<?= htmlspecialchars($oldPaymentInput['payment_method'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Cash, bank transfer, check, etc.">
                                    </div>
                                    <div class="form-group">
                                        <label for="payment_reference_no">Reference No.</label>
                                        <input class="form-control" type="text" id="payment_reference_no" name="reference_no" value="<?= htmlspecialchars($oldPaymentInput['reference_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="payment_notes">Notes</label>
                                    <textarea class="form-control form-textarea" id="payment_notes" name="notes"><?= htmlspecialchars($oldPaymentInput['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>

                                <div class="table-placeholder">
                                    Supplier payment dates should be on or after the original purchase date of <?= htmlspecialchars(inventoryDate($selectedPurchase['purchase_date']), ENT_QUOTES, 'UTF-8') ?>.
                                </div>

                                <div class="approval-actions">
                                    <button type="submit" class="btn btn-primary">Record Supplier Payment</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Recent Supplier Payments</strong>
                                    <p>Outstanding balance: <?= htmlspecialchars(inventoryCurrency(inventoryOutstandingAmount($selectedPurchase)), ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </div>

                            <?php if (!empty($purchasePayments)): ?>
                                <?php foreach ($purchasePayments as $payment): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars(inventoryCurrency($payment['amount']), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p>
                                                <?= htmlspecialchars(inventoryDate($payment['payment_date']), ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($payment['payment_method'])): ?>
                                                    | <?= htmlspecialchars($payment['payment_method'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                                <?php if (!empty($payment['reference_no'])): ?>
                                                    | Ref <?= htmlspecialchars($payment['reference_no'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="table-placeholder">
                                    No supplier payments have been recorded for this purchase yet.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            <strong>Current tenant admin</strong><br>
                            Signed in as <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>. Inventory, suppliers, and payable balances on this page are filtered by <code>tenant_id = <?= $tenantId ?></code>.
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        </main>
    </div>

    <?= renderTenantAdminFooterScripts() ?>
</body>
</html>
