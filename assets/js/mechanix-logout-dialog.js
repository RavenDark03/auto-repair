(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var dialog = document.getElementById('mechanix-logout-dialog');
        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }

        var lastFocus = null;

        function openDialog() {
            lastFocus = document.activeElement;
            dialog.showModal();
            var cancel = dialog.querySelector('.mechanix-logout-cancel');
            if (cancel && typeof cancel.focus === 'function') {
                cancel.focus();
            }
        }

        function closeDialog() {
            if (dialog.open) {
                dialog.close();
            }
            if (lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus();
            }
        }

        document.querySelectorAll('[data-mechanix-logout-open]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                openDialog();
            });
        });

        dialog.querySelectorAll('.mechanix-logout-cancel').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeDialog();
            });
        });

        dialog.addEventListener('cancel', function (e) {
            e.preventDefault();
            closeDialog();
        });
    });
})();
