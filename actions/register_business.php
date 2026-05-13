<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../register.php');
    exit;
}

const REGISTRATION_NCR_REGION_CODE = '130000000';

$businessName = trim($_POST['business_name'] ?? '');
$ownerFullName = trim($_POST['owner_full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = preg_replace('/\s+/', '', trim($_POST['phone'] ?? ''));
$addressLine1 = trim($_POST['address_line1'] ?? '');
$addressLine2 = trim($_POST['address_line2'] ?? '');
$addressRegionCode = trim($_POST['address_region_code'] ?? '');
$addressProvinceCode = trim($_POST['address_province_code'] ?? '');
$addressCityCode = trim($_POST['address_city_code'] ?? '');
$addressBrgyCode = trim($_POST['address_brgy_code'] ?? '');
$addressRegionName = trim($_POST['address_region_name'] ?? '');
$addressProvinceName = trim($_POST['address_province_name'] ?? '');
$addressCityName = trim($_POST['address_city_name'] ?? '');
$addressBrgyName = trim($_POST['address_brgy_name'] ?? '');
$preferredUsername = trim($_POST['preferred_username'] ?? '');
$selectedPlanId = (int) ($_POST['selected_plan_id'] ?? 0);
$billingCycle = $_POST['billing_cycle'] ?? 'monthly';
$requestedFeatures = $_POST['requested_features'] ?? [];
$requestedFeatureIds = [];

foreach ($requestedFeatures as $featureId) {
    $requestedFeatureIds[] = (int) $featureId;
}

$requestedFeatureIds = array_values(array_unique(array_filter($requestedFeatureIds)));

$_SESSION['registration_old_input'] = [
    'business_name' => $businessName,
    'owner_full_name' => $ownerFullName,
    'email' => $email,
    'phone' => $phone,
    'address_line1' => $addressLine1,
    'address_line2' => $addressLine2,
    'address_region_code' => $addressRegionCode,
    'address_province_code' => $addressProvinceCode,
    'address_city_code' => $addressCityCode,
    'address_brgy_code' => $addressBrgyCode,
    'address_region_name' => $addressRegionName,
    'address_province_name' => $addressProvinceName,
    'address_city_name' => $addressCityName,
    'address_brgy_name' => $addressBrgyName,
    'preferred_username' => $preferredUsername,
    'selected_plan_id' => $selectedPlanId,
    'billing_cycle' => $billingCycle,
    'requested_features' => $requestedFeatureIds,
];

$fieldErrors = [];

if ($businessName === '' || $ownerFullName === '' || $email === '' || $selectedPlanId <= 0) {
    $fieldErrors['general'] = 'Please complete the required registration fields.';
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fieldErrors['email'] = 'Please enter a valid email address.';
}

if ($phone === '' || !preg_match('/^\+639[0-9]{9}$/', $phone)) {
    $fieldErrors['phone'] = 'Phone must be a Philippine mobile: +639 followed by exactly 9 digits.';
}

if ($addressLine1 === '') {
    $fieldErrors['address_line1'] = 'Address line 1 is required.';
} elseif (strlen($addressLine1) > 200) {
    $fieldErrors['address_line1'] = 'Address line 1 is too long (max 200 characters).';
}

if ($addressLine2 !== '' && strlen($addressLine2) > 200) {
    $fieldErrors['address_line2'] = 'Address line 2 is too long (max 200 characters).';
}

if ($addressRegionCode === '' || !preg_match('/^[0-9]{9}$/', $addressRegionCode)) {
    $fieldErrors['address_region_code'] = 'Select a valid region.';
}

if ($addressRegionCode !== '' && $addressRegionCode !== REGISTRATION_NCR_REGION_CODE) {
    if ($addressProvinceCode === '' || !preg_match('/^[0-9]{9}$/', $addressProvinceCode)) {
        $fieldErrors['address_city_code'] = 'Select a valid province.';
    }
}

if ($addressCityCode === '' || !preg_match('/^[0-9]{9}$/', $addressCityCode)) {
    $fieldErrors['address_city_code'] = 'Select a valid city or municipality.';
}

if ($addressBrgyCode === '' || !preg_match('/^[0-9]{9}$/', $addressBrgyCode)) {
    $fieldErrors['address_brgy_code'] = 'Select a valid barangay.';
}

if ($addressCityName === '' || strlen($addressCityName) > 120) {
    $fieldErrors['address_city_code'] = isset($fieldErrors['address_city_code'])
        ? $fieldErrors['address_city_code']
        : 'City or municipality name is missing or invalid.';
}

if ($addressBrgyName === '' || strlen($addressBrgyName) > 120) {
    $fieldErrors['address_brgy_code'] = isset($fieldErrors['address_brgy_code'])
        ? $fieldErrors['address_brgy_code']
        : 'Barangay name is missing or invalid.';
}

if ($addressRegionName === '' || strlen($addressRegionName) > 120) {
    $fieldErrors['address_region_code'] = isset($fieldErrors['address_region_code'])
        ? $fieldErrors['address_region_code']
        : 'Region name is missing or invalid.';
}

if ($addressRegionCode !== REGISTRATION_NCR_REGION_CODE && $addressProvinceName === '') {
    $fieldErrors['address_city_code'] = $fieldErrors['address_city_code'] ?? 'Province name is required outside NCR.';
}

if (!in_array($billingCycle, ['monthly', 'yearly'], true)) {
    $_SESSION['error_message'] = 'Please choose a valid billing cycle.';
    header('Location: ../register.php?plan_id=' . $selectedPlanId);
    exit;
}

if (!empty($fieldErrors)) {
    $_SESSION['registration_field_errors'] = $fieldErrors;
    if (isset($fieldErrors['general'])) {
        $_SESSION['error_message'] = $fieldErrors['general'];
    }
    header('Location: ../register.php?plan_id=' . $selectedPlanId);
    exit;
}

$addressParts = [$addressLine1];
if ($addressLine2 !== '') {
    $addressParts[] = $addressLine2;
}
$addressParts[] = $addressBrgyName . ', ' . $addressCityName;
$locMeta = $addressRegionName;
if ($addressProvinceName !== '') {
    $locMeta .= ' · ' . $addressProvinceName;
}
$addressParts[] = $locMeta;
$addressParts[] = 'PSGC city ' . $addressCityCode . ' · brgy ' . $addressBrgyCode;
$address = implode("\n", $addressParts);

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    $planStmt = $pdo->prepare(" 
        SELECT plan_id
        FROM subscription_plans
        WHERE plan_id = :plan_id
          AND is_active = 1
        LIMIT 1
    ");
    $planStmt->execute(['plan_id' => $selectedPlanId]);

    if (!$planStmt->fetch()) {
        throw new RuntimeException('The selected subscription plan is not available.');
    }

    $validFeatureIds = [];
    if (!empty($requestedFeatureIds)) {
        $featureStmt = $pdo->query(" 
            SELECT fp.feature_id
            FROM feature_pricing fp
            WHERE fp.is_active = 1
        ");
        $validFeatureIds = array_map('intval', $featureStmt->fetchAll(PDO::FETCH_COLUMN));
    }

    foreach ($requestedFeatureIds as $featureId) {
        if (!in_array($featureId, $validFeatureIds, true)) {
            throw new RuntimeException('One or more selected add-on features are invalid.');
        }
    }

    $registrationStmt = $pdo->prepare(" 
        INSERT INTO tenant_registrations (
            business_name,
            owner_full_name,
            email,
            phone,
            address,
            preferred_username,
            selected_plan_id,
            billing_cycle,
            registration_status
        ) VALUES (
            :business_name,
            :owner_full_name,
            :email,
            :phone,
            :address,
            :preferred_username,
            :selected_plan_id,
            :billing_cycle,
            'pending'
        )
    ");

    $registrationStmt->execute([
        'business_name' => $businessName,
        'owner_full_name' => $ownerFullName,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'preferred_username' => $preferredUsername !== '' ? $preferredUsername : null,
        'selected_plan_id' => $selectedPlanId,
        'billing_cycle' => $billingCycle,
    ]);

    $registrationId = (int) $pdo->lastInsertId();

    if (!empty($requestedFeatureIds)) {
        $featureInsertStmt = $pdo->prepare(" 
            INSERT INTO registration_requested_features (
                registration_id,
                feature_id,
                is_requested
            ) VALUES (
                :registration_id,
                :feature_id,
                1
            )
        ");

        foreach ($requestedFeatureIds as $featureId) {
            $featureInsertStmt->execute([
                'registration_id' => $registrationId,
                'feature_id' => $featureId,
            ]);
        }
    }

    $emailLogStmt = $pdo->prepare(" 
        INSERT INTO email_logs (
            registration_id,
            recipient_email,
            subject,
            body,
            email_type,
            send_status
        ) VALUES (
            :registration_id,
            :recipient_email,
            :subject,
            :body,
            'registration_received',
            'pending'
        )
    ");
    $emailLogStmt->execute([
        'registration_id' => $registrationId,
        'recipient_email' => $email,
        'subject' => 'MECHANIX registration received',
        'body' => 'Your registration has been received and is pending super admin review.',
    ]);

    $pdo->commit();

    unset($_SESSION['registration_old_input'], $_SESSION['error_message'], $_SESSION['registration_field_errors']);
    $_SESSION['registration_success'] = 'Your registration was submitted successfully. The super admin can now review your plan and requested features.';
    header('Location: ../register.php');
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['error_message'] = 'Registration failed: ' . $e->getMessage();
    header('Location: ../register.php?plan_id=' . $selectedPlanId);
    exit;
}
