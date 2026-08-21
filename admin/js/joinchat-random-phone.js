/**
 * Random Phone for Joinchat - admin (multi phone field)
 *
 * The value that gets SAVED is exactly what it has always been: a plain text
 * input with a real `name` holding the full international number in digits.
 * Nothing here owns the value or writes it from a hidden field, so a failure in
 * any helper can never erase a phone number.
 *
 * On top of that it adds, all of it display-only or opt-in:
 *
 *  - A flag per row, taken from the country code you type (1 node per row: the
 *    full country picker builds ~1000 nodes per instance, which with a list of
 *    70 numbers would bring the page to a crawl).
 *  - A readable version of the number next to the field (+57 300 1234567).
 *  - A default country: numbers typed without a country code get it added on
 *    blur, using libphonenumber to tell a national number from an international
 *    one, so an already complete number is never touched.
 *  - "x N" badges on repeated numbers (slots) and a summary of the split.
 *  - Paste a whole list at once.
 *
 * Everything degrades: with no intl-tel-input library there are no flags, no
 * formatting and no country picker, but adding, removing and saving still work.
 */
(function ($) {
  'use strict';

  var STORE_KEY = 'jcrp_default_country';

  // Localized strings, with English fallbacks so a missing wp_localize_script
  // can never break the field.
  var L = $.extend({
    slot: 'slot',
    slots: 'slots',
    number: 'number',
    numbers: 'numbers',
    most: 'the most loaded gets %1$s slots (%2$s%)',
    even: 'even split, %s% each',
    remove: 'Remove this phone',
    no_country: '— none —',
  }, window.jcrp_l10n || {});

  var iti = window.intlTelInput;
  var utils = iti && iti.utils;
  var countries = [];       // [{iso2, dialCode, name, priority}]
  var by_dial = [];         // Same, sorted by dial code length (longest first).

  // ---------------------------------------------------------------- helpers --

  function l10n(key, fallback) {
    var t = window.intl_tel_l10n;
    return (t && typeof t[key] === 'string' && t[key]) || fallback;
  }

  function digits(value) {
    return String(value == null ? '' : value).replace(/\D/g, '');
  }

  function load_countries() {
    if (!iti || typeof iti.getCountryData !== 'function') return;

    try {
      countries = (iti.getCountryData() || []).map(function (c) {
        return {
          iso2: c.iso2,
          dialCode: String(c.dialCode || ''),
          priority: c.priority || 0,
          name: l10n(c.iso2, c.name),
        };
      }).filter(function (c) { return c.iso2 && c.dialCode; });
    } catch (e) {
      countries = [];
      return;
    }

    by_dial = countries.slice().sort(function (a, b) {
      if (b.dialCode.length !== a.dialCode.length) return b.dialCode.length - a.dialCode.length;
      return a.priority - b.priority;
    });
  }

  // Country of an international number, by longest matching dial code.
  function country_of(value) {
    var num = digits(value);
    if (!num) return null;

    for (var i = 0; i < by_dial.length; i++) {
      if (num.indexOf(by_dial[i].dialCode) === 0) return by_dial[i];
    }
    return null;
  }

  function country_by_iso(iso2) {
    for (var i = 0; i < countries.length; i++) {
      if (countries[i].iso2 === iso2) return countries[i];
    }
    return null;
  }

  // Is this already a complete international number?
  function is_international(num) {
    if (!utils) return true; // Without libphonenumber, never touch what is typed.
    try {
      return !!utils.isPossibleNumber('+' + num, null);
    } catch (e) {
      return true;
    }
  }

  // Is this a valid national number for that country?
  function is_national(num, iso2) {
    if (!utils || !iso2) return false;
    try {
      return !!utils.isPossibleNumber(num, iso2.toUpperCase());
    } catch (e) {
      return false;
    }
  }

  function pretty(num) {
    var country = country_of(num);
    if (!utils || !country) return '';
    try {
      return utils.formatNumber('+' + num, country.iso2.toUpperCase(), utils.numberFormat.INTERNATIONAL) || '';
    } catch (e) {
      return '';
    }
  }

  function store_get(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }
  function store_set(key, val) {
    try { window.localStorage.setItem(key, val); } catch (e) { /* no-op */ }
  }

  // ------------------------------------------------------------- the widget --

  function $rows($box) {
    return $box.find('.jcrp__rows .jcrp__row');
  }

  // Renumber inputs so their names stay 0..n-1 with no gaps.
  function reindex($box) {
    var tpl = $box.data('name') || 'joinchat[telephone][%d]';

    $rows($box).each(function (index) {
      var $row = $(this);
      $row.find('.jcrp__index').text(index + 1);
      $row.find('.jcrp__input').attr('name', tpl.replace('%d', index));
    });
  }

  // Flag, readable number and "x N" badge for every row, plus the summary.
  function refresh($box) {
    var counts = {};
    var total = 0;

    $rows($box).each(function () {
      var num = digits($(this).find('.jcrp__input').val());
      if (!num) return;
      counts[num] = (counts[num] || 0) + 1;
      total++;
    });

    $rows($box).each(function () {
      var $row = $(this);
      var num = digits($row.find('.jcrp__input').val());
      var country = country_of(num);

      var $flag = $row.find('.jcrp__flag');
      $flag.attr('class', 'jcrp__flag' + (country ? ' iti__flag iti__' + country.iso2 : ''))
        .attr('title', country ? country.name : '');

      $row.find('.jcrp__pretty').text(num ? pretty(num) : '');

      var weight = counts[num] || 0;
      $row.find('.jcrp__weight').text(weight > 1 ? '×' + weight : '');
      $row.toggleClass('jcrp__row--dup', weight > 1);
      $row.toggleClass('jcrp__row--bad', !!num && !is_international(num));
    });

    var uniques = Object.keys(counts).length;
    var $summary = $box.find('.jcrp__summary');

    if (!total) {
      $summary.text('');
      return;
    }

    var max = 0;
    Object.keys(counts).forEach(function (k) { max = Math.max(max, counts[k]); });

    var parts = [
      total + ' ' + (total === 1 ? L.slot : L.slots),
      uniques + ' ' + (uniques === 1 ? L.number : L.numbers),
    ];
    if (max > 1) {
      parts.push(L.most.replace('%1$s', max).replace('%2$s', (max * 100 / total).toFixed(1)));
    } else {
      parts.push(L.even.replace('%s', (100 / total).toFixed(1)));
    }

    $summary.text(parts.join(' · '));
  }

  // Add the default country code to a number typed without one.
  //
  // Every guard here points the same way: when in doubt, leave what was typed
  // alone. Leaving a number untouched is visible and the user fixes it; silently
  // doubling a country code is not, and would send contacts to a dead number.
  function with_country_code(num, iso) {
    if (!num || !iso) return num;

    var country = country_by_iso(iso);
    if (!country) return num;

    // Already starts with this country code: never add it twice.
    if (num.indexOf(country.dialCode) === 0) return num;

    // Already a complete international number of some other country.
    if (is_international(num)) return num;

    // Only a plausible national number of the chosen country qualifies.
    if (!is_national(num, iso)) return num;

    var candidate = country.dialCode + num.replace(/^0+/, '');

    // And only if the result is itself a sane international number.
    return is_international(candidate) ? candidate : num;
  }

  function complete($box, $input) {
    var num = digits($input.val());
    if (!num) {
      $input.val('');
      return;
    }

    $input.val(with_country_code(num, $box.find('.jcrp__country').val()));
  }

  function add_row($box, value) {
    var tpl = $box.data('name') || 'joinchat[telephone][%d]';
    var index = $rows($box).length;

    var $row = $(
      '<div class="jcrp__row">' +
      '<span class="jcrp__index"></span>' +
      '<span class="jcrp__flag" aria-hidden="true"></span>' +
      '<input class="joinchat_phone jcrp__input" type="text" autocomplete="off_jcrp">' +
      '<span class="jcrp__pretty" aria-hidden="true"></span>' +
      '<span class="jcrp__weight"></span>' +
      '<button type="button" class="jcrp__del" title="' + L.remove + '" aria-label="' + L.remove + '">×</button>' +
      '</div>'
    );

    $row.find('.jcrp__input').attr('name', tpl.replace('%d', index)).val(value || '');
    $box.find('.jcrp__rows').append($row);

    return $row;
  }

  // ------------------------------------------------------- country selector --

  function build_country_select($box) {
    var $select = $box.find('.jcrp__country');
    if (!$select.length) return;

    if (!countries.length) {
      $select.closest('.jcrp__country-row').hide();
      return;
    }

    var sorted = countries.slice().sort(function (a, b) {
      return a.name.localeCompare(b.name);
    });

    var html = '<option value="">' + L.no_country + '</option>';
    sorted.forEach(function (c) {
      html += '<option value="' + c.iso2 + '">' + c.name + ' (+' + c.dialCode + ')</option>';
    });
    $select.html(html);

    // Guess it: first number already saved, then a previous choice, then the
    // country Joinchat already looked up for its own phone field.
    var guess = '';
    var first = country_of($box.find('.jcrp__input').first().val());
    if (first) {
      guess = first.iso2;
    } else {
      guess = store_get(STORE_KEY) || '';
      if (!guess) {
        try {
          var jc = JSON.parse(window.localStorage.joinchat_country_code || '{}');
          if (jc && jc.code) guess = String(jc.code).toLowerCase();
        } catch (e) { /* no-op */ }
      }
    }

    if (guess && country_by_iso(guess)) $select.val(guess);
  }

  // ------------------------------------------------------------------ paste --

  function parse_list(text) {
    return String(text || '')
      .split(/[\s,;|]+/)
      .map(function (chunk) { return digits(chunk); })
      .filter(function (num) { return num.length > 4; });
  }

  // ------------------------------------------------------------------- init --

  function init($box) {
    if ($box.data('jcrp-ready')) return;
    $box.data('jcrp-ready', true);

    build_country_select($box);
    reindex($box);
    refresh($box);
  }

  $(function () {

    load_countries();

    $('.jcrp').each(function () { init($(this)); });

    // Joinchat repaints parts of the admin (agents, block editor sidebar).
    $(document).on('joinchat:agents:added joinchat:refresh', function () {
      $('.jcrp').each(function () { init($(this)); });
    });

    $(document).on('click', '.jcrp__add', function () {
      var $box = $(this).closest('.jcrp');
      var $row = add_row($box, '');
      reindex($box);
      refresh($box);
      $row.find('.jcrp__input').trigger('focus');
    });

    $(document).on('click', '.jcrp__del', function () {
      var $box = $(this).closest('.jcrp');
      var $row = $(this).closest('.jcrp__row');

      // Never leave the field with no rows at all.
      if ($rows($box).length <= 1) {
        $row.find('.jcrp__input').val('');
      } else {
        $row.remove();
      }

      reindex($box);
      refresh($box);
    });

    $(document).on('input', '.jcrp__input', function () {
      refresh($(this).closest('.jcrp'));
    });

    $(document).on('blur', '.jcrp__input', function () {
      var $box = $(this).closest('.jcrp');
      complete($box, $(this));
      refresh($box);
    });

    // Enter adds a new row instead of submitting the whole settings form.
    $(document).on('keydown', '.jcrp__input', function (e) {
      var key = e.which || e.keyCode;
      var $box = $(this).closest('.jcrp');

      if (key === 13) {
        e.preventDefault();
        complete($box, $(this));
        var $row = add_row($box, '');
        reindex($box);
        refresh($box);
        $row.find('.jcrp__input').trigger('focus');
        return;
      }

      // Backspace on an empty field removes it.
      if (key === 8 && $(this).val() === '' && $rows($box).length > 1) {
        e.preventDefault();
        var $prev = $(this).closest('.jcrp__row').prev('.jcrp__row');
        $(this).closest('.jcrp__row').remove();
        reindex($box);
        refresh($box);
        if ($prev.length) $prev.find('.jcrp__input').trigger('focus');
      }
    });

    $(document).on('change', '.jcrp__country', function () {
      store_set(STORE_KEY, $(this).val() || '');
    });

    // ---- Paste a whole list ----
    $(document).on('click', '.jcrp__paste-open', function () {
      var $box = $(this).closest('.jcrp');
      $box.find('.jcrp__paste').prop('hidden', false).find('.jcrp__paste-text').val('').trigger('focus');
    });

    $(document).on('click', '.jcrp__paste-cancel', function () {
      $(this).closest('.jcrp__paste').prop('hidden', true);
    });

    $(document).on('click', '.jcrp__paste-add', function () {
      var $box = $(this).closest('.jcrp');
      var $panel = $box.find('.jcrp__paste');
      var numbers = parse_list($panel.find('.jcrp__paste-text').val());

      if (!numbers.length) {
        $panel.prop('hidden', true);
        return;
      }

      if ($panel.find('.jcrp__paste-replace input').prop('checked')) {
        $box.find('.jcrp__rows').empty();
      } else {
        // Drop trailing empty rows so the pasted list does not leave holes.
        $rows($box).each(function () {
          if (!digits($(this).find('.jcrp__input').val())) $(this).remove();
        });
      }

      var iso = $box.find('.jcrp__country').val();

      numbers.forEach(function (num) {
        add_row($box, with_country_code(num, iso));
      });

      if (!$rows($box).length) add_row($box, '');

      $panel.prop('hidden', true);
      reindex($box);
      refresh($box);
    });

  });
})(jQuery);
