<?php
declare(strict_types=1);

/**
 * Shared MECHANIX / landing-aligned UI fragments (theme control, icon nav).
 */

function mechanix_theme_toggle_button(): string
{
    return '<button type="button" class="theme-toggle theme-icon-btn" data-theme-toggle aria-label="Switch to dark mode">'
        . '<svg class="theme-icon theme-icon--moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" /></svg>'
        . '<svg class="theme-icon theme-icon--sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>'
        . '</button>';
}

function mechanix_back_icon_link(string $href, string $ariaLabel = 'Back'): string
{
    $safeHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
    $safeLabel = htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8');

    return '<a href="' . $safeHref . '" class="icon-nav-btn" aria-label="' . $safeLabel . '">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>'
        . '</a>';
}

/**
 * Accessible logout confirmation using native dialog + POST to logout handler.
 *
 * @param 'superadmin'|'tenant' $context
 */
function mechanix_logout_dialog_markup(string $context, string $formAction = '../logout.php'): string
{
    $ctx = $context === 'superadmin' ? 'superadmin' : 'tenant';
    $safeAction = htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8');
    $safeCtx = htmlspecialchars($ctx, ENT_QUOTES, 'UTF-8');

    return '<dialog class="mechanix-logout-dialog" id="mechanix-logout-dialog" aria-labelledby="mechanix-logout-title">'
        . '<form class="mechanix-logout-dialog-form" method="post" action="' . $safeAction . '">'
        . '<input type="hidden" name="logout_context" value="' . $safeCtx . '">'
        . '<h2 id="mechanix-logout-title" class="mechanix-logout-dialog-title">Log out?</h2>'
        . '<p class="mechanix-logout-dialog-text">You will need to sign in again to continue.</p>'
        . '<div class="mechanix-logout-dialog-actions">'
        . '<button type="button" class="btn btn-secondary mechanix-logout-cancel">Cancel</button>'
        . '<button type="submit" class="btn btn-primary">Log out</button>'
        . '</div>'
        . '</form>'
        . '</dialog>';
}

/**
 * CSS stack for Tabler-based tenant/admin and heavy superadmin workspaces.
 * Pass web path ending in assets/css/, e.g. '../assets/css/' from admin/*.php or superadmin/*.php
 */
function mechanix_link_styles_tabler_workspace(string $assetsCssHrefPrefix): string
{
    $p = htmlspecialchars(rtrim($assetsCssHrefPrefix, '/') . '/', ENT_QUOTES, 'UTF-8');

    return '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0/dist/css/tabler.min.css">' . "\n"
        . '    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">' . "\n"
        . '    <link rel="stylesheet" href="' . $p . 'tabler-mechanix-bridge.css">' . "\n"
        . '    <link rel="stylesheet" href="' . $p . 'mechanix-app-shell.css">' . "\n"
        . '    <link rel="stylesheet" href="' . $p . 'styles.css">' . "\n"
        . '    <link rel="stylesheet" href="' . $p . 'superadmin-landing-theme.css">';
}

/**
 * Superadmin pages without Tabler: align shell + tokens-derived surfaces only.
 *
 * @param string $assetsCssHrefPrefix e.g. '../assets/css/'
 */
function mechanix_link_styles_plain_workspace(string $assetsCssHrefPrefix): string
{
    $p = htmlspecialchars(rtrim($assetsCssHrefPrefix, '/') . '/', ENT_QUOTES, 'UTF-8');

    return '<link rel="stylesheet" href="' . $p . 'styles.css">' . "\n"
        . '    <link rel="stylesheet" href="' . $p . 'mechanix-app-shell.css">' . "\n"
        . '    <link rel="stylesheet" href="' . $p . 'superadmin-landing-theme.css">';
}

/**
 * Bootstrap 5 modal fragment (shown via bootstrap.Modal or data-bs-toggle).
 *
 * @param array{footer?: string, scrollable?: bool, size?: ''|'modal-sm'|'modal-lg'|'modal-xl'} $opts
 */
function mechanix_modal(
    string $id,
    string $titleHtml,
    string $bodyHtml,
    string $dialogClassExtras = '',
    array $opts = []
): string {
    $scrollableClass = !empty($opts['scrollable']) ? ' modal-dialog-scrollable' : '';
    $sizeClass = isset($opts['size']) ? trim((string) $opts['size']) : '';
    $sizeClass = $sizeClass !== '' ? ' ' . htmlspecialchars($sizeClass, ENT_QUOTES, 'UTF-8') : '';
    $extras = trim($dialogClassExtras) !== ''
        ? ' ' . htmlspecialchars(trim($dialogClassExtras), ENT_QUOTES, 'UTF-8')
        : '';

    $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    $headingId = $safeId . '-title';

    $footerHtml = $opts['footer'] ?? '';

    return '<div class="modal fade" id="' . $safeId . '" tabindex="-1" aria-labelledby="' . $headingId . '" aria-hidden="true">' . "\n"
        . '  <div class="modal-dialog' . $sizeClass . $scrollableClass . $extras . '">' . "\n"
        . '    <div class="modal-content">' . "\n"
        . '      <div class="modal-header">' . "\n"
        . '        <h5 class="modal-title" id="' . $headingId . '">' . $titleHtml . '</h5>' . "\n"
        . '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' . "\n"
        . '      </div>' . "\n"
        . '      <div class="modal-body">' . $bodyHtml . '</div>' . "\n"
        . ($footerHtml !== '' ? '      <div class="modal-footer">' . $footerHtml . '</div>' . "\n" : '')
        . '    </div>' . "\n"
        . '  </div>' . "\n"
        . '</div>';
}
