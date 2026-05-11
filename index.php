<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin/dashboard.php');
        exit;
    }

    header('Location: login.php');
    exit;
}

$plans = [];
$addonFeatures = [];
$landingError = null;

try {
    $pdo = Database::getInstance();

    $planRows = $pdo->query(" 
        SELECT
            sp.plan_id,
            sp.plan_name,
            sp.monthly_price,
            sp.yearly_price,
            sp.description,
            f.feature_name
        FROM subscription_plans sp
        LEFT JOIN plan_features pf
            ON pf.plan_id = sp.plan_id
           AND pf.is_included = 1
        LEFT JOIN features f
            ON f.feature_id = pf.feature_id
        WHERE sp.is_active = 1
        ORDER BY sp.monthly_price ASC, sp.plan_name ASC, f.feature_name ASC
    ")->fetchAll();

    foreach ($planRows as $row) {
        $planId = (int) $row['plan_id'];

        if (!isset($plans[$planId])) {
            $plans[$planId] = [
                'plan_id' => $planId,
                'plan_name' => $row['plan_name'],
                'monthly_price' => (float) $row['monthly_price'],
                'yearly_price' => (float) ($row['yearly_price'] ?? 0),
                'description' => $row['description'],
                'features' => [],
            ];
        }

        if (!empty($row['feature_name'])) {
            $plans[$planId]['features'][] = $row['feature_name'];
        }
    }

    $addonFeatures = $pdo->query(" 
        SELECT
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
    $landingError = 'Pricing details are temporarily unavailable: ' . $e->getMessage();
}

function landingMoney($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function landingFeatureLabel($featureName) {
    return ucwords(str_replace('_', ' ', $featureName));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MECHANIX - Multi-Tenant Auto Repair SaaS</title>
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
                <button type="button" class="theme-toggle" data-theme-toggle>Dark Mode</button>
                <a href="superadmin/login.php" class="btn btn-secondary">Super Admin</a>
                <a href="login.php" class="btn btn-secondary">Tenant Login</a>
                <a href="register.php" class="btn btn-primary">Register Business</a>
            </div>
        </div>
    </header>

    <main class="landing-main">
        <section class="hero landing-hero">
            <div class="container landing-hero-grid">
                <article class="panel hero-copy landing-copy">
                    <div class="eyebrow">Auto Repair SaaS for modern shop owners</div>
                    <h2>One platform to onboard repair businesses, run daily operations, and grow subscriptions with confidence.</h2>
                    <p class="landing-lead">
                        MECHANIX combines tenant-safe shop operations, subscription packaging, add-on selling, super admin review,
                        and billing-ready workflows in one clean SaaS experience built for local auto repair businesses.
                    </p>

                    <div class="hero-actions">
                        <a href="register.php" class="btn btn-primary">Start Registration</a>
                        <a href="#pricing" class="btn btn-secondary">View Pricing</a>
                    </div>

                    <div class="landing-proof-strip">
                        <div class="landing-proof-card">
                            <strong>Multi-Tenant Safe</strong>
                            <span>Strict tenant isolation across customers, jobs, stock, invoices, and payments.</span>
                        </div>
                        <div class="landing-proof-card">
                            <strong>Modular Growth</strong>
                            <span>Start with a plan, then unlock appointments, inventory, invoicing, reports, and more.</span>
                        </div>
                        <div class="landing-proof-card">
                            <strong>Billing Ready</strong>
                            <span>Prepared for approval, draft billing, and future PayMongo activation.</span>
                        </div>
                    </div>
                </article>

                <aside class="landing-stage">
                    <div class="landing-stage-shell">
                        <div class="stage-card stage-card-primary">
                            <div class="stage-card-head">
                                <span class="stage-pill">Live Workspace</span>
                                <strong>Shop Operations Snapshot</strong>
                            </div>
                            <div class="dashboard-mock">
                                <div class="dashboard-mock-sidebar">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                                <div class="dashboard-mock-main">
                                    <div class="dashboard-mock-top"></div>
                                    <div class="dashboard-mock-metrics">
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                    </div>
                                    <div class="dashboard-mock-table"></div>
                                </div>
                            </div>
                        </div>

                        <div class="stage-card stage-card-photo">
                            <div class="stage-photo-frame">
                                <svg viewBox="0 0 520 320" class="stage-photo-svg" aria-hidden="true">
                                    <defs>
                                        <linearGradient id="shopBg" x1="0%" y1="0%" x2="100%" y2="100%">
                                            <stop offset="0%" stop-color="#f1f1f1" />
                                            <stop offset="100%" stop-color="#d9d9d9" />
                                        </linearGradient>
                                        <linearGradient id="carBody" x1="0%" y1="0%" x2="100%" y2="0%">
                                            <stop offset="0%" stop-color="#0f0f0f" />
                                            <stop offset="100%" stop-color="#3a3a3a" />
                                        </linearGradient>
                                    </defs>
                                    <rect width="520" height="320" rx="28" fill="url(#shopBg)" />
                                    <rect x="28" y="34" width="160" height="84" rx="18" fill="#ffffff" opacity="0.75" />
                                    <rect x="210" y="34" width="282" height="24" rx="12" fill="#cfcfcf" />
                                    <rect x="210" y="72" width="220" height="18" rx="9" fill="#b8b8b8" />
                                    <rect x="210" y="102" width="180" height="18" rx="9" fill="#b8b8b8" />
                                    <rect x="44" y="214" width="434" height="18" rx="9" fill="#9b9b9b" opacity="0.5" />
                                    <path d="M126 204h250c20 0 31-7 38-22l18-37c6-12 2-22-11-28l-52-24c-13-6-27-9-41-9H207c-22 0-41 8-58 23l-34 31c-10 9-16 21-16 33v7c0 16 11 26 27 26Z" fill="url(#carBody)" />
                                    <path d="M190 108h137c11 0 21 3 30 9l28 19c7 5 5 13-4 13H166c-9 0-12-7-6-13l18-16c4-4 8-7 12-9Z" fill="#ededed" />
                                    <circle cx="181" cy="209" r="31" fill="#151515" />
                                    <circle cx="181" cy="209" r="14" fill="#d7d7d7" />
                                    <circle cx="375" cy="209" r="31" fill="#151515" />
                                    <circle cx="375" cy="209" r="14" fill="#d7d7d7" />
                                    <rect x="88" y="246" width="344" height="10" rx="5" fill="#777777" opacity="0.45" />
                                </svg>
                            </div>
                            <div class="stage-photo-copy">
                                <strong>Designed for real-world shop flow</strong>
                                <p>From registration to vehicle intake, repair tracking, billing, and collections.</p>
                            </div>
                        </div>

                        <div class="stage-card stage-card-stack">
                            <div class="stack-mini-card">
                                <span class="stack-label">Tenant Onboarding</span>
                                <strong>Plan + add-ons + review</strong>
                            </div>
                            <div class="stack-mini-card">
                                <span class="stack-label">Daily Operations</span>
                                <strong>Appointments, jobs, stock, invoices</strong>
                            </div>
                            <div class="stack-mini-card">
                                <span class="stack-label">Revenue Control</span>
                                <strong>Collections, AP prep, analytics, billing-ready flow</strong>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <?php if ($landingError !== null): ?>
            <section class="section-block">
                <div class="container">
                    <div class="alert alert-error">
                        <?= htmlspecialchars($landingError, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section id="modules" class="section-block">
            <div class="container">
                <div class="section-heading">
                    <p class="eyebrow">Why It Sells</p>
                    <h2>A landing page for growth, and an admin workspace for real shop work.</h2>
                    <p>
                        The platform already supports the business journey from public pricing and registration all the way to
                        tenant activation, feature control, repair operations, collections, and reporting.
                    </p>
                </div>

                <div class="landing-feature-grid">
                    <article class="content-card landing-feature-card">
                        <span class="feature-kicker">01</span>
                        <h3>Registration to Approval</h3>
                        <p>Capture applications with selected plans and optional add-ons, then route them through super admin review before activation.</p>
                    </article>
                    <article class="content-card landing-feature-card">
                        <span class="feature-kicker">02</span>
                        <h3>Tier-Based Feature Control</h3>
                        <p>Use subscription packaging and feature toggles to give each tenant the right module set without rebuilding the product.</p>
                    </article>
                    <article class="content-card landing-feature-card">
                        <span class="feature-kicker">03</span>
                        <h3>Operational Workspace</h3>
                        <p>Manage customers, vehicles, appointments, jobs, inventory, invoices, and payments inside one tenant-aware dashboard.</p>
                    </article>
                    <article class="content-card landing-feature-card">
                        <span class="feature-kicker">04</span>
                        <h3>Billing-Ready Direction</h3>
                        <p>Prepared to evolve into a live PayMongo workflow once production billing, keys, webhooks, and payment testing are connected.</p>
                    </article>
                    <article class="content-card landing-feature-card">
                        <span class="feature-kicker">05</span>
                        <h3>Inventory With AP Direction</h3>
                        <p>Track suppliers, purchases, payables, and stock movement while keeping the product modular for smaller shops.</p>
                    </article>
                    <article class="content-card landing-feature-card">
                        <span class="feature-kicker">06</span>
                        <h3>Reports That Scale</h3>
                        <p>Monitor collections, receivables, supplier spending, payables, and low-stock visibility from the same tenant-safe dataset.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="section-block">
            <div class="container story-grid">
                <article class="content-card story-card story-card-dark">
                    <div class="story-card-copy">
                        <p class="eyebrow">From First Contact</p>
                        <h3>Turn your pricing page into a conversion path, not just an information page.</h3>
                        <p>
                            Business owners can understand the offer, compare plans, choose optional modules,
                            and move directly into registration without leaving the product story behind.
                        </p>
                    </div>
                </article>

                <article class="content-card story-card">
                    <div class="story-timeline">
                        <div class="timeline-step">
                            <strong>Choose Plan</strong>
                            <span>Starter, Growth, or Pro</span>
                        </div>
                        <div class="timeline-step">
                            <strong>Select Add-Ons</strong>
                            <span>Extend only what the business needs</span>
                        </div>
                        <div class="timeline-step">
                            <strong>Review + Billing</strong>
                            <span>Admin review and billing flow ready</span>
                        </div>
                        <div class="timeline-step">
                            <strong>Go Live</strong>
                            <span>Tenant workspace opens with feature control</span>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <section id="pricing" class="section-block">
            <div class="container">
                <div class="section-heading">
                    <p class="eyebrow">Pricing</p>
                    <h2>Choose a base plan, then extend it with optional platform add-ons.</h2>
                    <p>
                        Start smaller shops on essential operations, then let growing businesses unlock more value over time.
                    </p>
                </div>

                <div class="pricing-grid">
                    <?php foreach ($plans as $plan): ?>
                        <article class="content-card pricing-card">
                            <div class="pricing-card-head">
                                <h3><?= htmlspecialchars($plan['plan_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p><?= htmlspecialchars($plan['description'] ?: 'Subscription plan for repair businesses.', ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div class="pricing-amount">
                                <strong><?= htmlspecialchars(landingMoney($plan['monthly_price']), ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>per month</span>
                            </div>
                            <p class="pricing-secondary"><?= htmlspecialchars(landingMoney($plan['yearly_price']), ENT_QUOTES, 'UTF-8') ?> yearly</p>
                            <div class="pricing-feature-list">
                                <?php foreach ($plan['features'] as $featureName): ?>
                                    <div class="pricing-feature-item"><?= htmlspecialchars(landingFeatureLabel($featureName), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="pricing-actions">
                                <a href="register.php?plan_id=<?= (int) $plan['plan_id'] ?>" class="btn btn-primary btn-full">Choose <?= htmlspecialchars($plan['plan_name'], ENT_QUOTES, 'UTF-8') ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section-block">
            <div class="container content-grid">
                <article class="content-card">
                    <h3>Optional Add-Ons</h3>
                    <p>Businesses can request more capabilities during registration and be billed for those extra modules later.</p>
                    <div class="addon-list">
                        <?php foreach ($addonFeatures as $addon): ?>
                            <div class="addon-item">
                                <div>
                                    <strong><?= htmlspecialchars(landingFeatureLabel($addon['feature_name']), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <p><?= htmlspecialchars($addon['description'] ?: 'Optional platform add-on.', ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="addon-price"><?= htmlspecialchars(landingMoney($addon['monthly_addon_price']), ENT_QUOTES, 'UTF-8') ?>/mo</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="content-card">
                    <h3>What Happens After Registration</h3>
                    <p>
                        Applications enter a super admin review workflow first, so plan choice, add-ons, approval, and future billing can all stay organized.
                    </p>
                    <div class="checklist">
                        <div class="checklist-item">Business selects a subscription plan and optional add-ons</div>
                        <div class="checklist-item">Registration is saved for review in the platform</div>
                        <div class="checklist-item">Billing and PayMongo can be connected in the next phase</div>
                    </div>
                    <div class="pricing-actions">
                        <a href="register.php" class="btn btn-primary">Register Your Business</a>
                    </div>
                </article>
            </div>
        </section>

        <section class="section-block">
            <div class="container">
                <div class="landing-cta-band">
                    <div>
                        <p class="eyebrow">Ready To Launch</p>
                        <h2>Start with the plan that fits your shop today, then expand when the business is ready.</h2>
                        <p>
                            MECHANIX is built to feel like a polished SaaS from the public landing page all the way to the tenant admin dashboard.
                        </p>
                    </div>
                    <div class="landing-cta-actions">
                        <a href="register.php" class="btn btn-primary">Register Your Business</a>
                        <a href="login.php" class="btn btn-secondary">Tenant Login</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script src="assets/js/theme.js"></script>
</body>
</html>
