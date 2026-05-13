<?php
/**
 * Path helpers for redirects and links. Loads after config so BASE_URL exists.
 * Safe if BASE_URL is missing: falls back to ''.
 */
if (!function_exists('mechanix_public_url_prefix')) {
    function mechanix_public_url_prefix(): string
    {
        $configBase = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === '' || $script === '/') {
            return $configBase;
        }

        $depth = 1;
        if (preg_match('#/(?:admin|superadmin|actions)(?:/|$)#', $script)) {
            $depth = 2;
        }
        if (strpos($script, '/superadmin/actions/') !== false) {
            $depth = 3;
        } elseif (strpos($script, '/actions/') !== false) {
            $depth = 2;
        }

        $base = $script;
        for ($i = 0; $i < $depth; $i++) {
            $base = dirname($base);
        }

        $base = rtrim(str_replace('\\', '/', $base), '/');
        if ($base === '/' || $base === '.' || $base === '') {
            return '';
        }

        return $base;
    }
}

if (!function_exists('mechanix_url_path')) {
    function mechanix_url_path(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $prefix = mechanix_public_url_prefix();

        return ($prefix === '' ? '' : $prefix) . $path;
    }
}
