(function () {
    function debounce(fn, ms) {
        var t = null;
        return function () {
            var ctx = this;
            var args = arguments;
            clearTimeout(t);
            t = setTimeout(function () {
                fn.apply(ctx, args);
            }, ms);
        };
    }

    function collectText(el) {
        return (el.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function initScope(scope) {
        var inputSelector = scope.getAttribute('data-live-filter-input');
        if (!inputSelector) {
            return;
        }
        var input = document.querySelector(inputSelector);
        if (!input) {
            return;
        }

        var itemSel = scope.getAttribute('data-live-filter-items') || '[data-live-filter-row]';
        var liveId = scope.getAttribute('data-live-filter-announcer');
        var announcer = liveId ? document.getElementById(liveId) : null;

        function run() {
            var q = String(input.value || '').trim().toLowerCase();
            var items = scope.querySelectorAll(itemSel);
            var shown = 0;

            items.forEach(function (item) {
                var hay = item.getAttribute('data-live-filter-text');
                if (hay === null) {
                    hay = collectText(item);
                    item.setAttribute('data-live-filter-text', hay);
                }
                var match = q === '' || hay.indexOf(q) !== -1;
                item.hidden = !match;
                item.setAttribute('aria-hidden', match ? 'false' : 'true');
                if (match) {
                    shown += 1;
                }
            });

            if (announcer) {
                announcer.textContent =
                    items.length === 0
                        ? 'No rows to filter.'
                        : shown === items.length
                            ? 'Showing all ' + shown + ' row(s).'
                            : 'Showing ' + shown + ' of ' + items.length + ' row(s).';
            }
        }

        var debounced = debounce(run, 200);
        input.addEventListener('input', debounced);
        input.addEventListener('change', run);
        run();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-live-filter-scope]').forEach(initScope);
    });
})();
