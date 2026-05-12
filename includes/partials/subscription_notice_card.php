<?php
/** @var array $subscriptionNotice from getTenantSubscriptionNotice() */
if (empty($subscriptionNotice) || !is_array($subscriptionNotice)) {
    return;
}
?>
<div class="row mb-4">
    <div class="col">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-receipt me-2 text-muted"></i>Business Subscription</h3>
            </div>
            <div class="card-body pb-0">
                <p class="text-muted small">Your shop can monitor subscription timing here. SaaS billing changes are handled by the platform super admin.</p>
            </div>
            <div class="list-group list-group-flush">
                <div class="list-group-item">
                    <div class="row align-items-center">
                        <div class="col">
                            <div class="font-weight-medium">Current Subscription</div>
                            <div class="text-muted small"><?= htmlspecialchars($subscriptionNotice['summary'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="col-auto">
                            <span class="badge <?= htmlspecialchars($subscriptionNotice['class'] ?? 'bg-secondary-lt', ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($subscriptionNotice['label'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="list-group-item">
                    <div class="row align-items-center">
                        <div class="col">
                            <div class="font-weight-medium">Renewal Reminder</div>
                            <div class="text-muted small"><?= htmlspecialchars($subscriptionNotice['detail'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="col-auto"><span class="badge bg-secondary-lt">Subscription</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
