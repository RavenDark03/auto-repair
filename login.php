<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/super_admin_auth.php';
require_once __DIR__ . '/includes/mechanix_ui.php';

if (isSuperAdminLoggedIn()) {
    header('Location: ' . mechanix_url_path('/superadmin/dashboard.php'));
    exit;
}

if (isset($_SESSION['user_id'])) {
    if (!empty($_SESSION['must_change_password'])) {
        header('Location: ' . mechanix_url_path('/change_password.php'));
        exit;
    }

    if ($_SESSION['role'] === 'admin') {
        header('Location: ' . mechanix_url_path('/admin/dashboard.php'));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MECHANIX</title>
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
                <?= mechanix_back_icon_link('index.php', 'Back to landing') ?>
            </div>
        </div>
    </header>

    <main class="auth-page auth-page--brand">
        <div class="auth-card">
            <div class="auth-card-eyebrow">
                <span class="eyebrow">Secure sign-in</span>
            </div>
            <h2>Welcome back</h2>
            <p>Sign in with tenant staff credentials or your platform super admin account.</p>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <?php
                    echo htmlspecialchars($_SESSION['error_message']);
                    unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>

            <form action="actions/login_process.php" method="POST">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input class="form-control" type="text" id="username" name="username" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="pw-input-wrap">
                        <input class="form-control" type="password" id="password" name="password" required>
                        <button type="button" class="pw-toggle-btn" data-pw-target="password" aria-label="Show password">
                            <svg class="pw-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="pw-eye-off" hidden xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-8-10-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-full">Login</button>
            </form>

            <div class="auth-links">
                <a href="index.php">Return to home</a>
            </div>
        </div>
    </main>

    <script src="assets/js/theme.js?v=3"></script>
</body>
</html>
