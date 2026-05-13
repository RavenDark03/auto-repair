<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/bearer.php';
require_once __DIR__ . '/../includes/tenant_features_api.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    api_error('method_not_allowed', 'Use GET.', 405);
}

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'mechanic') {
    api_error('forbidden', 'Mechanic role required.', 403);
}

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'jobs')) {
    api_error('feature_disabled', 'Jobs module is not enabled for this tenant.', 403);
}

$st = $pdo->prepare('
    SELECT
        (SELECT COUNT(*) FROM jobs WHERE tenant_id = :tid AND mechanic_id = :mid) AS assigned_jobs,
        (SELECT COUNT(*) FROM jobs WHERE tenant_id = :tid AND mechanic_id = :mid AND status IN (\'pending_inspection\', \'in_repair\', \'waiting_for_parts\', \'ongoing\')) AS ongoing_jobs,
        (SELECT COUNT(*) FROM jobs WHERE tenant_id = :tid AND mechanic_id = :mid AND status = \'completed\' AND DATE(COALESCE(completed_at, updated_at)) = CURDATE()) AS completed_today,
        (SELECT COUNT(*)
         FROM appointments a
         INNER JOIN jobs j ON j.appointment_id = a.appointment_id AND j.tenant_id = a.tenant_id
         WHERE a.tenant_id = :tid
           AND j.mechanic_id = :mid
           AND a.appointment_date >= CURDATE()
           AND a.status = \'approved\') AS scheduled_appointments
');
$st->execute(['tid' => $ctx['tenant_id'], 'mid' => $ctx['user_id']]);
$row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

api_json([
    'ok' => true,
    'item' => [
        'assignedJobs' => (int) ($row['assigned_jobs'] ?? 0),
        'scheduledAppointments' => (int) ($row['scheduled_appointments'] ?? 0),
        'ongoingJobs' => (int) ($row['ongoing_jobs'] ?? 0),
        'completedToday' => (int) ($row['completed_today'] ?? 0),
    ],
]);
