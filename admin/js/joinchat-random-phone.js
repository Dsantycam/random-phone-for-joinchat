(function ($) {
  'use strict';

  $(function () {
    var intl_tel = typeof (intlTelInput) === 'function';

    if (intl_tel) {
      var country_request = JSON.parse(localStorage.joinchat_country_code || '{}');
      var country_code = (country_request.code && country_request.date == new Date().toDateString()) ? country_request.code : false;
      var iti_settings = window.joinchatIntlTel || {
        separateDialCode: true,
        initialCountry: 'auto',
        preferredCountries: [country_code || ''],
        geoIpLookup: function (callback) {
          if (country_code) {
            callback(country_code);
          } else {
            $.getJSON('https://ipinfo.io').always(function (resp) {
              var countryCode = (resp && resp.country) ? resp.country : '';
              localStorage.joinchat_country_code = JSON.stringify({ code: countryCode, date: new Date().toDateString() });
              callback(countryCode);
            });
          }
        },
        customPlaceholder: function (placeholder) { return intlTelConf.placeholder + ' ' + placeholder; },
        utilsScript: intlTelConf.utils_js,
      };

      // Enable intlTelInput
      $('.joinchat_phone').each(function () {
        var iti = intlTelInput(this, $.extend({}, iti_settings, { hiddenInput: $(this).data('name') }));
        iti.hiddenInput.value = this.value;
        this.autocomplete = 'off_' + Math.random().toString(36).substring(2, 15);
      });

      // Enable input events delegated to wrapper (if not is Support Agents form)
      if ($('#joinchat_agents').length == 0) {
        $('.joinchat_phones')
          .on('input', '.joinchat_phone', function () {
            var $this = $(this);
            var iti = intlTelInputGlobals.getInstance(this);

            $this.css('color', $this.val().trim() && !iti.isValidNumber() ? '#ca4a1f' : '');
            iti.hiddenInput.value = iti.getNumber();
          })
          .on('blur', '.joinchat_phone', function () {
            var iti = intlTelInputGlobals.getInstance(this);
            iti.setNumber(iti.getNumber());
          });
      }
    }

    // Add phone number
    $(document).on('click', '.joinchat_phone__add', function (e) {
      e.preventDefault();
      var $wrap = $(this).parent();
      var $zero = $wrap.find('.joinchat_phone:first');
      var key = Math.floor(Math.random() * 100000);
      var name = ($zero.data('name') || $zero.attr('name')).replace(/0([^0]*)$/, key + '$1');

      $('<br><input class="joinchat_phone" ' + (intl_tel ? 'data-' : '') + 'name="' + name + '" value="" type="text">').insertBefore(this);

      var input = $wrap.find('.joinchat_phone:last')[0];
      if (intl_tel) {
        intlTelInput(input, $.extend({}, iti_settings, { hiddenInput: name }));
      }
      input.autocomplete = 'off_' + Math.random().toString(36).substring(2, 15);
      input.focus();
    });

    // Remove phone field (on Backspace key press on empty field)
    $('.joinchat_phones').on('keydown', '.joinchat_phone', function (e) {
      var key = e.which || e.keyCode;
      var $input = $(this);

      if (key == 8 && $input.val() == '') {
        e.preventDefault();

        if (intl_tel) {
          var $iti = $input.closest('.iti');

          if ($iti.index() > 0) {
            $iti.prev().prev().find('input').get(0).focus();
            $iti.prev().remove();
            intlTelInputGlobals.getInstance(this).destroy();
            $input.remove();
          }
        } else if ($input.index() > 0) {
          $input.prev().prev().get(0).focus();
          $input.prev().remove();
          $input.remove();
        }
      }
    });

  });
})(jQuery);
