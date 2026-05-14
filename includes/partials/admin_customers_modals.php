<?php
declare(strict_types=1);

/** @var array<string, mixed> $oldInput */
/** @var ?array<string, mixed> $selectedCustomer */

$addName = htmlspecialchars((string) ($oldInput['name'] ?? ''), ENT_QUOTES, 'UTF-8');
$addContact = htmlspecialchars((string) ($oldInput['contact'] ?? ''), ENT_QUOTES, 'UTF-8');
$addEmail = htmlspecialchars((string) ($oldInput['email'] ?? ''), ENT_QUOTES, 'UTF-8');
$addAddress = htmlspecialchars((string) ($oldInput['address'] ?? ''), ENT_QUOTES, 'UTF-8');

$editSel = is_array($selectedCustomer) ? $selectedCustomer : null;
?>
<!-- Add customer -->
<div class="modal fade" id="mechanixCustomerModalAdd" tabindex="-1" aria-labelledby="mechanixCustomerModalAddTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mechanixCustomerModalAddTitle">Add customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Create a new customer record for service intake and future vehicle tracking.</p>
                <form action="actions/create_customer.php" method="POST" class="feature-toggle-form">
                    <div class="form-group mb-3">
                        <label class="form-label" for="cac_name">Customer name</label>
                        <input class="form-control" type="text" id="cac_name" name="name" value="<?= $addName ?>" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="cac_contact">Contact number</label>
                            <input class="form-control" type="text" id="cac_contact" name="contact" value="<?= $addContact ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cac_email">Email</label>
                            <input class="form-control" type="email" id="cac_email" name="email" value="<?= $addEmail ?>">
                        </div>
                    </div>
                    <div class="form-group mt-3 mb-0">
                        <label class="form-label" for="cac_address">Address</label>
                        <textarea class="form-control form-textarea" id="cac_address" name="address" rows="3"><?= $addAddress ?></textarea>
                    </div>
                    <div class="modal-footer px-0 pb-0 pt-3 border-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create customer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($editSel !== null): ?>
<?php
$eName = htmlspecialchars((string) ($oldInput['name'] ?? $editSel['name'] ?? ''), ENT_QUOTES, 'UTF-8');
$eContact = htmlspecialchars((string) ($oldInput['contact'] ?? $editSel['contact'] ?? ''), ENT_QUOTES, 'UTF-8');
$eEmail = htmlspecialchars((string) ($oldInput['email'] ?? $editSel['email'] ?? ''), ENT_QUOTES, 'UTF-8');
$eAddress = htmlspecialchars((string) ($oldInput['address'] ?? $editSel['address'] ?? ''), ENT_QUOTES, 'UTF-8');
$eStatus = (string) ($oldInput['status'] ?? $editSel['status'] ?? 'active');
?>
<div class="modal fade" id="mechanixCustomerModalEdit" tabindex="-1" aria-labelledby="mechanixCustomerModalEditTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mechanixCustomerModalEditTitle">Edit customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Update this customer profile without leaving the tenant workspace.</p>
                <form action="actions/update_customer.php" method="POST" class="feature-toggle-form">
                    <input type="hidden" name="customer_id" value="<?= (int) $editSel['customer_id'] ?>">
                    <div class="form-group mb-3">
                        <label class="form-label" for="cec_name">Customer name</label>
                        <input class="form-control" type="text" id="cec_name" name="name" value="<?= $eName ?>" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="cec_contact">Contact number</label>
                            <input class="form-control" type="text" id="cec_contact" name="contact" value="<?= $eContact ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cec_email">Email</label>
                            <input class="form-control" type="email" id="cec_email" name="email" value="<?= $eEmail ?>">
                        </div>
                    </div>
                    <div class="form-group mt-3">
                        <label class="form-label" for="cec_address">Address</label>
                        <textarea class="form-control form-textarea" id="cec_address" name="address" rows="3"><?= $eAddress ?></textarea>
                    </div>
                    <div class="form-group mt-3 mb-0">
                        <label class="form-label" for="cec_status">Status</label>
                        <select class="form-control" id="cec_status" name="status" required>
                            <option value="active"<?= $eStatus === 'active' ? ' selected' : '' ?>>Active</option>
                            <option value="inactive"<?= $eStatus === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="modal-footer px-0 pb-0 pt-3 border-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
