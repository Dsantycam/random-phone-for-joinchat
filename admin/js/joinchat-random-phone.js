/**
 * Joinchat Random Phone - admin (multi phone field)
 *
 * Plain, dependency-free multi-phone field: each phone is a normal text input
 * with a real `name`, so every value is always submitted (no reliance on
 * intl-tel-input, whose API changes between Joinchat versions and was erasing
 * fields). You type the full international number, e.g. 34600111222.
 *
 *  - "+"  adds a new empty phone field.
 *  - Backspace on an empty field removes it (and focuses the previous one).
 */
(function ($) {
  'use strict';

  $(function () {

    // Add a phone field.
    $(document).on('click', '.joinchat_phone__add', function (e) {
      e.preventDefault();

      var $wrap = $(this).parent();
      var $zero = $wrap.find('.joinchat_phone').first();
      var key = Math.floor(Math.random() * 100000);
      var base = $zero.attr('name') || $zero.data('name') || 'joinchat[telephone][0]';
      // Replace the last [number] with a unique key.
      var name = base.replace(/\[(\d+)\]([^\[\]]*)$/, '[' + key + ']$2');

      $('<br><input class="joinchat_phone" name="' + name + '" value="" type="text" autocomplete="off">').insertBefore(this);
      $wrap.find('.joinchat_phone').last().trigger('focus');
    });

    // Remove a phone field (Backspace on an empty field).
    $(document).on('keydown', '.joinchat_phones .joinchat_phone', function (e) {
      var key = e.which || e.keyCode;
      var $input = $(this);

      if (key !== 8 || $input.val() !== '') return;
      e.preventDefault();

      var $prev = $input.prevAll('.joinchat_phone').first();
      if ($prev.length) {
        $prev.trigger('focus');
        $input.prev('br').remove();
        $input.remove();
      }
    });

  });
})(jQuery);
