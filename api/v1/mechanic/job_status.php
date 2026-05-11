<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/bearer.php';
require_once __DIR__ . '/../includes/tenant_features_api.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_error('method_not_allowed', 'Use POST.', 405);
}

$body = api_input_json();
$jobId = (int) ($body['job_id'] ?? $body['jobId'] ?? 0);
$statusRaw = (string) ($body['status'] ?? '');

$statusMap = [
    'ongoing' => 'ongoing',
    'completed' => 'completed',
    'Ongoing' => 'ongoing',
    'Completed' => 'completed',
];
$status = $statusMap[$statusRaw] ?? '';

if ($jobId <= 0 || !in_array($status, ['ongoing', 'completed'], true)) {
    api_error('validation_error', 'job_id and status (ongoing|completed) are required.', 422);
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

    if (($job['status'] ?? '') === $status) {
        api_json(['ok' => true, 'message' => 'Already in requested status.']);
    }

    if (!empty($job['invoice_id'])) {
        api_error('invoice_exists', 'Job status cannot be changed after an invoice exists for this job.', 409);
    }

    $upd = $pdo->prepare('
        UPDATE jobs
        SET status = :status
        WHERE job_id = :job_id
          AND tenant_id = :tenant_id
          AND mechanic_id = :mechanic_id
    ');
    $upd->execute([
        'status' => $status,
        'job_id' => $jobId,
        'tenant_id' => $tenantId,
        'mechanic_id' => $userId,
    ]);

    api_json(['ok' => true]);
} catch (PDOException $e) {
    api_error('server_error', 'Update failed.', 500);
}
