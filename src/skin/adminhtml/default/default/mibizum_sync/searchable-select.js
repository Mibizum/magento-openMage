/**
 * mibizum-searchable-select - searchable combo box for the Magento admin.
 *
 * Replaces the native <select> with an input that filters options as you type.
 * Supports two modes:
 *   - client: options preloaded in data-mibizum-options (JSON array of
 *     {value, label}). Filters in-memory as you type.
 *   - AJAX: data-mibizum-url points to an endpoint that returns JSON for
 *     ?q=<text>. Accepts items shaped {value,label}, {code,frontend_label}
 *     or {id,name}.
 *
 * Expected markup:
 *
 *   <div class="mibizum-searchable-select"
 *        data-mibizum-options='[{"value":"a","label":"A"}]'>
 *     <input type="text" class="mss-input" placeholder="Search..."/>
 *     <input type="hidden" class="mss-value" name="field" value=""/>
 *     <ul class="mss-list" style="display:none;"></ul>
 *   </div>
 *
 * Vanilla JS - coexists with the admin's Prototype.js (used for Ajax.Request
 * when an endpoint is present).
 */
(function () {
    'use strict';

    function bind(root) {
        if (root._mssBound) return;
        root._mssBound = true;

        var input  = root.querySelector('.mss-input');
        var hidden = root.querySelector('.mss-value');
        var list   = root.querySelector('.mss-list');
        if (!input || !hidden || !list) return;

        var url       = root.getAttribute('data-mibizum-url') || null;
        var emptyText = root.getAttribute('data-mibizum-empty') || 'No matches';
        var opts      = [];
        try { opts = JSON.parse(root.getAttribute('data-mibizum-options') || '[]'); } catch (e) { opts = []; }

        var current   = opts.slice();
        var activeIdx = -1;
        var debounce  = null;

        function adapt(items) {
            return (items || []).map(function (it) {
                if ('value' in it && 'label' in it) return it;
                if ('code' in it) {
                    var label = it.frontend_label && it.frontend_label !== ''
                        ? it.frontend_label + ' (' + it.code + ')' : it.code;
                    return { value: it.code, label: label };
                }
                if ('id' in it) {
                    var lab = String(it.name || it.id);
                    // Some endpoints return a SKU (catalog products); if present
                    // we prepend it to the name so the admin can see it.
                    if (it.sku) lab = String(it.sku) + ' - ' + lab;
                    return { value: String(it.id), label: lab };
                }
                return { value: '', label: '' };
            }).filter(function (o) { return o.value !== ''; });
        }

        function filterClient(q) {
            if (!q) return opts.slice();
            var n = q.toLowerCase();
            return opts.filter(function (o) {
                return o.label.toLowerCase().indexOf(n) !== -1
                    || o.value.toLowerCase().indexOf(n) !== -1;
            });
        }

        function render() {
            list.innerHTML = '';
            if (!current.length) {
                var em = document.createElement('li');
                em.className = 'mss-empty';
                em.textContent = emptyText;
                list.appendChild(em);
                return;
            }
            current.forEach(function (o, i) {
                var li = document.createElement('li');
                li.className = 'mss-item' + (i === activeIdx ? ' mss-item--active' : '');
                li.textContent = o.label;
                li.setAttribute('data-value', o.value);
                li.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    pick(o);
                });
                list.appendChild(li);
            });
        }

        function show() {
            // Recompute the absolute position via getBoundingClientRect: the CSS
            // uses position:fixed to escape the parent fieldset's stacking
            // context (see searchable-select.css). This keeps working even when
            // the input is inside a varien-form <tr> or a Magento admin fieldset.
            var r = input.getBoundingClientRect();
            list.style.top    = (r.bottom + 2) + 'px';
            list.style.left   = r.left + 'px';
            list.style.width  = r.width + 'px';
            list.style.display = 'block';
        }
        function hide() { list.style.display = 'none'; activeIdx = -1; }

        function pick(o) {
            hidden.value = o.value;
            input.value  = o.label;
            try {
                var ev = (typeof Event === 'function')
                    ? new Event('change', { bubbles: true })
                    : (function () { var e = document.createEvent('Event'); e.initEvent('change', true, true); return e; })();
                hidden.dispatchEvent(ev);
            } catch (e) {}
            hide();
        }

        function searchAjax(q) {
            if (typeof Ajax === 'undefined' || !Ajax.Request) return;
            var sep = url.indexOf('?') >= 0 ? '&' : '?';
            new Ajax.Request(url + sep + 'q=' + encodeURIComponent(q), {
                method: 'get',
                onSuccess: function (resp) {
                    try {
                        var data = JSON.parse(resp.responseText);
                        current = adapt(Array.isArray(data) ? data : (data.rows || []));
                        activeIdx = -1;
                        render();
                        show();
                    } catch (e) {}
                }
            });
        }

        function onInput() {
            var q = input.value.trim();
            if (url) {
                clearTimeout(debounce);
                debounce = setTimeout(function () { searchAjax(q); }, 200);
            } else {
                current = filterClient(q);
                activeIdx = -1;
                render();
                show();
            }
        }

        input.addEventListener('focus', function () {
            if (url) {
                searchAjax(input.value.trim());
            } else {
                current = filterClient(input.value.trim());
                render();
                show();
            }
        });
        input.addEventListener('input', onInput);
        input.addEventListener('blur', function () { setTimeout(hide, 150); });
        input.addEventListener('keydown', function (e) {
            if (list.style.display === 'none') return;
            if (e.key === 'ArrowDown' || e.keyCode === 40) {
                e.preventDefault();
                activeIdx = Math.min(activeIdx + 1, current.length - 1);
                render();
            } else if (e.key === 'ArrowUp' || e.keyCode === 38) {
                e.preventDefault();
                activeIdx = Math.max(activeIdx - 1, 0);
                render();
            } else if (e.key === 'Enter' || e.keyCode === 13) {
                if (activeIdx >= 0 && current[activeIdx]) {
                    e.preventDefault();
                    pick(current[activeIdx]);
                }
            } else if (e.key === 'Escape' || e.keyCode === 27) {
                hide();
            }
        });

        // If the hidden field already has a value (editing), reflect its label in the input.
        if (hidden.value) {
            var found = opts.filter(function (o) { return o.value === hidden.value; })[0];
            if (found) input.value = found.label;
        }
    }

    function bindAll() {
        var nodes = document.querySelectorAll('.mibizum-searchable-select');
        for (var i = 0; i < nodes.length; i++) bind(nodes[i]);
    }

    if (document.readyState !== 'loading') bindAll();
    else document.addEventListener('DOMContentLoaded', bindAll);

    // The admin's Prototype.js fires dom:loaded - we cover the case where the
    // browser's DOMContentLoaded arrives before we are loaded.
    if (typeof document.observe === 'function') {
        document.observe('dom:loaded', bindAll);
    }
})();
