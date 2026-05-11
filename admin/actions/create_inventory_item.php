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
$partName = trim($_POST['part_name'] ?? '');
$sku = trim($_POST['sku'] ?? '');
$quantity = trim($_POST['quantity'] ?? '');
$unitCost = trim($_POST['unit_cost'] ?? '');
$reorderLevel = trim($_POST['reorder_level'] ?? '');

$_SESSION['inventory_old_input'] = [
    'part_name' => $partName,
    'sku' => $sku,
    'quantity' => $quantity,
    'unit_cost' => $unitCost,
    'reorder_level' => $reorderLevel,
];

if ($partName === '' || $quantity === '' || $unitCost === '' || $reorderLevel === '') {
    $_SESSION['inventory_error'] = 'Part name, quantity, unit cost, and reorder level are required.';
    header('Location: ../inventory.php');
    exit;
}

if (!ctype_digit($quantity) || !ctype_digit($reorderLevel) || (int) $quantity < 0 || (int) $reorderLevel < 0) {
    $_SESSION['inventory_error'] = 'Quantity and reorder level must be valid non-negative whole numbers.';
    header('Location: ../inventory.php');
    exit;
}

if (!is_numeric($unitCost) || (float) $unitCost < 0) {
    $_SESSION['inventory_error'] = 'Unit cost must be a valid non-negative amount.';
    header('Location: ../inventory.php');
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("
        INSERT INTO inventory (
            tenant_id,
            part_name,
            sku,
            quantity,
            unit_cost,
            reorder_level,
            status
        ) VALUES (
            :tenant_id,
            :part_name,
            :sku,
            :quantity,
            :unit_cost,
            :reorder_level,
            'active'
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'part_name' => $partName,
        'sku' => $sku !== '' ? $sku : null,
        'quantity' => (int) $quantity,
        'unit_cost' => number_format((float) $unitCost, 2, '.', ''),
        'reorder_level' => (int) $reorderLevel,
    ]);

    $inventoryId = (int) $pdo->lastInsertId();
    unset($_SESSION['inventory_old_input']);
    $_SESSION['inventory_success'] = 'Inventory item created successfully.';
    header('Location: ../inventory.php?inventory_id=' . $inventoryId);
    exit;
} catch (PDOException $e) {
    $_SESSION['inventory_error'] = 'Inventory item creation failed: ' . $e->getMessage();
    header('Location: ../inventory.php');
    exit;
}
?>
