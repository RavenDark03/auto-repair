<?php
declare(strict_types=1);

$variant = $mechanixPublicTopbarVariant ?? null;
if ($variant === null) {
    return;
}

$photoLogo = !empty($mechanixPublicTopbarLogoPhoto);

?>
<header class="topbar mechanix-public-topbar">
    <div class="topbar-inner">
        <div class="brand">
            <?php if ($photoLogo): ?>
                <div class="brand-mark brand-mark--photo" role="img" aria-label="MECHANIX">
                    <img src="images/logo-mech.jpg" alt="" width="42" height="42" decoding="async">
                </div>
            <?php else: ?>
                <div class="brand-mark">M</div>
            <?php endif; ?>
            <div class="brand-text">
                <h1>MECHANIX</h1>
                <p>Subscription-based auto repair SaaS</p>
            </div>
        </div>
        <div class="nav-actions">
            <?= mechanix_theme_toggle_button() ?>
            <?php if ($variant === 'cta'): ?>
                <a href="login.php" class="btn btn-secondary">Log in</a>
                <a href="register.php" class="btn btn-primary">Register business</a>
            <?php elseif ($variant === 'back_home'): ?>
                <?= mechanix_back_icon_link('index.php', 'Back to landing') ?>
            <?php elseif ($variant === 'back_link' && isset($mechanixPublicTopbarBackHref)): ?>
                <?= mechanix_back_icon_link((string) $mechanixPublicTopbarBackHref, (string) ($mechanixPublicTopbarBackLabel ?? 'Back')) ?>
            <?php elseif ($variant === 'billing_pending'): ?>
                <a href="index.php" class="btn btn-secondary">Home</a>
            <?php elseif ($variant === 'logout_only'): ?>
                <a href="logout.php" class="btn btn-secondary">Log out</a>
            <?php endif; ?>
        </div>
    </div>
</header>
