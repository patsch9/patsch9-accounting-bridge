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
if ('yes' !== get_option('patsacbr_delete_data_on_uninstall', 'no')) {
    return;
}

global $wpdb;

$patsacbr_legacy_prefix = 'wl' . 'c_';
$patsacbr_legacy_meta_prefix = '_wl' . 'c_';

foreach (array('patsacbr_process_queue', 'patsacbr_reconcile_existing_invoices') as $patsacbr_schedule_hook) {
    wp_clear_scheduled_hook($patsacbr_schedule_hook);
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions($patsacbr_schedule_hook);
    }
}
foreach (array($patsacbr_legacy_prefix . 'process_queue', $patsacbr_legacy_prefix . 'reconcile_existing_invoices') as $patsacbr_legacy_schedule_hook) {
    wp_clear_scheduled_hook($patsacbr_legacy_schedule_hook);
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions($patsacbr_legacy_schedule_hook);
    }
}

$patsacbr_options_to_delete = array(
    'patsacbr_api_key',
    'patsacbr_order_statuses',
    'patsacbr_retry_attempts',
    'patsacbr_invoice_title',
    'patsacbr_invoice_introduction',
    'patsacbr_payment_terms',
    'patsacbr_rental_print_layout_id',
    'patsacbr_payment_due_days',
    'patsacbr_closing_text',
    'patsacbr_finalize_immediately',
    'patsacbr_auto_sync_contacts',
    'patsacbr_show_in_customer_area',
    'patsacbr_shipping_as_line_item',
    'patsacbr_enable_logging',
    'patsacbr_email_on_error',
    'patsacbr_auto_send_email',
    'patsacbr_delete_data_on_uninstall',
    'patsacbr_error_logs',
    'patsacbr_api_logs',
    'patsacbr_db_version',
    'patsacbr_invoice_reconciliation_cutoff',
    'patsacbr_invoice_reconciliation_finished_at',
    'patsacbr_invoice_reconciliation_force_recheck',
    'patsacbr_invoice_reconciliation_last_error',
    'patsacbr_invoice_reconciliation_lock',
    'patsacbr_invoice_reconciliation_max_pages',
    'patsacbr_invoice_reconciliation_notice_pending',
    'patsacbr_invoice_reconciliation_page',
    'patsacbr_invoice_reconciliation_started_at',
    'patsacbr_invoice_reconciliation_stats',
    'patsacbr_invoice_reconciliation_status',
    'patsacbr_invoice_reconciliation_version',
    'patsacbr_prefix_migration_version',
    'woocommerce_patsacbr_invoice_settings',
);
foreach ($patsacbr_options_to_delete as $patsacbr_option) {
    delete_option($patsacbr_option);
    if (0 === strpos($patsacbr_option, 'patsacbr_')) {
        delete_option($patsacbr_legacy_prefix . substr($patsacbr_option, strlen('patsacbr_')));
    }
}
delete_option('woocommerce_' . $patsacbr_legacy_prefix . 'invoice_settings');

// Payment-gateway-specific settings are dynamic and must not depend on
// WooCommerce being active during uninstall.
$patsacbr_dynamic_option_prefixes = array(
    'patsacbr_payment_terms_',
    'patsacbr_payment_due_days_',
    $patsacbr_legacy_prefix . 'payment_terms_',
    $patsacbr_legacy_prefix . 'payment_due_days_',
);
foreach ($patsacbr_dynamic_option_prefixes as $patsacbr_option_prefix) {
    $patsacbr_like = $wpdb->esc_like($patsacbr_option_prefix) . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit opt-in uninstall cleanup of dynamically named plugin options; cached state is irrelevant during removal.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM %i WHERE option_name LIKE %s",
        $wpdb->options,
        $patsacbr_like
    ));
}

foreach (array($wpdb->prefix . 'patsacbr_queue', $wpdb->prefix . $patsacbr_legacy_prefix . 'queue') as $patsacbr_queue_table) {
    $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $patsacbr_queue_table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit opt-in uninstall cleanup of plugin-owned queue tables.
}

$patsacbr_meta_keys = array(
    '_patsacbr_lexware_contact_id',
    '_patsacbr_lexware_credit_note_for_invoice_id',
    '_patsacbr_lexware_credit_note_history',
    '_patsacbr_lexware_credit_note_id',
    '_patsacbr_lexware_invoice_id',
    '_patsacbr_lexware_invoice_number',
    '_patsacbr_lexware_invoice_reconciled',
    '_patsacbr_lexware_invoice_voided',
    '_patsacbr_lexware_reconciliation_checked',
    '_patsacbr_lexware_reconciliation_error',
    '_patsacbr_lexware_reconciliation_state',
    '_patsacbr_lexware_update_source_invoice_id',
    '_patsacbr_manual_invoice_entry',
    '_patsacbr_skip_auto_reconciliation',
);

// Legacy/post-based order storage.
foreach ($patsacbr_meta_keys as $patsacbr_meta_key) {
    delete_post_meta_by_key($patsacbr_meta_key);
    delete_post_meta_by_key($patsacbr_legacy_meta_prefix . substr($patsacbr_meta_key, strlen('_patsacbr_')));
}

// HPOS order metadata. Query the table directly so uninstall remains safe even
// when WooCommerce was deactivated before this plugin.
$patsacbr_hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
$patsacbr_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($patsacbr_hpos_meta_table))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ($patsacbr_table_exists === $patsacbr_hpos_meta_table) {
    foreach ($patsacbr_meta_keys as $patsacbr_meta_key) {
        $wpdb->delete($patsacbr_hpos_meta_table, array('meta_key' => $patsacbr_meta_key), array('%s')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time explicit opt-in uninstall cleanup of plugin metadata when WooCommerce may already be inactive.
        $patsacbr_legacy_meta_key = $patsacbr_legacy_meta_prefix . substr($patsacbr_meta_key, strlen('_patsacbr_'));
        $wpdb->delete($patsacbr_hpos_meta_table, array('meta_key' => $patsacbr_legacy_meta_key), array('%s')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time explicit opt-in cleanup of metadata written by pre-directory builds.
    }
}

// Remove old public PDF cache from versions before 1.1.0, if it still exists.
$patsacbr_upload_dir = wp_upload_dir();
if (empty($patsacbr_upload_dir['error'])) {
    $patsacbr_pdf_dir = trailingslashit($patsacbr_upload_dir['basedir']) . 'lexware-invoices';
    if (is_dir($patsacbr_pdf_dir)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;
        if (!empty($wp_filesystem) || WP_Filesystem()) {
            $patsacbr_files = $wp_filesystem->dirlist($patsacbr_pdf_dir);
            if (is_array($patsacbr_files)) {
                foreach ($patsacbr_files as $patsacbr_file => $patsacbr_fileinfo) {
                    if (!empty($patsacbr_fileinfo['type']) && 'f' === $patsacbr_fileinfo['type']) {
                        $wp_filesystem->delete(trailingslashit($patsacbr_pdf_dir) . wp_basename($patsacbr_file));
                    }
                }
            }
            $wp_filesystem->rmdir($patsacbr_pdf_dir);
        }
    }
}

delete_transient('patsacbr_api_test_result');
$patsacbr_transient_prefixes = array(
    '_transient_patsacbr_rate_limit_',
    '_transient_timeout_patsacbr_rate_limit_',
    '_transient_' . $patsacbr_legacy_prefix . 'rate_limit_',
    '_transient_timeout_' . $patsacbr_legacy_prefix . 'rate_limit_',
);
foreach ($patsacbr_transient_prefixes as $patsacbr_transient_prefix) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit opt-in uninstall cleanup of plugin rate-limit transients; cached state is irrelevant during removal.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like($patsacbr_transient_prefix) . '%'
    ));
}
