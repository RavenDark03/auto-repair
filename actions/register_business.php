<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/email_helper.php';

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
$password = (string) ($_POST['password'] ?? '');
$passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
$birTinRaw = trim($_POST['bir_tin'] ?? '');
$birTinDigits = preg_replace('/\D+/', '', $birTinRaw) ?? '';
$ownerIdNumber = preg_replace('/\s+/', '', trim($_POST['owner_id_number'] ?? ''));
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
    'bir_tin' => $birTinRaw,
    'owner_id_number' => $ownerIdNumber,
    'selected_plan_id' => $selectedPlanId,
    'billing_cycle' => $billingCycle,
    'requested_features' => $requestedFeatureIds,
];

$fieldErrors = [];

if ($businessName === '' || $ownerFullName === '' || $email === '' || $selectedPlanId <= 0) {
    $fieldErrors['general'] = 'Please complete the required registration fields.';
}

if ($preferredUsername === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $preferredUsername)) {
    $fieldErrors['preferred_username'] = 'Choose an admin username: 3–30 letters, numbers, or underscore only.';
}

if (strlen($password) < 10) {
    $fieldErrors['password'] = 'Password must be at least 10 characters.';
} elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    $fieldErrors['password'] = 'Password must include at least one letter and one number.';
} elseif ($password !== $passwordConfirm) {
    $fieldErrors['password'] = 'Passwords do not match.';
    $fieldErrors['password_confirm'] = 'Passwords do not match.';
}

if ($birTinDigits === '' || strlen($birTinDigits) < 9 || strlen($birTinDigits) > 12) {
    $fieldErrors['bir_tin'] = 'Enter a valid BIR TIN (9–12 digits).';
}

if ($ownerIdNumber === '' || strlen($ownerIdNumber) < 4 || strlen($ownerIdNumber) > 32) {
    $fieldErrors['owner_id_number'] = 'Enter the ID number as shown on the document (4–32 characters, no spaces).';
} elseif (!preg_match('/^[A-Za-z0-9\-]+$/', $ownerIdNumber)) {
    $fieldErrors['owner_id_number'] = 'ID number may contain only letters, digits, and hyphens.';
}

$uploadedFile = $_FILES['owner_id_document'] ?? null;
if (!$uploadedFile || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $fieldErrors['owner_id_document'] = 'Upload a valid government or business ID (PDF, JPG, or PNG).';
} elseif (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $fieldErrors['owner_id_document'] = 'ID upload failed. Try a smaller file or a different format.';
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

$ownerIdMime = null;
$ownerIdExtension = null;
if ($uploadedFile && (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $maxBytes = 5 * 1024 * 1024;
    if ((int) ($uploadedFile['size'] ?? 0) > $maxBytes) {
        $_SESSION['registration_field_errors'] = ['owner_id_document' => 'ID file must be 5 MB or smaller.'];
        header('Location: ../register.php?plan_id=' . $selectedPlanId);
        exit;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $uploadedFile['tmp_name']) ?: '';
    $mimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($mimeMap[$mime])) {
        $_SESSION['registration_field_errors'] = ['owner_id_document' => 'Allowed types: PDF, JPG, PNG, or WebP.'];
        header('Location: ../register.php?plan_id=' . $selectedPlanId);
        exit;
    }
    $ownerIdExtension = $mimeMap[$mime];
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

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
            password_hash,
            bir_tin,
            owner_id_number,
            owner_id_document_path,
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
            :password_hash,
            :bir_tin,
            :owner_id_number,
            NULL,
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
        'preferred_username' => $preferredUsername,
        'password_hash' => $passwordHash,
        'bir_tin' => $birTinDigits,
        'owner_id_number' => $ownerIdNumber,
        'selected_plan_id' => $selectedPlanId,
        'billing_cycle' => $billingCycle,
    ]);

    $registrationId = (int) $pdo->lastInsertId();

    $relativeDocPath = null;
    if ($ownerIdExtension !== null && $uploadedFile && (int) $uploadedFile['error'] === UPLOAD_ERR_OK) {
        $destDir = __DIR__ . '/../uploads/registrations/' . $registrationId;
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new RuntimeException('Could not create upload directory for your ID document.');
        }
        $destFile = $destDir . '/owner_id.' . $ownerIdExtension;
        if (!move_uploaded_file((string) $uploadedFile['tmp_name'], $destFile)) {
            throw new RuntimeException('Could not save the uploaded ID document.');
        }
        $relativeDocPath = 'uploads/registrations/' . $registrationId . '/owner_id.' . $ownerIdExtension;
        $pathUpdate = $pdo->prepare('UPDATE tenant_registrations SET owner_id_document_path = :path WHERE registration_id = :id');
        $pathUpdate->execute(['path' => $relativeDocPath, 'id' => $registrationId]);
    }

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

    // Send "registration received – please wait 3-5 days" email
    $waitEmailHtml = mechanix_email_html('
        <h2>Registration Received!</h2>
        <p>Hi <strong>' . htmlspecialchars($ownerFullName, ENT_QUOTES, 'UTF-8') . '</strong>, thank you for registering <strong>' . htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') . '</strong> on MECHANIX.</p>
        <p>Your application is now under review. Our team will verify your business details within <strong>3–5 business days</strong>.</p>
        <div class="info-box">
            <p>What happens next?</p>
            <div class="val">✔ Business verification review (3–5 days)</div>
            <div class="val">✔ You will receive an email when approved</div>
            <div class="val">✔ Use the link in that email to log in &amp; pay your subscription</div>
        </div>
        <hr class="divider">
        <p style="font-size:13px;color:#64748b">If you have questions, please contact MECHANIX support. Do not reply to this email.</p>
    ');
    mechanix_send_email(
        $email,
        'MECHANIX – Registration Received',
        $waitEmailHtml,
        $registrationId,
        'registration_received',
        $pdo
    );

    $pdo->commit();

    unset($_SESSION['registration_old_input'], $_SESSION['error_message'], $_SESSION['registration_field_errors']);
    $_SESSION['registration_success'] = 'Your registration was submitted successfully! Please check your email — we will notify you within 3–5 business days once your business is verified.';
    header('Location: ../login.php');
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['error_message'] = 'Registration failed: ' . $e->getMessage();
    header('Location: ../register.php?plan_id=' . $selectedPlanId);
    exit;
}
