/**
 * Joinchat Random Phone - public
 *
 * Two behaviours, chosen automatically:
 *
 *  1. GLOBAL + STICKY (main site list, config in `joinchat_random_phone`):
 *     - Sticky: if this browser already has an assigned phone for the current
 *       list signature, always reuse it (same agent forever).
 *     - Global: otherwise ask the server for the next phone from the shared
 *       shuffle-bag (fair split across all visitors) and remember it.
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

  var assigned = null;   // Globally assigned phone for the main list.
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

  // ---- Load the global + sticky assignment (main list) ----
  function load_assignment() {
    if (!cfg.signature || !cfg.url) return;

    // Sticky: reuse the phone already assigned to this browser for this list.
    var stored = store_get('joinchat_rp_assigned');
    if (stored) {
      try {
        var data = JSON.parse(stored);
        if (data && data.sig === cfg.signature && data.phone) {
          assigned = data.phone;
          return;
        }
      } catch (e) { /* fall through to fetch */ }
    }

    // Global: pop the next phone from the shared server bag.
    var body = 'action=' + encodeURIComponent(cfg.action || 'joinchat_random_phone') +
      '&signature=' + encodeURIComponent(cfg.signature) +
      (cfg.nonce ? '&nonce=' + encodeURIComponent(cfg.nonce) : '');

    fetch(cfg.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body,
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success && res.data && res.data.phone) {
          assigned = res.data.phone;
          store_set('joinchat_rp_assigned', JSON.stringify({ sig: cfg.signature, phone: assigned }));
        }
      })
      .catch(function () { /* keep default fallback */ });
  }

  // Kick off the assignment as early as possible so it's ready before a click.
  load_assignment();

})(window, document);
