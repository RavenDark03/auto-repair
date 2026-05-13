document.addEventListener('DOMContentLoaded', function () {
    const root = document.documentElement;
    const toggleButtons = document.querySelectorAll('[data-theme-toggle]');
    const savedTheme = localStorage.getItem('theme');

    if (savedTheme === 'dark' || savedTheme === 'light') {
        root.setAttribute('data-theme', savedTheme);
    } else {
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        root.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
    }

    function syncBootstrapTheme() {
        const currentTheme = root.getAttribute('data-theme') || 'light';
        root.setAttribute('data-bs-theme', currentTheme === 'dark' ? 'dark' : 'light');
    }

    syncBootstrapTheme();

    function applyThemeLabel() {
        const currentTheme = root.getAttribute('data-theme') || 'light';
        toggleButtons.forEach(function (button) {
            if (button.querySelector('svg')) {
                button.setAttribute('aria-label', currentTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            } else {
                button.textContent = currentTheme === 'dark' ? 'Light Mode' : 'Dark Mode';
            }
        });
    }

    applyThemeLabel();

    toggleButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const currentTheme = root.getAttribute('data-theme') || 'light';
            const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', nextTheme);
            localStorage.setItem('theme', nextTheme);
            syncBootstrapTheme();
            applyThemeLabel();
        });
    });

    /* ------------------------------------------------------------------
       PASSWORD VISIBILITY TOGGLE
       Handles .pw-toggle-btn buttons next to password inputs.
       Swaps input type and icon, keeps aria-label accessible.
    ------------------------------------------------------------------ */
    document.querySelectorAll('.pw-toggle-btn').forEach(function (btn) {
        const targetId = btn.getAttribute('data-pw-target');
        const input = targetId ? document.getElementById(targetId) : null;
        if (!input) return;

        btn.addEventListener('click', function () {
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            btn.querySelector('.pw-eye').toggleAttribute('hidden', isHidden);
            btn.querySelector('.pw-eye-off').toggleAttribute('hidden', !isHidden);
        });
    });

    /* Legacy checkbox toggles (kept for backwards compatibility) */
    document.querySelectorAll('[data-password-toggle]').forEach(function (toggle) {
        const targetId = toggle.getAttribute('data-password-target');
        const input = targetId ? document.getElementById(targetId) : null;
        if (!input) return;
        toggle.addEventListener('change', function () {
            input.type = toggle.checked ? 'text' : 'password';
        });
    });
});