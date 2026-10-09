(function ($) {
    'use strict';

    $(function () {
        var config = window.patsacbrOrderMetabox || {};
        var strings = config.strings || {};

        if (!config.ajaxUrl || !config.nonce) {
            return;
        }

        function message(response, fallback) {
            if (response && response.data && response.data.message) {
                return response.data.message;
            }
            return fallback;
        }

        function request(button, action, busyText, resetText, fallbackError, options) {
            options = options || {};
            button.prop('disabled', true).text(busyText);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: action,
                    order_id: button.data('order-id'),
                    nonce: config.nonce
                }
            }).done(function (response) {
                if (response && response.success) {
                    if (options.successMessage) {
                        window.alert(options.successMessage);
                    }
                    if (options.reload !== false) {
                        window.location.reload();
                        return;
                    }
                } else {
                    window.alert(message(response, fallbackError));
                }
                button.prop('disabled', false).text(resetText);
            }).fail(function () {
                window.alert(fallbackError);
                button.prop('disabled', false).text(resetText);
            });
        }

        $('.patsacbr-create-invoice').on('click', function () {
            var button = $(this);
            if (window.confirm(strings.confirmCreate)) {
                request(button, 'patsacbr_manual_create_invoice', strings.creating, strings.createLabel, strings.createError);
            }
        });

        $('.patsacbr-void-invoice').on('click', function () {
            var button = $(this);
            if (window.confirm(strings.confirmVoid)) {
                request(button, 'patsacbr_manual_void_invoice', strings.voiding, strings.voidLabel, strings.voidError);
            }
        });

        $('.patsacbr-send-invoice-email').on('click', function () {
            var button = $(this);
            if (window.confirm(strings.confirmSend)) {
                request(button, 'patsacbr_send_invoice_email', strings.sending, strings.sendLabel, strings.sendError, {
                    reload: false,
                    successMessage: strings.sendSuccess
                });
            }
        });

        $('.patsacbr-update-invoice').on('click', function () {
            var button = $(this);
            if (window.confirm(strings.confirmUpdate)) {
                request(button, 'patsacbr_manual_update_invoice', strings.updating, strings.updateLabel, strings.updateError);
            }
        });

        $('.patsacbr-unlink-invoice').on('click', function () {
            var button = $(this);
            if (window.confirm(strings.confirmUnlink)) {
                request(button, 'patsacbr_unlink_invoice', strings.unlinking, strings.unlinkLabel, strings.unlinkError);
            }
        });
    });
}(jQuery));
