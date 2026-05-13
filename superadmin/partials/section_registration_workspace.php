            <!-- Registration Queue + Registration Details -->
            <div id="registrations" class="row row-deck row-cards mb-4">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-clipboard-list me-2 text-muted"></i>Registration Queue</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">Review incoming business applications before billing and tenant activation.</p>
                        </div>
                        <div class="card-body border-top">
                            <form action="<?= htmlspecialchars($mechanixSuperadminRegistrationPage, ENT_QUOTES, 'UTF-8') ?>" method="GET">
                                <?php if ($selectedTenantId > 0): ?>
                                    <input type="hidden" name="tenant_id" value="<?= (int) $selectedTenantId ?>">
                                <?php endif; ?>
                                <?php if ($tenantSearch !== ''): ?>
                                    <input type="hidden" name="tenant_search" value="<?= htmlspecialchars($tenantSearch, ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                                <?php if ($tenantStatusFilter !== ''): ?>
                                    <input type="hidden" name="tenant_status" value="<?= htmlspecialchars($tenantStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                                <?php if ($subscriptionStatusFilter !== ''): ?>
                                    <input type="hidden" name="subscription_status" value="<?= htmlspecialchars($subscriptionStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                                <div class="row g-2 mb-2">
                                    <div class="col-12">
                                        <label class="form-label" for="registration_search">Search registration</label>
                                        <input class="form-control" type="search" id="registration_search" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Business, owner, or email" autocomplete="off">
                                        <span id="sa-reg-filter-live" class="sr-only" aria-live="polite"></span>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="registration_status_filter">Registration Status</label>
                                        <select class="form-control" id="registration_status_filter" name="registration_status">
                                            <option value="">All registration statuses</option>
                                            <option value="pending"<?= $registrationStatusFilter === 'pending' ? ' selected' : '' ?>>Pending</option>
                                            <option value="approved"<?= $registrationStatusFilter === 'approved' ? ' selected' : '' ?>>Approved</option>
                                            <option value="billing_sent"<?= $registrationStatusFilter === 'billing_sent' ? ' selected' : '' ?>>Billing Sent</option>
                                            <option value="paid"<?= $registrationStatusFilter === 'paid' ? ' selected' : '' ?>>Paid</option>
                                            <option value="converted"<?= $registrationStatusFilter === 'converted' ? ' selected' : '' ?>>Converted</option>
                                            <option value="rejected"<?= $registrationStatusFilter === 'rejected' ? ' selected' : '' ?>>Rejected</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="registration_billing_status_filter">Billing Status</label>
                                        <select class="form-control" id="registration_billing_status_filter" name="registration_billing_status">
                                            <option value="">All billing statuses</option>
                                            <option value="draft"<?= $registrationBillingStatusFilter === 'draft' ? ' selected' : '' ?>>Draft</option>
                                            <option value="sent"<?= $registrationBillingStatusFilter === 'sent' ? ' selected' : '' ?>>Sent</option>
                                            <option value="paid"<?= $registrationBillingStatusFilter === 'paid' ? ' selected' : '' ?>>Paid</option>
                                            <option value="cancelled"<?= $registrationBillingStatusFilter === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
                                            <option value="none"<?= $registrationBillingStatusFilter === 'none' ? ' selected' : '' ?>>No billing request</option>
                                        </select>
                                    </div>
                                    <div class="col-12 d-flex gap-2">
                                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                                        <a href="<?= htmlspecialchars($mechanixSuperadminRegistrationPage, ENT_QUOTES, 'UTF-8') ?><?= $selectedTenantId > 0 ? '?tenant_id=' . (int) $selectedTenantId . $mechanixSuperadminRegListFragment : $mechanixSuperadminRegListFragment ?>" class="btn btn-secondary btn-sm">Reset</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <?php if (!empty($registrations)): ?>
                            <div class="list-group list-group-flush"
                                 id="sa-livefilter-registrations"
                                 data-live-filter-scope
                                 data-live-filter-input="#registration_search"
                                 data-live-filter-items=".list-group-item"
                                 data-live-filter-announcer="sa-reg-filter-live">
                                <?php
                                $regStatusBadge = [
                                    'pending'      => 'bg-orange-lt text-orange',
                                    'approved'     => 'bg-green-lt text-green',
                                    'billing_sent' => 'bg-blue-lt text-blue',
                                    'paid'         => 'bg-teal-lt text-teal',
                                    'converted'    => 'bg-blue-lt text-blue',
                                    'rejected'     => 'bg-red-lt text-red',
                                ];
                                foreach ($registrations as $registration):
                                    $isSelectedRegistration = (int) $registration['registration_id'] === $selectedRegistrationId;
                                    $regBadge = $regStatusBadge[$registration['registration_status']] ?? 'bg-secondary-lt';
                                ?>
                                    <a class="list-group-item list-group-item-action<?= $isSelectedRegistration ? ' active' : '' ?>" data-live-filter-row href="<?= htmlspecialchars($mechanixSuperadminRegistrationPage, ENT_QUOTES, 'UTF-8') ?>?registration_id=<?= (int) $registration['registration_id'] ?><?= $registrationSearch !== '' ? '&registration_search=' . urlencode($registrationSearch) : '' ?><?= $registrationStatusFilter !== '' ? '&registration_status=' . urlencode($registrationStatusFilter) : '' ?><?= $registrationBillingStatusFilter !== '' ? '&registration_billing_status=' . urlencode($registrationBillingStatusFilter) : '' ?><?= $selectedTenantId > 0 ? '&tenant_id=' . (int) $selectedTenantId : '' ?><?= $tenantSearch !== '' ? '&tenant_search=' . urlencode($tenantSearch) : '' ?><?= $tenantStatusFilter !== '' ? '&tenant_status=' . urlencode($tenantStatusFilter) : '' ?><?= $subscriptionStatusFilter !== '' ? '&subscription_status=' . urlencode($subscriptionStatusFilter) : '' ?><?= $mechanixSuperadminRegListFragment ?>">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars($registration['business_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small">
                                                    <?= htmlspecialchars($registration['plan_name'], ENT_QUOTES, 'UTF-8') ?>
                                                    &middot; <?= htmlspecialchars($registration['owner_full_name'], ENT_QUOTES, 'UTF-8') ?>
                                                    &middot; Billing: <?= htmlspecialchars($registration['latest_billing_status'] ?: 'none', ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <span class="badge <?= $regBadge ?>">
                                                    <?= htmlspecialchars(ucfirst($registration['registration_status']), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="card-body">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No registrations</p>
                                    <p class="empty-subtitle text-muted">No registrations matched the current filters.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="ti ti-file-description me-2 text-muted"></i>Registration Details</h3>
                        </div>
                    <?php if ($selectedRegistration): ?>
                        <?php
                        $registrationStatus = (string) ($selectedRegistration['registration_status'] ?? '');
                        $billingStatus = (string) ($selectedBillingRequest['billing_status'] ?? '');
                        $canGenerateBillingDraft = in_array($registrationStatus, ['approved', 'billing_sent', 'paid'], true) && $registrationStatus !== 'converted';
                        $canCreateCheckout = $selectedBillingRequest && in_array($registrationStatus, ['approved', 'billing_sent'], true) && $billingStatus !== 'paid';
                        $canConvertTenant = $selectedBillingRequest && $registrationStatus === 'paid';
                        $canUpdateBillingStatus = $selectedBillingRequest && $registrationStatus !== 'converted';
                        $canApproveRegistration = in_array($registrationStatus, ['pending', 'rejected'], true);
                        $canMarkBillingSent = $selectedBillingRequest && in_array($registrationStatus, ['approved', 'billing_sent'], true);
                        $canRejectRegistration = in_array($registrationStatus, ['pending', 'approved', 'billing_sent'], true);
                        $regDetailBadge = $regStatusBadge[$registrationStatus] ?? 'bg-secondary-lt';
                        ?>
                        <div class="card-body pb-0">
                            <p class="text-muted small">
                                Reviewing <strong><?= htmlspecialchars($selectedRegistration['business_name'], ENT_QUOTES, 'UTF-8') ?></strong> for the <?= htmlspecialchars($selectedRegistration['plan_name'], ENT_QUOTES, 'UTF-8') ?> plan.
                            </p>
                        </div>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Primary Contact</div>
                                        <div class="text-muted small"><?= htmlspecialchars($selectedRegistration['owner_full_name'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($selectedRegistration['email'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge <?= $regDetailBadge ?>"><?= htmlspecialchars(ucfirst($registrationStatus), ENT_QUOTES, 'UTF-8') ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Billing Cycle</div>
                                        <div class="text-muted small">Preferred username: <?= htmlspecialchars($selectedRegistration['preferred_username'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt"><?= htmlspecialchars($selectedRegistration['billing_cycle'] === 'yearly' ? 'Yearly' : 'Monthly', ENT_QUOTES, 'UTF-8') ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Plan Price</div>
                                        <div class="text-muted small">Monthly: PHP <?= number_format((float) $selectedRegistration['monthly_price'], 2) ?> &middot; Yearly: PHP <?= number_format((float) $selectedRegistration['yearly_price'], 2) ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-blue-lt text-blue"><?= htmlspecialchars($selectedRegistration['plan_name'], ENT_QUOTES, 'UTF-8') ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Estimated Add-On Total</div>
                                        <div class="text-muted small">Calculated from requested add-ons for the selected billing cycle.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt">PHP <?= number_format((float) $selectedRegistration['estimated_addon_amount'], 2) ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Address</div>
                                        <div class="text-muted small"><?= nl2br(htmlspecialchars($selectedRegistration['address'] ?: 'No address provided.', ENT_QUOTES, 'UTF-8')) ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">BIR TIN</div>
                                        <div class="text-muted small"><?= htmlspecialchars((string) ($selectedRegistration['bir_tin'] ?? ''), ENT_QUOTES, 'UTF-8') ?: 'Not provided' ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt">Tax</span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Government ID number</div>
                                        <div class="text-muted small"><?= htmlspecialchars((string) ($selectedRegistration['owner_id_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?: 'Not provided' ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt">ID #</span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Admin password (registration)</div>
                                        <div class="text-muted small"><?= !empty($selectedRegistration['has_registration_password']) ? 'A bcrypt hash is stored from signup — never the plain password.' : 'No hash on file (legacy row or incomplete signup).' ?></div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt"><?= !empty($selectedRegistration['has_registration_password']) ? 'Stored' : 'Missing' ?></span></div>
                                </div>
                            </div>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Owner / business ID</div>
                                        <div class="text-muted small">
                                            <?php if (!empty($selectedRegistration['owner_id_document_path'])): ?>
                                                <a href="serve_registration_document.php?registration_id=<?= (int) $selectedRegistration['registration_id'] ?>" target="_blank" rel="noopener noreferrer">View uploaded document</a>
                                            <?php else: ?>
                                                No file on record (legacy registration).
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-secondary-lt">KYC</span></div>
                                </div>
                            </div>
                            <?php if (!empty($selectedRegistration['provisioned_tenant_id'])): ?>
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Owner workspace</div>
                                        <div class="text-muted small">Tenant #<?= (int) $selectedRegistration['provisioned_tenant_id'] ?> created at approval — owner can log in while billing is pending.</div>
                                    </div>
                                    <div class="col-auto"><span class="badge bg-azure-lt text-azure">Provisioned</span></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="card-header mt-2">
                            <h3 class="card-title">Included Plan Features</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">These features will be auto-enabled for the tenant when the paid registration is converted into a live tenant.</p>
                        </div>
                        <div class="list-group list-group-flush">
                            <?php if (!empty($selectedRegistrationPlanFeatures)): ?>
                                <?php foreach ($selectedRegistrationPlanFeatures as $planFeature): ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $planFeature['feature_name'])), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small"><?= htmlspecialchars($planFeature['description'] ?: 'Included in the selected subscription plan.', ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <div class="col-auto"><span class="badge bg-green-lt text-green">Included</span></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="list-group-item text-muted small">No plan-linked features are configured for this subscription plan yet.</div>
                            <?php endif; ?>
                            <?php if (!empty($selectedRegistrationFeatures)): ?>
                                <?php foreach ($selectedRegistrationFeatures as $addon): ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $addon['feature_name'])), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small"><?= htmlspecialchars($addon['description'] ?: 'Requested add-on feature.', ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <div class="col-auto"><span class="badge bg-blue-lt text-blue">PHP <?= number_format((float) $addon['monthly_addon_price'], 2) ?>/mo</span></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="list-group-item text-muted small">No add-on features requested for this registration.</div>
                            <?php endif; ?>
                        </div>

                        <div class="card-header mt-2">
                            <h3 class="card-title">Billing</h3>
                        </div>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="font-weight-medium">Billing Draft</div>
                                        <div class="text-muted small"><?= $selectedBillingRequest ? 'Latest billing request is ready for review.' : 'No billing request generated yet.' ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <span class="badge <?= $selectedBillingRequest ? 'bg-blue-lt text-blue' : 'bg-orange-lt text-orange' ?>">
                                            <?= htmlspecialchars(ucfirst($selectedBillingRequest['billing_status'] ?? 'none'), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php if ($selectedBillingRequest): ?>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Amounts</div>
                                            <div class="text-muted small">Plan: <?= htmlspecialchars($selectedBillingRequest['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $selectedBillingRequest['plan_amount'], 2) ?> &middot; Add-ons: <?= htmlspecialchars($selectedBillingRequest['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $selectedBillingRequest['addon_amount'], 2) ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-teal-lt text-teal"><?= htmlspecialchars($selectedBillingRequest['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) $selectedBillingRequest['total_amount'], 2) ?></span></div>
                                    </div>
                                </div>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Due Date</div>
                                            <div class="text-muted small"><?= htmlspecialchars($selectedBillingRequest['due_date'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-secondary-lt"><?= htmlspecialchars(ucfirst($selectedBillingRequest['billing_status']), ENT_QUOTES, 'UTF-8') ?></span></div>
                                    </div>
                                </div>
                                <?php
                                $ngRefClass = match($selectedBillingRequest['ng_reference_meta']['class'] ?? '') {
                                    'status-active', 'status-approved' => 'bg-green-lt text-green',
                                    'status-rejected', 'status-inactive' => 'bg-red-lt text-red',
                                    'status-warning' => 'bg-orange-lt text-orange',
                                    default => 'bg-orange-lt text-orange',
                                };
                                ?>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Payment Reference</div>
                                            <div class="text-muted small"><?= htmlspecialchars($selectedBillingRequest['payment_reference'] ?: 'Not set yet', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge <?= $ngRefClass ?>"><?= htmlspecialchars($selectedBillingRequest['ng_reference_meta']['label'] ?? 'Ref', ENT_QUOTES, 'UTF-8') ?></span></div>
                                    </div>
                                </div>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">NG Reference Review</div>
                                            <div class="text-muted small"><?= htmlspecialchars($selectedBillingRequest['ng_reference_meta']['detail'] ?? 'No reference review recorded yet.', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-secondary-lt"><?= number_format((int) ($selectedBillingRequest['duplicate_reference_count'] ?? 0)) ?>x</span></div>
                                    </div>
                                </div>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">Paid At</div>
                                            <div class="text-muted small"><?= htmlspecialchars($selectedBillingRequest['paid_at'] ?: 'Not paid yet', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-secondary-lt">Payment</span></div>
                                    </div>
                                </div>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <div class="font-weight-medium">PayMongo Checkout</div>
                                            <div class="text-muted small"><?= htmlspecialchars($selectedBillingRequest['paymongo_checkout_session_id'] ?? 'Not created yet', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div class="col-auto"><span class="badge bg-secondary-lt"><?= htmlspecialchars(ucfirst($selectedBillingRequest['paymongo_status'] ?? 'none'), ENT_QUOTES, 'UTF-8') ?></span></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="card-body">
                            <?php if ($canGenerateBillingDraft): ?>
                                <form action="actions/generate_billing_request.php" method="POST" class="mb-3">
                                    <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                    <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="mb-2">
                                        <label class="form-label" for="due_date">Billing Due Date</label>
                                        <input class="form-control" type="date" id="due_date" name="due_date" value="<?= htmlspecialchars($selectedBillingRequest['due_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm"><?= $selectedBillingRequest ? 'Update Billing Draft' : 'Generate Billing Draft' ?></button>
                                </form>
                            <?php else: ?>
                                <div class="alert alert-warning mb-3" role="alert">
                                    <div class="d-flex">
                                        <div><i class="ti ti-lock icon alert-icon"></i></div>
                                        <div><strong>Billing Draft Locked</strong><br>Approve the registration first before preparing billing, and stop billing changes once conversion is complete.</div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($selectedBillingRequest): ?>
                                <?php if ($canCreateCheckout): ?>
                                    <form action="actions/create_paymongo_checkout.php" method="POST" class="mb-3">
                                        <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                        <input type="hidden" name="billing_request_id" value="<?= (int) $selectedBillingRequest['billing_request_id'] ?>">
                                        <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary btn-sm">Create PayMongo Checkout</button>
                                            <?php if (!empty($selectedBillingRequest['paymongo_checkout_url'])): ?>
                                                <a href="<?= htmlspecialchars($selectedBillingRequest['paymongo_checkout_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">Open Checkout</a>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                <?php elseif (!empty($selectedBillingRequest['paymongo_checkout_url'])): ?>
                                    <div class="mb-3">
                                        <a href="<?= htmlspecialchars($selectedBillingRequest['paymongo_checkout_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">Open Checkout</a>
                                    </div>
                                <?php endif; ?>

                                <?php if ($canConvertTenant): ?>
                                    <form action="actions/convert_registration_to_tenant.php" method="POST" class="mb-3">
                                        <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                        <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="alert alert-info mb-2" role="alert">
                                            <div class="d-flex">
                                                <div><i class="ti ti-info-circle icon alert-icon"></i></div>
                                                <div>This activates the subscription on an existing owner workspace (or creates a new tenant for legacy registrations), enables plan features, and finalizes onboarding.</div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">Convert Paid Registration to Tenant</button>
                                    </form>
                                <?php elseif ($registrationStatus === 'converted'): ?>
                                    <div class="alert alert-success mb-3" role="alert">
                                        <div class="d-flex">
                                            <div><i class="ti ti-circle-check icon alert-icon"></i></div>
                                            <div><strong>Tenant Conversion Complete</strong><br>This registration has already been converted into a live tenant account.</div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($canUpdateBillingStatus): ?>
                                    <form action="actions/update_billing_status.php" method="POST" class="mb-3">
                                        <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                        <input type="hidden" name="billing_request_id" value="<?= (int) $selectedBillingRequest['billing_request_id'] ?>">
                                        <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="row g-2 mb-2">
                                            <div class="col-sm-6">
                                                <label class="form-label" for="billing_status">Billing Status</label>
                                                <select class="form-control" id="billing_status" name="billing_status" required>
                                                    <option value="draft"<?= ($selectedBillingRequest['billing_status'] === 'draft') ? ' selected' : '' ?>>Draft</option>
                                                    <option value="sent"<?= ($selectedBillingRequest['billing_status'] === 'sent') ? ' selected' : '' ?>>Sent</option>
                                                    <option value="paid"<?= ($selectedBillingRequest['billing_status'] === 'paid') ? ' selected' : '' ?>>Paid</option>
                                                    <option value="cancelled"<?= ($selectedBillingRequest['billing_status'] === 'cancelled') ? ' selected' : '' ?>>Cancelled</option>
                                                </select>
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label" for="payment_reference">Payment / External Reference</label>
                                                <input class="form-control" type="text" id="payment_reference" name="payment_reference" value="<?= htmlspecialchars($selectedBillingRequest['payment_reference'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">Update Billing Status</button>
                                    </form>

                                    <form action="actions/update_billing_verification.php" method="POST" class="mb-3">
                                        <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                        <input type="hidden" name="billing_request_id" value="<?= (int) $selectedBillingRequest['billing_request_id'] ?>">
                                        <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="mb-2">
                                            <label class="form-label" for="payment_reference_check_notes">Verification Notes</label>
                                            <textarea class="form-control" rows="3" id="payment_reference_check_notes" name="payment_reference_check_notes"><?= htmlspecialchars($selectedBillingRequest['payment_reference_check_notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                        </div>
                                        <div class="alert alert-info mb-2" role="alert">
                                            <div class="d-flex">
                                                <div><i class="ti ti-info-circle icon alert-icon"></i></div>
                                                <div>Review the NG reference format, confirm it is unique, and save the result into the billing record for audit tracking.</div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-secondary btn-sm">Save Reference Review</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>

                            <form action="actions/review_registration.php" method="POST">
                                <input type="hidden" name="superadmin_context" value="<?= htmlspecialchars($mechanixSuperadminContext, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="registration_id" value="<?= (int) $selectedRegistration['registration_id'] ?>">
                                <input type="hidden" name="registration_search" value="<?= htmlspecialchars($registrationSearch, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="registration_status_filter" value="<?= htmlspecialchars($registrationStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="registration_billing_status_filter" value="<?= htmlspecialchars($registrationBillingStatusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="mb-2">
                                    <label class="form-label" for="notes">Review Notes</label>
                                    <textarea class="form-control" rows="3" id="notes" name="notes"><?= htmlspecialchars($selectedRegistration['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php if ($canApproveRegistration): ?>
                                        <button type="submit" class="btn btn-primary btn-sm" name="decision" value="approve">Approve Registration</button>
                                    <?php endif; ?>
                                    <?php if ($canMarkBillingSent): ?>
                                        <button type="submit" class="btn btn-secondary btn-sm" name="decision" value="billing_sent">Mark Billing Sent</button>
                                    <?php endif; ?>
                                    <?php if ($canRejectRegistration): ?>
                                        <button type="submit" class="btn btn-secondary btn-sm" name="decision" value="reject">Reject Registration</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>

                        <div class="card-header">
                            <h3 class="card-title">Communication Activity</h3>
                        </div>
                        <div class="card-body pb-0">
                            <p class="text-muted small">System communication is tracked through email_logs, even when live email delivery is not configured yet.</p>
                        </div>
                        <?php if (!empty($selectedRegistrationEmailLogs)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($selectedRegistrationEmailLogs as $emailLog): ?>
                                    <?php $emailBadge = ($emailLog['send_status'] === 'sent') ? 'bg-green-lt text-green' : (($emailLog['send_status'] === 'failed') ? 'bg-red-lt text-red' : 'bg-orange-lt text-orange'); ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <div class="font-weight-medium"><?= htmlspecialchars($emailLog['subject'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small"><?= htmlspecialchars($emailLog['recipient_email'], ENT_QUOTES, 'UTF-8') ?> &middot; Type: <?= htmlspecialchars($emailLog['email_type'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small">Created: <?= htmlspecialchars($emailLog['created_at'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?> &middot; Sent: <?= htmlspecialchars($emailLog['sent_at'] ?: 'Pending', ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <div class="col-auto"><span class="badge <?= $emailBadge ?>"><?= htmlspecialchars(ucfirst($emailLog['send_status']), ENT_QUOTES, 'UTF-8') ?></span></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="card-body">
                                <div class="empty empty-sm">
                                    <p class="empty-title">No email logs</p>
                                    <p class="empty-subtitle text-muted">No email log activity has been recorded for this registration yet.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="card-body">
                            <p class="text-muted">Select a registration from the queue to review plan and add-on choices.</p>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

