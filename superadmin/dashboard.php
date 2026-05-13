<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/platform_rules.php';
require_once __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../includes/mechanix_ui.php';

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

    if ($selectedTenantId === 0 && !empty($tenants)) {
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
    $errorMessage = 'Unable to load super admin dashboard: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - MECHANIX</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap">
    <link rel="stylesheet" href="../assets/css/tabler-mechanix-bridge.css">
    <link rel="stylesheet" href="../assets/css/superadmin-landing-theme.css">
</head>
<body class="antialiased superadmin-app">
<div class="page">

    <!-- SIDEBAR -->
    <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <h1 class="navbar-brand navbar-brand-autodark m-0">
                <a href="dashboard.php" class="d-flex align-items-center gap-2 text-decoration-none" aria-label="MECHANIX super admin home">
                    <span class="sa-brand-mark" aria-hidden="true">M</span>
                    <span class="fw-bold">MECHANIX</span>
                </a>
            </h1>
            <div class="collapse navbar-collapse" id="sidebar-menu">
                <ul class="navbar-nav flex-column">
                    <li class="nav-item sa-nav-section-heading">
                        <span class="navbar-heading text-muted small text-uppercase fw-bold px-2">Platform</span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="#registrations">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-clipboard-list"></i></span>
                            <span class="nav-link-title">Registrations</span>
                            <?php if ($summary['pending_registrations'] > 0): ?>
                                <span class="badge bg-red ms-auto"><?= number_format($summary['pending_registrations']) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#features">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-building-store"></i></span>
                            <span class="nav-link-title">Tenant Operations</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#catalog">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-packages"></i></span>
                            <span class="nav-link-title">Plan Catalog</span>
                        </a>
                    </li>
                    <li class="nav-item sa-nav-section-heading mt-2">
                        <span class="navbar-heading text-muted small text-uppercase fw-bold px-2">Lifecycle</span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#features">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-activity"></i></span>
                            <span class="nav-link-title">Tenant Status</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#features">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-credit-card"></i></span>
                            <span class="nav-link-title">Subscriptions</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="earnings.php">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-chart-bar"></i></span>
                            <span class="nav-link-title">Earnings Reports</span>
                        </a>
                    </li>
                </ul>
                <div class="mt-auto p-3">
                    <div class="text-muted small mb-2">
                        Logged in as <strong><?= htmlspecialchars($_SESSION['super_admin_username'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <a href="../logout.php" class="btn btn-outline-light w-100">
                        <i class="ti ti-logout me-2"></i>Log Out
                    </a>
                </div>
            </div>
        </div>
    </aside>

    <!-- PAGE WRAPPER -->
    <div class="page-wrapper">
        <!-- PAGE HEADER -->
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="page-pretitle"><span class="sa-eyebrow">Super Admin</span></div>
                        <h2 class="page-title">Dashboard</h2>
                        <p class="text-muted mt-1 mb-0 small">Manage registrations, billing, tenant activation, subscriptions, and tenant access from one operational workspace.</p>
                    </div>
                    <div class="col-auto ms-auto d-print-none">
                        <?= mechanix_theme_toggle_button() ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- PAGE BODY -->
        <div class="page-body">
            <div class="container-xl">

            <?php if ($flashMessage !== null): ?>
                <div class="alert alert-success" role="alert">
                    <div class="d-flex">
                        <div><i class="ti ti-circle-check icon alert-icon"></i></div>
                        <div><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== null): ?>
                <div class="alert alert-danger" role="alert">
                    <div class="d-flex">
                        <div><i class="ti ti-alert-circle icon alert-icon"></i></div>
                        <div><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="sa-status-bar mb-4 d-flex align-items-center gap-2 flex-wrap">
                <span class="badge <?= $isPaymongoConfigured ? 'bg-green-lt text-green' : 'bg-orange-lt text-orange' ?>">
                    <i class="ti ti-<?= $isPaymongoConfigured ? 'circle-check' : 'alert-circle' ?> me-1"></i>
                    PayMongo <?= $isPaymongoConfigured ? 'Ready' : 'Incomplete' ?>
                </span>
                <span class="text-muted small"><?= $isPaymongoConfigured
                    ? 'Checkout &amp; webhook verification available.'
                    : 'Manual billing works. Checkout &amp; webhooks not fully configured yet.' ?></span>
            </div>

            <?php if (!empty($operationalAlerts)): ?>
                <?php $alertErrorCount = count(array_filter($operationalAlerts, fn($a) => $a['class'] === 'alert-error')); ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="ti ti-alert-triangle me-2"></i>Operational Alerts</h3>
                        <div class="card-options">
                            <?php if ($alertErrorCount > 0): ?>
                                <span class="badge bg-red-lt text-red"><?= $alertErrorCount ?> issue<?= $alertErrorCount !== 1 ? 's' : '' ?></span>
                            <?php else: ?>
                                <span class="badge bg-green-lt text-green">All clear</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($operationalAlerts as $alert): ?>
                            <?php $isError = $alert['class'] === 'alert-error'; ?>
                            <div class="list-group-item sa-op-alert <?= $isError ? 'sa-op-alert--danger' : 'sa-op-alert--success' ?>">
                                <div class="d-flex align-items-start gap-3">
                                    <i class="ti ti-<?= $isError ? 'alert-circle' : 'circle-check' ?> flex-shrink-0 mt-1 <?= $isError ? 'text-danger' : 'text-success' ?>"></i>
                                    <div>
                                        <div class="fw-semibold small"><?= htmlspecialchars($alert['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="text-muted small mt-1"><?= htmlspecialchars($alert['detail'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (is_array($tenantOnboarding)): ?>
                <div class="card card-status-top bg-green mb-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="ti ti-circle-check me-2 text-green"></i>Tenant Onboarding Ready</h3>
                        <div class="card-options">
                            <a href="../login.php" class="btn btn-success btn-sm">Open Tenant Login</a>
                        </div>
                    </div>
                    <div class="card-body pb-0">
                        <p class="text-muted small">
                            <?= htmlspecialchars($tenantOnboarding['business_name'], ENT_QUOTES, 'UTF-8') ?> is now live and ready for first login testing.
                        </p>
                    </div>
                    <div class="row row-cards px-3 pb-3">
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-muted small">Tenant ID</div>
                                    <div class="font-weight-medium"><?= number_format((int) $tenantOnboarding['tenant_id']) ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-muted small">Admin Username</div>
                                    <div class="font-weight-medium"><?= htmlspecialchars($tenantOnboarding['username'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-muted small">Temporary Password</div>
                                    <div class="font-weight-medium"><?= htmlspecialchars($tenantOnboarding['temporary_password'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-muted small">Subscription Window</div>
                                    <div class="font-weight-medium">
                                        <?= htmlspecialchars($tenantOnboarding['subscription_start'], ENT_QUOTES, 'UTF-8') ?>
                                        &mdash;
                                        <?= htmlspecialchars($tenantOnboarding['subscription_end'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="list-group list-group-flush">
                        <div class="list-group-item">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="font-weight-medium">Plan and Billing</div>
                                    <div class="text-muted small">
                                        <?= htmlspecialchars($tenantOnboarding['plan_name'], ENT_QUOTES, 'UTF-8') ?>
                                        &middot;
                                        <?= htmlspecialchars(ucfirst($tenantOnboarding['billing_cycle']), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                                <div class="col-auto"><span class="badge bg-green-lt text-green">Active</span></div>
                            </div>
                        </div>
                        <div class="list-group-item">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="font-weight-medium">Primary Admin</div>
                                    <div class="text-muted small"><?= htmlspecialchars($tenantOnboarding['admin_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="col-auto"><span class="badge bg-green-lt text-green">Ready</span></div>
                            </div>
                        </div>
                    </div>
                    <div class="card-header mt-2">
                        <h3 class="card-title">Assigned Feature Set</h3>
                    </div>
                    <div class="card-body pb-0">
                        <p class="text-muted small">These are the modules applied during conversion from the selected plan and requested add-ons.</p>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php if (!empty($tenantOnboarding['assigned_features']) && is_array($tenantOnboarding['assigned_features'])): ?>
                            <?php foreach ($tenantOnboarding['assigned_features'] as $feature): ?>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $feature['feature_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars(($feature['description'] ?? '') !== '' ? $feature['description'] : 'Enabled during tenant conversion.', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-blue-lt text-blue"><?= htmlspecialchars(ucwords((string) ($feature['source'] ?? 'enabled')), ENT_QUOTES, 'UTF-8') ?></span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="list-group-item">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No features applied</p>
                                    <p class="empty-subtitle text-muted">No plan-linked or add-on features were applied during this conversion.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mb-0" role="alert">
                            <div class="d-flex">
                                <div><i class="ti ti-info-circle icon alert-icon"></i></div>
                                <div>
                                    <strong>Quick Validation Flow</strong><br>
                                    1. Open the tenant login page.<br>
                                    2. Sign in with the admin username and temporary password shown above.<br>
                                    3. Confirm the tenant dashboard opens and only shows the enabled features for this new tenant.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Metric cards row 1: Registrations + Tenants -->
            <div class="row row-deck row-cards mb-3">
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-orange text-white avatar"><i class="ti ti-clock icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Pending Registrations</div>
                                    <div class="text-muted"><?= number_format($summary['pending_registrations']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-blue text-white avatar"><i class="ti ti-building icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Total Tenants</div>
                                    <div class="text-muted"><?= number_format($summary['tenants']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-green text-white avatar"><i class="ti ti-building-community icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Active Tenants</div>
                                    <div class="text-muted"><?= number_format($summary['active_tenants']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-red text-white avatar"><i class="ti ti-building-off icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Inactive Tenants</div>
                                    <div class="text-muted"><?= number_format($summary['inactive_tenants']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Metric cards row 2: Subscriptions + Registration pipeline -->
            <div class="row row-deck row-cards mb-4">
                <div class="col-sm-6 col-lg-2">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-azure text-white avatar"><i class="ti ti-toggle-right icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Feature Links</div>
                                    <div class="text-muted"><?= number_format($summary['enabled_feature_links']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-teal text-white avatar"><i class="ti ti-receipt icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Active Subs</div>
                                    <div class="text-muted"><?= number_format($summary['subscriptions']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-red text-white avatar"><i class="ti ti-alert-triangle icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Expired / Overdue</div>
                                    <div class="text-muted"><?= number_format($summary['subscription_attention']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-orange text-white avatar"><i class="ti ti-calendar-due icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Due 7 Days</div>
                                    <div class="text-muted"><?= number_format($summary['subscriptions_due_soon']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-secondary text-white avatar"><i class="ti ti-receipt-off icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">No Sub</div>
                                    <div class="text-muted"><?= number_format($summary['tenants_without_subscription']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-yellow text-white avatar"><i class="ti ti-file-dollar icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Awaiting Billing</div>
                                    <div class="text-muted"><?= number_format($summary['awaiting_billing_registrations']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Metric cards row 3: Billing pipeline -->
            <div class="row row-deck row-cards mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-blue text-white avatar"><i class="ti ti-send icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Billing Sent</div>
                                    <div class="text-muted"><?= number_format($summary['billing_sent_registrations']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-auto">
                                    <span class="bg-purple text-white avatar"><i class="ti ti-refresh icon"></i></span>
                                </div>
                                <div class="col">
                                    <div class="font-weight-medium">Awaiting Conversion</div>
                                    <div class="text-muted"><?= number_format($summary['awaiting_conversion_registrations']) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue Snapshot + Reference Verification Queue -->
            <div class="row row-deck row-cards mb-4">
                <div class="col-lg-6">
                    <div class="card sa-dark-card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-currency-peso me-2 text-muted"></i>Revenue Snapshot</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">Current earnings and paid billing volume from the platform billing ledger.</p>
                        </div>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">This Month</div>
                                        <div class="text-muted small">Paid requests collected during the current month.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-teal-lt text-teal">PHP <?= number_format($earnings['current_month'], 2) ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Last Month</div>
                                        <div class="text-muted small">Use this to compare short-term performance.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-blue-lt text-blue">PHP <?= number_format($earnings['last_month'], 2) ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">All-Time Earnings</div>
                                        <div class="text-muted small">Total verified billing collected so far.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-green-lt text-green">PHP <?= number_format($earnings['all_time'], 2) ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Paid Billing Requests</div>
                                        <div class="text-muted small">Total payment events already captured in the system.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt"><?= number_format($earnings['paid_requests']) ?></span></div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer">
                            <a href="earnings.php" class="btn btn-primary btn-sm">Open Earnings Report</a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-scan me-2 text-muted"></i>Reference Verification Queue</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">Cross-check NG references before converting or auditing paid registrations.</p>
                        </div>
                        <?php if (!empty($referenceReviewQueue)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($referenceReviewQueue as $referenceItem): ?>
                                    <?php $referenceMeta = evaluateNgReference($referenceItem); ?>
                                    <?php
                                    $refBadge = match($referenceMeta['class'] ?? '') {
                                        'status-active', 'status-approved' => 'bg-green-lt text-green',
                                        'status-pending' => 'bg-orange-lt text-orange',
                                        'status-rejected', 'status-inactive' => 'bg-red-lt text-red',
                                        'status-converted', 'status-billing_sent' => 'bg-blue-lt text-blue',
                                        'status-paid' => 'bg-teal-lt text-teal',
                                        'status-warning' => 'bg-orange-lt text-orange',
                                        default => 'bg-secondary-lt',
                                    };
                                    ?>
                                    <a class="list-group-item list-group-item-action" href="dashboard.php?registration_id=<?= (int) $referenceItem['registration_id'] ?>#registrations">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars($referenceItem['business_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small">
                                                    <?= htmlspecialchars($referenceItem['payment_reference'] ?: 'No reference yet', ENT_QUOTES, 'UTF-8') ?>
                                                    &middot; <?= htmlspecialchars($referenceItem['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $referenceItem['total_amount'], 2) ?>
                                                </div>
                                            </div>
                                            <div class="col-auto"><span class="badge <?= $refBadge ?>"><?= htmlspecialchars($referenceMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="card-body">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No pending references</p>
                                    <p class="empty-subtitle text-muted">No billing requests are waiting for reference review.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Plan Catalog + Add-On Catalog -->
            <div id="catalog" class="row g-3 mb-4 align-items-start">
                <div class="col-lg-6">
                    <?php if (!empty($planCatalog)): ?>
                        <div class="sa-catalog-plans d-flex flex-column gap-3">
                            <div class="card sa-dark-card">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="ti ti-packages me-2 text-muted"></i>Plan Catalog</h3>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted small mb-0">Adjust pricing in real time and keep registration, billing drafts, and future conversions aligned with the latest catalog values.</p>
                                </div>
                            </div>
                            <?php foreach ($planCatalog as $plan): ?>
                                <div class="card sa-plan-edit-card">
                                    <div class="card-body">
                                        <form action="actions/update_plan_catalog.php" method="POST">
                                            <input type="hidden" name="plan_id" value="<?= (int) $plan['plan_id'] ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Plan Name</label>
                                                <input class="form-control" type="text" name="plan_name" value="<?= htmlspecialchars($plan['plan_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                                            </div>
                                            <div class="row g-2 mb-3">
                                                <div class="col">
                                                    <label class="form-label">Monthly Price</label>
                                                    <input class="form-control" type="number" step="0.01" min="0" name="monthly_price" value="<?= htmlspecialchars((string) $plan['monthly_price'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                                <div class="col">
                                                    <label class="form-label">Yearly Price</label>
                                                    <input class="form-control" type="number" step="0.01" min="0" name="yearly_price" value="<?= htmlspecialchars((string) $plan['yearly_price'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Description</label>
                                                <textarea class="form-control" rows="2" name="description"><?= htmlspecialchars($plan['description'] ?: '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-check">
                                                    <input type="checkbox" class="form-check-input" name="is_active" value="1" <?= (int) $plan['is_active'] === 1 ? 'checked' : '' ?>>
                                                    <span class="form-check-label">Active in catalog</span>
                                                </label>
                                            </div>
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <span class="badge <?= (int) $plan['is_active'] === 1 ? 'bg-green-lt text-green' : 'bg-red-lt text-red' ?>">
                                                    <?= (int) $plan['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                                </span>
                                                <button type="submit" class="btn btn-primary btn-sm ms-auto">Save Plan</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="ti ti-packages me-2 text-muted"></i>Plan Catalog</h3>
                            </div>
                            <div class="card-body">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No plans configured</p>
                                    <p class="empty-subtitle text-muted">No subscription plans are configured yet.</p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-puzzle me-2 text-muted"></i>Add-On Catalog</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">Reference view of the optional features currently priced for registrations and billing drafts.</p>
                        </div>
                        <?php if (!empty($addonCatalog)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($addonCatalog as $addon): ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $addon['feature_name'])), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small">
                                                    Monthly: PHP <?= number_format((float) $addon['monthly_addon_price'], 2) ?>
                                                    &middot; Yearly: PHP <?= number_format((float) $addon['yearly_addon_price'], 2) ?>
                                                </div>
                                                <div class="text-muted small"><?= htmlspecialchars($addon['description'] ?: 'No add-on description provided.', ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <div class="col-auto">
                                                <span class="badge <?= (int) $addon['is_active'] === 1 ? 'bg-green-lt text-green' : 'bg-red-lt text-red' ?>">
                                                    <?= (int) $addon['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="card-body">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No add-ons configured</p>
                                    <p class="empty-subtitle text-muted">No add-on pricing is configured yet.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Registration Queue + Registration Details -->
            <div id="registrations" class="row row-deck row-cards mb-4">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-clipboard-list me-2 text-muted"></i>Registration Queue</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">Review incoming business applications before billing and tenant activation.</p>
                        </div>
                        <div class="card-body border-top">
                            <form action="dashboard.php" method="GET">
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
                                <div class="row g-2 mb-2">
                                    <div class="col-12">
                                        <label class="form-label" for="registration_search">Search Registration</label>
                                        <input class="form-control" type="text" id="registration_search" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Business, owner, or email">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="registration_status_filter">Registration Status</label>
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
                                    <div class="col-sm-6">
                                        <label class="form-label" for="registration_billing_status_filter">Billing Status</label>
                                        <select class="form-control" id="registration_billing_status_filter" name="registration_billing_status">
                                            <option value="">All billing statuses</option>
                                            <option value="draft"<?= $registrationBillingStatusFilter === 'draft' ? ' selected' : '' ?>>Draft</option>
                                            <option value="sent"<?= $registrationBillingStatusFilter === 'sent' ? ' selected' : '' ?>>Sent</option>
                                            <option value="paid"<?= $registrationBillingStatusFilter === 'paid' ? ' selected' : '' ?>>Paid</option>
                                            <option value="cancelled"<?= $registrationBillingStatusFilter === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
                                            <option value="none"<?= $registrationBillingStatusFilter === 'none' ? ' selected' : '' ?>>No billing request</option>
                                        </select>
                                    </div>
                                    <div class="col-12 d-flex gap-2">
                                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                                        <a href="dashboard.php<?= $selectedTenantId > 0 ? '?tenant_id=' . (int) $selectedTenantId . '#registrations' : '#registrations' ?>" class="btn btn-secondary btn-sm">Reset</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <?php if (!empty($registrations)): ?>
                            <div class="list-group list-group-flush">
                                <?php
                                $regStatusBadge = [
                                    'pending'      => 'bg-orange-lt text-orange',
                                    'approved'     => 'bg-green-lt text-green',
                                    'billing_sent' => 'bg-blue-lt text-blue',
                                    'paid'         => 'bg-teal-lt text-teal',
                                    'converted'    => 'bg-blue-lt text-blue',
                                    'rejected'     => 'bg-red-lt text-red',
                                ];
                                foreach ($registrations as $registration):
                                    $isSelectedRegistration = (int) $registration['registration_id'] === $selectedRegistrationId;
                                    $regBadge = $regStatusBadge[$registration['registration_status']] ?? 'bg-secondary-lt';
                                ?>
                                    <a class="list-group-item list-group-item-action<?= $isSelectedRegistration ? ' active' : '' ?>" href="dashboard.php?registration_id=<?= (int) $registration['registration_id'] ?><?= $registrationSearch !== '' ? '&registration_search=' . urlencode($registrationSearch) : '' ?><?= $registrationStatusFilter !== '' ? '&registration_status=' . urlencode($registrationStatusFilter) : '' ?><?= $registrationBillingStatusFilter !== '' ? '&registration_billing_status=' . urlencode($registrationBillingStatusFilter) : '' ?><?= $selectedTenantId > 0 ? '&tenant_id=' . (int) $selectedTenantId : '' ?><?= $tenantSearch !== '' ? '&tenant_search=' . urlencode($tenantSearch) : '' ?><?= $tenantStatusFilter !== '' ? '&tenant_status=' . urlencode($tenantStatusFilter) : '' ?><?= $subscriptionStatusFilter !== '' ? '&subscription_status=' . urlencode($subscriptionStatusFilter) : '' ?>#registrations">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars($registration['business_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small">
                                                    <?= htmlspecialchars($registration['plan_name'], ENT_QUOTES, 'UTF-8') ?>
                                                    &middot; <?= htmlspecialchars($registration['owner_full_name'], ENT_QUOTES, 'UTF-8') ?>
                                                    &middot; Billing: <?= htmlspecialchars($registration['latest_billing_status'] ?: 'none', ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <span class="badge <?= $regBadge ?>">
                                                    <?= htmlspecialchars(ucfirst($registration['registration_status']), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="card-body">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No registrations</p>
                                    <p class="empty-subtitle text-muted">No registrations matched the current filters.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-file-description me-2 text-muted"></i>Registration Details</h3>
                        </div>
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
                        $regDetailBadge = $regStatusBadge[$registrationStatus] ?? 'bg-secondary-lt';
                        ?>
                        <div class="card-body pb-0">
                            <p class="text-muted small">
                                Reviewing <strong><?= htmlspecialchars($selectedRegistration['business_name'], ENT_QUOTES, 'UTF-8') ?></strong> for the <?= htmlspecialchars($selectedRegistration['plan_name'], ENT_QUOTES, 'UTF-8') ?> plan.
                            </p>
                        </div>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Primary Contact</div>
                                        <div class="text-muted small"><?= htmlspecialchars($selectedRegistration['owner_full_name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($selectedRegistration['email'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge <?= $regDetailBadge ?>"><?= htmlspecialchars(ucfirst($registrationStatus), ENT_QUOTES, 'UTF-8') ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Billing Cycle</div>
                                        <div class="text-muted small">Preferred username: <?= htmlspecialchars($selectedRegistration['preferred_username'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt"><?= htmlspecialchars($selectedRegistration['billing_cycle'] === 'yearly' ? 'Yearly' : 'Monthly', ENT_QUOTES, 'UTF-8') ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Plan Price</div>
                                        <div class="text-muted small">Monthly: PHP <?= number_format((float) $selectedRegistration['monthly_price'], 2) ?> &middot; Yearly: PHP <?= number_format((float) $selectedRegistration['yearly_price'], 2) ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-blue-lt text-blue"><?= htmlspecialchars($selectedRegistration['plan_name'], ENT_QUOTES, 'UTF-8') ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Estimated Add-On Total</div>
                                        <div class="text-muted small">Calculated from requested add-ons for the selected billing cycle.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt">PHP <?= number_format((float) $selectedRegistration['estimated_addon_amount'], 2) ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Address</div>
                                        <div class="text-muted small"><?= nl2br(htmlspecialchars($selectedRegistration['address'] ?: 'No address provided.', ENT_QUOTES, 'UTF-8')) ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">BIR TIN</div>
                                        <div class="text-muted small"><?= htmlspecialchars((string) ($selectedRegistration['bir_tin'] ?? ''), ENT_QUOTES, 'UTF-8') ?: 'Not provided' ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt">Tax</span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Government ID number</div>
                                        <div class="text-muted small"><?= htmlspecialchars((string) ($selectedRegistration['owner_id_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?: 'Not provided' ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt">ID #</span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Admin password (registration)</div>
                                        <div class="text-muted small"><?= !empty($selectedRegistration['has_registration_password']) ? 'A bcrypt hash is stored from signup — never the plain password.' : 'No hash on file (legacy row or incomplete signup).' ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt"><?= !empty($selectedRegistration['has_registration_password']) ? 'Stored' : 'Missing' ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Owner / business ID</div>
                                        <div class="text-muted small">
                                            <?php if (!empty($selectedRegistration['owner_id_document_path'])): ?>
                                                <a href="serve_registration_document.php?registration_id=<?= (int) $selectedRegistration['registration_id'] ?>" target="_blank" rel="noopener noreferrer">View uploaded document</a>
                                            <?php else: ?>
                                                No file on record (legacy registration).
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt">KYC</span></div>
                                </div>
                            </div>
                            <?php if (!empty($selectedRegistration['provisioned_tenant_id'])): ?>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Owner workspace</div>
                                        <div class="text-muted small">Tenant #<?= (int) $selectedRegistration['provisioned_tenant_id'] ?> created at approval — owner can log in while billing is pending.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-azure-lt text-azure">Provisioned</span></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="card-header mt-2">
                            <h3 class="card-title">Included Plan Features</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">These features will be auto-enabled for the tenant when the paid registration is converted into a live tenant.</p>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php if (!empty($selectedRegistrationPlanFeatures)): ?>
                                <?php foreach ($selectedRegistrationPlanFeatures as $planFeature): ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $planFeature['feature_name'])), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small"><?= htmlspecialchars($planFeature['description'] ?: 'Included in the selected subscription plan.', ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <div class="col-auto"><span class="badge bg-green-lt text-green">Included</span></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="list-group-item text-muted small">No plan-linked features are configured for this subscription plan yet.</div>
                            <?php endif; ?>
                            <?php if (!empty($selectedRegistrationFeatures)): ?>
                                <?php foreach ($selectedRegistrationFeatures as $addon): ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $addon['feature_name'])), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small"><?= htmlspecialchars($addon['description'] ?: 'Requested add-on feature.', ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <div class="col-auto"><span class="badge bg-blue-lt text-blue">PHP <?= number_format((float) $addon['monthly_addon_price'], 2) ?>/mo</span></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="list-group-item text-muted small">No add-on features requested for this registration.</div>
                            <?php endif; ?>
                        </div>

                        <div class="card-header mt-2">
                            <h3 class="card-title">Billing</h3>
                        </div>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Billing Draft</div>
                                        <div class="text-muted small"><?= $selectedBillingRequest ? 'Latest billing request is ready for review.' : 'No billing request generated yet.' ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <span class="badge <?= $selectedBillingRequest ? 'bg-blue-lt text-blue' : 'bg-orange-lt text-orange' ?>">
                                            <?= htmlspecialchars(ucfirst($selectedBillingRequest['billing_status'] ?? 'none'), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php if ($selectedBillingRequest): ?>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Amounts</div>
                                            <div class="text-muted small">Plan: <?= htmlspecialchars($selectedBillingRequest['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $selectedBillingRequest['plan_amount'], 2) ?> &middot; Add-ons: <?= htmlspecialchars($selectedBillingRequest['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $selectedBillingRequest['addon_amount'], 2) ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-teal-lt text-teal"><?= htmlspecialchars($selectedBillingRequest['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $selectedBillingRequest['total_amount'], 2) ?></span></div>
                                    </div>
                                </div>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Due Date</div>
                                            <div class="text-muted small"><?= htmlspecialchars($selectedBillingRequest['due_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-secondary-lt"><?= htmlspecialchars(ucfirst($selectedBillingRequest['billing_status']), ENT_QUOTES, 'UTF-8') ?></span></div>
                                    </div>
                                </div>
                                <?php
                                $ngRefClass = match($selectedBillingRequest['ng_reference_meta']['class'] ?? '') {
                                    'status-active', 'status-approved' => 'bg-green-lt text-green',
                                    'status-rejected', 'status-inactive' => 'bg-red-lt text-red',
                                    'status-warning' => 'bg-orange-lt text-orange',
                                    default => 'bg-orange-lt text-orange',
                                };
                                ?>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Payment Reference</div>
                                            <div class="text-muted small"><?= htmlspecialchars($selectedBillingRequest['payment_reference'] ?: 'Not set yet', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge <?= $ngRefClass ?>"><?= htmlspecialchars($selectedBillingRequest['ng_reference_meta']['label'] ?? 'Ref', ENT_QUOTES, 'UTF-8') ?></span></div>
                                    </div>
                                </div>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">NG Reference Review</div>
                                            <div class="text-muted small"><?= htmlspecialchars($selectedBillingRequest['ng_reference_meta']['detail'] ?? 'No reference review recorded yet.', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-secondary-lt"><?= number_format((int) ($selectedBillingRequest['duplicate_reference_count'] ?? 0)) ?>x</span></div>
                                    </div>
                                </div>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Paid At</div>
                                            <div class="text-muted small"><?= htmlspecialchars($selectedBillingRequest['paid_at'] ?: 'Not paid yet', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-secondary-lt">Payment</span></div>
                                    </div>
                                </div>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">PayMongo Checkout</div>
                                            <div class="text-muted small"><?= htmlspecialchars($selectedBillingRequest['paymongo_checkout_session_id'] ?? 'Not created yet', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-secondary-lt"><?= htmlspecialchars(ucfirst($selectedBillingRequest['paymongo_status'] ?? 'none'), ENT_QUOTES, 'UTF-8') ?></span></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="card-body">
                            <?php if ($canGenerateBillingDraft): ?>
                                <form action="actions/generate_billing_request.php" method="POST" class="mb-3">
                                    <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                    <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="mb-2">
                                        <label class="form-label" for="due_date">Billing Due Date</label>
                                        <input class="form-control" type="date" id="due_date" name="due_date" value="<?= htmlspecialchars($selectedBillingRequest['due_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm"><?= $selectedBillingRequest ? 'Update Billing Draft' : 'Generate Billing Draft' ?></button>
                                </form>
                            <?php else: ?>
                                <div class="alert alert-warning mb-3" role="alert">
                                    <div class="d-flex">
                                        <div><i class="ti ti-lock icon alert-icon"></i></div>
                                        <div><strong>Billing Draft Locked</strong><br>Approve the registration first before preparing billing, and stop billing changes once conversion is complete.</div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($selectedBillingRequest): ?>
                                <?php if ($canCreateCheckout): ?>
                                    <form action="actions/create_paymongo_checkout.php" method="POST" class="mb-3">
                                        <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                        <input type="hidden" name="billing_request_id" value="<?= (int) $selectedBillingRequest['billing_request_id'] ?>">
                                        <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary btn-sm">Create PayMongo Checkout</button>
                                            <?php if (!empty($selectedBillingRequest['paymongo_checkout_url'])): ?>
                                                <a href="<?= htmlspecialchars($selectedBillingRequest['paymongo_checkout_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">Open Checkout</a>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                <?php elseif (!empty($selectedBillingRequest['paymongo_checkout_url'])): ?>
                                    <div class="mb-3">
                                        <a href="<?= htmlspecialchars($selectedBillingRequest['paymongo_checkout_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">Open Checkout</a>
                                    </div>
                                <?php endif; ?>

                                <?php if ($canConvertTenant): ?>
                                    <form action="actions/convert_registration_to_tenant.php" method="POST" class="mb-3">
                                        <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                        <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="alert alert-info mb-2" role="alert">
                                            <div class="d-flex">
                                                <div><i class="ti ti-info-circle icon alert-icon"></i></div>
                                                <div>This activates the subscription on an existing owner workspace (or creates a new tenant for legacy registrations), enables plan features, and finalizes onboarding.</div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">Convert Paid Registration to Tenant</button>
                                    </form>
                                <?php elseif ($registrationStatus === 'converted'): ?>
                                    <div class="alert alert-success mb-3" role="alert">
                                        <div class="d-flex">
                                            <div><i class="ti ti-circle-check icon alert-icon"></i></div>
                                            <div><strong>Tenant Conversion Complete</strong><br>This registration has already been converted into a live tenant account.</div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($canUpdateBillingStatus): ?>
                                    <form action="actions/update_billing_status.php" method="POST" class="mb-3">
                                        <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                        <input type="hidden" name="billing_request_id" value="<?= (int) $selectedBillingRequest['billing_request_id'] ?>">
                                        <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="row g-2 mb-2">
                                            <div class="col-sm-6">
                                                <label class="form-label" for="billing_status">Billing Status</label>
                                                <select class="form-control" id="billing_status" name="billing_status" required>
                                                    <option value="draft"<?= ($selectedBillingRequest['billing_status'] === 'draft') ? ' selected' : '' ?>>Draft</option>
                                                    <option value="sent"<?= ($selectedBillingRequest['billing_status'] === 'sent') ? ' selected' : '' ?>>Sent</option>
                                                    <option value="paid"<?= ($selectedBillingRequest['billing_status'] === 'paid') ? ' selected' : '' ?>>Paid</option>
                                                    <option value="cancelled"<?= ($selectedBillingRequest['billing_status'] === 'cancelled') ? ' selected' : '' ?>>Cancelled</option>
                                                </select>
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label" for="payment_reference">Payment / External Reference</label>
                                                <input class="form-control" type="text" id="payment_reference" name="payment_reference" value="<?= htmlspecialchars($selectedBillingRequest['payment_reference'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">Update Billing Status</button>
                                    </form>

                                    <form action="actions/update_billing_verification.php" method="POST" class="mb-3">
                                        <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                        <input type="hidden" name="billing_request_id" value="<?= (int) $selectedBillingRequest['billing_request_id'] ?>">
                                        <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="mb-2">
                                            <label class="form-label" for="payment_reference_check_notes">Verification Notes</label>
                                            <textarea class="form-control" rows="3" id="payment_reference_check_notes" name="payment_reference_check_notes"><?= htmlspecialchars($selectedBillingRequest['payment_reference_check_notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                        </div>
                                        <div class="alert alert-info mb-2" role="alert">
                                            <div class="d-flex">
                                                <div><i class="ti ti-info-circle icon alert-icon"></i></div>
                                                <div>Review the NG reference format, confirm it is unique, and save the result into the billing record for audit tracking.</div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-secondary btn-sm">Save Reference Review</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>

                            <form action="actions/review_registration.php" method="POST">
                                <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="mb-2">
                                    <label class="form-label" for="notes">Review Notes</label>
                                    <textarea class="form-control" rows="3" id="notes" name="notes"><?= htmlspecialchars($selectedRegistration['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php if ($canApproveRegistration): ?>
                                        <button type="submit" class="btn btn-primary btn-sm" name="decision" value="approve">Approve Registration</button>
                                    <?php endif; ?>
                                    <?php if ($canMarkBillingSent): ?>
                                        <button type="submit" class="btn btn-secondary btn-sm" name="decision" value="billing_sent">Mark Billing Sent</button>
                                    <?php endif; ?>
                                    <?php if ($canRejectRegistration): ?>
                                        <button type="submit" class="btn btn-secondary btn-sm" name="decision" value="reject">Reject Registration</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>

                        <div class="card-header">
                            <h3 class="card-title">Communication Activity</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">System communication is tracked through email_logs, even when live email delivery is not configured yet.</p>
                        </div>
                        <?php if (!empty($selectedRegistrationEmailLogs)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($selectedRegistrationEmailLogs as $emailLog): ?>
                                    <?php $emailBadge = ($emailLog['send_status'] === 'sent') ? 'bg-green-lt text-green' : (($emailLog['send_status'] === 'failed') ? 'bg-red-lt text-red' : 'bg-orange-lt text-orange'); ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars($emailLog['subject'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small"><?= htmlspecialchars($emailLog['recipient_email'], ENT_QUOTES, 'UTF-8') ?> &middot; Type: <?= htmlspecialchars($emailLog['email_type'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small">Created: <?= htmlspecialchars($emailLog['created_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> &middot; Sent: <?= htmlspecialchars($emailLog['sent_at'] ?: 'Pending', ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <div class="col-auto"><span class="badge <?= $emailBadge ?>"><?= htmlspecialchars(ucfirst($emailLog['send_status']), ENT_QUOTES, 'UTF-8') ?></span></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="card-body">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No email logs</p>
                                    <p class="empty-subtitle text-muted">No email log activity has been recorded for this registration yet.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="card-body">
                            <p class="text-muted">Select a registration from the queue to review plan and add-on choices.</p>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tenants + Tenant Controls -->
            <div id="features" class="row row-deck row-cards mb-4">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-building-store me-2 text-muted"></i>Tenants</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">Filter the tenant list by status or subscription state, then open a tenant to manage controls and feature access.</p>
                        </div>
                        <div class="card-body border-top">
                            <form action="dashboard.php" method="GET">
                                <div class="row g-2 mb-2">
                                    <div class="col-12">
                                        <label class="form-label" for="tenant_search">Search Tenant</label>
                                        <input class="form-control" type="text" id="tenant_search" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Business name">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="tenant_status">Tenant Status</label>
                                        <select class="form-control" id="tenant_status" name="tenant_status">
                                            <option value="">All tenant statuses</option>
                                            <option value="active"<?= $tenantStatusFilter === 'active' ? ' selected' : '' ?>>Active</option>
                                            <option value="inactive"<?= $tenantStatusFilter === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="subscription_status">Subscription Status</label>
                                        <select class="form-control" id="subscription_status" name="subscription_status">
                                            <option value="">All subscription statuses</option>
                                            <option value="active"<?= $subscriptionStatusFilter === 'active' ? ' selected' : '' ?>>Active</option>
                                            <option value="expired"<?= $subscriptionStatusFilter === 'expired' ? ' selected' : '' ?>>Expired</option>
                                            <option value="none"<?= $subscriptionStatusFilter === 'none' ? ' selected' : '' ?>>No subscription</option>
                                        </select>
                                    </div>
                                    <div class="col-12 d-flex gap-2">
                                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                                        <a href="dashboard.php#features" class="btn btn-secondary btn-sm">Reset</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <?php if (!empty($tenants)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($tenants as $tenant): ?>
                                    <?php
                                    $isSelected = (int) $tenant['tenant_id'] === $selectedTenantId;
                                    $tenantHealthBadge = match($tenant['health_class'] ?? '') {
                                        'status-active' => 'bg-green-lt text-green',
                                        'status-inactive' => 'bg-red-lt text-red',
                                        'status-rejected' => 'bg-red-lt text-red',
                                        'status-warning' => 'bg-orange-lt text-orange',
                                        'status-pending' => 'bg-orange-lt text-orange',
                                        default => 'bg-secondary-lt',
                                    };
                                    ?>
                                    <a class="list-group-item list-group-item-action<?= $isSelected ? ' active' : '' ?>" href="dashboard.php?tenant_id=<?= (int) $tenant['tenant_id'] ?><?= $tenantSearch !== '' ? '&tenant_search=' . urlencode($tenantSearch) : '' ?><?= $tenantStatusFilter !== '' ? '&tenant_status=' . urlencode($tenantStatusFilter) : '' ?><?= $subscriptionStatusFilter !== '' ? '&subscription_status=' . urlencode($subscriptionStatusFilter) : '' ?>#features">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars($tenant['business_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small">
                                                    Status: <?= htmlspecialchars($tenant['status'], ENT_QUOTES, 'UTF-8') ?>
                                                    &middot; Plan: <?= htmlspecialchars($tenant['current_plan'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>
                                                    &middot; Sub: <?= htmlspecialchars($tenant['current_subscription_status'] ?: 'none', ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                                <div class="text-muted small"><?= htmlspecialchars($tenant['health_detail'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <div class="col-auto d-flex flex-column gap-1 align-items-end">
                                                <span class="badge <?= $tenantHealthBadge ?>"><?= htmlspecialchars($tenant['health_label'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="badge bg-secondary-lt"><?= number_format((int) $tenant['active_users']) ?> users</span>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="card-body">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No tenants</p>
                                    <p class="empty-subtitle text-muted">No tenants matched the current filters.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-settings me-2 text-muted"></i>Tenant Controls</h3>
                        </div>
                    <?php if ($selectedTenant): ?>
                        <?php
                        $tenantStatusBadge = ($selectedTenant['status'] === 'active') ? 'bg-green-lt text-green' : 'bg-red-lt text-red';
                        $tenantSubBadge = ($selectedTenant['subscription_status'] === 'active') ? 'bg-green-lt text-green' : (($selectedTenant['subscription_status'] === 'expired') ? 'bg-red-lt text-red' : 'bg-secondary-lt');
                        ?>
                        <div class="card-body pb-0">
                            <p class="text-muted small">
                                Editing <strong><?= htmlspecialchars($selectedTenant['business_name'], ENT_QUOTES, 'UTF-8') ?></strong>.
                                Current plan: <?= htmlspecialchars($selectedTenant['plan'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>.
                            </p>
                        </div>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col"><div class="font-weight-medium">Tenant Status</div></div>
                                    <div class="col-auto"><span class="badge <?= $tenantStatusBadge ?>"><?= htmlspecialchars(ucfirst($selectedTenant['status']), ENT_QUOTES, 'UTF-8') ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Active Tenant Users</div>
                                        <div class="text-muted small">Accounts currently allowed to sign in under this tenant.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt"><?= number_format((int) ($selectedTenant['active_users'] ?? 0)) ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Subscription Window</div>
                                        <div class="text-muted small"><?= htmlspecialchars($selectedTenant['start_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($selectedTenant['end_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge <?= $tenantSubBadge ?>"><?= htmlspecialchars(ucfirst($selectedTenant['subscription_status'] ?: 'none'), ENT_QUOTES, 'UTF-8') ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Attention State</div>
                                        <div class="text-muted small"><?= htmlspecialchars($selectedTenant['health_detail'] ?? 'No subscription health details available.', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge <?= $tenantHealthBadge ?? 'bg-secondary-lt' ?>"><?= htmlspecialchars($selectedTenant['health_label'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Tenant Created</div>
                                        <div class="text-muted small"><?= htmlspecialchars($selectedTenant['created_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt">Live</span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Access Mode</div>
                                        <div class="text-muted small"><?= ($selectedTenant['access_mode'] ?? 'full_access') === 'read_only' ? 'Read-only workspace with editing disabled.' : 'Full workspace access is active.' ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <span class="badge <?= ($selectedTenant['access_mode'] ?? 'full_access') === 'read_only' ? 'bg-orange-lt text-orange' : 'bg-green-lt text-green' ?>">
                                            <?= ($selectedTenant['access_mode'] ?? 'full_access') === 'read_only' ? 'Read-Only' : 'Full Access' ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card-body">
                        <div class="row row-deck row-cards">
                            <div class="col-12">
                              <div class="card">
                                <div class="card-header"><h3 class="card-title">Tenant Lifecycle</h3></div>
                                <div class="card-body pb-0">
                                    <p class="text-muted small">View how this live tenant moved from registration through billing and conversion.</p>
                                </div>
                                <?php if ($selectedTenantRegistration): ?>
                                    <?php $lifecycleBadge = $regStatusBadge[$selectedTenantRegistration['registration_status']] ?? 'bg-secondary-lt'; ?>
                                    <div class="list-group list-group-flush">
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <div class="font-weight-medium">Source Registration</div>
                                                    <div class="text-muted small">#<?= (int) $selectedTenantRegistration['registration_id'] ?> &middot; <?= htmlspecialchars($selectedTenantRegistration['owner_full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <div class="col-auto"><span class="badge <?= $lifecycleBadge ?>"><?= htmlspecialchars(ucfirst($selectedTenantRegistration['registration_status']), ENT_QUOTES, 'UTF-8') ?></span></div>
                                            </div>
                                        </div>
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <div class="font-weight-medium">Plan and Billing Cycle</div>
                                                    <div class="text-muted small"><?= htmlspecialchars($selectedTenantRegistration['plan_name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars(ucfirst($selectedTenantRegistration['billing_cycle']), ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <div class="col-auto"><span class="badge bg-secondary-lt">Registration</span></div>
                                            </div>
                                        </div>
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <div class="font-weight-medium">Registration Timeline</div>
                                                    <div class="text-muted small">Submitted: <?= htmlspecialchars($selectedTenantRegistration['created_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?><br>Reviewed / Converted: <?= htmlspecialchars($selectedTenantRegistration['reviewed_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <div class="col-auto"><span class="badge bg-secondary-lt">Timeline</span></div>
                                            </div>
                                        </div>
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <div class="font-weight-medium">Current Subscription State</div>
                                                    <div class="text-muted small"><?= htmlspecialchars($selectedTenant['plan'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($selectedTenant['start_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($selectedTenant['end_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <div class="col-auto"><span class="badge <?= $tenantSubBadge ?>"><?= htmlspecialchars(ucfirst($selectedTenant['subscription_status'] ?: 'none'), ENT_QUOTES, 'UTF-8') ?></span></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <a class="btn btn-secondary btn-sm" href="dashboard.php?registration_id=<?= (int) $selectedTenantRegistration['registration_id'] ?>&tenant_id=<?= (int) $selectedTenant['tenant_id'] ?><?= $registrationSearch !== '' ? '&registration_search=' . urlencode($registrationSearch) : '' ?><?= $registrationStatusFilter !== '' ? '&registration_status=' . urlencode($registrationStatusFilter) : '' ?><?= $registrationBillingStatusFilter !== '' ? '&registration_billing_status=' . urlencode($registrationBillingStatusFilter) : '' ?><?= $tenantSearch !== '' ? '&tenant_search=' . urlencode($tenantSearch) : '' ?><?= $tenantStatusFilter !== '' ? '&tenant_status=' . urlencode($tenantStatusFilter) : '' ?><?= $subscriptionStatusFilter !== '' ? '&subscription_status=' . urlencode($subscriptionStatusFilter) : '' ?>#registrations">Open Registration Record</a>
                                    </div>

                                    <div class="card-header"><h3 class="card-title">Final Enabled Features</h3></div>
                                    <div class="card-body pb-0">
                                        <p class="text-muted small">These are the modules currently enabled for this live tenant after plan defaults, requested add-ons, and any later super admin adjustments.</p>
                                    </div>
                                    <div class="list-group list-group-flush">
                                        <?php if (!empty($selectedTenantEnabledFeatures)): ?>
                                            <?php foreach ($selectedTenantEnabledFeatures as $feature): ?>
                                                <div class="list-group-item">
                                                    <div class="row align-items-center">
                                                        <div class="col">
                                                            <div class="font-weight-medium"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $feature['feature_name'])), ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="text-muted small"><?= htmlspecialchars($feature['description'] ?: 'Enabled for this tenant.', ENT_QUOTES, 'UTF-8') ?></div>
                                                        </div>
                                                        <div class="col-auto"><span class="badge bg-green-lt text-green">Enabled</span></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="list-group-item text-muted small">No enabled features are currently recorded for this tenant.</div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($selectedTenantBillingHistory)): ?>
                                        <div class="card-header"><h3 class="card-title">Billing History</h3></div>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($selectedTenantBillingHistory as $billingHistory): ?>
                                                <?php
                                                $billHistBadge = match($billingHistory['billing_status']) {
                                                    'paid' => 'bg-teal-lt text-teal',
                                                    'sent' => 'bg-blue-lt text-blue',
                                                    'draft' => 'bg-secondary-lt',
                                                    'cancelled' => 'bg-red-lt text-red',
                                                    default => 'bg-secondary-lt',
                                                };
                                                ?>
                                                <div class="list-group-item">
                                                    <div class="row align-items-center">
                                                        <div class="col">
                                                            <div class="font-weight-medium">Billing Request #<?= (int) $billingHistory['billing_request_id'] ?></div>
                                                            <div class="text-muted small"><?= htmlspecialchars($billingHistory['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $billingHistory['total_amount'], 2) ?> &middot; Due: <?= htmlspecialchars($billingHistory['due_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> &middot; Paid: <?= htmlspecialchars($billingHistory['paid_at'] ?: 'Not paid yet', ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="text-muted small">Ref: <?= htmlspecialchars($billingHistory['payment_reference'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> &middot; PayMongo: <?= htmlspecialchars(ucfirst($billingHistory['paymongo_status'] ?: 'none'), ENT_QUOTES, 'UTF-8') ?></div>
                                                        </div>
                                                        <div class="col-auto"><span class="badge <?= $billHistBadge ?>"><?= htmlspecialchars(ucfirst($billingHistory['billing_status']), ENT_QUOTES, 'UTF-8') ?></span></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="card-body">
                                            <div class="empty empty-sm">
                                                <p class="empty-title">No billing history</p>
                                                <p class="empty-subtitle text-muted">No billing requests were found for the linked registration.</p>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="card-body">
                                        <div class="alert alert-info mb-0" role="alert">
                                            <div class="d-flex">
                                                <div><i class="ti ti-notes icon alert-icon"></i></div>
                                                <div><strong>Conversion Notes</strong><br><?= nl2br(htmlspecialchars($selectedTenantRegistration['notes'] ?: 'No conversion notes recorded.', ENT_QUOTES, 'UTF-8')) ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="card-header"><h3 class="card-title">Communication Activity</h3></div>
                                    <div class="card-body pb-0">
                                        <p class="text-muted small">Recent communication logs linked to this tenant lifecycle record.</p>
                                    </div>
                                    <?php if (!empty($selectedTenantEmailLogs)): ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($selectedTenantEmailLogs as $emailLog): ?>
                                                <?php $tenantEmailBadge = ($emailLog['send_status'] === 'sent') ? 'bg-green-lt text-green' : (($emailLog['send_status'] === 'failed') ? 'bg-red-lt text-red' : 'bg-orange-lt text-orange'); ?>
                                                <div class="list-group-item">
                                                    <div class="row align-items-center">
                                                        <div class="col">
                                                            <div class="font-weight-medium"><?= htmlspecialchars($emailLog['subject'], ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="text-muted small"><?= htmlspecialchars($emailLog['recipient_email'], ENT_QUOTES, 'UTF-8') ?> &middot; Type: <?= htmlspecialchars($emailLog['email_type'], ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="text-muted small">Created: <?= htmlspecialchars($emailLog['created_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> &middot; Sent: <?= htmlspecialchars($emailLog['sent_at'] ?: 'Pending', ENT_QUOTES, 'UTF-8') ?></div>
                                                        </div>
                                                        <div class="col-auto"><span class="badge <?= $tenantEmailBadge ?>"><?= htmlspecialchars(ucfirst($emailLog['send_status']), ENT_QUOTES, 'UTF-8') ?></span></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="card-body">
                                            <div class="empty empty-sm">
                                                <p class="empty-title">No email logs</p>
                                                <p class="empty-subtitle text-muted">No email log entries were found for this tenant lifecycle yet.</p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="card-body">
                                        <div class="alert alert-warning mb-0" role="alert">
                                            <div class="d-flex">
                                                <div><i class="ti ti-alert-triangle icon alert-icon"></i></div>
                                                <div>This tenant is live, but the dashboard could not find a linked source registration automatically. Review the conversion notes and tenant details before making lifecycle changes.</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                              </div>
                            </div>

                            <!-- Tenant Status control -->
                            <div class="col-12">
                              <div class="card">
                                <div class="card-header"><h3 class="card-title">Tenant Status</h3></div>
                                <div class="card-body pb-0">
                                    <p class="text-muted small">Control whether this tenant can access the platform. Tenant login already blocks inactive tenants.</p>
                                </div>
                                <div class="card-body">
                                    <form action="actions/update_tenant_status.php" method="POST">
                                        <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                        <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="mb-2">
                                            <label class="form-label" for="tenant_status_control">Tenant Status</label>
                                            <select class="form-control" id="tenant_status_control" name="status" required>
                                                <option value="active"<?= ($selectedTenant['status'] === 'active') ? ' selected' : '' ?>>Active</option>
                                                <option value="inactive"<?= ($selectedTenant['status'] === 'inactive') ? ' selected' : '' ?>>Inactive</option>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">Save Tenant Status</button>
                                    </form>
                                </div>
                              </div>
                            </div>

                            <!-- Subscription Status control -->
                            <div class="col-12">
                              <div class="card">
                                <div class="card-header"><h3 class="card-title">Subscription Status</h3></div>
                                <div class="card-body pb-0">
                                    <p class="text-muted small">Update the latest subscription state using the current schema-supported values.</p>
                                </div>
                                <div class="card-body">
                                    <form action="actions/update_tenant_access_mode.php" method="POST" class="mb-3">
                                        <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                        <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <div>
                                                <div class="font-weight-medium">Read-Only Downgrade</div>
                                                <div class="text-muted small">Switch this tenant to read-only when they need access to records without operational editing.</div>
                                            </div>
                                            <span class="badge <?= ($selectedTenant['access_mode'] ?? 'full_access') === 'read_only' ? 'bg-orange-lt text-orange' : 'bg-green-lt text-green' ?>">
                                                <?= ($selectedTenant['access_mode'] ?? 'full_access') === 'read_only' ? 'Read-Only' : 'Full Access' ?>
                                            </span>
                                        </div>
                                        <?php if (($selectedTenant['access_mode'] ?? 'full_access') === 'read_only'): ?>
                                            <button type="submit" name="access_mode" value="full_access" class="btn btn-secondary btn-sm">Restore Full Access</button>
                                        <?php else: ?>
                                            <button type="submit" name="access_mode" value="read_only" class="btn btn-secondary btn-sm">Downgrade To Read-Only</button>
                                        <?php endif; ?>
                                    </form>

                                    <?php if (!empty($selectedTenant['subscription_status'])): ?>
                                        <form action="actions/update_subscription_status.php" method="POST" class="mb-3">
                                            <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                            <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="mb-2">
                                                <label class="form-label" for="subscription_status_control">Subscription Status</label>
                                                <select class="form-control" id="subscription_status_control" name="status" required>
                                                    <option value="active"<?= ($selectedTenant['subscription_status'] === 'active') ? ' selected' : '' ?>>Active</option>
                                                    <option value="expired"<?= ($selectedTenant['subscription_status'] === 'expired') ? ' selected' : '' ?>>Expired</option>
                                                </select>
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm">Save Subscription Status</button>
                                        </form>

                                        <form action="actions/update_subscription_window.php" method="POST" class="mb-3">
                                            <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                            <input type="hidden" name="action_type" value="save_dates">
                                            <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="row g-2 mb-2">
                                                <div class="col-sm-6">
                                                    <label class="form-label" for="subscription_start_date">Start Date</label>
                                                    <input class="form-control" type="date" id="subscription_start_date" name="start_date" value="<?= htmlspecialchars($selectedTenant['start_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                                <div class="col-sm-6">
                                                    <label class="form-label" for="subscription_end_date">End Date</label>
                                                    <input class="form-control" type="date" id="subscription_end_date" name="end_date" value="<?= htmlspecialchars($selectedTenant['end_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-secondary btn-sm">Save Subscription Window</button>
                                        </form>

                                        <form action="actions/update_subscription_window.php" method="POST">
                                            <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                            <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="mb-2">
                                                <div class="font-weight-medium">Quick Renewal</div>
                                                <div class="text-muted small">Extend the latest subscription window from its current end date, or from today if already elapsed.</div>
                                            </div>
                                            <div class="d-flex gap-2">
                                                <button type="submit" name="action_type" value="extend_month" class="btn btn-secondary btn-sm">Extend 1 Month</button>
                                                <button type="submit" name="action_type" value="extend_year" class="btn btn-primary btn-sm">Extend 1 Year</button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <div class="alert alert-warning mb-0" role="alert">
                                            <div class="d-flex">
                                                <div><i class="ti ti-info-circle icon alert-icon"></i></div>
                                                <div>This tenant does not have a subscription record yet.</div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                              </div>
                            </div>

                        </div>
                        </div>

                        <div class="card-header">
                            <h3 class="card-title">Feature Toggles</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">Plan-included and requested add-on features are auto-applied during tenant conversion. You can fine-tune them here afterward.</p>
                        </div>
                        <div class="card-body">
                        <form action="actions/save_tenant_features.php" method="POST">
                            <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                            <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="row g-2 mb-3">
                                <?php foreach ($features as $feature): ?>
                                    <div class="col-sm-6">
                                        <label class="form-check">
                                            <input
                                                type="checkbox"
                                                class="form-check-input"
                                                name="features[]"
                                                value="<?= (int) $feature['feature_id'] ?>"
                                                <?= !empty($featureStates[(int) $feature['feature_id']]) ? 'checked' : '' ?>
                                            >
                                            <span class="form-check-label">
                                                <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $feature['feature_name'])), ENT_QUOTES, 'UTF-8') ?></strong><br>
                                                <span class="text-muted small"><?= htmlspecialchars($feature['description'] ?: 'No description provided.', ENT_QUOTES, 'UTF-8') ?></span>
                                            </span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Save Tenant Features</button>
                        </form>
                        </div>
                    <?php else: ?>
                        <div class="card-body">
                            <p class="text-muted">Select a tenant from the left to manage feature access.</p>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/js/tabler.min.js"></script>
<script src="../assets/js/theme.js?v=2"></script>
</body>
</html>
