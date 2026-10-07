<?php
/**
 * Historical invoice reconciliation.
 *
 * Links WooCommerce orders that pre-date the plugin integration to already
 * existing Lexware invoices without creating or modifying remote vouchers.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WLC_Invoice_Reconciler {
    const VERSION = '1';
    const GROUP = 'wlc-reconciliation';
    const HOOK = 'wlc_reconcile_existing_invoices';
    const BATCH_SIZE = 20;

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_schedule'), 30);
        add_action(self::HOOK, array($this, 'process_batch'), 10, 1);
        add_action('wlc_cleanup_scheduler', array($this, 'cleanup_scheduler'));
        add_action('admin_notices', array($this, 'render_completion_notice'));
    }

    public function maybe_schedule() {
        if (self::VERSION === (string) get_option('wlc_invoice_reconciliation_version', '')) {
            return;
        }

        $api_client = new WLC_API_Client();
        if (!$api_client->is_configured()) {
            return;
        }

        if (!get_option('wlc_invoice_reconciliation_cutoff', 0)) {
            update_option('wlc_invoice_reconciliation_cutoff', time(), false);
        }

        $page = max(1, absint(get_option('wlc_invoice_reconciliation_page', 1)));
        $this->schedule_page($page);
    }

    private function schedule_page($page) {
        $page = max(1, absint($page));
        $args = array($page);

        if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_single_action')) {
            if (!as_has_scheduled_action(self::HOOK, $args, self::GROUP)) {
                as_schedule_single_action(time() + 5, self::HOOK, $args, self::GROUP, true);
            }
            return;
        }

        if (!wp_next_scheduled(self::HOOK, $args)) {
            wp_schedule_single_event(time() + 10, self::HOOK, $args);
        }
    }

    public function cleanup_scheduler() {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK);
        }
        wp_clear_scheduled_hook(self::HOOK);
    }

    public function process_batch($page = 1) {
        $page = max(1, absint($page));
        $api_client = new WLC_API_Client();
        if (!$api_client->is_configured()) {
            return;
        }

        $cutoff = absint(get_option('wlc_invoice_reconciliation_cutoff', 0));
        if (!$cutoff) {
            $cutoff = time();
            update_option('wlc_invoice_reconciliation_cutoff', $cutoff, false);
        }

        $query = wc_get_orders(array(
            'limit'    => self::BATCH_SIZE,
            'page'     => $page,
            'paginate' => true,
            'orderby'  => 'ID',
            'order'    => 'ASC',
            'return'   => 'objects',
        ));

        if (!is_object($query) || !isset($query->orders, $query->max_num_pages)) {
            $this->record_global_error('invalid_order_query', __('Der historische Rechnungsabgleich konnte die WooCommerce-Bestellungen nicht paginiert laden.', 'patsch9-accounting-bridge'));
            return;
        }

        $max_pages = absint(get_option('wlc_invoice_reconciliation_max_pages', 0));
        if (!$max_pages) {
            $max_pages = max(1, absint($query->max_num_pages));
            update_option('wlc_invoice_reconciliation_max_pages', $max_pages, false);
        }

        $stats = get_option('wlc_invoice_reconciliation_stats', array());
        $stats = wp_parse_args(is_array($stats) ? $stats : array(), array(
            'checked' => 0,
            'matched' => 0,
            'not_found' => 0,
            'skipped_existing' => 0,
            'skipped_newer' => 0,
            'skipped_manual' => 0,
            'errors' => 0,
        ));

        foreach ($query->orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            if ($order->get_meta('_wlc_lexware_invoice_id')) {
                $stats['skipped_existing']++;
                continue;
            }

            if ('yes' === $order->get_meta('_wlc_skip_auto_reconciliation')) {
                $stats['skipped_manual']++;
                continue;
            }

            $created = $order->get_date_created();
            if (!$created || $created->getTimestamp() > $cutoff) {
                $stats['skipped_newer']++;
                continue;
            }

            if (self::VERSION === (string) $order->get_meta('_wlc_lexware_reconciliation_checked')) {
                continue;
            }

            $stats['checked']++;
            $result = $this->reconcile_order($order, $api_client, true);
            if (is_wp_error($result)) {
                $stats['errors']++;
            } elseif (is_array($result) && !empty($result['id'])) {
                $stats['matched']++;
            } else {
                $stats['not_found']++;
            }
        }

        update_option('wlc_invoice_reconciliation_stats', $stats, false);

        if ($page < $max_pages) {
            $next_page = $page + 1;
            update_option('wlc_invoice_reconciliation_page', $next_page, false);
            $this->schedule_page($next_page);
            return;
        }

        update_option('wlc_invoice_reconciliation_version', self::VERSION, false);
        update_option('wlc_invoice_reconciliation_page', 1, false);
        delete_option('wlc_invoice_reconciliation_max_pages');
        update_option('wlc_invoice_reconciliation_notice_pending', 'yes', false);
    }

    public function reconcile_before_create($order, $api_client = null) {
        if (!$order instanceof WC_Order) {
            return new WP_Error('invalid_order', __('Ungültige Bestellung für den Lexware-Abgleich.', 'patsch9-accounting-bridge'), array('retryable' => false));
        }

        if ($order->get_meta('_wlc_lexware_invoice_id')) {
            return array('id' => sanitize_text_field((string) $order->get_meta('_wlc_lexware_invoice_id')));
        }

        if ('yes' === $order->get_meta('_wlc_skip_auto_reconciliation')) {
            return null;
        }

        if ($order->get_meta('_wlc_lexware_update_source_invoice_id')) {
            return null;
        }

        $cutoff = absint(get_option('wlc_invoice_reconciliation_cutoff', 0));
        $created = $order->get_date_created();
        if (!$cutoff || !$created || $created->getTimestamp() > $cutoff) {
            return null;
        }

        $state = sanitize_key((string) $order->get_meta('_wlc_lexware_reconciliation_state'));
        $checked_version = (string) $order->get_meta('_wlc_lexware_reconciliation_checked');
        if (self::VERSION === $checked_version && 'not_found' === $state) {
            return null;
        }

        if (!$api_client instanceof WLC_API_Client) {
            $api_client = new WLC_API_Client();
        }

        return $this->reconcile_order($order, $api_client, true);
    }

    public function reconcile_order($order, $api_client = null, $add_note = true) {
        if (!$order instanceof WC_Order) {
            return new WP_Error('invalid_order', __('Ungültige Bestellung für den Lexware-Abgleich.', 'patsch9-accounting-bridge'), array('retryable' => false));
        }

        if ($order->get_meta('_wlc_lexware_invoice_id')) {
            return array('id' => sanitize_text_field((string) $order->get_meta('_wlc_lexware_invoice_id')));
        }

        if ('yes' === $order->get_meta('_wlc_skip_auto_reconciliation')) {
            return null;
        }

        if (!$api_client instanceof WLC_API_Client) {
            $api_client = new WLC_API_Client();
        }

        $cutoff = absint(get_option('wlc_invoice_reconciliation_cutoff', 0));
        $match = $api_client->find_invoice_by_order($order, $cutoff);
        if (is_wp_error($match)) {
            $order->update_meta_data('_wlc_lexware_reconciliation_state', 'error');
            $order->update_meta_data('_wlc_lexware_reconciliation_error', sanitize_text_field($match->get_error_message()));
            $order->save();
            return $match;
        }

        if (!$match) {
            $order->update_meta_data('_wlc_lexware_reconciliation_checked', self::VERSION);
            $order->update_meta_data('_wlc_lexware_reconciliation_state', 'not_found');
            $order->delete_meta_data('_wlc_lexware_reconciliation_error');
            $order->save();
            return null;
        }

        $invoice_id = sanitize_text_field((string) ($match['id'] ?? ''));
        $invoice_number = sanitize_text_field((string) ($match['voucherNumber'] ?? ''));
        $invoice_status = sanitize_key((string) ($match['voucherStatus'] ?? ''));
        if (!$invoice_id || !$invoice_number) {
            return new WP_Error('invalid_reconciliation_match', __('Lexware hat beim historischen Rechnungsabgleich unvollständige Rechnungsdaten geliefert.', 'patsch9-accounting-bridge'), array('retryable' => false));
        }

        $order->update_meta_data('_wlc_lexware_invoice_id', $invoice_id);
        $order->update_meta_data('_wlc_lexware_invoice_number', $invoice_number);
        $order->update_meta_data('_wlc_lexware_invoice_reconciled', 'yes');
        $order->update_meta_data('_wlc_lexware_reconciliation_checked', self::VERSION);
        $order->update_meta_data('_wlc_lexware_reconciliation_state', 'matched');
        $order->delete_meta_data('_wlc_lexware_reconciliation_error');
        if ('voided' === $invoice_status) {
            $order->update_meta_data('_wlc_lexware_invoice_voided', 'yes');
        } else {
            $order->delete_meta_data('_wlc_lexware_invoice_voided');
        }
        $order->save();

        if ($add_note) {
            $order->add_order_note(sprintf(
                /* translators: 1: Lexware invoice number, 2: Lexware invoice ID. */
                __('Bestehende Lexware-Rechnung automatisch zugeordnet: %1$s (ID: %2$s). Der Abgleich erfolgte über die Bestellnummer im Einleitungstext und den identischen Gesamtbetrag.', 'patsch9-accounting-bridge'),
                $invoice_number,
                $invoice_id
            ));
        }

        return $match;
    }

    private function record_global_error($code, $message) {
        update_option('wlc_invoice_reconciliation_last_error', array(
            'code' => sanitize_key((string) $code),
            'message' => sanitize_text_field((string) $message),
            'time' => time(),
        ), false);
    }

    public function render_completion_notice() {
        if (!current_user_can('manage_woocommerce') || 'yes' !== get_option('wlc_invoice_reconciliation_notice_pending', 'no')) {
            return;
        }

        delete_option('wlc_invoice_reconciliation_notice_pending');
        $stats = get_option('wlc_invoice_reconciliation_stats', array());
        $stats = wp_parse_args(is_array($stats) ? $stats : array(), array(
            'matched' => 0,
            'not_found' => 0,
            'errors' => 0,
        ));

        printf(
            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: linked invoices, 2: orders without a match, 3: orders requiring review. */
                __('Historischer Lexware-Rechnungsabgleich abgeschlossen: %1$d zugeordnet, %2$d ohne Treffer, %3$d mit Prüfbedarf.', 'patsch9-accounting-bridge'),
                absint($stats['matched']),
                absint($stats['not_found']),
                absint($stats['errors'])
            ))
        );
    }
}
