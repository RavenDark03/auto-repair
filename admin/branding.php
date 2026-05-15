<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/feature_access.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/platform_rules.php';
require_once __DIR__ . '/../includes/tenant_branding.php';

requireAdmin();

$tenantId = (int) ($_SESSION['tenant_id'] ?? 0);
$businessName = $_SESSION['business_name'] ?? 'Tenant Workspace';
$flashMessage = $_SESSION['branding_success'] ?? null;
$errorMessage = $_SESSION['branding_error'] ?? null;
unset($_SESSION['branding_success'], $_SESSION['branding_error']);

$iosUrl = '';
$androidUrl = '';
$services = [];
$brandingRow = null;

try {
    $pdo = Database::getInstance();
    tenant_branding_ensure_row($pdo, $tenantId);
    $brandingRow = tenant_branding_row_for_tenant($pdo, $tenantId);

    $row = $pdo->prepare('
        SELECT mobile_app_ios_url, mobile_app_android_url
        FROM tenants
        WHERE tenant_id = :tenant_id
        LIMIT 1
    ');
    $row->execute(['tenant_id' => $tenantId]);
    $t = $row->fetch(PDO::FETCH_ASSOC);

    if ($t) {
        $iosUrl = (string) ($t['mobile_app_ios_url'] ?? '');
        $androidUrl = (string) ($t['mobile_app_android_url'] ?? '');
    }

    $svcStmt = $pdo->prepare('
            SELECT service_name, service_description
            FROM tenant_branding_services
            WHERE tenant_id = :tenant_id
            ORDER BY sort_order ASC, tenant_branding_service_id ASC
        ');
    $svcStmt->execute(['tenant_id' => $tenantId]);
    $services = $svcStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorMessage = 'Branding data could not be loaded: ' . $e->getMessage();
}

$publicSlugField = (string) (($brandingRow ?? [])['public_slug'] ?? '');
$defaultsFallback = tenant_branding_default_colors();
$primaryHex = (string) (($brandingRow ?? [])['primary_color'] ?? $defaultsFallback['primary_color']);
$accentHex = (string) (($brandingRow ?? [])['accent_color'] ?? $defaultsFallback['accent_color']);
$bgHex = (string) (($brandingRow ?? [])['background_color'] ?? $defaultsFallback['background_color']);
$textHex = (string) (($brandingRow ?? [])['text_color'] ?? $defaultsFallback['text_color']);
$logoPreviewHref = tenant_branding_logo_public_href(isset(($brandingRow ?? [])['logo_path']) ? (string) (($brandingRow ?? [])['logo_path']) : null);
$publicUrls = $publicSlugField !== '' ? tenant_branding_public_urls($publicSlugField) : null;

if ($services === []) {
    $services = [
        ['service_name' => '', 'service_description' => ''],
    ];
}

$showAnalytics = tenantHasFeature('reports', $tenantId);
$visibleModuleLinks = getVisibleTenantAdminModuleLinks($tenantId);

$iosHref = trim($iosUrl);
$androidHref = trim($androidUrl);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branding — MECHANIX</title>
    <?= mechanix_link_styles_tabler_workspace('../assets/css/') ?>
</head>
<body class="page-shell antialiased tenant-app">
    <div class="dashboard">
        <?= renderTenantAdminSidebar($businessName, $visibleModuleLinks, 'branding.php', $showAnalytics) ?>

        <main class="dashboard-main" id="main-content" tabindex="-1">
            <?= renderTenantAdminTopbar('Branding', 'Public workspace link, logo, colors, promoted services, and mobile store URLs.') ?>

            <?php if ($flashMessage !== null): ?>
                <div class="alert alert-success"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($errorMessage !== null): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?= renderTenantAccessModeNotice() ?>

            <section class="content-grid">
                <article class="content-card">
                    <h3>Public workspace</h3>
                    <p>Customers and staff use this short link for your branded landing page and staff login. Colors apply to those public pages.</p>

                    <?php if ($publicUrls !== null): ?>
                        <div class="form-group">
                            <label class="form-label">Public URLs</label>
                            <div class="small text-muted" style="margin-bottom:6px;">Pretty paths need Apache <code>mod_rewrite</code> (see <code>t/.htaccess</code>). Otherwise use the compatibility links.</div>
                            <ul class="list-unstyled small mb-0">
                                <li><strong>Landing:</strong> <a href="<?= htmlspecialchars($publicUrls['pretty_home'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($publicUrls['pretty_home'], ENT_QUOTES, 'UTF-8') ?></a></li>
                                <li><strong>Staff login:</strong> <a href="<?= htmlspecialchars($publicUrls['pretty_login'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($publicUrls['pretty_login'], ENT_QUOTES, 'UTF-8') ?></a></li>
                                <li><strong>Compat landing:</strong> <a href="<?= htmlspecialchars($publicUrls['compat_home'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($publicUrls['compat_home'], ENT_QUOTES, 'UTF-8') ?></a></li>
                                <li><strong>Compat login:</strong> <a href="<?= htmlspecialchars($publicUrls['compat_login'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($publicUrls['compat_login'], ENT_QUOTES, 'UTF-8') ?></a></li>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form action="actions/save_branding.php" method="POST" enctype="multipart/form-data" class="feature-toggle-form" style="margin-top: 1rem;">
                        <div class="form-group">
                            <label for="public_slug">Public link slug</label>
                            <input class="form-control" type="text" id="public_slug" name="public_slug" value="<?= htmlspecialchars($publicSlugField, ENT_QUOTES, 'UTF-8') ?>" pattern="[a-z0-9][a-z0-9-]{1,62}" maxlength="63" required autocomplete="off">
                            <span class="form-hint text-muted small">Lowercase letters, numbers, hyphens only; 2–63 characters; must start with a letter or number.</span>
                        </div>

                        <style>
                            .branding-color-row {
                                display: flex;
                                align-items: center;
                                gap: 12px;
                                flex-wrap: wrap;
                            }
                            .branding-color-row input[type="color"] {
                                width: 3.25rem;
                                height: 2.5rem;
                                padding: 4px;
                                border-radius: 10px;
                                border: 1px solid var(--tblr-border-color, #dee2e6);
                                cursor: pointer;
                                flex-shrink: 0;
                            }
                            .branding-color-row .branding-color-hex {
                                font-family: ui-monospace, monospace;
                                font-size: 0.875rem;
                                color: var(--tblr-secondary, #6c757d);
                                min-width: 7ch;
                            }
                        </style>

                        <div class="row g-2">
                            <div class="col-sm-6">
                                <div class="form-group mb-2">
                                    <label for="primary_color">Primary color</label>
                                    <div class="branding-color-row">
                                        <input type="color" id="primary_color" name="primary_color" value="<?= htmlspecialchars(strtolower($primaryHex), ENT_QUOTES, 'UTF-8') ?>" title="Primary color" required>
                                        <span class="branding-color-hex" data-branding-color-hex-for="primary_color"><?= htmlspecialchars(strtolower($primaryHex), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="form-group mb-2">
                                    <label for="accent_color">Accent color</label>
                                    <div class="branding-color-row">
                                        <input type="color" id="accent_color" name="accent_color" value="<?= htmlspecialchars(strtolower($accentHex), ENT_QUOTES, 'UTF-8') ?>" title="Accent color" required>
                                        <span class="branding-color-hex" data-branding-color-hex-for="accent_color"><?= htmlspecialchars(strtolower($accentHex), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="form-group mb-2">
                                    <label for="background_color">Page background</label>
                                    <div class="branding-color-row">
                                        <input type="color" id="background_color" name="background_color" value="<?= htmlspecialchars(strtolower($bgHex), ENT_QUOTES, 'UTF-8') ?>" title="Page background" required>
                                        <span class="branding-color-hex" data-branding-color-hex-for="background_color"><?= htmlspecialchars(strtolower($bgHex), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="form-group mb-2">
                                    <label for="text_color">Main text</label>
                                    <div class="branding-color-row">
                                        <input type="color" id="text_color" name="text_color" value="<?= htmlspecialchars(strtolower($textHex), ENT_QUOTES, 'UTF-8') ?>" title="Main text color" required>
                                        <span class="branding-color-hex" data-branding-color-hex-for="text_color"><?= htmlspecialchars(strtolower($textHex), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="branding_logo">Logo (optional)</label>
                            <input class="form-control" type="file" id="branding_logo" name="branding_logo" accept=".png,.jpg,.jpeg,.webp,.svg,image/png,image/jpeg,image/webp,image/svg+xml">
                            <span class="form-hint text-muted small">PNG, JPG, WebP, or SVG · max <?= (int) (TENANT_BRANDING_LOGO_MAX_BYTES / 1024) ?> KB · stored under uploads/branding/<?= (int) $tenantId ?>/</span>
                        </div>
                        <?php if ($logoPreviewHref !== null): ?>
                            <div class="form-group">
                                <label class="form-check">
                                    <input type="checkbox" name="remove_logo" value="1" class="form-check-input">
                                    <span class="form-check-label">Remove current logo</span>
                                </label>
                                <div style="margin-top:8px;">
                                    <img src="<?= htmlspecialchars($logoPreviewHref, ENT_QUOTES, 'UTF-8') ?>" alt="Current logo" style="max-height:72px;border-radius:10px;border:1px solid var(--tblr-border-color);padding:6px;background:var(--tblr-bg-surface-secondary);">
                                </div>
                            </div>
                        <?php endif; ?>

                    <h3 class="mt-4">Mobile app</h3>
                    <p>Add your App Store and Google Play listings so staff can open the correct install page on a phone.</p>

                    <div class="branding-store-preview" aria-label="Store link preview">
                        <?php if ($iosHref !== ''): ?>
                            <a class="branding-store-btn" href="<?= htmlspecialchars($iosHref, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">App Store</a>
                        <?php else: ?>
                            <span class="branding-store-btn branding-store-btn--secondary" aria-disabled="true">App Store — add URL below</span>
                        <?php endif; ?>
                        <?php if ($androidHref !== ''): ?>
                            <a class="branding-store-btn" href="<?= htmlspecialchars($androidHref, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Google Play</a>
                        <?php else: ?>
                            <span class="branding-store-btn branding-store-btn--secondary" aria-disabled="true">Google Play — add URL below</span>
                        <?php endif; ?>
                    </div>

                        <div class="form-group">
                            <label for="mobile_app_ios_url">App Store URL</label>
                            <input class="form-control" type="url" id="mobile_app_ios_url" name="mobile_app_ios_url" value="<?= htmlspecialchars($iosUrl, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://apps.apple.com/..." autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label for="mobile_app_android_url">Google Play URL</label>
                            <input class="form-control" type="url" id="mobile_app_android_url" name="mobile_app_android_url" value="<?= htmlspecialchars($androidUrl, ENT_QUOTES, 'UTF-8') ?>" placeholder="https://play.google.com/store/apps/..." autocomplete="off">
                        </div>

                        <h3 class="mt-3">Services you offer</h3>
                        <p class="text-muted" style="margin-bottom: 0;">These entries describe what the shop promotes (for customers, marketing, and mobile context). Up to 30 items.</p>

                        <div id="branding-services-list" class="branding-services-editor">
                            <?php foreach ($services as $i => $svc): ?>
                                <div class="branding-service-row">
                                    <div class="branding-service-row__head">
                                        <span>Service</span>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-remove-branding-service>Remove</button>
                                    </div>
                                    <div class="form-group">
                                        <label for="service_name_<?= (int) $i ?>">Name</label>
                                        <input class="form-control" type="text" id="service_name_<?= (int) $i ?>" name="service_name[<?= (int) $i ?>]" value="<?= htmlspecialchars((string) ($svc['service_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="150" placeholder="e.g. Oil change, brake service">
                                    </div>
                                    <div class="form-group">
                                        <label for="service_description_<?= (int) $i ?>">Short description (optional)</label>
                                        <textarea class="form-control form-textarea" id="service_description_<?= (int) $i ?>" name="service_description[<?= (int) $i ?>]" maxlength="500" rows="2" placeholder="One line customers understand"><?= htmlspecialchars((string) ($svc['service_description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <template id="branding-service-row-template">
                            <div class="branding-service-row">
                                <div class="branding-service-row__head">
                                    <span>Service</span>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-remove-branding-service>Remove</button>
                                </div>
                                <div class="form-group">
                                    <label>Name</label>
                                    <input class="form-control" type="text" name="service_name[__INDEX__]" value="" maxlength="150" placeholder="e.g. Oil change, brake service">
                                </div>
                                <div class="form-group">
                                    <label>Short description (optional)</label>
                                    <textarea class="form-control form-textarea" name="service_description[__INDEX__]" maxlength="500" rows="2" placeholder="One line customers understand"></textarea>
                                </div>
                            </div>
                        </template>

                        <div class="approval-actions" style="margin-top: 12px; flex-wrap: wrap; gap: 10px;">
                            <button type="button" class="btn btn-secondary" data-add-branding-service>Add service</button>
                            <button type="submit" class="btn btn-primary">Save branding</button>
                        </div>
                    </form>
                </article>

                <article class="content-card">
                    <h3>Summary</h3>
                    <p>What customers and the mobile experience should reflect for <strong><?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
                    <div class="dashboard-list compact-list">
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Public slug</strong>
                                <p><?= htmlspecialchars($publicSlugField !== '' ? $publicSlugField : '—', ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Logo</strong>
                                <p><?= $logoPreviewHref !== null ? 'Uploaded' : 'Not set' ?></p>
                            </div>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>App Store</strong>
                                <p><?= $iosHref !== '' ? 'Linked' : 'Not set' ?></p>
                            </div>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Google Play</strong>
                                <p><?= $androidHref !== '' ? 'Linked' : 'Not set' ?></p>
                            </div>
                        </div>
                        <div class="dashboard-list-item">
                            <div>
                                <strong>Services listed</strong>
                                <p><?= count(array_filter($services, static fn ($s) => trim((string) ($s['service_name'] ?? '')) !== '')) ?> saved</p>
                            </div>
                        </div>
                    </div>
                    <?php
                    $listed = array_values(array_filter($services, static fn ($s) => trim((string) ($s['service_name'] ?? '')) !== ''));
                    if ($listed !== []):
                    ?>
                        <h4 class="mt-3" style="font-size: 0.95rem;">Current list</h4>
                        <ul class="branding-services-readonly list-unstyled">
                            <?php foreach ($listed as $item): ?>
                                <li>
                                    <strong><?= htmlspecialchars((string) $item['service_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if (trim((string) ($item['service_description'] ?? '')) !== ''): ?>
                                        <div class="text-muted small"><?= htmlspecialchars((string) $item['service_description'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>
            </section>
        </main>
    </div>

    <?= renderTenantAdminFooterScripts() ?>
    <script src="../assets/js/branding-services.js?v=1"></script>
    <script>
        document.querySelectorAll('input[type="color"][id]').forEach(function (picker) {
            var hexSpan = document.querySelector('[data-branding-color-hex-for="' + picker.id + '"]');
            if (!hexSpan) return;
            picker.addEventListener('input', function () {
                hexSpan.textContent = picker.value;
            });
            picker.addEventListener('change', function () {
                hexSpan.textContent = picker.value;
            });
        });
    </script>
</body>
</html>
