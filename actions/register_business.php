<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../register.php');
    exit;
}

$businessName = trim($_POST['business_name'] ?? '');
$ownerFullName = trim($_POST['owner_full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
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
    'address' => $address,
    'preferred_username' => $preferredUsername,
    'selected_plan_id' => $selectedPlanId,
    'billing_cycle' => $billingCycle,
    'requested_features' => $requestedFeatureIds,
];

if ($businessName === '' || $ownerFullName === '' || $email === '' || $selectedPlanId <= 0) {
    $_SESSION['error_message'] = 'Please complete the required registration fields.';
    header('Location: ../register.php?plan_id=' . $selectedPlanId);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error_message'] = 'Please enter a valid email address.';
    header('Location: ../register.php?plan_id=' . $selectedPlanId);
    exit;
}

if (!in_array($billingCycle, ['monthly', 'yearly'], true)) {
    $_SESSION['error_message'] = 'Please choose a valid billing cycle.';
    header('Location: ../register.php?plan_id=' . $selectedPlanId);
    exit;
}

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
        'phone' => $phone !== '' ? $phone : null,
        'address' => $address !== '' ? $address : null,
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

    unset($_SESSION['registration_old_input'], $_SESSION['error_message']);
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
?>
