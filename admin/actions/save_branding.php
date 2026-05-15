<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/mechanix_urls.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/tenant_branding.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../branding.php');
    exit;
}

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    header('Location: ../branding.php');
    exit;
}

$iosRaw = trim((string) ($_POST['mobile_app_ios_url'] ?? ''));
$androidRaw = trim((string) ($_POST['mobile_app_android_url'] ?? ''));

$publicSlugNorm = tenant_branding_normalize_slug(trim((string) ($_POST['public_slug'] ?? '')));

$primary = trim((string) ($_POST['primary_color'] ?? ''));
$accent = trim((string) ($_POST['accent_color'] ?? ''));
$background = trim((string) ($_POST['background_color'] ?? ''));
$textCol = trim((string) ($_POST['text_color'] ?? ''));

$removeLogo = isset($_POST['remove_logo']);

if ($publicSlugNorm === null) {
    $_SESSION['branding_error'] = 'Public link slug must be 2–63 characters: lowercase letters, numbers, and hyphens only; start with a letter or number.';
    header('Location: ../branding.php');
    exit;
}

foreach (
    ['Primary color' => $primary, 'Accent color' => $accent, 'Background color' => $background, 'Text color' => $textCol] as $label => $hex
) {
    if (!tenant_branding_is_valid_hex_color($hex)) {
        $_SESSION['branding_error'] = $label . ' must be a 6-digit hex value like #f5a524.';
        header('Location: ../branding.php');
        exit;
    }
}

$validateUrl = static function (string $url): bool {
    if ($url === '') {
        return true;
    }

    if (strlen($url) > 500) {
        return false;
    }

    $filtered = filter_var($url, FILTER_VALIDATE_URL);

    return $filtered !== false && (str_starts_with($filtered, 'https://') || str_starts_with($filtered, 'http://'));
};

if (!$validateUrl($iosRaw) || !$validateUrl($androidRaw)) {
    $_SESSION['branding_error'] = 'Store links must be valid http(s) URLs (max 500 characters).';
    header('Location: ../branding.php');
    exit;
}

$names = $_POST['service_name'] ?? [];
$descriptions = $_POST['service_description'] ?? [];

if (!is_array($names) || !is_array($descriptions)) {
    $_SESSION['branding_error'] = 'Invalid services payload.';
    header('Location: ../branding.php');
    exit;
}

ksort($names, SORT_NUMERIC);
ksort($descriptions, SORT_NUMERIC);

$indices = array_unique(array_merge(array_keys($names), array_keys($descriptions)));
sort($indices, SORT_NUMERIC);

$pairs = [];
foreach ($indices as $i) {
    if (count($pairs) >= 30) {
        break;
    }

    $name = trim((string) ($names[$i] ?? ''));
    if ($name === '') {
        continue;
    }

    $desc = trim((string) ($descriptions[$i] ?? ''));
    if (strlen($name) > 150) {
        $_SESSION['branding_error'] = 'Each service name must be 150 characters or fewer.';
        header('Location: ../branding.php');
        exit;
    }
    if (strlen($desc) > 500) {
        $_SESSION['branding_error'] = 'Each service description must be 500 characters or fewer.';
        header('Location: ../branding.php');
        exit;
    }

    $pairs[] = [$name, $desc];
}

try {
    $pdo = Database::getInstance();

    tenant_branding_ensure_row($pdo, $tenantId);

    if (!tenant_branding_slug_available_for_tenant($pdo, $publicSlugNorm, $tenantId)) {
        $_SESSION['branding_error'] = 'That public link slug is already used by another shop. Pick a different slug.';
        header('Location: ../branding.php');
        exit;
    }

    $uploadLogo = tenant_branding_process_logo_upload($tenantId);
    if (isset($uploadLogo['error'])) {
        $_SESSION['branding_error'] = $uploadLogo['error'];
        header('Location: ../branding.php');
        exit;
    }

    $stmtCur = $pdo->prepare('SELECT logo_path FROM tenant_branding WHERE tenant_id = :tenant_id LIMIT 1');
    $stmtCur->execute(['tenant_id' => $tenantId]);
    $logoPathFinal = $stmtCur->fetchColumn();
    $logoPathFinal = $logoPathFinal !== false && $logoPathFinal !== null ? (string) $logoPathFinal : null;

    if ($removeLogo) {
        tenant_branding_delete_logo_file($logoPathFinal);
        $logoPathFinal = null;
    }

    if (isset($uploadLogo['path'])) {
        if ($logoPathFinal !== null && $logoPathFinal !== $uploadLogo['path']) {
            tenant_branding_delete_logo_file($logoPathFinal);
        }
        $logoPathFinal = $uploadLogo['path'];
    }

    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE tenants
        SET mobile_app_ios_url = :ios,
            mobile_app_android_url = :android
        WHERE tenant_id = :tenant_id
    ")->execute([
        'ios' => $iosRaw !== '' ? $iosRaw : null,
        'android' => $androidRaw !== '' ? $androidRaw : null,
        'tenant_id' => $tenantId,
    ]);

    $pdo->prepare('
        UPDATE tenant_branding
        SET public_slug = :public_slug,
            logo_path = :logo_path,
            primary_color = :primary_color,
            accent_color = :accent_color,
            background_color = :background_color,
            text_color = :text_color
        WHERE tenant_id = :tenant_id
    ')->execute([
        'public_slug' => $publicSlugNorm,
        'logo_path' => $logoPathFinal,
        'primary_color' => strtolower($primary),
        'accent_color' => strtolower($accent),
        'background_color' => strtolower($background),
        'text_color' => strtolower($textCol),
        'tenant_id' => $tenantId,
    ]);

    $pdo->prepare('DELETE FROM tenant_branding_services WHERE tenant_id = :tenant_id')
        ->execute(['tenant_id' => $tenantId]);

    $insert = $pdo->prepare('
        INSERT INTO tenant_branding_services (tenant_id, service_name, service_description, sort_order)
        VALUES (:tenant_id, :service_name, :service_description, :sort_order)
    ');

    foreach ($pairs as $order => $pair) {
        $insert->execute([
            'tenant_id' => $tenantId,
            'service_name' => $pair[0],
            'service_description' => $pair[1] !== '' ? $pair[1] : null,
            'sort_order' => $order,
        ]);
    }

    $pdo->commit();
    $_SESSION['branding_success'] = 'Branding saved.';
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['branding_error'] = 'Branding could not be saved: ' . $e->getMessage();
}

header('Location: ../branding.php');
exit;
