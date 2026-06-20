(function() {
    'use strict';

    // Mirrors fabianhaef\simpleform\helpers\FormRows::MAX_COLUMNS — keep in sync.
    var MAX_COLUMNS = 4;

    // Pure, order-driven row grouping — parity with the PHP FormRows::group().
    // Walks fields in order; consecutive fields sharing the same positive numeric
    // config.row join one visual row (capped at MAX_COLUMNS); anything else starts
    // a new single-column row. Exported for the Node parity test.
    function rowKeyOf(f) {
        var config = (f && f.config) || {};
        var row = config.row;
        var n = (typeof row === 'number') ? row : parseInt(row, 10);
        return (!isNaN(n) && n >= 1) ? n : null;
    }
    function groupRows(list) {
        var rows = [];
        var current = [];
        var currentKey = null;
        (list || []).forEach(function(f) {
            var key = rowKeyOf(f);
            var same = key !== null && key === currentKey && current.length < MAX_COLUMNS;
            if (!same) {
                if (current.length) { rows.push(current); }
                current = [];
                currentKey = key;
            }
            current.push(f);
        });
        if (current.length) { rows.push(current); }
        return rows;
    }

    // Under Node (tests) there is no DOM, so export the pure row grouper for the
    // parity test and skip everything that touches `document`.
    if (typeof document === 'undefined') {
        if (typeof module !== 'undefined' && module.exports) {
            module.exports = { groupRows: groupRows, MAX_COLUMNS: MAX_COLUMNS };
        }
        return;
    }

    // On non-source sites the option editor is translation-only: option values
    // and the source labels are locked, and only the per-site label is editable.
    var sfBuilder = document.querySelector('.sf-builder');
    var sfData = (sfBuilder && sfBuilder.dataset) || {};
    // Source-site / volume config + initial fields are passed via data-* attrs
    // on .sf-builder so this file can ship as a static, cache-bustable asset.
    var SF_SOURCE_SITE = sfData.sourceSite === '1';
    var SF_VOLUMES = JSON.parse(sfData.volumes || '[]');

    var TYPE_LABELS = {
        text: 'Text', email: 'Email', textarea: 'Textarea', select: 'Select',
        checkbox: 'Checkbox', radio: 'Radio', date: 'Date', number: 'Number', file: 'File Upload',
        payment: 'Payment'
    };
    var OPTION_TYPES = ['select', 'checkbox', 'radio'];

    var canvas = document.getElementById('sf-canvas');
    var palette = document.getElementById('sf-palette');
    var inspector = document.getElementById('sf-inspector');
    var hidden = document.getElementById('sf-fields-data');
    if (!canvas || !hidden) { return; }

    // Programmatically focusable so focus can land here after a field is removed.
    canvas.setAttribute('tabindex', '-1');

    var seq = 0;
    function uid() { return 'new-' + (++seq) + '-' + Math.random().toString(36).slice(2, 7); }

    function slug(s) {
        var out = (s || '').toLowerCase().replace(/[^a-z0-9_]+/g, '');
        if (out && /^[0-9]/.test(out)) { out = '_' + out; }
        return out;
    }

    function normalize(f) {
        return {
            clientId: (typeof f.id === 'number') ? 'f' + f.id : (f.clientId || uid()),
            id: (typeof f.id === 'number') ? f.id : null,
            type: f.type,
            handle: f.handle || '',
            label: f.label || '',
            required: !!f.required,
            helpText: f.helpText || '',
            errorMessage: f.errorMessage || '',
            config: (f.config && typeof f.config === 'object') ? f.config : {}
        };
    }

    var initial = [];
    try { initial = JSON.parse(sfData.initialFields || '[]'); }
    catch (e) { initial = []; }
    var fields = (Array.isArray(initial) ? initial : []).map(normalize);
    var selectedId = null;

    function uniqueHandle(base, ignoreClientId) {
        base = base || 'field';
        var taken = {};
        fields.forEach(function(f) { if (f.clientId !== ignoreClientId) { taken[f.handle.toLowerCase()] = true; } });
        if (!taken[base.toLowerCase()]) { return base; }
        var n = 2;
        while (taken[(base + n).toLowerCase()]) { n++; }
        return base + n;
    }

    function defaultConfig(type) {
        if (OPTION_TYPES.indexOf(type) !== -1) {
            return { options: [{ label: 'Option 1', value: 'option1' }] };
        }
        if (type === 'payment') {
            return { amountType: 'fixed', currency: 'USD' };
        }
        return {};
    }

    // Drop an inert conditional block (disabled or with no usable rules) so the
    // payload never carries empty scaffolding the server would discard anyway.
    function cleanConfig(config) {
        var c = config.conditional;
        if (!c) { return config; }
        var hasVis = c.enabled && Array.isArray(c.rules) && c.rules.some(function(r) { return r.field; });
        var hasReq = c.enabled && c.required && c.required.enabled
            && Array.isArray(c.required.rules) && c.required.rules.some(function(r) { return r.field; });
        if (!hasVis && !hasReq) {
            var copy = {}; Object.keys(config).forEach(function(k) { if (k !== 'conditional') { copy[k] = config[k]; } });
            return copy;
        }
        return config;
    }

    function serialize() {
        hidden.value = JSON.stringify(fields.map(function(f) {
            return {
                id: f.id, type: f.type, handle: f.handle, label: f.label,
                required: f.required, helpText: f.helpText, errorMessage: f.errorMessage, config: cleanConfig(f.config)
            };
        }));
    }

    // ---- canvas rendering ------------------------------------------------

    function render() {
        Array.prototype.slice.call(canvas.querySelectorAll('.sf-field, .sf-builder-row')).forEach(function(el) { el.remove(); });
        var empty = canvas.querySelector('.sf-empty');
        if (empty) { empty.style.display = fields.length ? 'none' : ''; }
        // Group into visual rows so columns sit side by side in the builder too.
        groupRows(fields).forEach(function(row) {
            if (row.length <= 1) {
                canvas.appendChild(renderBlock(row[0]));
                return;
            }
            var wrap = document.createElement('div');
            wrap.className = 'sf-builder-row';
            wrap.dataset.cols = row.length;
            row.forEach(function(f) { wrap.appendChild(renderBlock(f)); });
            canvas.appendChild(wrap);
        });
    }

    function renderBlock(f) {
        var el = document.createElement('div');
        el.className = 'sf-field' + (f.clientId === selectedId ? ' sel' : '');
        el.setAttribute('draggable', 'true');
        // Keyboard-selectable: focusable + Enter/Space opens the inspector (#105).
        el.setAttribute('tabindex', '0');
        el.setAttribute('role', 'button');
        el.setAttribute('aria-label', (f.label || '(untitled)') + ' — ' + (TYPE_LABELS[f.type] || f.type));
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(f.clientId); focusInspector(); }
        });
        el.dataset.cid = f.clientId;

        var grip = document.createElement('span');
        grip.className = 'sf-grip'; grip.setAttribute('aria-hidden', 'true'); grip.textContent = '⋮⋮';

        var label = document.createElement('span');
        label.className = 'sf-field-label'; label.textContent = f.label || '(untitled)';

        var type = document.createElement('span');
        type.className = 'sf-field-type'; type.textContent = TYPE_LABELS[f.type] || f.type;

        var req = document.createElement('span');
        req.className = 'sf-field-req'; req.textContent = f.required ? '*' : '';

        var del = document.createElement('button');
        del.type = 'button'; del.className = 'sf-field-del'; del.title = 'Remove'; del.textContent = '×';

        el.appendChild(grip); el.appendChild(label); el.appendChild(req); el.appendChild(type); el.appendChild(del);
        return el;
    }

    // ---- mutation --------------------------------------------------------

    // Off-screen live region so screen-reader users hear add/remove (#105).
    var liveRegion = null;
    function announce(message) {
        if (!liveRegion) {
            liveRegion = document.createElement('div');
            liveRegion.className = 'sf-sr-only';
            liveRegion.setAttribute('aria-live', 'polite');
            liveRegion.setAttribute('role', 'status');
            (sfBuilder || document.body).appendChild(liveRegion);
        }
        // Clear then set on a tick so a repeated message is still announced.
        liveRegion.textContent = '';
        window.setTimeout(function() { liveRegion.textContent = message; }, 50);
    }

    function focusInspector() {
        var first = inspector.querySelector('input, select, textarea');
        if (first) { first.focus(); }
    }

    function addField(type, atIndex) {
        var label = TYPE_LABELS[type] || 'Field';
        var f = normalize({ id: null, type: type, label: label, config: defaultConfig(type) });
        f.handle = uniqueHandle(slug(label), f.clientId);
        if (atIndex == null || atIndex >= fields.length) { fields.push(f); }
        else { fields.splice(Math.max(0, atIndex), 0, f); }
        commit();
        select(f.clientId);
        // Land keyboard focus in the editor and announce the change.
        announce(label + ' field added.');
        focusInspector();
    }

    function removeField(cid) {
        fields = fields.filter(function(f) { return f.clientId !== cid; });
        if (selectedId === cid) { selectedId = null; showPalette(); }
        commit();
        announce('Field removed.');
        // Move focus somewhere sensible now that the row is gone.
        var nextField = canvas.querySelector('.sf-field');
        if (nextField && typeof nextField.focus === 'function') { nextField.focus(); }
        else { canvas.focus(); }
    }

    function select(cid) {
        selectedId = cid;
        render();
        renderInspector();
    }

    function commit() { render(); serialize(); }

    // ---- inspector -------------------------------------------------------

    function showPalette() {
        if (inspector) { inspector.hidden = true; inspector.innerHTML = ''; }
        if (palette) { palette.hidden = false; }
    }

    function field(cid) { return fields.find(function(f) { return f.clientId === cid; }); }

    function row(labelText) {
        var wrap = document.createElement('div'); wrap.className = 'field';
        var heading = document.createElement('div'); heading.className = 'heading';
        var lab = document.createElement('label'); lab.textContent = labelText;
        heading.appendChild(lab); wrap.appendChild(heading);
        var input = document.createElement('div'); input.className = 'input ltr';
        wrap.appendChild(input);
        wrap._input = input;
        return wrap;
    }

    function textInput(value, oninput, type) {
        var i = document.createElement('input');
        i.className = 'text fullwidth'; i.type = type || 'text'; i.value = value != null ? value : '';
        i.addEventListener('input', function() { oninput(i.value); });
        return i;
    }

    function renderInspector() {
        var f = field(selectedId);
        if (!f || !inspector) { showPalette(); return; }
        palette.hidden = true;
        inspector.hidden = false;
        inspector.innerHTML = '';

        var head = document.createElement('div'); head.className = 'sf-inspector-head';
        var title = document.createElement('h3'); title.className = 'sf-panel-title';
        title.textContent = (TYPE_LABELS[f.type] || f.type) + ' ' + 'field';
        var back = document.createElement('button');
        back.type = 'button'; back.className = 'btn sf-inspector-back'; back.textContent = 'Done';
        back.addEventListener('click', function() { selectedId = null; render(); showPalette(); });
        head.appendChild(title); head.appendChild(back);
        inspector.appendChild(head);

        // Label
        var labelRow = row('Label');
        labelRow._input.appendChild(textInput(f.label, function(v) {
            f.label = v; commit();
        }));
        inspector.appendChild(labelRow);

        // Handle. Renaming rewrites any conditional rules in other fields that
        // referenced the old handle, so conditions survive a rename.
        var handleRow = row('Handle');
        var handleInput = textInput(f.handle, function(v) {
            var old = f.handle;
            f.handle = v;
            if (old && old !== v) { renameHandleRefs(old, v); }
            serialize();
        });
        handleRow._input.appendChild(handleInput);
        inspector.appendChild(handleRow);

        // Required
        var reqRow = document.createElement('div'); reqRow.className = 'field';
        var reqWrap = document.createElement('div'); reqWrap.className = 'checkbox-wrapper';
        var cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'checkbox';
        cb.id = 'sf-req-' + f.clientId; cb.checked = f.required;
        cb.addEventListener('change', function() { f.required = cb.checked; commit(); });
        var cbl = document.createElement('label'); cbl.setAttribute('for', cb.id); cbl.textContent = 'Required field';
        reqWrap.appendChild(cb); reqWrap.appendChild(cbl); reqRow.appendChild(reqWrap);
        inspector.appendChild(reqRow);

        // Help text
        var helpRow = row('Help Text');
        var ta = document.createElement('textarea'); ta.className = 'text fullwidth'; ta.rows = 2; ta.value = f.helpText || '';
        ta.addEventListener('input', function() { f.helpText = ta.value; serialize(); });
        helpRow._input.appendChild(ta);
        inspector.appendChild(helpRow);

        // Custom validation message (per-site override). Blank falls back to the
        // field type's localized default message at submit time.
        var errRow = row('Error Message');
        var errInput = textInput(f.errorMessage, function(v) { f.errorMessage = v; serialize(); });
        errInput.placeholder = 'Leave blank to use the default';
        errRow._input.appendChild(errInput);
        var errHint = document.createElement('div'); errHint.className = 'instructions';
        var errHintP = document.createElement('p');
        errHintP.textContent = 'Shown for this site when the field fails validation. Leave blank to use the default (translated) message.';
        errHint.appendChild(errHintP);
        errRow._input.appendChild(errHint);
        inspector.appendChild(errRow);

        inspector.appendChild(numberRow('Step / Page', (f.config && f.config.page) || '', function(v) {
            f.config = f.config || {};
            var n = parseInt(v, 10);
            if (v === '' || v == null || isNaN(n) || n < 1) { delete f.config.page; } else { f.config.page = n; }
            commit();
        }));

        // Row: fields that share the same Row number (and Page) on consecutive
        // positions render side by side as columns (max MAX_COLUMNS). Blank = a
        // full-width, single-column field (the default).
        var rowRow = numberRow('Row', (f.config && f.config.row) || '', function(v) {
            f.config = f.config || {};
            var n = parseInt(v, 10);
            if (v === '' || v == null || isNaN(n) || n < 1) { delete f.config.row; } else { f.config.row = n; }
            commit();
        });
        var rowHint = document.createElement('div'); rowHint.className = 'instructions';
        var rowHintP = document.createElement('p');
        rowHintP.textContent = 'Give two or more adjacent fields the same Row number to lay them out side by side (up to ' + MAX_COLUMNS + ' columns).';
        rowHint.appendChild(rowHintP);
        rowRow._input.appendChild(rowHint);
        inspector.appendChild(rowRow);

        renderTypeConfig(f);
        inspector.appendChild(conditionsSection(f));
    }

    function renderTypeConfig(f) {
        var c = f.config || (f.config = {});
        if (OPTION_TYPES.indexOf(f.type) !== -1) {
            inspector.appendChild(optionsEditor(f));
        } else if (f.type === 'text' || f.type === 'textarea') {
            inspector.appendChild(numberRow('Minimum Length', c.minLength, function(v) { setNum(c, 'minLength', v); }));
            inspector.appendChild(numberRow('Maximum Length', c.maxLength, function(v) { setNum(c, 'maxLength', v); }));
        } else if (f.type === 'number') {
            inspector.appendChild(numberRow('Minimum Value', c.min, function(v) { setNum(c, 'min', v); }));
            inspector.appendChild(numberRow('Maximum Value', c.max, function(v) { setNum(c, 'max', v); }));
        } else if (f.type === 'date') {
            var fmtRow = row('Date Format');
            var sel = document.createElement('div'); sel.className = 'select';
            var s = document.createElement('select');
            [['Y-m-d', 'YYYY-MM-DD'], ['d.m.Y', 'DD.MM.YYYY'], ['m/d/Y', 'MM/DD/YYYY']].forEach(function(o) {
                var opt = document.createElement('option'); opt.value = o[0]; opt.textContent = o[1];
                if (c.format === o[0]) { opt.selected = true; }
                s.appendChild(opt);
            });
            s.addEventListener('change', function() { c.format = s.value; serialize(); });
            sel.appendChild(s); fmtRow._input.appendChild(sel);
            inspector.appendChild(fmtRow);
        } else if (f.type === 'file') {
            var volOpts = [{ value: '', label: '(first available)' }].concat(
                (SF_VOLUMES || []).map(function(v) { return { value: v.handle, label: v.name }; }));
            var volRow = row('Asset Volume');
            volRow._input.appendChild(selectEl(volOpts, c.volume || '', function(v) {
                if (v === '') { delete c.volume; } else { c.volume = v; } serialize();
            }));
            inspector.appendChild(volRow);

            var extRow = row('Allowed Extensions');
            extRow._input.appendChild(textInput(c.allowedExtensions || '', function(v) {
                if (v.trim() === '') { delete c.allowedExtensions; } else { c.allowedExtensions = v; } serialize();
            }));
            inspector.appendChild(extRow);

            inspector.appendChild(numberRow('Max Size (MB)', c.maxSize, function(v) { setNum(c, 'maxSize', v); }));

            var multRow = row('Allow Multiple Files');
            var cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = !!c.multiple;
            cb.addEventListener('change', function() { c.multiple = cb.checked; serialize(); });
            multRow._input.appendChild(cb);
            inspector.appendChild(multRow);
        } else if (f.type === 'payment') {
            var atRow = row('Amount Type');
            atRow._input.appendChild(selectEl(
                [{ value: 'fixed', label: 'Fixed amount' }, { value: 'field', label: 'From a field' }],
                c.amountType || 'fixed',
                function(v) { c.amountType = v; serialize(); }
            ));
            inspector.appendChild(atRow);

            inspector.appendChild(numberRow('Fixed Amount', c.amount, function(v) { setNum(c, 'amount', v); }));

            var afRow = row('Amount Field Handle');
            afRow._input.appendChild(textInput(c.amountField || '', function(v) {
                if (v.trim() === '') { delete c.amountField; } else { c.amountField = v.trim(); } serialize();
            }));
            inspector.appendChild(afRow);

            var curRow = row('Currency');
            curRow._input.appendChild(textInput(c.currency || 'USD', function(v) {
                c.currency = v.trim().toUpperCase(); serialize();
            }));
            inspector.appendChild(curRow);
        }
    }

    function setNum(c, key, v) {
        if (v === '' || v == null) { delete c[key]; } else { c[key] = parseFloat(v); }
        serialize();
    }

    function numberRow(labelText, value, oninput) {
        var r = row(labelText);
        r._input.appendChild(textInput(value, oninput, 'number'));
        return r;
    }

    function optionsEditor(f) {
        var c = f.config || (f.config = {});
        if (!Array.isArray(c.options)) { c.options = []; }
        var wrap = document.createElement('div'); wrap.className = 'field sf-options';
        var heading = document.createElement('div'); heading.className = 'heading';
        var lab = document.createElement('label'); lab.textContent = SF_SOURCE_SITE ? 'Options' : 'Option Labels (this site)';
        heading.appendChild(lab); wrap.appendChild(heading);

        if (!SF_SOURCE_SITE) {
            var hint = document.createElement('div'); hint.className = 'instructions';
            var hp = document.createElement('p');
            hp.textContent = 'Translate each option for this site. Leave blank to use the source label. Option values are managed on the primary site.';
            hint.appendChild(hp); wrap.appendChild(hint);
        }

        var list = document.createElement('div'); list.className = 'sf-options-list';
        wrap.appendChild(list);

        // Source site: full structural editor (label, value, add/remove).
        function sourceRow(opt, idx) {
            var r = document.createElement('div'); r.className = 'sf-option-row';
            var li = document.createElement('input'); li.type = 'text'; li.className = 'text'; li.placeholder = 'Label'; li.value = opt.label || '';
            var vi = document.createElement('input'); vi.type = 'text'; vi.className = 'text'; vi.placeholder = 'Value'; vi.value = opt.value || '';
            li.addEventListener('input', function() { opt.label = li.value; serialize(); });
            vi.addEventListener('input', function() { opt.value = vi.value; serialize(); });
            var del = document.createElement('button'); del.type = 'button'; del.className = 'btn sf-option-del'; del.textContent = '×';
            del.addEventListener('click', function() { c.options.splice(idx, 1); redraw(); serialize(); });
            r.appendChild(li); r.appendChild(vi); r.appendChild(del);
            return r;
        }

        // Non-source site: translation-only. Source label is shown read-only and
        // the per-site label rides with its option (keeping it value-aligned).
        function translateRow(opt) {
            var r = document.createElement('div'); r.className = 'sf-option-row sf-option-row--translate';
            var src = document.createElement('span'); src.className = 'sf-option-source'; src.textContent = opt.label || opt.value || '';
            var ti = document.createElement('input'); ti.type = 'text'; ti.className = 'text fullwidth';
            ti.placeholder = opt.label || opt.value || 'Label';
            ti.value = opt.siteLabel || '';
            ti.addEventListener('input', function() { opt.siteLabel = ti.value; serialize(); });
            r.appendChild(src); r.appendChild(ti);
            return r;
        }

        function redraw() {
            list.innerHTML = '';
            c.options.forEach(function(opt, idx) {
                list.appendChild(SF_SOURCE_SITE ? sourceRow(opt, idx) : translateRow(opt));
            });
        }
        redraw();

        if (SF_SOURCE_SITE) {
            var add = document.createElement('button'); add.type = 'button'; add.className = 'btn sf-option-add'; add.textContent = 'Add Option';
            add.addEventListener('click', function() {
                c.options.push({ label: '', value: '' }); redraw(); serialize();
            });
            wrap.appendChild(add);
        }
        return wrap;
    }

    // ---- conditional logic -----------------------------------------------

    // Operators offered in the rule builder. `noValue` ops hide the value input.
    var OPERATORS = [
        { op: 'eq', label: 'is' },
        { op: 'neq', label: 'is not' },
        { op: 'empty', label: 'is empty', noValue: true },
        { op: 'notEmpty', label: 'is not empty', noValue: true },
        { op: 'contains', label: 'contains' },
        { op: 'gt', label: 'greater than' },
        { op: 'lt', label: 'less than' }
    ];

    // Fields the current field may reference: every other field with a handle.
    function targetFields(self) {
        return fields.filter(function(t) { return t.clientId !== self.clientId && t.handle; });
    }

    function fieldByHandle(handle) {
        return fields.find(function(t) { return t.handle === handle; });
    }

    // Rewrite rule references when a field handle is renamed.
    function renameHandleRefs(oldHandle, newHandle) {
        fields.forEach(function(f) {
            var c = f.config && f.config.conditional;
            if (!c) { return; }
            [c.rules, (c.required && c.required.rules)].forEach(function(rules) {
                if (!Array.isArray(rules)) { return; }
                rules.forEach(function(r) { if (r.field === oldHandle) { r.field = newHandle; } });
            });
        });
    }

    function selectEl(options, value, onchange) {
        var sel = document.createElement('div'); sel.className = 'select';
        var s = document.createElement('select');
        options.forEach(function(o) {
            var opt = document.createElement('option');
            opt.value = o.value; opt.textContent = o.label;
            if (o.value === value) { opt.selected = true; }
            s.appendChild(opt);
        });
        s.addEventListener('change', function() { onchange(s.value); });
        sel.appendChild(s);
        return sel;
    }

    // One "[field] [operator] [value]" rule row.
    function ruleRow(self, rule, onRemove, onChange) {
        var rowEl = document.createElement('div'); rowEl.className = 'sf-cond-rule';

        var targets = targetFields(self);
        var fieldOpts = [{ value: '', label: '— field —' }].concat(targets.map(function(t) {
            return { value: t.handle, label: t.label || t.handle };
        }));
        rowEl.appendChild(selectEl(fieldOpts, rule.field || '', function(v) { rule.field = v; onChange(true); }));

        rowEl.appendChild(selectEl(OPERATORS.map(function(o) { return { value: o.op, label: o.label }; }),
            rule.operator || 'eq', function(v) { rule.operator = v; onChange(true); }));

        var opDef = OPERATORS.find(function(o) { return o.op === (rule.operator || 'eq'); });
        if (!opDef || !opDef.noValue) {
            var target = fieldByHandle(rule.field);
            var valueCell;
            if (target && OPTION_TYPES.indexOf(target.type) !== -1) {
                // Value is one of the target's option values.
                var opts = [{ value: '', label: '— value —' }].concat(((target.config && target.config.options) || []).map(function(o) {
                    return { value: o.value, label: o.label || o.value };
                }));
                valueCell = selectEl(opts, rule.value != null ? rule.value : '', function(v) { rule.value = v; onChange(false); });
            } else {
                var inputType = (target && target.type === 'number') ? 'number' : (target && target.type === 'date' ? 'date' : 'text');
                valueCell = textInput(rule.value != null ? rule.value : '', function(v) { rule.value = v; onChange(false); }, inputType);
                valueCell.classList.add('sf-cond-value');
            }
            rowEl.appendChild(valueCell);
        }

        var del = document.createElement('button');
        del.type = 'button'; del.className = 'btn sf-cond-del'; del.textContent = '×'; del.title = 'Remove rule';
        del.addEventListener('click', onRemove);
        rowEl.appendChild(del);
        return rowEl;
    }

    // A rule list with a match (all/any) selector and an "Add rule" button.
    // `block` is the object holding `match` + `rules` (the visibility config or
    // the required sub-block). `rerender` redraws the whole conditions section.
    function ruleList(self, block, rerender) {
        var wrap = document.createElement('div'); wrap.className = 'sf-cond-rules';

        var matchRow = document.createElement('div'); matchRow.className = 'sf-cond-match';
        var pre = document.createElement('span'); pre.textContent = 'Match';
        matchRow.appendChild(pre);
        matchRow.appendChild(selectEl([{ value: 'all', label: 'all' }, { value: 'any', label: 'any' }],
            block.match || 'all', function(v) { block.match = v; serialize(); }));
        var post = document.createElement('span'); post.textContent = 'of:';
        matchRow.appendChild(post);
        wrap.appendChild(matchRow);

        if (!Array.isArray(block.rules)) { block.rules = []; }
        block.rules.forEach(function(rule, idx) {
            wrap.appendChild(ruleRow(self, rule,
                function() { block.rules.splice(idx, 1); serialize(); rerender(); },
                // Changing the target field also changes the value widget, so
                // redraw on field/operator change; a value-only change just saves.
                function(needsRedraw) { serialize(); if (needsRedraw) { rerender(); } }
            ));
        });

        var add = document.createElement('button');
        add.type = 'button'; add.className = 'btn sf-cond-add'; add.textContent = 'Add rule';
        add.addEventListener('click', function() {
            block.rules.push({ field: '', operator: 'eq', value: '' });
            serialize(); rerender();
        });
        wrap.appendChild(add);
        return wrap;
    }

    function checkbox(id, labelText, checked, onchange) {
        var rowEl = document.createElement('div'); rowEl.className = 'field';
        var w = document.createElement('div'); w.className = 'checkbox-wrapper';
        var cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'checkbox'; cb.id = id; cb.checked = !!checked;
        cb.addEventListener('change', function() { onchange(cb.checked); });
        var l = document.createElement('label'); l.setAttribute('for', id); l.textContent = labelText;
        w.appendChild(cb); w.appendChild(l); rowEl.appendChild(w);
        return rowEl;
    }

    function conditionsSection(f) {
        var c = f.config.conditional || (f.config.conditional = {});
        var wrap = document.createElement('div'); wrap.className = 'sf-cond';

        var hr = document.createElement('hr'); wrap.appendChild(hr);
        var title = document.createElement('h3'); title.className = 'sf-panel-title'; title.textContent = 'Conditions';
        wrap.appendChild(title);

        var others = targetFields(f);
        if (!others.length) {
            var none = document.createElement('p'); none.className = 'light';
            none.textContent = 'Add another field first to base conditions on it.';
            wrap.appendChild(none);
            return wrap;
        }

        function rerender() {
            var fresh = conditionsSection(f);
            wrap.parentNode.replaceChild(fresh, wrap);
        }

        wrap.appendChild(checkbox('sf-cond-enable-' + f.clientId, 'Enable conditional logic', c.enabled, function(on) {
            c.enabled = on; serialize(); rerender();
        }));

        if (!c.enabled) { return wrap; }

        // Visibility block.
        var visLabel = document.createElement('div'); visLabel.className = 'sf-cond-line';
        visLabel.appendChild(selectEl([{ value: 'show', label: 'Show' }, { value: 'hide', label: 'Hide' }],
            c.action || 'show', function(v) { c.action = v; serialize(); }));
        var visText = document.createElement('span'); visText.textContent = 'this field when';
        visLabel.appendChild(visText);
        wrap.appendChild(visLabel);
        wrap.appendChild(ruleList(f, c, rerender));

        // Conditional-required block.
        var req = c.required || (c.required = { enabled: false, match: 'all', rules: [] });
        wrap.appendChild(checkbox('sf-cond-req-' + f.clientId, 'Make this field required when…', req.enabled, function(on) {
            req.enabled = on; serialize(); rerender();
        }));
        if (req.enabled) {
            wrap.appendChild(ruleList(f, req, rerender));
        }

        return wrap;
    }

    // ---- canvas events: select / delete ---------------------------------

    canvas.addEventListener('click', function(e) {
        var del = e.target.closest('.sf-field-del');
        if (del) { e.preventDefault(); removeField(del.closest('.sf-field').dataset.cid); return; }
        var block = e.target.closest('.sf-field');
        if (block) { select(block.dataset.cid); }
    });

    // ---- drag & drop -----------------------------------------------------

    var dragCid = null;

    if (palette) {
        Array.prototype.slice.call(palette.querySelectorAll('.sf-palette-item')).forEach(function(item) {
            item.addEventListener('click', function() { addField(item.dataset.type, null); });
            item.addEventListener('dragstart', function(e) {
                e.dataTransfer.effectAllowed = 'copy';
                e.dataTransfer.setData('text/sf-new', item.dataset.type);
            });
        });
    }

    canvas.addEventListener('dragstart', function(e) {
        var block = e.target.closest('.sf-field');
        if (block) {
            dragCid = block.dataset.cid;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/sf-move', dragCid);
            block.classList.add('dragging');
        }
    });

    canvas.addEventListener('dragend', function() {
        dragCid = null;
        Array.prototype.slice.call(canvas.querySelectorAll('.sf-field')).forEach(function(el) { el.classList.remove('dragging', 'drop-before', 'drop-after'); });
    });

    function indexFromEvent(e) {
        var blocks = Array.prototype.slice.call(canvas.querySelectorAll('.sf-field'));
        for (var i = 0; i < blocks.length; i++) {
            var rect = blocks[i].getBoundingClientRect();
            if (e.clientY < rect.top + rect.height / 2) { return i; }
        }
        return blocks.length;
    }

    canvas.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = dragCid ? 'move' : 'copy';
    });

    canvas.addEventListener('drop', function(e) {
        e.preventDefault();
        var index = indexFromEvent(e);
        var newType = e.dataTransfer.getData('text/sf-new');
        if (newType) { addField(newType, index); return; }
        var moveCid = e.dataTransfer.getData('text/sf-move') || dragCid;
        if (moveCid) {
            var from = fields.findIndex(function(f) { return f.clientId === moveCid; });
            if (from === -1) { return; }
            var moved = fields.splice(from, 1)[0];
            if (index > from) { index--; }
            fields.splice(Math.min(index, fields.length), 0, moved);
            commit();
        }
    });

    // ---- submit guard ----------------------------------------------------

    function clientErrors() {
        var errs = [], seen = {};
        fields.forEach(function(f, i) {
            var name = f.label || f.handle || ('#' + (i + 1));
            if (!f.label || !f.label.trim()) { errs.push('Field "' + name + '": label is required.'); }
            if (!f.handle || !f.handle.trim()) { errs.push('Field "' + name + '": handle is required.'); }
            else if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(f.handle)) { errs.push('Field "' + name + '": invalid handle.'); }
            else { var k = f.handle.toLowerCase(); if (seen[k]) { errs.push('Duplicate handle "' + f.handle + '".'); } seen[k] = true; }
            if (OPTION_TYPES.indexOf(f.type) !== -1) {
                var opts = (f.config && f.config.options) || [];
                if (!opts.length) { errs.push('Field "' + name + '": needs at least one option.'); }
            }
        });
        return errs;
    }

    var formEl = document.getElementById('main-form') || (hidden.form);
    if (formEl) {
        formEl.addEventListener('submit', function(e) {
            serialize();
            var errs = clientErrors();
            if (errs.length) {
                e.preventDefault();
                e.stopImmediatePropagation();
                // Surface every validation problem, not just the first, via
                // Craft's notice stack (no native alert()).
                if (window.Craft && Craft.cp && Craft.cp.displayError) {
                    errs.forEach(function(msg) { Craft.cp.displayError(msg); });
                }
            }
        });
    }

    // ---- init ------------------------------------------------------------
    render();
    serialize();
})();
