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

class PATSACBR_Invoice_Reconciler {
    const VERSION = '1';
    const GROUP = 'patsacbr-reconciliation';
    const HOOK = 'patsacbr_reconcile_existing_invoices';
    const BATCH_SIZE = 10;
    const LOCK_OPTION = 'patsacbr_invoice_reconciliation_lock';
    const LOCK_TTL = 1800;

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
        add_action('patsacbr_cleanup_scheduler', array($this, 'cleanup_scheduler'));
        add_action('admin_post_patsacbr_restart_invoice_reconciliation', array($this, 'handle_manual_restart'));
    }

    public function maybe_schedule() {
        if (self::VERSION === (string) get_option('patsacbr_invoice_reconciliation_version', '')) {
            return;
        }

        $api_client = new PATSACBR_API_Client();
        if (!$api_client->is_configured()) {
            update_option('patsacbr_invoice_reconciliation_status', 'api_missing', false);
            return;
        }

        if (!get_option('patsacbr_invoice_reconciliation_cutoff', 0)) {
            update_option('patsacbr_invoice_reconciliation_cutoff', time(), false);
        }

        $page = max(1, absint(get_option('patsacbr_invoice_reconciliation_page', 1)));
        $scheduled = $this->schedule_page($page);
        if ($scheduled) {
            update_option('patsacbr_invoice_reconciliation_status', 'scheduled', false);
        }
    }

    /**
     * Schedule one reconciliation batch.
     *
     * @param int  $page      WooCommerce order page to process.
     * @param bool $immediate Whether to enqueue asynchronously when possible.
     * @return int|bool Action ID / scheduling result.
     */
    private function schedule_page($page, $immediate = false) {
        $page = max(1, absint($page));
        $args = array($page);

        if (function_exists('as_has_scheduled_action')) {
            if (as_has_scheduled_action(self::HOOK, $args, self::GROUP)) {
                return true;
            }

            if ($immediate && function_exists('as_enqueue_async_action')) {
                return as_enqueue_async_action(self::HOOK, $args, self::GROUP, true);
            }

            if (function_exists('as_schedule_single_action')) {
                return as_schedule_single_action(time() + 5, self::HOOK, $args, self::GROUP, true);
            }
        }

        if (!wp_next_scheduled(self::HOOK, $args)) {
            return wp_schedule_single_event(time() + ($immediate ? 1 : 10), self::HOOK, $args);
        }

        return true;
    }

    public function cleanup_scheduler() {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, array(), self::GROUP);
        }
        wp_clear_scheduled_hook(self::HOOK);
        delete_option(self::LOCK_OPTION);
    }

    /**
     * Check whether a non-stale reconciliation worker currently owns the lock.
     *
     * @return bool
     */
    private function is_batch_lock_active() {
        $locked_at = absint(get_option(self::LOCK_OPTION, 0));
        if (!$locked_at) {
            return false;
        }

        if ((time() - $locked_at) >= self::LOCK_TTL) {
            delete_option(self::LOCK_OPTION);
            return false;
        }

        return true;
    }

    /**
     * Start a completely new historical scan without touching existing links.
     *
     * Already linked orders remain untouched. Orders previously checked without
     * a match are rechecked because Lexware may have gained invoices since the
     * original scan.
     *
     * @return int|bool|WP_Error Action ID / scheduling result or busy state.
     */
    public function restart_scan() {
        $api_client = new PATSACBR_API_Client();
        if (!$api_client->is_configured()) {
            update_option('patsacbr_invoice_reconciliation_status', 'api_missing', false);
            return false;
        }

        if ($this->is_batch_lock_active()) {
            update_option('patsacbr_invoice_reconciliation_status', 'running', false);
            return new WP_Error(
                'reconciliation_busy',
                __('Historical invoice reconciliation is already running. A concurrent restart was prevented for safety reasons.', 'patsch9-accounting-bridge')
            );
        }

        $this->cleanup_scheduler();

        update_option('patsacbr_invoice_reconciliation_cutoff', time(), false);
        update_option('patsacbr_invoice_reconciliation_page', 1, false);
        delete_option('patsacbr_invoice_reconciliation_max_pages');
        delete_option('patsacbr_invoice_reconciliation_version');
        delete_option('patsacbr_invoice_reconciliation_last_error');
        delete_option('patsacbr_invoice_reconciliation_notice_pending');
        update_option('patsacbr_invoice_reconciliation_force_recheck', 'yes', false);
        update_option('patsacbr_invoice_reconciliation_started_at', time(), false);
        delete_option('patsacbr_invoice_reconciliation_finished_at');
        update_option(
            'patsacbr_invoice_reconciliation_stats',
            array(
                'checked' => 0,
                'matched' => 0,
                'not_found' => 0,
                'skipped_existing' => 0,
                'skipped_newer' => 0,
                'skipped_manual' => 0,
                'skipped_update' => 0,
                'errors' => 0,
            ),
            false
        );

        $scheduled = $this->schedule_page(1, true);
        update_option('patsacbr_invoice_reconciliation_status', $scheduled ? 'scheduled' : 'schedule_error', false);
        return $scheduled;
    }

    public function handle_manual_restart() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'patsch9-accounting-bridge'), '', array('response' => 403));
        }

        check_admin_referer('patsacbr_restart_invoice_reconciliation');

        $result = $this->restart_scan();
        if (is_wp_error($result)) {
            $notice = 'reconciliation_busy' === $result->get_error_code() ? 'busy' : 'error';
        } else {
            $notice = $result ? 'started' : 'error';
        }
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'patsacbr-settings',
                    'tab' => 'logs',
                    'patsacbr_reconciliation_notice' => $notice,
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Acquire a short-lived cross-request lock for the background scanner.
     *
     * add_option() is used as an atomic create-if-missing operation. The TTL
     * allows recovery after a PHP/worker crash without permanently blocking
     * reconciliation.
     *
     * @return bool
     */
    private function acquire_batch_lock() {
        $now = time();
        if (add_option(self::LOCK_OPTION, $now, '', false)) {
            return true;
        }

        $locked_at = absint(get_option(self::LOCK_OPTION, 0));
        if ($locked_at && ($now - $locked_at) < self::LOCK_TTL) {
            return false;
        }

        delete_option(self::LOCK_OPTION);
        return add_option(self::LOCK_OPTION, $now, '', false);
    }

    private function release_batch_lock() {
        delete_option(self::LOCK_OPTION);
    }

    public function process_batch($page = 1) {
        $page = max(1, absint($page));
        $api_client = new PATSACBR_API_Client();
        if (!$api_client->is_configured()) {
            update_option('patsacbr_invoice_reconciliation_status', 'api_missing', false);
            return;
        }

        if (!$this->acquire_batch_lock()) {
            return;
        }

        update_option('patsacbr_invoice_reconciliation_status', 'running', false);

        try {
            $cutoff = absint(get_option('patsacbr_invoice_reconciliation_cutoff', 0));
            if (!$cutoff) {
                $cutoff = time();
                update_option('patsacbr_invoice_reconciliation_cutoff', $cutoff, false);
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
                $this->record_global_error('invalid_order_query', __('Historical invoice reconciliation could not load paginated WooCommerce orders.', 'patsch9-accounting-bridge'));
                update_option('patsacbr_invoice_reconciliation_status', 'error', false);
                return;
            }

            $max_pages = absint(get_option('patsacbr_invoice_reconciliation_max_pages', 0));
            if (!$max_pages) {
                $max_pages = max(1, absint($query->max_num_pages));
                update_option('patsacbr_invoice_reconciliation_max_pages', $max_pages, false);
            }

            $stats = get_option('patsacbr_invoice_reconciliation_stats', array());
            $stats = wp_parse_args(is_array($stats) ? $stats : array(), array(
                'checked' => 0,
                'matched' => 0,
                'not_found' => 0,
                'skipped_existing' => 0,
                'skipped_newer' => 0,
                'skipped_manual' => 0,
                'skipped_update' => 0,
                'errors' => 0,
            ));
            $force_recheck = 'yes' === get_option('patsacbr_invoice_reconciliation_force_recheck', 'no');

            foreach ($query->orders as $order) {
                if (!$order instanceof WC_Order) {
                    continue;
                }

                if ($order->get_meta('_patsacbr_lexware_invoice_id')) {
                    $stats['skipped_existing']++;
                    continue;
                }

                if ('yes' === $order->get_meta('_patsacbr_skip_auto_reconciliation')) {
                    $stats['skipped_manual']++;
                    continue;
                }

                if ($order->get_meta('_patsacbr_lexware_update_source_invoice_id')) {
                    $stats['skipped_update']++;
                    continue;
                }

                $created = $order->get_date_created();
                if (!$created || $created->getTimestamp() > $cutoff) {
                    $stats['skipped_newer']++;
                    continue;
                }

                if (!$force_recheck && self::VERSION === (string) $order->get_meta('_patsacbr_lexware_reconciliation_checked')) {
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

            update_option('patsacbr_invoice_reconciliation_stats', $stats, false);

            if ($page < $max_pages) {
                $next_page = $page + 1;
                update_option('patsacbr_invoice_reconciliation_page', $next_page, false);
                $scheduled = $this->schedule_page($next_page);
                if (!$scheduled) {
                    $this->record_global_error('schedule_failed', __('The next batch of historical invoice reconciliation could not be scheduled.', 'patsch9-accounting-bridge'));
                    update_option('patsacbr_invoice_reconciliation_status', 'schedule_error', false);
                } else {
                    update_option('patsacbr_invoice_reconciliation_status', 'scheduled', false);
                }
                return;
            }

            update_option('patsacbr_invoice_reconciliation_version', self::VERSION, false);
            update_option('patsacbr_invoice_reconciliation_page', 1, false);
            delete_option('patsacbr_invoice_reconciliation_max_pages');
            delete_option('patsacbr_invoice_reconciliation_force_recheck');
            update_option('patsacbr_invoice_reconciliation_finished_at', time(), false);
            update_option('patsacbr_invoice_reconciliation_status', 'completed', false);
            update_option('patsacbr_invoice_reconciliation_notice_pending', 'yes', false);
        } finally {
            $this->release_batch_lock();
        }
    }

    public function reconcile_before_create($order, $api_client = null) {
        if (!$order instanceof WC_Order) {
            return new WP_Error('invalid_order', __('Invalid order for Lexware reconciliation.', 'patsch9-accounting-bridge'), array('retryable' => false));
        }

        if ($order->get_meta('_patsacbr_lexware_invoice_id')) {
            return array('id' => sanitize_text_field((string) $order->get_meta('_patsacbr_lexware_invoice_id')));
        }

        if ('yes' === $order->get_meta('_patsacbr_skip_auto_reconciliation')) {
            return null;
        }

        if ($order->get_meta('_patsacbr_lexware_update_source_invoice_id')) {
            return null;
        }

        $cutoff = absint(get_option('patsacbr_invoice_reconciliation_cutoff', 0));
        $created = $order->get_date_created();
        if (!$cutoff || !$created || $created->getTimestamp() > $cutoff) {
            return null;
        }

        // Never trust an earlier "not found" result immediately before a
        // financial write. An invoice may have been created/imported in Lexware
        // after the background scan. Re-checking here prevents duplicate invoices.
        if (!$api_client instanceof PATSACBR_API_Client) {
            $api_client = new PATSACBR_API_Client();
        }

        return $this->reconcile_order($order, $api_client, true);
    }

    public function reconcile_order($order, $api_client = null, $add_note = true) {
        if (!$order instanceof WC_Order) {
            return new WP_Error('invalid_order', __('Invalid order for Lexware reconciliation.', 'patsch9-accounting-bridge'), array('retryable' => false));
        }

        if ($order->get_meta('_patsacbr_lexware_invoice_id')) {
            return array('id' => sanitize_text_field((string) $order->get_meta('_patsacbr_lexware_invoice_id')));
        }

        if ('yes' === $order->get_meta('_patsacbr_skip_auto_reconciliation')) {
            return null;
        }

        if ($order->get_meta('_patsacbr_lexware_update_source_invoice_id')) {
            return null;
        }

        if (!$api_client instanceof PATSACBR_API_Client) {
            $api_client = new PATSACBR_API_Client();
        }

        $cutoff = absint(get_option('patsacbr_invoice_reconciliation_cutoff', 0));
        $match = $api_client->find_invoice_by_order($order, $cutoff);
        if (is_wp_error($match)) {
            $order->update_meta_data('_patsacbr_lexware_reconciliation_state', 'error');
            $order->update_meta_data('_patsacbr_lexware_reconciliation_error', sanitize_text_field($match->get_error_message()));
            $order->save();
            return $match;
        }

        if (!$match) {
            $order->update_meta_data('_patsacbr_lexware_reconciliation_checked', self::VERSION);
            $order->update_meta_data('_patsacbr_lexware_reconciliation_state', 'not_found');
            $order->delete_meta_data('_patsacbr_lexware_reconciliation_error');
            $order->save();
            return null;
        }

        $invoice_id = sanitize_text_field((string) ($match['id'] ?? ''));
        $invoice_number = sanitize_text_field((string) ($match['voucherNumber'] ?? ''));
        $invoice_status = sanitize_key((string) ($match['voucherStatus'] ?? ''));
        if (!$invoice_id || !$invoice_number) {
            return new WP_Error('invalid_reconciliation_match', __('Lexware returned incomplete invoice data during historical reconciliation.', 'patsch9-accounting-bridge'), array('retryable' => false));
        }

        $order->update_meta_data('_patsacbr_lexware_invoice_id', $invoice_id);
        $order->update_meta_data('_patsacbr_lexware_invoice_number', $invoice_number);
        $order->update_meta_data('_patsacbr_lexware_invoice_reconciled', 'yes');
        $order->update_meta_data('_patsacbr_lexware_reconciliation_checked', self::VERSION);
        $order->update_meta_data('_patsacbr_lexware_reconciliation_state', 'matched');
        $order->delete_meta_data('_patsacbr_lexware_reconciliation_error');
        if ('voided' === $invoice_status) {
            $order->update_meta_data('_patsacbr_lexware_invoice_voided', 'yes');
        } else {
            $order->delete_meta_data('_patsacbr_lexware_invoice_voided');
        }
        $order->save();

        if ($add_note) {
            $order->add_order_note(sprintf(
                /* translators: 1: Lexware invoice number, 2: Lexware invoice ID. */
                __('Existing Lexware invoice linked automatically: %1$s (ID: %2$s). The order number in the introduction and an identical total amount were used for matching.', 'patsch9-accounting-bridge'),
                $invoice_number,
                $invoice_id
            ));
        }

        return $match;
    }

    private function record_global_error($code, $message) {
        update_option('patsacbr_invoice_reconciliation_last_error', array(
            'code' => sanitize_key((string) $code),
            'message' => sanitize_text_field((string) $message),
            'time' => time(),
        ), false);
    }

    public function render_completion_notice() {
        if (!current_user_can('manage_woocommerce') || 'yes' !== get_option('patsacbr_invoice_reconciliation_notice_pending', 'no')) {
            return;
        }

        delete_option('patsacbr_invoice_reconciliation_notice_pending');
        $stats = get_option('patsacbr_invoice_reconciliation_stats', array());
        $stats = wp_parse_args(is_array($stats) ? $stats : array(), array(
            'matched' => 0,
            'not_found' => 0,
            'errors' => 0,
        ));

        printf(
            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: linked invoices, 2: orders without a match, 3: orders requiring review. */
                __('Historical Lexware invoice reconciliation completed: %1$d linked, %2$d without a match, %3$d requiring review.', 'patsch9-accounting-bridge'),
                absint($stats['matched']),
                absint($stats['not_found']),
                absint($stats['errors'])
            ))
        );
    }

    /**
     * Show reconciliation status and a nonce-protected manual restart control on
     * the Accounting Bridge settings page.
     */
    public function render_status_controls() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || 'woocommerce_page_patsacbr-settings' !== $screen->id) {
            return;
        }

        $stats = get_option('patsacbr_invoice_reconciliation_stats', array());
        $stats = wp_parse_args(is_array($stats) ? $stats : array(), array(
            'checked' => 0,
            'matched' => 0,
            'not_found' => 0,
            'skipped_existing' => 0,
            'errors' => 0,
        ));
        $status = sanitize_key((string) get_option('patsacbr_invoice_reconciliation_status', 'idle'));
        $page = max(1, absint(get_option('patsacbr_invoice_reconciliation_page', 1)));
        $max_pages = absint(get_option('patsacbr_invoice_reconciliation_max_pages', 0));
        $notice = isset($_GET['patsacbr_reconciliation_notice']) ? sanitize_key(wp_unslash($_GET['patsacbr_reconciliation_notice'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only status after nonce-protected redirect.
        $last_error = get_option('patsacbr_invoice_reconciliation_last_error', array());
        $last_error_message = is_array($last_error) && !empty($last_error['message']) ? sanitize_text_field((string) $last_error['message']) : '';

        $labels = array(
            'idle' => __('Not started yet', 'patsch9-accounting-bridge'),
            'scheduled' => __('Scheduled / waiting for background processing', 'patsch9-accounting-bridge'),
            'running' => __('Running', 'patsch9-accounting-bridge'),
            'completed' => __('Completed', 'patsch9-accounting-bridge'),
            'api_missing' => __('API key missing or invalid', 'patsch9-accounting-bridge'),
            'schedule_error' => __('The scheduler could not be started', 'patsch9-accounting-bridge'),
            'error' => __('Error', 'patsch9-accounting-bridge'),
        );
        $status_label = isset($labels[$status]) ? $labels[$status] : $status;

        echo '<div class="notice notice-info" style="padding-bottom:12px;">';
        echo '<p><strong>' . esc_html__('Historical Lexware invoice reconciliation', 'patsch9-accounting-bridge') . '</strong></p>';

        if ('started' === $notice) {
            echo '<p><strong>' . esc_html__('Invoice reconciliation has been restarted.', 'patsch9-accounting-bridge') . '</strong></p>';
        } elseif ('busy' === $notice) {
            echo '<p><strong>' . esc_html__('Invoice reconciliation is already running. A concurrent restart was not started for safety reasons.', 'patsch9-accounting-bridge') . '</strong></p>';
        } elseif ('error' === $notice) {
            echo '<p><strong>' . esc_html__('Invoice reconciliation could not be started. Please check the API configuration and error status.', 'patsch9-accounting-bridge') . '</strong></p>';
        }

        echo '<p>' . esc_html(sprintf(
            /* translators: 1: status label, 2: current page, 3: total pages or question mark. */
            __('Status: %1$s · Batch %2$d/%3$s', 'patsch9-accounting-bridge'),
            $status_label,
            $page,
            $max_pages ? (string) $max_pages : '?'
        )) . '</p>';
        echo '<p>' . esc_html(sprintf(
            /* translators: 1: checked orders, 2: matched invoices, 3: no matches, 4: errors, 5: already linked orders. */
            __('Checked: %1$d · Linked: %2$d · No match: %3$d · Review required/errors: %4$d · Already linked: %5$d', 'patsch9-accounting-bridge'),
            absint($stats['checked']),
            absint($stats['matched']),
            absint($stats['not_found']),
            absint($stats['errors']),
            absint($stats['skipped_existing'])
        )) . '</p>';

        if ($last_error_message) {
            echo '<p><strong>' . esc_html__('Last error:', 'patsch9-accounting-bridge') . '</strong> ' . esc_html($last_error_message) . '</p>';
        }

        echo '<p>' . esc_html__('A manual restart checks all current unlinked orders again. Existing Lexware invoice links are not changed.', 'patsch9-accounting-bridge') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="patsacbr_restart_invoice_reconciliation">';
        wp_nonce_field('patsacbr_restart_invoice_reconciliation');
        submit_button(__('Start / restart historical invoice reconciliation', 'patsch9-accounting-bridge'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';
    }
}
