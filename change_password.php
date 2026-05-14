<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mechanix_ui.php';

if (!isset($_SESSION['user_id'], $_SESSION['tenant_id'], $_SESSION['role'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (empty($_SESSION['must_change_password'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        try {
            $pdo = Database::getInstance();
            $st = $pdo->prepare('SELECT status FROM tenants WHERE tenant_id = :id LIMIT 1');
            $st->execute(['id' => (int) $_SESSION['tenant_id']]);
            $ts = (string) $st->fetchColumn();
            if ($ts === 'pending_payment') {
                header('Location: ' . BASE_URL . '/pending_payment.php');
                exit;
            }
        } catch (PDOException $e) {
        }
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
    <?php
    $mechanixPublicTopbarVariant = 'logout_only';
    require __DIR__ . '/includes/partials/mechanix_public_topbar.php';
    ?>

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
                    <div class="pw-input-wrap">
                        <input class="form-control" type="password" id="new_password" name="new_password" required minlength="8">
                        <button type="button" class="pw-toggle-btn" data-pw-target="new_password" aria-label="Show password">
                            <svg class="pw-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="pw-eye-off" hidden xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-8-10-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="pw-input-wrap">
                        <input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="8">
                        <button type="button" class="pw-toggle-btn" data-pw-target="confirm_password" aria-label="Show password">
                            <svg class="pw-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="pw-eye-off" hidden xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-8-10-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-full">Save New Password</button>
            </form>
        </div>
    </main>

    <script src="assets/js/theme.js?v=3"></script>
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
