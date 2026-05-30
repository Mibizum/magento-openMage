/**
 * mibizum-multiselect-table - multi picker with a table of selected items.
 *
 * Designed for the "search box + list of chosen items with per-row metadata"
 * pattern (e.g. categories with "include descendants").
 *
 * Expected markup:
 *
 *   <div class="mibizum-multiselect-table"
 *        data-mibizum-url="/searchCategories"
 *        data-mibizum-out-key="category_id"           (key of the value in the JSON)
 *        data-mibizum-extras='[{"key":"include_descendants",
 *                            "type":"checkbox",
 *                            "label":"Include subcategories",
 *                            "default":true}]'
 *        data-mibizum-initial='[{"value":12,"label":"Cat A","sublabel":"Path > Cat A",
 *                             "extras":{"include_descendants":true}}]'
 *        data-mibizum-name="categories_json">
 *     <input type="text" class="mst-input input-text" placeholder="Search…"/>
 *     <ul class="mst-suggestions" style="display:none;"></ul>
 *     <table class="mst-table data"><thead></thead><tbody class="mst-rows"></tbody></table>
 *     <p class="mst-empty">No items.</p>
 *     <input type="hidden" class="mst-value" name="categories_json" value="[]"/>
 *   </div>
 *
 * Vanilla JS - coexists with the admin's Prototype.js.
 */
(function () {
    'use strict';

    function bind(root) {
        if (root._mstBound) return;
        root._mstBound = true;

        var input   = root.querySelector('.mst-input');
        var sugg    = root.querySelector('.mst-suggestions');
        var rowsTb  = root.querySelector('.mst-rows');
        var empty   = root.querySelector('.mst-empty');
        var hidden  = root.querySelector('.mst-value');
        if (!input || !sugg || !rowsTb || !hidden) return;

        var url         = root.getAttribute('data-mibizum-url') || '';
        var outKey      = root.getAttribute('data-mibizum-out-key') || 'value';
        var emptyText   = root.getAttribute('data-mibizum-empty') || 'No results.';
        var removeLabel = root.getAttribute('data-mibizum-remove-label') || 'Remove';
        var extras      = [];
        var initial     = [];
        try { extras  = JSON.parse(root.getAttribute('data-mibizum-extras')  || '[]'); } catch (e) {}
        try { initial = JSON.parse(root.getAttribute('data-mibizum-initial') || '[]'); } catch (e) {}

        // Internal model: array of {value, label, sublabel, extras:{...}}
        var rows = initial.map(function (r) {
            return {
                value:    r.value,
                label:    r.label || String(r.value),
                sublabel: r.sublabel || '',
                extras:   r.extras || {},
            };
        });
        var debounce = null;

        function adaptSuggestion(it) {
            if ('value' in it && 'label' in it) {
                return { value: it.value, label: it.label, sublabel: it.sublabel || it.path || '' };
            }
            if ('id' in it) {
                return {
                    value: it.id,
                    label: String(it.name || it.id),
                    sublabel: it.path || '',
                };
            }
            return null;
        }

        function syncHidden() {
            hidden.value = JSON.stringify(rows.map(function (r) {
                var out = {};
                out[outKey] = r.value;
                extras.forEach(function (e) {
                    out[e.key] = (e.key in r.extras) ? r.extras[e.key] : e.default;
                });
                return out;
            }));
        }

        function renderRows() {
            rowsTb.innerHTML = '';
            if (empty) empty.style.display = rows.length === 0 ? '' : 'none';

            rows.forEach(function (r, idx) {
                var tr = document.createElement('tr');

                // Main column: label + sublabel (path).
                var tdMain = document.createElement('td');
                var strong = document.createElement('strong');
                strong.textContent = r.label;
                tdMain.appendChild(strong);
                if (r.sublabel) {
                    var sub = document.createElement('span');
                    sub.className = 'mst-sublabel';
                    sub.textContent = ' ' + r.sublabel;
                    tdMain.appendChild(sub);
                }
                tr.appendChild(tdMain);

                // Extra columns (checkbox, text, etc.).
                extras.forEach(function (e) {
                    var td = document.createElement('td');
                    if (e.type === 'checkbox') {
                        var lbl = document.createElement('label');
                        lbl.className = 'mst-extra-label';
                        var cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.checked = (e.key in r.extras) ? !!r.extras[e.key] : !!e.default;
                        cb.addEventListener('change', function () {
                            rows[idx].extras[e.key] = cb.checked;
                            syncHidden();
                        });
                        var sp = document.createElement('span');
                        sp.textContent = ' ' + (e.label || e.key);
                        lbl.appendChild(cb);
                        lbl.appendChild(sp);
                        td.appendChild(lbl);
                    } else {
                        // type text / number: editable input.
                        var inp = document.createElement('input');
                        inp.type = (e.type === 'number') ? 'number' : 'text';
                        inp.className = 'input-text';
                        inp.value = (e.key in r.extras) ? r.extras[e.key] : (e.default || '');
                        inp.addEventListener('input', function () {
                            rows[idx].extras[e.key] = inp.value;
                            syncHidden();
                        });
                        td.appendChild(inp);
                    }
                    tr.appendChild(td);
                });

                // Action column: remove.
                var tdAct = document.createElement('td');
                var rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'mst-remove';
                rm.title = removeLabel;
                rm.textContent = '×';
                rm.addEventListener('click', function () {
                    rows.splice(idx, 1);
                    renderRows();
                });
                tdAct.appendChild(rm);
                tr.appendChild(tdAct);

                rowsTb.appendChild(tr);
            });
            syncHidden();
        }

        function positionSuggestions() {
            // The CSS uses position:fixed to escape the parent fieldset's
            // stacking contexts. Top comes from the input (right below it); but
            // width and left come from the whole container - this way the
            // dropdown aligns with the table below, which is much wider than the
            // input. Long category paths fit without being truncated.
            var ir = input.getBoundingClientRect();
            var rr = root.getBoundingClientRect();
            sugg.style.top    = (ir.bottom + 2) + 'px';
            sugg.style.left   = rr.left + 'px';
            sugg.style.width  = rr.width + 'px';
        }

        function showSuggestions(items) {
            positionSuggestions();
            sugg.innerHTML = '';
            var adapted = (items || []).map(adaptSuggestion).filter(Boolean);
            if (adapted.length === 0) {
                var li = document.createElement('li');
                li.className = 'mst-empty-sugg';
                li.textContent = emptyText;
                sugg.appendChild(li);
            } else {
                adapted.forEach(function (it) {
                    var already = rows.some(function (r) { return String(r.value) === String(it.value); });
                    var li = document.createElement('li');
                    var top = document.createElement('div');
                    top.className = 'mst-sugg-top';
                    top.textContent = it.label + (already ? ' ✓' : '');
                    li.appendChild(top);
                    if (it.sublabel) {
                        var small = document.createElement('small');
                        small.textContent = it.sublabel;
                        li.appendChild(small);
                    }
                    if (already) {
                        li.className = 'mst-sugg-disabled';
                    } else {
                        li.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            var initExtras = {};
                            extras.forEach(function (e2) { initExtras[e2.key] = e2.default; });
                            rows.push({
                                value:    it.value,
                                label:    it.label,
                                sublabel: it.sublabel,
                                extras:   initExtras,
                            });
                            input.value = '';
                            sugg.style.display = 'none';
                            renderRows();
                        });
                    }
                    sugg.appendChild(li);
                });
            }
            sugg.style.display = 'block';
        }

        function fetchSuggestions(q) {
            if (!url || typeof Ajax === 'undefined' || !Ajax.Request) return;
            var sep = url.indexOf('?') !== -1 ? '&' : '?';
            new Ajax.Request(url + sep + 'q=' + encodeURIComponent(q), {
                method: 'get',
                onSuccess: function (resp) {
                    try {
                        var data = JSON.parse(resp.responseText);
                        showSuggestions(Array.isArray(data) ? data : (data.rows || []));
                    } catch (e) {
                        showSuggestions([]);
                    }
                },
                onFailure: function () { showSuggestions([]); },
            });
        }

        input.addEventListener('input', function () {
            var q = input.value.trim();
            clearTimeout(debounce);
            if (q.length < 2) { sugg.style.display = 'none'; return; }
            debounce = setTimeout(function () { fetchSuggestions(q); }, 200);
        });
        input.addEventListener('focus', function () {
            if (input.value.trim().length >= 2) {
                positionSuggestions();
                sugg.style.display = 'block';
            }
        });
        input.addEventListener('blur', function () {
            setTimeout(function () { sugg.style.display = 'none'; }, 150);
        });

        // Init.
        renderRows();
    }

    function bindAll() {
        var nodes = document.querySelectorAll('.mibizum-multiselect-table');
        for (var i = 0; i < nodes.length; i++) bind(nodes[i]);
    }

    if (document.readyState !== 'loading') bindAll();
    else document.addEventListener('DOMContentLoaded', bindAll);
    if (typeof document.observe === 'function') {
        document.observe('dom:loaded', bindAll);
    }
})();
