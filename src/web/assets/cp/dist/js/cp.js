/*
 * Simple Form — Control Panel behaviors.
 *
 * Consolidated from the per-template inline <script> blocks (#100). Each block
 * is guarded on the presence of its root element, so this single file is safe
 * to load on every CP screen. Dynamic values (action URLs, translated
 * messages) are read from data-* attributes set by the templates.
 */
(function () {
    'use strict';

    var csrf = window.Craft && Craft.csrfTokenValue;

    /**
     * POST form-encoded body, expecting a JSON `{success, error}` response.
     * Mirrors the controllers' requireAcceptsJson() contract.
     */
    function postJson(url, body, onSuccess, messages) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-Token', csrf);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onload = function () {
            var r = null;
            try { r = JSON.parse(xhr.responseText); } catch (e) { r = null; }
            if (xhr.status === 200 && r && r.success) {
                onSuccess(r);
            } else {
                Craft.cp.displayError((r && r.error) || messages.generic);
            }
        };
        xhr.onerror = function () {
            Craft.cp.displayError(messages.network || messages.generic);
        };
        xhr.send(body);
    }

    function t(message) {
        return (window.Craft && Craft.t) ? Craft.t('app', message) : message;
    }

    /**
     * Promise-based confirmation rendered as a Craft-styled <dialog> — replaces
     * the native confirm() so destructive CP actions get a consistent,
     * accessible (focus-trapped, Esc-dismissable) prompt. Resolves true when
     * confirmed, false on cancel/dismiss.
     */
    function sfConfirm(message) {
        return new Promise(function (resolve) {
            var dialog = document.createElement('dialog');
            dialog.className = 'sf-confirm';

            var msg = document.createElement('p');
            msg.className = 'sf-confirm-msg';
            msg.textContent = message;

            var actions = document.createElement('div');
            actions.className = 'sf-confirm-actions';

            var cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn';
            cancel.textContent = t('Cancel');

            var ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'btn submit';
            ok.textContent = t('OK');

            actions.appendChild(cancel);
            actions.appendChild(ok);
            dialog.appendChild(msg);
            dialog.appendChild(actions);
            document.body.appendChild(dialog);

            function done(result) {
                if (dialog.open) { dialog.close(); }
                dialog.remove();
                resolve(result);
            }
            cancel.addEventListener('click', function () { done(false); });
            ok.addEventListener('click', function () { done(true); });
            // Esc / backdrop dismissal counts as cancel.
            dialog.addEventListener('cancel', function (e) { e.preventDefault(); done(false); });

            dialog.showModal();
            ok.focus();
        });
    }

    // Any form opting into confirmation defers submit until the user agrees.
    document.querySelectorAll('form[data-sf-confirm]').forEach(function (form) {
        var armed = false;
        form.addEventListener('submit', function (e) {
            if (armed) { return; }
            e.preventDefault();
            sfConfirm(form.dataset.sfConfirm).then(function (ok) {
                if (ok) { armed = true; form.submit(); }
            });
        });
    });

    // --- Submission detail: toggle read status ---
    var toggleBtn = document.getElementById('toggle-status-btn');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            var msg = { generic: toggleBtn.dataset.error, network: toggleBtn.dataset.error };
            postJson(
                toggleBtn.dataset.action,
                'submissionId=' + toggleBtn.dataset.submissionId,
                function () { location.reload(); },
                msg,
            );
        });
    }

    // --- Integrations index: enable toggle + delete ---
    var integrations = document.getElementById('sf-integrations');
    if (integrations) {
        var msgs = {
            generic: integrations.dataset.error,
            network: integrations.dataset.networkError,
        };
        integrations.querySelectorAll('.status-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                postJson(integrations.dataset.toggleUrl, 'integrationId=' + btn.dataset.id,
                    function () { location.reload(); }, msgs);
            });
        });
        integrations.querySelectorAll('.delete[data-id]').forEach(function (el) {
            el.addEventListener('click', function () {
                sfConfirm(integrations.dataset.confirmDelete).then(function (ok) {
                    if (!ok) { return; }
                    postJson(integrations.dataset.deleteUrl, 'integrationId=' + el.dataset.id,
                        function () { location.reload(); }, msgs);
                });
            });
        });
    }

    // --- Per-form integrations: attach/detach a global integration to a form ---
    var formIntegrations = document.getElementById('sf-form-integrations');
    if (formIntegrations) {
        var fiMsgs = {
            generic: formIntegrations.dataset.error,
            network: formIntegrations.dataset.networkError,
        };
        var fiFormId = formIntegrations.dataset.formId;
        formIntegrations.querySelectorAll('.attach-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                postJson(formIntegrations.dataset.toggleUrl,
                    'formId=' + fiFormId + '&integrationId=' + btn.dataset.id,
                    function () { location.reload(); }, fiMsgs);
            });
        });
    }

    // --- Integration editor: pick a type from the dropdown, load its fields ---
    var integrationType = document.getElementById('sf-integration-type');
    if (integrationType && integrationType.dataset.newUrl) {
        integrationType.addEventListener('change', function () {
            if (!this.value) { return; }
            window.location = integrationType.dataset.newUrl + '?type=' + encodeURIComponent(this.value);
        });
    }

    // --- Settings → Spam: conditional visibility of provider/type blocks ---
    // Craft's checkboxField renders a hidden input *and* the checkbox under the
    // same name; target the checkbox specifically.
    var enableCaptcha = document.querySelector('input[type="checkbox"][name="enableCaptcha"]');
    var captchaSettings = document.getElementById('captcha-settings');
    var captchaType = document.getElementById('captchaType');

    function showCaptchaType(type) {
        document.querySelectorAll('.captcha-type-settings').forEach(function (el) {
            el.style.display = 'none';
        });
        var typeEl = document.getElementById(type + '-settings');
        if (typeEl) { typeEl.style.display = 'block'; }
    }

    if (enableCaptcha && captchaSettings) {
        enableCaptcha.addEventListener('change', function () {
            captchaSettings.style.display = this.checked ? 'block' : 'none';
        });
    }

    if (captchaType) {
        showCaptchaType(captchaType.value);
        captchaType.addEventListener('change', function () {
            showCaptchaType(this.value);
        });
    }

    var providerSelect = document.getElementById('selectedCaptchaProvider');
    function showProvider(handle) {
        document.querySelectorAll('.captcha-provider-settings').forEach(function (el) {
            el.style.display = 'none';
        });
        var el = document.getElementById(handle + '-provider-settings');
        if (el) { el.style.display = 'block'; }
    }
    if (providerSelect) {
        showProvider(providerSelect.value);
        providerSelect.addEventListener('change', function () {
            showProvider(this.value);
        });
    }

    var enableAkismet = document.querySelector('input[type="checkbox"][name="enableAkismet"]');
    var akismetSettings = document.getElementById('akismet-settings');
    if (enableAkismet && akismetSettings) {
        enableAkismet.addEventListener('change', function () {
            akismetSettings.style.display = this.checked ? 'block' : 'none';
        });
    }
})();
