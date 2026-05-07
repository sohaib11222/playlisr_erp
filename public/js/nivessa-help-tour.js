/* Nivessa help tour — minimal vanilla JS guided walkthrough.
 *
 * Pages opt in by rendering the tour-button partial, which loads this
 * file and calls window.NivessaTour.start(stepsArray).
 *
 * Defensive on purpose: this script never modifies existing DOM, only
 * adds new overlay elements. If anything in here errors, the only
 * thing that breaks is the tour itself — never the underlying page.
 */
(function () {
    'use strict';

    var ns = (window.NivessaTour = window.NivessaTour || {});

    var state = {
        steps: [],
        index: 0,
        backdrop: null,
        highlight: null,
        tooltip: null,
        keyHandler: null
    };

    ns.start = function (steps) {
        try {
            if (!Array.isArray(steps) || steps.length === 0) return;
            state.steps = steps;
            state.index = 0;
            ensureUI();
            renderStep();
        } catch (e) { /* swallow — tour failure must never break the page */ }
    };

    ns.close = function () {
        try {
            if (state.tooltip) state.tooltip.style.display = 'none';
            if (state.highlight) state.highlight.style.display = 'none';
            if (state.backdrop) state.backdrop.style.display = 'none';
            if (state.keyHandler) {
                document.removeEventListener('keydown', state.keyHandler);
                state.keyHandler = null;
            }
        } catch (e) { /* swallow */ }
    };

    function ensureUI() {
        if (state.backdrop) return;

        state.backdrop = document.createElement('div');
        state.backdrop.className = 'nv-tour-backdrop';
        state.backdrop.addEventListener('click', ns.close);
        document.body.appendChild(state.backdrop);

        state.highlight = document.createElement('div');
        state.highlight.className = 'nv-tour-highlight';
        document.body.appendChild(state.highlight);

        state.tooltip = document.createElement('div');
        state.tooltip.className = 'nv-tour-tooltip';
        document.body.appendChild(state.tooltip);

        state.keyHandler = function (e) {
            if (e.key === 'Escape') ns.close();
            else if (e.key === 'ArrowRight') next();
            else if (e.key === 'ArrowLeft') prev();
        };
        document.addEventListener('keydown', state.keyHandler);
    }

    function findVisibleTarget(selector) {
        if (!selector) return null;
        var el;
        try { el = document.querySelector(selector); } catch (e) { return null; }
        if (!el) return null;
        var rect = el.getBoundingClientRect();
        if (rect.width === 0 && rect.height === 0) {
            var sib = el.parentElement && el.parentElement.querySelector('.select2-container');
            if (sib) return sib;
            return el.parentElement;
        }
        return el;
    }

    function renderStep() {
        var step = state.steps[state.index];
        if (!step) { ns.close(); return; }

        state.backdrop.style.display = 'block';

        var target = findVisibleTarget(step.selector);
        if (!target) {
            state.highlight.style.display = 'none';
            renderTooltip(step, null);
            return;
        }

        try { target.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        catch (e) { target.scrollIntoView(); }

        setTimeout(function () {
            try {
                var rect = target.getBoundingClientRect();
                state.highlight.style.display = 'block';
                state.highlight.style.left = (rect.left + window.scrollX - 6) + 'px';
                state.highlight.style.top = (rect.top + window.scrollY - 6) + 'px';
                state.highlight.style.width = (rect.width + 12) + 'px';
                state.highlight.style.height = (rect.height + 12) + 'px';
                renderTooltip(step, rect);
            } catch (e) { ns.close(); }
        }, 220);
    }

    function renderTooltip(step, rect) {
        var html = '';
        html += '<div class="nv-tour-step-counter">Step ' + (state.index + 1) + ' of ' + state.steps.length + '</div>';
        if (step.title) html += '<h3>' + escape(step.title) + '</h3>';
        if (step.body) html += '<div class="nv-tour-body">' + step.body + '</div>';
        html += '<div class="nv-tour-actions">';
        if (state.index > 0) html += '<button type="button" class="nv-tour-btn nv-tour-btn-back" data-tour-prev>&larr; Back</button>';
        html += '<button type="button" class="nv-tour-btn nv-tour-btn-skip" data-tour-skip>Skip</button>';
        if (state.index < state.steps.length - 1) {
            html += '<button type="button" class="nv-tour-btn nv-tour-btn-next" data-tour-next>Next &rarr;</button>';
        } else {
            html += '<button type="button" class="nv-tour-btn nv-tour-btn-done" data-tour-done>Done</button>';
        }
        html += '</div>';
        state.tooltip.innerHTML = html;
        state.tooltip.style.transform = '';
        state.tooltip.style.display = 'block';

        if (rect) {
            var ttRect = state.tooltip.getBoundingClientRect();
            var spaceBelow = window.innerHeight - rect.bottom;
            var topPx;
            if (spaceBelow > ttRect.height + 30) {
                topPx = rect.bottom + window.scrollY + 14;
            } else {
                topPx = rect.top + window.scrollY - ttRect.height - 14;
            }
            var leftPx = rect.left + window.scrollX + (rect.width / 2) - (ttRect.width / 2);
            leftPx = Math.max(16, Math.min(leftPx, window.innerWidth - ttRect.width - 16));
            state.tooltip.style.left = leftPx + 'px';
            state.tooltip.style.top = topPx + 'px';
        } else {
            state.tooltip.style.left = '50%';
            state.tooltip.style.top = '50%';
            state.tooltip.style.transform = 'translate(-50%, -50%)';
        }

        bind('[data-tour-next]', next);
        bind('[data-tour-prev]', prev);
        bind('[data-tour-skip]', ns.close);
        bind('[data-tour-done]', ns.close);
    }

    function bind(sel, fn) {
        var el = state.tooltip.querySelector(sel);
        if (el) el.onclick = function (e) { e.preventDefault(); fn(); };
    }

    function next() {
        if (state.index < state.steps.length - 1) {
            state.index++;
            renderStep();
        } else {
            ns.close();
        }
    }

    function prev() {
        if (state.index > 0) {
            state.index--;
            renderStep();
        }
    }

    function escape(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
})();
