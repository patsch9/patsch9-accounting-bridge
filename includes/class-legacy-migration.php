<?php
/**
 * Compatibility migration for builds published before the WordPress.org review.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class PATSACBR_Legacy_Migration {
    const VERSION = '1';

    private static $option_suffixes = array(
        'api_key',
        'api_logs',
        'auto_send_email',
        'auto_sync_contacts',
        'closing_text',
        'db_version',
        'delete_data_on_uninstall',
        'email_on_error',
        'enable_logging',
        'error_logs',
        'finalize_immediately',
        'invoice_introduction',
        'invoice_reconciliation_cutoff',
        'invoice_reconciliation_finished_at',
        'invoice_reconciliation_force_recheck',
        'invoice_reconciliation_last_error',
        'invoice_reconciliation_max_pages',
        'invoice_reconciliation_notice_pending',
        'invoice_reconciliation_page',
        'invoice_reconciliation_started_at',
        'invoice_reconciliation_stats',
        'invoice_reconciliation_status',
        'invoice_reconciliation_version',
        'invoice_title',
        'order_statuses',
        'payment_due_days',
        'payment_terms',
        'rental_print_layout_id',
        'retry_attempts',
        'shipping_as_line_item',
        'show_in_customer_area',
    );

    private static $order_meta_suffixes = array(
        'lexware_contact_id',
        'lexware_credit_note_for_invoice_id',
        'lexware_credit_note_history',
        'lexware_credit_note_id',
        'lexware_invoice_id',
        'lexware_invoice_number',
        'lexware_invoice_reconciled',
        'lexware_invoice_voided',
        'lexware_reconciliation_checked',
        'lexware_reconciliation_error',
        'lexware_reconciliation_state',
        'lexware_update_source_invoice_id',
        'manual_invoice_entry',
        'skip_auto_reconciliation',
    );

    private static function legacy_prefix() {
        return 'wl' . 'c_';
    }

    private static function legacy_meta_prefix() {
        return '_wl' . 'c_';
    }

    /**
     * Preserve installations that stored the API key in wp-config.php under
     * the pre-directory constant name, while exposing only the new prefixed
     * constant to the active plugin code.
     */
    private static function register_legacy_api_key_constant() {
        $legacy_constant = 'LEXWARE' . '_CONNECTOR_API_KEY';
        if (defined('PATSACBR_LEXWARE_API_KEY') || !defined($legacy_constant)) {
            return;
        }

        $legacy_value = constant($legacy_constant);
        if (is_scalar($legacy_value)) {
            define('PATSACBR_LEXWARE_API_KEY', (string) $legacy_value);
        }
    }

    public static function run() {
        self::register_legacy_api_key_constant();
        self::register_order_meta_fallbacks();

        if (self::VERSION === (string) get_option('patsacbr_prefix_migration_version', '')) {
            return;
        }

        foreach (self::$option_suffixes as $suffix) {
            self::migrate_option(self::legacy_prefix() . $suffix, 'patsacbr_' . $suffix);
        }

        if (function_exists('WC') && WC() && WC()->payment_gateways()) {
            foreach (WC()->payment_gateways->payment_gateways() as $gateway) {
                if (!is_object($gateway) || empty($gateway->id)) {
                    continue;
                }
                $gateway_id = sanitize_key((string) $gateway->id);
                self::migrate_option(self::legacy_prefix() . 'payment_terms_' . $gateway_id, 'patsacbr_payment_terms_' . $gateway_id);
                self::migrate_option(self::legacy_prefix() . 'payment_due_days_' . $gateway_id, 'patsacbr_payment_due_days_' . $gateway_id);
            }
        }

        self::cleanup_legacy_schedules();
        update_option('patsacbr_prefix_migration_version', self::VERSION, false);
    }

    private static function migrate_option($legacy_key, $new_key) {
        $sentinel = '__patsacbr_missing_option__';
        if ($sentinel !== get_option($new_key, $sentinel)) {
            return;
        }

        $legacy_value = get_option($legacy_key, $sentinel);
        if ($sentinel === $legacy_value) {
            return;
        }

        update_option($new_key, $legacy_value, false);
        delete_option($legacy_key);
    }

    private static function register_order_meta_fallbacks() {
        foreach (self::$order_meta_suffixes as $suffix) {
            add_filter('woocommerce_order_get__patsacbr_' . $suffix, array(__CLASS__, 'migrate_order_meta_on_read'), 10, 2);
        }
    }

    public static function migrate_order_meta_on_read($value, $order) {
        if (!$order instanceof WC_Order) {
            return $value;
        }

        $hook_prefix = 'woocommerce_order_get__patsacbr_';
        $hook = current_filter();
        if (0 !== strpos($hook, $hook_prefix)) {
            return $value;
        }

        $suffix = substr($hook, strlen($hook_prefix));
        if (!in_array($suffix, self::$order_meta_suffixes, true)) {
            return $value;
        }

        $new_key = '_patsacbr_' . $suffix;
        if ($order->meta_exists($new_key)) {
            return $value;
        }

        $legacy_key = self::legacy_meta_prefix() . $suffix;
        $legacy_value = $order->get_meta($legacy_key, true, 'edit');
        if ('' === $legacy_value || null === $legacy_value) {
            return $value;
        }

        $order->update_meta_data($new_key, $legacy_value);
        $order->delete_meta_data($legacy_key);
        $order->save_meta_data();
        return $legacy_value;
    }

    public static function migrate_queue_table() {
        global $wpdb;

        $legacy_table = $wpdb->prefix . self::legacy_prefix() . 'queue';
        $new_table = $wpdb->prefix . 'patsacbr_queue';
        $legacy_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($legacy_table))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration of a plugin-owned table.
        if ($legacy_table !== $legacy_exists) {
            return;
        }

        $result = $wpdb->query($wpdb->prepare('INSERT IGNORE INTO %i SELECT * FROM %i', $new_table, $legacy_table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- migration between plugin-owned tables.
        if (false !== $result) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $legacy_table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- remove the plugin-owned legacy table only after a successful copy.
        }
    }

    private static function cleanup_legacy_schedules() {
        $prefix = self::legacy_prefix();
        foreach (array($prefix . 'process_queue', $prefix . 'reconcile_existing_invoices') as $hook) {
            wp_clear_scheduled_hook($hook);
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions($hook);
            }
        }
    }
}
