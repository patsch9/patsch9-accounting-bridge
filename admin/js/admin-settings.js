(function ($) {
    'use strict';

    $(function () {
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
