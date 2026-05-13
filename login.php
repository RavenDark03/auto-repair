<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/super_admin_auth.php';
require_once __DIR__ . '/includes/mechanix_ui.php';

if (isSuperAdminLoggedIn()) {
    header('Location: ' . BASE_URL . '/superadmin/dashboard.php');
    exit;
}

if (isset($_SESSION['user_id'])) {
    if (!empty($_SESSION['must_change_password'])) {
        header('Location: ' . BASE_URL . '/change_password.php');
        exit;
    }

    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/dashboard.php");
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
                    <input class="form-control" type="password" id="password" name="password" required>
                </div>

                <label class="toggle-option" for="show-login-password">
                    <input type="checkbox" id="show-login-password" data-password-toggle data-password-target="password">
                    <span>Show password</span>
                </label>

                <button type="submit" class="btn btn-primary btn-full">Login</button>
            </form>

            <div class="auth-links">
                <a href="index.php">Return to home</a>
            </div>
        </div>
    </main>

    <script src="assets/js/theme.js"></script>
</body>
</html>
