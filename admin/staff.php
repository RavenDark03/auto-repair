<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$fullName = $_SESSION['full_name'] ?? 'Admin User';
$flashMessage = $_SESSION['staff_success'] ?? null;
$errorMessage = $_SESSION['staff_error'] ?? null;
$oldInput = $_SESSION['staff_old_input'] ?? [];
unset($_SESSION['staff_success'], $_SESSION['staff_error'], $_SESSION['staff_old_input']);

$staffMembers = [];
$staffSummary = [
    'total_staff' => 0,
    'active_staff' => 0,
    'admins' => 0,
    'mechanics' => 0,
    'cashiers' => 0,
];

try {
    $pdo = Database::getInstance();

    $tenantStmt = $pdo->prepare("
        SELECT business_name
        FROM tenants
        WHERE tenant_id = :tenant_id
        LIMIT 1
    ");
    $tenantStmt->execute(['tenant_id' => $tenantId]);
    $tenantRow = $tenantStmt->fetch();

    if ($tenantRow && !empty($tenantRow['business_name'])) {
        $businessName = $tenantRow['business_name'];
        $_SESSION['business_name'] = $businessName;
    }

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_staff,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_staff,
            SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admins,
            SUM(CASE WHEN role = 'mechanic' THEN 1 ELSE 0 END) AS mechanics,
            SUM(CASE WHEN role = 'cashier' THEN 1 ELSE 0 END) AS cashiers
        FROM users
        WHERE tenant_id = :tenant_id
          AND role IN ('admin', 'cashier', 'mechanic')
    ");
    $summaryStmt->execute(['tenant_id' => $tenantId]);
    $summaryRow = $summaryStmt->fetch();

    if ($summaryRow) {
        foreach ($staffSummary as $key => $unused) {
            $staffSummary[$key] = (int) ($summaryRow[$key] ?? 0);
        }
    }

    $staffStmt = $pdo->prepare("
        SELECT
            user_id,
            full_name,
            username,
            role,
            status,
            must_change_password,
            created_at
        FROM users
        WHERE tenant_id = :tenant_id
          AND role IN ('admin', 'cashier', 'mechanic')
        ORDER BY
            FIELD(role, 'admin', 'cashier', 'mechanic'),
            full_name ASC,
            user_id ASC
    ");
    $staffStmt->execute(['tenant_id' => $tenantId]);
    $staffMembers = $staffStmt->fetchAll();
} catch (PDOException $e) {
    $errorMessage = 'Staff module could not be loaded: ' . $e->getMessage();
}

$showAnalytics = tenantHasFeature('reports', $tenantId);
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId);

function staffDate($date) {
    if (!$date) {
        return 'No date';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('M d, Y', $timestamp) : $date;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management - MECHANIX</title>
    <?= mechanix_link_styles_tabler_workspace('../assets/css/') ?>
</head>
<body class="page-shell antialiased tenant-app">
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'staff.php', $showAnalytics) ?>

        <main class="dashboard-main" id="main-content" tabindex="-1">
            <?= renderTenantAdminTopbar(
                'Staff Management',
                'Manage tenant-scoped admins, cashiers, and mechanics for ' . htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') . '.'
            ) ?>

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

            <section class="dashboard-grid">
                <article class="metric-card">
                    <span>Total Staff</span>
                    <h3><?= number_format($staffSummary['total_staff']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Active Staff</span>
                    <h3><?= number_format($staffSummary['active_staff']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Admins</span>
                    <h3><?= number_format($staffSummary['admins']) ?></h3>
                </article>
                <article class="metric-card">
                    <span>Mechanics</span>
                    <h3><?= number_format($staffSummary['mechanics']) ?></h3>
                </article>
            </section>

            <section class="content-grid staff-grid">
                <article class="content-card">
                    <h3>Staff Directory</h3>
                    <p>Every account shown here is filtered by the current tenant and ready for admin-side access control.</p>

                    <?php if (!empty($staffMembers)): ?>
                        <div class="dashboard-list">
                            <?php foreach ($staffMembers as $staff): ?>
                                <?php $isCurrentUser = (int) $staff['user_id'] === $currentUserId; ?>
                                <div class="dashboard-list-item staff-list-item">
                                    <div>
                                        <strong><?= htmlspecialchars($staff['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <p>
                                            @<?= htmlspecialchars($staff['username'], ENT_QUOTES, 'UTF-8') ?> |
                                            <?= htmlspecialchars(ucfirst($staff['role']), ENT_QUOTES, 'UTF-8') ?> |
                                            Added <?= htmlspecialchars(staffDate($staff['created_at']), ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                        <?php if ((int) $staff['must_change_password'] === 1): ?>
                                            <p class="staff-note">First login password change is still required for this account.</p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="staff-actions">
                                        <span class="status-chip status-<?= htmlspecialchars($staff['status'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(ucfirst($staff['status']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>

                                        <?php if ($isCurrentUser): ?>
                                            <span class="metric-pill">You</span>
                                        <?php else: ?>
                                            <form action="actions/update_staff_status.php" method="POST" class="inline-form">
                                                <input type="hidden" name="user_id" value="<?= (int) $staff['user_id'] ?>">
                                                <input type="hidden" name="status" value="<?= $staff['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                <button type="submit" class="btn btn-secondary">
                                                    <?= $staff['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>

                                    <div class="staff-inline-editor">
                                        <form action="actions/update_staff.php" method="POST" class="feature-toggle-form">
                                            <input type="hidden" name="user_id" value="<?= (int) $staff['user_id'] ?>">
                                            <div class="staff-editor-grid">
                                                <div class="form-group">
                                                    <label for="staff_full_name_<?= (int) $staff['user_id'] ?>">Name</label>
                                                    <input class="form-control" type="text" id="staff_full_name_<?= (int) $staff['user_id'] ?>" name="full_name" value="<?= htmlspecialchars($staff['full_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="staff_username_<?= (int) $staff['user_id'] ?>">Username</label>
                                                    <input class="form-control" type="text" id="staff_username_<?= (int) $staff['user_id'] ?>" name="username" value="<?= htmlspecialchars($staff['username'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="staff_role_<?= (int) $staff['user_id'] ?>">Role</label>
                                                    <select class="form-control" id="staff_role_<?= (int) $staff['user_id'] ?>" name="role" required<?= $isCurrentUser ? ' disabled' : '' ?>>
                                                        <?php foreach (['admin' => 'Admin', 'cashier' => 'Cashier', 'mechanic' => 'Mechanic'] as $roleValue => $roleLabel): ?>
                                                            <option value="<?= htmlspecialchars($roleValue, ENT_QUOTES, 'UTF-8') ?>"<?= $staff['role'] === $roleValue ? ' selected' : '' ?>>
                                                                <?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?php if ($isCurrentUser): ?>
                                                        <input type="hidden" name="role" value="<?= htmlspecialchars($staff['role'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="approval-actions">
                                                <button type="submit" class="btn btn-secondary btn-small">Save Details</button>
                                            </div>
                                        </form>

                                        <form action="actions/reset_staff_credentials.php" method="POST" class="feature-toggle-form">
                                            <input type="hidden" name="user_id" value="<?= (int) $staff['user_id'] ?>">
                                            <div class="staff-editor-grid">
                                                <div class="form-group">
                                                    <label for="staff_reset_username_<?= (int) $staff['user_id'] ?>">Reset Username</label>
                                                    <input class="form-control" type="text" id="staff_reset_username_<?= (int) $staff['user_id'] ?>" name="username" value="<?= htmlspecialchars($staff['username'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="staff_reset_password_<?= (int) $staff['user_id'] ?>">Temporary Password</label>
                                                    <input class="form-control" type="password" id="staff_reset_password_<?= (int) $staff['user_id'] ?>" name="password" minlength="8" required>
                                                </div>
                                                <div class="form-group">
                                                    <label for="staff_reset_confirm_<?= (int) $staff['user_id'] ?>">Confirm Password</label>
                                                    <input class="form-control" type="password" id="staff_reset_confirm_<?= (int) $staff['user_id'] ?>" name="confirm_password" minlength="8" required>
                                                </div>
                                            </div>
                                            <label class="toggle-option" for="staff_reset_must_change_<?= (int) $staff['user_id'] ?>">
                                                <input type="checkbox" id="staff_reset_must_change_<?= (int) $staff['user_id'] ?>" name="must_change_password" value="1" checked>
                                                <span>Require password change after reset</span>
                                            </label>
                                            <div class="approval-actions">
                                                <button type="submit" class="btn btn-secondary btn-small">Reset Credentials</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-placeholder">
                            No tenant staff accounts have been added yet beyond onboarding.
                        </div>
                    <?php endif; ?>
                </article>

                <article class="content-card">
                    <h3>Add Staff Member</h3>
                    <p>Create a tenant-scoped account for a cashier, mechanic, or another admin.</p>

                    <form action="actions/create_staff.php" method="POST" class="feature-toggle-form">
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input class="form-control" type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($oldInput['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input class="form-control" type="text" id="username" name="username" value="<?= htmlspecialchars($oldInput['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="role">Role</label>
                                <select class="form-control" id="role" name="role" required>
                                    <option value="cashier"<?= (($oldInput['role'] ?? '') === 'cashier') ? ' selected' : '' ?>>Cashier</option>
                                    <option value="mechanic"<?= (($oldInput['role'] ?? '') === 'mechanic') ? ' selected' : '' ?>>Mechanic</option>
                                    <option value="admin"<?= (($oldInput['role'] ?? '') === 'admin') ? ' selected' : '' ?>>Admin</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="password">Temporary Password</label>
                                <div class="pw-input-wrap">
                                    <input class="form-control" type="password" id="password" name="password" required minlength="8">
                                    <button type="button" class="pw-toggle-btn" data-pw-target="password" aria-label="Show password">
                                        <svg class="pw-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="pw-eye-off" hidden xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-8-10-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="confirm_password">Confirm Password</label>
                                <div class="pw-input-wrap">
                                    <input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="8">
                                    <button type="button" class="pw-toggle-btn" data-pw-target="confirm_password" aria-label="Show password">
                                        <svg class="pw-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="pw-eye-off" hidden xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-8-10-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <label class="toggle-option" for="must_change_password">
                            <input type="checkbox" id="must_change_password" name="must_change_password" value="1"<?= !isset($oldInput['must_change_password']) || !empty($oldInput['must_change_password']) ? ' checked' : '' ?>>
                            <span>Require password change on first login</span>
                        </label>

                        <div class="approval-actions">
                            <button type="submit" class="btn btn-primary">Create Staff Account</button>
                        </div>
                    </form>

                    <div class="table-placeholder">
                        <strong>Current tenant admin</strong><br>
                        Signed in as <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>. Staff records created here are isolated to <code>tenant_id = <?= $tenantId ?></code>.
                    </div>
                </article>
            </section>
        </main>
    </div>

    <?= renderTenantAdminFooterScripts() ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const toggle = document.querySelector('[data-password-toggle-group]');

            if (!toggle) {
                return;
            }

            const targets = (toggle.getAttribute('data-password-targets') || '')
                .split(',')
                .map(function (id) { return id.trim(); })
                .filter(Boolean)
                .map(function (id) { return document.getElementById(id); })
                .filter(Boolean);

            toggle.addEventListener('change', function () {
                targets.forEach(function (input) {
                    input.type = toggle.checked ? 'text' : 'password';
                });
            });
        });
    </script>
</body>
</html>
