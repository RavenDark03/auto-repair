<?php
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../settings.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$businessName = trim($_POST['business_name'] ?? '');
$contactPhone = trim($_POST['contact_phone'] ?? '');
$contactEmail = trim($_POST['contact_email'] ?? '');
$address = trim($_POST['address'] ?? '');
$operatingHours = trim($_POST['operating_hours'] ?? '');

if ($businessName === '') {
    $_SESSION['settings_error'] = 'Business name is required.';
    header('Location: ../settings.php');
    exit;
}

if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['settings_error'] = 'Please provide a valid contact email.';
    header('Location: ../settings.php');
    exit;
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("
        UPDATE tenants
        SET business_name = :business_name,
            contact_phone = :contact_phone,
            contact_email = :contact_email,
            address = :address,
            operating_hours = :operating_hours
        WHERE tenant_id = :tenant_id
    ");
    $stmt->execute([
        'business_name' => $businessName,
        'contact_phone' => $contactPhone !== '' ? $contactPhone : null,
        'contact_email' => $contactEmail !== '' ? $contactEmail : null,
        'address' => $address !== '' ? $address : null,
        'operating_hours' => $operatingHours !== '' ? $operatingHours : null,
        'tenant_id' => $tenantId,
    ]);

    $_SESSION['business_name'] = $businessName;
    $_SESSION['settings_success'] = 'Shop profile updated successfully.';
    header('Location: ../settings.php');
    exit;
} catch (PDOException $e) {
    $_SESSION['settings_error'] = 'Shop settings could not be saved: ' . $e->getMessage();
    header('Location: ../settings.php');
    exit;
}
?>
