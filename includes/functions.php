<?php
function redirect($path) {
    header("Location: " . $path);
    exit;
}

function sanitize($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function buildPageUrl($path, array $params = []) {
    $filteredParams = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filteredParams[$key] = $value;
    }

    return $path . (!empty($filteredParams) ? '?' . http_build_query($filteredParams) : '');
}

function formatVehicleLabel(array $record, $includeYear = true, $includePlate = false) {
    $label = trim(($record['make'] ?? '') . ' ' . ($record['model'] ?? ''));

    if ($label === '') {
        $label = 'Vehicle record';
    }

    if ($includeYear && !empty($record['year_model'])) {
        $label .= ' (' . $record['year_model'] . ')';
    }

    if ($includePlate && !empty($record['plate'])) {
        $label .= ' - ' . $record['plate'];
    }

    return $label;
}

function getTenantSubscriptionNotice(?string $plan, ?string $subscriptionStatus, ?string $startDate, ?string $endDate): array
{
    $planLabel = $plan ?: 'No plan assigned';

    if (empty($subscriptionStatus)) {
        return [
            'label' => 'No Subscription',
            'class' => 'bg-secondary-lt',
            'summary' => $planLabel,
            'detail' => 'No subscription record is available yet. Please contact your platform administrator.',
        ];
    }

    $windowLabel = ($startDate ?: 'Not set') . ' to ' . ($endDate ?: 'Not set');

    if ($subscriptionStatus === 'expired') {
        return [
            'label' => 'Late',
            'class' => 'bg-red-lt text-red',
            'summary' => $planLabel . ' | ' . $windowLabel,
            'detail' => 'Your subscription is already marked as expired.',
        ];
    }

    if (!empty($endDate)) {
        $today = new DateTimeImmutable(date('Y-m-d'));
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);

        if ($end instanceof DateTimeImmutable) {
            $daysRemaining = (int) $today->diff($end)->format('%r%a');

            if ($daysRemaining < 0) {
                return [
                    'label' => 'Late',
                    'class' => 'bg-red-lt text-red',
                    'summary' => $planLabel . ' | ' . $windowLabel,
                    'detail' => 'Your subscription ended ' . abs($daysRemaining) . ' day' . (abs($daysRemaining) === 1 ? '' : 's') . ' ago.',
                ];
            }

            if ($daysRemaining === 0) {
                return [
                    'label' => 'Due Today',
                    'class' => 'bg-orange-lt text-orange',
                    'summary' => $planLabel . ' | ' . $windowLabel,
                    'detail' => 'Your subscription ends today.',
                ];
            }

            if ($daysRemaining <= 7) {
                return [
                    'label' => 'Due Soon',
                    'class' => 'bg-orange-lt text-orange',
                    'summary' => $planLabel . ' | ' . $windowLabel,
                    'detail' => $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's') . ' left before renewal is due.',
                ];
            }

            return [
                'label' => 'Active',
                'class' => 'bg-green-lt text-green',
                'summary' => $planLabel . ' | ' . $windowLabel,
                'detail' => $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's') . ' left in your current subscription.',
            ];
        }
    }

    return [
        'label' => 'Active',
        'class' => 'bg-green-lt text-green',
        'summary' => $planLabel . ' | ' . $windowLabel,
        'detail' => 'Your subscription is currently active.',
    ];
}

function renderContextStrip(array $links, $currentLabel = null, array $actions = []) {
    if (empty($links) && $currentLabel === null && empty($actions)) {
        return '';
    }

    $html = '<ol class="breadcrumb breadcrumb-arrows" aria-label="breadcrumbs">';
    $segments = [];

    foreach ($links as $link) {
        if (empty($link['label'])) {
            continue;
        }

        $label = htmlspecialchars((string) $link['label'], ENT_QUOTES, 'UTF-8');
        $href = $link['href'] ?? '';

        if ($href !== '') {
            $segments[] = '<li class="breadcrumb-item"><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a></li>';
        } else {
            $segments[] = '<li class="breadcrumb-item active" aria-current="page">' . $label . '</li>';
        }
    }

    if ($currentLabel !== null && $currentLabel !== '') {
        $segments[] = '<li class="breadcrumb-item active" aria-current="page">' . htmlspecialchars((string) $currentLabel, ENT_QUOTES, 'UTF-8') . '</li>';
    }

    foreach ($actions as $action) {
        if (empty($action['label']) || empty($action['href'])) {
            continue;
        }

        $segments[] = '<li class="breadcrumb-item"><a href="' . htmlspecialchars((string) $action['href'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $action['label'], ENT_QUOTES, 'UTF-8') . '</a></li>';
    }

    $html .= implode('', $segments);
    $html .= '</ol>';

    return $html;
}

function getTenantAdminModuleLinks() {
    return [
        ['label' => 'Staff Management', 'feature' => null,              'hint' => 'Live', 'href' => 'staff.php',        'roles' => ['admin'],            'icon' => 'ti-users'],
        ['label' => 'Customers',        'feature' => 'customer_module', 'hint' => 'Live', 'href' => 'customers.php',    'roles' => ['admin'],            'icon' => 'ti-user-circle'],
        ['label' => 'Vehicles',         'feature' => 'customer_module', 'hint' => 'Live', 'href' => 'vehicles.php',     'roles' => ['admin'],            'icon' => 'ti-car'],
        ['label' => 'Appointments',     'feature' => 'appointments',    'hint' => 'Live', 'href' => 'appointments.php', 'roles' => ['admin'],            'icon' => 'ti-calendar'],
        ['label' => 'Jobs',             'feature' => 'jobs',            'hint' => 'Live', 'href' => 'jobs.php',         'roles' => ['admin'],            'icon' => 'ti-tool'],
        ['label' => 'Inventory',        'feature' => 'inventory',       'hint' => 'Live', 'href' => 'inventory.php',    'roles' => ['admin'],            'icon' => 'ti-package'],
        ['label' => 'Invoices',         'feature' => 'invoicing',       'hint' => 'Live', 'href' => 'invoices.php',     'roles' => ['admin', 'cashier'], 'icon' => 'ti-file-invoice'],
        ['label' => 'Payments',         'feature' => 'payments',        'hint' => 'Live', 'href' => 'payments.php',     'roles' => ['admin', 'cashier'], 'icon' => 'ti-credit-card'],
    ];
}

function getVisibleTenantAdminModuleLinks($tenantId, $role = null) {
    $moduleLinks = getTenantAdminModuleLinks();
    $role = $role ?: ($_SESSION['role'] ?? '');

    return array_values(array_filter($moduleLinks, function ($module) use ($tenantId, $role) {
        $moduleRoles = $module['roles'] ?? ['admin'];
        $roleAllowed = in_array((string) $role, $moduleRoles, true);
        $featureAllowed = $module['feature'] === null || tenantHasFeature($module['feature'], $tenantId);

        return $roleAllowed && $featureAllowed;
    }));
}

function renderTenantAdminFooterScripts() {
    return '<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/js/tabler.min.js"></script>';
}

function renderTenantAdminSidebar($businessName, array $visibleModuleLinks, $activeHref, $showAnalytics, $logoutHref = '../logout.php') {
    $currentRole = $_SESSION['role'] ?? 'admin';
    $dashboardHref = $currentRole === 'cashier' ? 'cashier_dashboard.php' : 'dashboard.php';
    $safeBusinessName = htmlspecialchars((string) $businessName, ENT_QUOTES, 'UTF-8');

    ob_start();
    ?>
    <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <h1 class="navbar-brand navbar-brand-autodark">
                <a href="<?= htmlspecialchars($dashboardHref, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="fw-bold">MECHANIX</span>
                </a>
            </h1>
            <div class="collapse navbar-collapse" id="sidebar-menu">
                <div class="navbar-nav flex-column">
                    <p class="navbar-heading text-muted small text-uppercase fw-bold px-1 mt-2 mb-1"><?= $safeBusinessName ?></p>
                </div>
                <ul class="navbar-nav pt-lg-1">
                    <li class="nav-item">
                        <a class="nav-link<?= $activeHref === $dashboardHref ? ' active' : '' ?>" href="<?= htmlspecialchars($dashboardHref, ENT_QUOTES, 'UTF-8') ?>">
                            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-layout-dashboard"></i></span>
                            <span class="nav-link-title">Dashboard</span>
                        </a>
                    </li>
                    <?php if ($showAnalytics): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $activeHref === 'reports.php' ? ' active' : '' ?>" href="reports.php">
                                <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-chart-bar"></i></span>
                                <span class="nav-link-title">Analytics</span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
                <div class="navbar-nav flex-column">
                    <p class="navbar-heading text-muted small text-uppercase fw-bold px-1 mt-3 mb-1">Operations</p>
                </div>
                <ul class="navbar-nav">
                    <?php foreach ($visibleModuleLinks as $module): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $module['href'] === $activeHref ? ' active' : '' ?>" href="<?= htmlspecialchars($module['href'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti <?= htmlspecialchars($module['icon'] ?? 'ti-circle', ENT_QUOTES, 'UTF-8') ?>"></i></span>
                                <span class="nav-link-title"><?= htmlspecialchars($module['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="mt-auto p-3">
                    <a href="<?= htmlspecialchars($logoutHref, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-light w-100">
                        <i class="ti ti-logout me-2"></i>Log Out
                    </a>
                </div>
            </div>
        </div>
    </aside>
    <?php

    return ob_get_clean();
}

function renderTenantAdminTopbar($title, $description, $contextHtml = '') {
    $businessName = htmlspecialchars((string) ($_SESSION['business_name'] ?? 'Workspace'), ENT_QUOTES, 'UTF-8');

    ob_start();
    ?>
    <div class="page-header d-print-none">
        <div class="container-xl">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <div class="page-pretitle"><?= $businessName ?></div>
                    <h2 class="page-title"><?= htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') ?></h2>
                    <?php if ($description !== ''): ?>
                        <p class="text-muted mt-1 mb-0"><?= htmlspecialchars((string) $description, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <?php if ($contextHtml !== ''): ?>
                        <div class="mt-2"><?= $contextHtml ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php

    return ob_get_clean();
}

function renderTenantAccessModeNotice() {
    $accessMode = (string) ($_SESSION['access_mode'] ?? 'full_access');

    if ($accessMode !== 'read_only') {
        return '';
    }

    return '<div class="alert alert-warning" role="alert"><div class="d-flex"><div><i class="ti ti-alert-triangle icon alert-icon"></i></div><div>Read-Only plan is active for this tenant. You can review records, but create and update actions are locked.</div></div></div>';
}
?>
