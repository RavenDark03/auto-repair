<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/config.php';

requireSuperAdmin();

$selectedTenantId = (int) ($_GET['tenant_id'] ?? 0);
$selectedRegistrationId = (int) ($_GET['registration_id'] ?? 0);
$registrationSearch = trim($_GET['registration_search'] ?? '');
$registrationStatusFilter = $_GET['registration_status'] ?? '';
$registrationBillingStatusFilter = $_GET['registration_billing_status'] ?? '';
$tenantSearch = trim($_GET['tenant_search'] ?? '');
$tenantStatusFilter = $_GET['tenant_status'] ?? '';
$subscriptionStatusFilter = $_GET['subscription_status'] ?? '';
$flashMessage = $_SESSION['super_admin_success'] ?? null;
$errorMessage = $_SESSION['super_admin_error'] ?? null;
$tenantOnboarding = $_SESSION['tenant_onboarding'] ?? null;
$isPaymongoConfigured = PAYMONGO_SECRET_KEY !== '' && PAYMONGO_WEBHOOK_SECRET !== '';
unset($_SESSION['super_admin_success'], $_SESSION['super_admin_error'], $_SESSION['tenant_onboarding']);

function getSubscriptionHealthMeta(?string $tenantStatus, ?string $subscriptionStatus, ?string $endDate): array
{
    if ($tenantStatus !== 'active') {
        return [
            'label' => 'Inactive Tenant',
            'class' => 'status-inactive',
            'detail' => 'Tenant access is currently disabled.',
        ];
    }

    if (empty($subscriptionStatus)) {
        return [
            'label' => 'No Subscription',
            'class' => 'status-pending',
            'detail' => 'No subscription record is linked yet.',
        ];
    }

    if ($subscriptionStatus === 'expired') {
        return [
            'label' => 'Expired',
            'class' => 'status-rejected',
            'detail' => 'Latest subscription is marked as expired.',
        ];
    }

    if (!empty($endDate)) {
        $today = new DateTimeImmutable(date('Y-m-d'));
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);

        if ($end instanceof DateTimeImmutable) {
            $daysRemaining = (int) $today->diff($end)->format('%r%a');

            if ($daysRemaining < 0) {
                return [
                    'label' => 'Overdue',
                    'class' => 'status-rejected',
                    'detail' => 'Ended ' . abs($daysRemaining) . ' day' . (abs($daysRemaining) === 1 ? '' : 's') . ' ago.',
                ];
            }

            if ($daysRemaining <= 7) {
                return [
                    'label' => 'Due Soon',
                    'class' => 'status-warning',
                    'detail' => $daysRemaining === 0
                        ? 'Ends today.'
                        : $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's') . ' remaining.',
                ];
            }

            return [
                'label' => 'Healthy',
                'class' => 'status-active',
                'detail' => $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's') . ' remaining.',
            ];
        }
    }

    return [
        'label' => 'Active',
        'class' => 'status-active',
        'detail' => 'Subscription is active.',
    ];
}

$tenants = [];
$selectedTenant = null;
$selectedTenantRegistration = null;
$selectedTenantBillingHistory = [];
$selectedTenantEmailLogs = [];
$features = [];
$featureStates = [];
$selectedTenantEnabledFeatures = [];
$registrations = [];
$selectedRegistration = null;
$selectedRegistrationFeatures = [];
$selectedRegistrationPlanFeatures = [];
$selectedBillingRequest = null;
$selectedRegistrationEmailLogs = [];
$planCatalog = [];
$addonCatalog = [];
$operationalAlerts = [];
$summary = [
    'tenants' => 0,
    'active_tenants' => 0,
    'inactive_tenants' => 0,
    'enabled_feature_links' => 0,
    'subscriptions' => 0,
    'subscription_attention' => 0,
    'subscriptions_due_soon' => 0,
    'tenants_without_subscription' => 0,
    'pending_registrations' => 0,
    'awaiting_billing_registrations' => 0,
    'billing_sent_registrations' => 0,
    'awaiting_conversion_registrations' => 0,
    'active_tenants_without_enabled_features' => 0,
    'registration_billing_mismatches' => 0,
];
$allowedTenantStatuses = ['active', 'inactive'];
$allowedSubscriptionStatuses = ['active', 'expired', 'none'];
$allowedRegistrationStatuses = ['pending', 'approved', 'billing_sent', 'paid', 'converted', 'rejected'];
$allowedRegistrationBillingStatuses = ['draft', 'sent', 'paid', 'cancelled', 'none'];

if (!in_array($tenantStatusFilter, $allowedTenantStatuses, true)) {
    $tenantStatusFilter = '';
}

if (!in_array($subscriptionStatusFilter, $allowedSubscriptionStatuses, true)) {
    $subscriptionStatusFilter = '';
}

if (!in_array($registrationStatusFilter, $allowedRegistrationStatuses, true)) {
    $registrationStatusFilter = '';
}

if (!in_array($registrationBillingStatusFilter, $allowedRegistrationBillingStatuses, true)) {
    $registrationBillingStatusFilter = '';
}

try {
    $pdo = Database::getInstance();

    $summaryQueries = [
        'tenants' => "SELECT COUNT(*) FROM tenants",
        'active_tenants' => "SELECT COUNT(*) FROM tenants WHERE status = 'active'",
        'inactive_tenants' => "SELECT COUNT(*) FROM tenants WHERE status = 'inactive'",
        'enabled_feature_links' => "SELECT COUNT(*) FROM tenant_features WHERE is_enabled = 1",
        'subscriptions' => "SELECT COUNT(*) FROM subscriptions WHERE status = 'active'",
        'subscription_attention' => "
            SELECT COUNT(*)
            FROM tenants t
            LEFT JOIN subscriptions s
                ON s.subscription_id = (
                    SELECT MAX(s2.subscription_id)
                    FROM subscriptions s2
                    WHERE s2.tenant_id = t.tenant_id
                )
            WHERE t.status = 'active'
              AND s.subscription_id IS NOT NULL
              AND (
                    s.status = 'expired'
                    OR (s.end_date IS NOT NULL AND s.end_date < CURDATE())
              )
        ",
        'subscriptions_due_soon' => "
            SELECT COUNT(*)
            FROM tenants t
            LEFT JOIN subscriptions s
                ON s.subscription_id = (
                    SELECT MAX(s2.subscription_id)
                    FROM subscriptions s2
                    WHERE s2.tenant_id = t.tenant_id
                )
            WHERE t.status = 'active'
              AND s.subscription_id IS NOT NULL
              AND s.status = 'active'
              AND s.end_date IS NOT NULL
              AND s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ",
        'tenants_without_subscription' => "
            SELECT COUNT(*)
            FROM tenants t
            WHERE NOT EXISTS (
                SELECT 1
                FROM subscriptions s
                WHERE s.tenant_id = t.tenant_id
            )
        ",
        'pending_registrations' => "SELECT COUNT(*) FROM tenant_registrations WHERE registration_status = 'pending'",
        'awaiting_billing_registrations' => "SELECT COUNT(*) FROM tenant_registrations WHERE registration_status = 'approved'",
        'billing_sent_registrations' => "SELECT COUNT(*) FROM tenant_registrations WHERE registration_status = 'billing_sent'",
        'awaiting_conversion_registrations' => "SELECT COUNT(*) FROM tenant_registrations WHERE registration_status = 'paid'",
        'active_tenants_without_enabled_features' => "
            SELECT COUNT(*)
            FROM tenants t
            WHERE t.status = 'active'
              AND NOT EXISTS (
                    SELECT 1
                    FROM tenant_features tf
                    WHERE tf.tenant_id = t.tenant_id
                      AND tf.is_enabled = 1
              )
        ",
        'registration_billing_mismatches' => "
            SELECT COUNT(*)
            FROM tenant_registrations tr
            LEFT JOIN billing_requests latest_billing
                ON latest_billing.billing_request_id = (
                    SELECT MAX(br.billing_request_id)
                    FROM billing_requests br
                    WHERE br.registration_id = tr.registration_id
                )
            WHERE
                (tr.registration_status = 'billing_sent' AND latest_billing.billing_request_id IS NULL)
                OR (tr.registration_status = 'paid' AND COALESCE(latest_billing.billing_status, '') <> 'paid')
                OR (tr.registration_status = 'converted' AND tr.converted_tenant_id IS NULL)
        ",
    ];

    foreach ($summaryQueries as $key => $sql) {
        $summary[$key] = (int) $pdo->query($sql)->fetchColumn();
    }

    $planCatalog = $pdo->query("
        SELECT
            plan_id,
            plan_name,
            monthly_price,
            yearly_price,
            description,
            is_active
        FROM subscription_plans
        ORDER BY monthly_price ASC, plan_name ASC
    ")->fetchAll();

    $addonCatalog = $pdo->query("
        SELECT
            f.feature_name,
            f.description,
            fp.monthly_addon_price,
            fp.yearly_addon_price,
            fp.is_active
        FROM feature_pricing fp
        INNER JOIN features f ON f.feature_id = fp.feature_id
        ORDER BY fp.monthly_addon_price ASC, f.feature_name ASC
    ")->fetchAll();

    $tenantSql = "
        SELECT
            t.tenant_id,
            t.business_name,
            t.status,
            (
                SELECT s.plan
                FROM subscriptions s
                WHERE s.tenant_id = t.tenant_id
                ORDER BY s.subscription_id DESC
                LIMIT 1
            ) AS current_plan,
            (
                SELECT s.status
                FROM subscriptions s
                WHERE s.tenant_id = t.tenant_id
                ORDER BY s.subscription_id DESC
                LIMIT 1
            ) AS current_subscription_status,
            (
                SELECT s.start_date
                FROM subscriptions s
                WHERE s.tenant_id = t.tenant_id
                ORDER BY s.subscription_id DESC
                LIMIT 1
            ) AS current_subscription_start_date,
            (
                SELECT s.end_date
                FROM subscriptions s
                WHERE s.tenant_id = t.tenant_id
                ORDER BY s.subscription_id DESC
                LIMIT 1
            ) AS current_subscription_end_date,
            (
                SELECT COUNT(*)
                FROM users u
                WHERE u.tenant_id = t.tenant_id
                  AND u.status = 'active'
            ) AS active_users
        FROM tenants t
        WHERE 1 = 1
    ";
    $tenantParams = [];

    if ($tenantSearch !== '') {
        $tenantSql .= " AND t.business_name LIKE :tenant_search ";
        $tenantParams['tenant_search'] = '%' . $tenantSearch . '%';
    }

    if ($tenantStatusFilter !== '') {
        $tenantSql .= " AND t.status = :tenant_status ";
        $tenantParams['tenant_status'] = $tenantStatusFilter;
    }

    if ($subscriptionStatusFilter === 'none') {
        $tenantSql .= "
            AND (
                SELECT s.status
                FROM subscriptions s
                WHERE s.tenant_id = t.tenant_id
                ORDER BY s.subscription_id DESC
                LIMIT 1
            ) IS NULL
        ";
    } elseif ($subscriptionStatusFilter !== '') {
        $tenantSql .= "
            AND (
                SELECT s.status
                FROM subscriptions s
                WHERE s.tenant_id = t.tenant_id
                ORDER BY s.subscription_id DESC
                LIMIT 1
            ) = :subscription_status
        ";
        $tenantParams['subscription_status'] = $subscriptionStatusFilter;
    }

    $tenantSql .= "
        ORDER BY t.business_name ASC
    ";
    $tenantStmt = $pdo->prepare($tenantSql);
    $tenantStmt->execute($tenantParams);
    $tenants = $tenantStmt->fetchAll();

    foreach ($tenants as &$tenant) {
        $health = getSubscriptionHealthMeta(
            $tenant['status'] ?? null,
            $tenant['current_subscription_status'] ?? null,
            $tenant['current_subscription_end_date'] ?? null
        );
        $tenant['health_label'] = $health['label'];
        $tenant['health_class'] = $health['class'];
        $tenant['health_detail'] = $health['detail'];
    }
    unset($tenant);

    $registrationSql = "
        SELECT
            tr.registration_id,
            tr.business_name,
            tr.owner_full_name,
            tr.email,
            tr.billing_cycle,
            tr.registration_status,
            tr.created_at,
            sp.plan_name,
            latest_billing.billing_status AS latest_billing_status
        FROM tenant_registrations tr
        INNER JOIN subscription_plans sp ON sp.plan_id = tr.selected_plan_id
        LEFT JOIN billing_requests latest_billing
            ON latest_billing.billing_request_id = (
                SELECT MAX(br.billing_request_id)
                FROM billing_requests br
                WHERE br.registration_id = tr.registration_id
            )
        WHERE 1 = 1
    ";
    $registrationParams = [];

    if ($registrationSearch !== '') {
        $registrationSql .= "
            AND (
                tr.business_name LIKE :registration_search
                OR tr.owner_full_name LIKE :registration_search
                OR tr.email LIKE :registration_search
            )
        ";
        $registrationParams['registration_search'] = '%' . $registrationSearch . '%';
    }

    if ($registrationStatusFilter !== '') {
        $registrationSql .= " AND tr.registration_status = :registration_status ";
        $registrationParams['registration_status'] = $registrationStatusFilter;
    }

    if ($registrationBillingStatusFilter === 'none') {
        $registrationSql .= " AND latest_billing.billing_request_id IS NULL ";
    } elseif ($registrationBillingStatusFilter !== '') {
        $registrationSql .= " AND latest_billing.billing_status = :registration_billing_status ";
        $registrationParams['registration_billing_status'] = $registrationBillingStatusFilter;
    }

    $registrationSql .= "
        ORDER BY
            FIELD(tr.registration_status, 'pending', 'approved', 'billing_sent', 'paid', 'converted', 'rejected'),
            tr.created_at DESC,
            tr.registration_id DESC
    ";
    $registrationStmt = $pdo->prepare($registrationSql);
    $registrationStmt->execute($registrationParams);
    $registrations = $registrationStmt->fetchAll();

    if ($selectedRegistrationId === 0 && !empty($registrations)) {
        $selectedRegistrationId = (int) $registrations[0]['registration_id'];
    }

    if ($selectedRegistrationId > 0) {
        $selectedRegistrationStmt = $pdo->prepare("
            SELECT
                tr.registration_id,
                tr.business_name,
                tr.owner_full_name,
                tr.email,
                tr.phone,
                tr.address,
                tr.preferred_username,
                tr.billing_cycle,
                tr.registration_status,
                tr.notes,
                tr.created_at,
                tr.reviewed_at,
                sp.plan_name,
                sp.monthly_price,
                sp.yearly_price,
                (
                    SELECT COALESCE(SUM(
                        CASE
                            WHEN tr.billing_cycle = 'yearly' THEN COALESCE(fp.yearly_addon_price, 0)
                            ELSE COALESCE(fp.monthly_addon_price, 0)
                        END
                    ), 0)
                    FROM registration_requested_features rrf
                    LEFT JOIN feature_pricing fp
                        ON fp.feature_id = rrf.feature_id
                       AND fp.is_active = 1
                    WHERE rrf.registration_id = tr.registration_id
                      AND rrf.is_requested = 1
                ) AS estimated_addon_amount
            FROM tenant_registrations tr
            INNER JOIN subscription_plans sp ON sp.plan_id = tr.selected_plan_id
            WHERE tr.registration_id = :registration_id
            LIMIT 1
        ");
        $selectedRegistrationStmt->execute(['registration_id' => $selectedRegistrationId]);
        $selectedRegistration = $selectedRegistrationStmt->fetch();

        if ($selectedRegistration) {
            $selectedRegistrationPlanFeaturesStmt = $pdo->prepare("
                SELECT
                    f.feature_name,
                    f.description
                FROM plan_features pf
                INNER JOIN features f ON f.feature_id = pf.feature_id
                WHERE pf.plan_id = (
                    SELECT selected_plan_id
                    FROM tenant_registrations
                    WHERE registration_id = :registration_id
                    LIMIT 1
                )
                  AND pf.is_included = 1
                ORDER BY f.feature_name ASC
            ");
            $selectedRegistrationPlanFeaturesStmt->execute(['registration_id' => $selectedRegistrationId]);
            $selectedRegistrationPlanFeatures = $selectedRegistrationPlanFeaturesStmt->fetchAll();

            $selectedRegistrationFeaturesStmt = $pdo->prepare("
                SELECT
                    f.feature_name,
                    f.description,
                    fp.monthly_addon_price,
                    fp.yearly_addon_price
                FROM registration_requested_features rrf
                INNER JOIN features f ON f.feature_id = rrf.feature_id
                LEFT JOIN feature_pricing fp ON fp.feature_id = f.feature_id
                WHERE rrf.registration_id = :registration_id
                  AND rrf.is_requested = 1
                ORDER BY f.feature_name ASC
            ");
            $selectedRegistrationFeaturesStmt->execute(['registration_id' => $selectedRegistrationId]);
            $selectedRegistrationFeatures = $selectedRegistrationFeaturesStmt->fetchAll();

            $selectedBillingRequestStmt = $pdo->prepare("
                SELECT
                    billing_request_id,
                    plan_amount,
                    addon_amount,
                    total_amount,
                    currency,
                    billing_status,
                    payment_reference,
                    paymongo_checkout_session_id,
                    paymongo_checkout_url,
                    paymongo_status,
                    paid_at,
                    due_date,
                    created_at,
                    updated_at
                FROM billing_requests
                WHERE registration_id = :registration_id
                ORDER BY billing_request_id DESC
                LIMIT 1
            ");
            $selectedBillingRequestStmt->execute(['registration_id' => $selectedRegistrationId]);
            $selectedBillingRequest = $selectedBillingRequestStmt->fetch();

            $selectedRegistrationEmailLogsStmt = $pdo->prepare("
                SELECT
                    email_log_id,
                    recipient_email,
                    subject,
                    email_type,
                    send_status,
                    sent_at,
                    created_at
                FROM email_logs
                WHERE registration_id = :registration_id
                ORDER BY email_log_id DESC
                LIMIT 8
            ");
            $selectedRegistrationEmailLogsStmt->execute(['registration_id' => $selectedRegistrationId]);
            $selectedRegistrationEmailLogs = $selectedRegistrationEmailLogsStmt->fetchAll();
        }
    }

    if ($selectedTenantId === 0 && !empty($tenants)) {
        $selectedTenantId = (int) $tenants[0]['tenant_id'];
    }

    if ($selectedTenantId > 0) {
        $selectedTenantStmt = $pdo->prepare("
            SELECT
                t.tenant_id,
                t.business_name,
                t.status,
                t.created_at,
                s.plan,
                s.start_date,
                s.end_date,
                s.status AS subscription_status,
                (
                    SELECT COUNT(*)
                    FROM users u
                    WHERE u.tenant_id = t.tenant_id
                      AND u.status = 'active'
                ) AS active_users
            FROM tenants t
            LEFT JOIN subscriptions s
                ON s.tenant_id = t.tenant_id
               AND s.subscription_id = (
                    SELECT MAX(subscription_id)
                    FROM subscriptions
                    WHERE tenant_id = t.tenant_id
                )
            WHERE t.tenant_id = :tenant_id
            LIMIT 1
        ");
        $selectedTenantStmt->execute(['tenant_id' => $selectedTenantId]);
        $selectedTenant = $selectedTenantStmt->fetch();

        if ($selectedTenant) {
            $selectedTenantHealth = getSubscriptionHealthMeta(
                $selectedTenant['status'] ?? null,
                $selectedTenant['subscription_status'] ?? null,
                $selectedTenant['end_date'] ?? null
            );
            $selectedTenant['health_label'] = $selectedTenantHealth['label'];
            $selectedTenant['health_class'] = $selectedTenantHealth['class'];
            $selectedTenant['health_detail'] = $selectedTenantHealth['detail'];

            $selectedTenantRegistrationStmt = $pdo->prepare("
                SELECT
                    tr.registration_id,
                    tr.business_name,
                    tr.owner_full_name,
                    tr.email,
                    tr.billing_cycle,
                    tr.registration_status,
                    tr.created_at,
                    tr.reviewed_at,
                    tr.notes,
                    sp.plan_name
                FROM tenant_registrations tr
                INNER JOIN subscription_plans sp
                    ON sp.plan_id = tr.selected_plan_id
                WHERE tr.converted_tenant_id = :tenant_id
                   OR (
                        tr.business_name = :business_name
                        AND tr.registration_status = 'converted'
                   )
                ORDER BY
                    CASE
                        WHEN tr.converted_tenant_id = :tenant_id_exact THEN 0
                        ELSE 1
                    END,
                    tr.reviewed_at DESC,
                    tr.registration_id DESC
                LIMIT 1
            ");
            $selectedTenantRegistrationStmt->execute([
                'tenant_id' => $selectedTenantId,
                'tenant_id_exact' => $selectedTenantId,
                'business_name' => $selectedTenant['business_name'],
            ]);
            $selectedTenantRegistration = $selectedTenantRegistrationStmt->fetch();

            if ($selectedTenantRegistration) {
                $selectedTenantBillingHistoryStmt = $pdo->prepare("
                    SELECT
                        billing_request_id,
                        total_amount,
                        currency,
                        billing_status,
                        payment_reference,
                        paymongo_status,
                        due_date,
                        paid_at,
                        created_at,
                        updated_at
                    FROM billing_requests
                    WHERE registration_id = :registration_id
                    ORDER BY billing_request_id DESC
                ");
                $selectedTenantBillingHistoryStmt->execute([
                    'registration_id' => (int) $selectedTenantRegistration['registration_id'],
                ]);
                $selectedTenantBillingHistory = $selectedTenantBillingHistoryStmt->fetchAll();

                $selectedTenantEmailLogsStmt = $pdo->prepare("
                    SELECT
                        email_log_id,
                        recipient_email,
                        subject,
                        email_type,
                        send_status,
                        sent_at,
                        created_at
                    FROM email_logs
                    WHERE registration_id = :registration_id
                       OR tenant_id = :tenant_id
                    ORDER BY email_log_id DESC
                    LIMIT 10
                ");
                $selectedTenantEmailLogsStmt->execute([
                    'registration_id' => (int) $selectedTenantRegistration['registration_id'],
                    'tenant_id' => (int) $selectedTenant['tenant_id'],
                ]);
                $selectedTenantEmailLogs = $selectedTenantEmailLogsStmt->fetchAll();
            }
        }

        $featureStmt = $pdo->prepare("
            SELECT
                f.feature_id,
                f.feature_name,
                f.description,
                COALESCE(tf.is_enabled, 0) AS is_enabled
            FROM features f
            LEFT JOIN tenant_features tf
                ON tf.feature_id = f.feature_id
               AND tf.tenant_id = :tenant_id
            ORDER BY f.feature_name ASC
        ");
        $featureStmt->execute(['tenant_id' => $selectedTenantId]);
        $features = $featureStmt->fetchAll();

        foreach ($features as $feature) {
            $featureStates[(int) $feature['feature_id']] = (int) $feature['is_enabled'] === 1;
        }

        $selectedTenantEnabledFeatures = array_values(array_filter(
            $features,
            static fn (array $feature): bool => (int) $feature['is_enabled'] === 1
        ));
    }

    if ($summary['active_tenants_without_enabled_features'] > 0) {
        $operationalAlerts[] = [
            'class' => 'alert-error',
            'title' => 'Active tenants with no enabled features',
            'detail' => number_format($summary['active_tenants_without_enabled_features']) . ' active tenant(s) currently have no enabled feature records. Review feature toggles before tenant login testing.',
        ];
    }

    if ($summary['subscription_attention'] > 0) {
        $operationalAlerts[] = [
            'class' => 'alert-error',
            'title' => 'Expired or overdue live subscriptions',
            'detail' => number_format($summary['subscription_attention']) . ' active tenant(s) have expired or overdue subscription windows and should be reviewed.',
        ];
    }

    if ($summary['tenants_without_subscription'] > 0) {
        $operationalAlerts[] = [
            'class' => 'alert-error',
            'title' => 'Tenants without subscriptions',
            'detail' => number_format($summary['tenants_without_subscription']) . ' tenant(s) do not have any subscription record linked yet.',
        ];
    }

    if ($summary['registration_billing_mismatches'] > 0) {
        $operationalAlerts[] = [
            'class' => 'alert-error',
            'title' => 'Registration and billing mismatches detected',
            'detail' => number_format($summary['registration_billing_mismatches']) . ' registration record(s) have inconsistent registration, billing, or conversion linkage states.',
        ];
    }

    if ($summary['subscriptions_due_soon'] > 0) {
        $operationalAlerts[] = [
            'class' => 'alert-success',
            'title' => 'Subscriptions due soon',
            'detail' => number_format($summary['subscriptions_due_soon']) . ' active subscription(s) end within 7 days. This is a good time to renew them before they lapse.',
        ];
    }

    if ($selectedTenant) {
        if (($selectedTenant['status'] ?? '') === 'active' && empty($selectedTenantEnabledFeatures)) {
            $operationalAlerts[] = [
                'class' => 'alert-error',
                'title' => 'Selected tenant has no enabled features',
                'detail' => $selectedTenant['business_name'] . ' is active, but no enabled modules are currently recorded for it.',
            ];
        }

        if (($selectedTenant['status'] ?? '') === 'active' && ($selectedTenant['subscription_status'] ?? '') === 'expired') {
            $operationalAlerts[] = [
                'class' => 'alert-error',
                'title' => 'Selected tenant is active with an expired subscription',
                'detail' => $selectedTenant['business_name'] . ' is still active even though the latest subscription is marked expired.',
            ];
        }

        if (($selectedTenant['status'] ?? '') === 'active' && empty($selectedTenant['subscription_status'])) {
            $operationalAlerts[] = [
                'class' => 'alert-error',
                'title' => 'Selected tenant has no subscription record',
                'detail' => $selectedTenant['business_name'] . ' is active, but no subscription record is linked to it yet.',
            ];
        }
    }

    if ($selectedRegistration) {
        $registrationStatus = $selectedRegistration['registration_status'] ?? '';
        $billingStatus = $selectedBillingRequest['billing_status'] ?? '';

        if ($registrationStatus === 'billing_sent' && !$selectedBillingRequest) {
            $operationalAlerts[] = [
                'class' => 'alert-error',
                'title' => 'Selected registration is marked billing sent without a billing record',
                'detail' => $selectedRegistration['business_name'] . ' is in billing_sent state, but no billing request could be found.',
            ];
        }

        if ($registrationStatus === 'paid' && $billingStatus !== 'paid') {
            $operationalAlerts[] = [
                'class' => 'alert-error',
                'title' => 'Selected paid registration is not backed by a paid billing record',
                'detail' => $selectedRegistration['business_name'] . ' is marked paid, but the latest billing request is not paid yet.',
            ];
        }

        if ($registrationStatus === 'converted' && $selectedBillingRequest && $billingStatus !== 'paid') {
            $operationalAlerts[] = [
                'class' => 'alert-error',
                'title' => 'Selected converted registration was not finalized against a paid billing record',
                'detail' => $selectedRegistration['business_name'] . ' is converted, but the latest billing request does not show a paid state.',
            ];
        }
    }
} catch (PDOException $e) {
    $errorMessage = 'Unable to load super admin dashboard: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - MECHANIX</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body class="page-shell">
    <div class="dashboard">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="brand">
                    <div class="brand-mark">M</div>
                    <div class="brand-text">
                        <h2>MECHANIX</h2>
                        <p>Platform operations</p>
                    </div>
                </div>
                <p class="sidebar-meta">
                    Logged in as <?= htmlspecialchars($_SESSION['super_admin_username'], ENT_QUOTES, 'UTF-8') ?>.
                </p>
            </div>

            <div class="sidebar-section-title">Platform</div>
            <nav class="sidebar-menu">
                <a href="#registrations" class="active"><span>Registrations</span><span class="badge"><?= number_format($summary['pending_registrations']) ?></span></a>
                <a href="#features"><span>Tenant Operations</span><span class="sidebar-hint">Live</span></a>
            </nav>

            <div class="sidebar-section-title">Lifecycle</div>
            <nav class="sidebar-menu">
                <a href="#features"><span>Tenant Status</span><span class="sidebar-hint">Live</span></a>
                <a href="#features"><span>Subscriptions</span><span class="sidebar-hint">Live</span></a>
            </nav>

            <div class="sidebar-footer">
                <a href="../logout.php" class="btn btn-secondary btn-full">Log Out</a>
            </div>
        </aside>

        <main class="dashboard-main">
            <div class="dashboard-topbar">
                <div class="dashboard-title">
                    <h2>Super Admin Dashboard</h2>
                    <p>Manage registrations, billing, tenant activation, subscriptions, and tenant access from one operational workspace.</p>
                </div>

                <div class="nav-actions">
                    <button type="button" class="theme-toggle" data-theme-toggle>Dark Mode</button>
                </div>
            </div>

            <?php if ($flashMessage !== null): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== null): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <div class="alert <?= $isPaymongoConfigured ? 'alert-success' : 'alert-error' ?>">
                <?= htmlspecialchars(
                    $isPaymongoConfigured
                        ? 'PayMongo configuration is ready. Checkout creation and webhook verification can be tested from this dashboard.'
                        : 'PayMongo configuration is incomplete. Manual billing still works, but checkout creation and webhook verification are not fully ready yet.',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>

            <?php if (!empty($operationalAlerts)): ?>
                <section class="content-card">
                    <h3>Operational Alerts</h3>
                    <p>These checks surface tenant, subscription, and registration states that are most likely to cause onboarding, billing, or demo issues.</p>

                    <?php foreach ($operationalAlerts as $alert): ?>
                        <div class="alert <?= htmlspecialchars($alert['class'], ENT_QUOTES, 'UTF-8') ?>">
                            <strong><?= htmlspecialchars($alert['title'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                            <?= htmlspecialchars($alert['detail'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if (is_array($tenantOnboarding)): ?>
                <section class="onboarding-panel">
                    <div class="onboarding-panel-head">
                        <div>
                            <h3>Tenant Onboarding Ready</h3>
                            <p>
                                <?= htmlspecialchars($tenantOnboarding['business_name'], ENT_QUOTES, 'UTF-8') ?>
                                is now live and ready for first login testing.
                            </p>
                        </div>
                        <a href="../login.php" class="btn btn-secondary">Open Tenant Login</a>
                    </div>

                    <div class="onboarding-grid">
                        <div class="onboarding-card">
                            <span>Tenant ID</span>
                            <strong><?= number_format((int) $tenantOnboarding['tenant_id']) ?></strong>
                        </div>
                        <div class="onboarding-card">
                            <span>Admin Username</span>
                            <strong><?= htmlspecialchars($tenantOnboarding['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="onboarding-card">
                            <span>Temporary Password</span>
                            <strong><?= htmlspecialchars($tenantOnboarding['temporary_password'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="onboarding-card">
                            <span>Subscription Window</span>
                            <strong>
                                <?= htmlspecialchars($tenantOnboarding['subscription_start'], ENT_QUOTES, 'UTF-8') ?>
                                to
                                <?= htmlspecialchars($tenantOnboarding['subscription_end'], ENT_QUOTES, 'UTF-8') ?>
                            </strong>
                        </div>
                    </div>

                    <div class="dashboard-list compact-list">
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Plan and Billing</strong>
                                <p>
                                    <?= htmlspecialchars($tenantOnboarding['plan_name'], ENT_QUOTES, 'UTF-8') ?>
                                    |
                                    <?= htmlspecialchars(ucfirst($tenantOnboarding['billing_cycle']), ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            </div>
                            <span class="metric-pill">Active</span>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Primary Admin</strong>
                                <p><?= htmlspecialchars($tenantOnboarding['admin_name'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <span class="metric-pill">Ready</span>
                        </div>
                    </div>

                    <h3>Assigned Feature Set</h3>
                    <p>These are the modules applied during conversion from the selected plan and requested add-ons.</p>

                    <div class="addon-list registration-addon-list">
                        <?php if (!empty($tenantOnboarding['assigned_features']) && is_array($tenantOnboarding['assigned_features'])): ?>
                            <?php foreach ($tenantOnboarding['assigned_features'] as $feature): ?>
                                <div class="addon-item">
                                    <div>
                                        <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $feature['feature_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p><?= htmlspecialchars(($feature['description'] ?? '') !== '' ? $feature['description'] : 'Enabled during tenant conversion.', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <span class="addon-price"><?= htmlspecialchars(ucwords((string) ($feature['source'] ?? 'enabled')), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="table-placeholder">No plan-linked or add-on features were applied during this conversion.</div>
                        <?php endif; ?>
                    </div>

                    <div class="table-placeholder">
                        <strong>Quick Validation Flow</strong><br>
                        1. Open the tenant login page.<br>
                        2. Sign in with the admin username and temporary password shown above.<br>
                        3. Confirm the tenant dashboard opens and only shows the enabled features for this new tenant.
                    </div>
                </section>
            <?php endif; ?>

            <section class="dashboard-grid">
                <article class="metric-card">
                    <span>Pending Registrations</span>
                    <h3><?= number_format($summary['pending_registrations']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Total Tenants</span>
                    <h3><?= number_format($summary['tenants']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Active Tenants</span>
                    <h3><?= number_format($summary['active_tenants']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Inactive Tenants</span>
                    <h3><?= number_format($summary['inactive_tenants']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Enabled Feature Links</span>
                    <h3><?= number_format($summary['enabled_feature_links']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Active Subscriptions</span>
                    <h3><?= number_format($summary['subscriptions']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Expired / Overdue</span>
                    <h3><?= number_format($summary['subscription_attention']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Due Within 7 Days</span>
                    <h3><?= number_format($summary['subscriptions_due_soon']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>No Subscription</span>
                    <h3><?= number_format($summary['tenants_without_subscription']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Awaiting Billing</span>
                    <h3><?= number_format($summary['awaiting_billing_registrations']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Billing Sent</span>
                    <h3><?= number_format($summary['billing_sent_registrations']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Awaiting Conversion</span>
                    <h3><?= number_format($summary['awaiting_conversion_registrations']) ?></h3>
                </article>
            </section>

            <section class="content-grid superadmin-grid">
                <article class="content-card">
                    <h3>Plan Catalog</h3>
                    <p>Reference view of the subscription plans currently used for onboarding and billing.</p>

                    <?php if (!empty($planCatalog)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($planCatalog as $plan): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars($plan['plan_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            Monthly: PHP <?= number_format((float) $plan['monthly_price'], 2) ?>
                                            | Yearly: PHP <?= number_format((float) $plan['yearly_price'], 2) ?>
                                        </p>
                                        <p><?= htmlspecialchars($plan['description'] ?: 'No plan description provided.', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <span class="status-chip <?= (int) $plan['is_active'] === 1 ? 'status-active' : 'status-inactive' ?>">
                                        <?= (int) $plan['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">No subscription plans are configured yet.</div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3>Add-On Catalog</h3>
                    <p>Reference view of the optional features currently priced for registrations and billing drafts.</p>

                    <?php if (!empty($addonCatalog)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($addonCatalog as $addon): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $addon['feature_name'])), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            Monthly: PHP <?= number_format((float) $addon['monthly_addon_price'], 2) ?>
                                            | Yearly: PHP <?= number_format((float) $addon['yearly_addon_price'], 2) ?>
                                        </p>
                                        <p><?= htmlspecialchars($addon['description'] ?: 'No add-on description provided.', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <span class="status-chip <?= (int) $addon['is_active'] === 1 ? 'status-active' : 'status-inactive' ?>">
                                        <?= (int) $addon['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">No add-on pricing is configured yet.</div>
                    <?php endif; ?>
                </article>
            </section>

            <section id="registrations" class="content-grid superadmin-grid">
                <article class="content-card">
                    <h3>Registration Queue</h3>
                    <p>Review incoming business applications before billing and tenant activation.</p>

                    <form action="dashboard.php" method="GET" class="feature-toggle-form">
                        <?php if ($selectedTenantId > 0): ?>
                            <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenantId ?>">
                        <?php endif; ?>
                        <?php if ($tenantSearch !== ''): ?>
                            <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                        <?php if ($tenantStatusFilter !== ''): ?>
                            <input type="hidden" name="tenant_status" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                        <?php if ($subscriptionStatusFilter !== ''): ?>
                            <input type="hidden" name="subscription_status" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>

                        <div class="form-grid customer-search-grid">
                            <div class="form-group">
                                <label for="registration_search">Search Registration</label>
                                <input class="form-control" type="text" id="registration_search" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Business, owner, or email">
                            </div>
                            <div class="form-group">
                                <label for="registration_status_filter">Registration Status</label>
                                <select class="form-control" id="registration_status_filter" name="registration_status">
                                    <option value="">All registration statuses</option>
                                    <option value="pending"<?= $registrationStatusFilter === 'pending' ? ' selected' : '' ?>>Pending</option>
                                    <option value="approved"<?= $registrationStatusFilter === 'approved' ? ' selected' : '' ?>>Approved</option>
                                    <option value="billing_sent"<?= $registrationStatusFilter === 'billing_sent' ? ' selected' : '' ?>>Billing Sent</option>
                                    <option value="paid"<?= $registrationStatusFilter === 'paid' ? ' selected' : '' ?>>Paid</option>
                                    <option value="converted"<?= $registrationStatusFilter === 'converted' ? ' selected' : '' ?>>Converted</option>
                                    <option value="rejected"<?= $registrationStatusFilter === 'rejected' ? ' selected' : '' ?>>Rejected</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="registration_billing_status_filter">Billing Status</label>
                                <select class="form-control" id="registration_billing_status_filter" name="registration_billing_status">
                                    <option value="">All billing statuses</option>
                                    <option value="draft"<?= $registrationBillingStatusFilter === 'draft' ? ' selected' : '' ?>>Draft</option>
                                    <option value="sent"<?= $registrationBillingStatusFilter === 'sent' ? ' selected' : '' ?>>Sent</option>
                                    <option value="paid"<?= $registrationBillingStatusFilter === 'paid' ? ' selected' : '' ?>>Paid</option>
                                    <option value="cancelled"<?= $registrationBillingStatusFilter === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
                                    <option value="none"<?= $registrationBillingStatusFilter === 'none' ? ' selected' : '' ?>>No billing request</option>
                                </select>
                            </div>
                            <div class="feature-toggle-actions">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="dashboard.php<?= $selectedTenantId > 0 ? '?tenant_id=' . (int) $selectedTenantId . '#registrations' : '#registrations' ?>" class="btn btn-secondary">Reset</a>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($registrations)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($registrations as $registration): ?>
                                <?php $isSelectedRegistration = (int) $registration['registration_id'] === $selectedRegistrationId; ?>
                                <a class="dashboard-list-item dashboard-link-card<?= $isSelectedRegistration ? ' is-selected' : '' ?>" href="dashboard.php?registration_id=<?= (int) $registration['registration_id'] ?><?= $registrationSearch !== '' ? '&registration_search=' . urlencode($registrationSearch) : '' ?><?= $registrationStatusFilter !== '' ? '&registration_status=' . urlencode($registrationStatusFilter) : '' ?><?= $registrationBillingStatusFilter !== '' ? '&registration_billing_status=' . urlencode($registrationBillingStatusFilter) : '' ?><?= $selectedTenantId > 0 ? '&tenant_id=' . (int) $selectedTenantId : '' ?><?= $tenantSearch !== '' ? '&tenant_search=' . urlencode($tenantSearch) : '' ?><?= $tenantStatusFilter !== '' ? '&tenant_status=' . urlencode($tenantStatusFilter) : '' ?><?= $subscriptionStatusFilter !== '' ? '&subscription_status=' . urlencode($subscriptionStatusFilter) : '' ?>#registrations">
                                    <div>
                                        <strong><?= htmlspecialchars($registration['business_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            <?= htmlspecialchars($registration['plan_name'], ENT_QUOTES, 'UTF-8') ?> |
                                            <?= htmlspecialchars($registration['owner_full_name'], ENT_QUOTES, 'UTF-8') ?> |
                                            Billing: <?= htmlspecialchars($registration['latest_billing_status'] ?: 'none', ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                    </div>
                                    <span class="status-chip status-<?= htmlspecialchars($registration['registration_status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(ucfirst($registration['registration_status']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">No registrations matched the current filters.</div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3>Registration Details</h3>
                    <?php if ($selectedRegistration): ?>
                        <?php
                        $registrationStatus = (string) ($selectedRegistration['registration_status'] ?? '');
                        $billingStatus = (string) ($selectedBillingRequest['billing_status'] ?? '');
                        $canGenerateBillingDraft = in_array($registrationStatus, ['approved', 'billing_sent', 'paid'], true) && $registrationStatus !== 'converted';
                        $canCreateCheckout = $selectedBillingRequest && in_array($registrationStatus, ['approved', 'billing_sent'], true) && $billingStatus !== 'paid';
                        $canConvertTenant = $selectedBillingRequest && $registrationStatus === 'paid';
                        $canUpdateBillingStatus = $selectedBillingRequest && $registrationStatus !== 'converted';
                        $canApproveRegistration = in_array($registrationStatus, ['pending', 'rejected'], true);
                        $canMarkBillingSent = $selectedBillingRequest && in_array($registrationStatus, ['approved', 'billing_sent'], true);
                        $canRejectRegistration = in_array($registrationStatus, ['pending', 'approved', 'billing_sent'], true);
                        ?>
                        <p>
                            Reviewing <?= htmlspecialchars($selectedRegistration['business_name'], ENT_QUOTES, 'UTF-8') ?> for the
                            <?= htmlspecialchars($selectedRegistration['plan_name'], ENT_QUOTES, 'UTF-8') ?> plan.
                        </p>

                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Primary Contact</strong>
                                    <p><?= htmlspecialchars($selectedRegistration['owner_full_name'], ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($selectedRegistration['email'], ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="status-chip status-<?= htmlspecialchars($selectedRegistration['registration_status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucfirst($selectedRegistration['registration_status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Billing Cycle</strong>
                                    <p><?= htmlspecialchars(ucfirst($selectedRegistration['billing_cycle']), ENT_QUOTES, 'UTF-8') ?> | Preferred username: <?= htmlspecialchars($selectedRegistration['preferred_username'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="metric-pill">
                                    <?= htmlspecialchars($selectedRegistration['billing_cycle'] === 'yearly' ? 'Yearly' : 'Monthly', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Plan Price</strong>
                                    <p>
                                        Monthly: PHP <?= number_format((float) $selectedRegistration['monthly_price'], 2) ?> |
                                        Yearly: PHP <?= number_format((float) $selectedRegistration['yearly_price'], 2) ?>
                                    </p>
                                </div>
                                <span class="metric-pill"><?= htmlspecialchars($selectedRegistration['plan_name'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Estimated Add-On Total</strong>
                                    <p>Calculated from requested add-ons for the selected billing cycle.</p>
                                </div>
                                <span class="metric-pill">PHP <?= number_format((float) $selectedRegistration['estimated_addon_amount'], 2) ?></span>
                            </div>
                        </div>

                        <div class="table-placeholder">
                            <strong>Address</strong><br>
                            <?= nl2br(htmlspecialchars($selectedRegistration['address'] ?: 'No address provided.', ENT_QUOTES, 'UTF-8')) ?>
                        </div>

                        <h3>Included Plan Features</h3>
                        <p>These features will be auto-enabled for the tenant when the paid registration is converted into a live tenant.</p>

                        <div class="addon-list registration-addon-list">
                            <?php if (!empty($selectedRegistrationPlanFeatures)): ?>
                                <?php foreach ($selectedRegistrationPlanFeatures as $planFeature): ?>
                                    <div class="addon-item">
                                        <div>
                                            <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $planFeature['feature_name'])), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p><?= htmlspecialchars($planFeature['description'] ?: 'Included in the selected subscription plan.', ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                        <span class="addon-price">Included</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="table-placeholder">No plan-linked features are configured for this subscription plan yet.</div>
                            <?php endif; ?>
                        </div>

                        <div class="addon-list registration-addon-list">
                            <?php if (!empty($selectedRegistrationFeatures)): ?>
                                <?php foreach ($selectedRegistrationFeatures as $addon): ?>
                                    <div class="addon-item">
                                        <div>
                                            <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $addon['feature_name'])), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p><?= htmlspecialchars($addon['description'] ?: 'Requested add-on feature.', ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                        <span class="addon-price">PHP <?= number_format((float) $addon['monthly_addon_price'], 2) ?>/mo</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="table-placeholder">No add-on features requested for this registration.</div>
                            <?php endif; ?>
                        </div>

                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Billing Draft</strong>
                                    <p>
                                        <?= $selectedBillingRequest ? 'Latest billing request is ready for review.' : 'No billing request generated yet.' ?>
                                    </p>
                                </div>
                                <span class="status-chip<?= $selectedBillingRequest ? '' : ' status-pending' ?>">
                                    <?= htmlspecialchars(ucfirst($selectedBillingRequest['billing_status'] ?? 'none'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <?php if ($selectedBillingRequest): ?>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong>Amounts</strong>
                                        <p>
                                            Plan: <?= htmlspecialchars($selectedBillingRequest['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $selectedBillingRequest['plan_amount'], 2) ?> |
                                            Add-ons: <?= htmlspecialchars($selectedBillingRequest['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $selectedBillingRequest['addon_amount'], 2) ?>
                                        </p>
                                    </div>
                                    <span class="metric-pill"><?= htmlspecialchars($selectedBillingRequest['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $selectedBillingRequest['total_amount'], 2) ?></span>
                                </div>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong>Due Date</strong>
                                        <p><?= htmlspecialchars($selectedBillingRequest['due_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <span class="metric-pill"><?= htmlspecialchars(ucfirst($selectedBillingRequest['billing_status']), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong>Payment Reference</strong>
                                        <p><?= htmlspecialchars($selectedBillingRequest['payment_reference'] ?: 'Not set yet', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <span class="metric-pill">Ref</span>
                                </div>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong>Paid At</strong>
                                        <p><?= htmlspecialchars($selectedBillingRequest['paid_at'] ?: 'Not paid yet', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <span class="metric-pill">Payment</span>
                                </div>
                                <div class="dashboard-list-item">
                                    <div>
                                        <strong>PayMongo Checkout</strong>
                                        <p><?= htmlspecialchars($selectedBillingRequest['paymongo_checkout_session_id'] ?? 'Not created yet', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <span class="metric-pill"><?= htmlspecialchars(ucfirst($selectedBillingRequest['paymongo_status'] ?? 'none'), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($canGenerateBillingDraft): ?>
                            <form action="actions/generate_billing_request.php" method="POST" class="feature-toggle-form">
                                <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">

                                <div class="form-group">
                                    <label for="due_date">Billing Due Date</label>
                                    <input
                                        class="form-control"
                                        type="date"
                                        id="due_date"
                                        name="due_date"
                                        value="<?= htmlspecialchars($selectedBillingRequest['due_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                </div>

                                <div class="feature-toggle-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <?= $selectedBillingRequest ? 'Update Billing Draft' : 'Generate Billing Draft' ?>
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="table-placeholder">
                                <strong>Billing Draft Locked</strong><br>
                                Approve the registration first before preparing billing, and stop billing changes once conversion is complete.
                            </div>
                        <?php endif; ?>

                        <?php if ($selectedBillingRequest): ?>
                            <?php if ($canCreateCheckout): ?>
                                <form action="actions/create_paymongo_checkout.php" method="POST" class="feature-toggle-form">
                                    <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                    <input type="hidden" name="billing_request_id" value="<?= (int) $selectedBillingRequest['billing_request_id'] ?>">
                                    <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">

                                    <div class="approval-actions">
                                        <button type="submit" class="btn btn-primary">Create PayMongo Checkout</button>
                                        <?php if (!empty($selectedBillingRequest['paymongo_checkout_url'])): ?>
                                            <a href="<?= htmlspecialchars($selectedBillingRequest['paymongo_checkout_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">Open Checkout</a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            <?php elseif (!empty($selectedBillingRequest['paymongo_checkout_url'])): ?>
                                <div class="approval-actions">
                                    <a href="<?= htmlspecialchars($selectedBillingRequest['paymongo_checkout_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">Open Checkout</a>
                                </div>
                            <?php endif; ?>

                            <?php if ($canConvertTenant): ?>
                                <form action="actions/convert_registration_to_tenant.php" method="POST" class="feature-toggle-form">
                                    <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                    <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">

                                    <div class="table-placeholder">
                                        <strong>Tenant Conversion</strong><br>
                                        This creates the live tenant record, the active subscription, the enabled tenant features, and the initial tenant admin account.
                                    </div>

                                    <div class="approval-actions">
                                        <button type="submit" class="btn btn-primary">Convert Paid Registration to Tenant</button>
                                    </div>
                                </form>
                            <?php elseif ($registrationStatus === 'converted'): ?>
                                <div class="table-placeholder">
                                    <strong>Tenant Conversion Complete</strong><br>
                                    This registration has already been converted into a live tenant account.
                                </div>
                            <?php endif; ?>

                            <?php if ($canUpdateBillingStatus): ?>
                                <form action="actions/update_billing_status.php" method="POST" class="feature-toggle-form">
                                    <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                    <input type="hidden" name="billing_request_id" value="<?= (int) $selectedBillingRequest['billing_request_id'] ?>">
                                    <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">

                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label for="billing_status">Billing Status</label>
                                            <select class="form-control" id="billing_status" name="billing_status" required>
                                                <option value="draft"<?= ($selectedBillingRequest['billing_status'] === 'draft') ? ' selected' : '' ?>>Draft</option>
                                                <option value="sent"<?= ($selectedBillingRequest['billing_status'] === 'sent') ? ' selected' : '' ?>>Sent</option>
                                                <option value="paid"<?= ($selectedBillingRequest['billing_status'] === 'paid') ? ' selected' : '' ?>>Paid</option>
                                                <option value="cancelled"<?= ($selectedBillingRequest['billing_status'] === 'cancelled') ? ' selected' : '' ?>>Cancelled</option>
                                            </select>
                                        </div>

                                        <div class="form-group">
                                            <label for="payment_reference">Payment / External Reference</label>
                                            <input
                                                class="form-control"
                                                type="text"
                                                id="payment_reference"
                                                name="payment_reference"
                                                value="<?= htmlspecialchars($selectedBillingRequest['payment_reference'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                        </div>
                                    </div>

                                    <div class="approval-actions">
                                        <button type="submit" class="btn btn-primary">Update Billing Status</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>

                        <form action="actions/review_registration.php" method="POST" class="feature-toggle-form">
                            <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                            <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">

                            <div class="form-group">
                                <label for="notes">Review Notes</label>
                                <textarea class="form-control form-textarea" id="notes" name="notes"><?= htmlspecialchars($selectedRegistration['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>

                            <div class="approval-actions">
                                <?php if ($canApproveRegistration): ?>
                                    <button type="submit" class="btn btn-primary" name="decision" value="approve">Approve Registration</button>
                                <?php endif; ?>
                                <?php if ($canMarkBillingSent): ?>
                                    <button type="submit" class="btn btn-secondary" name="decision" value="billing_sent">Mark Billing Sent</button>
                                <?php endif; ?>
                                <?php if ($canRejectRegistration): ?>
                                    <button type="submit" class="btn btn-secondary" name="decision" value="reject">Reject Registration</button>
                                <?php endif; ?>
                            </div>
                        </form>

                        <h3>Communication Activity</h3>
                        <p>Current system communication is tracked through `email_logs`, even when live email delivery is not configured yet.</p>

                        <?php if (!empty($selectedRegistrationEmailLogs)): ?>
                            <div class="dashboard-list compact-list">
                                <?php foreach ($selectedRegistrationEmailLogs as $emailLog): ?>
                                    <div class="dashboard-list-item">
                                        <div>
                                            <strong><?= htmlspecialchars($emailLog['subject'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p>
                                                <?= htmlspecialchars($emailLog['recipient_email'], ENT_QUOTES, 'UTF-8') ?>
                                                | Type: <?= htmlspecialchars($emailLog['email_type'], ENT_QUOTES, 'UTF-8') ?>
                                            </p>
                                            <p>
                                                Created: <?= htmlspecialchars($emailLog['created_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>
                                                | Sent: <?= htmlspecialchars($emailLog['sent_at'] ?: 'Pending', ENT_QUOTES, 'UTF-8') ?>
                                            </p>
                                        </div>
                                        <span class="status-chip status-<?= htmlspecialchars($emailLog['send_status'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(ucfirst($emailLog['send_status']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-placeholder">No email log activity has been recorded for this registration yet.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p>Select a registration from the queue to review plan and add-on choices.</p>
                    <?php endif; ?>
                </article>
            </section>

            <section id="features" class="content-grid superadmin-grid">
                <article class="content-card">
                    <h3>Tenants</h3>
                    <p>Filter the tenant list by status or subscription state, then open a tenant to manage controls and feature access.</p>

                    <form action="dashboard.php" method="GET" class="feature-toggle-form">
                        <div class="form-grid customer-search-grid">
                            <div class="form-group">
                                <label for="tenant_search">Search Tenant</label>
                                <input class="form-control" type="text" id="tenant_search" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Business name">
                            </div>
                            <div class="form-group">
                                <label for="tenant_status">Tenant Status</label>
                                <select class="form-control" id="tenant_status" name="tenant_status">
                                    <option value="">All tenant statuses</option>
                                    <option value="active"<?= $tenantStatusFilter === 'active' ? ' selected' : '' ?>>Active</option>
                                    <option value="inactive"<?= $tenantStatusFilter === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="subscription_status">Subscription Status</label>
                                <select class="form-control" id="subscription_status" name="subscription_status">
                                    <option value="">All subscription statuses</option>
                                    <option value="active"<?= $subscriptionStatusFilter === 'active' ? ' selected' : '' ?>>Active</option>
                                    <option value="expired"<?= $subscriptionStatusFilter === 'expired' ? ' selected' : '' ?>>Expired</option>
                                    <option value="none"<?= $subscriptionStatusFilter === 'none' ? ' selected' : '' ?>>No subscription</option>
                                </select>
                            </div>
                            <div class="feature-toggle-actions">
                                <button type="submit" class="btn btn-primary">Filter</button>
                                <a href="dashboard.php#features" class="btn btn-secondary">Reset</a>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($tenants)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($tenants as $tenant): ?>
                                <?php $isSelected = (int) $tenant['tenant_id'] === $selectedTenantId; ?>
                                <a class="dashboard-list-item dashboard-link-card<?= $isSelected ? ' is-selected' : '' ?>" href="dashboard.php?tenant_id=<?= (int) $tenant['tenant_id'] ?><?= $tenantSearch !== '' ? '&tenant_search=' . urlencode($tenantSearch) : '' ?><?= $tenantStatusFilter !== '' ? '&tenant_status=' . urlencode($tenantStatusFilter) : '' ?><?= $subscriptionStatusFilter !== '' ? '&subscription_status=' . urlencode($subscriptionStatusFilter) : '' ?>#features">
                                    <div>
                                        <strong><?= htmlspecialchars($tenant['business_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            Status: <?= htmlspecialchars($tenant['status'], ENT_QUOTES, 'UTF-8') ?> |
                                            Plan: <?= htmlspecialchars($tenant['current_plan'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> |
                                            Subscription: <?= htmlspecialchars($tenant['current_subscription_status'] ?: 'none', ENT_QUOTES, 'UTF-8') ?><br>
                                            <?= htmlspecialchars($tenant['health_detail'], ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                    </div>
                                    <div class="tenant-directory-meta">
                                        <span class="status-chip <?= htmlspecialchars($tenant['health_class'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($tenant['health_label'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <span class="metric-pill"><?= number_format((int) $tenant['active_users']) ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">No tenants matched the current filters.</div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3>Tenant Controls</h3>
                    <?php if ($selectedTenant): ?>
                        <p>
                            Editing <?= htmlspecialchars($selectedTenant['business_name'], ENT_QUOTES, 'UTF-8') ?>.
                            Current plan: <?= htmlspecialchars($selectedTenant['plan'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>.
                        </p>

                        <div class="dashboard-list compact-list">
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Tenant Status</strong>
                                    <p><?= htmlspecialchars(ucfirst($selectedTenant['status']), ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="status-chip status-<?= htmlspecialchars($selectedTenant['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(ucfirst($selectedTenant['status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Active Tenant Users</strong>
                                    <p>Accounts currently allowed to sign in under this tenant.</p>
                                </div>
                                <span class="metric-pill"><?= number_format((int) ($selectedTenant['active_users'] ?? 0)) ?></span>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Subscription Window</strong>
                                    <p>
                                        <?= htmlspecialchars($selectedTenant['start_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>
                                        to
                                        <?= htmlspecialchars($selectedTenant['end_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <span class="status-chip">
                                    <?= htmlspecialchars(ucfirst($selectedTenant['subscription_status'] ?: 'none'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Attention State</strong>
                                    <p><?= htmlspecialchars($selectedTenant['health_detail'] ?? 'No subscription health details available.', ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="status-chip <?= htmlspecialchars($selectedTenant['health_class'] ?? 'status-pending', ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($selectedTenant['health_label'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <div class="dashboard-list-item">
                                <div>
                                    <strong>Tenant Created</strong>
                                    <p><?= htmlspecialchars($selectedTenant['created_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="metric-pill">Live</span>
                            </div>
                        </div>

                        <div class="content-grid superadmin-grid">
                            <article class="content-card">
                                <h3>Tenant Lifecycle</h3>
                                <p>View how this live tenant moved from registration through billing and conversion.</p>

                                <?php if ($selectedTenantRegistration): ?>
                                    <div class="dashboard-list compact-list">
                                        <div class="dashboard-list-item">
                                            <div>
                                                <strong>Source Registration</strong>
                                                <p>
                                                    #<?= (int) $selectedTenantRegistration['registration_id'] ?> |
                                                    <?= htmlspecialchars($selectedTenantRegistration['owner_full_name'], ENT_QUOTES, 'UTF-8') ?>
                                                </p>
                                            </div>
                                            <span class="status-chip status-<?= htmlspecialchars($selectedTenantRegistration['registration_status'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars(ucfirst($selectedTenantRegistration['registration_status']), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                        <div class="dashboard-list-item">
                                            <div>
                                                <strong>Plan and Billing Cycle</strong>
                                                <p>
                                                    <?= htmlspecialchars($selectedTenantRegistration['plan_name'], ENT_QUOTES, 'UTF-8') ?> |
                                                    <?= htmlspecialchars(ucfirst($selectedTenantRegistration['billing_cycle']), ENT_QUOTES, 'UTF-8') ?>
                                                </p>
                                            </div>
                                            <span class="metric-pill">Registration</span>
                                        </div>
                                        <div class="dashboard-list-item">
                                            <div>
                                                <strong>Registration Timeline</strong>
                                                <p>
                                                    Submitted: <?= htmlspecialchars($selectedTenantRegistration['created_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?><br>
                                                    Reviewed / Converted: <?= htmlspecialchars($selectedTenantRegistration['reviewed_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>
                                                </p>
                                            </div>
                                            <span class="metric-pill">Timeline</span>
                                        </div>
                                        <div class="dashboard-list-item">
                                            <div>
                                                <strong>Current Subscription State</strong>
                                                <p>
                                                    <?= htmlspecialchars($selectedTenant['plan'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> |
                                                    <?= htmlspecialchars($selectedTenant['start_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>
                                                    to
                                                    <?= htmlspecialchars($selectedTenant['end_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>
                                                </p>
                                            </div>
                                            <span class="status-chip">
                                                <?= htmlspecialchars(ucfirst($selectedTenant['subscription_status'] ?: 'none'), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="approval-actions">
                                        <a class="btn btn-secondary" href="dashboard.php?registration_id=<?= (int) $selectedTenantRegistration['registration_id'] ?>&tenant_id=<?= (int) $selectedTenant['tenant_id'] ?><?= $registrationSearch !== '' ? '&registration_search=' . urlencode($registrationSearch) : '' ?><?= $registrationStatusFilter !== '' ? '&registration_status=' . urlencode($registrationStatusFilter) : '' ?><?= $registrationBillingStatusFilter !== '' ? '&registration_billing_status=' . urlencode($registrationBillingStatusFilter) : '' ?><?= $tenantSearch !== '' ? '&tenant_search=' . urlencode($tenantSearch) : '' ?><?= $tenantStatusFilter !== '' ? '&tenant_status=' . urlencode($tenantStatusFilter) : '' ?><?= $subscriptionStatusFilter !== '' ? '&subscription_status=' . urlencode($subscriptionStatusFilter) : '' ?>#registrations">Open Registration Record</a>
                                    </div>

                                    <h3>Final Enabled Features</h3>
                                    <p>These are the modules currently enabled for this live tenant after plan defaults, requested add-ons, and any later super admin adjustments.</p>

                                    <div class="addon-list registration-addon-list">
                                        <?php if (!empty($selectedTenantEnabledFeatures)): ?>
                                            <?php foreach ($selectedTenantEnabledFeatures as $feature): ?>
                                                <div class="addon-item">
                                                    <div>
                                                        <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $feature['feature_name'])), ENT_QUOTES, 'UTF-8') ?></strong>
                                                        <p><?= htmlspecialchars($feature['description'] ?: 'Enabled for this tenant.', ENT_QUOTES, 'UTF-8') ?></p>
                                                    </div>
                                                    <span class="addon-price">Enabled</span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="table-placeholder">No enabled features are currently recorded for this tenant.</div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($selectedTenantBillingHistory)): ?>
                                        <div class="dashboard-list compact-list">
                                            <?php foreach ($selectedTenantBillingHistory as $billingHistory): ?>
                                                <div class="dashboard-list-item">
                                                    <div>
                                                        <strong>Billing Request #<?= (int) $billingHistory['billing_request_id'] ?></strong>
                                                        <p>
                                                            <?= htmlspecialchars($billingHistory['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $billingHistory['total_amount'], 2) ?> |
                                                            Due: <?= htmlspecialchars($billingHistory['due_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> |
                                                            Paid: <?= htmlspecialchars($billingHistory['paid_at'] ?: 'Not paid yet', ENT_QUOTES, 'UTF-8') ?><br>
                                                            Ref: <?= htmlspecialchars($billingHistory['payment_reference'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> |
                                                            PayMongo: <?= htmlspecialchars(ucfirst($billingHistory['paymongo_status'] ?: 'none'), ENT_QUOTES, 'UTF-8') ?>
                                                        </p>
                                                    </div>
                                                    <span class="status-chip">
                                                        <?= htmlspecialchars(ucfirst($billingHistory['billing_status']), ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-placeholder">
                                            No billing requests were found for the linked registration.
                                        </div>
                                    <?php endif; ?>

                                    <div class="table-placeholder">
                                        <strong>Conversion Notes</strong><br>
                                        <?= nl2br(htmlspecialchars($selectedTenantRegistration['notes'] ?: 'No conversion notes recorded.', ENT_QUOTES, 'UTF-8')) ?>
                                    </div>

                                    <h3>Communication Activity</h3>
                                    <p>Recent communication logs linked to this tenant lifecycle record.</p>

                                    <?php if (!empty($selectedTenantEmailLogs)): ?>
                                        <div class="dashboard-list compact-list">
                                            <?php foreach ($selectedTenantEmailLogs as $emailLog): ?>
                                                <div class="dashboard-list-item">
                                                    <div>
                                                        <strong><?= htmlspecialchars($emailLog['subject'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                        <p>
                                                            <?= htmlspecialchars($emailLog['recipient_email'], ENT_QUOTES, 'UTF-8') ?>
                                                            | Type: <?= htmlspecialchars($emailLog['email_type'], ENT_QUOTES, 'UTF-8') ?>
                                                        </p>
                                                        <p>
                                                            Created: <?= htmlspecialchars($emailLog['created_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>
                                                            | Sent: <?= htmlspecialchars($emailLog['sent_at'] ?: 'Pending', ENT_QUOTES, 'UTF-8') ?>
                                                        </p>
                                                    </div>
                                                    <span class="status-chip status-<?= htmlspecialchars($emailLog['send_status'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars(ucfirst($emailLog['send_status']), ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-placeholder">
                                            No email log entries were found for this tenant lifecycle yet.
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="table-placeholder">
                                        This tenant is live, but the dashboard could not find a linked source registration automatically. Review the conversion notes and tenant details before making lifecycle changes.
                                    </div>
                                <?php endif; ?>
                            </article>

                            <article class="content-card">
                                <h3>Tenant Status</h3>
                                <p>Control whether this tenant can access the platform. Tenant login already blocks inactive tenants.</p>

                                <form action="actions/update_tenant_status.php" method="POST" class="feature-toggle-form">
                                    <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                    <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">

                                    <div class="form-group">
                                        <label for="tenant_status_control">Tenant Status</label>
                                        <select class="form-control" id="tenant_status_control" name="status" required>
                                            <option value="active"<?= ($selectedTenant['status'] === 'active') ? ' selected' : '' ?>>Active</option>
                                            <option value="inactive"<?= ($selectedTenant['status'] === 'inactive') ? ' selected' : '' ?>>Inactive</option>
                                        </select>
                                    </div>

                                    <div class="feature-toggle-actions">
                                        <button type="submit" class="btn btn-primary">Save Tenant Status</button>
                                    </div>
                                </form>
                            </article>

                            <article class="content-card">
                                <h3>Subscription Status</h3>
                                <p>Update the latest subscription state using the current schema-supported values.</p>

                                <?php if (!empty($selectedTenant['subscription_status'])): ?>
                                    <form action="actions/update_subscription_status.php" method="POST" class="feature-toggle-form">
                                        <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                        <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">

                                        <div class="form-group">
                                            <label for="subscription_status_control">Subscription Status</label>
                                            <select class="form-control" id="subscription_status_control" name="status" required>
                                                <option value="active"<?= ($selectedTenant['subscription_status'] === 'active') ? ' selected' : '' ?>>Active</option>
                                                <option value="expired"<?= ($selectedTenant['subscription_status'] === 'expired') ? ' selected' : '' ?>>Expired</option>
                                            </select>
                                        </div>

                                        <div class="feature-toggle-actions">
                                            <button type="submit" class="btn btn-primary">Save Subscription Status</button>
                                        </div>
                                    </form>

                                    <form action="actions/update_subscription_window.php" method="POST" class="feature-toggle-form">
                                        <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                        <input type="hidden" name="action_type" value="save_dates">
                                        <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">

                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label for="subscription_start_date">Start Date</label>
                                                <input class="form-control" type="date" id="subscription_start_date" name="start_date" value="<?= htmlspecialchars($selectedTenant['start_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                            </div>

                                            <div class="form-group">
                                                <label for="subscription_end_date">End Date</label>
                                                <input class="form-control" type="date" id="subscription_end_date" name="end_date" value="<?= htmlspecialchars($selectedTenant['end_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                            </div>
                                        </div>

                                        <div class="feature-toggle-actions">
                                            <button type="submit" class="btn btn-secondary">Save Subscription Window</button>
                                        </div>
                                    </form>

                                    <form action="actions/update_subscription_window.php" method="POST" class="feature-toggle-form">
                                        <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                        <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">

                                        <div class="dashboard-list compact-list">
                                            <div class="dashboard-list-item">
                                                <div>
                                                    <strong>Quick Renewal</strong>
                                                    <p>Extend the latest subscription window from its current end date, or from today if already elapsed.</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="approval-actions">
                                            <button type="submit" name="action_type" value="extend_month" class="btn btn-secondary">Extend 1 Month</button>
                                            <button type="submit" name="action_type" value="extend_year" class="btn btn-primary">Extend 1 Year</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="table-placeholder">
                                        This tenant does not have a subscription record yet.
                                    </div>
                                <?php endif; ?>
                            </article>
                        </div>

                        <h3>Feature Toggles</h3>
                        <p>Plan-included and requested add-on features are auto-applied during tenant conversion. You can fine-tune them here afterward.</p>

                        <form action="actions/save_tenant_features.php" method="POST" class="feature-toggle-form">
                            <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                            <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">

                            <div class="feature-toggle-grid">
                                <?php foreach ($features as $feature): ?>
                                    <label class="feature-toggle-card">
                                        <span class="feature-toggle-copy">
                                            <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $feature['feature_name'])), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <span><?= htmlspecialchars($feature['description'] ?: 'No description provided.', ENT_QUOTES, 'UTF-8') ?></span>
                                        </span>
                                        <span class="switch">
                                            <input
                                                type="checkbox"
                                                name="features[]"
                                                value="<?= (int) $feature['feature_id'] ?>"
                                                <?= !empty($featureStates[(int) $feature['feature_id']]) ? 'checked' : '' ?>
                                            >
                                            <span class="switch-slider"></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <div class="feature-toggle-actions">
                                <button type="submit" class="btn btn-primary">Save Tenant Features</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <p>Select a tenant from the left to manage feature access.</p>
                    <?php endif; ?>
                </article>
            </section>
        </main>
    </div>

    <script src="../assets/js/theme.js"></script>
</body>
</html>
