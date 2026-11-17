/**
 * Random Phone for Joinchat - public
 *
 * Two behaviours, chosen automatically:
 *
 *  1. GLOBAL + STICKY (main site list, config in `joinchat_random_phone`):
 *     - Sticky: if this browser already has an assigned phone for the current
 *       list signature, always reuse it (same agent forever). Reading it costs
 *       nothing and never consumes a slot from the shared bag.
 *     - Global: otherwise ask the server for the next phone from the shared
 *       shuffle-bag (fair split across all visitors) and remember it.
 *
 *     The server is asked ON INTENT (pointing at / touching a WhatsApp
 *     trigger, opening the chatbox, an inline QR coming into view), so a slot
 *     is only spent by someone who actually reaches for WhatsApp.
 *
 *  2. CLIENT SHUFFLE-BAG (array phones, e.g. Agents addon): pick locally from a
 *     per-browser shuffle-bag so no phone repeats until the list is exhausted.
 *
 * Fail-safe layers, so a visitor is NEVER sent to the default phone just
 * because something went wrong:
 *
 *  a) WhatsApp opens in the same click. If the async answer has not arrived
 *     yet (mobile: touch->click is ~100 ms), a synchronous request is made
 *     inside the click, so window.open stays in the gesture.
 *  b) A per-browser token makes every request of the same visitor return the
 *     same phone: the touch request and the click request spend ONE slot.
 *  c) If the server can't answer (blocked, error, stale cache...), the phone
 *     is picked at random from the list on the visitor side, never the first.
 *  d) Works whatever the script order is (optimization plugins that defer or
 *     reorder scripts): the Joinchat functions are wrapped as soon as they
 *     exist, and the `joinchat:open` event (official Joinchat API) rewrites
 *     the link right before WhatsApp opens if it still has the default phone.
 *  e) admin-ajax is always called on the visitor's own origin (www/no-www,
 *     http/https mismatches can't block it) and the answer is parsed even if
 *     another plugin prints notices around the JSON.
 *
 * Explicit phones (a specific button/page/agent number) are always respected.
 *
 * Stats: when WhatsApp opens towards a phone of the list, a beacon tells the
 * server (phone, device, what opened it, page). It goes out after WhatsApp is
 * already opening, never delays it and can't break it if it fails.
 */
(function (window, document) {
  'use strict';

  var cfg = window.joinchat_random_phone || {};

  var STICKY_KEY = 'joinchat_rp_assigned';
  var TOKEN_KEY = 'joinchat_rp_token';
  var OPENED_KEY = 'joinchat_rp_opened'; // { sig: 1 } this browser already wrote.
  var LOCAL_KEY = 'joinchat_rp_local';   // { sig: phone } picked locally, not yet reported.
  var MAX_STICKY = 10;  // Signatures remembered per browser.
  var MARK = '__jcrp';  // Flag on our wrappers (never wrap twice).

  // Anything that means "this visitor is heading to WhatsApp" (Joinchat 4-6
  // and the WAme 3.x names).
  var TRIGGERS = '.joinchat__button, .joinchat__open, .joinchat_open, .joinchat_app, ' +
    '.joinchat-button__link, .joinchat-button__qr, a[href="#joinchat"], a[href="#whatsapp"], ' +
    '.whatsappme__button, .wame_open';

  // Names of the function that builds the WhatsApp link: 6.x / 3.2-5.x.
  var LINK_FNS = ['get_wa_link', 'whatsapp_link'];

  var assigned = null;    // Phone assigned to this visitor.
  var asking = false;     // An async request is already in flight.
  var attempts = 0;       // Async requests made (capped).
  var sync_tried = false; // The synchronous last-chance request was made.
  var token = null;       // Per-browser token.
  var client_bags = {};   // In-memory cache of client-side bags (array phones).

  // ---- Helpers ----
  function clean(phone) {
    return String(phone === undefined || phone === null ? '' : phone).replace(/\D/g, '').replace(/^0+/, '');
  }

  // Phone list of the current view (cleaned, no duplicates kept in order).
  var phones = [];
  if (Array.isArray(cfg.phones)) {
    for (var k = 0; k < cfg.phones.length; k++) {
      var c = clean(cfg.phones[k]);
      if (c && phones.indexOf(c) === -1) phones.push(c);
    }
  }

  // A phone that may be assigned (if the list is known it must be on it).
  function valid(phone) {
    return typeof phone === 'string' && phone !== '' && (!phones.length || phones.indexOf(phone) !== -1);
  }

  // Joinchat object: `joinchat_obj` (4.x+) or `wame_public` (WAme 3.x).
  function jo() {
    var o = window.joinchat_obj;
    if (o && typeof o === 'object') return o;
    o = window.wame_public;
    return o && typeof o === 'object' ? o : null;
  }

  // The link builder this Joinchat version has (name), or null.
  function link_fn(o) {
    for (var i = 0; i < LINK_FNS.length; i++) {
      if (o && typeof o[LINK_FNS[i]] === 'function') return LINK_FNS[i];
    }
    return null;
  }

  function default_phone() {
    var o = jo();
    return clean((o && o.settings && o.settings.telephone) || cfg.def || phones[0] || '');
  }

  // Global mode is only active when there is a server list for this view.
  function global_mode() {
    return !!cfg.signature;
  }

  // ---- Storage helpers (degrade gracefully on iOS private mode) ----
  function store_get(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }
  function store_set(key, val) {
    try { window.localStorage.setItem(key, val); } catch (e) { /* no-op */ }
  }

  function shuffle(arr) {
    for (var i = arr.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = arr[i]; arr[i] = arr[j]; arr[j] = t;
    }
    return arr;
  }

  // Pick from an array of phones using a per-browser shuffle-bag.
  function pick_from_array(list) {
    if (list.length === 1) return list[0];

    var key = 'joinchat_rp_bag_' + list.join('_');
    var bag = client_bags[key];

    if (!bag || !bag.length) {
      try { bag = JSON.parse(store_get(key) || '[]'); } catch (e) { bag = []; }
      if (!Array.isArray(bag)) bag = [];
      bag = bag.filter(function (p) { return list.indexOf(p) !== -1; });
      if (!bag.length) bag = shuffle(list.slice());
    }

    var phone = bag.shift();
    client_bags[key] = bag;
    store_set(key, JSON.stringify(bag));

    return phone;
  }

  // Only randomize when the default site phone is being used (no explicit
  // phone, or explicitly the same as the default, in any format). Any other
  // phone is a specific button/page number and must be respected.
  function uses_default(phone) {
    if (!global_mode() || Array.isArray(phone)) return false;
    if (!phone) return true;
    var def = default_phone();
    return !!def && clean(phone) === def;
  }

  // Resolve which phone to use for a given call.
  function resolve_phone(phone) {
    // Array phone (Agents / legacy) -> client-side shuffle-bag.
    if (Array.isArray(phone)) {
      return phone.length ? pick_from_array(phone) : phone;
    }

    if (uses_default(phone)) {
      return assigned || phone || default_phone();
    }

    return phone;
  }

  // ---- Sticky: reuse the phone this browser already has (no slot consumed) ----
  // Stored as a map { signature: phone } so a site with more than one list
  // keeps one fixed agent PER list.
  function read_sticky() {
    var raw = store_get(STICKY_KEY);
    if (!raw) return {};

    try {
      var data = JSON.parse(raw);
      if (!data || typeof data !== 'object' || Array.isArray(data)) return {};

      // Shape used up to 4.1.0: { sig: '...', phone: '...' }.
      if (data.sig && data.phone) {
        var old = {};
        old[data.sig] = data.phone;
        return old;
      }

      return data;
    } catch (e) {
      return {}; // Corrupt value, ask the server later.
    }
  }

  // Small { signature: value } maps in localStorage (corrupt = empty, capped).
  function read_map(key) {
    try {
      var data = JSON.parse(store_get(key) || '{}');
      return data && typeof data === 'object' && !Array.isArray(data) ? data : {};
    } catch (e) {
      return {};
    }
  }

  function write_map(key, map) {
    var keys = Object.keys(map);
    if (keys.length > MAX_STICKY) {
      var trimmed = {};
      keys.slice(-MAX_STICKY).forEach(function (k) { trimmed[k] = map[k]; });
      map = trimmed;
    }
    store_set(key, JSON.stringify(map));
  }

  function load_sticky() {
    if (!cfg.signature) return;

    var phone = read_sticky()[cfg.signature];
    if (valid(phone)) assigned = phone;
  }

  function save_sticky(phone) {
    if (!cfg.signature) return;

    var map = read_sticky();

    // Re-insert so the most recently used signature is always last.
    delete map[cfg.signature];
    map[cfg.signature] = phone;

    var keys = Object.keys(map);
    if (keys.length > MAX_STICKY) {
      var trimmed = {};
      keys.slice(-MAX_STICKY).forEach(function (key) { trimmed[key] = map[key]; });
      map = trimmed;
    }

    store_set(STICKY_KEY, JSON.stringify(map));
  }

  function assign(phone) {
    if (assigned || !valid(phone)) return false;
    assigned = phone;
    save_sticky(phone);
    refresh_qr();
    return true;
  }

  // Server unreachable: random phone from the list, never the first one by
  // default. Still sticky, so the visitor keeps that agent.
  function assign_local() {
    if (assigned || phones.length < 2) return;
    if (!assign(phones[Math.floor(Math.random() * phones.length)])) return;

    // Remembered so the stats can show how often the server failed.
    var locals = read_map(LOCAL_KEY);
    locals[cfg.signature] = assigned;
    write_map(LOCAL_KEY, locals);
  }

  // ---- Server requests ----
  function get_token() {
    if (token) return token;
    token = store_get(TOKEN_KEY);
    if (!token || !/^[a-z0-9]{16,64}$/.test(token)) {
      token = '';
      while (token.length < 24) token += Math.random().toString(36).slice(2);
      token = token.slice(0, 24);
      store_set(TOKEN_KEY, token);
    }
    return token;
  }

  // Always call admin-ajax on the visitor's own origin: a www/no-www or
  // http/https difference with the WordPress URL would be blocked as CORS.
  function ajax_url() {
    var url = cfg.url || '';
    try {
      var u = new window.URL(url, window.location.href);
      var origin = window.location.origin;
      if (origin && u.origin !== origin) return origin + u.pathname + u.search;
      return u.href;
    } catch (e) {
      return url;
    }
  }

  function request_body() {
    return 'action=' + encodeURIComponent(cfg.action || 'joinchat_random_phone') +
      '&signature=' + encodeURIComponent(cfg.signature) +
      '&token=' + encodeURIComponent(get_token()) +
      (cfg.nonce ? '&nonce=' + encodeURIComponent(cfg.nonce) : '');
  }

  // Parse the answer even if a plugin printed notices/BOM around the JSON.
  function parse(text) {
    if (typeof text !== 'string') return null;
    try { return JSON.parse(text); } catch (e) { /* try harder */ }

    var start = text.indexOf('{"success"');
    var end = text.lastIndexOf('}');
    if (start === -1 || end < start) return null;

    try { return JSON.parse(text.slice(start, end + 1)); } catch (e) { return null; }
  }

  // Returns true when the answer carried a usable phone.
  function accept(res) {
    if (assigned) return true; // The first answer wins (they are the same anyway).
    if (res && res.success && res.data && res.data.phone) {
      return assign(clean(res.data.phone));
    }
    return false;
  }

  // ---- Global: pop the next phone from the shared server bag (async) ----
  function request_phone() {
    if (assigned || asking || attempts >= 3 || !global_mode()) return;

    if (!cfg.url || typeof window.fetch !== 'function') {
      assign_local();
      return;
    }

    asking = true;
    attempts++;

    window.fetch(ajax_url(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: request_body(),
      credentials: 'same-origin',
    })
      .then(function (r) { return r.text(); })
      .then(function (text) {
        asking = false;
        if (!accept(parse(text))) assign_local();
      })
      .catch(function () {
        asking = false;
        assign_local();
      });
  }

  // Synchronous version, used only at the moment WhatsApp is being opened and
  // no phone has arrived yet. Staying synchronous keeps window.open inside the
  // click gesture (no pop-up blocker). Tried once per page at most.
  function request_phone_sync() {
    if (assigned || sync_tried || !global_mode() || !cfg.url) return;
    if (typeof window.XMLHttpRequest !== 'function') return;

    sync_tried = true;

    try {
      var xhr = new window.XMLHttpRequest();
      xhr.open('POST', ajax_url(), false);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      xhr.send(request_body());
      if (xhr.status >= 200 && xhr.status < 300) accept(parse(xhr.responseText));
    } catch (e) { /* fall back below */ }
  }

  // Make sure the visitor has a phone right now (server first, then local).
  function ensure_assigned() {
    if (!assigned) request_phone_sync();
    if (!assigned) assign_local();
    return assigned;
  }

  // ---- Wrap Joinchat functions (as soon as they exist, never twice) ----
  //
  //  - get_wa_link (6.x) / whatsapp_link (3.2-5.x): builds the link. Wrapped
  //    so links and QR codes carry the visitor's phone.
  //  - open_whatsapp (4.x+): opens WhatsApp. Wrapped to get the phone right in
  //    the click (sync) if it has not arrived yet.
  //  WAme 3.0/3.1 builds the link in a private function: there only the
  //  `whatsappme:open` event (see on_open) can fix it, and it does.
  function wrap_link(o, name) {
    if (typeof o[name] !== 'function' || o[name][MARK]) return;
    var orig = o[name];
    var wrapped = function (phone, message, wa_web) {
      return orig.call(this, resolve_phone(phone), message, wa_web);
    };
    wrapped[MARK] = true;
    o[name] = wrapped;
  }

  function install() {
    bind_jquery();

    var o = jo();
    if (!o) return false;

    for (var i = 0; i < LINK_FNS.length; i++) wrap_link(o, LINK_FNS[i]);

    if (typeof o.open_whatsapp === 'function' && !o.open_whatsapp[MARK]) {
      var orig_open_whatsapp = o.open_whatsapp;
      var open_whatsapp = function (phone, message, trigger) {
        if (!assigned && uses_default(phone)) ensure_assigned();
        return orig_open_whatsapp.call(this, resolve_phone(phone), message, trigger);
      };
      open_whatsapp[MARK] = true;
      o.open_whatsapp = open_whatsapp;
    }

    return !!link_fn(o);
  }

  // Put the assigned phone in a WhatsApp link (wa.me/123 or ...?phone=123).
  function swap_phone(link, phone) {
    return link.replace(/(wa\.me\/|[?&]phone=)\+?\d+/, '$1' + phone);
  }

  // Phone inside a WhatsApp link ('' if none).
  function phone_in(link) {
    var m = /(?:wa\.me\/|[?&]phone=)\+?(\d+)/.exec(link || '');
    return m ? m[1] : '';
  }

  // Last line of defence: every Joinchat fires an "open" event with the link
  // editable right before opening WhatsApp. If the link still carries the
  // default phone (our wrapper was skipped, overwritten, or the version builds
  // the link privately like WAme 3.0/3.1), fix it here.
  //   6.x:      native CustomEvent 'joinchat:open', e.detail = params.
  //   4.4-5.x:  jQuery 'joinchat:open', (e, params) with chat_id.
  //   4.0-4.3:  jQuery 'joinchat:open', (e, {link}).
  //   3.x:      jQuery 'whatsappme:open', (e, {link}).
  function fix_params(d) {
    if (!d || typeof d !== 'object' || typeof d.link !== 'string') return;

    var current = d.chat_id !== undefined && d.chat_id !== null && d.chat_id !== '' ? d.chat_id : phone_in(d.link);
    if (!uses_default(current)) return;

    var phone = ensure_assigned();
    if (!phone || clean(current) === phone) return;

    d.link = swap_phone(d.link, phone);
    if ('chat_id' in d) d.chat_id = phone;
  }

  function on_open(e) {
    fix_params(e && e.detail);
    after_open(e, e && e.detail, function () { return e.defaultPrevented; });
  }

  // ---- Stats: count the open once it is certain WhatsApp opens ----
  // Runs right after the current click (other listeners may still cancel the
  // event or edit the link), so the final phone is the one counted.
  function after_open(e, d, cancelled) {
    if (!cfg.track || !global_mode() || !d) return;
    var later = function () {
      try { if (!cancelled()) report_open(d); } catch (err) { /* never break */ }
    };
    if (typeof window.Promise === 'function') window.Promise.resolve().then(later);
    else window.setTimeout(later, 0);
  }

  var last_report = { phone: '', at: 0 };

  function is_mobile() {
    var o = jo();
    if (o && typeof o.is_mobile === 'boolean') return o.is_mobile;
    return /Mobi|Android|iPhone|iPad|iPod/i.test((window.navigator && window.navigator.userAgent) || '');
  }

  function report_open(d) {
    if (typeof d !== 'object' || typeof d.link !== 'string') return;

    var phone = clean(d.chat_id !== undefined && d.chat_id !== null && d.chat_id !== '' && !Array.isArray(d.chat_id) ? d.chat_id : phone_in(d.link));
    if (!phone || phones.indexOf(phone) === -1) return; // Not a phone of the list.

    // The same open seen twice (native + jQuery event, double tap).
    var now = Date.now();
    if (last_report.phone === phone && now - last_report.at < 2000) return;
    last_report = { phone: phone, at: now };

    // First contact of this browser with this list.
    var opened = read_map(OPENED_KEY);
    var first = !opened[cfg.signature];
    if (first) {
      opened[cfg.signature] = 1;
      write_map(OPENED_KEY, opened);
    }

    // Phone that was picked locally: reported once.
    var locals = read_map(LOCAL_KEY);
    var local = locals[cfg.signature] === phone;
    if (local) {
      delete locals[cfg.signature];
      write_map(LOCAL_KEY, locals);
    }

    var path = '';
    try { path = window.location.pathname || ''; } catch (err) { /* none */ }

    send_beacon(
      'action=' + encodeURIComponent(cfg.track) +
      '&signature=' + encodeURIComponent(cfg.signature) +
      '&token=' + encodeURIComponent(get_token()) +
      '&phone=' + encodeURIComponent(phone) +
      '&src=' + encodeURIComponent(typeof d.trigger === 'string' ? d.trigger : '') +
      '&page=' + encodeURIComponent(path) +
      '&m=' + (is_mobile() ? 1 : 0) +
      '&first=' + (first ? 1 : 0) +
      '&local=' + (local ? 1 : 0)
    );
  }

  // Fire and forget, surviving the page being left right after.
  function send_beacon(body) {
    var url = ajax_url();
    var nav = window.navigator;

    try {
      if (nav && typeof nav.sendBeacon === 'function' && typeof window.URLSearchParams === 'function' &&
        nav.sendBeacon(url, new window.URLSearchParams(body))) return;
    } catch (e) { /* next */ }

    try {
      if (typeof window.fetch === 'function') {
        window.fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body,
          credentials: 'same-origin',
          keepalive: true,
        }).catch(function () { /* lost: only a counter */ });
        return;
      }
    } catch (e) { /* next */ }

    try {
      var xhr = new window.XMLHttpRequest();
      xhr.open('POST', url, true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      xhr.send(body);
    } catch (e) { /* lost: only a counter */ }
  }

  // jQuery events (Joinchat 3.x-5.x trigger them with jQuery, and those never
  // reach native listeners). Bound as soon as jQuery is there.
  var jquery_bound = false;
  function bind_jquery() {
    var $ = window.jQuery;
    if (jquery_bound || typeof $ !== 'function' || !$.fn || typeof $.fn.on !== 'function') return;
    jquery_bound = true;

    $(document).on('joinchat:open whatsappme:open', function (e, params) {
      // A native 6.x event also reaches jQuery handlers, but with no params:
      // on_open already took care of it.
      if (!params) return;
      fix_params(params);
      after_open(e, params, function () { return typeof e.isDefaultPrevented === 'function' && e.isDefaultPrevented(); });
    });

    $(document).on('joinchat:show whatsappme:show', function () {
      install();
      request_phone();
    });
  }

  // ---- Redraw the QR codes with the assigned phone ----
  // Never throws: if anything goes wrong the QR already on screen is kept, so a
  // QR code is always present and always valid.
  // A QR is a <canvas> (QrCreator, 5.x+) or <canvas>/<img> (kjua, 4.4-4.5).
  function qr_node(el) {
    return el && el.querySelector ? el.querySelector('canvas, img') : null;
  }

  function refresh_qr() {
    var o = jo();
    if (!assigned || !o || typeof o.qr !== 'function' || !o.can_qr || o.is_mobile) return;

    install();
    var fn = link_fn(o);
    if (!fn) return;

    var make = function (msg) {
      var qr = o.qr(o[fn](assigned, msg, false));
      return qr && qr.jquery ? qr[0] : qr; // Some versions may hand back jQuery.
    };

    // Chatbox QR (`$` is a querySelector alias in 6.x, jQuery before).
    if (o.$div && typeof o.$ === 'function') {
      try {
        var box = o.$('.joinchat__qr');
        if (box && box.jquery) box = box[0];
        var box_node = qr_node(box);
        if (box_node) box.replaceChild(make(undefined), box_node);
      } catch (e) { /* keep the current QR */ }
    }

    // Inline (block) button QR codes.
    var list = document.querySelectorAll('.joinchat-button__qr');
    for (var i = 0; i < list.length; i++) {
      var el = list[i];

      // A block with its own phone keeps it untouched.
      if (el.dataset && el.dataset.phone && !uses_default(el.dataset.phone)) continue;

      var node = qr_node(el);
      if (!node) continue;

      try {
        el.replaceChild(make(el.dataset ? el.dataset.message : undefined), node);
      } catch (e) { /* keep the current QR */ }
    }
  }

  // ---- Ask for the phone on the first sign of intent ----
  function on_intent(e) {
    install();
    var target = e && e.target;
    var trigger = target && target.closest && target.closest(TRIGGERS);
    if (!trigger) return;

    // A button with its own (non default) phone won't use the shared list:
    // don't spend a slot on it.
    var own = trigger.dataset && trigger.dataset.phone;
    if (own && !uses_default(own)) return;

    request_phone();
  }

  function bind_intent() {
    var opts = { capture: true, passive: true };

    ['pointerdown', 'touchstart', 'mouseover', 'focusin'].forEach(function (evt) {
      document.addEventListener(evt, on_intent, opts);
    });

    // Click in capture phase: runs before Joinchat's own click handler, so the
    // wrappers are in place even if Joinchat loaded after this script.
    document.addEventListener('click', install, true);

    // Chatbox opened: the visitor still has to read it and press the WhatsApp
    // button, so there is plenty of time for the response to arrive.
    document.addEventListener('joinchat:show', function () { install(); request_phone(); });

    // Last line of defence (see on_open).
    document.addEventListener('joinchat:open', on_open);
  }

  function on_ready() {
    // Joinchat may have loaded after this script (deferred / reordered).
    if (install() && assigned) refresh_qr();

    // Inline QR codes: ask before they reach the screen, so the code is already
    // the right one by the time the visitor can scan it.
    var qrs = document.querySelectorAll('.joinchat-button__qr');
    if (!qrs.length || !global_mode() || assigned) return;

    if (!('IntersectionObserver' in window)) {
      request_phone(); // Old browser: keep the previous behaviour.
      return;
    }

    var observer = new window.IntersectionObserver(
      function (entries) {
        for (var i = 0; i < entries.length; i++) {
          if (entries[i].isIntersecting) {
            observer.disconnect();
            request_phone();
            return;
          }
        }
      },
      { rootMargin: '400px' }
    );

    for (var j = 0; j < qrs.length; j++) observer.observe(qrs[j]);
  }

  // ---- Boot ----
  // Runs before Joinchat draws its QR codes (when loaded after it), so a
  // returning visitor already gets their own number in the code.
  load_sticky();
  install();
  bind_intent();

  if (document.readyState !== 'loading') on_ready();
  else document.addEventListener('DOMContentLoaded', on_ready);

  // Late Joinchat (delayed by an optimization plugin): wrap it when it lands.
  window.addEventListener('load', function () {
    if (install() && assigned) refresh_qr();
  });

})(window, document);
