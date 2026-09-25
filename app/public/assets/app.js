/* General page behaviour. No inline scripts are used anywhere (strict CSP). */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () { select.form.submit(); });
    });

    document.querySelectorAll('[data-print]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.print(); });
    });

    document.querySelectorAll('[data-back]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (window.history.length > 1) {
                event.preventDefault();
                window.history.back();
            }
        });
    });
})();
