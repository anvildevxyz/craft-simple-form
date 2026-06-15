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

    // ---- submit ----------------------------------------------------------

    function initForm(form) {
        if (form.dataset.simpleFormBound === "1") {
            return;
        }
        form.dataset.simpleFormBound = "1";

        initConditions(form);

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
                    if (data.success) {
                        alert(data.message || "Form submitted successfully!");
                        form.reset();
                    } else if (data.errors) {
                        Object.keys(data.errors).forEach(function (fieldKey) {
                            var errorMessages = data.errors[fieldKey];
                            var fieldElement = form.querySelector("[name=\"" + fieldKey + "\"]");
                            if (fieldElement) {
                                var errorDiv = document.createElement("div");
                                errorDiv.className = "form-error";
                                errorDiv.innerHTML = errorMessages.join("<br>");
                                fieldElement.parentNode.appendChild(errorDiv);
                            }
                        });
                    }
                })
                .catch(function (error) { console.error("Form submission error:", error); });
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
