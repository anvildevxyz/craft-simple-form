(function() {
    'use strict';

    // Mirrors anvildev\simpleform\helpers\FormRows::MAX_COLUMNS — keep in sync.
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
    // A field's relative column weight within its row (1..MAX_COLUMNS, default 1).
    function widthOf(f) {
        var config = (f && f.config) || {};
        var w = (typeof config.width === 'number') ? config.width : parseInt(config.width, 10);
        return (!isNaN(w) && w >= 1) ? Math.min(MAX_COLUMNS, w) : 1;
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
            module.exports = { groupRows: groupRows, widthOf: widthOf, MAX_COLUMNS: MAX_COLUMNS };
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
    var SF_PHONE_COUNTRIES = JSON.parse(sfData.phoneCountries || '[]');
    // Selectable sources per element-relation type (section/group/volume).
    var SF_SOURCES = JSON.parse(sfData.relationSources || '{}');

    var TYPE_LABELS = {
        text: 'Text', email: 'Email', textarea: 'Textarea', select: 'Select',
        checkbox: 'Checkbox', radio: 'Radio', date: 'Date', time: 'Time', datetime: 'Date & Time', number: 'Number',
        phone: 'Phone', file: 'File Upload',
        name: 'Name', address: 'Address',
        payment: 'Payment', hidden: 'Hidden', consent: 'Agree / Consent',
        rating: 'Rating', opinion: 'Opinion Scale', signature: 'Signature',
        entry: 'Entries', category: 'Categories', tag: 'Tags',
        user: 'Users', asset: 'Assets', calculation: 'Calculation',
        repeater: 'Repeater',
        heading: 'Heading', divider: 'Section Divider', html: 'HTML Block',
        paragraph: 'Text'
    };
    var OPTION_TYPES = ['select', 'checkbox', 'radio'];
    // Non-visible types: the visitor never sees them, so the inspector suppresses
    // the Required / Help Text / Error Message rows (#124).
    var HIDDEN_TYPES = ['hidden'];
    // Presentational/layout blocks: value-less, so the inspector omits
    // Required / validation / conditions and the submit guard skips them.
    var LAYOUT_TYPES = ['heading', 'divider', 'html', 'paragraph'];
    function isLayout(type) { return LAYOUT_TYPES.indexOf(type) !== -1; }
    var RELATION_TYPES = ['entry', 'category', 'tag', 'user', 'asset'];
    // Inner field types a repeater may contain (mirrors
    // RepeaterFieldType::ALLOWED_INNER_TYPES — keep in lockstep).
    var REPEATER_INNER_TYPES = ['text', 'email', 'number', 'select'];
    var COMPOSITE_TYPES = ['name', 'address'];

    // Ordered sub-field defs per composite type, mirroring the PHP field types:
    // [key, default label, enabled-by-default, primary]. Used to seed default
    // config and to render the sub-field editor.
    var SUBFIELD_DEFS = {
        name: [
            ['prefix', 'Prefix', false, false],
            ['first', 'First name', true, true],
            ['middle', 'Middle name', false, false],
            ['last', 'Last name', true, true],
            ['suffix', 'Suffix', false, false]
        ],
        address: [
            ['line1', 'Address line 1', true, true],
            ['line2', 'Address line 2', true, false],
            ['city', 'City', true, true],
            ['state', 'State / Region', true, false],
            ['postalCode', 'Postal code', true, true],
            ['country', 'Country', true, true]
        ]
    };

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
        if (type === 'hidden') {
            return { source: 'static' };
        }
        if (type === 'consent') {
            return { consentText: 'I agree to the [privacy policy](https://example.com/privacy)' };
        }
        if (type === 'rating') {
            return { max: 5, iconStyle: 'star' };
        }
        if (type === 'opinion') {
            return { min: 0, max: 10 };
        }
        if (RELATION_TYPES.indexOf(type) !== -1) {
            return { sources: [], multiple: false };
        }
        if (type === 'calculation') {
            return { formula: '', decimals: 2, thousandsSeparator: false, missingAsZero: true };
        }
        if (type === 'repeater') {
            return {
                minRows: 1, maxRows: 0, addButtonLabel: '',
                fields: [{ handle: 'item', type: 'text', label: 'Item', required: false }]
            };
        }
        if (COMPOSITE_TYPES.indexOf(type) !== -1) {
            var subFields = {};
            SUBFIELD_DEFS[type].forEach(function(d) {
                subFields[d[0]] = { enabled: d[2], required: false, label: d[1] };
            });
            return { subFields: subFields };
        }
        if (type === 'heading') {
            return { level: 'h3' };
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

    // A field's 1-based page, mirroring FormSteps::pageOf() server-side.
    function pageOf(f) {
        var page = parseInt(f.config && f.config.page, 10);
        return (!isNaN(page) && page >= 1) ? page : 1;
    }

    function render() {
        Array.prototype.slice.call(canvas.querySelectorAll('.sf-field, .sf-builder-row, .sf-page-sep')).forEach(function(el) { el.remove(); });
        var empty = canvas.querySelector('.sf-empty');
        if (empty) { empty.style.display = fields.length ? 'none' : ''; }
        // Step separators (#292): when the form is multi-page, bracket each
        // page's fields so the author sees the grouping instead of tracking
        // "Step / Page" numbers mentally. The runtime compacts page-number
        // gaps (FormSteps::group), so the label shows the EFFECTIVE step and
        // flags non-contiguous numbering.
        var pages = [];
        fields.forEach(function(f) {
            var page = pageOf(f);
            if (pages.indexOf(page) === -1) { pages.push(page); }
        });
        pages.sort(function(a, b) { return a - b; });
        var multiStep = pages.length > 1;
        var lastPage = null;
        // Group into visual rows so columns sit side by side in the builder too.
        groupRows(fields).forEach(function(row) {
            if (multiStep) {
                var rowPage = pageOf(row[0]);
                if (rowPage !== lastPage) {
                    lastPage = rowPage;
                    var sep = document.createElement('div');
                    sep.className = 'sf-page-sep';
                    var ordinal = pages.indexOf(rowPage) + 1;
                    sep.textContent = 'Step ' + ordinal
                        + (ordinal !== rowPage ? ' (numbered ' + rowPage + ' — steps renumber contiguously on the form)' : '');
                    canvas.appendChild(sep);
                }
            }
            if (row.length <= 1) {
                canvas.appendChild(renderBlock(row[0]));
                return;
            }
            var wrap = document.createElement('div');
            wrap.className = 'sf-builder-row';
            wrap.dataset.cols = row.length;
            // Mirror the front-end column weights so the canvas previews widths.
            wrap.style.setProperty('--sf-cols', row.map(function(f) {
                return (widthOf(f)) + 'fr';
            }).join(' '));
            row.forEach(function(f) { wrap.appendChild(renderBlock(f)); });
            canvas.appendChild(wrap);
        });
    }

    function renderBlock(f) {
        var layout = isLayout(f.type);
        var el = document.createElement('div');
        el.className = 'sf-field' + (layout ? ' sf-field--layout' : '') + (f.clientId === selectedId ? ' sel' : '');
        el.setAttribute('draggable', 'true');
        // Keyboard-selectable: focusable + Enter/Space opens the inspector (#105).
        el.setAttribute('tabindex', '0');
        el.setAttribute('role', 'button');
        el.setAttribute('aria-label', blockPreviewText(f) + ' — ' + (TYPE_LABELS[f.type] || f.type));
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(f.clientId); focusInspector(); }
            if ((e.altKey || e.ctrlKey) && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
                e.preventDefault();
                moveField(f.clientId, e.key === 'ArrowUp' ? -1 : 1);
            }
        });
        el.dataset.cid = f.clientId;

        var grip = document.createElement('span');
        grip.className = 'sf-grip'; grip.setAttribute('aria-hidden', 'true'); grip.textContent = '⋮⋮';

        var label = document.createElement('span');
        label.className = 'sf-field-label'; label.textContent = blockPreviewText(f);

        var type = document.createElement('span');
        type.className = 'sf-field-type'; type.textContent = TYPE_LABELS[f.type] || f.type;
        // Hidden fields are invisible on the front end — flag the card so a
        // creator doesn't expect it to render (#124).
        if (HIDDEN_TYPES.indexOf(f.type) !== -1) {
            type.classList.add('sf-field-type-hidden');
            type.title = 'Hidden — captured silently; never shown on the form.';
        }

        var idx = fields.indexOf(f);
        var up = document.createElement('button');
        up.type = 'button'; up.className = 'sf-field-move'; up.dataset.dir = '-1';
        up.title = 'Move up'; up.setAttribute('aria-label', 'Move up'); up.textContent = '\u25B4';
        up.disabled = idx <= 0;
        var down = document.createElement('button');
        down.type = 'button'; down.className = 'sf-field-move'; down.dataset.dir = '1';
        down.title = 'Move down'; down.setAttribute('aria-label', 'Move down'); down.textContent = '\u25BE';
        down.disabled = idx === fields.length - 1;

        var del = document.createElement('button');
        del.type = 'button'; del.className = 'sf-field-del'; del.title = 'Remove'; del.textContent = '×';

        el.appendChild(grip); el.appendChild(label);
        // Layout blocks are value-less, so the canvas never shows a required mark.
        if (!layout) {
            var req = document.createElement('span');
            req.className = 'sf-field-req'; req.textContent = f.required ? '*' : '';
            el.appendChild(req);
        }
        el.appendChild(type); el.appendChild(up); el.appendChild(down); el.appendChild(del);
        return el;
    }

    // The canvas-preview text for a block: its visible content for layout blocks
    // (heading text / divider label / "HTML block"), its label otherwise.
    function blockPreviewText(f) {
        if (f.type === 'heading') { return (f.label && f.label.trim()) || '(heading)'; }
        if (f.type === 'divider') { return (f.label && f.label.trim()) || '— divider —'; }
        if (f.type === 'html') { return 'HTML block'; }
        if (f.type === 'paragraph') {
            var body = (f.helpText || '').trim().replace(/\s+/g, ' ');
            if (!body) { return '(text)'; }
            return body.length > 60 ? body.slice(0, 60) + '…' : body;
        }
        return f.label || '(untitled)';
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

    function addField(type, atIndex, pairWith) {
        // Layout blocks have no user-facing label (heading text / divider label
        // is the content); start them blank and seed the handle from the type so
        // the row is still uniquely addressable for conditionals + persistence.
        var label = isLayout(type) ? '' : (TYPE_LABELS[type] || 'Field');
        // Consent is, by design, normally a required tick — default it on.
        var f = normalize({ id: null, type: type, label: label, required: type === 'consent', config: defaultConfig(type) });
        f.handle = uniqueHandle(slug(label) || type, f.clientId);
        if (pairWith) {
            // Dropped onto a field's edge: place it beside that field as a column.
            fields.push(f);
            pairFields(f, field(pairWith.cid), pairWith.side);
        } else if (atIndex == null || atIndex >= fields.length) { fields.push(f); }
        else { fields.splice(Math.max(0, atIndex), 0, f); }
        commit();
        select(f.clientId);
        // Land keyboard focus in the editor and announce the change.
        announce(label + ' field added.');
        focusInspector();
    }

    // Other fields whose visibility/required rules or logic jumps reference
    // this handle — deleting it silently prunes those rules server-side on
    // save, so the delete confirmation must warn about them (#288).
    function fieldReferences(handle) {
        if (!handle) { return []; }
        return fields.filter(function(f) {
            if (f.handle === handle) { return false; }
            var c = f.config || {};
            var rules = (c.conditional && c.conditional.rules) || [];
            var reqRules = (c.conditional && c.conditional.required && c.conditional.required.rules) || [];
            var jumps = c.jumps || [];
            return rules.concat(reqRules).some(function(r) { return r && r.field === handle; })
                || jumps.some(function(j) { return j && j.target === handle; });
        });
    }

    // Confirmed delete: the small × is destructive (field config, rules, and
    // any other field's rules that reference it, all gone on save) and there
    // is no undo, so it always asks first — via the shared accessible dialog
    // when available, the native confirm otherwise.
    function confirmRemoveField(cid, refocusEl) {
        var f = fields.filter(function(x) { return x.clientId === cid; })[0];
        if (!f) { return; }
        var name = f.label || TYPE_LABELS[f.type] || f.type;
        var refs = fieldReferences(f.handle);
        var message = 'Delete the \u201C' + name + '\u201D field?';
        if (refs.length) {
            var names = refs.map(function(r) { return r.label || r.handle; }).join(', ');
            message += ' ' + refs.length + ' other field' + (refs.length === 1 ? ' has' : 's have')
                + ' rules based on it (' + names + '); those rules will be removed too.';
        }
        // cp.js (same bundle, loaded first) provides the accessible dialog;
        // native confirm() is banned in CP JS, so absent the dialog we keep the
        // pre-#288 immediate-delete behavior rather than block deletion.
        var ask = (window.SimpleFormCp && window.SimpleFormCp.sfConfirm)
            ? window.SimpleFormCp.sfConfirm(message)
            : Promise.resolve(true);
        ask.then(function(ok) {
            if (ok) { removeField(cid); }
            else if (refocusEl && typeof refocusEl.focus === 'function') { refocusEl.focus(); }
        });
    }

    // Vertical reorder without drag (#291): keyboard (Alt/Ctrl+Arrow on the
    // focused card) and touch (the per-card arrows) both land here. HTML5 DnD
    // never fires on iOS/Android and had no keyboard path, so drag-only meant
    // tablet authors couldn't reorder at all.
    function moveField(cid, delta) {
        var idx = -1;
        fields.forEach(function(f, i) { if (f.clientId === cid) { idx = i; } });
        var target = idx + delta;
        if (idx === -1 || target < 0 || target >= fields.length) { return; }
        var moved = fields.splice(idx, 1)[0];
        fields.splice(target, 0, moved);
        commit();
        announce('Moved to position ' + (target + 1) + ' of ' + fields.length + '.');
        // render() rebuilt the cards — put focus back on the moved one so a
        // keyboard user can keep arrowing.
        var el = canvas.querySelector('.sf-field[data-cid="' + cid + '"]');
        if (el && typeof el.focus === 'function') { el.focus(); }
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

        // Presentational/layout blocks: a tailored editor with no Required,
        // validation message, or generic help-text — only their own content.
        if (isLayout(f.type)) {
            renderLayoutInspector(f);
            return;
        }

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

        var isHiddenType = HIDDEN_TYPES.indexOf(f.type) !== -1;

        // Hidden fields are non-visible: they have no Required / Help Text /
        // Error Message — only an internal label (above) for exports/CP (#124).
        if (!isHiddenType) {
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
        } else {
            var hiddenHint = document.createElement('div'); hiddenHint.className = 'instructions';
            var hiddenHintP = document.createElement('p');
            hiddenHintP.textContent = 'Hidden — captured silently. This field is never shown on the form; the label is used only for the CP submission view and exports.';
            hiddenHint.appendChild(hiddenHintP);
            inspector.appendChild(hiddenHint);
        }

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
        rowHintP.textContent = 'Drag a field onto the left or right edge of another to place them side by side '
            + '(up to ' + MAX_COLUMNS + ' columns), or set a matching Row number here.';
        rowHint.appendChild(rowHintP);
        rowRow._input.appendChild(rowHint);
        inspector.appendChild(rowRow);

        appendWidthRow(f);

        renderTypeConfig(f);
        inspector.appendChild(conditionsSection(f));

        // Logic jumps (#245): branch to a later field's step based on this
        // field's answer (input fields only — a layout block has no answer).
        inspector.appendChild(jumpsSection(f));
    }

    // Tailored editor for the value-less layout blocks. The per-site
    // translatable content rides on the field's label (heading text / divider
    // label) and helpText (HTML body / paragraph copy) so it persists with no
    // schema change.
    function renderLayoutInspector(f) {
        var c = f.config || (f.config = {});

        if (f.type === 'heading') {
            var lvlRow = row('Heading Level');
            lvlRow._input.appendChild(selectEl(
                [{ value: 'h2', label: 'Heading 2' }, { value: 'h3', label: 'Heading 3' }, { value: 'h4', label: 'Heading 4' }],
                c.level || 'h3',
                function(v) { c.level = v; serialize(); }
            ));
            inspector.appendChild(lvlRow);

            var textRow = row('Heading Text');
            textRow._input.appendChild(textInput(f.label, function(v) { f.label = v; commit(); }));
            inspector.appendChild(textRow);
        } else if (f.type === 'divider') {
            var labRow = row('Label (optional)');
            labRow._input.appendChild(textInput(f.label, function(v) { f.label = v; commit(); }));
            var labHint = document.createElement('div'); labHint.className = 'instructions';
            var labHintP = document.createElement('p');
            labHintP.textContent = 'Optional text shown over the divider line. Leave blank for a plain rule.';
            labHint.appendChild(labHintP); labRow._input.appendChild(labHint);
            inspector.appendChild(labRow);
        } else if (f.type === 'html') {
            var htmlRow = row('HTML / Twig');
            var ta = document.createElement('textarea'); ta.className = 'text fullwidth code'; ta.rows = 8;
            ta.value = f.helpText || '';
            ta.addEventListener('input', function() { f.helpText = ta.value; commit(); });
            htmlRow._input.appendChild(ta);
            var htmlHint = document.createElement('div'); htmlHint.className = 'instructions';
            var htmlHintP = document.createElement('p');
            htmlHintP.textContent = 'Rendered safely on the form: Twig runs in a sandbox and the output is purified — '
                + 'scripts, inline handlers and unsafe URLs are stripped.';
            htmlHint.appendChild(htmlHintP); htmlRow._input.appendChild(htmlHint);
            inspector.appendChild(htmlRow);
        } else if (f.type === 'paragraph') {
            var textRow2 = row('Text');
            var pta = document.createElement('textarea'); pta.className = 'text fullwidth'; pta.rows = 5;
            pta.value = f.helpText || '';
            pta.addEventListener('input', function() { f.helpText = pta.value; commit(); });
            textRow2._input.appendChild(pta);
            var pHint = document.createElement('div'); pHint.className = 'instructions';
            var pHintP = document.createElement('p');
            pHintP.textContent = 'Static paragraph copy shown between fields. Plain text only — '
                + 'line breaks are preserved and any markup is escaped (use the HTML Block for real formatting).';
            pHint.appendChild(pHintP); textRow2._input.appendChild(pHint);
            inspector.appendChild(textRow2);
        }

        inspector.appendChild(numberRow('Step / Page', (f.config && f.config.page) || '', function(v) {
            f.config = f.config || {};
            var n = parseInt(v, 10);
            if (v === '' || v == null || isNaN(n) || n < 1) { delete f.config.page; } else { f.config.page = n; }
            serialize();
        }));

        // Layout blocks may still be shown/hidden by conditional logic.
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
        } else if (f.type === 'phone') {
            var phRow = row('Placeholder');
            phRow._input.appendChild(textInput(c.placeholder || '', function(v) {
                if (v.trim() === '') { delete c.placeholder; } else { c.placeholder = v; } serialize();
            }));
            inspector.appendChild(phRow);

            var selRow = row('Show Country Selector');
            var selCb = document.createElement('input'); selCb.type = 'checkbox'; selCb.checked = !!c.showCountrySelector;
            selCb.addEventListener('change', function() { c.showCountrySelector = selCb.checked; serialize(); });
            selRow._input.appendChild(selCb);
            inspector.appendChild(selRow);

            var countryOpts = (SF_PHONE_COUNTRIES || []).map(function(co) {
                return { value: co.iso, label: co.label + ' (' + co.dial + ')' };
            });

            var dcRow = row('Default Country');
            dcRow._input.appendChild(selectEl(countryOpts, c.defaultCountry || 'CH', function(v) {
                c.defaultCountry = v; serialize();
            }));
            inspector.appendChild(dcRow);

            var allowedSelected = Array.isArray(c.allowedCountries) ? c.allowedCountries : [];
            var acRow = row('Allowed Countries');
            var acHint = document.createElement('div'); acHint.className = 'instructions';
            var acHintP = document.createElement('p');
            acHintP.textContent = 'Leave all unchecked to allow every country.';
            acHint.appendChild(acHintP); acRow._input.appendChild(acHint);
            (SF_PHONE_COUNTRIES || []).forEach(function(co) {
                var w = document.createElement('div'); w.className = 'checkbox-wrapper';
                var cbx = document.createElement('input'); cbx.type = 'checkbox'; cbx.className = 'checkbox';
                cbx.id = 'sf-ac-' + f.clientId + '-' + co.iso;
                cbx.checked = allowedSelected.indexOf(co.iso) !== -1;
                cbx.addEventListener('change', function() {
                    var list = Array.isArray(c.allowedCountries) ? c.allowedCountries : [];
                    var i = list.indexOf(co.iso);
                    if (cbx.checked && i === -1) { list.push(co.iso); }
                    else if (!cbx.checked && i !== -1) { list.splice(i, 1); }
                    if (list.length === 0) { delete c.allowedCountries; } else { c.allowedCountries = list; }
                    serialize();
                });
                var lbl = document.createElement('label'); lbl.setAttribute('for', cbx.id);
                lbl.textContent = co.label + ' (' + co.dial + ')';
                w.appendChild(cbx); w.appendChild(lbl); acRow._input.appendChild(w);
            });
            inspector.appendChild(acRow);

            inspector.appendChild(numberRow('Minimum Digits', c.minDigits, function(v) { setNum(c, 'minDigits', v); }));
            inspector.appendChild(numberRow('Maximum Digits', c.maxDigits, function(v) { setNum(c, 'maxDigits', v); }));

            var patRow = row('Custom Pattern');
            patRow._input.appendChild(textInput(c.pattern || '', function(v) {
                if (v.trim() === '') { delete c.pattern; } else { c.pattern = v; } serialize();
            }));
            var patHint = document.createElement('div'); patHint.className = 'instructions';
            var patHintP = document.createElement('p');
            patHintP.textContent = 'Optional regex applied to the number’s digits. Leave blank for the default check.';
            patHint.appendChild(patHintP); patRow._input.appendChild(patHint);
            inspector.appendChild(patRow);
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
        } else if (f.type === 'signature') {
            // Stored as an asset, same as the File field, so it gets the same
            // volume choice; pen/background are presentational (blank = default).
            var sigVolOpts = [{ value: '', label: '(first available)' }].concat(
                (SF_VOLUMES || []).map(function(v) { return { value: v.handle, label: v.name }; }));
            var sigVolRow = row('Asset Volume');
            sigVolRow._input.appendChild(selectEl(sigVolOpts, c.volume || '', function(v) {
                if (v === '') { delete c.volume; } else { c.volume = v; } serialize();
            }));
            inspector.appendChild(sigVolRow);

            var penRow = row('Pen Color');
            var penInput = textInput(c.penColor || '', function(v) {
                if (v.trim() === '') { delete c.penColor; } else { c.penColor = v.trim(); } serialize();
            });
            penInput.placeholder = '#1a1a1a';
            penRow._input.appendChild(penInput);
            inspector.appendChild(penRow);

            var bgRow = row('Pad Background');
            var bgInput = textInput(c.background || '', function(v) {
                if (v.trim() === '') { delete c.background; } else { c.background = v.trim(); } serialize();
            });
            bgInput.placeholder = '#ffffff';
            bgRow._input.appendChild(bgInput);
            inspector.appendChild(bgRow);
        } else if (f.type === 'payment') {
            var atRow = row('Amount Type');
            atRow._input.appendChild(selectEl(
                [{ value: 'fixed', label: 'Fixed amount' }, { value: 'field', label: 'From a field' }],
                c.amountType || 'fixed',
                function(v) { c.amountType = v; serialize(); }
            ));
            inspector.appendChild(atRow);

            inspector.appendChild(numberRow('Fixed Amount', c.amount, function(v) { setNum(c, 'amount', v); }));

            inspector.appendChild(numberRow('Min Amount', c.minAmount, function(v) { setNum(c, 'minAmount', v); }));
            inspector.appendChild(numberRow('Max Amount', c.maxAmount, function(v) { setNum(c, 'maxAmount', v); }));

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

            // Coupons (#246): show a discount-code box on the form when enabled.
            var coupRow = row('Allow Coupons');
            var coupCb = document.createElement('input'); coupCb.type = 'checkbox'; coupCb.checked = !!c.enableCoupons;
            coupCb.addEventListener('change', function() { c.enableCoupons = coupCb.checked; serialize(); });
            coupRow._input.appendChild(coupCb);
            inspector.appendChild(coupRow);
        } else if (f.type === 'hidden') {
            renderHiddenConfig(f, c);
        } else if (f.type === 'consent') {
            // Consent text (rich label). One inline [label](url) link is rendered
            // safely server-side; everything else is escaped.
            var ctRow = row('Consent Text');
            var ctTa = document.createElement('textarea');
            ctTa.className = 'text fullwidth'; ctTa.rows = 3;
            ctTa.value = c.consentText || '';
            ctTa.addEventListener('input', function() { c.consentText = ctTa.value; serialize(); });
            ctRow._input.appendChild(ctTa);

            var addLink = document.createElement('button');
            addLink.type = 'button'; addLink.className = 'btn small';
            addLink.style.marginTop = '5px';
            addLink.textContent = 'Add link';
            addLink.addEventListener('click', function() {
                var token = '[privacy policy](https://example.com/privacy)';
                ctTa.value = (ctTa.value ? ctTa.value + ' ' : '') + token;
                c.consentText = ctTa.value; serialize(); ctTa.focus();
            });
            ctRow._input.appendChild(addLink);

            var ctHint = document.createElement('div'); ctHint.className = 'instructions';
            var ctHintP = document.createElement('p');
            ctHintP.textContent = 'Use [label](https://…) for a single inline link. Per-site translatable.';
            ctHint.appendChild(ctHintP);
            ctRow._input.appendChild(ctHint);
            inspector.appendChild(ctRow);

            // Required message override (the "must agree" error).
            var rmRow = row('Required Message');
            var rmInput = textInput(c.requiredMessage || '', function(v) {
                if (v.trim() === '') { delete c.requiredMessage; } else { c.requiredMessage = v; } serialize();
            });
            rmInput.placeholder = 'You must agree before submitting.';
            rmRow._input.appendChild(rmInput);
            inspector.appendChild(rmRow);
        } else if (f.type === 'rating') {
            inspector.appendChild(numberRow('Maximum (1–10)', c.max != null ? c.max : 5, function(v) {
                var n = parseInt(v, 10);
                if (isNaN(n)) { delete c.max; } else { c.max = Math.max(1, Math.min(10, n)); }
                serialize();
            }));
            var styleRow = row('Icon Style');
            styleRow._input.appendChild(selectEl(
                [{ value: 'star', label: 'Stars' }, { value: 'heart', label: 'Hearts' }, { value: 'number', label: 'Numbers' }],
                c.iconStyle || 'star',
                function(v) { c.iconStyle = v; serialize(); }
            ));
            inspector.appendChild(styleRow);
        } else if (f.type === 'opinion') {
            inspector.appendChild(numberRow('Minimum', c.min != null ? c.min : 0, function(v) {
                var n = parseInt(v, 10);
                if (v === '' || isNaN(n)) { delete c.min; } else { c.min = n; }
                serialize();
            }));
            inspector.appendChild(numberRow('Maximum', c.max != null ? c.max : 10, function(v) {
                var n = parseInt(v, 10);
                if (v === '' || isNaN(n)) { delete c.max; } else { c.max = n; }
                serialize();
            }));
            var leftRow = row('Left Label');
            leftRow._input.appendChild(textInput(c.leftLabel || '', function(v) {
                if (v.trim() === '') { delete c.leftLabel; } else { c.leftLabel = v; } serialize();
            }));
            inspector.appendChild(leftRow);
            var rightRow = row('Right Label');
            rightRow._input.appendChild(textInput(c.rightLabel || '', function(v) {
                if (v.trim() === '') { delete c.rightLabel; } else { c.rightLabel = v; } serialize();
            }));
            inspector.appendChild(rightRow);
        } else if (RELATION_TYPES.indexOf(f.type) !== -1) {
            renderRelationConfig(f);
        } else if (f.type === 'calculation') {
            var fRow = row('Formula');
            var ta = document.createElement('textarea');
            ta.className = 'text fullwidth'; ta.rows = 2;
            ta.placeholder = '{quantity} * {unitPrice}';
            ta.value = c.formula || '';
            ta.addEventListener('input', function() { c.formula = ta.value; serialize(); });
            fRow._input.appendChild(ta);
            var fHint = document.createElement('div'); fHint.className = 'instructions';
            var fp = document.createElement('p');
            fp.textContent = 'Reference fields by handle, e.g. {quantity} * {unitPrice}. Allowed: + - * / ( ) and min, max, round, ceil, floor, abs.';
            fHint.appendChild(fp); fRow.appendChild(fHint);
            inspector.appendChild(fRow);

            // Quick-insert buttons for each other field's handle.
            var handles = fields.filter(function(other) { return other.clientId !== f.clientId && other.handle; });
            if (handles.length) {
                var pickRow = row('Insert Field');
                handles.forEach(function(other) {
                    var b = document.createElement('button');
                    b.type = 'button'; b.className = 'btn'; b.textContent = '{' + other.handle + '}';
                    b.style.marginRight = '4px'; b.style.marginBottom = '4px';
                    b.addEventListener('click', function() {
                        ta.value += '{' + other.handle + '}';
                        c.formula = ta.value; serialize(); ta.focus();
                    });
                    pickRow._input.appendChild(b);
                });
                inspector.appendChild(pickRow);
            }

            inspector.appendChild(numberRow('Decimal Places', c.decimals != null ? c.decimals : 2, function(v) {
                if (v === '' || v == null) { c.decimals = 2; } else { c.decimals = Math.max(0, Math.min(6, parseInt(v, 10) || 0)); }
                serialize();
            }));

            var sepRow = row('Thousands Separator');
            var sepCb = document.createElement('input'); sepCb.type = 'checkbox'; sepCb.checked = !!c.thousandsSeparator;
            sepCb.addEventListener('change', function() { c.thousandsSeparator = sepCb.checked; serialize(); });
            sepRow._input.appendChild(sepCb);
            inspector.appendChild(sepRow);

            var preRow = row('Prefix');
            preRow._input.appendChild(textInput(c.prefix || '', function(v) {
                if (v === '') { delete c.prefix; } else { c.prefix = v; } serialize();
            }));
            inspector.appendChild(preRow);

            var sufRow = row('Suffix');
            sufRow._input.appendChild(textInput(c.suffix || '', function(v) {
                if (v === '') { delete c.suffix; } else { c.suffix = v; } serialize();
            }));
            inspector.appendChild(sufRow);
        } else if (f.type === 'repeater') {
            inspector.appendChild(numberRow('Minimum Rows', c.minRows, function(v) {
                var n = parseInt(v, 10); c.minRows = (isNaN(n) || n < 0) ? 0 : n; serialize();
            }));
            inspector.appendChild(numberRow('Maximum Rows (0 = unlimited)', c.maxRows, function(v) {
                var n = parseInt(v, 10); c.maxRows = (isNaN(n) || n < 0) ? 0 : n; serialize();
            }));
            var addLabelRow = row('Add Button Label');
            var addLabelInput = textInput(c.addButtonLabel || '', function(v) { c.addButtonLabel = v; serialize(); });
            addLabelInput.placeholder = 'Add another';
            addLabelRow._input.appendChild(addLabelInput);
            inspector.appendChild(addLabelRow);

            inspector.appendChild(innerFieldsEditor(f));
        } else if (COMPOSITE_TYPES.indexOf(f.type) !== -1) {
            inspector.appendChild(subFieldsEditor(f));

            // Address autocomplete (#250): opt in to a type-ahead lookup that fills
            // the sub-fields. The provider is configured in plugin settings.
            if (f.type === 'address') {
                var acRow = row('Enable Autocomplete');
                var acCb = document.createElement('input'); acCb.type = 'checkbox'; acCb.checked = !!c.enableAutocomplete;
                acCb.addEventListener('change', function() { c.enableAutocomplete = acCb.checked; serialize(); });
                acRow._input.appendChild(acCb);
                inspector.appendChild(acRow);
            }
        }
    }

    // Source picker + single/multi + limit for the element-relation field types.
    function renderRelationConfig(f) {
        var c = f.config || (f.config = {});
        var available = SF_SOURCES[f.type] || [];

        // Allowed sources: a checkbox list (empty selection = any source). Stored
        // as a list of handles; '*' / empty both mean "any".
        if (!Array.isArray(c.sources)) { c.sources = []; }

        var srcWrap = document.createElement('div'); srcWrap.className = 'field';
        var srcHead = document.createElement('div'); srcHead.className = 'heading';
        var srcLab = document.createElement('label'); srcLab.textContent = 'Allowed Sources';
        srcHead.appendChild(srcLab); srcWrap.appendChild(srcHead);
        var srcHint = document.createElement('div'); srcHint.className = 'instructions';
        var srcHintP = document.createElement('p');
        srcHintP.textContent = 'Leave all unchecked to allow any source of this element type.';
        srcHint.appendChild(srcHintP); srcWrap.appendChild(srcHint);

        if (available.length === 0) {
            var none = document.createElement('p'); none.className = 'light';
            none.textContent = 'No sources available for this element type.';
            srcWrap.appendChild(none);
        }

        available.forEach(function(src) {
            var line = document.createElement('div'); line.className = 'sf-source-row';
            var cb = document.createElement('input'); cb.type = 'checkbox';
            cb.id = 'sf-src-' + f.type + '-' + src.handle;
            cb.checked = c.sources.indexOf(src.handle) !== -1;
            cb.addEventListener('change', function() {
                var i = c.sources.indexOf(src.handle);
                if (cb.checked && i === -1) { c.sources.push(src.handle); }
                else if (!cb.checked && i !== -1) { c.sources.splice(i, 1); }
                serialize();
            });
            var lab = document.createElement('label'); lab.setAttribute('for', cb.id);
            lab.textContent = ' ' + src.name;
            line.appendChild(cb); line.appendChild(lab);
            srcWrap.appendChild(line);
        });
        inspector.appendChild(srcWrap);

        // Single vs. multiple.
        var multRow = row('Allow Multiple');
        var multCb = document.createElement('input'); multCb.type = 'checkbox'; multCb.checked = !!c.multiple;
        multCb.addEventListener('change', function() {
            c.multiple = multCb.checked;
            if (!c.multiple) { delete c.limit; }
            serialize();
            // Re-render so the Limit row shows/hides with the toggle.
            renderInspector();
        });
        multRow._input.appendChild(multCb);
        inspector.appendChild(multRow);

        // Limit only applies to multiple-select.
        if (c.multiple) {
            inspector.appendChild(numberRow('Limit', c.limit, function(v) { setNum(c, 'limit', v); }));
        }
    }

    // Source dropdown + the source-specific input for a Hidden field (#124). The
    // source-specific row is re-rendered when the source changes so only the
    // relevant input shows.
    function renderHiddenConfig(f, c) {
        if (!c.source) { c.source = 'static'; }

        var srcRow = row('Source');
        srcRow._input.appendChild(selectEl(
            [
                { value: 'static', label: 'Static value' },
                { value: 'query', label: 'URL query parameter' },
                { value: 'user', label: 'Logged-in user' },
                { value: 'cookie', label: 'Cookie' }
            ],
            c.source,
            function(v) { c.source = v; serialize(); renderInspector(); }
        ));
        inspector.appendChild(srcRow);

        if (c.source === 'query') {
            var qpRow = row('Query Parameter');
            qpRow._input.appendChild(textInput(c.queryParam || '', function(v) {
                c.queryParam = v.trim(); serialize();
            }));
            inspector.appendChild(qpRow);
        } else if (c.source === 'user') {
            var uaRow = row('User Attribute');
            uaRow._input.appendChild(selectEl(
                [
                    { value: 'email', label: 'Email' },
                    { value: 'id', label: 'User ID' },
                    { value: 'username', label: 'Username' }
                ],
                c.userAttribute || 'email',
                function(v) { c.userAttribute = v; serialize(); }
            ));
            inspector.appendChild(uaRow);
        } else if (c.source === 'cookie') {
            var ckRow = row('Cookie Name');
            ckRow._input.appendChild(textInput(c.cookieName || '', function(v) {
                c.cookieName = v.trim(); serialize();
            }));
            inspector.appendChild(ckRow);
        }

        // Default / fallback value (used when the source yields nothing).
        var defRow = row(c.source === 'static' ? 'Value' : 'Default Value');
        defRow._input.appendChild(textInput(c.default || '', function(v) {
            if (v === '') { delete c.default; } else { c.default = v; } serialize();
        }));
        inspector.appendChild(defRow);

        inspector.appendChild(numberRow('Maximum Length', c.maxLength, function(v) { setNum(c, 'maxLength', v); }));
    }

    // ---- repeater inner-field editor -------------------------------------

    // A mini field editor for a repeater's inner sub-fields: add/remove, with a
    // type picker limited to REPEATER_INNER_TYPES, a handle, a label, a required
    // toggle, and the type's own settings (select options, number min/max).
    function innerFieldsEditor(f) {
        var c = f.config || (f.config = {});
        if (!Array.isArray(c.fields)) { c.fields = []; }

        var wrap = document.createElement('div'); wrap.className = 'field sf-repeater-fields';
        var heading = document.createElement('div'); heading.className = 'heading';
        var lab = document.createElement('label'); lab.textContent = 'Inner Fields';
        heading.appendChild(lab); wrap.appendChild(heading);

        var hint = document.createElement('div'); hint.className = 'instructions';
        var hp = document.createElement('p');
        hp.textContent = 'The sub-fields a visitor fills in per row. Allowed types: text, email, number, select.';
        hint.appendChild(hp); wrap.appendChild(hint);

        var list = document.createElement('div'); list.className = 'sf-repeater-list';
        wrap.appendChild(list);

        function redraw() {
            list.innerHTML = '';
            c.fields.forEach(function(inner, idx) { list.appendChild(innerRow(inner, idx)); });
        }

        function innerRow(inner, idx) {
            var r = document.createElement('div'); r.className = 'sf-repeater-row';

            var li = document.createElement('input'); li.type = 'text'; li.className = 'text'; li.placeholder = 'Label';
            li.value = inner.label || '';
            li.addEventListener('input', function() { inner.label = li.value; serialize(); });

            var hi = document.createElement('input'); hi.type = 'text'; hi.className = 'text'; hi.placeholder = 'Handle';
            hi.value = inner.handle || '';
            hi.addEventListener('input', function() { inner.handle = slug(hi.value); serialize(); });

            var typeSel = selectEl(REPEATER_INNER_TYPES.map(function(t) {
                return { value: t, label: TYPE_LABELS[t] || t };
            }), inner.type || 'text', function(v) {
                inner.type = v;
                if (v === 'select' && !Array.isArray(inner.options)) {
                    inner.options = [{ label: 'Option 1', value: 'option1' }];
                }
                serialize(); redraw();
            });

            var reqWrap = document.createElement('label'); reqWrap.className = 'sf-repeater-req';
            var cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = !!inner.required;
            cb.addEventListener('change', function() { inner.required = cb.checked; serialize(); });
            reqWrap.appendChild(cb);
            reqWrap.appendChild(document.createTextNode(' required'));

            var del = document.createElement('button'); del.type = 'button'; del.className = 'btn sf-repeater-del'; del.textContent = '×';
            del.addEventListener('click', function() { c.fields.splice(idx, 1); redraw(); serialize(); });

            r.appendChild(li); r.appendChild(hi); r.appendChild(typeSel); r.appendChild(reqWrap); r.appendChild(del);

            // Per-type extra settings.
            if (inner.type === 'select') {
                r.appendChild(innerOptionsEditor(inner));
            } else if (inner.type === 'number') {
                var nWrap = document.createElement('div'); nWrap.className = 'sf-repeater-subsettings';
                nWrap.appendChild(miniNumber('Min', inner.min, function(v) { setNum(inner, 'min', v); }));
                nWrap.appendChild(miniNumber('Max', inner.max, function(v) { setNum(inner, 'max', v); }));
                r.appendChild(nWrap);
            }

            return r;
        }

        function miniNumber(labelText, value, oninput) {
            var l = document.createElement('label'); l.className = 'sf-repeater-mini';
            l.appendChild(document.createTextNode(labelText + ' '));
            l.appendChild(textInput(value, oninput, 'number'));
            return l;
        }

        redraw();

        var add = document.createElement('button'); add.type = 'button'; add.className = 'btn sf-repeater-add'; add.textContent = 'Add Inner Field';
        add.addEventListener('click', function() {
            c.fields.push({ handle: uniqueInnerHandle(c.fields, 'field'), type: 'text', label: '', required: false });
            redraw(); serialize();
        });
        wrap.appendChild(add);
        return wrap;
    }

    function uniqueInnerHandle(innerFields, base) {
        var taken = {};
        innerFields.forEach(function(i) { if (i.handle) { taken[i.handle.toLowerCase()] = true; } });
        if (!taken[base]) { return base; }
        var n = 2;
        while (taken[(base + n).toLowerCase()]) { n++; }
        return base + n;
    }

    function innerOptionsEditor(inner) {
        if (!Array.isArray(inner.options)) { inner.options = []; }
        var wrap = document.createElement('div'); wrap.className = 'sf-repeater-options';

        function redraw() {
            wrap.innerHTML = '';
            inner.options.forEach(function(opt, idx) {
                var r = document.createElement('div'); r.className = 'sf-option-row';
                var li = document.createElement('input'); li.type = 'text'; li.className = 'text'; li.placeholder = 'Label'; li.value = opt.label || '';
                var vi = document.createElement('input'); vi.type = 'text'; vi.className = 'text'; vi.placeholder = 'Value'; vi.value = opt.value || '';
                li.addEventListener('input', function() { opt.label = li.value; serialize(); });
                vi.addEventListener('input', function() { opt.value = vi.value; serialize(); });
                var del = document.createElement('button'); del.type = 'button'; del.className = 'btn sf-option-del'; del.textContent = '×';
                del.addEventListener('click', function() { inner.options.splice(idx, 1); redraw(); serialize(); });
                r.appendChild(li); r.appendChild(vi); r.appendChild(del);
                wrap.appendChild(r);
            });
            var add = document.createElement('button'); add.type = 'button'; add.className = 'btn sf-option-add'; add.textContent = 'Add Option';
            add.addEventListener('click', function() { inner.options.push({ label: '', value: '' }); redraw(); serialize(); });
            wrap.appendChild(add);
        }
        redraw();
        return wrap;
    }

    // Per-sub-field editor for the composite types (Name/Address): one row per
    // declared sub-field with an enable toggle, an editable (translatable) label,
    // and a required toggle. The config writes back as config.subFields[<key>].
    function subFieldsEditor(f) {
        var c = f.config || (f.config = {});
        if (!c.subFields || typeof c.subFields !== 'object') { c.subFields = {}; }
        var defs = SUBFIELD_DEFS[f.type] || [];

        var wrap = document.createElement('div'); wrap.className = 'field sf-subfields';
        var heading = document.createElement('div'); heading.className = 'heading';
        var lab = document.createElement('label'); lab.textContent = SF_SOURCE_SITE ? 'Sub-fields' : 'Sub-field Labels (this site)';
        heading.appendChild(lab); wrap.appendChild(heading);

        var list = document.createElement('div'); list.className = 'sf-subfields-list';
        wrap.appendChild(list);

        defs.forEach(function(d) {
            var key = d[0];
            var sub = c.subFields[key] || (c.subFields[key] = { enabled: d[2], required: false, label: d[1] });

            var r = document.createElement('div'); r.className = 'sf-subfield-row';

            // Enable toggle (structural — source site only).
            if (SF_SOURCE_SITE) {
                var en = document.createElement('input'); en.type = 'checkbox'; en.className = 'checkbox';
                en.checked = sub.enabled !== false;
                en.addEventListener('change', function() { sub.enabled = en.checked; serialize(); });
                r.appendChild(en);
            }

            // Translatable label.
            var li = document.createElement('input'); li.type = 'text'; li.className = 'text';
            li.placeholder = d[1]; li.value = sub.label != null ? sub.label : d[1];
            li.addEventListener('input', function() { sub.label = li.value; serialize(); });
            r.appendChild(li);

            // Required toggle (structural — source site only).
            if (SF_SOURCE_SITE) {
                var reqWrap = document.createElement('label'); reqWrap.className = 'sf-subfield-required';
                var rq = document.createElement('input'); rq.type = 'checkbox'; rq.className = 'checkbox';
                rq.checked = !!sub.required;
                rq.addEventListener('change', function() { sub.required = rq.checked; serialize(); });
                var rqt = document.createElement('span'); rqt.textContent = 'Required';
                reqWrap.appendChild(rq); reqWrap.appendChild(rqt);
                r.appendChild(reqWrap);
            }

            list.appendChild(r);
        });

        return wrap;
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

    // The group of fields sharing this field's visual row (just [f] when lone).
    function rowGroupOf(f) {
        var found = null;
        groupRows(fields).forEach(function(g) { if (g.indexOf(f) !== -1) { found = g; } });
        return found || [f];
    }

    // A "Column Width" control, shown only when the field shares a row. Sets
    // config.width (1..MAX_COLUMNS) as a relative weight; 1 (Equal) is the
    // default and is stored as the absence of the key.
    function appendWidthRow(f) {
        var group = rowGroupOf(f);
        if (group.length <= 1) { return; }
        var WIDTH_LABELS = { 1: 'Equal', 2: 'Wide', 3: 'Wider', 4: 'Widest' };
        var opts = [];
        for (var i = 1; i <= MAX_COLUMNS; i++) {
            opts.push({ value: String(i), label: i === 1 ? 'Equal' : ((WIDTH_LABELS[i] || 'Wide') + ' (' + i + '×)') });
        }
        var widthRow = row('Column Width');
        widthRow._input.appendChild(selectEl(opts, String(widthOf(f)), function(v) {
            f.config = f.config || {};
            var n = parseInt(v, 10);
            if (isNaN(n) || n <= 1) { delete f.config.width; } else { f.config.width = Math.min(MAX_COLUMNS, n); }
            commit();
            renderInspector();
        }));
        var ratio = group.map(function(x) { return widthOf(x); }).join(' : ');
        var wHint = document.createElement('div'); wHint.className = 'instructions';
        var wp = document.createElement('p');
        wp.textContent = 'Relative width within the row. This row: ' + ratio + '.';
        wHint.appendChild(wp);
        widthRow._input.appendChild(wHint);
        inspector.appendChild(widthRow);
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
            r.appendChild(li); r.appendChild(vi);

            // Quiz answer key (#241): mark this option correct and weight it.
            // Only shown while the form is in quiz mode.
            if (quizModeOn()) {
                var key = document.createElement('span'); key.className = 'sf-answer-key';
                var cbId = 'sf-correct-' + (f.clientId || 'f') + '-' + idx;
                var cb = document.createElement('input'); cb.type = 'checkbox'; cb.id = cbId; cb.checked = !!opt.correct;
                var cbl = document.createElement('label'); cbl.setAttribute('for', cbId); cbl.textContent = 'Correct';
                cb.addEventListener('change', function() {
                    if (cb.checked) { opt.correct = true; } else { delete opt.correct; }
                    serialize();
                });
                var pts = document.createElement('input'); pts.type = 'number'; pts.min = '0'; pts.className = 'text sf-answer-points';
                pts.placeholder = 'Pts'; pts.title = 'Points'; pts.value = (opt.points != null ? opt.points : '');
                pts.addEventListener('input', function() {
                    var n = parseInt(pts.value, 10);
                    if (pts.value === '' || isNaN(n) || n < 0) { delete opt.points; } else { opt.points = n; }
                    serialize();
                });
                key.appendChild(cb); key.appendChild(cbl); key.appendChild(pts);
                r.appendChild(key);
            }

            var del = document.createElement('button'); del.type = 'button'; del.className = 'btn sf-option-del'; del.textContent = '×';
            del.addEventListener('click', function() { c.options.splice(idx, 1); redraw(); serialize(); });
            r.appendChild(del);
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
            if (c) {
                [c.rules, (c.required && c.required.rules)].forEach(function(rules) {
                    if (!Array.isArray(rules)) { return; }
                    rules.forEach(function(r) { if (r.field === oldHandle) { r.field = newHandle; } });
                });
            }
            // Logic-jump targets reference handles too (#245).
            if (f.config && Array.isArray(f.config.jumps)) {
                f.config.jumps.forEach(function(j) { if (j.target === oldHandle) { j.target = newHandle; } });
            }
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
                var inputType = (target && target.type === 'number') ? 'number' : (target && target.type === 'date' ? 'date' : (target && target.type === 'time' ? 'time' : (target && target.type === 'datetime' ? 'datetime-local' : 'text')));
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
    // `onSerialize` persists after a change; it defaults to the field builder's
    // serialize() so field conditionals are unaffected, but the conditional-
    // messages editor (#266) passes its own serializer to reuse this exact
    // component in that context instead of building a second rule editor.
    function ruleList(self, block, rerender, onSerialize) {
        onSerialize = onSerialize || serialize;
        var wrap = document.createElement('div'); wrap.className = 'sf-cond-rules';

        var matchRow = document.createElement('div'); matchRow.className = 'sf-cond-match';
        var pre = document.createElement('span'); pre.textContent = 'Match';
        matchRow.appendChild(pre);
        matchRow.appendChild(selectEl([{ value: 'all', label: 'all' }, { value: 'any', label: 'any' }],
            block.match || 'all', function(v) { block.match = v; onSerialize(); }));
        var post = document.createElement('span'); post.textContent = 'of:';
        matchRow.appendChild(post);
        wrap.appendChild(matchRow);

        if (!Array.isArray(block.rules)) { block.rules = []; }
        block.rules.forEach(function(rule, idx) {
            wrap.appendChild(ruleRow(self, rule,
                function() { block.rules.splice(idx, 1); onSerialize(); rerender(); },
                // Changing the target field also changes the value widget, so
                // redraw on field/operator change; a value-only change just saves.
                function(needsRedraw) { onSerialize(); if (needsRedraw) { rerender(); } }
            ));
        });

        var add = document.createElement('button');
        add.type = 'button'; add.className = 'btn sf-cond-add'; add.textContent = 'Add rule';
        add.addEventListener('click', function() {
            block.rules.push({ field: '', operator: 'eq', value: '' });
            onSerialize(); rerender();
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

    // ---- logic jumps (#245) ----------------------------------------------
    // Branch to a later field's step based on this field's answer. Jumps only go
    // forward (target must be a field after this one), so routes can't loop.

    function laterFields(self) {
        var idx = fields.indexOf(self);
        return fields.filter(function(t, i) { return i > idx && t.handle && !isLayout(t.type); });
    }

    // The value widget for a jump rule, based on THIS field's type (a choice
    // field offers its option values; others a text/number/date input). Null for
    // operators that take no value (is empty / is not empty).
    function jumpValueCell(self, jump) {
        var opDef = OPERATORS.find(function(o) { return o.op === (jump.operator || 'eq'); });
        if (opDef && opDef.noValue) { return null; }
        if (OPTION_TYPES.indexOf(self.type) !== -1) {
            var opts = [{ value: '', label: '— value —' }].concat(((self.config && self.config.options) || []).map(function(o) {
                return { value: o.value, label: o.label || o.value };
            }));
            return selectEl(opts, jump.value != null ? jump.value : '', function(v) { jump.value = v; serialize(); });
        }
        var inputType = self.type === 'number' ? 'number' : (self.type === 'date' ? 'date' : (self.type === 'time' ? 'time' : (self.type === 'datetime' ? 'datetime-local' : 'text')));
        var cell = textInput(jump.value != null ? jump.value : '', function(v) { jump.value = v; serialize(); }, inputType);
        cell.classList.add('sf-cond-value');
        return cell;
    }

    function jumpRow(self, jump, laters, onRemove, rerender) {
        var rowEl = document.createElement('div'); rowEl.className = 'sf-cond-rule';

        rowEl.appendChild(selectEl(OPERATORS.map(function(o) { return { value: o.op, label: o.label }; }),
            jump.operator || 'eq', function(v) { jump.operator = v; serialize(); rerender(); }));

        var val = jumpValueCell(self, jump);
        if (val) { rowEl.appendChild(val); }

        var arrow = document.createElement('span'); arrow.className = 'sf-jump-arrow'; arrow.textContent = '→';
        rowEl.appendChild(arrow);

        var targetOpts = [{ value: '', label: '— jump to —' }].concat(laters.map(function(t) {
            return { value: t.handle, label: t.label || t.handle };
        }));
        rowEl.appendChild(selectEl(targetOpts, jump.target || '', function(v) { jump.target = v; serialize(); }));

        var del = document.createElement('button');
        del.type = 'button'; del.className = 'btn sf-cond-del'; del.textContent = '×'; del.title = 'Remove jump';
        del.addEventListener('click', onRemove);
        rowEl.appendChild(del);
        return rowEl;
    }

    function jumpsSection(f) {
        f.config = f.config || {};
        var wrap = document.createElement('div'); wrap.className = 'sf-cond sf-jumps';

        var hr = document.createElement('hr'); wrap.appendChild(hr);
        var title = document.createElement('h3'); title.className = 'sf-panel-title'; title.textContent = 'Logic jumps';
        wrap.appendChild(title);

        var laters = laterFields(f);
        if (!laters.length) {
            var none = document.createElement('p'); none.className = 'light';
            none.textContent = 'Add a field after this one to jump to it.';
            wrap.appendChild(none);
            return wrap;
        }

        if (!Array.isArray(f.config.jumps)) { f.config.jumps = []; }

        function rerender() {
            var fresh = jumpsSection(f);
            wrap.parentNode.replaceChild(fresh, wrap);
        }

        var hint = document.createElement('p'); hint.className = 'light';
        hint.textContent = 'When this field’s answer matches, skip ahead to the chosen field’s step. First matching rule wins.';
        wrap.appendChild(hint);

        f.config.jumps.forEach(function(jump, idx) {
            wrap.appendChild(jumpRow(f, jump, laters,
                function() { f.config.jumps.splice(idx, 1); serialize(); rerender(); },
                rerender
            ));
        });

        var add = document.createElement('button');
        add.type = 'button'; add.className = 'btn sf-cond-add'; add.textContent = 'Add jump';
        add.addEventListener('click', function() {
            f.config.jumps.push({ operator: 'eq', value: '', target: '' });
            serialize(); rerender();
        });
        wrap.appendChild(add);

        return wrap;
    }

    // ---- canvas events: select / delete ---------------------------------

    canvas.addEventListener('click', function(e) {
        var move = e.target.closest('.sf-field-move');
        if (move) {
            e.preventDefault();
            moveField(move.closest('.sf-field').dataset.cid, parseInt(move.dataset.dir, 10));
            return;
        }
        var del = e.target.closest('.sf-field-del');
        if (del) { e.preventDefault(); confirmRemoveField(del.closest('.sf-field').dataset.cid, del); return; }
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

    function clearDropMarks() {
        Array.prototype.slice.call(canvas.querySelectorAll('.sf-field')).forEach(function(el) {
            el.classList.remove('dragging', 'drop-before', 'drop-after', 'drop-left', 'drop-right');
        });
    }

    canvas.addEventListener('dragend', function() { dragCid = null; clearDropMarks(); });

    // Resolve a drag event to a drop intent. When the cursor sits over a field's
    // left/right edge band (and that field's row has room) it's a side-by-side
    // drop sharing that field's row; otherwise it's a vertical reorder, returning
    // the flat insert index. Block DOM order matches the `fields` array order.
    var EDGE_BAND = 0.3;
    function dropTarget(e) {
        var blocks = Array.prototype.slice.call(canvas.querySelectorAll('.sf-field'));
        for (var i = 0; i < blocks.length; i++) {
            var r = blocks[i].getBoundingClientRect();
            if (e.clientY >= r.top && e.clientY <= r.bottom && e.clientX >= r.left && e.clientX <= r.right) {
                var cid = blocks[i].dataset.cid;
                var target = field(cid);
                var rowNum = target && rowKeyOf(target);
                var rowSize = rowNum ? fields.filter(function(f) { return rowKeyOf(f) === rowNum; }).length : 1;
                // Don't offer pairing once the row is full (unless re-ordering a
                // member already in it — that's the dragged field itself).
                var roomy = rowSize < MAX_COLUMNS || (dragCid && rowKeyOf(field(dragCid)) === rowNum);
                if (roomy) {
                    var band = r.width * EDGE_BAND;
                    if (e.clientX < r.left + band) { return { mode: 'left', cid: cid }; }
                    if (e.clientX > r.right - band) { return { mode: 'right', cid: cid }; }
                }
                break; // over the middle of a field — fall through to vertical
            }
        }
        var index = blocks.length;
        for (var j = 0; j < blocks.length; j++) {
            var rr = blocks[j].getBoundingClientRect();
            if (e.clientY < rr.top + rr.height / 2) { index = j; break; }
        }
        return { mode: 'vertical', index: index };
    }

    // Compact row numbers from the current ordering: a multi-field group gets the
    // next sequential row number; a field left alone in a row drops its row/width
    // hints. Keeps positional grouping robust after any move (and the manual Row
    // input in sync). Mirror of nothing on the PHP side — the server re-derives.
    function normalizeRows() {
        var n = 0;
        groupRows(fields).forEach(function(group) {
            if (group.length <= 1) {
                var lone = group[0];
                if (lone && lone.config) { delete lone.config.row; delete lone.config.width; }
            } else {
                n++;
                group.forEach(function(f) { f.config = f.config || {}; f.config.row = n; });
            }
        });
    }

    function nextRowNumber() {
        var max = 0;
        fields.forEach(function(f) { var r = rowKeyOf(f); if (r && r > max) { max = r; } });
        return max + 1;
    }

    // Place `moved` beside `target` (side 'left'|'right'), sharing its row.
    function pairFields(moved, target, side) {
        if (!moved || !target || moved === target) { return; }
        target.config = target.config || {};
        if (!rowKeyOf(target)) { target.config.row = nextRowNumber(); }
        var rowNum = target.config.row;
        var members = fields.filter(function(f) { return rowKeyOf(f) === rowNum; });
        if (members.length >= MAX_COLUMNS && rowKeyOf(moved) !== rowNum) { return; }
        var from = fields.indexOf(moved);
        if (from !== -1) { fields.splice(from, 1); }
        moved.config = moved.config || {};
        moved.config.row = rowNum;
        var ti = fields.indexOf(target);
        fields.splice(side === 'left' ? ti : ti + 1, 0, moved);
        normalizeRows();
    }

    canvas.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = dragCid ? 'move' : 'copy';
        clearDropMarks();
        var blocks = Array.prototype.slice.call(canvas.querySelectorAll('.sf-field'));
        var t = dropTarget(e);
        if (t.mode === 'left' || t.mode === 'right') {
            var b = blocks.filter(function(el) { return el.dataset.cid === t.cid; })[0];
            if (b) { b.classList.add(t.mode === 'left' ? 'drop-left' : 'drop-right'); }
        } else if (t.index < blocks.length) {
            blocks[t.index].classList.add('drop-before');
        } else if (blocks.length) {
            blocks[blocks.length - 1].classList.add('drop-after');
        }
    });

    canvas.addEventListener('drop', function(e) {
        e.preventDefault();
        clearDropMarks();
        var t = dropTarget(e);
        var newType = e.dataTransfer.getData('text/sf-new');

        if (newType) {
            if (t.mode === 'left' || t.mode === 'right') {
                addField(newType, null, { cid: t.cid, side: t.mode });
            } else {
                addField(newType, t.index);
            }
            return;
        }

        var moveCid = e.dataTransfer.getData('text/sf-move') || dragCid;
        if (!moveCid) { return; }
        var moved = field(moveCid);
        if (!moved) { return; }

        if (t.mode === 'left' || t.mode === 'right') {
            pairFields(moved, field(t.cid), t.mode);
            commit();
            return;
        }
        // Vertical reorder: the field leaves any row it was in (full width again).
        var from = fields.indexOf(moved);
        var index = t.index;
        fields.splice(from, 1);
        if (index > from) { index--; }
        if (moved.config) { delete moved.config.row; delete moved.config.width; }
        fields.splice(Math.min(index, fields.length), 0, moved);
        normalizeRows();
        commit();
    });

    // ---- submit guard ----------------------------------------------------

    function clientErrors() {
        var errs = [], seen = {};
        fields.forEach(function(f, i) {
            var name = f.label || f.handle || ('#' + (i + 1));
            var layout = isLayout(f.type);
            // Layout blocks carry no user-facing label (their content is the
            // heading text / divider label / HTML body), so a label is not
            // required — but every block still needs a unique handle.
            if (!layout && (!f.label || !f.label.trim())) { errs.push('Field "' + name + '": label is required.'); }
            if (!f.handle || !f.handle.trim()) { errs.push('Field "' + name + '": handle is required.'); }
            else if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(f.handle)) { errs.push('Field "' + name + '": invalid handle.'); }
            else { var k = f.handle.toLowerCase(); if (seen[k]) { errs.push('Duplicate handle "' + f.handle + '".'); } seen[k] = true; }
            if (OPTION_TYPES.indexOf(f.type) !== -1) {
                var opts = (f.config && f.config.options) || [];
                if (!opts.length) { errs.push('Field "' + name + '": needs at least one option.'); }
            }
            if (f.type === 'repeater') {
                var inner = (f.config && f.config.fields) || [];
                if (!inner.length) { errs.push('Field "' + name + '": needs at least one inner field.'); }
                var min = parseInt((f.config && f.config.minRows), 10) || 0;
                var max = parseInt((f.config && f.config.maxRows), 10) || 0;
                if (max > 0 && min > max) { errs.push('Field "' + name + '": minimum rows cannot exceed maximum rows.'); }
                var innerSeen = {};
                inner.forEach(function(inf, j) {
                    var iname = inf.handle || ('#' + (j + 1));
                    if (!inf.handle || !/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(inf.handle)) {
                        errs.push('Field "' + name + '": inner field "' + iname + '" has an invalid handle.');
                    } else {
                        var ik = inf.handle.toLowerCase();
                        if (innerSeen[ik]) { errs.push('Field "' + name + '": duplicate inner handle "' + inf.handle + '".'); }
                        innerSeen[ik] = true;
                    }
                    if (REPEATER_INNER_TYPES.indexOf(inf.type) === -1) {
                        errs.push('Field "' + name + '": inner field "' + iname + '" has an unsupported type.');
                    }
                    if (inf.type === 'select' && !((inf.options || []).length)) {
                        errs.push('Field "' + name + '": inner select "' + iname + '" needs at least one option.');
                    }
                });
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

    // ---- quiz mode (#241) ------------------------------------------------

    // Whether the form's quiz-mode lightswitch (Rules tab) is currently on.
    // Read live so the option editor shows/hides the per-option answer key.
    function quizModeOn() {
        var el = document.getElementById('quizMode');
        return !!(el && (el.getAttribute('aria-checked') === 'true' || el.classList.contains('on')));
    }

    // Re-render the open inspector when quiz mode is toggled, so the answer-key
    // controls appear/disappear without needing to reselect the field.
    var quizSwitch = document.getElementById('quizMode');
    if (quizSwitch) {
        quizSwitch.addEventListener('change', function() {
            if (selectedId) { renderInspector(); }
        });
    }

    // ---- conditional submit messages (#266) ------------------------------
    // An ordered list of confirmation messages, each gated by the SAME rule
    // builder used for field visibility (ruleList/ruleRow above) plus a per-site
    // message textarea. The first row whose condition matches the submitted
    // values wins at submit time; otherwise the form's default message shows.
    // Reuses the field conditional-rule component rather than duplicating it.
    (function() {
        var root = document.querySelector('.sf-submit-messages');
        if (!root) { return; }

        var rowsEl = root.querySelector('.sf-sm-rows');
        var emptyEl = root.querySelector('.sf-sm-empty');
        var addBtn = root.querySelector('.sf-sm-add');
        var store = document.getElementById('sf-submit-messages-data');
        if (!rowsEl || !store) { return; }

        var canAdd = root.dataset.canAdd === '1';
        var smRows = [];
        try { smRows = JSON.parse(root.dataset.initial || '[]') || []; } catch (e) { smRows = []; }
        smRows = smRows.map(normalizeMessageRow);

        // A pseudo "self" so the reused targetFields() offers every form field as a
        // condition target (a message has no owning field to exclude).
        var MESSAGE_SELF = { clientId: '__sf_submit_message__' };

        function normalizeMessageRow(r) {
            r = r || {};
            var c = r.conditional || {};
            return {
                id: (typeof r.id === 'number') ? r.id : null,
                conditional: {
                    enabled: true,
                    match: c.match === 'any' ? 'any' : 'all',
                    rules: Array.isArray(c.rules) ? c.rules : []
                },
                message: typeof r.message === 'string' ? r.message : ''
            };
        }

        function serializeMessages() {
            store.value = JSON.stringify(smRows.map(function(r) {
                return { id: r.id, conditional: r.conditional, message: r.message };
            }));
        }

        // Handles referenced by a row's rules that no longer exist on the form —
        // surfaced as a warning (mirrors the field-conditional dangling guard).
        function danglingHandles(row) {
            var out = [];
            (row.conditional.rules || []).forEach(function(rule) {
                if (rule.field && !fieldByHandle(rule.field) && out.indexOf(rule.field) === -1) {
                    out.push(rule.field);
                }
            });
            return out;
        }

        function renderRow(row, index) {
            var el = document.createElement('div');
            el.className = 'sf-sm-row';
            el.dataset.index = String(index);

            var head = document.createElement('div'); head.className = 'sf-sm-row-head';
            // Only the grip starts a drag, so selecting text in the message
            // textarea never accidentally reorders the row.
            var grip = document.createElement('span');
            grip.className = 'sf-grip sf-sm-grip'; grip.setAttribute('aria-hidden', 'true');
            grip.setAttribute('draggable', 'true'); grip.textContent = '⋮⋮';
            head.appendChild(grip);
            var title = document.createElement('span');
            title.className = 'sf-sm-row-title'; title.textContent = 'Message ' + (index + 1);
            head.appendChild(title);
            var del = document.createElement('button');
            del.type = 'button'; del.className = 'btn sf-sm-del'; del.textContent = '×'; del.title = 'Remove message';
            del.addEventListener('click', function() {
                smRows.splice(index, 1); serializeMessages(); renderMessages();
            });
            head.appendChild(del);
            el.appendChild(head);

            var showWhen = document.createElement('p');
            showWhen.className = 'sf-sm-when light'; showWhen.textContent = 'Show this message when';
            el.appendChild(showWhen);

            // The reused rule builder (match all/any + field/operator/value rows).
            el.appendChild(ruleList(MESSAGE_SELF, row.conditional, function() {
                var fresh = renderRow(row, index);
                el.parentNode.replaceChild(fresh, el);
            }, serializeMessages));

            var dangling = danglingHandles(row);
            if (dangling.length) {
                var warn = document.createElement('p');
                warn.className = 'sf-sm-warning warning';
                warn.textContent = 'A rule references a field that no longer exists: ' + dangling.join(', ') + '.';
                el.appendChild(warn);
            }

            var msgLabel = document.createElement('label');
            msgLabel.className = 'sf-sm-msg-label'; msgLabel.textContent = 'Message';
            el.appendChild(msgLabel);
            var ta = document.createElement('textarea');
            ta.className = 'text fullwidth'; ta.rows = 2; ta.value = row.message || '';
            ta.addEventListener('input', function() { row.message = ta.value; serializeMessages(); });
            el.appendChild(ta);

            return el;
        }

        function renderMessages() {
            rowsEl.innerHTML = '';
            smRows.forEach(function(row, i) { rowsEl.appendChild(renderRow(row, i)); });
            if (emptyEl) { emptyEl.style.display = smRows.length ? 'none' : ''; }
        }

        if (addBtn && canAdd) {
            addBtn.addEventListener('click', function() {
                smRows.push(normalizeMessageRow({}));
                serializeMessages(); renderMessages();
            });
        }

        // Drag-to-reorder (HTML5), mirroring the field canvas: reorder the backing
        // array on drop, then re-render so indices and titles stay in sync.
        var dragIndex = null;
        rowsEl.addEventListener('dragstart', function(e) {
            var row = e.target.closest ? e.target.closest('.sf-sm-row') : null;
            if (!row) { return; }
            dragIndex = parseInt(row.dataset.index, 10);
            e.dataTransfer.effectAllowed = 'move';
        });
        rowsEl.addEventListener('dragover', function(e) {
            if (dragIndex === null) { return; }
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        });
        rowsEl.addEventListener('drop', function(e) {
            if (dragIndex === null) { return; }
            e.preventDefault();
            var target = e.target.closest ? e.target.closest('.sf-sm-row') : null;
            var to = target ? parseInt(target.dataset.index, 10) : smRows.length - 1;
            var moved = smRows.splice(dragIndex, 1)[0];
            if (to > dragIndex) { to--; }
            smRows.splice(Math.min(to, smRows.length), 0, moved);
            dragIndex = null;
            serializeMessages(); renderMessages();
        });

        renderMessages();
        serializeMessages();
    })();

    // ---- init ------------------------------------------------------------
    render();
    serialize();
})();
