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
