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
 *     The server is asked ON INTENT, not on page load: the first time the
 *     visitor points at / touches / hovers a WhatsApp trigger, opens the
 *     chatbox, or scrolls an inline QR code into view. So a slot is only spent
 *     by someone who actually reaches for WhatsApp, never by a bounce or a bot.
 *
 *     The request is fired BEFORE the click completes (pointerdown happens
 *     while the finger is still down, the chatbox opens seconds before the
 *     WhatsApp button is pressed), so `window.open` stays synchronous inside
 *     the click gesture and pop-up blockers never kick in.
 *
 *  2. CLIENT SHUFFLE-BAG (array phones, e.g. Agents addon): pick locally from a
 *     per-browser shuffle-bag so no phone repeats until the list is exhausted.
 *
 * Explicit phones (a specific button/page/agent number) are always respected.
 */
(function (window, document) {
  'use strict';

  var jo = window.joinchat_obj;
  if (typeof jo !== 'object' || !jo) return;

  var cfg = window.joinchat_random_phone || {};

  var STICKY_KEY = 'joinchat_rp_assigned';

  // Anything that means "this visitor is heading to WhatsApp".
  var TRIGGERS = '.joinchat__button, .joinchat__open, .joinchat_open, .joinchat_app, ' +
    '.joinchat-button__link, .joinchat-button__qr, a[href="#joinchat"], a[href="#whatsapp"]';

  var assigned = null;   // Phone assigned to this visitor.
  var asking = false;    // A request is already in flight.
  var attempts = 0;      // Requests made (capped, so a broken network can't loop).
  var client_bags = {};  // In-memory cache of client-side bags (array phones).

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
  function pick_from_array(phones) {
    if (phones.length === 1) return phones[0];

    var key = 'joinchat_rp_bag_' + phones.join('_');
    var bag = client_bags[key];

    if (!bag || !bag.length) {
      try { bag = JSON.parse(store_get(key) || '[]'); } catch (e) { bag = []; }
      if (!bag.length) bag = shuffle(phones.slice());
    }

    var phone = bag.shift();
    client_bags[key] = bag;
    store_set(key, JSON.stringify(bag));

    return phone;
  }

  // Resolve which phone to use for a given call.
  function resolve_phone(phone) {
    // Array phone (Agents / legacy) -> client-side shuffle-bag.
    if (Array.isArray(phone)) {
      return phone.length ? pick_from_array(phone) : phone;
    }

    var def = jo.settings && jo.settings.telephone;

    // Only randomize when the default site phone is being used (no explicit
    // phone, or explicitly the same as the default). Any other phone is a
    // specific button/page number and must be respected.
    if (!phone || phone === def) {
      return assigned || def || phone;
    }

    return phone;
  }

  // ---- Wrap Joinchat link builders ----
  var orig_get_wa_link = jo.get_wa_link;
  jo.get_wa_link = function (phone, message, wa_web) {
    return orig_get_wa_link.call(this, resolve_phone(phone), message, wa_web);
  };

  var orig_open_whatsapp = jo.open_whatsapp;
  jo.open_whatsapp = function (phone, message, trigger) {
    return orig_open_whatsapp.call(this, resolve_phone(phone), message, trigger);
  };

  // ---- Sticky: reuse the phone this browser already has (no slot consumed) ----
  function load_sticky() {
    if (!cfg.signature) return;

    var stored = store_get(STICKY_KEY);
    if (!stored) return;

    try {
      var data = JSON.parse(stored);
      if (data && data.sig === cfg.signature && data.phone) assigned = data.phone;
    } catch (e) { /* corrupt value, ask the server later */ }
  }

  // ---- Global: pop the next phone from the shared server bag ----
  function request_phone() {
    if (assigned || asking || attempts >= 3) return;
    if (!cfg.signature || !cfg.url || typeof window.fetch !== 'function') return;

    asking = true;
    attempts++;

    var body = 'action=' + encodeURIComponent(cfg.action || 'joinchat_random_phone') +
      '&signature=' + encodeURIComponent(cfg.signature) +
      (cfg.nonce ? '&nonce=' + encodeURIComponent(cfg.nonce) : '');

    window.fetch(cfg.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body,
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        asking = false;
        if (res && res.success && res.data && res.data.phone) {
          assigned = res.data.phone;
          store_set(STICKY_KEY, JSON.stringify({ sig: cfg.signature, phone: assigned }));
          refresh_qr();
        }
      })
      .catch(function () { asking = false; }); // Keep the default fallback.
  }

  // ---- Redraw the QR codes with the assigned phone ----
  // Never throws: if anything goes wrong the QR already on screen is kept, so a
  // QR code is always present and always valid.
  function refresh_qr() {
    if (typeof jo.qr !== 'function' || !jo.can_qr || jo.is_mobile) return;

    // Chatbox QR.
    if (jo.$div) {
      var box = jo.$('.joinchat__qr');
      var box_canvas = box && box.querySelector('canvas');
      if (box_canvas) {
        try {
          box.replaceChild(jo.qr(jo.get_wa_link(undefined, undefined, false)), box_canvas);
        } catch (e) { /* keep the current QR */ }
      }
    }

    // Inline (block) button QR codes.
    var list = document.querySelectorAll('.joinchat-button__qr');
    for (var i = 0; i < list.length; i++) {
      var el = list[i];

      // A block with its own phone keeps it untouched.
      if (el.dataset && el.dataset.phone) continue;

      var canvas = el.querySelector('canvas');
      if (!canvas) continue;

      try {
        var msg = el.dataset ? el.dataset.message : undefined;
        el.replaceChild(jo.qr(jo.get_wa_link(undefined, msg, false)), canvas);
      } catch (e) { /* keep the current QR */ }
    }
  }

  // ---- Ask for the phone on the first sign of intent ----
  function bind_intent() {
    var opts = { capture: true, passive: true };

    ['pointerdown', 'touchstart', 'mouseover'].forEach(function (evt) {
      document.addEventListener(evt, function (e) {
        var target = e.target;
        if (target && target.closest && target.closest(TRIGGERS)) request_phone();
      }, opts);
    });

    // Chatbox opened: the visitor still has to read it and press the WhatsApp
    // button, so there is plenty of time for the response to arrive.
    document.addEventListener('joinchat:show', request_phone);

    // Last resort, in case something opens WhatsApp with no previous signal.
    document.addEventListener('joinchat:open', request_phone);

    // Inline QR codes: ask before they reach the screen, so the code is already
    // the right one by the time the visitor can scan it.
    var qrs = document.querySelectorAll('.joinchat-button__qr');
    if (!qrs.length) return;

    if (!('IntersectionObserver' in window)) {
      request_phone(); // Old browser: keep the previous behaviour.
      return;
    }

    var observer = new IntersectionObserver(
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
  // Runs before Joinchat draws its QR codes, so a returning visitor already
  // gets their own number in the code with no redraw at all.
  load_sticky();

  if (document.readyState !== 'loading') bind_intent();
  else document.addEventListener('DOMContentLoaded', bind_intent);

})(window, document);
