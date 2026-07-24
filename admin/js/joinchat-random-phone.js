/**
 * Joinchat Random Phone - admin (multi phone field)
 *
 * Compatible with Joinchat 6.x, which bundles intl-tel-input v25 (the old
 * `intlTelInputGlobals` API no longer exists). We reuse the global config that
 * Joinchat prepares in `window.joinchat_intl_tel_config` and keep a reference to
 * each instance on its own input element.
 *
 * The "+" (add) and Backspace (remove) handlers are bound FIRST and
 * unconditionally, so they keep working even if intl-tel-input is unavailable.
 */
(function ($, window) {
  'use strict';

  $(function () {

    var base_config = window.joinchat_intl_tel_config || null;
    var intl_tel = typeof intlTelInput === 'function' && !!base_config;

    // Initialize intl-tel-input on a single field (guarded by caller).
    function init_iti(input) {
      var name = $(input).data('name') || $(input).attr('name') || 'joinchat[telephone]';

      var config = $.extend({}, base_config, {
        hiddenInput: function () { return { phone: name }; },
      });

      var iti = intlTelInput(input, config);
      input._jcrp_iti = iti;

      // Reflect the current value into the generated hidden input once ready.
      var current = input.value;
      if (iti && iti.promise && typeof iti.promise.then === 'function') {
        iti.promise.then(function () {
          try {
            if (current) iti.setNumber(current);
            if (iti.ui && iti.ui.hiddenInput) iti.ui.hiddenInput.value = iti.getNumber() || current;
          } catch (e) { /* no-op */ }
        });
      }

      return iti;
    }

    // ---- Bind UI handlers FIRST (always work) ----

    // Add phone number.
    $(document).on('click', '.joinchat_phone__add', function (e) {
      e.preventDefault();

      var $wrap = $(this).parent();
      var $zero = $wrap.find('.joinchat_phone').first();
      var key = Math.floor(Math.random() * 100000);
      var use_data = !!$zero.data('name');
      var attr = use_data ? 'data-name' : 'name';
      var base = ($zero.data('name') || $zero.attr('name') || 'joinchat[telephone][0]');
      // Replace the last [number] with a unique key.
      var name = base.replace(/\[(\d+)\]([^\[\]]*)$/, '[' + key + ']$2');

      $('<br><input class="joinchat_phone" ' + attr + '="' + name + '" value="" type="text" autocomplete="off">').insertBefore(this);

      var input = $wrap.find('.joinchat_phone').last()[0];
      if (intl_tel) { try { init_iti(input); } catch (err) { /* no-op */ } }
      input.autocomplete = 'off_' + Math.random().toString(36).substring(2, 15);
      input.focus();
    });

    // Remove field (Backspace on an empty field).
    $(document).on('keydown', '.joinchat_phones .joinchat_phone', function (e) {
      var key = e.which || e.keyCode;
      var $input = $(this);

      if (key !== 8 || $input.val() !== '') return;
      e.preventDefault();

      if (intl_tel && $input.closest('.iti').length) {
        var $iti = $input.closest('.iti');
        var $prev = $iti.prevAll('.iti').first();

        if ($prev.length) {
          $prev.find('.joinchat_phone').get(0).focus();
          try { if (this._jcrp_iti) this._jcrp_iti.destroy(); } catch (err) { /* no-op */ }
          $iti.prev('br').remove();
          $iti.remove();
        }
      } else {
        var $prevInput = $input.prevAll('.joinchat_phone').first();
        if ($prevInput.length) {
          $prevInput.get(0).focus();
          $input.prev('br').remove();
          $input.remove();
        }
      }
    });

    // Update the hidden input + validation color while typing.
    if (intl_tel) {
      $(document).on('input countrychange', '.joinchat_phones .joinchat_phone', function () {
        var iti = this._jcrp_iti;
        if (!iti) return;

        try {
          var invalid = this.value.trim() && !iti.isValidNumber(true);
          $(this).css('color', invalid ? '#ca4a1f' : '');
          if (iti.ui && iti.ui.hiddenInput) iti.ui.hiddenInput.value = iti.getNumber();
        } catch (e) { /* no-op */ }
      });
    }

    // ---- Then initialize intl-tel-input on existing fields (guarded) ----
    if (intl_tel) {
      $('.joinchat_phone').each(function () {
        try { init_iti(this); } catch (e) { /* no-op */ }
      });
    }

  });
})(jQuery, window);
