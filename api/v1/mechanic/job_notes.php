<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/bearer.php';
require_once __DIR__ . '/../includes/tenant_features_api.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    api_error('method_not_allowed', 'Use GET or POST.', 405);
}

$pdo = Database::getInstance();
$ctx = api_require_bearer($pdo);

if (($ctx['role'] ?? '') !== 'mechanic') {
    api_error('forbidden', 'Mechanic role required.', 403);
}

if (!api_tenant_has_feature($pdo, $ctx['tenant_id'], 'jobs')) {
    api_error('feature_disabled', 'Jobs module is not enabled for this tenant.', 403);
}

$body = $method === 'POST' ? api_input_json() : [];
$jobId = (int) ($body['job_id'] ?? $body['jobId'] ?? $_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    api_error('validation_error', 'job_id is required.', 422);
}

$jobStmt = $pdo->prepare('
    SELECT job_id
    FROM jobs
    WHERE tenant_id = :tid
      AND job_id = :jid
      AND mechanic_id = :mid
    LIMIT 1
');
$jobStmt->execute(['tid' => $ctx['tenant_id'], 'jid' => $jobId, 'mid' => $ctx['user_id']]);
if (!$jobStmt->fetch()) {
    api_error('not_found', 'Job not found or not assigned to you.', 404);
}

if ($method === 'POST') {
    $note = trim((string) ($body['note'] ?? ''));
    $visible = !empty($body['isCustomerVisible']) || !empty($body['customerVisible']);
    if ($note === '') {
        api_error('validation_error', 'note is required.', 422);
    }

    $ins = $pdo->prepare('
        INSERT INTO job_notes (tenant_id, job_id, user_id, note_type, note, is_customer_visible)
        VALUES (:tid, :jid, :uid, \'mechanic\', :note, :visible)
    ');
    $ins->execute([
        'tid' => $ctx['tenant_id'],
        'jid' => $jobId,
        'uid' => $ctx['user_id'],
        'note' => $note,
        'visible' => $visible ? 1 : 0,
    ]);

    api_json(['ok' => true, 'id' => (string) $pdo->lastInsertId()], 201);
}

$notesStmt = $pdo->prepare('
    SELECT note_id, note_type, note, is_customer_visible, created_at
    FROM job_notes
    WHERE tenant_id = :tid
      AND job_id = :jid
    ORDER BY created_at DESC, note_id DESC
');
$notesStmt->execute(['tid' => $ctx['tenant_id'], 'jid' => $jobId]);

$items = array_map(static function (array $note): array {
    return [
        'id' => (string) $note['note_id'],
        'type' => (string) $note['note_type'],
        'note' => (string) $note['note'],
        'isCustomerVisible' => (int) $note['is_customer_visible'] === 1,
        'createdAt' => (string) $note['created_at'],
    ];
}, $notesStmt->fetchAll(PDO::FETCH_ASSOC));

api_json(['ok' => true, 'items' => $items]);
