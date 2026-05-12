(function () {
    'use strict';

    var root = document.querySelector('[data-job-tabs]');
    if (!root) {
        return;
    }

    var tabs = root.querySelectorAll('.panel-tab[role="tab"]');
    var panels = root.querySelectorAll('.panel-tab-panel[role="tabpanel"]');

    function activate(name) {
        tabs.forEach(function (tab) {
            var isMatch = tab.getAttribute('data-job-tab') === name;
            tab.classList.toggle('is-active', isMatch);
            tab.setAttribute('aria-selected', isMatch ? 'true' : 'false');
            tab.tabIndex = isMatch ? 0 : -1;
        });

        panels.forEach(function (panel) {
            var isMatch = panel.getAttribute('data-job-panel') === name;
            if (isMatch) {
                panel.removeAttribute('hidden');
                panel.classList.add('is-active');
            } else {
                panel.setAttribute('hidden', 'hidden');
                panel.classList.remove('is-active');
            }
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var name = tab.getAttribute('data-job-tab');
            if (name) {
                activate(name);
            }
        });
    });

    root.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') {
            return;
        }
        var list = Array.prototype.slice.call(tabs);
        var i = list.indexOf(document.activeElement);
        if (i < 0) {
            return;
        }
        e.preventDefault();
        var next = e.key === 'ArrowRight' ? i + 1 : i - 1;
        if (next < 0) {
            next = list.length - 1;
        }
        if (next >= list.length) {
            next = 0;
        }
        list[next].focus();
        var name = list[next].getAttribute('data-job-tab');
        if (name) {
            activate(name);
        }
    });
})();
