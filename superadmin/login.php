<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/super_admin_auth.php';

if (isSuperAdminLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login - MECHANIX</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body class="page-shell">
    <div class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <div class="brand-mark">M</div>
                <div class="brand-text">
                    <h1>MECHANIX</h1>
                    <p>Platform control center</p>
                </div>
            </div>

            <div class="nav-actions">
                <button type="button" class="theme-toggle" data-theme-toggle>Dark Mode</button>
                <a href="../index.php" class="btn btn-secondary">Back to Landing</a>
            </div>
        </div>
    </div>

    <main class="auth-page">
        <div class="auth-card">
            <h2>Super admin access</h2>
            <p>Sign in to manage tenants, feature availability, and future membership tiers.</p>

            <?php if (isset($_SESSION['super_admin_error'])): ?>
                <div class="alert alert-error">
                    <?php
                    echo htmlspecialchars($_SESSION['super_admin_error']);
                    unset($_SESSION['super_admin_error']);
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

                <button type="submit" class="btn btn-primary btn-full">Login as Super Admin</button>
            </form>
        </div>
    </main>

    <script src="../assets/js/theme.js"></script>
</body>
</html>
