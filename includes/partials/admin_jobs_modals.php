<?php
declare(strict_types=1);

if (!is_array($selectedJob) || !empty($jobSourceAppointmentLocked)) {
    return;
}
?>
<div class="modal fade" id="mechanixJobEditModal" tabindex="-1" aria-labelledby="mechanixJobEditModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mechanixJobEditModalTitle">Edit job details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2">
                <form action="actions/update_job.php" method="POST" class="feature-toggle-form">
                    <input type="hidden" name="job_id" value="<?= (int) $selectedJob['job_id'] ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="jm_mechanic_id">Assigned mechanic</label>
                            <select class="form-control" id="jm_mechanic_id" name="mechanic_id">
                                <option value="0">Unassigned</option>
                                <?php foreach ($mechanicOptions as $mechanic): ?>
                                    <option value="<?= (int) $mechanic['user_id'] ?>"<?= $selectedMechanicId === (int) $mechanic['user_id'] ? ' selected' : '' ?>>
                                        <?= htmlspecialchars((string) $mechanic['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="jm_job_status">Job status</label>
                            <select class="form-control" id="jm_job_status" name="status" required>
                                <?php foreach (getJobStatusOptions() as $statusValue => $statusLabel): ?>
                                    <option value="<?= htmlspecialchars((string) $statusValue, ENT_QUOTES, 'UTF-8') ?>"<?= $selectedStatus === $statusValue ? ' selected' : '' ?>>
                                        <?= htmlspecialchars((string) $statusLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="jm_priority">Priority</label>
                            <select class="form-control" id="jm_priority" name="priority" required>
                                <?php foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $priorityValue => $priorityLabel): ?>
                                    <option value="<?= htmlspecialchars((string) $priorityValue, ENT_QUOTES, 'UTF-8') ?>"<?= $selectedPriority === $priorityValue ? ' selected' : '' ?>>
                                        <?= htmlspecialchars((string) $priorityLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="jm_issue_concern">Vehicle issue / concern</label>
                        <textarea class="form-control form-textarea" id="jm_issue_concern" name="issue_concern" rows="3"><?= htmlspecialchars((string) $selectedIssueConcern, ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="jm_description">Job description</label>
                        <textarea class="form-control form-textarea" id="jm_description" name="description" rows="3"><?= htmlspecialchars((string) $selectedDescription, ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="jm_customer_visible_notes">Customer-visible notes</label>
                        <textarea class="form-control form-textarea" id="jm_customer_visible_notes" name="customer_visible_notes" rows="2"><?= htmlspecialchars((string) $selectedCustomerVisibleNotes, ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="jm_progress_note">Internal progress note</label>
                        <textarea class="form-control form-textarea" id="jm_progress_note" name="progress_note" rows="2" placeholder="Optional note for this update"></textarea>
                    </div>

                    <div class="modal-footer px-0 pb-0 pt-3 border-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save job changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
