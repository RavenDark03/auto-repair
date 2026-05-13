<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mechanix_ui.php';

if (!isset($_SESSION['user_id'], $_SESSION['tenant_id'], $_SESSION['role'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (empty($_SESSION['must_change_password'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }

    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$errorMessage = $_SESSION['change_password_error'] ?? null;
$successMessage = $_SESSION['change_password_success'] ?? null;
unset($_SESSION['change_password_error'], $_SESSION['change_password_success']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - MECHANIX</title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="page-shell">
    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <div class="brand-mark">M</div>
                <div class="brand-text">
                    <h1>MECHANIX</h1>
                    <p>Subscription-based auto repair SaaS</p>
                </div>
            </div>

            <div class="nav-actions">
                <?= mechanix_theme_toggle_button() ?>
                <a href="logout.php" class="btn btn-secondary">Log Out</a>
            </div>
        </div>
    </header>

    <main class="auth-page auth-page--brand">
        <div class="auth-card">
            <h2>Create your new password</h2>
            <p>This is the first login for your tenant admin account, so the temporary password needs to be replaced before you can continue.</p>

            <?php if ($errorMessage !== null): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($successMessage !== null): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <div class="auth-help">
                Logged in as <?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Tenant Admin', ENT_QUOTES, 'UTF-8') ?>.
                Use a strong password you can keep for daily access.
            </div>

            <form action="actions/change_password_process.php" method="POST">
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input class="form-control" type="password" id="new_password" name="new_password" required minlength="8">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="8">
                </div>

                <label class="toggle-option" for="show-new-passwords">
                    <input type="checkbox" id="show-new-passwords" data-password-toggle-group data-password-targets="new_password,confirm_password">
                    <span>Show passwords</span>
                </label>

                <button type="submit" class="btn btn-primary btn-full">Save New Password</button>
            </form>
        </div>
    </main>

    <script src="assets/js/theme.js"></script>
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
