<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/bearer.php';
require_once __DIR__ . '/../includes/tenant_features_api.php';
require_once __DIR__ . '/../includes/job_format.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_error('method_not_allowed', 'Use POST.', 405);
}

$body = api_input_json();
$jobId = (int) ($body['job_id'] ?? $body['jobId'] ?? 0);
$statusRaw = (string) ($body['status'] ?? '');
$progressNote = trim((string) ($body['note'] ?? $body['progressNote'] ?? ''));
$status = api_job_status_db($statusRaw);

if ($jobId <= 0 || !in_array($status, ['pending_inspection', 'in_repair', 'waiting_for_parts', 'completed'], true)) {
    api_error('validation_error', 'job_id and a valid repair status are required.', 422);
}

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'mechanic') {
    api_error('forbidden', 'Mechanic role required.', 403);
}

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'jobs')) {
    api_error('feature_disabled', 'Jobs module is not enabled for this tenant.', 403);
}

$tenantId = $ctx['tenant_id'];
$userId = $ctx['user_id'];

try {
    $jobStmt = $pdo->prepare('
        SELECT j.job_id, j.status, j.mechanic_id, i.invoice_id, a.status AS appointment_status
        FROM jobs j
        INNER JOIN appointments a
            ON a.appointment_id = j.appointment_id
           AND a.tenant_id = j.tenant_id
        LEFT JOIN invoices i
            ON i.job_id = j.job_id
           AND i.tenant_id = j.tenant_id
        WHERE j.job_id = :job_id
          AND j.tenant_id = :tenant_id
          AND j.mechanic_id = :mechanic_id
        LIMIT 1
    ');
    $jobStmt->execute([
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
        'mechanic_id' => $userId,
    ]);
    $job = $jobStmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        api_error('not_found', 'Job not found or not assigned to you.', 404);
    }

    if (($job['appointment_status'] ?? '') !== 'approved') {
        api_error('appointment_not_approved', 'Job status changes are blocked until the appointment is approved.', 409);
    }

    if (!empty($job['invoice_id'])) {
        api_error('invoice_exists', 'Job status cannot be changed after an invoice exists for this job.', 409);
    }

    if (($job['status'] ?? '') === $status && $progressNote === '') {
        api_json(['ok' => true, 'message' => 'Already in requested status.']);
    }

    if (($job['status'] ?? '') !== $status) {
        $upd = $pdo->prepare('
            UPDATE jobs
            SET status = :status,
                completed_at = CASE WHEN :status_completed = \'completed\' THEN COALESCE(completed_at, NOW()) ELSE completed_at END,
                ready_for_invoice_at = CASE WHEN :status_ready = \'completed\' THEN COALESCE(ready_for_invoice_at, NOW()) ELSE ready_for_invoice_at END
            WHERE job_id = :job_id
              AND tenant_id = :tenant_id
              AND mechanic_id = :mechanic_id
        ');
        $upd->execute([
            'status' => $status,
            'status_completed' => $status,
            'status_ready' => $status,
            'job_id' => $jobId,
            'tenant_id' => $tenantId,
            'mechanic_id' => $userId,
        ]);
    }

    if ($progressNote !== '') {
        $note = $pdo->prepare('
            INSERT INTO job_notes (tenant_id, job_id, user_id, note_type, note, is_customer_visible)
            VALUES (:tenant_id, :job_id, :user_id, \'mechanic\', :note, 1)
        ');
        $note->execute([
            'tenant_id' => $tenantId,
            'job_id' => $jobId,
            'user_id' => $userId,
            'note' => $progressNote,
        ]);
    }

    api_json(['ok' => true]);
} catch (PDOException $e) {
    api_error('server_error', 'Update failed.', 500);
}
