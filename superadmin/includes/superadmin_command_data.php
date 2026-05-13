<?php
declare(strict_types=1);

$mechanixSuperadminLoadOpts = $mechanixSuperadminLoadOpts ?? [];

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/platform_rules.php';

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
$earnings = [
    'current_month' => 0,
    'last_month' => 0,
    'all_time' => 0,
    'paid_requests' => 0,
];
$earningsTrend = [];
$referenceReviewQueue = [];
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
$allowedTenantStatuses = ['active', 'inactive', 'pending_payment'];
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

    $earningsStmt = $pdo->query("
        SELECT
            COALESCE(SUM(CASE WHEN DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN total_amount ELSE 0 END), 0) AS current_month,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m') THEN total_amount ELSE 0 END), 0) AS last_month,
            COALESCE(SUM(total_amount), 0) AS all_time,
            COUNT(*) AS paid_requests
        FROM billing_requests
        WHERE billing_status = 'paid'
    ");
    $earningsRow = $earningsStmt->fetch();

    if ($earningsRow) {
        $earnings['current_month'] = (float) ($earningsRow['current_month'] ?? 0);
        $earnings['last_month'] = (float) ($earningsRow['last_month'] ?? 0);
        $earnings['all_time'] = (float) ($earningsRow['all_time'] ?? 0);
        $earnings['paid_requests'] = (int) ($earningsRow['paid_requests'] ?? 0);
    }

    $earningsTrendStmt = $pdo->query("
        SELECT
            DATE_FORMAT(paid_at, '%Y-%m') AS earnings_month,
            COALESCE(SUM(total_amount), 0) AS month_total
        FROM billing_requests
        WHERE billing_status = 'paid'
          AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY DATE_FORMAT(paid_at, '%Y-%m')
        ORDER BY earnings_month ASC
    ");
    $earningsTrend = $earningsTrendStmt->fetchAll();

    $referenceQueueStmt = $pdo->query("
        SELECT
            br.billing_request_id,
            br.registration_id,
            tr.business_name,
            br.payment_reference,
            br.payment_reference_check_status,
            br.billing_status,
            br.total_amount,
            br.currency
        FROM billing_requests br
        INNER JOIN tenant_registrations tr
            ON tr.registration_id = br.registration_id
        WHERE br.billing_status IN ('sent', 'paid')
        ORDER BY br.billing_request_id DESC
        LIMIT 8
    ");
    $referenceReviewQueue = $referenceQueueStmt->fetchAll();

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
                OR tr.bir_tin LIKE :registration_search
                OR tr.owner_id_number LIKE :registration_search
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

    if (($mechanixSuperadminLoadOpts['auto_select_registration'] ?? true) && $selectedRegistrationId === 0 && !empty($registrations)) {
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
                tr.bir_tin,
                tr.owner_id_number,
                tr.owner_id_document_path,
                (tr.password_hash IS NOT NULL AND tr.password_hash <> '') AS has_registration_password,
                tr.provisioned_tenant_id,
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
                    payment_reference_check_status,
                    payment_reference_checked_at,
                    payment_reference_check_notes,
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

            if ($selectedBillingRequest) {
                $duplicateReferenceCount = 0;
                $normalizedReference = normalizePaymentReference($selectedBillingRequest['payment_reference'] ?? '');

                if ($normalizedReference !== '') {
                    $duplicateReferenceStmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM billing_requests
                        WHERE REPLACE(REPLACE(REPLACE(UPPER(COALESCE(payment_reference, '')), '-', ''), ' ', ''), '.', '') = :payment_reference
                    ");
                    $duplicateReferenceStmt->execute(['payment_reference' => $normalizedReference]);
                    $duplicateReferenceCount = (int) $duplicateReferenceStmt->fetchColumn();
                }

                $selectedBillingRequest['ng_reference_meta'] = evaluateNgReference($selectedBillingRequest, $duplicateReferenceCount);
                $selectedBillingRequest['duplicate_reference_count'] = $duplicateReferenceCount;
            }

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

    if (($mechanixSuperadminLoadOpts['auto_select_tenant'] ?? true) && $selectedTenantId === 0 && !empty($tenants)) {
        $selectedTenantId = (int) $tenants[0]['tenant_id'];
    }

    if ($selectedTenantId > 0) {
        $selectedTenantStmt = $pdo->prepare("
            SELECT
                t.tenant_id,
                t.business_name,
                t.status,
                t.access_mode,
                t.read_only_source_plan,
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
    $errorMessage = 'Unable to load super admin workspace: ' . $e->getMessage();
}
