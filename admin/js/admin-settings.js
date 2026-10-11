(function ($) {
    'use strict';

    $(function () {
        // Translated messages are data, including quotes and line breaks.
        $('form[data-patsacbr-confirm]').on('submit', function (event) {
            if (!window.confirm($(this).attr('data-patsacbr-confirm'))) {
                event.preventDefault();
            }
        });

        var input = $('#patsacbr_api_key');
        var button = $('.patsacbr-toggle-api-key');
        var icon = button.find('.dashicons');
        var isVisible = false;

        button.on('click', function (event) {
            event.preventDefault();
            isVisible = !isVisible;
            input.attr('type', isVisible ? 'text' : 'password');
            icon.toggleClass('dashicons-visibility', !isVisible);
            icon.toggleClass('dashicons-hidden', isVisible);
        });
    });
}(jQuery));
