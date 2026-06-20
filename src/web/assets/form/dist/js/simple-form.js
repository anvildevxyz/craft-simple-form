(function () {
    "use strict";

    // ---- conditional logic evaluator -------------------------------------
    // Mirrors the PHP ConditionalEvaluator (src/helpers/ConditionalEvaluator.php)
    // exactly: same operators and the same show/hide + match-all/any semantics.
    // The server is authoritative; this is for live UX. Keep the two in sync —
    // tests/js/conditional-evaluator.test.js asserts parity against the PHP cases.
    var SF = {
        scalar: function (v) {
            if (v === null || v === undefined || v === false) { return ""; }
            if (v === true) { return "1"; }
            if (Array.isArray(v)) { return ""; }
            return String(v);
        },
        isEmpty: function (v) {
            if (v === null || v === undefined || v === "" || v === false) { return true; }
            if (Array.isArray(v)) { return v.every(function (i) { return SF.isEmpty(i); }); }
            return false;
        },
        eq: function (actual, expected) {
            var exp = SF.scalar(expected);
            if (Array.isArray(actual)) {
                return actual.some(function (i) { return SF.scalar(i) === exp; });
            }
            return SF.scalar(actual) === exp;
        },
        contains: function (actual, expected) {
            var exp = SF.scalar(expected);
            if (exp === "") { return false; }
            if (Array.isArray(actual)) {
                return actual.some(function (i) { return SF.scalar(i) === exp; });
            }
            return SF.scalar(actual).indexOf(exp) !== -1;
        },
        comparable: function (v) {
            if (typeof v === "number") { return v; }
            if (typeof v === "string" && v !== "") {
                if (!isNaN(Number(v))) { return Number(v); }
                var t = Date.parse(v);
                if (!isNaN(t)) { return t; }
            }
            return null;
        },
        compare: function (op, actual, expected) {
            switch (op) {
                case "empty": return SF.isEmpty(actual);
                case "notEmpty": return !SF.isEmpty(actual);
                case "eq": return SF.eq(actual, expected);
                case "neq": return !SF.eq(actual, expected);
                case "contains": return SF.contains(actual, expected);
                case "gt": {
                    var a = SF.comparable(actual), b = SF.comparable(expected);
                    return a !== null && b !== null && a > b;
                }
                case "lt": {
                    var c = SF.comparable(actual), d = SF.comparable(expected);
                    return c !== null && d !== null && c < d;
                }
                default: return false;
            }
        },
        rulesMatch: function (rules, match, values) {
            if (!Array.isArray(rules) || !rules.length) { return true; }
            var results = rules.map(function (r) {
                return SF.compare(r.operator || "eq", values[r.field], r.value !== undefined ? r.value : "");
            });
            return match === "any"
                ? results.indexOf(true) !== -1
                : results.indexOf(false) === -1;
        },
        isVisible: function (cond, values) {
            if (!cond || !cond.enabled || !Array.isArray(cond.rules) || !cond.rules.length) { return true; }
            var match = cond.match === "any" ? "any" : "all";
            var ok = SF.rulesMatch(cond.rules, match, values);
            return (cond.action === "hide") ? !ok : ok;
        },
        isRequiredByCondition: function (cond, values) {
            if (!cond || !cond.required || !cond.required.enabled) { return false; }
            var req = cond.required;
            if (!Array.isArray(req.rules) || !req.rules.length) { return false; }
            var match = req.match === "any" ? "any" : "all";
            return SF.rulesMatch(req.rules, match, values);
        },
        // Decide the post-submit branch from a success envelope (#133): a
        // non-empty redirectUrl navigates; otherwise the inline message shows.
        // Pure so its parity with the PHP resolution can be asserted in node.
        successAction: function (data) {
            if (data && data.redirectUrl) {
                return { action: "redirect", url: String(data.redirectUrl) };
            }
            return { action: "message", message: (data && data.message) || "" };
        }
    };

    // ---- per-form conditional wiring -------------------------------------

    // Current value of a field group, keyed by what its inputs are.
    function groupValue(group) {
        var checkboxes = group.querySelectorAll("input[type=checkbox]");
        if (checkboxes.length) {
            var checked = [];
            checkboxes.forEach(function (c) { if (c.checked) { checked.push(c.value); } });
            // A lone checkbox reads as its value (or empty), a group as an array.
            return checkboxes.length === 1 ? (checkboxes[0].checked ? checkboxes[0].value : "") : checked;
        }
        var radios = group.querySelectorAll("input[type=radio]");
        if (radios.length) {
            var sel = "";
            radios.forEach(function (r) { if (r.checked) { sel = r.value; } });
            return sel;
        }
        var single = group.querySelector("select, textarea, input");
        return single ? single.value : "";
    }

    // Inputs in a group that can carry the `required` constraint (checkbox
    // groups are left to the server, since per-box required is wrong).
    function requirable(group) {
        return group.querySelectorAll("select, textarea, input:not([type=checkbox])");
    }

    function applyConditions(form, groups) {
        var values = {};
        groups.forEach(function (g) { values[g.dataset.sfHandle] = groupValue(g); });

        groups.forEach(function (g) {
            if (!g._sfCond) { return; }
            var visible = SF.isVisible(g._sfCond, values);
            g.style.display = visible ? "" : "none";
            g.querySelectorAll("input, select, textarea").forEach(function (el) {
                el.disabled = !visible; // disabled inputs are excluded from the POST
            });

            var required = visible && (g._sfBaseRequired || SF.isRequiredByCondition(g._sfCond, values));
            requirable(g).forEach(function (el) {
                if (required) { el.setAttribute("required", "required"); }
                else { el.removeAttribute("required"); }
            });
        });
    }

    function initConditions(form) {
        var groups = Array.prototype.slice.call(form.querySelectorAll("[data-sf-handle]"));
        if (!groups.length) { return; }

        groups.forEach(function (g) {
            g._sfBaseRequired = !!g.querySelector("[required]");
            var raw = g.getAttribute("data-sf-conditional");
            if (raw) {
                try { g._sfCond = JSON.parse(raw); } catch (e) { g._sfCond = null; }
            }
        });

        var hasConditions = groups.some(function (g) { return g._sfCond; });
        if (!hasConditions) { return; }

        var rerun = function () { applyConditions(form, groups); };
        form.addEventListener("input", rerun);
        form.addEventListener("change", rerun);
        rerun(); // set initial visibility before first paint of interaction
    }

    // ---- multi-step navigation -------------------------------------------
    // Reveals one .simple-form-step at a time with next/back. Each step is
    // validated (native HTML5 validity) before advancing; conditionally-hidden
    // inputs are disabled by initConditions, so they're skipped here. The server
    // still validates the whole submission, so this is UX only.

    function initSteps(form) {
        var nav = form.querySelector(".simple-form-step-nav");
        if (!nav) { return; }
        var steps = Array.prototype.slice.call(form.querySelectorAll(".simple-form-step"));
        if (steps.length < 2) { return; }

        var backBtn = nav.querySelector(".simple-form-step-back");
        var nextBtn = nav.querySelector(".simple-form-step-next");
        var submitBtn = nav.querySelector(".simple-form-submit-btn");
        var progress = nav.querySelector(".simple-form-step-progress");
        var current = 0;

        function render() {
            steps.forEach(function (s, i) { s.hidden = i !== current; });
            var last = current === steps.length - 1;
            if (backBtn) { backBtn.hidden = current === 0; }
            if (nextBtn) { nextBtn.hidden = last; }
            if (submitBtn) { submitBtn.hidden = !last; }
            if (progress) { progress.textContent = "Step " + (current + 1) + " of " + steps.length; }
        }

        function currentStepValid() {
            var controls = steps[current].querySelectorAll("input, select, textarea");
            for (var i = 0; i < controls.length; i++) {
                var c = controls[i];
                if (c.disabled) { continue; }
                if (typeof c.checkValidity === "function" && !c.checkValidity()) {
                    if (typeof c.reportValidity === "function") { c.reportValidity(); }
                    return false;
                }
            }
            return true;
        }

        if (nextBtn) {
            nextBtn.addEventListener("click", function () {
                if (currentStepValid() && current < steps.length - 1) { current++; render(); }
            });
        }
        if (backBtn) {
            backBtn.addEventListener("click", function () {
                if (current > 0) { current--; render(); }
            });
        }

        render();
    }

    // ---- submit ----------------------------------------------------------

    // ---- save & resume ---------------------------------------------------
    // The "Save & continue later" button posts the current values to the
    // save-draft endpoint and shows a resume link the visitor can return to.
    function showResumeLink(form, url) {
        var existing = form.querySelector(".simple-form-resume-link");
        if (existing) { existing.remove(); }

        var wrap = document.createElement("div");
        wrap.className = "simple-form-resume-link";
        wrap.setAttribute("role", "status");
        wrap.setAttribute("tabindex", "-1");

        var label = document.createElement("p");
        label.textContent = form.getAttribute("data-sf-resume-label") || "Saved. Use this link to continue later:";
        wrap.appendChild(label);

        var row = document.createElement("div");
        row.className = "simple-form-resume-row";

        var input = document.createElement("input");
        input.type = "text";
        input.readOnly = true;
        input.value = url;
        input.className = "simple-form-resume-url";
        row.appendChild(input);

        var copy = document.createElement("button");
        copy.type = "button";
        copy.className = "simple-form-resume-copy";
        var copyLabel = form.getAttribute("data-sf-resume-copy") || "Copy";
        copy.textContent = copyLabel;
        copy.addEventListener("click", function () {
            input.select();
            if (navigator.clipboard) { navigator.clipboard.writeText(url); }
            else { try { document.execCommand("copy"); } catch (e) {} }
            copy.textContent = form.getAttribute("data-sf-resume-copied") || "Copied";
            setTimeout(function () { copy.textContent = copyLabel; }, 2000);
        });
        row.appendChild(copy);
        wrap.appendChild(row);

        var nav = form.querySelector(".simple-form-step-nav");
        if (nav && nav.parentNode) { nav.parentNode.insertBefore(wrap, nav.nextSibling); }
        else { form.appendChild(wrap); }
        wrap.focus();
    }

    function initSaveResume(form) {
        var btn = form.querySelector(".simple-form-save-resume");
        var url = form.getAttribute("data-sf-resume");
        if (!btn || !url) { return; }

        btn.addEventListener("click", function () {
            var formData = new FormData(form);
            var token = form.getAttribute("data-sf-resume-token");
            if (token) { formData.set("sfresume", token); }
            btn.disabled = true;
            fetch(url, {
                method: "POST",
                body: formData,
                headers: { "X-Requested-With": "XMLHttpRequest" }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    btn.disabled = false;
                    if (!data || !data.success || !data.token) { return; }
                    // Reuse the same draft on the next save.
                    form.setAttribute("data-sf-resume-token", data.token);
                    var resumeUrl = location.origin + location.pathname + "?sfresume=" + encodeURIComponent(data.token);
                    showResumeLink(form, resumeUrl);
                })
                .catch(function () { btn.disabled = false; });
        });
    }

    function initForm(form) {
        if (form.dataset.simpleFormBound === "1") {
            return;
        }
        form.dataset.simpleFormBound = "1";

        initConditions(form);
        initSteps(form);
        initSaveResume(form);

        // Remove any prior error state so re-submits don't stack duplicate
        // messages and resolved fields lose their invalid wiring (a11y, #105).
        function clearErrors() {
            form.querySelectorAll(".form-error").forEach(function (el) { el.remove(); });
            form.querySelectorAll("[aria-invalid=\"true\"]").forEach(function (el) {
                el.removeAttribute("aria-invalid");
                el.removeAttribute("aria-describedby");
            });
        }

        // Build an error container. Messages are added as text nodes (never
        // innerHTML) so server-supplied strings can't inject markup.
        function makeErrorDiv(messages, extraClass) {
            var div = document.createElement("div");
            div.className = "form-error" + (extraClass ? " " + extraClass : "");
            div.setAttribute("role", "alert");
            messages.forEach(function (msg, i) {
                if (i > 0) { div.appendChild(document.createElement("br")); }
                div.appendChild(document.createTextNode(String(msg)));
            });
            return div;
        }

        // Insert a focusable form-level error banner at the top of the form.
        function showGeneralError(messages) {
            var general = makeErrorDiv(messages, "form-error--general");
            general.setAttribute("tabindex", "-1");
            form.insertBefore(general, form.firstChild);
            general.focus();
        }

        // Replace the form with a focusable role="status" success node. The
        // message is set via textContent (never innerHTML) so a server-supplied
        // string can't inject markup (a11y parity with the error path, #105).
        function showSuccess(message) {
            var existing = form.parentNode
                ? form.parentNode.querySelector(".simple-form-success") : null;
            if (existing) { existing.remove(); }

            var node = document.createElement("div");
            node.className = "simple-form-success";
            node.setAttribute("role", "status");
            node.setAttribute("tabindex", "-1");
            node.textContent = message || "Thank you! Your submission has been received.";

            form.hidden = true;
            if (form.parentNode) { form.parentNode.insertBefore(node, form.nextSibling); }
            else { form.appendChild(node); }
            node.focus();
        }

        form.addEventListener("submit", function (e) {
            e.preventDefault();
            var formData = new FormData(form); // disabled (hidden) inputs are excluded
            fetch(form.action, {
                method: "POST",
                body: formData,
                headers: {
                    "X-Requested-With": "XMLHttpRequest"
                }
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    clearErrors();
                    if (data.success) {
                        var next = SF.successAction(data);
                        // A resolved redirect wins: navigate the browser to the
                        // post-submit URL (thank-you page / entry / external).
                        if (next.action === "redirect") {
                            window.location.assign(next.url);
                            return;
                        }
                        // Otherwise replace the form with an inline success node.
                        showSuccess(next.message);
                        form.reset();
                    } else if (data.errors) {
                        var generalErrors = [];
                        Object.keys(data.errors).forEach(function (fieldKey) {
                            var errorMessages = data.errors[fieldKey];
                            var fieldElement = form.querySelector("[name=\"" + fieldKey + "\"]")
                                || form.querySelector("[name=\"" + fieldKey + "[]\"]");
                            if (fieldElement) {
                                var errorId = "sf-error-" + (fieldElement.id || fieldKey).replace(/[^\w-]/g, "-");
                                var errorDiv = makeErrorDiv(errorMessages);
                                errorDiv.id = errorId;
                                // Tie the message to the control for assistive tech.
                                fieldElement.setAttribute("aria-invalid", "true");
                                fieldElement.setAttribute("aria-describedby", errorId);
                                fieldElement.parentNode.appendChild(errorDiv);
                            } else {
                                // No matching field (e.g. a form-level or rate-limit
                                // error): surface it in a banner instead of dropping it.
                                generalErrors = generalErrors.concat(errorMessages);
                            }
                        });
                        // Move focus to the first field that needs attention, else
                        // the general banner.
                        var firstInvalid = form.querySelector("[aria-invalid=\"true\"]");
                        if (firstInvalid) {
                            firstInvalid.focus();
                            if (generalErrors.length) { showGeneralError(generalErrors); firstInvalid.focus(); }
                        } else if (generalErrors.length) {
                            showGeneralError(generalErrors);
                        }
                    }
                })
                .catch(function (error) {
                    // Non-JSON / network failure: the .then above never ran, so the
                    // user has no feedback. Show the configured (localized) error.
                    console.error("Form submission error:", error);
                    clearErrors();
                    showGeneralError([form.getAttribute("data-sf-error") || "Something went wrong. Please try again."]);
                });
        });
    }

    function init() {
        document.querySelectorAll(".simple-form").forEach(initForm);
    }

    // Browser: auto-init. Under Node (tests) there is no document, so export the
    // pure evaluator instead so its parity with the PHP version can be asserted.
    if (typeof document !== "undefined") {
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", init);
        } else {
            init();
        }
    } else if (typeof module !== "undefined" && module.exports) {
        module.exports = { SF: SF };
    }
})();
