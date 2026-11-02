<?php
/** Payment-method selection for new invoices. */
defined('ABSPATH') || exit;

final class PATSACBR_Payment_Policy {
    public static function allows($order) {
        if ('all' === get_option('patsacbr_payment_scope', 'all')) {
            return true;
        }
        $methods = get_option('patsacbr_payment_methods', array());
        $method = (string) $order->get_payment_method();
        return '' !== $method && is_array($methods) && in_array($method, $methods, true);
    }

    public static function error() {
        return new WP_Error('payment_method_excluded', __('Invoice creation is disabled for this payment method.', 'patsch9-accounting-bridge'));
    }

    public static function sanitize_scope($value) {
        return 'all' === $value ? 'all' : 'selected';
    }

    public static function sanitize_methods($value) {
        if (!is_array($value)) {
            return array();
        }
        $methods = array();
        foreach (array_slice($value, 0, 100) as $method) {
            if (is_string($method) && preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $method)) {
                $methods[] = $method;
            }
        }
        return array_values(array_unique($methods));
    }
}
