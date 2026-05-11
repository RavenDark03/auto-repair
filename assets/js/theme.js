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

    function applyThemeLabel() {
        const currentTheme = root.getAttribute('data-theme') || 'light';
        toggleButtons.forEach(function (button) {
            button.textContent = currentTheme === 'dark' ? 'Light Mode' : 'Dark Mode';
        });
    }

    applyThemeLabel();

    toggleButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const currentTheme = root.getAttribute('data-theme') || 'light';
            const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', nextTheme);
            localStorage.setItem('theme', nextTheme);
            applyThemeLabel();
        });
    });

    const passwordToggles = document.querySelectorAll('[data-password-toggle]');

    passwordToggles.forEach(function (toggle) {
        const targetId = toggle.getAttribute('data-password-target');
        const input = targetId ? document.getElementById(targetId) : null;

        if (!input) {
            return;
        }

        toggle.addEventListener('change', function () {
            input.type = toggle.checked ? 'text' : 'password';
        });
    });
});
