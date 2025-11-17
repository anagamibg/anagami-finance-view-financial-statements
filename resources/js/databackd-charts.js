/*
 * AFV chart theme and helpers for Chart.js 3.x — window.dbkChart
 * Rules: docs/mvp/phase2/ui-guidelines.md (section 6). Loaded globally by layouts/app after Chart.min.js.
 *
 *   dbkChart.bar('#chart', {labels, datasets: [{label, data, role: 'revenue'}], stacked, horizontal, unit})
 *   dbkChart.line(el, {labels, datasets, area, unit})
 *   dbkChart.combo(el, {labels, bars: [...], lines: [...], unit})
 *   dbkChart.donut(el, {labels, data, colors, unit, center: {label, value}})
 *   dbkChart.sparkline(el, {data, labels, role|color, area})
 *   dbkChart.create(el, chartJsConfig)   // any Chart.js config with the theme applied
 *   dbkChart.empty(el, message)          // empty state in place of the chart
 *   dbkChart.fmt.money(v) → "1 234,56 €", fmt.number(v, d), fmt.percent(v, d), fmt.compact(v), fmt.axis(v)
 *
 * Dataset options: role ('revenue'|'expense'|'profit'|'cash'|'budget'|'forecast'|'neutral'), color (hex),
 * or neither (categorical colour by position, fixed order). Every helper replaces a chart already on the canvas.
 * unit: 'money' (default) | 'number' | 'percent' | a function(value) → string.
 * Currency: EUR, or the ISO code in window.dbkCurrency / <html data-dbk-currency="…"> (company currency), read on every call.
 * A chart that fails to build shows an error state in its own box and does not stop the other charts on the page.
 */
(function (window) {
    'use strict';

    var LOCALE = (document.documentElement.getAttribute('lang') || 'bg').toLowerCase().indexOf('en') === 0 ? 'en-GB' : 'bg-BG';
    var MINUS = '−';

    // ISO code of the company currency (amounts are in the company base currency); EUR by default
    function currency() {
        var c = window.dbkCurrency || document.documentElement.getAttribute('data-dbk-currency') || 'EUR';
        return /^[A-Za-z]{3}$/.test(c) ? c.toUpperCase() : 'EUR';
    }

    var NBSP = ' ';

    var LIGHT = {
        revenue: '#16a06a', expense: '#e34948', profit: '#2a78d6', cash: '#6250d6', budget: '#9aa5b4', forecast: '#eb6834', neutral: '#9aa5b4',
        good: '#0b7a45', warning: '#9a5b00', bad: '#c9302c',
        series: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'],
        text: '#4f5b6b', muted: '#66717f', grid: 'rgba(29,38,51,0.08)', surface: '#ffffff', tooltipBg: '#1d2633', tooltipText: '#ffffff'
    };
    var DARK = {
        revenue: '#1fb87b', expense: '#ef6b6a', profit: '#3987e5', cash: '#9085e9', budget: '#6f7891', forecast: '#d95926', neutral: '#6f7891',
        good: '#3ccf86', warning: '#f2b13d', bad: '#ff7a72',
        series: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'],
        text: '#b9bfcf', muted: '#9aa1b5', grid: 'rgba(230,232,239,0.1)', surface: '#1f2440', tooltipBg: '#0f1226', tooltipText: '#e6e8ef'
    };

    function isDark() {
        return !!(document.body && document.body.classList.contains('dark-theme'));
    }

    function palette() {
        return isDark() ? DARK : LIGHT;
    }

    function media(query) {
        return !!(window.matchMedia && window.matchMedia(query).matches);
    }

    var reducedMotion = media('(prefers-reduced-motion: reduce)');

    // ------------------------------------------------------------------ formatting
    var cache = {};

    function nf(options) {
        var key = JSON.stringify(options);
        if (!cache[key]) {
            var opts = Object.assign({}, options);
            try {
                opts.useGrouping = 'always';
                cache[key] = new Intl.NumberFormat(LOCALE, opts);
            } catch (e) {
                opts.useGrouping = true;
                cache[key] = new Intl.NumberFormat(LOCALE, opts);
            }
        }
        return cache[key];
    }

    // bg-BG groups 4-digit numbers only with useGrouping 'always' (newer browsers); force it everywhere (integer part only).
    // Negative numbers get the minus sign "−" instead of the hyphen (ui-guidelines §7).
    function forceGrouping(str) {
        str = str.replace(/^(\D*)-/, '$1' + MINUS);
        if (LOCALE !== 'bg-BG') return str;
        return str.replace(/(^|[^\d,])(\d{4,})/g, function (all, lead, digits) {
            return lead + digits.replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
        });
    }

    function toNumber(v) {
        if (v === null || v === undefined || v === '') return null;
        var n = typeof v === 'number' ? v : parseFloat(v);
        return isFinite(n) ? n : null;
    }

    var fmt = {
        money: function (v, decimals) {
            var n = toNumber(v);
            if (n === null) return '—';
            var d = decimals === undefined ? 2 : decimals;
            return forceGrouping(nf({ style: 'currency', currency: currency(), minimumFractionDigits: d, maximumFractionDigits: d }).format(n));
        },
        number: function (v, decimals) {
            var n = toNumber(v);
            if (n === null) return '—';
            var d = decimals === undefined ? 0 : decimals;
            return forceGrouping(nf({ minimumFractionDigits: d, maximumFractionDigits: d }).format(n));
        },
        percent: function (v, decimals) {
            var n = toNumber(v);
            if (n === null) return '—';
            var d = decimals === undefined ? 1 : decimals;
            return fmt.number(n, d) + NBSP + '%';
        },
        compact: function (v) {
            var n = toNumber(v);
            if (n === null) return '—';
            var abs = Math.abs(n);
            if (abs < 10000) return fmt.number(n, 0);
            return forceGrouping(nf({ notation: 'compact', compactDisplay: 'short', maximumFractionDigits: abs < 1e6 ? 0 : 1 }).format(n));
        },
        compactMoney: function (v) {
            var n = toNumber(v);
            if (n === null) return '—';
            if (Math.abs(n) < 10000) return fmt.money(n, 0);
            return forceGrouping(nf({ style: 'currency', currency: currency(), notation: 'compact', compactDisplay: 'short', maximumFractionDigits: Math.abs(n) < 1e6 ? 0 : 1 }).format(n));
        },
        signed: function (v, formatter) {
            var n = toNumber(v);
            if (n === null) return '—';
            var s = (formatter || fmt.number)(Math.abs(n));
            return (n > 0 ? '+' : n < 0 ? '−' : '') + s;
        },
        // Axis ticks: thousands with a space up to 1M, compact above
        axis: function (v) {
            var n = toNumber(v);
            if (n === null) return '';
            return Math.abs(n) >= 1e6 ? fmt.compact(n) : fmt.number(n, Math.abs(n) < 10 && n % 1 !== 0 ? 1 : 0);
        }
    };

    function unitFormatter(unit) {
        if (typeof unit === 'function') return unit;
        if (unit === 'percent') return function (v) { return fmt.percent(v); };
        if (unit === 'number') return function (v) { return fmt.number(v, Math.abs(v) < 100 && v % 1 !== 0 ? 2 : 0); };
        if (unit === 'ratio') return function (v) { return fmt.number(v, 2); };
        return function (v) { return fmt.money(v); };
    }

    function axisFormatter(unit, compact) {
        if (typeof unit === 'function') return unit;
        // narrow value axis (horizontal bars): "200 хил." instead of "200 000", so the ticks never overlap
        if (compact && (unit === undefined || unit === 'money' || unit === 'number')) {
            return function (v) { return Math.abs(v) >= 10000 ? fmt.compact(v) : fmt.axis(v); };
        }
        if (unit === 'percent') return function (v) { return fmt.number(v, Math.abs(v) < 10 && v % 1 !== 0 ? 1 : 0) + NBSP + '%'; };
        if (unit === 'ratio') return function (v) { return fmt.number(v, Math.abs(v) < 10 ? 1 : 0); };
        return fmt.axis;
    }

    // ------------------------------------------------------------------ colours
    function color(i) {
        var s = palette().series;
        return s[i % s.length];
    }

    function role(name) {
        var p = palette();
        return p[name] || null;
    }

    function alpha(hex, a) {
        if (!hex || hex.charAt(0) !== '#') return hex;
        var h = hex.length === 4 ? hex.replace(/^#(.)(.)(.)$/, '#$1$1$2$2$3$3') : hex;
        var r = parseInt(h.substr(1, 2), 16), g = parseInt(h.substr(3, 2), 16), b = parseInt(h.substr(5, 2), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
    }

    function datasetColor(ds, i) {
        if (ds.color) return ds.color;
        if (ds.role && role(ds.role)) return role(ds.role);
        return color(i);
    }

    /**
     * Fold a long category list into top N + "Други"/"Other" (keeps the fixed colour order).
     * @param {Array<{label:string, value:number}>} items
     * @param {number} n
     * @param {string} otherLabel
     */
    function topN(items, n, otherLabel) {
        var sorted = items.slice().sort(function (a, b) { return Math.abs(b.value) - Math.abs(a.value); });
        if (sorted.length <= n) return sorted;
        var head = sorted.slice(0, n - 1);
        var rest = sorted.slice(n - 1).reduce(function (s, x) { return s + (x.value || 0); }, 0);
        head.push({ label: otherLabel || (LOCALE === 'bg-BG' ? 'Други' : 'Other'), value: rest, other: true });
        return head;
    }

    // ------------------------------------------------------------------ theme
    function applyDefaults(Chart) {
        if (!Chart || Chart.__dbkThemed === isDark()) return;
        var p = palette();
        var d = Chart.defaults;

        d.font.family = '"Helvetica Neue", Helvetica, Arial, sans-serif';
        d.font.size = 12;
        d.color = p.text;
        d.borderColor = p.grid;
        d.maintainAspectRatio = false;
        d.responsive = true;
        d.animation = reducedMotion ? false : { duration: 400 };

        d.plugins.legend.position = 'bottom';
        d.plugins.legend.align = 'center';
        d.plugins.legend.labels.usePointStyle = true;
        d.plugins.legend.labels.pointStyle = 'circle';
        d.plugins.legend.labels.boxWidth = 8;
        d.plugins.legend.labels.boxHeight = 8;
        d.plugins.legend.labels.padding = media('(max-width: 575.98px)') ? 10 : 16;
        d.plugins.legend.labels.color = p.text;
        // Legend & tooltip follow dataset order (revenue, expense, profit), not the drawing order.
        d.plugins.legend.labels.sort = function (a, b) { return a.datasetIndex - b.datasetIndex; };

        var t = d.plugins.tooltip;
        t.backgroundColor = p.tooltipBg;
        t.titleColor = p.tooltipText;
        t.bodyColor = p.tooltipText;
        t.footerColor = p.tooltipText;
        t.padding = 10;
        t.cornerRadius = 6;
        t.boxWidth = 8;
        t.boxHeight = 8;
        t.boxPadding = 6;
        t.usePointStyle = true;
        t.titleFont = { weight: '600', size: 12 };
        t.bodyFont = { size: 12 };
        t.caretSize = 5;
        t.displayColors = true;
        t.itemSort = function (a, b) { return a.datasetIndex - b.datasetIndex; };

        d.elements.bar.borderRadius = 4;
        d.elements.bar.borderSkipped = 'start';
        d.elements.line.borderWidth = 2;
        d.elements.line.tension = 0.25;
        d.elements.point.radius = 0;
        d.elements.point.hoverRadius = 4;
        d.elements.point.hitRadius = 8;
        d.elements.arc.borderWidth = 2;
        d.elements.arc.borderColor = p.surface;

        Chart.__dbkThemed = isDark();
    }

    function chartLib() {
        var Chart = window.Chart;
        if (!Chart) throw new Error('dbkChart: Chart.js is not loaded');
        applyDefaults(Chart);
        return Chart;
    }

    // ------------------------------------------------------------------ element helpers
    function resolve(el) {
        if (!el) return null;
        if (typeof el === 'string') el = document.getElementById(el.replace(/^#/, '')) || document.querySelector(el);
        if (el && el.jquery) el = el[0];
        if (!el) return null;
        if (el.tagName !== 'CANVAS') {
            var c = el.querySelector('canvas');
            if (!c) {
                c = document.createElement('canvas');
                el.appendChild(c);
            }
            el = c;
        }
        return el;
    }

    function clearEmpty(canvas) {
        var box = canvas.parentNode;
        var e = box && box.querySelector(':scope > .dbk-chart-empty');
        if (e) e.parentNode.removeChild(e);
        canvas.style.display = '';
    }

    function destroy(el) {
        var canvas = resolve(el);
        if (!canvas) return;
        var Chart = window.Chart;
        var existing = Chart && Chart.getChart ? Chart.getChart(canvas) : null;
        if (existing) existing.destroy();
    }

    function create(el, config) {
        var canvas = resolve(el);
        if (!canvas) return null;
        try {
            var Chart = chartLib();
            destroy(canvas);
            clearEmpty(canvas);
            return new Chart(canvas.getContext('2d'), config);
        } catch (e) {
            // one broken chart must not stop the others (ui-guidelines §9): show it in its own box
            if (window.console) console.error('dbkChart:', e);
            empty(canvas, LOCALE === 'bg-BG' ? 'Графиката не може да се покаже' : 'The chart cannot be shown', 'bi-exclamation-triangle');
            return null;
        }
    }

    function hasData(datasets) {
        return (datasets || []).some(function (ds) {
            return (ds.data || []).some(function (v) { return v !== null && v !== undefined && Number(v) !== 0; });
        });
    }

    /**
     * Show an empty state in place of a chart (keeps the container height).
     * @param {*} el canvas / container / id
     * @param {string} message
     */
    function empty(el, message, icon) {
        var canvas = resolve(el);
        if (!canvas) return;
        try { destroy(canvas); } catch (e) { /* a half-built chart */ }
        clearEmpty(canvas);
        canvas.style.display = 'none';
        var box = document.createElement('div');
        box.className = 'dbk-chart-empty';
        box.setAttribute('role', 'status');
        box.innerHTML = '<i class="bi ' + (icon === 'bi-exclamation-triangle' ? icon : 'bi-bar-chart') + '" aria-hidden="true"></i><span></span>';
        box.querySelector('span').textContent = message || (LOCALE === 'bg-BG' ? 'Няма данни за избрания период' : 'No data for the selected period');
        var parent = canvas.parentNode;
        if (getComputedStyle(parent).position === 'static') parent.style.position = 'relative';
        parent.appendChild(box);
    }

    // ------------------------------------------------------------------ shared option builders
    function tooltipLabel(format) {
        return function (ctx) {
            var v = ctx.parsed && typeof ctx.parsed === 'object' ? (ctx.chart.options.indexAxis === 'y' ? ctx.parsed.x : ctx.parsed.y) : ctx.parsed;
            if (ctx.dataset._dbkFormat) return ' ' + (ctx.dataset.label ? ctx.dataset.label + ': ' : '') + ctx.dataset._dbkFormat(v);
            return ' ' + (ctx.dataset.label ? ctx.dataset.label + ': ' : '') + format(v);
        };
    }

    function scales(opts, format) {
        var p = palette();
        var valueAxis = {
            beginAtZero: opts.beginAtZero !== false,
            stacked: !!opts.stacked,
            grid: { color: p.grid, drawBorder: false, drawTicks: false },
            ticks: {
                color: p.muted, padding: 8, maxRotation: 0, autoSkipPadding: 12,
                maxTicksLimit: opts.maxTicks || (opts.horizontal ? 4 : 6),
                callback: function (v, i, ticks) {
                    // compact "1,1 млн." would repeat the same label when the ticks are closer than 0,1 млн. (e.g. one month of cash)
                    var step = ticks && ticks.length > 1 ? Math.abs(ticks[1].value - ticks[0].value) : 0;
                    if (typeof opts.unit !== 'function' && opts.unit !== 'percent' && opts.unit !== 'ratio' && Math.abs(v) >= 1e6 && step && step < 1e5) {
                        return fmt.number(v, 0);
                    }
                    return axisFormatter(opts.unit, !!opts.horizontal)(v);
                }
            },
            title: opts.valueTitle ? { display: true, text: opts.valueTitle, color: p.muted, font: { size: 11 } } : undefined,
            suggestedMin: opts.min,
            suggestedMax: opts.max
        };
        var catAxis = {
            stacked: !!opts.stacked,
            grid: { display: false, drawBorder: false },
            ticks: { color: p.muted, padding: 6, autoSkip: true, maxRotation: 0, autoSkipPadding: 12 }
        };
        if (opts.horizontal) {
            catAxis.ticks.callback = function (value) {
                var l = this.getLabelForValue(value);
                return typeof l === 'string' && l.length > 24 ? l.slice(0, 23) + '…' : l;
            };
            return { x: valueAxis, y: catAxis };
        }
        return { x: catAxis, y: valueAxis };
    }

    function legend(opts, count) {
        var show = opts.legend !== undefined ? opts.legend : count > 1;
        return { display: !!show, position: opts.legendPosition || 'bottom' };
    }

    function merge(target, src) {
        if (!src) return target;
        Object.keys(src).forEach(function (k) {
            var v = src[k];
            if (v && typeof v === 'object' && !Array.isArray(v) && typeof target[k] === 'object' && target[k] !== null && !Array.isArray(target[k])) {
                merge(target[k], v);
            } else {
                target[k] = v;
            }
        });
        return target;
    }

    function prepBar(ds, i, opts) {
        var c = datasetColor(ds, i);
        var out = Object.assign({
            type: 'bar',
            backgroundColor: ds.colors || c,
            hoverBackgroundColor: ds.colors || c,
            borderWidth: 0,
            maxBarThickness: opts.horizontal ? 22 : 28,
            categoryPercentage: 0.72,
            barPercentage: opts.stacked ? 0.9 : 0.86,
            order: 2
        }, ds);
        delete out.role; delete out.color; delete out.colors;
        if (ds.format) { out._dbkFormat = unitFormatter(ds.format); delete out.format; }
        return out;
    }

    function prepLine(ds, i, opts) {
        var c = datasetColor(ds, i);
        var area = ds.area !== undefined ? ds.area : opts.area;
        var out = Object.assign({
            type: 'line',
            borderColor: c,
            backgroundColor: area ? alpha(c, 0.12) : c,
            pointBackgroundColor: c,
            pointBorderColor: palette().surface,
            pointBorderWidth: 2,
            // a single point is invisible as a line - draw it as a dot
            pointRadius: ds.points || (ds.data || []).filter(function (v) { return v !== null && v !== undefined; }).length === 1 ? 4 : 0,
            fill: area ? (ds.fill !== undefined ? ds.fill : 'origin') : false,
            spanGaps: true,
            order: 1
        }, ds);
        if (ds.dashed) out.borderDash = [6, 4];
        delete out.role; delete out.color; delete out.area; delete out.dashed; delete out.points;
        if (ds.format) { out._dbkFormat = unitFormatter(ds.format); delete out.format; }
        return out;
    }

    function base(opts, format, count, type) {
        var o = {
            interaction: { mode: opts.horizontal ? 'nearest' : 'index', intersect: false, axis: opts.horizontal ? 'y' : 'x' },
            plugins: {
                legend: legend(opts, count),
                tooltip: { callbacks: { label: tooltipLabel(format) } }
            },
            scales: scales(opts, format)
        };
        if (opts.horizontal) o.indexAxis = 'y';
        if (opts.onClick) {
            o.onClick = function (evt, elements, chart) {
                if (!elements.length) return;
                var el = elements[0];
                opts.onClick({ index: el.index, datasetIndex: el.datasetIndex, label: chart.data.labels[el.index], value: chart.data.datasets[el.datasetIndex].data[el.index] }, chart);
            };
            o.onHover = function (evt, elements) {
                evt.native && (evt.native.target.style.cursor = elements.length ? 'pointer' : 'default');
            };
        }
        return o;
    }

    // ------------------------------------------------------------------ chart helpers
    /**
     * Bar chart (grouped, stacked or horizontal).
     * @param {*} el canvas / container / id
     * @param {{labels:string[], datasets:Array, stacked?:boolean, horizontal?:boolean, unit?:string|Function,
     *          legend?:boolean, emptyText?:string, onClick?:Function, options?:Object}} opts
     */
    function bar(el, opts) {
        opts = opts || {};
        if (!hasData(opts.datasets) && opts.emptyText !== false) return empty(el, opts.emptyText), null;
        var format = unitFormatter(opts.unit);
        var datasets = (opts.datasets || []).map(function (ds, i) { return prepBar(ds, i, opts); });
        var options = base(opts, format, datasets.length, 'bar');
        return create(el, { type: 'bar', data: { labels: opts.labels || [], datasets: datasets }, options: merge(options, opts.options) });
    }

    /**
     * Line / area chart.
     * @param {*} el
     * @param {{labels:string[], datasets:Array, area?:boolean, unit?:string|Function, beginAtZero?:boolean, options?:Object}} opts
     */
    function line(el, opts) {
        opts = opts || {};
        if (!hasData(opts.datasets) && opts.emptyText !== false) return empty(el, opts.emptyText), null;
        var format = unitFormatter(opts.unit);
        var datasets = (opts.datasets || []).map(function (ds, i) { return prepLine(ds, i, opts); });
        var options = base(opts, format, datasets.length, 'line');
        var single = (opts.labels || []).length <= 1;
        // one period (e.g. "this month"): a zero-based axis and a centred dot instead of a flat ±1 scale at the left edge
        if (opts.beginAtZero === undefined) options.scales.y.beginAtZero = single;
        if (single) options.scales.x.offset = true;
        return create(el, { type: 'line', data: { labels: opts.labels || [], datasets: datasets }, options: merge(options, opts.options) });
    }

    /**
     * Bars + lines on ONE value axis (e.g. revenue & expense bars + profit line).
     * @param {*} el
     * @param {{labels:string[], bars:Array, lines:Array, stacked?:boolean, unit?:string|Function, options?:Object}} opts
     */
    function combo(el, opts) {
        opts = opts || {};
        var all = (opts.bars || []).concat(opts.lines || []);
        if (!hasData(all) && opts.emptyText !== false) return empty(el, opts.emptyText), null;
        var format = unitFormatter(opts.unit);
        var n = 0;
        var datasets = (opts.bars || []).map(function (ds) { return prepBar(ds, n++, opts); })
            .concat((opts.lines || []).map(function (ds) { return prepLine(Object.assign({ points: true }, ds), n++, opts); }));
        var options = base(opts, format, datasets.length, 'bar');
        return create(el, { type: 'bar', data: { labels: opts.labels || [], datasets: datasets }, options: merge(options, opts.options) });
    }

    // Centre text plugin for donuts
    var centerPlugin = {
        id: 'dbkCenter',
        afterDraw: function (chart) {
            var c = chart.options.plugins && chart.options.plugins.dbkCenter;
            if (!c || (!c.value && !c.label)) return;
            var area = chart.chartArea;
            var ctx = chart.ctx;
            var p = palette();
            var x = (area.left + area.right) / 2, y = (area.top + area.bottom) / 2;
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            if (c.value) {
                ctx.font = '600 16px "Helvetica Neue", Helvetica, Arial, sans-serif';
                ctx.fillStyle = isDark() ? '#e6e8ef' : '#1d2633';
                ctx.fillText(c.value, x, c.label ? y - 8 : y);
            }
            if (c.label) {
                ctx.font = '12px "Helvetica Neue", Helvetica, Arial, sans-serif';
                ctx.fillStyle = p.muted;
                ctx.fillText(c.label, x, c.value ? y + 12 : y);
            }
            ctx.restore();
        }
    };

    /**
     * Doughnut (≤ 6–8 parts; fold the rest with topN).
     * @param {*} el
     * @param {{labels:string[], data:number[], colors?:string[], unit?:string|Function, center?:{label?:string, value?:string},
     *          legend?:boolean, cutout?:string, options?:Object}} opts
     */
    function donut(el, opts) {
        opts = opts || {};
        var data = opts.data || [];
        if (!hasData([{ data: data }]) && opts.emptyText !== false) return empty(el, opts.emptyText), null;
        var format = unitFormatter(opts.unit);
        var total = data.reduce(function (s, v) { return s + (Number(v) || 0); }, 0);
        var colors = opts.colors || data.map(function (v, i) { return color(i); });
        var options = {
            cutout: opts.cutout || '68%',
            plugins: {
                legend: { display: opts.legend !== false, position: opts.legendPosition || 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            var v = ctx.parsed;
                            var pct = total ? ' (' + fmt.percent(v / total * 100) + ')' : '';
                            return ' ' + ctx.label + ': ' + format(v) + pct;
                        }
                    }
                },
                dbkCenter: opts.center || null
            }
        };
        if (opts.onClick) {
            options.onClick = function (evt, elements, chart) {
                if (elements.length) opts.onClick({ index: elements[0].index, label: chart.data.labels[elements[0].index], value: data[elements[0].index] }, chart);
            };
        }
        return create(el, {
            type: 'doughnut',
            data: { labels: opts.labels || [], datasets: [{ data: data, backgroundColor: colors, hoverBackgroundColor: colors, borderColor: palette().surface, hoverOffset: 4 }] },
            options: merge(options, opts.options),
            plugins: [centerPlugin]
        });
    }

    /**
     * Sparkline for KPI cards: no axes, no legend, tooltip with value.
     * @param {*} el
     * @param {{data:number[], labels?:string[], role?:string, color?:string, area?:boolean, unit?:string|Function, type?:'line'|'bar'}} opts
     */
    function sparkline(el, opts) {
        opts = opts || {};
        var data = opts.data || [];
        if (!data.length) return null;
        var c = opts.color || (opts.role && role(opts.role)) || role('profit');
        var format = unitFormatter(opts.unit);
        var isBar = opts.type === 'bar';
        var ds = isBar
            ? { data: data, backgroundColor: data.map(function (v) { return v < 0 ? role('expense') : c; }), borderRadius: 2, maxBarThickness: 6 }
            : { data: data, borderColor: c, backgroundColor: alpha(c, 0.12), fill: opts.area !== false ? 'origin' : false, borderWidth: 1.5, pointRadius: 0, pointHoverRadius: 3, tension: 0.35, spanGaps: true };
        return create(el, {
            type: isBar ? 'bar' : 'line',
            data: { labels: opts.labels || data.map(function (v, i) { return i + 1; }), datasets: [ds] },
            options: {
                animation: false,
                interaction: { mode: 'index', intersect: false },
                layout: { padding: 2 },
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: !!opts.labels, displayColors: false, callbacks: { label: function (ctx) { return format(ctx.parsed.y); } } }
                },
                scales: { x: { display: false }, y: { display: false, beginAtZero: isBar } }
            }
        });
    }

    // ------------------------------------------------------------------ re-theme on dark-mode toggle
    function rethemeAll() {
        var Chart = window.Chart;
        if (!Chart || !Chart.instances) return;
        Chart.__dbkThemed = null;
        applyDefaults(Chart);
    }

    window.dbkChart = {
        version: '1.1.0',
        locale: LOCALE,
        get currency() { return currency(); },
        fmt: fmt,
        get colors() { return palette(); },
        color: color,
        role: role,
        alpha: alpha,
        topN: topN,
        create: create,
        destroy: destroy,
        empty: empty,
        bar: bar,
        line: line,
        combo: combo,
        donut: donut,
        sparkline: sparkline,
        retheme: rethemeAll,
        applyTheme: function () { chartLib(); }
    };

    if (window.Chart) {
        try { applyDefaults(window.Chart); } catch (e) { /* Chart.js not ready */ }
    }
})(window);
