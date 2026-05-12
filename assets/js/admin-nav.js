(function () {
    'use strict';

    var root = document.querySelector('.dashboard');
    if (!root) {
        return;
    }

    var sidebar = document.getElementById('tenant-sidebar');
    var openBtn = root.querySelector('[data-sidebar-open]');
    var backdrop = root.querySelector('[data-sidebar-backdrop]');
    var closeBtns = root.querySelectorAll('[data-sidebar-close]');
    var mq = window.matchMedia('(max-width: 1100px)');

    function isNarrow() {
        return mq.matches;
    }

    function setOpen(open) {
        root.classList.toggle('dashboard--nav-open', open);
        document.body.classList.toggle('dashboard-nav-locked', open && isNarrow());

        if (openBtn) {
            openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        if (backdrop) {
            backdrop.classList.toggle('is-visible', open && isNarrow());
            backdrop.setAttribute('aria-hidden', open && isNarrow() ? 'false' : 'true');
        }
    }

    function closeNav() {
        setOpen(false);
    }

    function openNav() {
        if (isNarrow()) {
            setOpen(true);
        }
    }

    function toggleNav() {
        if (root.classList.contains('dashboard--nav-open')) {
            closeNav();
        } else {
            openNav();
        }
    }

    if (openBtn) {
        openBtn.addEventListener('click', function () {
            if (!isNarrow()) {
                return;
            }
            toggleNav();
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeNav);
    }

    closeBtns.forEach(function (btn) {
        btn.addEventListener('click', closeNav);
    });

    root.querySelectorAll('.sidebar a[href]').forEach(function (a) {
        a.addEventListener('click', function () {
            if (isNarrow()) {
                closeNav();
            }
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && root.classList.contains('dashboard--nav-open')) {
            closeNav();
        }
    });

    function onMqChange() {
        if (!isNarrow()) {
            closeNav();
        }
    }

    if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', onMqChange);
    } else if (typeof mq.addListener === 'function') {
        mq.addListener(onMqChange);
    }
})();
