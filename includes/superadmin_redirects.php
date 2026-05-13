<?php
declare(strict_types=1);

function mechanix_superadmin_context_pages(): array
{
    return [
        'dashboard' => 'dashboard.php',
        'registrations' => 'registrations.php',
        'tenant_operations' => 'tenant_operations.php',
        'tenant_status' => 'tenant_status.php',
        'plan_catalog' => 'plan_catalog.php',
        'subscriptions' => 'subscriptions.php',
    ];
}

function mechanix_superadmin_effective_context(): string
{
    $raw = isset($_POST['superadmin_context']) ? trim((string) $_POST['superadmin_context']) : '';
    $map = mechanix_superadmin_context_pages();

    return array_key_exists($raw, $map) ? $raw : 'dashboard';
}

function mechanix_superadmin_redirect_page(): string
{
    return mechanix_superadmin_context_pages()[mechanix_superadmin_effective_context()];
}

function mechanix_superadmin_registration_redirect_url(int $registrationId, int $tenantId = 0): string
{
    $ctx = mechanix_superadmin_effective_context();
    $page = mechanix_superadmin_redirect_page();
    $query = ['registration_id' => $registrationId];
    if ($tenantId > 0) {
        $query['tenant_id'] = $tenantId;
    }

    $registrationSearch = trim($_POST['registration_search'] ?? '');
    $registrationStatusFilter = trim($_POST['registration_status_filter'] ?? '');
    $registrationBillingStatusFilter = trim($_POST['registration_billing_status_filter'] ?? '');

    if ($registrationSearch !== '') {
        $query['registration_search'] = $registrationSearch;
    }
    if ($registrationStatusFilter !== '') {
        $query['registration_status'] = $registrationStatusFilter;
    }
    if ($registrationBillingStatusFilter !== '') {
        $query['registration_billing_status'] = $registrationBillingStatusFilter;
    }

    $hash = $ctx === 'dashboard' ? '#registrations' : '';

    return '../' . $page . '?' . http_build_query($query) . $hash;
}

function mechanix_superadmin_tenant_redirect_url(int $tenantId): string
{
    $ctx = mechanix_superadmin_effective_context();
    $page = mechanix_superadmin_redirect_page();
    $query = ['tenant_id' => $tenantId];

    foreach ([
        'tenant_search' => 'tenant_search',
        'tenant_status' => 'tenant_status_filter',
        'subscription_status' => 'subscription_status_filter',
    ] as $queryKey => $postKey) {
        $value = trim($_POST[$postKey] ?? '');
        if ($value !== '') {
            $query[$queryKey] = $value;
        }
    }

    $hash = $ctx === 'dashboard' ? '#features' : '';

    return '../' . $page . '?' . http_build_query($query) . $hash;
}

function mechanix_superadmin_plan_catalog_redirect_url(): string
{
    $ctx = mechanix_superadmin_effective_context();
    $page = mechanix_superadmin_redirect_page();
    $hash = $ctx === 'dashboard' ? '#catalog' : '';

    return '../' . $page . $hash;
}

function mechanix_superadmin_non_post_redirect_url(): string
{
    return '../dashboard.php';
}
