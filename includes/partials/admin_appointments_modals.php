<?php
declare(strict_types=1);

/** @var list<array<string,mixed>> $customerOptions */
/** @var list<array<string,mixed>> $vehicleOptions */
/** @var list<array<string,mixed>> $mechanicOptions */
/** @var array<string,mixed> $oldInput */
/** @var ?array<string,mixed> $selectedAppointment */

$canAppt = !empty($customerOptions) && !empty($vehicleOptions);
?>
<div class="modal fade" id="mechanixAppointmentModalAdd" tabindex="-1" aria-labelledby="mechanixAppointmentModalAddTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mechanixAppointmentModalAddTitle">Add appointment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (!$canAppt): ?>
                    <p class="text-muted mb-0">Add at least one customer and one vehicle before scheduling.</p>
                <?php else: ?>
                    <form action="actions/create_appointment.php" method="POST" class="feature-toggle-form" data-mechanix-appt-form>
                        <div data-mechanix-appt-sync>
                            <div class="form-group mb-3">
                                <label class="form-label" for="apm_customer_id">Customer</label>
                                <select class="form-control mechanix-appt-customer" id="apm_customer_id" name="customer_id" required>
                                    <option value="">Select customer</option>
                                    <?php foreach ($customerOptions as $customer): ?>
                                        <option
                                            value="<?= (int) $customer['customer_id'] ?>"
                                            <?= $selectedCustomerValue === (int) $customer['customer_id'] ? ' selected' : '' ?>
                                            <?= ($customer['status'] !== 'active' && $selectedCustomerValue !== (int) $customer['customer_id']) ? ' disabled' : '' ?>
                                        >
                                            <?= htmlspecialchars((string) $customer['name'], ENT_QUOTES, 'UTF-8') ?><?= $customer['status'] !== 'active' ? ' (Inactive)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group mb-3">
                                <label class="form-label" for="apm_vehicle_id">Vehicle</label>
                                <select class="form-control mechanix-appt-vehicle" id="apm_vehicle_id" name="vehicle_id" data-selected-vehicle-id="<?= (int) $selectedVehicleValue ?>" required>
                                    <option value="">Select vehicle</option>
                                    <?php foreach ($vehicleOptions as $vehicle): ?>
                                        <option
                                            value="<?= (int) $vehicle['vehicle_id'] ?>"
                                            data-customer-id="<?= (int) $vehicle['customer_id'] ?>"
                                            <?= $selectedVehicleValue === (int) $vehicle['vehicle_id'] ? ' selected' : '' ?>
                                            <?= ($vehicle['status'] !== 'active' && $selectedVehicleValue !== (int) $vehicle['vehicle_id']) ? ' disabled' : '' ?>
                                        >
                                            <?= htmlspecialchars(appointmentVehicleLabel($vehicle), ENT_QUOTES, 'UTF-8') ?>
                                            <?= $vehicle['status'] !== 'active' ? ' (Inactive)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label" for="apm_appointment_date">Appointment date</label>
                            <input class="form-control" type="date" id="apm_appointment_date" name="appointment_date" value="<?= htmlspecialchars((string) ($oldInput['appointment_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label" for="apm_mechanic_id">Assigned mechanic</label>
                            <select class="form-control" id="apm_mechanic_id" name="mechanic_id">
                                <option value="0">Unassigned</option>
                                <?php foreach ($mechanicOptions as $mechanic): ?>
                                    <option value="<?= (int) $mechanic['user_id'] ?>"<?= $selectedMechanicValue === (int) $mechanic['user_id'] ? ' selected' : '' ?>>
                                        <?= htmlspecialchars((string) $mechanic['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label" for="apm_concern">Vehicle issue / concern</label>
                            <textarea class="form-control form-textarea" id="apm_concern" name="concern" rows="3"><?= htmlspecialchars((string) ($oldInput['concern'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="modal-footer px-0 pb-0 pt-3 border-0">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Create appointment</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (is_array($selectedAppointment)): ?>
<div class="modal fade" id="mechanixAppointmentModalEdit" tabindex="-1" aria-labelledby="mechanixAppointmentModalEditTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mechanixAppointmentModalEditTitle">Edit appointment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (!$canAppt): ?>
                    <p class="text-muted mb-0">Missing customer or vehicle records.</p>
                <?php else: ?>
                    <form action="actions/update_appointment.php" method="POST" class="feature-toggle-form" data-mechanix-appt-form>
                        <input type="hidden" name="appointment_id" value="<?= (int) $selectedAppointment['appointment_id'] ?>">
                        <div data-mechanix-appt-sync>
                            <div class="form-group mb-3">
                                <label class="form-label" for="ape_customer_id">Customer</label>
                                <select class="form-control mechanix-appt-customer" id="ape_customer_id" name="customer_id" required>
                                    <option value="">Select customer</option>
                                    <?php foreach ($customerOptions as $customer): ?>
                                        <option
                                            value="<?= (int) $customer['customer_id'] ?>"
                                            <?= $selectedCustomerValue === (int) $customer['customer_id'] ? ' selected' : '' ?>
                                            <?= ($customer['status'] !== 'active' && $selectedCustomerValue !== (int) $customer['customer_id']) ? ' disabled' : '' ?>
                                        >
                                            <?= htmlspecialchars((string) $customer['name'], ENT_QUOTES, 'UTF-8') ?><?= $customer['status'] !== 'active' ? ' (Inactive)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group mb-3">
                                <label class="form-label" for="ape_vehicle_id">Vehicle</label>
                                <select class="form-control mechanix-appt-vehicle" id="ape_vehicle_id" name="vehicle_id" data-selected-vehicle-id="<?= (int) $selectedVehicleValue ?>" required>
                                    <option value="">Select vehicle</option>
                                    <?php foreach ($vehicleOptions as $vehicle): ?>
                                        <option
                                            value="<?= (int) $vehicle['vehicle_id'] ?>"
                                            data-customer-id="<?= (int) $vehicle['customer_id'] ?>"
                                            <?= $selectedVehicleValue === (int) $vehicle['vehicle_id'] ? ' selected' : '' ?>
                                            <?= ($vehicle['status'] !== 'active' && $selectedVehicleValue !== (int) $vehicle['vehicle_id']) ? ' disabled' : '' ?>
                                        >
                                            <?= htmlspecialchars(appointmentVehicleLabel($vehicle), ENT_QUOTES, 'UTF-8') ?>
                                            <?= $vehicle['status'] !== 'active' ? ' (Inactive)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label" for="ape_appointment_date">Appointment date</label>
                            <input class="form-control" type="date" id="ape_appointment_date" name="appointment_date" value="<?= htmlspecialchars((string) ($oldInput['appointment_date'] ?? ($selectedAppointment['appointment_date'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label" for="ape_mechanic_id">Assigned mechanic</label>
                            <select class="form-control" id="ape_mechanic_id" name="mechanic_id">
                                <option value="0">Unassigned</option>
                                <?php foreach ($mechanicOptions as $mechanic): ?>
                                    <option value="<?= (int) $mechanic['user_id'] ?>"<?= $selectedMechanicValue === (int) $mechanic['user_id'] ? ' selected' : '' ?>>
                                        <?= htmlspecialchars((string) $mechanic['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label" for="ape_concern">Vehicle issue / concern</label>
                            <textarea class="form-control form-textarea" id="ape_concern" name="concern" rows="3"><?= htmlspecialchars((string) ($oldInput['concern'] ?? ($selectedAppointment['concern'] ?? '')), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label" for="ape_appointment_status">Status</label>
                            <?php if ($approvedAppointmentLocked): ?>
                                <input type="hidden" name="status" value="approved">
                                <select class="form-control" id="ape_appointment_status" disabled>
                                    <option selected>Approved</option>
                                </select>
                            <?php else: ?>
                                <select class="form-control" id="ape_appointment_status" name="status" required>
                                    <option value="pending"<?= $selectedAppointmentStatus === 'pending' ? ' selected' : '' ?>>Pending</option>
                                    <option value="approved"<?= $selectedAppointmentStatus === 'approved' ? ' selected' : '' ?>>Approved</option>
                                    <option value="cancelled"<?= $selectedAppointmentStatus === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label" for="ape_cancellation_reason">Cancellation reason</label>
                            <textarea class="form-control form-textarea" id="ape_cancellation_reason" name="cancellation_reason" rows="2"><?= htmlspecialchars((string) ($oldInput['cancellation_reason'] ?? ($selectedAppointment['cancellation_reason'] ?? '')), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="modal-footer px-0 pb-0 pt-3 border-0">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save changes</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
