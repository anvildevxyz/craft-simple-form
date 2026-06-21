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
        initSignaturePads(form);

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
                        alert(data.message || "Form submitted successfully!");
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
        module.exports = { SF: SF, Formula: Formula };
    }
})();
