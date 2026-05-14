<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mechanix_ui.php';
require_once __DIR__ . '/../includes/superadmin_sidebar.php';

requireSuperAdmin();

$mechanixSuperadminLoadOpts = [
    'auto_select_tenant' => false,
    'auto_select_registration' => false,
];
require_once __DIR__ . '/includes/superadmin_command_data.php';

$mechanixSuperadminContext = 'plan_catalog';
$pendingCount = (int) ($summary['pending_registrations'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plan Catalog - MECHANIX</title>
    <?= mechanix_link_styles_tabler_workspace('../assets/css/') ?>
</head>
<body class="page-shell antialiased superadmin-app superadmin-platform">
<div class="dashboard">
    <?php mechanix_superadmin_render_sidebar('plan_catalog', ['pending_registrations' => $pendingCount]); ?>

    <main class="dashboard-main sa-dashboard-main" id="main-content" tabindex="-1">
        <div class="dashboard-topbar">
            <div class="dashboard-title">
                <span class="sa-eyebrow">Super Admin</span>
                <h2>Plan Catalog</h2>
                <p>Adjust subscription plan pricing and activation flags; reference add-on pricing shown to applicants.</p>
            </div>
            <div class="nav-actions"><?= mechanix_theme_toggle_button() ?></div>
        </div>

        <div class="page-body sa-superadmin-page-body">
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

                <div class="row g-3">
                    <div class="col-lg-6">
                        <?php if ($planCatalog): ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($planCatalog as $plan): ?>
                                    <div class="card sa-plan-edit-card">
                                        <div class="card-body">
                                            <form action="actions/update_plan_catalog.php" method="POST">
                                                <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="plan_id" value="<?= (int) $plan['plan_id'] ?>">
                                                <div class="mb-3">
                                                    <label class="form-label">Plan name</label>
                                                    <input class="form-control" type="text" name="plan_name" value="<?= htmlspecialchars($plan['plan_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                                <div class="row g-2 mb-3">
                                                    <div class="col">
                                                        <label class="form-label">Monthly (PHP)</label>
                                                        <input class="form-control" type="number" step="0.01" min="0" name="monthly_price" value="<?= htmlspecialchars((string) $plan['monthly_price'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                    </div>
                                                    <div class="col">
                                                        <label class="form-label">Yearly (PHP)</label>
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
                                                    <button type="submit" class="btn btn-primary btn-sm ms-auto">Save plan</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="card"><div class="card-body text-muted">No plans configured.</div></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="ti ti-puzzle me-2 text-muted"></i>Add-on catalog</h3>
                            </div>
                            <div class="card-body pb-0">
                                <p class="text-muted small">Reference pricing for optional features at signup and billing.</p>
                            </div>
                            <?php if ($addonCatalog): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($addonCatalog as $addon): ?>
                                        <div class="list-group-item">
                                            <div class="font-weight-medium"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $addon['feature_name'])), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="text-muted small">
                                                Monthly: PHP <?= number_format((float) $addon['monthly_addon_price'], 2) ?>
                                                · Yearly: PHP <?= number_format((float) $addon['yearly_addon_price'], 2) ?>
                                            </div>
                                            <div class="text-muted small"><?= htmlspecialchars($addon['description'] ?: '', ENT_QUOTES, 'UTF-8') ?></div>
                                            <span class="badge mt-1 <?= (int) $addon['is_active'] === 1 ? 'bg-green-lt text-green' : 'bg-red-lt text-red' ?>">
                                                <?= (int) $addon['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="card-body text-muted">No add-ons.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
    <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/js/tabler.min.js"></script>
    <script src="../assets/js/mechanix-modals.js?v=1"></script>
<script src="../assets/js/theme.js?v=3"></script>
<script src="../assets/js/mechanix-logout-dialog.js"></script>
</body>
</html>
