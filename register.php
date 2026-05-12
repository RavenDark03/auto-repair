<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';

$selectedPlanId = (int) ($_GET['plan_id'] ?? 0);
$plans = [];
$addons = [];
$formError = $_SESSION['error_message'] ?? null;
$oldInput = $_SESSION['registration_old_input'] ?? [];
$successMessage = $_SESSION['registration_success'] ?? null;
unset($_SESSION['registration_old_input'], $_SESSION['registration_success'], $_SESSION['error_message']);

try {
    $pdo = Database::getInstance();

    $plans = $pdo->query(" 
        SELECT plan_id, plan_name, monthly_price, yearly_price, description
        FROM subscription_plans
        WHERE is_active = 1
          AND plan_name <> 'Read-Only'
        ORDER BY monthly_price ASC, plan_name ASC
    ")->fetchAll();

    $addons = $pdo->query(" 
        SELECT
            f.feature_id,
            f.feature_name,
            f.description,
            fp.monthly_addon_price,
            fp.yearly_addon_price
        FROM feature_pricing fp
        INNER JOIN features f ON f.feature_id = fp.feature_id
        WHERE fp.is_active = 1
        ORDER BY fp.monthly_addon_price ASC, f.feature_name ASC
    ")->fetchAll();
} catch (PDOException $e) {
    $formError = 'Registration options could not be loaded: ' . $e->getMessage();
}

if ($selectedPlanId <= 0 && !empty($oldInput['selected_plan_id'])) {
    $selectedPlanId = (int) $oldInput['selected_plan_id'];
}

$selectedAddons = array_map('intval', $oldInput['requested_features'] ?? []);

function registerValue($oldInput, $key) {
    return htmlspecialchars($oldInput[$key] ?? '', ENT_QUOTES, 'UTF-8');
}

function registerMoney($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function registerFeatureLabel($featureName) {
    return ucwords(str_replace('_', ' ', $featureName));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Business - MECHANIX</title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="page-shell">
    <div class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <div class="brand-mark">M</div>
                <div class="brand-text">
                    <h1>MECHANIX</h1>
                    <p>Business registration</p>
                </div>
            </div>

            <div class="nav-actions">
                <button type="button" class="theme-toggle" data-theme-toggle>Dark Mode</button>
                <a href="index.php" class="btn btn-secondary">Back to Landing</a>
            </div>
        </div>
    </div>

    <main class="section-block">
        <div class="container registration-layout">
            <article class="content-card registration-panel">
                <div class="section-heading">
                    <p class="eyebrow">Register</p>
                    <h2>Apply your repair business to the platform.</h2>
                    <p>Choose a subscription plan, request optional features, and send your application to the super admin for review.</p>
                </div>

                <?php if ($successMessage !== null): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($formError !== null): ?>
                    <div class="alert alert-error">
                        <?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form action="actions/register_business.php" method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="business_name">Business Name</label>
                            <input class="form-control" type="text" id="business_name" name="business_name" value="<?= registerValue($oldInput, 'business_name') ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="owner_full_name">Owner / Primary Contact</label>
                            <input class="form-control" type="text" id="owner_full_name" name="owner_full_name" value="<?= registerValue($oldInput, 'owner_full_name') ?>" required>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input class="form-control" type="email" id="email" name="email" value="<?= registerValue($oldInput, 'email') ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input class="form-control" type="text" id="phone" name="phone" value="<?= registerValue($oldInput, 'phone') ?>">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="preferred_username">Preferred Admin Username</label>
                            <input class="form-control" type="text" id="preferred_username" name="preferred_username" value="<?= registerValue($oldInput, 'preferred_username') ?>">
                        </div>

                        <div class="form-group">
                            <label for="billing_cycle">Billing Cycle</label>
                            <select class="form-control" id="billing_cycle" name="billing_cycle" required>
                                <option value="monthly"<?= (($oldInput['billing_cycle'] ?? 'monthly') === 'monthly') ? ' selected' : '' ?>>Monthly</option>
                                <option value="yearly"<?= (($oldInput['billing_cycle'] ?? '') === 'yearly') ? ' selected' : '' ?>>Yearly</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="address">Business Address</label>
                        <textarea class="form-control form-textarea" id="address" name="address"><?= registerValue($oldInput, 'address') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="selected_plan_id">Subscription Plan</label>
                        <select class="form-control" id="selected_plan_id" name="selected_plan_id" required>
                            <option value="">Select a plan</option>
                            <?php foreach ($plans as $plan): ?>
                                <option value="<?= (int) $plan['plan_id'] ?>"<?= $selectedPlanId === (int) $plan['plan_id'] ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($plan['plan_name'], ENT_QUOTES, 'UTF-8') ?> -
                                    <?= htmlspecialchars(registerMoney($plan['monthly_price']), ENT_QUOTES, 'UTF-8') ?>/mo
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Optional Add-On Features</label>
                        <div class="feature-toggle-grid">
                            <?php foreach ($addons as $addon): ?>
                                <label class="feature-toggle-card">
                                    <span class="feature-toggle-copy">
                                        <strong><?= htmlspecialchars(registerFeatureLabel($addon['feature_name']), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span>
                                            <?= htmlspecialchars($addon['description'] ?: 'Optional platform add-on.', ENT_QUOTES, 'UTF-8') ?> |
                                            <?= htmlspecialchars(registerMoney($addon['monthly_addon_price']), ENT_QUOTES, 'UTF-8') ?>/mo
                                        </span>
                                    </span>
                                    <span class="switch">
                                        <input
                                            type="checkbox"
                                            name="requested_features[]"
                                            value="<?= (int) $addon['feature_id'] ?>"
                                            <?= in_array((int) $addon['feature_id'], $selectedAddons, true) ? 'checked' : '' ?>
                                        >
                                        <span class="switch-slider"></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">Submit Registration</button>
                </form>
            </article>

            <aside class="content-card registration-side">
                <h3>What happens next</h3>
                <p>Your registration enters the super admin review queue so plan choice, requested features, and future billing can be checked before tenant activation.</p>
                <div class="checklist">
                    <div class="checklist-item">Choose a plan that matches your business size</div>
                    <div class="checklist-item">Request extra features you want billed separately later</div>
                    <div class="checklist-item">Wait for approval, billing instructions, and tenant onboarding</div>
                </div>

                <div class="table-placeholder">
                    PayMongo is planned for the billing stage next, so this registration step is preparing the data we need before payment activation.
                </div>
            </aside>
        </div>
    </main>

    <script src="assets/js/theme.js"></script>
</body>
</html>
