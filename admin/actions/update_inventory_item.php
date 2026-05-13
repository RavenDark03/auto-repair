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
$inventoryId = (int) ($_POST['inventory_id'] ?? 0);
$partName = trim($_POST['part_name'] ?? '');
$sku = trim($_POST['sku'] ?? '');
$quantity = trim($_POST['quantity'] ?? '');
$unitCost = trim($_POST['unit_cost'] ?? '');
$reorderLevel = trim($_POST['reorder_level'] ?? '');
$status = $_POST['status'] ?? '';
$allowedStatuses = ['active', 'inactive'];

$_SESSION['inventory_old_input'] = [
    'part_name' => $partName,
    'sku' => $sku,
    'quantity' => $quantity,
    'unit_cost' => $unitCost,
    'reorder_level' => $reorderLevel,
    'status' => $status,
];

if ($inventoryId <= 0 || $partName === '' || $quantity === '' || $unitCost === '' || $reorderLevel === '') {
    $_SESSION['inventory_error'] = 'A valid inventory item, part name, quantity, unit cost, and reorder level are required.';
    header('Location: ../inventory.php' . ($inventoryId > 0 ? '?inventory_id=' . $inventoryId : ''));
    exit;
}

if (!in_array($status, $allowedStatuses, true)) {
    $_SESSION['inventory_error'] = 'Please choose a valid inventory status.';
    header('Location: ../inventory.php?inventory_id=' . $inventoryId);
    exit;
}

if (!ctype_digit($quantity) || !ctype_digit($reorderLevel) || (int) $quantity < 0 || (int) $reorderLevel < 0) {
    $_SESSION['inventory_error'] = 'Quantity and reorder level must be valid non-negative whole numbers.';
    header('Location: ../inventory.php?inventory_id=' . $inventoryId);
    exit;
}

if (!is_numeric($unitCost) || (float) $unitCost < 0) {
    $_SESSION['inventory_error'] = 'Unit cost must be a valid non-negative amount.';
    header('Location: ../inventory.php?inventory_id=' . $inventoryId);
    exit;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $existingStmt = $pdo->prepare("
        SELECT inventory_id, quantity
        FROM inventory
        WHERE inventory_id = :inventory_id
          AND tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $existingStmt->execute([
        'inventory_id' => $inventoryId,
        'tenant_id' => $tenantId,
    ]);
    $existingInventory = $existingStmt->fetch();

    if (!$existingInventory) {
        $pdo->rollBack();
        $_SESSION['inventory_error'] = 'That inventory item could not be found in this tenant.';
        header('Location: ../inventory.php');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE inventory
        SET part_name = :part_name,
            sku = :sku,
            quantity = :quantity,
            unit_cost = :unit_cost,
            reorder_level = :reorder_level,
            status = :status
        WHERE inventory_id = :inventory_id
          AND tenant_id = :tenant_id
    ");
    $stmt->execute([
        'part_name' => $partName,
        'sku' => $sku !== '' ? $sku : null,
        'quantity' => (int) $quantity,
        'unit_cost' => number_format((float) $unitCost, 2, '.', ''),
        'reorder_level' => (int) $reorderLevel,
        'status' => $status,
        'inventory_id' => $inventoryId,
        'tenant_id' => $tenantId,
    ]);

    $quantityBefore = (int) $existingInventory['quantity'];
    $quantityAfter = (int) $quantity;
    $quantityChange = $quantityAfter - $quantityBefore;

    if ($quantityChange !== 0) {
        $movementStmt = $pdo->prepare("
            INSERT INTO inventory_movements (
                tenant_id,
                inventory_id,
                user_id,
                movement_type,
                quantity_change,
                quantity_before,
                quantity_after,
                reference_type,
                reference_id,
                notes
            ) VALUES (
                :tenant_id,
                :inventory_id,
                :user_id,
                'adjustment',
                :quantity_change,
                :quantity_before,
                :quantity_after,
                'inventory_item',
                :reference_id,
                :notes
            )
        ");
        $movementStmt->execute([
            'tenant_id' => $tenantId,
            'inventory_id' => $inventoryId,
            'user_id' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
            'quantity_change' => $quantityChange,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'reference_id' => $inventoryId,
            'notes' => 'Manual stock adjustment from inventory editor',
        ]);
    }

    $pdo->commit();
    unset($_SESSION['inventory_old_input']);
    $_SESSION['inventory_success'] = 'Inventory item updated successfully.';
    header('Location: ../inventory.php?inventory_id=' . $inventoryId);
    exit;
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['inventory_error'] = 'Inventory item update failed: ' . $e->getMessage();
    header('Location: ../inventory.php?inventory_id=' . $inventoryId);
    exit;
}
?>
