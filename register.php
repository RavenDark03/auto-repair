<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mechanix_ui.php';

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

$fieldErrors = $_SESSION['registration_field_errors'] ?? [];
unset($_SESSION['registration_field_errors']);

function registerFieldErr(array $fieldErrors, string $field): string
{
    return isset($fieldErrors[$field]) ? (string) $fieldErrors[$field] : '';
}

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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
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

    <main class="register-page">
        <div class="container register-layout">

            <!-- ── LEFT: FORM ── -->
            <div class="register-form-wrap">

                <div class="register-header">
                    <p class="eyebrow">New Business Application</p>
                    <h2>Register your repair shop</h2>
                    <p>Fill in your business details, choose a plan, and submit for super admin review (about 3 minutes). No password here—accounts are created after approval. You will receive a tenant admin username and temporary password when the super admin converts your registration; then use the <a href="login.php">Log in</a> page (username + password) and change your password if the system asks you to.</p>
                </div>

                <?php if ($successMessage !== null): ?>
                    <div class="reg-alert reg-alert-success">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($formError !== null): ?>
                    <div class="reg-alert reg-alert-error">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                        <?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form id="registration-form" action="actions/register_business.php" method="POST" novalidate>
                    <script type="application/json" id="register-old-json"><?= htmlspecialchars(json_encode($oldInput, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></script>

                    <!-- ─ Section 01: Business Profile ─ -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <span class="form-section-num">01</span>
                            <div>
                                <h3>Business Profile</h3>
                                <p>Basic information about your auto repair shop.</p>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="business_name">Business Name</label>
                                <input class="form-control" type="text" id="business_name" name="business_name"
                                    value="<?= registerValue($oldInput, 'business_name') ?>"
                                    placeholder="e.g. Santos Auto Repair" required>
                            </div>
                            <div class="form-group">
                                <label for="owner_full_name">Owner / Primary Contact</label>
                                <input class="form-control" type="text" id="owner_full_name" name="owner_full_name"
                                    value="<?= registerValue($oldInput, 'owner_full_name') ?>"
                                    placeholder="Full name" required>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input class="form-control<?= registerFieldErr($fieldErrors, 'email') !== '' ? ' is-invalid' : '' ?>"
                                    type="email" id="email" name="email"
                                    value="<?= registerValue($oldInput, 'email') ?>"
                                    placeholder="you@example.com" required autocomplete="email">
                                <?php if (registerFieldErr($fieldErrors, 'email') !== ''): ?>
                                    <p class="field-error"><?= htmlspecialchars(registerFieldErr($fieldErrors, 'email'), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="phone_suffix">Phone Number <span class="field-optional">(Philippine mobile)</span></label>
                                <input type="hidden" name="phone" id="phone"
                                    value="<?= htmlspecialchars(preg_replace('/\s+/', '', (string) ($oldInput['phone'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="phone-row">
                                    <span class="phone-prefix">+639</span>
                                    <input class="form-control" type="text" id="phone_suffix"
                                        inputmode="numeric" maxlength="9" pattern="[0-9]{9}"
                                        autocomplete="tel-national" required
                                        aria-describedby="phone-hint"
                                        placeholder="17xxxxxxx"
                                        value="<?php
                                            $p = preg_replace('/\s+/', '', (string) ($oldInput['phone'] ?? ''));
                                            echo htmlspecialchars(strpos($p, '+639') === 0 ? substr($p, 4) : '', ENT_QUOTES, 'UTF-8');
                                        ?>">
                                </div>
                                <p class="field-hint" id="phone-hint">9 digits after +639</p>
                                <?php if (registerFieldErr($fieldErrors, 'phone') !== ''): ?>
                                    <p class="field-error"><?= htmlspecialchars(registerFieldErr($fieldErrors, 'phone'), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ─ Section 02: Account Setup ─ -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <span class="form-section-num">02</span>
                            <div>
                                <h3>Account Setup</h3>
                                <p>Admin login credentials and billing preference.</p>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="preferred_username">Preferred Admin Username</label>
                                <input class="form-control" type="text" id="preferred_username" name="preferred_username"
                                    value="<?= registerValue($oldInput, 'preferred_username') ?>"
                                    placeholder="e.g. santosauto">
                                <p class="field-hint">Used for your tenant admin login.</p>
                            </div>
                            <div class="form-group">
                                <label for="billing_cycle">Billing Cycle</label>
                                <select class="form-control" id="billing_cycle" name="billing_cycle" required>
                                    <option value="monthly"<?= (($oldInput['billing_cycle'] ?? 'monthly') === 'monthly') ? ' selected' : '' ?>>Monthly</option>
                                    <option value="yearly"<?= (($oldInput['billing_cycle'] ?? '') === 'yearly') ? ' selected' : '' ?>>Yearly</option>
                                </select>
                                <p class="field-hint">Yearly billing saves compared to monthly.</p>
                            </div>
                        </div>
                    </div>

                    <!-- ─ Section 03: Business Address ─ -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <span class="form-section-num">03</span>
                            <div>
                                <h3>Business Address</h3>
                                <p>Philippine Standard Geographic Code (PSGC) location.</p>
                            </div>
                        </div>

                        <input type="hidden" name="address_region_name"   id="address_region_name"   value="<?= registerValue($oldInput, 'address_region_name') ?>">
                        <input type="hidden" name="address_province_name" id="address_province_name" value="<?= registerValue($oldInput, 'address_province_name') ?>">
                        <input type="hidden" name="address_city_name"     id="address_city_name"     value="<?= registerValue($oldInput, 'address_city_name') ?>">
                        <input type="hidden" name="address_brgy_name"     id="address_brgy_name"     value="<?= registerValue($oldInput, 'address_brgy_name') ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="address_line1">Address Line 1</label>
                                <input class="form-control<?= registerFieldErr($fieldErrors, 'address_line1') !== '' ? ' is-invalid' : '' ?>"
                                    type="text" id="address_line1" name="address_line1"
                                    maxlength="200" placeholder="Street, building, unit"
                                    value="<?= registerValue($oldInput, 'address_line1') ?>" required autocomplete="address-line1">
                                <?php if (registerFieldErr($fieldErrors, 'address_line1') !== ''): ?>
                                    <p class="field-error"><?= htmlspecialchars(registerFieldErr($fieldErrors, 'address_line1'), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="address_line2">Address Line 2 <span class="field-optional">optional</span></label>
                                <input class="form-control" type="text" id="address_line2" name="address_line2"
                                    maxlength="200" placeholder="Floor, suite, landmark"
                                    value="<?= registerValue($oldInput, 'address_line2') ?>" autocomplete="address-line2">
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="address_region_code">Region</label>
                                <select class="form-control" id="address_region_code" name="address_region_code" required>
                                    <option value="">Select region</option>
                                </select>
                                <?php if (registerFieldErr($fieldErrors, 'address_region_code') !== ''): ?>
                                    <p class="field-error"><?= htmlspecialchars(registerFieldErr($fieldErrors, 'address_region_code'), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="form-group" id="address_province_wrap" hidden>
                                <label for="address_province_code">Province</label>
                                <select class="form-control" id="address_province_code" name="address_province_code">
                                    <option value="">Select province</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="address_city_code">City / Municipality</label>
                                <select class="form-control" id="address_city_code" name="address_city_code" required disabled>
                                    <option value="">Select city</option>
                                </select>
                                <?php if (registerFieldErr($fieldErrors, 'address_city_code') !== ''): ?>
                                    <p class="field-error"><?= htmlspecialchars(registerFieldErr($fieldErrors, 'address_city_code'), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="address_brgy_code">Barangay</label>
                                <select class="form-control" id="address_brgy_code" name="address_brgy_code" required disabled>
                                    <option value="">Select barangay</option>
                                </select>
                                <?php if (registerFieldErr($fieldErrors, 'address_brgy_code') !== ''): ?>
                                    <p class="field-error"><?= htmlspecialchars(registerFieldErr($fieldErrors, 'address_brgy_code'), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ─ Section 04: Choose Your Plan ─ -->
                    <div class="form-section">
                        <div class="form-section-header">
                            <span class="form-section-num">04</span>
                            <div>
                                <h3>Choose Your Plan</h3>
                                <p>Select the subscription that fits your shop size.</p>
                            </div>
                        </div>
                        <div class="plan-cards-grid">
                            <?php foreach ($plans as $plan):
                                $isSelected = $selectedPlanId === (int) $plan['plan_id'];
                            ?>
                                <label class="plan-card<?= $isSelected ? ' is-selected' : '' ?>" for="plan_<?= (int) $plan['plan_id'] ?>">
                                    <input type="radio" id="plan_<?= (int) $plan['plan_id'] ?>"
                                        name="selected_plan_id"
                                        value="<?= (int) $plan['plan_id'] ?>"
                                        <?= $isSelected ? 'checked' : '' ?> required>
                                    <div class="plan-card-top">
                                        <span class="plan-card-name"><?= htmlspecialchars($plan['plan_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="plan-card-check" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                        </span>
                                    </div>
                                    <div class="plan-card-price">
                                        <strong><?= htmlspecialchars(registerMoney($plan['monthly_price']), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span>/mo</span>
                                    </div>
                                    <?php if (!empty($plan['yearly_price'])): ?>
                                        <p class="plan-card-yearly"><?= htmlspecialchars(registerMoney($plan['yearly_price']), ENT_QUOTES, 'UTF-8') ?>/yr</p>
                                    <?php endif; ?>
                                    <p class="plan-card-desc"><?= htmlspecialchars($plan['description'] ?: 'Subscription plan for repair businesses.', ENT_QUOTES, 'UTF-8') ?></p>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ─ Section 05: Optional Add-Ons ─ -->
                    <?php if (!empty($addons)): ?>
                    <div class="form-section">
                        <div class="form-section-header">
                            <span class="form-section-num">05</span>
                            <div>
                                <h3>Optional Add-Ons</h3>
                                <p>Extend your plan with extra modules billed separately.</p>
                            </div>
                        </div>
                        <div class="addon-toggle-list">
                            <?php foreach ($addons as $addon): ?>
                                <label class="addon-toggle-card">
                                    <div class="addon-toggle-info">
                                        <strong><?= htmlspecialchars(registerFeatureLabel($addon['feature_name']), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars($addon['description'] ?: 'Optional platform add-on.', ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="addon-toggle-right">
                                        <span class="addon-price-tag"><?= htmlspecialchars(registerMoney($addon['monthly_addon_price']), ENT_QUOTES, 'UTF-8') ?>/mo</span>
                                        <span class="switch">
                                            <input type="checkbox" name="requested_features[]"
                                                value="<?= (int) $addon['feature_id'] ?>"
                                                <?= in_array((int) $addon['feature_id'], $selectedAddons, true) ? 'checked' : '' ?>>
                                            <span class="switch-slider"></span>
                                        </span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- ─ Submit ─ -->
                    <div class="register-submit-wrap">
                        <button type="submit" class="btn btn-primary btn-full register-submit-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
                            Submit Registration
                        </button>
                        <p class="register-submit-note">Your application enters a review queue. We'll follow up via email.</p>
                    </div>

                </form>
            </div>

            <!-- ── RIGHT: SIDEBAR ── -->
            <aside class="register-sidebar">
                <div class="register-sidebar-inner">

                    <div class="sidebar-section">
                        <h3>What happens next</h3>
                        <p>Your registration enters the super admin review queue so plan choice, features, and billing can be confirmed before tenant activation.</p>
                    </div>

                    <div class="sidebar-steps">
                        <div class="sidebar-step">
                            <span class="sidebar-step-dot"></span>
                            <div>
                                <strong>Application Submitted</strong>
                                <p>Saved for super admin review with your plan and add-on choices.</p>
                            </div>
                        </div>
                        <div class="sidebar-step">
                            <span class="sidebar-step-dot"></span>
                            <div>
                                <strong>Review & Approval</strong>
                                <p>Admin checks your details and approves the tenant workspace.</p>
                            </div>
                        </div>
                        <div class="sidebar-step">
                            <span class="sidebar-step-dot"></span>
                            <div>
                                <strong>Billing Setup</strong>
                                <p>PayMongo billing instructions sent once workspace is ready.</p>
                            </div>
                        </div>
                        <div class="sidebar-step">
                            <span class="sidebar-step-dot sidebar-step-dot--last"></span>
                            <div>
                                <strong>Go Live</strong>
                                <p>Log in to your tenant dashboard and start running operations.</p>
                            </div>
                        </div>
                    </div>

                    <div class="sidebar-note">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>
                        <p>Strict tenant isolation — your data, customers, and records are never mixed with other businesses on the platform.</p>
                    </div>

                    <div class="sidebar-already-link">
                        Already registered? <a href="login.php">Log in to your workspace</a>
                    </div>

                </div>
            </aside>

        </div>
    </main>

    <script src="assets/js/theme.js?v=2"></script>
    <script src="assets/js/register-form.js"></script>
</body>
</html>
