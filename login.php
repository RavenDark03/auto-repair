<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/config.php';

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
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MECHANIX</title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="page-shell">
    <div class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <div class="brand-mark">M</div>
                <div class="brand-text">
                    <h1>MECHANIX</h1>
                    <p>SaaS Auto Repair System</p>
                </div>
            </div>

            <div class="nav-actions">
                <button type="button" class="theme-toggle" data-theme-toggle>Dark Mode</button>
                <a href="index.php" class="btn btn-secondary">Back to Landing</a>
            </div>
        </div>
    </div>

    <main class="auth-page">
        <div class="auth-card">
            <h2>Welcome back</h2>
            <p>Sign in to access your tenant workspace.</p>

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
