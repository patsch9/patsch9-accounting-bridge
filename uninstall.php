<?php
/**
 * Uninstall cleanup for Patsch9 Accounting Bridge.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Accounting links can be operationally important after a reinstall. Preserve
// all plugin data unless the administrator explicitly opted into destructive
// cleanup before deleting the plugin.
if ('yes' !== get_option('wlc_delete_data_on_uninstall', 'no')) {
    return;
}

global $wpdb;

wp_clear_scheduled_hook('wlc_process_queue');
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('wlc_process_queue');
}

$options_to_delete = array(
    'wlc_api_key',
    'wlc_order_statuses',
    'wlc_retry_attempts',
    'wlc_invoice_title',
    'wlc_invoice_introduction',
    'wlc_payment_terms',
    'wlc_rental_print_layout_id',
    'wlc_payment_due_days',
    'wlc_closing_text',
    'wlc_finalize_immediately',
    'wlc_auto_sync_contacts',
    'wlc_show_in_customer_area',
    'wlc_shipping_as_line_item',
    'wlc_enable_logging',
    'wlc_email_on_error',
    'wlc_auto_send_email',
    'wlc_delete_data_on_uninstall',
    'wlc_error_logs',
    'wlc_api_logs',
    'wlc_db_version',
);
foreach ($options_to_delete as $option) {
    delete_option($option);
}

// Payment-gateway-specific settings are dynamic and must not depend on
// WooCommerce being active during uninstall.
foreach (array('wlc_payment_terms_', 'wlc_payment_due_days_') as $prefix) {
    $like = $wpdb->esc_like($prefix) . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit opt-in uninstall cleanup of dynamically named plugin options; cached state is irrelevant during removal.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM %i WHERE option_name LIKE %s",
        $wpdb->options,
        $like
    ));
}

$table = $wpdb->prefix . 'wlc_queue';
$wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit opt-in uninstall cleanup of the plugin-owned queue table.

$meta_keys = array(
    '_wlc_lexware_invoice_id',
    '_wlc_lexware_invoice_number',
    '_wlc_lexware_credit_note_id',
    '_wlc_lexware_invoice_voided',
    '_wlc_lexware_contact_id',
    '_wlc_lexware_credit_note_for_invoice_id',
    '_wlc_lexware_credit_note_history',
    '_wlc_lexware_update_source_invoice_id',
    '_wlc_manual_invoice_entry',
);

// Legacy/post-based order storage.
foreach ($meta_keys as $meta_key) {
    delete_post_meta_by_key($meta_key);
}

// HPOS order metadata. Query the table directly so uninstall remains safe even
// when WooCommerce was deactivated before this plugin.
$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($hpos_meta_table))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ($table_exists === $hpos_meta_table) {
    foreach ($meta_keys as $meta_key) {
        $wpdb->delete($hpos_meta_table, array('meta_key' => $meta_key), array('%s')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time explicit opt-in uninstall cleanup of plugin metadata when WooCommerce may already be inactive.
    }
}

// Remove old public PDF cache from versions before 1.1.0, if it still exists.
$upload_dir = wp_upload_dir();
if (empty($upload_dir['error'])) {
    $pdf_dir = trailingslashit($upload_dir['basedir']) . 'lexware-invoices';
    if (is_dir($pdf_dir)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;
        if (!empty($wp_filesystem) || WP_Filesystem()) {
            $files = $wp_filesystem->dirlist($pdf_dir);
            if (is_array($files)) {
                foreach ($files as $file => $fileinfo) {
                    if (!empty($fileinfo['type']) && 'f' === $fileinfo['type']) {
                        $wp_filesystem->delete(trailingslashit($pdf_dir) . wp_basename($file));
                    }
                }
            }
            $wp_filesystem->rmdir($pdf_dir);
        }
    }
}

delete_transient('wlc_api_test_result');
foreach (array('_transient_wlc_rate_limit_', '_transient_timeout_wlc_rate_limit_') as $prefix) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit opt-in uninstall cleanup of plugin rate-limit transients; cached state is irrelevant during removal.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like($prefix) . '%'
    ));
}
