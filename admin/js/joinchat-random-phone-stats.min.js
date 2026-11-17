/**
 * Random Phone for Joinchat - contact split panel
 *
 * Daily chart (plain SVG, no libraries), sortable table, custom range toggle
 * and phone formatting (with Joinchat's intl-tel-input when it is around).
 * Everything is progressive: without this script the page still shows all
 * the numbers.
 */
(function (window, document) {
  'use strict';

  var SVG = 'http://www.w3.org/2000/svg';

  function el(name, attrs, parent) {
    var node = document.createElementNS(SVG, name);
    for (var k in attrs) if (Object.prototype.hasOwnProperty.call(attrs, k)) node.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(node);
    return node;
  }

  // ------------------------------------------------------------- phones.
  function formatter() {
    var iti = window.intlTelInput;
    var utils = (iti && iti.utils) || window.intlTelInputUtils;
    if (!utils || typeof utils.formatNumber !== 'function') return null;
    // v29 names the formats on intlTelInput, older versions on the utils.
    var formats = (iti && iti.NUMBER_FORMAT) || utils.numberFormat || {};
    var intl = formats.INTERNATIONAL !== undefined ? formats.INTERNATIONAL : 1;
    return function (digits) {
      try {
        var out = utils.formatNumber('+' + digits, null, intl);
        return out && out.charAt(0) === '+' ? out : '';
      } catch (e) {
        return '';
      }
    };
  }

  function format_phones() {
    var fmt = formatter();
    if (!fmt) return;
    var list = document.querySelectorAll('.jcrp-stats .jcrp-phone[data-phone]');
    for (var i = 0; i < list.length; i++) {
      var pretty = fmt(list[i].getAttribute('data-phone'));
      if (pretty) list[i].textContent = pretty;
    }
  }

  // -------------------------------------------------------------- chart.
  // Clean ticks: 1, 2, 5 x 10^n steps, four or five of them.
  function nice_max(max) {
    if (max <= 4) return { max: 4, step: 1 };
    var raw = max / 4;
    var pow = Math.pow(10, Math.floor(Math.log10(raw)));
    var steps = [1, 2, 2.5, 5, 10];
    var step = pow;
    for (var i = 0; i < steps.length; i++) {
      if (steps[i] * pow >= raw) { step = steps[i] * pow; break; }
    }
    step = Math.max(1, Math.round(step));
    return { max: Math.ceil(max / step) * step, step: step };
  }

  function chart(box) {
    var data;
    try { data = JSON.parse(box.getAttribute('data-chart')); } catch (e) { return; }
    if (!data || !data.days || data.days.length < 2) return;

    var days = data.days;
    var nf = new Intl.NumberFormat(data.locale || undefined);
    var df_short = new Intl.DateTimeFormat(data.locale || undefined, { day: 'numeric', month: 'short' });
    var df_long = new Intl.DateTimeFormat(data.locale || undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    var to_date = function (ymd) { var p = ymd.split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12); };

    var tip = document.createElement('div');
    tip.className = 'jcrp-tip';
    tip.hidden = true;

    function draw() {
      box.innerHTML = '';
      box.appendChild(tip);

      var W = Math.max(280, box.clientWidth);
      var H = box.clientHeight || 260;
      var pad = { t: 10, r: 12, b: 26, l: 0 };

      var peak = 0;
      days.forEach(function (d) { peak = Math.max(peak, d[1], d[2]); });
      var scale = nice_max(peak);

      // Left padding from the widest tick label.
      var widest = nf.format(scale.max).length;
      pad.l = 12 + widest * 7;

      var iw = W - pad.l - pad.r;
      var ih = H - pad.t - pad.b;
      var x = function (i) { return pad.l + (days.length === 1 ? iw / 2 : (i / (days.length - 1)) * iw); };
      var y = function (v) { return pad.t + ih - (v / scale.max) * ih; };

      var svg = el('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, 'aria-hidden': 'true', focusable: 'false' }, box);

      // Grid + y ticks.
      var grid = el('g', { 'class': 'grid' }, svg);
      for (var v = 0; v <= scale.max; v += scale.step) {
        var gy = Math.round(y(v)) + 0.5;
        el('line', { x1: pad.l, x2: W - pad.r, y1: gy, y2: gy, 'class': v === 0 ? 'base' : '' }, grid);
        var t = el('text', { x: pad.l - 10, y: gy + 4, 'text-anchor': 'end', 'class': 'tick' }, svg);
        t.textContent = nf.format(v);
      }

      // X labels: evenly spaced, about one every 90px, first and last always.
      var n = Math.max(2, Math.min(days.length, Math.floor(iw / 90)));
      var seen = {};
      for (var k = 0; k < n; k++) {
        var i = Math.round(k * (days.length - 1) / (n - 1));
        if (seen[i]) continue;
        seen[i] = true;
        var anchor = k === 0 ? 'start' : (k === n - 1 ? 'end' : 'middle');
        var xt = el('text', { x: x(i), y: H - 6, 'text-anchor': anchor, 'class': 'tick' }, svg);
        xt.textContent = df_short.format(to_date(days[i][0]));
      }

      // Series: assigned (with a light wash) and opens.
      var path = function (col) {
        return days.map(function (d, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(d[col]).toFixed(1); }).join(' ');
      };
      el('path', { d: path(1) + ' L' + x(days.length - 1).toFixed(1) + ' ' + y(0) + ' L' + x(0).toFixed(1) + ' ' + y(0) + ' Z', 'class': 'area' }, svg);
      el('path', { d: path(1), 'class': 'line line--assign' }, svg);
      el('path', { d: path(2), 'class': 'line line--open' }, svg);

      // Hover layer.
      var cross = el('line', { y1: pad.t, y2: pad.t + ih, 'class': 'cross', visibility: 'hidden' }, svg);
      var dot_a = el('circle', { r: 4.5, 'class': 'dot dot--assign', visibility: 'hidden' }, svg);
      var dot_o = el('circle', { r: 4.5, 'class': 'dot dot--open', visibility: 'hidden' }, svg);
      var hit = el('rect', { x: pad.l, y: 0, width: iw, height: H, fill: 'transparent', tabindex: '0' }, svg);

      var show = function (i) {
        var d = days[i];
        var px = x(i);
        cross.setAttribute('x1', px); cross.setAttribute('x2', px);
        dot_a.setAttribute('cx', px); dot_a.setAttribute('cy', y(d[1]));
        dot_o.setAttribute('cx', px); dot_o.setAttribute('cy', y(d[2]));
        [cross, dot_a, dot_o].forEach(function (n) { n.setAttribute('visibility', 'visible'); });

        tip.innerHTML = '';
        var head = document.createElement('div');
        head.className = 'jcrp-tip__date';
        head.textContent = df_long.format(to_date(d[0]));
        tip.appendChild(head);
        [['assign', 1], ['open', 2]].forEach(function (s) {
          var row = document.createElement('div');
          row.className = 'jcrp-tip__row';
          var key = document.createElement('span');
          key.className = 'jcrp-key jcrp-key--' + s[0];
          var label = document.createElement('span');
          label.textContent = data.labels[s[0]];
          var val = document.createElement('b');
          val.textContent = nf.format(d[s[1]]);
          row.appendChild(key); row.appendChild(label); row.appendChild(val);
          tip.appendChild(row);
        });
        tip.hidden = false;

        var tw = tip.offsetWidth;
        var left = px + 14;
        if (left + tw > W) left = px - tw - 14;
        tip.style.left = Math.max(0, left) + 'px';
        tip.style.top = Math.max(0, Math.min(y(Math.max(d[1], d[2])) - 20, H - tip.offsetHeight)) + 'px';
      };

      var hide = function () {
        tip.hidden = true;
        [cross, dot_a, dot_o].forEach(function (n) { n.setAttribute('visibility', 'hidden'); });
      };

      var index_at = function (clientX) {
        var r = svg.getBoundingClientRect();
        var rel = (clientX - r.left - pad.l) / iw;
        return Math.max(0, Math.min(days.length - 1, Math.round(rel * (days.length - 1))));
      };

      var current = days.length - 1;
      hit.addEventListener('mousemove', function (e) { current = index_at(e.clientX); show(current); });
      hit.addEventListener('mouseleave', hide);
      hit.addEventListener('touchstart', function (e) { if (e.touches[0]) { current = index_at(e.touches[0].clientX); show(current); } }, { passive: true });
      hit.addEventListener('focus', function () { show(current); });
      hit.addEventListener('blur', hide);
      hit.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
          e.preventDefault();
          current = Math.max(0, Math.min(days.length - 1, current + (e.key === 'ArrowLeft' ? -1 : 1)));
          show(current);
        }
      });
    }

    draw();

    var width = box.clientWidth;
    var redraw = function () {
      if (box.clientWidth !== width) { width = box.clientWidth; draw(); }
    };
    if (typeof window.ResizeObserver === 'function') new window.ResizeObserver(redraw).observe(box);
    else window.addEventListener('resize', redraw);
  }

  // -------------------------------------------------------------- table.
  function sortable(table) {
    var tbody = table.tBodies[0];
    var heads = table.querySelectorAll('th');

    Array.prototype.forEach.call(heads, function (th, col) {
      var btn = th.querySelector('button');
      if (!btn) return;

      btn.addEventListener('click', function () {
        var type = btn.getAttribute('data-type');
        var state = th.getAttribute('aria-sort');
        // Numbers start high to low, text A to Z; a third click restores.
        var next = state === 'none'
          ? (type === 'num' ? 'descending' : 'ascending')
          : (state === (type === 'num' ? 'descending' : 'ascending') ? (type === 'num' ? 'ascending' : 'descending') : 'none');

        Array.prototype.forEach.call(heads, function (h) { h.setAttribute('aria-sort', 'none'); });
        th.setAttribute('aria-sort', next);

        var rows = Array.prototype.slice.call(tbody.rows);
        rows.sort(function (a, b) {
          if (next === 'none') return a.getAttribute('data-order') - b.getAttribute('data-order');
          var va = a.cells[col].getAttribute('data-value');
          var vb = b.cells[col].getAttribute('data-value');
          var cmp = type === 'num' ? parseFloat(va) - parseFloat(vb) : String(va).localeCompare(String(vb));
          if (!cmp) cmp = a.getAttribute('data-order') - b.getAttribute('data-order');
          return next === 'ascending' ? cmp : -cmp;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
      });
    });
  }

  // ---------------------------------------------------------- the rest.
  function custom_range() {
    var btn = document.querySelector('.jcrp-range button[aria-controls]');
    var form = btn && document.getElementById(btn.getAttribute('aria-controls'));
    if (!form) return;
    btn.addEventListener('click', function () {
      form.hidden = !form.hidden;
      btn.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
      if (!form.hidden) {
        var first = form.querySelector('input[type="date"]');
        if (first) first.focus();
      }
    });
  }

  function confirm_reset() {
    var form = document.querySelector('.jcrp-reset[data-confirm]');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
    });
  }

  function init() {
    if (!document.querySelector('.jcrp-stats')) return;
    format_phones();
    Array.prototype.forEach.call(document.querySelectorAll('.jcrp-chart[data-chart]'), chart);
    Array.prototype.forEach.call(document.querySelectorAll('.jcrp-table[data-sortable]'), sortable);
    custom_range();
    confirm_reset();
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);

})(window, document);
