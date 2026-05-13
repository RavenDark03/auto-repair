            <!-- Tenants + Tenant Controls -->
            <div id="features" class="row row-deck row-cards mb-4">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-building-store me-2 text-muted"></i>Tenants</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">Filter the tenant list by status or subscription state, then open a tenant to manage controls and feature access.</p>
                        </div>
                        <div class="card-body border-top">
                            <form action="<?= htmlspecialchars($mechanixSuperadminTenantPage, ENT_QUOTES, 'UTF-8') ?>" method="GET">
                                <div class="row g-2 mb-2">
                                    <div class="col-12">
                                        <label class="form-label" for="tenant_search">Search tenant</label>
                                        <input class="form-control" type="search" id="tenant_search" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Business name" autocomplete="off">
                                        <span id="sa-tenant-filter-live" class="sr-only" aria-live="polite"></span>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="tenant_status">Tenant Status</label>
                                        <select class="form-control" id="tenant_status" name="tenant_status">
                                            <option value="">All tenant statuses</option>
                                            <option value="active"<?= $tenantStatusFilter === 'active' ? ' selected' : '' ?>>Active</option>
                                            <option value="inactive"<?= $tenantStatusFilter === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                                            <option value="pending_payment"<?= $tenantStatusFilter === 'pending_payment' ? ' selected' : '' ?>>Pending payment</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="subscription_status">Subscription Status</label>
                                        <select class="form-control" id="subscription_status" name="subscription_status">
                                            <option value="">All subscription statuses</option>
                                            <option value="active"<?= $subscriptionStatusFilter === 'active' ? ' selected' : '' ?>>Active</option>
                                            <option value="expired"<?= $subscriptionStatusFilter === 'expired' ? ' selected' : '' ?>>Expired</option>
                                            <option value="none"<?= $subscriptionStatusFilter === 'none' ? ' selected' : '' ?>>No subscription</option>
                                        </select>
                                    </div>
                                    <div class="col-12 d-flex gap-2">
                                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                                        <a href="<?= htmlspecialchars($mechanixSuperadminTenantPage, ENT_QUOTES, 'UTF-8') ?><?= $mechanixSuperadminTenantListFragment ?>" class="btn btn-secondary btn-sm">Reset</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <?php if (!empty($tenants)): ?>
                            <div class="list-group list-group-flush"
                                 id="sa-livefilter-tenants"
                                 data-live-filter-scope
                                 data-live-filter-input="#tenant_search"
                                 data-live-filter-items=".list-group-item"
                                 data-live-filter-announcer="sa-tenant-filter-live">
                                <?php foreach ($tenants as $tenant): ?>
                                    <?php
                                    $isSelected = (int) $tenant['tenant_id'] === $selectedTenantId;
                                    $tenantHealthBadge = match($tenant['health_class'] ?? '') {
                                        'status-active' => 'bg-green-lt text-green',
                                        'status-inactive' => 'bg-red-lt text-red',
                                        'status-rejected' => 'bg-red-lt text-red',
                                        'status-warning' => 'bg-orange-lt text-orange',
                                        'status-pending' => 'bg-orange-lt text-orange',
                                        default => 'bg-secondary-lt',
                                    };
                                    ?>
                                    <a class="list-group-item list-group-item-action<?= $isSelected ? ' active' : '' ?>" data-live-filter-row href="<?= htmlspecialchars($mechanixSuperadminTenantPage, ENT_QUOTES, 'UTF-8') ?>?tenant_id=<?= (int) $tenant['tenant_id'] ?><?= $tenantSearch !== '' ? '&tenant_search=' . urlencode($tenantSearch) : '' ?><?= $tenantStatusFilter !== '' ? '&tenant_status=' . urlencode($tenantStatusFilter) : '' ?><?= $subscriptionStatusFilter !== '' ? '&subscription_status=' . urlencode($subscriptionStatusFilter) : '' ?><?= $mechanixSuperadminTenantListFragment ?>">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars($tenant['business_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small">
                                                    Status: <?= htmlspecialchars($tenant['status'], ENT_QUOTES, 'UTF-8') ?>
                                                    &middot; Plan: <?= htmlspecialchars($tenant['current_plan'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>
                                                    &middot; Sub: <?= htmlspecialchars($tenant['current_subscription_status'] ?: 'none', ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                                <div class="text-muted small"><?= htmlspecialchars($tenant['health_detail'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <div class="col-auto d-flex flex-column gap-1 align-items-end">
                                                <span class="badge <?= $tenantHealthBadge ?>"><?= htmlspecialchars($tenant['health_label'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="badge bg-secondary-lt"><?= number_format((int) $tenant['active_users']) ?> users</span>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="card-body">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No tenants</p>
                                    <p class="empty-subtitle text-muted">No tenants matched the current filters.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-settings me-2 text-muted"></i>Tenant Controls</h3>
                        </div>
                    <?php if ($selectedTenant): ?>
                        <?php
                        $tenantStatusBadge = ($selectedTenant['status'] === 'active') ? 'bg-green-lt text-green' : 'bg-red-lt text-red';
                        $tenantSubBadge = ($selectedTenant['subscription_status'] === 'active') ? 'bg-green-lt text-green' : (($selectedTenant['subscription_status'] === 'expired') ? 'bg-red-lt text-red' : 'bg-secondary-lt');
                        ?>
                        <div class="card-body pb-0">
                            <p class="text-muted small">
                                Editing <strong><?= htmlspecialchars($selectedTenant['business_name'], ENT_QUOTES, 'UTF-8') ?></strong>.
                                Current plan: <?= htmlspecialchars($selectedTenant['plan'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?>.
                            </p>
                        </div>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col"><div class="font-weight-medium">Tenant Status</div></div>
                                    <div class="col-auto"><span class="badge <?= $tenantStatusBadge ?>"><?= htmlspecialchars(ucfirst($selectedTenant['status']), ENT_QUOTES, 'UTF-8') ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Active Tenant Users</div>
                                        <div class="text-muted small">Accounts currently allowed to sign in under this tenant.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt"><?= number_format((int) ($selectedTenant['active_users'] ?? 0)) ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Subscription Window</div>
                                        <div class="text-muted small"><?= htmlspecialchars($selectedTenant['start_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($selectedTenant['end_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge <?= $tenantSubBadge ?>"><?= htmlspecialchars(ucfirst($selectedTenant['subscription_status'] ?: 'none'), ENT_QUOTES, 'UTF-8') ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Attention State</div>
                                        <div class="text-muted small"><?= htmlspecialchars($selectedTenant['health_detail'] ?? 'No subscription health details available.', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge <?= $tenantHealthBadge ?? 'bg-secondary-lt' ?>"><?= htmlspecialchars($selectedTenant['health_label'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Tenant Created</div>
                                        <div class="text-muted small"><?= htmlspecialchars($selectedTenant['created_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt">Live</span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Access Mode</div>
                                        <div class="text-muted small"><?= ($selectedTenant['access_mode'] ?? 'full_access') === 'read_only' ? 'Read-only workspace with editing disabled.' : 'Full workspace access is active.' ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <span class="badge <?= ($selectedTenant['access_mode'] ?? 'full_access') === 'read_only' ? 'bg-orange-lt text-orange' : 'bg-green-lt text-green' ?>">
                                            <?= ($selectedTenant['access_mode'] ?? 'full_access') === 'read_only' ? 'Read-Only' : 'Full Access' ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card-body">
                        <div class="row row-deck row-cards">
                            <div class="col-12">
                              <div class="card">
                                <div class="card-header"><h3 class="card-title">Tenant Lifecycle</h3></div>
                                <div class="card-body pb-0">
                                    <p class="text-muted small">View how this live tenant moved from registration through billing and conversion.</p>
                                </div>
                                <?php if ($selectedTenantRegistration): ?>
                                    <?php $lifecycleBadge = $regStatusBadge[$selectedTenantRegistration['registration_status']] ?? 'bg-secondary-lt'; ?>
                                    <div class="list-group list-group-flush">
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <div class="font-weight-medium">Source Registration</div>
                                                    <div class="text-muted small">#<?= (int) $selectedTenantRegistration['registration_id'] ?> &middot; <?= htmlspecialchars($selectedTenantRegistration['owner_full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <div class="col-auto"><span class="badge <?= $lifecycleBadge ?>"><?= htmlspecialchars(ucfirst($selectedTenantRegistration['registration_status']), ENT_QUOTES, 'UTF-8') ?></span></div>
                                            </div>
                                        </div>
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <div class="font-weight-medium">Plan and Billing Cycle</div>
                                                    <div class="text-muted small"><?= htmlspecialchars($selectedTenantRegistration['plan_name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars(ucfirst($selectedTenantRegistration['billing_cycle']), ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <div class="col-auto"><span class="badge bg-secondary-lt">Registration</span></div>
                                            </div>
                                        </div>
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <div class="font-weight-medium">Registration Timeline</div>
                                                    <div class="text-muted small">Submitted: <?= htmlspecialchars($selectedTenantRegistration['created_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?><br>Reviewed / Converted: <?= htmlspecialchars($selectedTenantRegistration['reviewed_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <div class="col-auto"><span class="badge bg-secondary-lt">Timeline</span></div>
                                            </div>
                                        </div>
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <div class="font-weight-medium">Current Subscription State</div>
                                                    <div class="text-muted small"><?= htmlspecialchars($selectedTenant['plan'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($selectedTenant['start_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($selectedTenant['end_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <div class="col-auto"><span class="badge <?= $tenantSubBadge ?>"><?= htmlspecialchars(ucfirst($selectedTenant['subscription_status'] ?: 'none'), ENT_QUOTES, 'UTF-8') ?></span></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($mechanixSuperadminRegistrationPage, ENT_QUOTES, 'UTF-8') ?>?registration_id=<?= (int) $selectedTenantRegistration['registration_id'] ?>&tenant_id=<?= (int) $selectedTenant['tenant_id'] ?><?= $registrationSearch !== '' ? '&registration_search=' . urlencode($registrationSearch) : '' ?><?= $registrationStatusFilter !== '' ? '&registration_status=' . urlencode($registrationStatusFilter) : '' ?><?= $registrationBillingStatusFilter !== '' ? '&registration_billing_status=' . urlencode($registrationBillingStatusFilter) : '' ?><?= $tenantSearch !== '' ? '&tenant_search=' . urlencode($tenantSearch) : '' ?><?= $tenantStatusFilter !== '' ? '&tenant_status=' . urlencode($tenantStatusFilter) : '' ?><?= $subscriptionStatusFilter !== '' ? '&subscription_status=' . urlencode($subscriptionStatusFilter) : '' ?><?= $mechanixSuperadminRegListFragment ?>">Open Registration Record</a>
                                    </div>

                                    <div class="card-header"><h3 class="card-title">Final Enabled Features</h3></div>
                                    <div class="card-body pb-0">
                                        <p class="text-muted small">These are the modules currently enabled for this live tenant after plan defaults, requested add-ons, and any later super admin adjustments.</p>
                                    </div>
                                    <div class="list-group list-group-flush">
                                        <?php if (!empty($selectedTenantEnabledFeatures)): ?>
                                            <?php foreach ($selectedTenantEnabledFeatures as $feature): ?>
                                                <div class="list-group-item">
                                                    <div class="row align-items-center">
                                                        <div class="col">
                                                            <div class="font-weight-medium"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $feature['feature_name'])), ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="text-muted small"><?= htmlspecialchars($feature['description'] ?: 'Enabled for this tenant.', ENT_QUOTES, 'UTF-8') ?></div>
                                                        </div>
                                                        <div class="col-auto"><span class="badge bg-green-lt text-green">Enabled</span></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="list-group-item text-muted small">No enabled features are currently recorded for this tenant.</div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($selectedTenantBillingHistory)): ?>
                                        <div class="card-header"><h3 class="card-title">Billing History</h3></div>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($selectedTenantBillingHistory as $billingHistory): ?>
                                                <?php
                                                $billHistBadge = match($billingHistory['billing_status']) {
                                                    'paid' => 'bg-teal-lt text-teal',
                                                    'sent' => 'bg-blue-lt text-blue',
                                                    'draft' => 'bg-secondary-lt',
                                                    'cancelled' => 'bg-red-lt text-red',
                                                    default => 'bg-secondary-lt',
                                                };
                                                ?>
                                                <div class="list-group-item">
                                                    <div class="row align-items-center">
                                                        <div class="col">
                                                            <div class="font-weight-medium">Billing Request #<?= (int) $billingHistory['billing_request_id'] ?></div>
                                                            <div class="text-muted small"><?= htmlspecialchars($billingHistory['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $billingHistory['total_amount'], 2) ?> &middot; Due: <?= htmlspecialchars($billingHistory['due_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> &middot; Paid: <?= htmlspecialchars($billingHistory['paid_at'] ?: 'Not paid yet', ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="text-muted small">Ref: <?= htmlspecialchars($billingHistory['payment_reference'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> &middot; PayMongo: <?= htmlspecialchars(ucfirst($billingHistory['paymongo_status'] ?: 'none'), ENT_QUOTES, 'UTF-8') ?></div>
                                                        </div>
                                                        <div class="col-auto"><span class="badge <?= $billHistBadge ?>"><?= htmlspecialchars(ucfirst($billingHistory['billing_status']), ENT_QUOTES, 'UTF-8') ?></span></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="card-body">
                                            <div class="empty empty-sm">
                                                <p class="empty-title">No billing history</p>
                                                <p class="empty-subtitle text-muted">No billing requests were found for the linked registration.</p>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="card-body">
                                        <div class="alert alert-info mb-0" role="alert">
                                            <div class="d-flex">
                                                <div><i class="ti ti-notes icon alert-icon"></i></div>
                                                <div><strong>Conversion Notes</strong><br><?= nl2br(htmlspecialchars($selectedTenantRegistration['notes'] ?: 'No conversion notes recorded.', ENT_QUOTES, 'UTF-8')) ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="card-header"><h3 class="card-title">Communication Activity</h3></div>
                                    <div class="card-body pb-0">
                                        <p class="text-muted small">Recent communication logs linked to this tenant lifecycle record.</p>
                                    </div>
                                    <?php if (!empty($selectedTenantEmailLogs)): ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($selectedTenantEmailLogs as $emailLog): ?>
                                                <?php $tenantEmailBadge = ($emailLog['send_status'] === 'sent') ? 'bg-green-lt text-green' : (($emailLog['send_status'] === 'failed') ? 'bg-red-lt text-red' : 'bg-orange-lt text-orange'); ?>
                                                <div class="list-group-item">
                                                    <div class="row align-items-center">
                                                        <div class="col">
                                                            <div class="font-weight-medium"><?= htmlspecialchars($emailLog['subject'], ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="text-muted small"><?= htmlspecialchars($emailLog['recipient_email'], ENT_QUOTES, 'UTF-8') ?> &middot; Type: <?= htmlspecialchars($emailLog['email_type'], ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="text-muted small">Created: <?= htmlspecialchars($emailLog['created_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> &middot; Sent: <?= htmlspecialchars($emailLog['sent_at'] ?: 'Pending', ENT_QUOTES, 'UTF-8') ?></div>
                                                        </div>
                                                        <div class="col-auto"><span class="badge <?= $tenantEmailBadge ?>"><?= htmlspecialchars(ucfirst($emailLog['send_status']), ENT_QUOTES, 'UTF-8') ?></span></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="card-body">
                                            <div class="empty empty-sm">
                                                <p class="empty-title">No email logs</p>
                                                <p class="empty-subtitle text-muted">No email log entries were found for this tenant lifecycle yet.</p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="card-body">
                                        <div class="alert alert-warning mb-0" role="alert">
                                            <div class="d-flex">
                                                <div><i class="ti ti-alert-triangle icon alert-icon"></i></div>
                                                <div>This tenant is live, but the dashboard could not find a linked source registration automatically. Review the conversion notes and tenant details before making lifecycle changes.</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                              </div>
                            </div>

                            <!-- Tenant Status control -->
                            <div class="col-12">
                              <div class="card">
                                <div class="card-header"><h3 class="card-title">Tenant Status</h3></div>
                                <div class="card-body pb-0">
                                    <p class="text-muted small">Control whether this tenant can access the platform. Tenant login already blocks inactive tenants.</p>
                                </div>
                                <div class="card-body">
                                    <form action="actions/update_tenant_status.php" method="POST">
                                        <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                        <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="mb-2">
                                            <label class="form-label" for="tenant_status_control">Tenant Status</label>
                                            <select class="form-control" id="tenant_status_control" name="status" required>
                                                <option value="active"<?= ($selectedTenant['status'] === 'active') ? ' selected' : '' ?>>Active</option>
                                                <option value="inactive"<?= ($selectedTenant['status'] === 'inactive') ? ' selected' : '' ?>>Inactive</option>
                                                <option value="pending_payment"<?= ($selectedTenant['status'] === 'pending_payment') ? ' selected' : '' ?>>Pending payment</option>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">Save Tenant Status</button>
                                    </form>
                                </div>
                              </div>
                            </div>

                            <!-- Subscription Status control -->
                            <div class="col-12">
                              <div class="card">
                                <div class="card-header"><h3 class="card-title">Subscription Status</h3></div>
                                <div class="card-body pb-0">
                                    <p class="text-muted small">Update the latest subscription state using the current schema-supported values.</p>
                                </div>
                                <div class="card-body">
                                    <form action="actions/update_tenant_access_mode.php" method="POST" class="mb-3">
                                        <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                        <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <div>
                                                <div class="font-weight-medium">Read-Only Downgrade</div>
                                                <div class="text-muted small">Switch this tenant to read-only when they need access to records without operational editing.</div>
                                            </div>
                                            <span class="badge <?= ($selectedTenant['access_mode'] ?? 'full_access') === 'read_only' ? 'bg-orange-lt text-orange' : 'bg-green-lt text-green' ?>">
                                                <?= ($selectedTenant['access_mode'] ?? 'full_access') === 'read_only' ? 'Read-Only' : 'Full Access' ?>
                                            </span>
                                        </div>
                                        <?php if (($selectedTenant['access_mode'] ?? 'full_access') === 'read_only'): ?>
                                            <button type="submit" name="access_mode" value="full_access" class="btn btn-secondary btn-sm">Restore Full Access</button>
                                        <?php else: ?>
                                            <button type="submit" name="access_mode" value="read_only" class="btn btn-secondary btn-sm">Downgrade To Read-Only</button>
                                        <?php endif; ?>
                                    </form>

                                    <?php if (!empty($selectedTenant['subscription_status'])): ?>
                                        <form action="actions/update_subscription_status.php" method="POST" class="mb-3">
                                            <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                            <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="mb-2">
                                                <label class="form-label" for="subscription_status_control">Subscription Status</label>
                                                <select class="form-control" id="subscription_status_control" name="status" required>
                                                    <option value="active"<?= ($selectedTenant['subscription_status'] === 'active') ? ' selected' : '' ?>>Active</option>
                                                    <option value="expired"<?= ($selectedTenant['subscription_status'] === 'expired') ? ' selected' : '' ?>>Expired</option>
                                                </select>
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm">Save Subscription Status</button>
                                        </form>

                                        <form action="actions/update_subscription_window.php" method="POST" class="mb-3">
                                            <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                            <input type="hidden" name="action_type" value="save_dates">
                                            <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="row g-2 mb-2">
                                                <div class="col-sm-6">
                                                    <label class="form-label" for="subscription_start_date">Start Date</label>
                                                    <input class="form-control" type="date" id="subscription_start_date" name="start_date" value="<?= htmlspecialchars($selectedTenant['start_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                                <div class="col-sm-6">
                                                    <label class="form-label" for="subscription_end_date">End Date</label>
                                                    <input class="form-control" type="date" id="subscription_end_date" name="end_date" value="<?= htmlspecialchars($selectedTenant['end_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-secondary btn-sm">Save Subscription Window</button>
                                        </form>

                                        <form action="actions/update_subscription_window.php" method="POST">
                                            <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                                            <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="mb-2">
                                                <div class="font-weight-medium">Quick Renewal</div>
                                                <div class="text-muted small">Extend the latest subscription window from its current end date, or from today if already elapsed.</div>
                                            </div>
                                            <div class="d-flex gap-2">
                                                <button type="submit" name="action_type" value="extend_month" class="btn btn-secondary btn-sm">Extend 1 Month</button>
                                                <button type="submit" name="action_type" value="extend_year" class="btn btn-primary btn-sm">Extend 1 Year</button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <div class="alert alert-warning mb-0" role="alert">
                                            <div class="d-flex">
                                                <div><i class="ti ti-info-circle icon alert-icon"></i></div>
                                                <div>This tenant does not have a subscription record yet.</div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                              </div>
                            </div>

                        </div>
                        </div>

                        <div class="card-header">
                            <h3 class="card-title">Feature Toggles</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">Plan-included and requested add-on features are auto-applied during tenant conversion. You can fine-tune them here afterward.</p>
                        </div>
                        <div class="card-body">
                        <form action="actions/save_tenant_features.php" method="POST">
                            <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenant['tenant_id'] ?>">
                            <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="tenant_status_filter" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="subscription_status_filter" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="row g-2 mb-3">
                                <?php foreach ($features as $feature): ?>
                                    <div class="col-sm-6">
                                        <label class="form-check">
                                            <input
                                                type="checkbox"
                                                class="form-check-input"
                                                name="features[]"
                                                value="<?= (int) $feature['feature_id'] ?>"
                                                <?= !empty($featureStates[(int) $feature['feature_id']]) ? 'checked' : '' ?>
                                            >
                                            <span class="form-check-label">
                                                <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $feature['feature_name'])), ENT_QUOTES, 'UTF-8') ?></strong><br>
                                                <span class="text-muted small"><?= htmlspecialchars($feature['description'] ?: 'No description provided.', ENT_QUOTES, 'UTF-8') ?></span>
                                            </span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Save Tenant Features</button>
                        </form>
                        </div>
                    <?php else: ?>
                        <div class="card-body">
                            <p class="text-muted">Select a tenant from the left to manage feature access.</p>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

