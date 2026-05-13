<?php
declare(strict_types=1);

/**
 * Earnings-style platform sidebar (shared across superadmin pages).
 *
 * @param string $active One of: overview, registrations, tenant_ops, plan_catalog, tenant_status, subscriptions, earnings
 * @param array{pending_registrations?: int} $options
 */
function mechanix_superadmin_render_sidebar(string $active, array $options = []): void
{
    $pending = (int) ($options['pending_registrations'] ?? 0);
    $username = htmlspecialchars((string) ($_SESSION['super_admin_username'] ?? ''), ENT_QUOTES, 'UTF-8');

    $item = static function (string $key, string $href, string $label, string $hint, ?string $rightInnerHtml = null) use ($active): void {
        $isActive = $active === $key;
        $class = $isActive ? ' class="active"' : '';
        echo '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $class . '>';
        echo '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        if ($rightInnerHtml !== null && $rightInnerHtml !== '') {
            echo $rightInnerHtml;
        } else {
            echo '<span class="sidebar-hint">' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        echo '</a>';
    };

    ?>
    <aside class="sidebar" aria-label="Platform navigation">
        <div class="sidebar-header">
            <div class="brand">
                <div class="brand-mark">M</div>
                <div class="brand-text">
                    <h2>MECHANIX</h2>
                    <p>Platform operations</p>
                </div>
            </div>
            <p class="sidebar-meta">Logged in as <?= $username ?>.</p>
        </div>

        <div class="sidebar-section-title">Overview</div>
        <nav class="sidebar-menu" aria-label="Overview">
            <?php $item('overview', 'dashboard.php', 'Command Center', 'Home'); ?>
            <?php
            $regRight = $pending > 0
                ? '<span class="badge">' . number_format($pending) . '</span>'
                : null;
            $item('registrations', 'registrations.php', 'Registrations', 'Queue', $regRight);
            ?>
        </nav>

        <div class="sidebar-section-title">Platform</div>
        <nav class="sidebar-menu" aria-label="Platform">
            <?php $item('tenant_ops', 'tenant_operations.php', 'Tenant Operations', 'Live'); ?>
            <?php $item('plan_catalog', 'plan_catalog.php', 'Plan Catalog', 'Pricing'); ?>
        </nav>

        <div class="sidebar-section-title">Lifecycle</div>
        <nav class="sidebar-menu" aria-label="Lifecycle">
            <?php $item('tenant_status', 'tenant_status.php', 'Tenant Status', 'Directory'); ?>
            <?php $item('subscriptions', 'subscriptions.php', 'Subscriptions', 'Terms'); ?>
        </nav>

        <div class="sidebar-section-title">Reports</div>
        <nav class="sidebar-menu" aria-label="Reports">
            <?php
            $earnRight = $active === 'earnings' ? '<span class="badge">Now</span>' : null;
            $item('earnings', 'earnings.php', 'Earnings Reports', 'Revenue', $earnRight);
            ?>
        </nav>

        <div class="sidebar-footer">
            <a href="../logout.php" class="btn btn-secondary btn-full">Log Out</a>
        </div>
    </aside>
    <?php
}
