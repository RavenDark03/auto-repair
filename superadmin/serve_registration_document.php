<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../includes/db.php';

requireSuperAdmin();

$registrationId = (int) ($_GET['registration_id'] ?? 0);
if ($registrationId <= 0) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$pdo = Database::getInstance();
$stmt = $pdo->prepare("
    SELECT owner_id_document_path
    FROM tenant_registrations
    WHERE registration_id = :registration_id
    LIMIT 1
");
$stmt->execute(['registration_id' => $registrationId]);
$relative = (string) ($stmt->fetchColumn() ?: '');

if ($relative === '' || strpos($relative, 'uploads/registrations/') !== 0) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$full = realpath(__DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $relative));
$base = realpath(__DIR__ . '/../uploads/registrations');

if ($full === false || $base === false || !is_file($full)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$fullNorm = str_replace('\\', '/', $full);
$baseNorm = rtrim(str_replace('\\', '/', $base), '/');
if ($fullNorm !== $baseNorm && strpos($fullNorm, $baseNorm . '/') !== 0) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
];
$mime = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="registration-' . $registrationId . '-id.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
readfile($full);
exit;
