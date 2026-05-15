<?php
declare(strict_types=1);

require_once __DIR__ . '/mechanix_urls.php';

/**
 * Tenant public branding: slug, logo path, colors; helpers for admin + public routes.
 */

const TENANT_BRANDING_LOGO_MAX_BYTES = 1048576;

function tenant_branding_default_colors(): array
{
    return [
        'primary_color' => '#f5a524',
        'accent_color' => '#ffc04a',
        'background_color' => '#f8f9fa',
        'text_color' => '#1a1a1a',
    ];
}

function tenant_branding_is_valid_hex_color(string $hex): bool
{
    return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $hex);
}

/**
 * Raw slug from owner input: lowercase, allowed chars only, length 2–63, starts with letter or digit.
 */
function tenant_branding_normalize_slug(string $raw): ?string
{
    $s = strtolower(trim($raw));
    $s = preg_replace('/[^a-z0-9-]+/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    $s = trim($s, '-');

    if (strlen($s) < 2 || strlen($s) > 63) {
        return null;
    }

    if (!preg_match('/^[a-z0-9]/', $s)) {
        return null;
    }

    return $s;
}

function tenant_branding_slug_candidate_from_business_name(string $businessName): string
{
    $base = tenant_branding_normalize_slug(str_replace([' ', '_'], '-', $businessName));
    if ($base !== null) {
        return $base;
    }

    $fallback = tenant_branding_normalize_slug(preg_replace('/[^a-zA-Z0-9]+/', '-', $businessName));
    if ($fallback !== null) {
        return $fallback;
    }

    return 'shop';
}

function tenant_branding_allocate_unique_slug(PDO $pdo, string $desiredBase, int $tenantId): string
{
    $desiredBase = tenant_branding_normalize_slug($desiredBase) ?? 'shop';
    $candidate = substr($desiredBase, 0, 63);
    $n = 0;

    while (true) {
        $stmt = $pdo->prepare('
            SELECT tenant_id
            FROM tenant_branding
            WHERE public_slug = :slug
            LIMIT 1
        ');
        $stmt->execute(['slug' => $candidate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int) $row['tenant_id'] === $tenantId) {
            return $candidate;
        }

        $n++;
        $suffix = '-' . $n;
        $candidate = substr($desiredBase, 0, 63 - strlen($suffix)) . $suffix;
        if (strlen($candidate) < 2) {
            $candidate = 't' . $suffix;
        }
    }
}

function tenant_branding_uploads_dir_for(int $tenantId): string
{
    return dirname(__DIR__) . '/uploads/branding/' . $tenantId;
}

/**
 * Web path like uploads/branding/5/logo.png (leading slash optional).
 */
function tenant_branding_logo_public_href(?string $logoPath): ?string
{
    if ($logoPath === null || trim($logoPath) === '') {
        return null;
    }

    $path = '/' . ltrim(str_replace('\\', '/', $logoPath), '/');
    $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';

    return ($base === '' ? '' : $base) . $path;
}

function tenant_branding_delete_logo_file(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }

    $full = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    if (is_file($full)) {
        @unlink($full);
    }
}

/**
 * Ensure one branding row exists with unique slug derived from business name.
 */
function tenant_branding_ensure_row(PDO $pdo, int $tenantId): void
{
    $stmt = $pdo->prepare('SELECT tenant_id FROM tenant_branding WHERE tenant_id = :tenant_id LIMIT 1');
    $stmt->execute(['tenant_id' => $tenantId]);
    if ($stmt->fetch()) {
        return;
    }

    $tn = $pdo->prepare('SELECT business_name FROM tenants WHERE tenant_id = :tenant_id LIMIT 1');
    $tn->execute(['tenant_id' => $tenantId]);
    $businessName = (string) ($tn->fetchColumn() ?: 'Workspace');

    $base = tenant_branding_slug_candidate_from_business_name($businessName);
    $slug = tenant_branding_allocate_unique_slug($pdo, $base, $tenantId);
    $defaults = tenant_branding_default_colors();

    $ins = $pdo->prepare('
        INSERT INTO tenant_branding (
            tenant_id, public_slug, logo_path,
            primary_color, accent_color, background_color, text_color
        ) VALUES (
            :tenant_id, :public_slug, NULL,
            :primary_color, :accent_color, :background_color, :text_color
        )
    ');
    $ins->execute([
        'tenant_id' => $tenantId,
        'public_slug' => $slug,
        'primary_color' => $defaults['primary_color'],
        'accent_color' => $defaults['accent_color'],
        'background_color' => $defaults['background_color'],
        'text_color' => $defaults['text_color'],
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function tenant_branding_row_for_tenant(PDO $pdo, int $tenantId): ?array
{
    tenant_branding_ensure_row($pdo, $tenantId);

    $stmt = $pdo->prepare('SELECT * FROM tenant_branding WHERE tenant_id = :tenant_id LIMIT 1');
    $stmt->execute(['tenant_id' => $tenantId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Public landing / branded login: active tenant only.
 *
 * @return array<string, mixed>|null
 */
function tenant_branding_resolve_public(PDO $pdo, string $slugRaw): ?array
{
    $slug = tenant_branding_normalize_slug($slugRaw);
    if ($slug === null) {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT
            tb.tenant_id,
            tb.public_slug,
            tb.logo_path,
            tb.primary_color,
            tb.accent_color,
            tb.background_color,
            tb.text_color,
            t.business_name,
            t.status AS tenant_status,
            t.mobile_app_ios_url,
            t.mobile_app_android_url
        FROM tenant_branding tb
        INNER JOIN tenants t ON t.tenant_id = tb.tenant_id
        WHERE tb.public_slug = :slug
          AND t.status IN (\'active\', \'pending_payment\')
        LIMIT 1
    ');
    $stmt->execute(['slug' => $slug]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return list<array{service_name: string, service_description: ?string}>
 */
function tenant_branding_services_for(PDO $pdo, int $tenantId): array
{
    $stmt = $pdo->prepare('
        SELECT service_name, service_description
        FROM tenant_branding_services
        WHERE tenant_id = :tenant_id
        ORDER BY sort_order ASC, tenant_branding_service_id ASC
    ');
    $stmt->execute(['tenant_id' => $tenantId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_values(array_filter(array_map(static function ($r) {
        $name = trim((string) ($r['service_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        return [
            'service_name' => $name,
            'service_description' => isset($r['service_description']) && $r['service_description'] !== ''
                ? (string) $r['service_description']
                : null,
        ];
    }, $rows)));
}

/**
 * Process uploaded logo; returns [ 'path' => relative web path ] or [ 'error' => message ].
 *
 * @return array{path?: string, error?: string}|array{}
 */
function tenant_branding_process_logo_upload(int $tenantId): array
{
    if (!isset($_FILES['branding_logo'])) {
        return [];
    }

    $file = $_FILES['branding_logo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'Logo upload failed. Try a smaller file or a different format.'];
    }

    if (($file['size'] ?? 0) > TENANT_BRANDING_LOGO_MAX_BYTES) {
        return ['error' => 'Logo must be ' . (TENANT_BRANDING_LOGO_MAX_BYTES / 1024) . ' KB or smaller.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['error' => 'Invalid logo upload.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $map = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    if (!isset($map[$mime])) {
        return ['error' => 'Logo must be PNG, JPG, WebP, or SVG.'];
    }

    $ext = $map[$mime];

    if ($ext === 'svg') {
        $svg = file_get_contents($tmp);
        if ($svg === false || stripos($svg, '<script') !== false) {
            return ['error' => 'SVG logo could not be accepted.'];
        }
    }

    $dir = tenant_branding_uploads_dir_for($tenantId);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['error' => 'Could not create upload folder.'];
    }

    foreach (glob($dir . '/logo.*') ?: [] as $old) {
        if (is_file($old)) {
            @unlink($old);
        }
    }

    $basename = 'logo.' . $ext;
    $destFs = $dir . '/' . $basename;
    if (!move_uploaded_file($tmp, $destFs)) {
        return ['error' => 'Could not save logo file.'];
    }

    $relative = 'uploads/branding/' . $tenantId . '/' . $basename;

    return ['path' => $relative];
}

/**
 * @return array{pretty_home: string, pretty_login: string, compat_home: string, compat_login: string}
 */
function tenant_branding_public_urls(string $slug): array
{
    $enc = rawurlencode($slug);

    return [
        'pretty_home' => mechanix_url_path('/t/' . $enc),
        'pretty_login' => mechanix_url_path('/t/' . $enc . '/login'),
        'compat_home' => mechanix_url_path('/t/index.php') . '?slug=' . urlencode($slug),
        'compat_login' => mechanix_url_path('/t/login.php') . '?slug=' . urlencode($slug),
    ];
}

function tenant_branding_slug_available_for_tenant(PDO $pdo, string $normalizedSlug, int $tenantId): bool
{
    $stmt = $pdo->prepare('SELECT tenant_id FROM tenant_branding WHERE public_slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $normalizedSlug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return true;
    }

    return (int) $row['tenant_id'] === $tenantId;
}

/** Resolve tenant_id from branding slug for active tenants (staff branded login). */
function tenant_branding_tenant_id_for_slug(PDO $pdo, string $slugRaw): ?int
{
    $slug = tenant_branding_normalize_slug($slugRaw);
    if ($slug === null) {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT tb.tenant_id
        FROM tenant_branding tb
        INNER JOIN tenants t ON t.tenant_id = tb.tenant_id
        WHERE tb.public_slug = :slug
          AND t.status IN (\'active\', \'pending_payment\')
        LIMIT 1
    ');
    $stmt->execute(['slug' => $slug]);

    $id = $stmt->fetchColumn();

    return $id !== false ? (int) $id : null;
}
