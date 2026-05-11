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
            'class' => 'status-pending',
            'summary' => $planLabel,
            'detail' => 'No subscription record is available yet. Please contact your platform administrator.',
        ];
    }

    $windowLabel = ($startDate ?: 'Not set') . ' to ' . ($endDate ?: 'Not set');

    if ($subscriptionStatus === 'expired') {
        return [
            'label' => 'Late',
            'class' => 'status-rejected',
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
                    'class' => 'status-rejected',
                    'summary' => $planLabel . ' | ' . $windowLabel,
                    'detail' => 'Your subscription ended ' . abs($daysRemaining) . ' day' . (abs($daysRemaining) === 1 ? '' : 's') . ' ago.',
                ];
            }

            if ($daysRemaining === 0) {
                return [
                    'label' => 'Due Today',
                    'class' => 'status-warning',
                    'summary' => $planLabel . ' | ' . $windowLabel,
                    'detail' => 'Your subscription ends today.',
                ];
            }

            if ($daysRemaining <= 7) {
                return [
                    'label' => 'Due Soon',
                    'class' => 'status-warning',
                    'summary' => $planLabel . ' | ' . $windowLabel,
                    'detail' => $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's') . ' left before renewal is due.',
                ];
            }

            return [
                'label' => 'Active',
                'class' => 'status-active',
                'summary' => $planLabel . ' | ' . $windowLabel,
                'detail' => $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's') . ' left in your current subscription.',
            ];
        }
    }

    return [
        'label' => 'Active',
        'class' => 'status-active',
        'summary' => $planLabel . ' | ' . $windowLabel,
        'detail' => 'Your subscription is currently active.',
    ];
}

function renderContextStrip(array $links, $currentLabel = null, array $actions = []) {
    if (empty($links) && $currentLabel === null && empty($actions)) {
        return '';
    }

    $html = '<div class="context-strip">';
    $segments = [];

    foreach ($links as $link) {
        if (empty($link['label'])) {
            continue;
        }

        $label = htmlspecialchars((string) $link['label'], ENT_QUOTES, 'UTF-8');
        $href = $link['href'] ?? '';

        if ($href !== '') {
            $segments[] = '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="context-link">' . $label . '</a>';
        } else {
            $segments[] = '<span class="context-current">' . $label . '</span>';
        }
    }

    if ($currentLabel !== null && $currentLabel !== '') {
        $segments[] = '<span class="context-current">' . htmlspecialchars((string) $currentLabel, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    foreach ($actions as $action) {
        if (empty($action['label']) || empty($action['href'])) {
            continue;
        }

        $segments[] = '<a href="' . htmlspecialchars((string) $action['href'], ENT_QUOTES, 'UTF-8') . '" class="context-link">' . htmlspecialchars((string) $action['label'], ENT_QUOTES, 'UTF-8') . '</a>';
    }

    $html .= implode('<span class="context-separator">/</span>', $segments);
    $html .= '</div>';

    return $html;
}

function getTenantAdminModuleLinks() {
    return [
        ['label' => 'Staff Management', 'feature' => null, 'hint' => 'Live', 'href' => 'staff.php', 'roles' => ['admin']],
        ['label' => 'Customers', 'feature' => 'customer_module', 'hint' => 'Live', 'href' => 'customers.php', 'roles' => ['admin']],
        ['label' => 'Vehicles', 'feature' => 'customer_module', 'hint' => 'Live', 'href' => 'vehicles.php', 'roles' => ['admin']],
        ['label' => 'Appointments', 'feature' => 'appointments', 'hint' => 'Live', 'href' => 'appointments.php', 'roles' => ['admin']],
        ['label' => 'Jobs', 'feature' => 'jobs', 'hint' => 'Live', 'href' => 'jobs.php', 'roles' => ['admin']],
        ['label' => 'Inventory', 'feature' => 'inventory', 'hint' => 'Live', 'href' => 'inventory.php', 'roles' => ['admin']],
        ['label' => 'Customer Invoices', 'feature' => 'invoicing', 'hint' => 'Live', 'href' => 'invoices.php', 'roles' => ['admin', 'cashier']],
        ['label' => 'Customer Payments', 'feature' => 'payments', 'hint' => 'Live', 'href' => 'payments.php', 'roles' => ['admin', 'cashier']],
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

function renderTenantAdminSidebar($businessName, array $visibleModuleLinks, $activeHref, $showAnalytics, $logoutHref = '../logout.php') {
    $currentRole = $_SESSION['role'] ?? 'admin';
    $dashboardHref = $currentRole === 'cashier' ? 'cashier_dashboard.php' : 'dashboard.php';

    ob_start();
    ?>
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="brand">
                <div class="brand-mark">M</div>
                <div class="brand-text">
                    <h2>MECHANIX</h2>
                    <p><?= htmlspecialchars((string) $businessName, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
            <p class="sidebar-meta">Tenant admin workspace for daily repair operations.</p>
        </div>

        <div class="sidebar-section-title">Overview</div>
        <nav class="sidebar-menu">
            <a href="<?= htmlspecialchars($dashboardHref, ENT_QUOTES, 'UTF-8') ?>"<?= $activeHref === $dashboardHref ? ' class="active"' : '' ?>><span>Dashboard</span><span class="badge">Now</span></a>
            <?php if ($showAnalytics): ?>
                <a href="reports.php"<?= $activeHref === 'reports.php' ? ' class="active"' : '' ?>><span>Analytics</span><span class="sidebar-hint">Live</span></a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-section-title">Operations</div>
        <nav class="sidebar-menu">
            <?php foreach ($visibleModuleLinks as $module): ?>
                <a href="<?= htmlspecialchars($module['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $module['href'] === $activeHref ? ' class="active"' : '' ?>>
                    <span><?= htmlspecialchars($module['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="sidebar-hint"><?= htmlspecialchars($module['hint'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <a href="<?= htmlspecialchars($logoutHref, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-full">Log Out</a>
        </div>
    </aside>
    <?php

    return ob_get_clean();
}

function renderTenantAdminTopbar($title, $description, $contextHtml = '') {
    ob_start();
    ?>
    <div class="dashboard-topbar">
        <div class="dashboard-title">
            <h2><?= htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars((string) $description, ENT_QUOTES, 'UTF-8') ?></p>
            <?php if ($contextHtml !== ''): ?>
                <?= $contextHtml ?>
            <?php endif; ?>
        </div>

        <div class="nav-actions">
            <button type="button" class="theme-toggle" data-theme-toggle>Dark Mode</button>
        </div>
    </div>
    <?php

    return ob_get_clean();
}
?>
