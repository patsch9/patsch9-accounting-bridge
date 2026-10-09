<?php
/**
 * Lexware Rechnungs-E-Mail (Plain Text)
 */

if (!defined('ABSPATH')) {
    exit;
}

echo "= " . esc_html($email_heading) . " =\n\n";

/* translators: %s: Vorname des Kunden */
echo esc_html(sprintf(__('Hallo %s,', 'patsch9-accounting-bridge'), $order->get_billing_first_name()));

echo "\n\n";

esc_html_e('vielen Dank für Ihre Bestellung. Im Anhang finden Sie Ihre Rechnungsdatei (PDF bzw. bei XRechnung XML).', 'patsch9-accounting-bridge');

echo "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.

do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.

echo "\n\n";

echo wp_kses_post(wpautop(wptexturize($additional_content)));

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

echo esc_html(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text'))); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
