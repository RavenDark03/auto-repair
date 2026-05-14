(function () {
    'use strict';

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    window.mechanixBootstrapModal = function (id) {
        var el = typeof id === 'string' ? document.getElementById(id) : id;
        if (!el || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return null;
        }
        return bootstrap.Modal.getOrCreateInstance(el);
    };

    window.mechanixOpenModal = function (id) {
        var m = window.mechanixBootstrapModal(id);
        if (m) {
            m.show();
        }
    };

    onReady(function () {
        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return;
        }

        if (document.body.getAttribute('data-mechanix-show-payment-gate') === '1') {
            var pay = document.getElementById('mechanixPaymentGateModal');
            if (pay) {
                bootstrap.Modal.getOrCreateInstance(pay, { backdrop: 'static', keyboard: false }).show();
            }
        }

        var cm = document.body.getAttribute('data-mechanix-open-customer-modal') || '';
        if (cm === 'add') {
            window.mechanixOpenModal('mechanixCustomerModalAdd');
        } else if (cm === 'edit') {
            window.mechanixOpenModal('mechanixCustomerModalEdit');
        }

        var am = document.body.getAttribute('data-mechanix-open-appointment-modal') || '';
        if (am === 'add') {
            window.mechanixOpenModal('mechanixAppointmentModalAdd');
        } else if (am === 'edit') {
            window.mechanixOpenModal('mechanixAppointmentModalEdit');
        }

        var jm = document.body.getAttribute('data-mechanix-open-job-modal') || '';
        if (jm === 'edit') {
            window.mechanixOpenModal('mechanixJobEditModal');
        }
    });
})();
