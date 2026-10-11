<?php
/**
 * Lexware Rechnungs-E-Mail (HTML)
 */

if (!defined('ABSPATH')) {
    exit;
}

do_action('woocommerce_email_header', $email_heading, $email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
?>

<p><?php
/* translators: %s: Customer's first name. */
echo esc_html(sprintf(__('Hello %s,', 'patsch9-accounting-bridge'), $order->get_billing_first_name()));
?></p>

<p><?php esc_html_e('Thank you for your order. Your invoice document is attached (PDF or XML for XRechnung).', 'patsch9-accounting-bridge'); ?></p>

<h2><?php
/* translators: %s: Order number. */
echo esc_html(sprintf(__('Order #%s', 'patsch9-accounting-bridge'), $order->get_order_number()));
?></h2>

<?php
do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.

do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.

do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.

if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email hook.
