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
                if (!confirm(integrations.dataset.confirmDelete)) { return; }
                postJson(integrations.dataset.deleteUrl, 'integrationId=' + el.dataset.id,
                    function () { location.reload(); }, msgs);
            });
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
