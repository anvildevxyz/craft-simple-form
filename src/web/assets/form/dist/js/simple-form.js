(function () {
    "use strict";

    // ---- conditional logic evaluator -------------------------------------
    // Mirrors the PHP ConditionalEvaluator (src/helpers/ConditionalEvaluator.php)
    // exactly: same operators and the same show/hide + match-all/any semantics.
    // The server is authoritative; this is for live UX. Keep the two in sync —
    // tests/js/conditional-evaluator.test.js asserts parity against the PHP cases.
    var SF = {
        // ---- front-end hook API (#220) -----------------------------------
        // Dispatch a namespaced CustomEvent on the form element so host pages can
        // observe (and, when cancelable, veto) the form lifecycle. Returns false
        // only when a cancelable event was preventDefault()-ed by a listener.
        // Event names: simpleform:beforeSubmit (cancelable), simpleform:afterSubmit,
        // simpleform:validationFailed, simpleform:stepChange.
        emit: function (form, name, detail, cancelable) {
            if (!form || typeof form.dispatchEvent !== "function" || typeof CustomEvent === "undefined") {
                return true;
            }
            var evt = new CustomEvent("simpleform:" + name, {
                bubbles: true,
                cancelable: !!cancelable,
                detail: detail || {}
            });
            return form.dispatchEvent(evt);
        },
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

    // ---- calculation formula engine --------------------------------------
    // Mirrors the PHP Formula helper (src/helpers/Formula.php): same allow-listed
    // grammar (numbers, + - * /, parentheses, {handle} refs, and the functions
    // min max round ceil floor abs). NO eval/Function — a hand-written tokenizer
    // + recursive-descent evaluator. The server recompute is authoritative; this
    // is cosmetic live UX. Keep in sync — tests/js/formula.test.js asserts parity.
    var Formula = (function () {
        var FUNCS = { min: null, max: null, round: null, ceil: 1, floor: 1, abs: 1 };

        function tokenize(src) {
            var tokens = [];
            var i = 0;
            var n = src.length;
            while (i < n) {
                var c = src[i];
                if (/\s/.test(c)) { i++; continue; }
                if (/[0-9]/.test(c) || (c === "." && i + 1 < n && /[0-9]/.test(src[i + 1]))) {
                    var num = "";
                    var dot = false;
                    while (i < n && (/[0-9]/.test(src[i]) || src[i] === ".")) {
                        if (src[i] === ".") { if (dot) { throw new Error("number"); } dot = true; }
                        num += src[i]; i++;
                    }
                    tokens.push({ type: "number", value: num });
                } else if (c === "{") {
                    var close = src.indexOf("}", i);
                    if (close === -1) { throw new Error("ref"); }
                    var handle = src.slice(i + 1, close);
                    if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(handle)) { throw new Error("ref"); }
                    tokens.push({ type: "ref", value: handle });
                    i = close + 1;
                } else if (/[a-zA-Z]/.test(c)) {
                    var word = "";
                    while (i < n && /[a-zA-Z0-9_]/.test(src[i])) { word += src[i]; i++; }
                    if (!Object.prototype.hasOwnProperty.call(FUNCS, word.toLowerCase())) { throw new Error("func"); }
                    tokens.push({ type: "func", value: word.toLowerCase() });
                } else if ("+-*/(),".indexOf(c) !== -1) {
                    tokens.push({ type: c, value: c }); i++;
                } else {
                    throw new Error("char");
                }
            }
            return tokens;
        }

        function evaluate(src, refs) {
            var tokens = tokenize(src);
            if (!tokens.length) { return 0; }
            var pos = 0;

            function peek() { return tokens[pos] || null; }
            function isType(t) { return (tokens[pos] && tokens[pos].type) === t; }
            function expect(t) { if (!isType(t)) { throw new Error("expect"); } pos++; }

            function parseExpr() {
                var v = parseTerm();
                while (isType("+") || isType("-")) {
                    var op = tokens[pos++].type;
                    var rhs = parseTerm();
                    v = op === "+" ? v + rhs : v - rhs;
                }
                return v;
            }
            function parseTerm() {
                var v = parseFactor();
                while (isType("*") || isType("/")) {
                    var op = tokens[pos++].type;
                    var rhs = parseFactor();
                    if (op === "*") { v = v * rhs; }
                    else { v = (rhs === 0) ? 0 : v / rhs; }
                }
                return v;
            }
            function parseFactor() {
                var t = peek();
                if (!t) { throw new Error("eof"); }
                if (t.type === "+" || t.type === "-") {
                    pos++;
                    var operand = parseFactor();
                    return t.type === "-" ? -operand : operand;
                }
                if (t.type === "number") { pos++; return parseFloat(t.value); }
                if (t.type === "ref") {
                    pos++;
                    var raw = refs[t.value];
                    var num = (typeof raw === "number") ? raw : (raw !== undefined && raw !== null && raw !== "" && !isNaN(Number(raw)) ? Number(raw) : 0);
                    return num;
                }
                if (t.type === "(") { pos++; var v = parseExpr(); expect(")"); return v; }
                if (t.type === "func") { return parseFunction(t.value); }
                throw new Error("token");
            }
            function parseFunction(name) {
                pos++; expect("(");
                var args = [parseExpr()];
                while (isType(",")) { pos++; args.push(parseExpr()); }
                expect(")");
                var arity = FUNCS[name];
                if (arity !== null && args.length !== arity) { throw new Error("arity"); }
                switch (name) {
                    case "min": return Math.min.apply(null, args);
                    case "max": return Math.max.apply(null, args);
                    case "abs": return Math.abs(args[0]);
                    case "ceil": return Math.ceil(args[0]);
                    case "floor": return Math.floor(args[0]);
                    case "round":
                        if (args.length === 1) { return Math.round(args[0]); }
                        if (args.length === 2) {
                            var f = Math.pow(10, args[1]);
                            return Math.round(args[0] * f) / f;
                        }
                        throw new Error("arity");
                    default: throw new Error("func");
                }
            }

            var result = parseExpr();
            if (pos !== tokens.length) { throw new Error("trailing"); }
            return isFinite(result) ? result : 0;
        }

        function format(value, opts) {
            var decimals = Math.max(0, Math.min(6, opts.decimals || 0));
            var fixed = value.toFixed(decimals);
            if (opts.separator) {
                var parts = fixed.split(".");
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                fixed = parts.join(".");
            }
            return (opts.prefix || "") + fixed + (opts.suffix || "");
        }

        return { tokenize: tokenize, evaluate: evaluate, format: format };
    })();

    // ---- per-form calculation wiring -------------------------------------
    // For each <output data-sf-formula>, recompute live as referenced inputs
    // change and update both the displayed text and the hidden round-trip input.
    // Server is authoritative — this is cosmetic UX only.
    function initCalculations(form) {
        var outputs = Array.prototype.slice.call(form.querySelectorAll("[data-sf-formula]"));
        if (!outputs.length) { return; }

        var groups = Array.prototype.slice.call(form.querySelectorAll("[data-sf-handle]"));

        function valuesByHandle() {
            var values = {};
            groups.forEach(function (g) { values[g.dataset.sfHandle] = groupValue(g); });
            return values;
        }

        function recompute() {
            var values = valuesByHandle();
            outputs.forEach(function (out) {
                var formula = out.getAttribute("data-sf-formula") || "";
                var result;
                try { result = Formula.evaluate(formula, values); }
                catch (e) { result = 0; }
                var opts = {
                    decimals: parseInt(out.getAttribute("data-sf-decimals") || "2", 10),
                    separator: out.getAttribute("data-sf-separator") === "1",
                    prefix: out.getAttribute("data-sf-prefix") || "",
                    suffix: out.getAttribute("data-sf-suffix") || ""
                };
                out.textContent = Formula.format(result, opts);
                var hidden = form.querySelector("input[type=hidden][name=\"" + out.getAttribute("name").replace(/-display$/, "") + "\"]");
                if (hidden) { hidden.value = String(result); }
            });
        }

        form.addEventListener("input", recompute);
        form.addEventListener("change", recompute);
        recompute();
    }

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

    // The form's current answers keyed by field handle — shared by conditional
    // logic and logic-jump (#245) evaluation.
    function formValues(form) {
        var values = {};
        Array.prototype.slice.call(form.querySelectorAll("[data-sf-handle]")).forEach(function (g) {
            values[g.dataset.sfHandle] = groupValue(g);
        });
        return values;
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

    // ---- repeater rows ----------------------------------------------------
    // Add/remove repeatable rows on the public form. The server re-keys row
    // indices on submit, so cloned rows keep the prototype's __INDEX__ token
    // replaced with a monotonic counter (uniqueness, not density, matters). Add
    // disables at maxRows, Remove at minRows. With JS off the server-rendered
    // rows still submit.

    var SF_INDEX_TOKEN = "__INDEX__";

    function initRepeaters(form) {
        var repeaters = form.querySelectorAll("[data-sf-repeater]");
        if (!repeaters.length) { return; }

        Array.prototype.forEach.call(repeaters, function (rep) {
            var rows = rep.querySelector("[data-sf-repeater-rows]");
            var template = rep.querySelector("[data-sf-repeater-template]");
            var addBtn = rep.querySelector("[data-sf-repeater-add]");
            if (!rows || !template || !addBtn) { return; }

            var min = parseInt(rep.getAttribute("data-sf-min"), 10) || 0;
            var max = parseInt(rep.getAttribute("data-sf-max"), 10) || 0;
            // Seed the counter past the server-rendered rows so cloned indices
            // never collide with the initial set.
            var next = rows.querySelectorAll("[data-sf-repeater-row]").length;

            function currentRows() { return rows.querySelectorAll("[data-sf-repeater-row]"); }

            function refresh() {
                var count = currentRows().length;
                addBtn.disabled = max > 0 && count >= max;
                Array.prototype.forEach.call(rep.querySelectorAll("[data-sf-repeater-remove]"), function (btn) {
                    btn.disabled = count <= Math.max(min, 1) || count <= min;
                });
            }

            addBtn.addEventListener("click", function () {
                var count = currentRows().length;
                if (max > 0 && count >= max) { return; }
                // template.content (a DocumentFragment) holds the prototype row.
                var html = (template.innerHTML || "").split(SF_INDEX_TOKEN).join(String(next++));
                var holder = document.createElement("div");
                holder.innerHTML = html;
                var row = holder.firstElementChild;
                if (row) { rows.appendChild(row); refresh(); }
            });

            rep.addEventListener("click", function (e) {
                var removeBtn = e.target.closest && e.target.closest("[data-sf-repeater-remove]");
                if (!removeBtn) { return; }
                if (currentRows().length <= min) { return; }
                var row = removeBtn.closest("[data-sf-repeater-row]");
                if (row) { row.remove(); refresh(); }
            });

            refresh();
        });
    }

    // ---- multi-step navigation -------------------------------------------
    // Reveals one .simple-form-step at a time with next/back. Each step is
    // validated (native HTML5 validity) before advancing; conditionally-hidden
    // inputs are disabled by initConditions, so they're skipped here. The server
    // still validates the whole submission, so this is UX only.

    // Pure step/screen navigation logic (#137/#239), separated from the DOM so it
    // is unit-testable (tests/js/step-nav.test.js). Operates on an array of
    // per-step "active" flags (a step is inactive when conditional logic hid its
    // only question) and the current index.
    SF.stepNav = {
        // The next/previous active step index from `current`, or -1 when none.
        seek: function (active, current, dir) {
            for (var i = current + dir; i >= 0 && i < active.length; i += dir) {
                if (active[i]) { return i; }
            }
            return -1;
        },
        // { pos, total, back, next, submit } for `current` among the active steps.
        state: function (active, current) {
            var idx = [];
            for (var i = 0; i < active.length; i++) {
                if (active[i]) { idx.push(i); }
            }
            var total = idx.length || active.length;
            var pos = idx.indexOf(current);
            var first = idx.length ? idx[0] : 0;
            var last = idx.length ? idx[idx.length - 1] : active.length - 1;
            var isLast = current >= last;
            return {
                pos: pos >= 0 ? pos + 1 : 1,
                total: total,
                back: current > first,
                next: !isLast,
                submit: isLast
            };
        },
        // Interpolate the translated "{current} of {total}" progress template.
        progress: function (tpl, pos, total) {
            return (tpl || "{current} of {total}")
                .replace("{current}", String(pos))
                .replace("{total}", String(total));
        }
    };

    // Logic jumps (#245) — page-level branching, mirroring PHP JumpResolver so
    // the navigator and the server agree on the path. `stepRules[i]` is the list
    // of { field, operator, value, to } rules on step i (to = target index).
    SF.jumps = {
        // The next step from `current` given the answers: the first matching
        // jump's target, else the next sequential step.
        next: function (stepRules, current, values) {
            var rules = (stepRules && stepRules[current]) || [];
            for (var i = 0; i < rules.length; i++) {
                var r = rules[i];
                if (SF.compare(r.operator || "eq", values[r.field], r.value)) {
                    return r.to;
                }
            }
            return current + 1;
        },
        // The step indices reached by replaying jumps from step 0.
        reachable: function (stepRules, count, values) {
            var visited = {};
            var i = 0;
            while (i >= 0 && i < count) {
                visited[i] = true;
                var n = SF.jumps.next(stepRules, i, values);
                i = n > i ? n : i + 1;
            }
            return Object.keys(visited).map(Number);
        }
    };

    function initSteps(form) {
        var nav = form.querySelector(".simple-form-step-nav");
        if (!nav) { return; }
        var steps = Array.prototype.slice.call(form.querySelectorAll(".simple-form-step"));
        if (steps.length < 2) { return; }

        var backBtn = nav.querySelector(".simple-form-step-back");
        var nextBtn = nav.querySelector(".simple-form-step-next");
        var submitBtn = nav.querySelector(".simple-form-submit-btn");
        var progress = nav.querySelector(".simple-form-step-progress");
        var progressBar = form.querySelector("[data-sf-progressbar]");
        var progressTpl = nav.getAttribute("data-sf-progress") || "{current} of {total}";
        var current = 0;
        var history = [];
        var stepJumps = null;
        try { stepJumps = JSON.parse(form.getAttribute("data-sf-jumps") || "null"); } catch (e) { stepJumps = null; }

        // A step is "active" unless every input control it holds is conditionally
        // hidden (initConditions disables hidden fields). Inactive steps — e.g. a
        // conversational screen whose only question conditional logic hid — are
        // skipped by the navigator and excluded from the progress count.
        function stepActive(i) {
            var controls = steps[i].querySelectorAll("input, select, textarea");
            if (controls.length === 0) { return true; }
            for (var j = 0; j < controls.length; j++) {
                if (!controls[j].disabled) { return true; }
            }
            return false;
        }

        function activeFlags() {
            return steps.map(function (s, i) { return stepActive(i); });
        }

        function render() {
            steps.forEach(function (s, i) { s.hidden = i !== current; });

            var st = SF.stepNav.state(activeFlags(), current);
            if (backBtn) { backBtn.hidden = !st.back; }
            if (nextBtn) { nextBtn.hidden = !st.next; }
            if (submitBtn) { submitBtn.hidden = !st.submit; }
            if (progress) { progress.textContent = SF.stepNav.progress(progressTpl, st.pos, st.total); }
            // Built-in conversational theme progress bar (#243).
            if (progressBar) { progressBar.style.width = Math.round((st.pos / st.total) * 100) + "%"; }
        }

        // Move focus into the step on user navigation so keyboard/AT users land
        // in the new step (not on a now-hidden button). Not called on first paint.
        function focusStep() {
            var step = steps[current];
            var firstControl = step.querySelector("input:not([disabled]), select:not([disabled]), textarea:not([disabled]), button");
            if (firstControl) {
                firstControl.focus();
            } else {
                step.setAttribute("tabindex", "-1");
                step.focus();
            }
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

        function go(to, record) {
            if (to < 0 || to === current) { return; }
            var from = current;
            if (record) { history.push(from); }
            current = to;
            render();
            focusStep();
            SF.emit(form, "stepChange", { form: form, from: from, to: current, total: steps.length }, false);
        }

        // The first active step at or after `i` (a jump target may land on a
        // conditionally-hidden screen; advance to the next shown one).
        function firstActiveFrom(i) {
            while (i < steps.length && !stepActive(i)) { i++; }
            return i < steps.length ? i : -1;
        }

        // Logic jumps (#245): the next step honoring jump rules. A matched jump
        // (target beyond the next sequential step) routes to its target screen;
        // otherwise advance to the next active step. Back replays the history so
        // jumped-over screens aren't revisited.
        function nextStep() {
            var sequential = current + 1;
            var to = stepJumps ? SF.jumps.next(stepJumps, current, formValues(form)) : sequential;
            return to > sequential ? firstActiveFrom(to) : SF.stepNav.seek(activeFlags(), current, 1);
        }

        if (nextBtn) {
            nextBtn.addEventListener("click", function () {
                if (currentStepValid()) { go(nextStep(), true); }
            });
        }
        if (backBtn) {
            backBtn.addEventListener("click", function () {
                go(history.length ? history.pop() : SF.stepNav.seek(activeFlags(), current, -1), false);
            });
        }

        // Conditional logic can hide/reveal later screens as answers change, so
        // recompute the progress + button state (cheap; visibility is unchanged).
        form.addEventListener("input", render);
        form.addEventListener("change", render);

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

    // ---- passive partial capture (#242) ----------------------------------
    // When the form opted in (data-sf-capture = the capture endpoint), debounce-
    // save the answers entered so far on blur / change / step change. Best-effort:
    // any failure is swallowed so a capture problem never disrupts the form. The
    // returned token is held in the hidden partialToken input so re-captures
    // update the same partial and the final submit deletes it.
    function initPartialCapture(form) {
        var url = form.getAttribute("data-sf-capture");
        var tokenInput = form.querySelector("input[data-sf-partial-token]");
        if (!url || !tokenInput) { return; }

        var timer = null;
        var inFlight = false;

        function hasAnswers(formData) {
            var entries = formData.entries ? formData.entries() : null;
            if (!entries) { return true; }
            var e = entries.next();
            while (!e.done) {
                var key = e.value[0];
                var val = e.value[1];
                if (typeof key === "string" && key.indexOf("field_") === 0 && val !== "" && !(val instanceof File)) {
                    return true;
                }
                e = entries.next();
            }
            return false;
        }

        function capture() {
            if (inFlight) { return; }
            var formData = new FormData(form);
            // File uploads can't be captured into a partial; drop them.
            try {
                Array.prototype.slice.call(form.querySelectorAll("input[type=file]")).forEach(function (f) {
                    if (f.name) { formData.delete(f.name); }
                });
            } catch (e) { /* best-effort */ }
            if (!hasAnswers(formData)) { return; }
            if (tokenInput.value) { formData.set("partialToken", tokenInput.value); }

            inFlight = true;
            fetch(url, {
                method: "POST",
                body: formData,
                headers: { "X-Requested-With": "XMLHttpRequest" }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    inFlight = false;
                    if (data && data.success && data.token) { tokenInput.value = data.token; }
                })
                .catch(function () { inFlight = false; });
        }

        function schedule() {
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(capture, 1200);
        }

        // Blur (focusout bubbles) + change cover typed and selected answers; a
        // step change is an immediate good moment to persist progress.
        form.addEventListener("focusout", schedule);
        form.addEventListener("change", schedule);
        form.addEventListener("simpleform:stepChange", capture);
    }

    // Coupon preview (#246): the Apply button previews the discount against the
    // payment amount before submit. The code input posts as `couponCode` with the
    // form regardless; this only shows the visitor what they'll pay. The server
    // re-validates and applies the discount authoritatively at submit.
    function initCoupon(form) {
        var box = form.querySelector("[data-sf-coupon]");
        if (!box) { return; }
        var input = box.querySelector("[data-sf-coupon-input]");
        var applyBtn = box.querySelector("[data-sf-coupon-apply]");
        var message = box.querySelector("[data-sf-coupon-message]");
        var url = box.getAttribute("data-sf-coupon-url");
        if (!input || !applyBtn || !url) { return; }

        function amountHint() {
            var fixed = box.getAttribute("data-sf-amount");
            if (fixed) { return fixed; }
            var handle = box.getAttribute("data-sf-amount-field");
            if (handle) {
                var el = form.querySelector("[name=\"field_" + handle + "\"], [data-sf-handle=\"" + handle + "\"] input, [data-sf-handle=\"" + handle + "\"] select");
                if (el && el.value) { return el.value; }
            }
            return "";
        }

        function setMessage(text, isError) {
            message.textContent = text || "";
            message.classList.remove("simple-form-coupon-ok", "simple-form-coupon-error");
            if (text) { message.classList.add(isError ? "simple-form-coupon-error" : "simple-form-coupon-ok"); }
        }

        function apply() {
            var code = (input.value || "").trim();
            if (!code) { setMessage("", false); return; }

            // Post the whole form so CSRF + formHandle + field values come along;
            // override couponCode and add the amount hint for a field-based price.
            var formData = new FormData(form);
            formData.set("couponCode", code);
            var amt = amountHint();
            if (amt) { formData.set("amount", amt); }

            applyBtn.disabled = true;
            fetch(url, {
                method: "POST",
                body: formData,
                headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    applyBtn.disabled = false;
                    if (data && data.success) {
                        setMessage(data.message || "", false);
                    } else {
                        setMessage((data && data.error) || "", true);
                    }
                })
                .catch(function () {
                    applyBtn.disabled = false;
                    setMessage(box.getAttribute("data-sf-coupon-network-error") || "", true);
                });
        }

        applyBtn.addEventListener("click", apply);
        input.addEventListener("keydown", function (e) {
            if (e.key === "Enter") { e.preventDefault(); apply(); }
        });
        // A re-typed code invalidates the previous preview.
        input.addEventListener("input", function () {
            setMessage("", false);
        });
    }

    // Address autocomplete (#250): a keyless, provider-agnostic type-ahead over a
    // geocoder (Photon/Nominatim by default). Selecting a suggestion fills the
    // address sub-fields; the value is still validated + persisted server-side.
    // Entirely progressive — with no JS the plain sub-inputs work unchanged.
    var SF_GEO = {
        // Map a provider result to the address sub-field values.
        photon: function (f) {
            var p = f.properties || {};
            var line1 = [p.housenumber, p.street].filter(Boolean).join(" ") || p.name || "";
            return {
                values: {
                    line1: line1,
                    city: p.city || p.district || p.county || "",
                    state: p.state || "",
                    postalCode: p.postcode || "",
                    country: (p.countrycode || "").toUpperCase()
                },
                label: [p.name && p.name !== line1 ? p.name : null, line1, p.postcode, p.city, p.country]
                    .filter(Boolean).join(", ")
            };
        },
        nominatim: function (r) {
            var a = r.address || {};
            return {
                values: {
                    line1: [a.house_number, a.road].filter(Boolean).join(" "),
                    city: a.city || a.town || a.village || a.hamlet || a.municipality || "",
                    state: a.state || a.region || "",
                    postalCode: a.postcode || "",
                    country: (a.country_code || "").toUpperCase()
                },
                label: r.display_name || ""
            };
        }
    };

    function geoRequest(provider, endpoint, apiKey, query) {
        var q = encodeURIComponent(query);
        if (provider === "nominatim") {
            var nBase = endpoint || "https://nominatim.openstreetmap.org/search";
            return { url: nBase + "?q=" + q + "&format=jsonv2&addressdetails=1&limit=5", pick: function (d) { return Array.isArray(d) ? d : []; } };
        }
        // Default: Photon (keyless, autocomplete-oriented).
        var pBase = endpoint || "https://photon.komoot.io/api/";
        var key = apiKey ? "&api_key=" + encodeURIComponent(apiKey) : "";
        return { url: pBase + "?q=" + q + "&limit=5" + key, pick: function (d) { return (d && d.features) || []; } };
    }

    function initAddressAutocomplete(form) {
        Array.prototype.slice.call(form.querySelectorAll("[data-sf-address-autocomplete]")).forEach(function (box) {
            var input = box.querySelector("[data-sf-address-search]");
            var list = box.querySelector("[data-sf-address-suggestions]");
            var fieldset = box.closest("fieldset") || box.parentNode;
            var provider = box.getAttribute("data-provider") || "photon";
            var endpoint = box.getAttribute("data-endpoint") || "";
            var apiKey = box.getAttribute("data-api-key") || "";
            var minChars = parseInt(box.getAttribute("data-min-chars"), 10) || 3;
            var mapper = SF_GEO[provider] || SF_GEO.photon;
            var message = box.querySelector("[data-sf-address-message]");
            var errorText = box.getAttribute("data-error") || "";
            if (!input || !list) { return; }

            var timer = null, active = -1, items = [], seq = 0;

            function setMessage(text) {
                if (message) { message.textContent = text || ""; }
            }

            function close() {
                list.hidden = true; list.innerHTML = ""; active = -1; items = [];
                input.setAttribute("aria-expanded", "false");
                input.removeAttribute("aria-activedescendant");
            }

            function fillSubField(key, value) {
                var el = fieldset.querySelector("[name$=\"[" + key + "]\"]");
                if (!el || value === "" || value == null) { return; }
                // Only set a <select> value the control actually offers (country).
                if (el.tagName === "SELECT") {
                    var ok = Array.prototype.some.call(el.options, function (o) { return o.value === value; });
                    if (!ok) { return; }
                }
                el.value = value;
                el.dispatchEvent(new Event("change", { bubbles: true }));
            }

            function choose(i) {
                var it = items[i];
                if (!it) { return; }
                Object.keys(it.values).forEach(function (k) { fillSubField(k, it.values[k]); });
                input.value = it.label;
                close();
            }

            function render() {
                list.innerHTML = "";
                input.removeAttribute("aria-activedescendant");
                items.forEach(function (it, i) {
                    var li = document.createElement("li");
                    li.className = "sf-address-suggestion";
                    li.setAttribute("role", "option");
                    li.id = input.id + "-opt-" + i;
                    li.textContent = it.label;
                    li.addEventListener("mousedown", function (e) { e.preventDefault(); choose(i); });
                    list.appendChild(li);
                });
                list.hidden = items.length === 0;
                input.setAttribute("aria-expanded", items.length ? "true" : "false");
            }

            function highlight(next) {
                var opts = list.querySelectorAll(".sf-address-suggestion");
                if (!opts.length) { return; }
                if (active >= 0 && opts[active]) { opts[active].removeAttribute("aria-selected"); }
                active = (next + opts.length) % opts.length;
                opts[active].setAttribute("aria-selected", "true");
                input.setAttribute("aria-activedescendant", opts[active].id);
            }

            function search() {
                var q = input.value.trim();
                if (q.length < minChars) { close(); return; }
                // Sequence guard: a slow earlier response must not overwrite the
                // suggestions of a newer query (#250).
                var mySeq = ++seq;
                var req = geoRequest(provider, endpoint, apiKey, q);
                fetch(req.url, { headers: { "Accept": "application/json" } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (mySeq !== seq) { return; }
                        setMessage("");
                        items = req.pick(d).map(mapper).filter(function (it) {
                            return it && (it.values.line1 || it.values.city || it.values.postalCode);
                        });
                        active = -1;
                        render();
                    })
                    .catch(function () {
                        if (mySeq !== seq) { return; }
                        close();
                        // Surface the (translated) graceful-degradation message so the
                        // visitor knows to enter the address manually.
                        setMessage(errorText);
                    });
            }

            input.addEventListener("input", function () {
                if (timer) { clearTimeout(timer); }
                timer = setTimeout(search, 300);
            });
            input.addEventListener("keydown", function (e) {
                if (list.hidden) { return; }
                if (e.key === "ArrowDown") { e.preventDefault(); highlight(active + 1); }
                else if (e.key === "ArrowUp") { e.preventDefault(); highlight(active - 1); }
                else if (e.key === "Enter" && active >= 0) { e.preventDefault(); choose(active); }
                else if (e.key === "Escape") { close(); }
            });
            input.addEventListener("blur", function () { setTimeout(close, 150); });
        });
    }

    // ---- signature pad (#129) --------------------------------------------
    // Dependency-free canvas signature pad. Pointer events cover mouse, touch,
    // and stylus uniformly; the backing store is scaled to devicePixelRatio for
    // crisp lines and the rendered PNG data URL is written to the hidden input
    // on each stroke end / Clear so the current state always posts. An empty pad
    // posts an empty string → the server treats it as "no signature".
    function initSignaturePad(wrapper) {
        if (wrapper.dataset.sfSignatureBound === "1") { return; }
        wrapper.dataset.sfSignatureBound = "1";

        var canvas = wrapper.querySelector("[data-sf-signature-canvas]");
        var input = wrapper.querySelector("[data-sf-signature-input]");
        var clearBtn = wrapper.querySelector("[data-sf-signature-clear]");
        if (!canvas || !input || !canvas.getContext) { return; }

        var ctx = canvas.getContext("2d");
        var penColor = wrapper.getAttribute("data-sf-pen") || "#1a1a1a";
        var background = wrapper.getAttribute("data-sf-bg") || "#ffffff";
        var drawing = false;
        var hasInk = false;
        var lastX = 0;
        var lastY = 0;

        function ratio() {
            return Math.max(1, window.devicePixelRatio || 1);
        }

        // Size the backing store to the CSS box × DPR and paint the background.
        // Called on init and resize; resizing clears the pad (acceptable — a
        // reflow during signing is rare and the visitor can simply re-sign).
        function resize() {
            var r = ratio();
            var rect = canvas.getBoundingClientRect();
            var w = Math.max(1, Math.round(rect.width));
            var h = Math.max(1, Math.round(rect.height || 150));
            canvas.width = w * r;
            canvas.height = h * r;
            ctx.setTransform(r, 0, 0, r, 0, 0);
            ctx.fillStyle = background;
            ctx.fillRect(0, 0, w, h);
            ctx.lineWidth = 2.5;
            ctx.lineCap = "round";
            ctx.lineJoin = "round";
            ctx.strokeStyle = penColor;
            hasInk = false;
            input.value = "";
        }

        function pos(event) {
            var rect = canvas.getBoundingClientRect();
            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        }

        function serialize() {
            input.value = hasInk ? canvas.toDataURL("image/png") : "";
        }

        function start(event) {
            drawing = true;
            var p = pos(event);
            lastX = p.x;
            lastY = p.y;
            // A single dot (tap) still counts as a signature.
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(lastX + 0.01, lastY + 0.01);
            ctx.stroke();
            hasInk = true;
            if (canvas.setPointerCapture && event.pointerId !== undefined) {
                try { canvas.setPointerCapture(event.pointerId); } catch (e) { /* ignore */ }
            }
            event.preventDefault();
        }

        function move(event) {
            if (!drawing) { return; }
            var p = pos(event);
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            lastX = p.x;
            lastY = p.y;
            hasInk = true;
            event.preventDefault();
        }

        function end(event) {
            if (!drawing) { return; }
            drawing = false;
            serialize();
            if (event && event.preventDefault) { event.preventDefault(); }
        }

        function clear() {
            resize();
        }

        resize();

        canvas.style.touchAction = "none";
        canvas.addEventListener("pointerdown", start);
        canvas.addEventListener("pointermove", move);
        canvas.addEventListener("pointerup", end);
        canvas.addEventListener("pointerleave", end);
        canvas.addEventListener("pointercancel", end);
        if (clearBtn) { clearBtn.addEventListener("click", clear); }

        // Reflow with the layout, but only when the visible size actually
        // changed, so an unrelated resize doesn't wipe an in-progress signature.
        var lastW = canvas.getBoundingClientRect().width;
        window.addEventListener("resize", function () {
            var w = canvas.getBoundingClientRect().width;
            if (Math.abs(w - lastW) > 1) { lastW = w; resize(); }
        });
    }

    function initSignaturePads(form) {
        form.querySelectorAll("[data-sf-signature]").forEach(initSignaturePad);
    }

    function initForm(form) {
        if (form.dataset.simpleFormBound === "1") {
            return;
        }
        form.dataset.simpleFormBound = "1";

        initConditions(form);
        initCalculations(form);
        initRepeaters(form);
        initSteps(form);
        initSaveResume(form);
        initPartialCapture(form);
        initSignaturePads(form);
        initCoupon(form);
        initAddressAutocomplete(form);

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
            // Cancelable hook: a listener calling preventDefault() aborts the send.
            if (!SF.emit(form, "beforeSubmit", { form: form, formData: formData }, true)) {
                return;
            }
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
                    // Observable hook: fires for both success and validation failure.
                    SF.emit(form, "afterSubmit", { form: form, success: !!data.success, data: data }, false);
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
                        SF.emit(form, "validationFailed", { form: form, errors: data.errors }, false);
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

    // ---- UTM/referrer auto-capture (#249) --------------------------------
    // Fill the form's hidden __sf_attr inputs from the URL's utm_* params, the
    // referrer and the landing page. The first-seen values are persisted in
    // sessionStorage so they survive in-session navigation to the form page;
    // best-effort, so a private-mode storage failure never blocks the form.
    function captureAttribution() {
        var inputs = document.querySelectorAll("input[data-sf-attr]");
        if (!inputs.length) { return; }

        var KEY = "sfAttribution";
        var attr = null;
        try { attr = JSON.parse(sessionStorage.getItem(KEY) || "null"); } catch (e) { attr = null; }

        if (!attr || typeof attr !== "object") {
            var qs = null;
            try { qs = new URLSearchParams(window.location.search); } catch (e) { qs = null; }
            var get = function (k) { return qs ? (qs.get(k) || "") : ""; };
            attr = {
                utm_source: get("utm_source"),
                utm_medium: get("utm_medium"),
                utm_campaign: get("utm_campaign"),
                utm_term: get("utm_term"),
                utm_content: get("utm_content"),
                referrer: document.referrer || "",
                landing_page: window.location.href || ""
            };
            try { sessionStorage.setItem(KEY, JSON.stringify(attr)); } catch (e) {}
        }

        inputs.forEach(function (input) {
            var key = input.getAttribute("data-sf-attr");
            if (key && attr[key] != null) { input.value = attr[key]; }
        });
    }

    // ---- embed height sync (#247) ----------------------------------------
    // When the form is shown on its standalone page inside an embed iframe, post
    // the document height to the parent so the embed loader can size the iframe
    // to fit (no scrollbars). No-op when not embedded.
    function postEmbedHeight() {
        if (window.self === window.top) { return; }
        var send = function () {
            var h = Math.max(
                document.documentElement.scrollHeight || 0,
                document.body ? document.body.scrollHeight : 0
            );
            try { window.parent.postMessage({ type: "simpleform:height", height: h }, "*"); } catch (e) {}
        };
        send();
        window.addEventListener("load", send);
        window.addEventListener("resize", send);
        document.addEventListener("input", send, true);
        document.addEventListener("change", send, true);
        if (typeof MutationObserver !== "undefined" && document.body) {
            try {
                new MutationObserver(send).observe(document.body, { childList: true, subtree: true, attributes: true });
            } catch (e) { /* best-effort */ }
        }
    }

    function init() {
        captureAttribution();
        postEmbedHeight();
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
        module.exports = { SF: SF, Formula: Formula };
    }
})();
