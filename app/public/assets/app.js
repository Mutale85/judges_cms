/*
 * General page behaviour. No inline scripts are used anywhere (strict CSP).
 *
 *  - Forms submit over AJAX: failures appear in a SweetAlert dialog and the
 *    form keeps what was typed; successes follow the server's redirect and the
 *    next page shows the message. Add data-no-ajax to a form to opt out.
 *  - data-confirm asks first, with a SweetAlert dialog.
 *  - Flash messages render as SweetAlert toasts (or a dialog when long, e.g.
 *    a one-time password that must be copied).
 *  - The reminders bell loads what is due over AJAX.
 */
(function () {
    'use strict';

    var hasSwal = typeof window.Swal !== 'undefined';
    var csrf = document.body ? document.body.getAttribute('data-csrf') : null;

    var Toast = hasSwal ? Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 4500,
        timerProgressBar: true,
        customClass: { popup: 'jc-toast' }
    }) : null;

    var swalButtons = {
        customClass: { confirmButton: 'btn btn-dark mx-1', cancelButton: 'btn btn-outline-secondary mx-1', denyButton: 'btn btn-danger mx-1' },
        buttonsStyling: false
    };

    function notify(type, message) {
        if (!message) { return; }
        var icon = { danger: 'error', error: 'error', warning: 'warning', info: 'info', success: 'success' }[type] || 'info';
        if (!hasSwal) { window.alert(message); return; }
        // Long messages (one-time passwords, explanations) need a dialog the user closes.
        if (message.length > 110 || icon === 'error') {
            Swal.fire(Object.assign({ icon: icon, text: message, confirmButtonText: 'OK' }, swalButtons));
        } else {
            Toast.fire({ icon: icon, title: message });
        }
    }
    window.jcNotify = notify;

    function confirmDialog(message, danger) {
        if (!hasSwal) { return Promise.resolve(window.confirm(message)); }
        return Swal.fire(Object.assign({
            icon: danger ? 'warning' : 'question',
            text: message,
            showCancelButton: true,
            confirmButtonText: danger ? 'Yes, continue' : 'Continue',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            focusCancel: !!danger
        }, swalButtons)).then(function (r) { return r.isConfirmed; });
    }
    window.jcConfirm = confirmDialog;

    // --- Flash messages → toasts ------------------------------------------------
    document.querySelectorAll('.jc-flash').forEach(function (el) {
        if (!hasSwal) { return; }
        var type = el.getAttribute('data-type');
        var text = el.getAttribute('data-message');
        el.remove();
        notify(type, text);
    });

    // --- AJAX forms ---------------------------------------------------------------
    function ajaxable(form) {
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        return method === 'post' && !form.hasAttribute('data-no-ajax') && !form.getAttribute('target') && window.FormData && window.XMLHttpRequest;
    }

    function setBusy(form, submitter, busy) {
        var buttons = form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]');
        buttons.forEach(function (b) { b.disabled = busy; });
        if (submitter && submitter.tagName === 'BUTTON') {
            if (busy) {
                submitter.setAttribute('data-label', submitter.innerHTML);
                submitter.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>');
            } else if (submitter.hasAttribute('data-label')) {
                submitter.innerHTML = submitter.getAttribute('data-label');
                submitter.removeAttribute('data-label');
            }
        }
    }

    function send(form, submitter) {
        var data;
        try { data = new FormData(form, submitter || undefined); } catch (e) {
            data = new FormData(form);
            if (submitter && submitter.name) { data.append(submitter.name, submitter.value); }
        }
        var hasFile = Array.prototype.some.call(form.querySelectorAll('input[type="file"]'), function (i) { return i.files && i.files.length; });
        var xhr = new XMLHttpRequest();
        xhr.open('POST', form.getAttribute('action') || window.location.href);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        setBusy(form, submitter, true);

        if (hasFile && hasSwal) {
            Swal.fire({
                title: 'Uploading…',
                html: '<div class="progress" role="progressbar" aria-label="Upload progress"><div class="progress-bar" id="jc-upload-bar"></div></div><div class="small text-muted mt-2">Files are encrypted as they are stored.</div>',
                allowOutsideClick: false,
                showConfirmButton: false
            });
            xhr.upload.addEventListener('progress', function (e) {
                var bar = document.getElementById('jc-upload-bar');
                if (bar && e.lengthComputable) { bar.style.width = Math.round(e.loaded / e.total * 100) + '%'; }
            });
        }

        xhr.onload = function () {
            var json = null;
            var type = xhr.getResponseHeader('Content-Type') || '';
            if (type.indexOf('application/json') !== -1) {
                try { json = JSON.parse(xhr.responseText); } catch (e) { json = null; }
            }
            if (json && json.ok && json.redirect) {
                window.location.assign(json.redirect);
                return;
            }
            setBusy(form, submitter, false);
            if (hasFile && hasSwal) { Swal.close(); }
            if (json && json.ok) {
                notify('success', json.message || 'Saved.');
                form.dispatchEvent(new CustomEvent('jc:success', { detail: json }));
                return;
            }
            if (json && json.message) {
                if (json.status === 419) {
                    confirmDialog('Your session has expired. Reload the page and try again?', false).then(function (ok) { if (ok) { window.location.reload(); } });
                    return;
                }
                notify('error', json.message);
                return;
            }
            // Not JSON (e.g. a redirect to the sign-in page): fall back to a normal submission.
            if (xhr.responseURL && xhr.responseURL !== (form.action || '')) {
                window.location.assign(xhr.responseURL);
                return;
            }
            form.setAttribute('data-no-ajax', '');
            HTMLFormElement.prototype.submit.call(form);
        };
        xhr.onerror = function () {
            setBusy(form, submitter, false);
            if (hasFile && hasSwal) { Swal.close(); }
            notify('error', 'The server could not be reached. Check your connection and try again — nothing was lost on this page.');
        };
        xhr.send(data);
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) { return; }
        var submitter = event.submitter || null;
        var message = (submitter && submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
        var useAjax = ajaxable(form);
        if (!message && !useAjax) { return; }
        if (form.hasAttribute('data-confirmed')) {
            form.removeAttribute('data-confirmed');
            if (!useAjax) { return; }
        }
        event.preventDefault();
        var go = function () {
            if (useAjax) {
                send(form, submitter);
            } else {
                form.setAttribute('data-confirmed', '');
                if (submitter && submitter.name) {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden'; hidden.name = submitter.name; hidden.value = submitter.value;
                    form.appendChild(hidden);
                }
                HTMLFormElement.prototype.submit.call(form);
            }
        };
        if (message) {
            confirmDialog(message, form.hasAttribute('data-danger') || (submitter && submitter.hasAttribute('data-danger'))).then(function (ok) { if (ok) { go(); } });
        } else {
            go();
        }
    });

    // --- Small behaviours ------------------------------------------------------------
    // Bar sizes come from data attributes: inline style attributes are blocked by the CSP.
    document.querySelectorAll('[data-w]').forEach(function (el) { el.style.width = Math.max(0, Math.min(100, +el.getAttribute('data-w'))) + '%'; });
    document.querySelectorAll('[data-h]').forEach(function (el) { el.style.height = Math.max(0, Math.min(100, +el.getAttribute('data-h'))) + '%'; });

    document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () {
            if (typeof select.form.requestSubmit === 'function') { select.form.requestSubmit(); } else { select.form.submit(); }
        });
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

    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function () { notify('success', 'Copied.'); });
            }
        });
    });

    // Sidebar toggle on small screens.
    var toggle = document.querySelector('[data-sidebar-toggle]');
    if (toggle) {
        toggle.addEventListener('click', function () { document.body.classList.toggle('jc-sidebar-open'); });
    }

    // --- Reminders bell ---------------------------------------------------------------
    var bell = document.getElementById('jc-bell');
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    function loadBell() {
        if (!bell || !window.fetch) { return; }
        fetch('/reminders/feed', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) { return; }
                var badge = bell.querySelector('.jc-bell-count');
                var list = document.getElementById('jc-bell-list');
                badge.textContent = data.count > 9 ? '9+' : String(data.count);
                badge.classList.toggle('d-none', data.count === 0);
                badge.classList.toggle('jc-bell-overdue', data.overdue > 0);
                if (!data.items.length) {
                    list.innerHTML = '<div class="px-3 py-3 text-muted small">Nothing due this week.</div>';
                    return;
                }
                list.innerHTML = data.items.map(function (i) {
                    return '<div class="jc-bell-item d-flex gap-2 align-items-start">' +
                        '<a class="flex-grow-1 text-reset text-decoration-none" href="' + esc(i.url) + '">' +
                        '<div class="small fw-semibold">' + esc(i.title) + '</div>' +
                        '<div class="small ' + (i.overdue ? 'text-danger' : 'text-muted') + '">' + (i.overdue ? 'Overdue · ' : '') + esc(i.due) + ' · ' + esc(i.kind) + (i.case ? ' · ' + esc(i.case) : '') + '</div></a>' +
                        (data.can_complete ? '<button type="button" class="btn btn-sm btn-link text-success p-0" data-complete="' + i.id + '" title="Mark done" aria-label="Mark done"><i class="bi bi-check2-circle"></i></button>' : '') +
                        '</div>';
                }).join('');
            })
            .catch(function () { /* the bell is a convenience; stay quiet */ });
    }
    if (bell) {
        loadBell();
        window.setInterval(loadBell, 5 * 60 * 1000);
        document.getElementById('jc-bell-list').addEventListener('click', function (event) {
            var btn = event.target.closest('[data-complete]');
            if (!btn) { return; }
            event.preventDefault();
            event.stopPropagation();
            var body = new FormData();
            body.append('_csrf', csrf);
            body.append('inline', '1');
            fetch('/reminders/' + btn.getAttribute('data-complete') + '/complete', {
                method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); }).then(function (json) {
                notify(json.ok ? 'success' : 'error', json.message);
                loadBell();
            });
        });
    }

    // --- Live search (admin users) -------------------------------------------------------
    document.querySelectorAll('[data-live-search]').forEach(function (form) {
        var target = document.querySelector(form.getAttribute('data-live-search'));
        var timer = null;
        var run = function () {
            var params = new URLSearchParams(new FormData(form));
            params.set('partial', '1');
            fetch(form.getAttribute('action') + '?' + params.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    target.innerHTML = html;
                    params.delete('partial');
                    window.history.replaceState(null, '', form.getAttribute('action') + '?' + params.toString());
                });
        };
        form.addEventListener('input', function () { window.clearTimeout(timer); timer = window.setTimeout(run, 250); });
        form.addEventListener('change', run);
        form.addEventListener('submit', function (e) { e.preventDefault(); run(); });
    });

    // --- Plan editor: fill the modal from the row ---------------------------------------
    document.querySelectorAll('[data-plan]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var plan = JSON.parse(btn.getAttribute('data-plan'));
            var form = document.getElementById('plan-form');
            form.setAttribute('action', plan.id ? '/admin/plans/' + plan.id : '/admin/plans');
            document.getElementById('plan-modal-title').textContent = plan.id ? 'Edit plan: ' + plan.name : 'New plan';
            ['code', 'name', 'description', 'price_monthly', 'price_annual', 'currency', 'max_staff', 'features', 'sort_order'].forEach(function (k) {
                form.elements[k].value = plan[k] == null ? '' : plan[k];
            });
            ['includes_transcription', 'is_active', 'is_featured'].forEach(function (k) {
                form.elements[k].checked = String(plan[k]) === '1';
            });
        });
    });

    // --- Billing: show the price for the chosen plan and cycle -------------------------
    var payForm = document.getElementById('pay-form');
    if (payForm) {
        var update = function () {
            var opt = payForm.elements.plan_id.selectedOptions[0];
            var cycle = payForm.querySelector('input[name="billing_cycle"]:checked');
            if (!opt || !cycle) { return; }
            document.getElementById('pay-amount').textContent = opt.getAttribute('data-' + cycle.value);
        };
        payForm.addEventListener('change', update);
        update();
    }

    // --- Sign-up: wording follows judge / arbitrator -----------------------------------
    var signup = document.getElementById('signup-form');
    if (signup) {
        var sync = function () {
            var arb = signup.querySelector('input[name="mode"]:checked').value === 'arbitration';
            signup.querySelectorAll('[data-court]').forEach(function (el) {
                el.textContent = arb ? el.getAttribute('data-arb') : el.getAttribute('data-court');
            });
            signup.elements.court.placeholder = arb ? 'e.g. Lusaka International Arbitration Centre' : 'e.g. High Court for Zambia';
        };
        signup.addEventListener('change', sync);
        sync();
    }
})();
