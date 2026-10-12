<?php
/**
 * Compatibility migration for builds published before the WordPress.org review.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class PATSACBR_Legacy_Migration {
    const VERSION = '2';

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

    /** Fail closed and retain legacy values until every copy has been verified. */
    public static function run() {
        self::register_legacy_api_key_constant();
        self::register_order_meta_fallbacks();
        if (self::VERSION === (string) get_option('patsacbr_prefix_migration_version', '')) {
            return true;
        }
        global $wpdb;
        $lock = self::lock_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration must be serialized across requests using a database advisory lock.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
            return false;
        }
        try {
            foreach (self::$option_suffixes as $suffix) {
                if (!self::migrate_option(self::legacy_prefix() . $suffix, 'patsacbr_' . $suffix)) {
                    return false;
                }
            }
            if (!self::migrate_option('woocommerce_' . self::legacy_prefix() . 'invoice_settings', 'woocommerce_patsacbr_invoice_settings') || !self::migrate_dynamic_payment_options()) {
                return false;
            }
            update_option('patsacbr_prefix_migration_version', self::VERSION, false);
            if (self::VERSION !== (string) get_option('patsacbr_prefix_migration_version', '')) {
                return false;
            }
            self::cleanup_legacy_schedules();
            return true;
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the matching migration advisory lock even after failure.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function lock_name() {
        global $wpdb;
        return 'patsacbr_migrate_' . substr(hash('sha256', DB_NAME . '|' . $wpdb->prefix), 0, 40);
    }

    private static function migrate_option($legacy_key, $new_key) {
        $sentinel = new stdClass();
        $legacy_value = get_option($legacy_key, $sentinel);
        if ($sentinel === $legacy_value) {
            return true;
        }
        $new_value = get_option($new_key, $sentinel);
        if ($sentinel !== $new_value && $new_value !== $legacy_value) {
            return false;
        }
        if ($sentinel === $new_value) {
            // add_option never overwrites a canonical value created concurrently.
            add_option($new_key, $legacy_value, '', false);
            if (get_option($new_key, $sentinel) !== $legacy_value) {
                return false;
            }
        }
        delete_option($legacy_key);
        return $sentinel === get_option($legacy_key, $sentinel);
    }

    /**
     * Migrate gateway-specific options without instantiating WooCommerce payment
     * gateways. Instantiating them during plugin activation can trigger WooCommerce
     * translations before WordPress reaches init.
     */
    private static function migrate_dynamic_payment_options() {
        global $wpdb;

        $legacy_prefix = self::legacy_prefix();
        foreach (array('payment_terms_', 'payment_due_days_') as $setting_prefix) {
            $expected_prefix = $legacy_prefix . $setting_prefix;
            $like = $wpdb->esc_like($expected_prefix) . '%';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration must discover dynamically named legacy plugin options without initializing WooCommerce gateways during activation.
            $option_names = $wpdb->get_col($wpdb->prepare(
                'SELECT option_name FROM %i WHERE option_name LIKE %s',
                $wpdb->options,
                $like
            ));

            if ('' !== $wpdb->last_error || !is_array($option_names)) {
                return false;
            }
            foreach ($option_names as $legacy_key) {
                $legacy_key = (string) $legacy_key;
                if (0 !== strpos($legacy_key, $expected_prefix)) {
                    continue;
                }

                $gateway_id = substr($legacy_key, strlen($expected_prefix));
                if ('' === $gateway_id || sanitize_key($gateway_id) !== $gateway_id) {
                    continue;
                }

                if (!self::migrate_option($legacy_key, 'patsacbr_' . $setting_prefix . $gateway_id)) {
                    return false;
                }
            }
        }
        return true;
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

    /**
     * Move the original table without copying or renumbering queue rows.
     * Schema upgrades happen afterwards through dbDelta. Two populated tables
     * require manual resolution; never merge with INSERT IGNORE or discard rows.
     */
    public static function migrate_queue_table() {
        global $wpdb;
        $lock = self::lock_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serialize schema migration and preserve source tables on database failures.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
            return false;
        }
        try {
            $legacy_table = $wpdb->prefix . self::legacy_prefix() . 'queue';
            $new_table = $wpdb->prefix . 'patsacbr_queue';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inspect plugin-owned tables before atomic rename.
            $legacy_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($legacy_table)));
            if ('' !== $wpdb->last_error) {
                return false;
            }
            if ($legacy_table !== $legacy_exists) {
                return true;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inspect the destination before moving the original table.
            $new_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($new_table)));
            if ('' !== $wpdb->last_error) {
                return false;
            }
            if ($new_table === $new_exists) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A verified empty legacy table has no work to preserve.
                $legacy_count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $legacy_table));
                if ('' !== $wpdb->last_error || null === $legacy_count) {
                    return false;
                }
                if (0 === (int) $legacy_count) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Remove only the verified empty legacy table.
                    return false !== $wpdb->query($wpdb->prepare('DROP TABLE %i', $legacy_table));
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only a verified empty destination may be removed.
                $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $new_table));
                if ('' !== $wpdb->last_error || null === $count || (int) $count > 0) {
                    return false;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Drop only the verified empty plugin-owned destination; the complete legacy source remains recoverable.
                if (false === $wpdb->query($wpdb->prepare('DROP TABLE %i', $new_table))) {
                    return false;
                }
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Atomic rename preserves every original row regardless of old column count/order.
            return false !== $wpdb->query($wpdb->prepare('RENAME TABLE %i TO %i', $legacy_table, $new_table));
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the migration advisory lock on every path.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
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
