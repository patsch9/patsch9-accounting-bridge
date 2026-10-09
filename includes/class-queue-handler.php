<?php
/**
 * Queue Handler
 * Serialisiert Lexware-Schreibvorgänge und verhindert parallele Doppelverarbeitung.
 */

if (!defined('ABSPATH')) {
    exit;
}

class PATSACBR_Queue_Handler {
    private static $instance = null;
    const GROUP = 'patsacbr-connector';
    const STALE_LOCK_MINUTES = 15;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('patsacbr_process_queue', array($this, 'process_queue'));
        add_action('action_scheduler_init', array($this, 'setup_scheduler'), 20);
        add_action('patsacbr_cleanup_scheduler', array($this, 'cleanup_scheduler'));
    }

    public function setup_scheduler() {
        if (!function_exists('as_schedule_recurring_action')) {
            $this->setup_fallback_cron();
            return;
        }
        if (!as_has_scheduled_action('patsacbr_process_queue', array(), self::GROUP)) {
            as_schedule_recurring_action(time() + 10, 60, 'patsacbr_process_queue', array(), self::GROUP, true);
        }
    }

    private function setup_fallback_cron() {
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        if (!wp_next_scheduled('patsacbr_process_queue')) {
            wp_schedule_event(time() + 10, 'patsacbr_every_minute', 'patsacbr_process_queue');
        }
    }

    public function add_cron_interval($schedules) {
        if (!isset($schedules['patsacbr_every_minute'])) {
            $schedules['patsacbr_every_minute'] = array(
                'interval' => MINUTE_IN_SECONDS,
                'display'  => esc_html__('Jede Minute', 'patsch9-accounting-bridge'),
            );
        }
        return $schedules;
    }

    public function cleanup_scheduler() {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('patsacbr_process_queue', array(), self::GROUP);
            // Migration from older versions that used the default group.
            as_unschedule_all_actions('patsacbr_process_queue');
        }
        wp_clear_scheduled_hook('patsacbr_process_queue');
    }

    private static function allowed_actions() {
        return array('create_invoice', 'void_invoice', 'update_invoice');
    }

    private static function dedupe_key($order_id, $action) {
        return absint($order_id) . ':' . sanitize_key($action);
    }

    public static function add_to_queue($order_id, $action) {
        global $wpdb;
        $order_id = absint($order_id);
        $action   = sanitize_key($action);
        if (!$order_id || !in_array($action, self::allowed_actions(), true) || !wc_get_order($order_id)) {
            return false;
        }
        $table = $wpdb->prefix . 'patsacbr_queue';

        // Compatibility guard for active rows created before dedupe_key existed.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
        $active = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE order_id = %d AND action = %s AND status IN ('pending','processing','manual_check') LIMIT 1",
            $table,
            $order_id,
            $action
        ));
        if ($active) {
            return absint($active);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
        $inserted = $wpdb->insert(
            $table,
            array(
                'order_id'        => $order_id,
                'action'          => $action,
                'status'          => 'pending',
                'dedupe_key'      => self::dedupe_key($order_id, $action),
                'attempts'        => 0,
                'error_message'   => null,
                'locked_at'       => null,
                'next_attempt_at' => null,
                'created_at'      => current_time('mysql'),
                'updated_at'      => current_time('mysql'),
            ),
            array('%d','%s','%s','%s','%d','%s','%s','%s','%s','%s')
        );
        return $inserted ? absint($wpdb->insert_id) : false;
    }

    private function recover_stale_items() {
        global $wpdb;
        $table = $wpdb->prefix . 'patsacbr_queue';
        $cutoff = wp_date('Y-m-d H:i:s', time() - self::STALE_LOCK_MINUTES * MINUTE_IN_SECONDS, wp_timezone());

        // Every queue action can cause a remote write. If a PHP worker dies after
        // Lexware accepted the request but before WordPress persisted the result,
        // replaying the item could create a duplicate voucher. Therefore stale
        // processing items fail closed and require a manual Lexware check.
        $message = esc_html__('Verarbeitung wurde unerwartet unterbrochen. Aus Schutz vor Doppelbelegen wird dieser Schreibvorgang nicht automatisch wiederholt. Bitte zuerst in Lexware prüfen und den Beleg bei Bedarf manuell verknüpfen.', 'patsch9-accounting-bridge');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status='manual_check', error_message=%s, locked_at=NULL, next_attempt_at=NULL, updated_at=%s WHERE status='processing' AND locked_at IS NOT NULL AND locked_at < %s",
            $table,
            $message,
            current_time('mysql'),
            $cutoff
        ));
    }

    private function claim_next_item() {
        global $wpdb;
        $this->recover_stale_items();
        $table = $wpdb->prefix . 'patsacbr_queue';
        $max_attempts = max(1, min(10, absint(get_option('patsacbr_retry_attempts', 3))));
        $now = current_time('mysql');

        // Several candidates are tried because another worker may claim one between SELECT and UPDATE.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM %i WHERE status='pending' AND attempts < %d AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY created_at ASC, id ASC LIMIT 10",
            $table,
            $max_attempts,
            $now
        ));
        foreach ($ids as $id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE %i SET status='processing', locked_at=%s, attempts=attempts+1, updated_at=%s WHERE id=%d AND status='pending' AND attempts < %d AND (next_attempt_at IS NULL OR next_attempt_at <= %s)",
                $table,
                $now,
                $now,
                absint($id),
                $max_attempts,
                $now
            ));
            if (1 === (int) $updated) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
                return $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id=%d", $table, absint($id)));
            }
        }
        return null;
    }

    private function claim_specific_item($order_id, $action) {
        global $wpdb;
        $this->recover_stale_items();
        $order_id = absint($order_id);
        $action = sanitize_key($action);
        if (!$order_id || !in_array($action, self::allowed_actions(), true)) {
            return new WP_Error('invalid_queue_target', esc_html__('Ungültige Queue-Aktion.', 'patsch9-accounting-bridge'));
        }
        $table = $wpdb->prefix . 'patsacbr_queue';
        $max_attempts = max(1, min(10, absint(get_option('patsacbr_retry_attempts', 3))));
        $now = current_time('mysql');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE order_id=%d AND action=%s AND status='pending' AND attempts < %d AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY id ASC LIMIT 1",
            $table,
            $order_id,
            $action,
            $max_attempts,
            $now
        ));
        if (!$id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
            $manual_check = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM %i WHERE order_id=%d AND action=%s AND status='manual_check' LIMIT 1",
                $table,
                $order_id,
                $action
            ));
            if ($manual_check) {
                return new WP_Error('queue_manual_check', esc_html__('Dieser Lexware-Schreibvorgang ist nach einem unklaren Übertragungszustand gesperrt. Bitte zuerst in Lexware prüfen und die Sperre unter WooCommerce > Accounting Bridge > Logs & Queue ausdrücklich freigeben.', 'patsch9-accounting-bridge'));
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
            $processing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM %i WHERE order_id=%d AND action=%s AND status='processing' LIMIT 1",
                $table,
                $order_id,
                $action
            ));
            if ($processing) {
                return new WP_Error('queue_busy', esc_html__('Diese Lexware-Aktion wird bereits verarbeitet.', 'patsch9-accounting-bridge'));
            }
            return new WP_Error('queue_not_due', esc_html__('Für diese Bestellung ist aktuell kein fälliges Queue-Item vorhanden.', 'patsch9-accounting-bridge'));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status='processing', locked_at=%s, attempts=attempts+1, updated_at=%s WHERE id=%d AND status='pending' AND attempts < %d AND (next_attempt_at IS NULL OR next_attempt_at <= %s)",
            $table,
            $now,
            $now,
            absint($id),
            $max_attempts,
            $now
        ));
        if (1 !== (int)$updated) {
            return new WP_Error('queue_race', esc_html__('Die Lexware-Aktion wurde parallel von einem anderen Prozess übernommen.', 'patsch9-accounting-bridge'));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id=%d", $table, absint($id)));
    }

    public function process_queue() {
        $item = $this->claim_next_item();
        if ($item) {
            $this->process_item($item);
        }
    }

    public static function process_order_action($order_id, $action) {
        $instance = self::get_instance();
        $item = $instance->claim_specific_item($order_id, $action);
        if (is_wp_error($item)) {
            return $item;
        }
        return $instance->process_item($item);
    }

    public static function process_next_item() {
        $instance = self::get_instance();
        $item = $instance->claim_next_item();
        if (!$item) {
            return new WP_Error('no_items', esc_html__('Keine fälligen Items in der Queue', 'patsch9-accounting-bridge'));
        }
        return $instance->process_item($item);
    }

    private function process_item($item) {
        $order = wc_get_order(absint($item->order_id));
        if (!$order) {
            $this->mark_as_failed($item, esc_html__('Bestellung nicht gefunden', 'patsch9-accounting-bridge'), false);
            return new WP_Error('order_not_found', esc_html__('Bestellung nicht gefunden', 'patsch9-accounting-bridge'));
        }
        if (!in_array($item->action, self::allowed_actions(), true)) {
            $this->mark_as_failed($item, esc_html__('Unbekannte Queue-Aktion', 'patsch9-accounting-bridge'), false);
            return new WP_Error('invalid_action', esc_html__('Unbekannte Queue-Aktion', 'patsch9-accounting-bridge'));
        }

        $api_client = new PATSACBR_API_Client();
        switch ($item->action) {
            case 'create_invoice':
                $result = $this->handle_create_invoice($order, $api_client);
                break;
            case 'void_invoice':
                $result = $this->handle_void_invoice($order, $api_client);
                break;
            case 'update_invoice':
                $result = $this->handle_update_invoice($order, $api_client);
                break;
            default:
                $result = new WP_Error('invalid_action', esc_html__('Unbekannte Queue-Aktion', 'patsch9-accounting-bridge'));
        }

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $retryable = is_array($data) && !empty($data['retryable']);
            $ambiguous = is_array($data) && !empty($data['ambiguous_write']);
            $manual_check = is_array($data) && !empty($data['manual_check']);
            $this->mark_as_failed($item, $result->get_error_message(), $retryable, ($ambiguous || $manual_check));
            return $result;
        }

        if (is_array($result) && !empty($result['skipped'])) {
            $this->mark_as_completed($item->id, '');
            return true;
        }

        $result_id = is_array($result) && isset($result['id']) ? $result['id'] : (string) $result;
        $this->mark_as_completed($item->id, $result_id);

        if ('create_invoice' === $item->action) {
            // Reload because the order status may have changed while the remote POST
            // was in flight. A concurrent cancellation must not leave a finalized
            // invoice behind without a compensating credit note.
            $fresh_order = wc_get_order($order->get_id());
            if ($fresh_order && $fresh_order->has_status(array('cancelled', 'refunded', 'failed', 'trash'))) {
                if ($fresh_order->get_meta('_patsacbr_lexware_invoice_id') && 'yes' !== $fresh_order->get_meta('_patsacbr_lexware_invoice_voided')) {
                    self::add_to_queue($fresh_order->get_id(), 'void_invoice');
                    $fresh_order->add_order_note(esc_html__('Lexware: Bestellung wurde während/nach der Rechnungserstellung beendet; Gutschrift wurde automatisch vorgemerkt.', 'patsch9-accounting-bridge'));
                }
                return true;
            }
            if ('yes' === get_option('patsacbr_auto_send_email', 'no')) {
                $this->send_invoice_email($fresh_order ?: $order);
            }
        }
        return true;
    }

    private function handle_create_invoice($order, $api_client) {
        // A stale automatic queue item must never create a new invoice for an order
        // which has already reached a terminal state before processing starts.
        $existing = $order->get_meta('_patsacbr_lexware_invoice_id');
        if ($order->has_status(array('cancelled', 'refunded', 'failed', 'trash'))) {
            if ($existing && 'yes' !== $order->get_meta('_patsacbr_lexware_invoice_voided')) {
                self::add_to_queue($order->get_id(), 'void_invoice');
            }
            $order->add_order_note(esc_html__('Lexware: Rechnungserstellung übersprungen, da die Bestellung bereits beendet/storniert ist.', 'patsch9-accounting-bridge'));
            return array('skipped' => true, 'reason' => 'terminal_order_status');
        }

        // Strong idempotency guard inside WordPress: if a previous request already stored an ID, never create again.
        if ($existing) {
            return array('id' => $existing);
        }

        // Historical orders may already have an invoice in Lexware from before
        // this plugin managed the order. Reconcile read-only before any remote POST.
        if (class_exists('PATSACBR_Invoice_Reconciler')) {
            $reconciled = PATSACBR_Invoice_Reconciler::get_instance()->reconcile_before_create($order, $api_client);
            if (is_wp_error($reconciled)) {
                return $reconciled;
            }
            if (is_array($reconciled) && !empty($reconciled['id'])) {
                return array(
                    'skipped' => true,
                    'reason'  => 'existing_invoice_reconciled',
                    'id'      => sanitize_text_field((string) $reconciled['id']),
                );
            }
        }

        $contact_id = null;
        if ('yes' === get_option('patsacbr_auto_sync_contacts', 'yes')) {
            $contact_result = $api_client->sync_contact($order);
            if (is_wp_error($contact_result)) {
                return $contact_result;
            }
            $contact_id = isset($contact_result['id']) ? $contact_result['id'] : null;
        }
        return $api_client->create_invoice($order, $contact_id);
    }

    private function handle_void_invoice($order, $api_client) {
        $invoice_id = sanitize_text_field((string)$order->get_meta('_patsacbr_lexware_invoice_id'));
        if (!$invoice_id) {
            return new WP_Error('no_invoice', esc_html__('Keine Rechnung vorhanden', 'patsch9-accounting-bridge'));
        }
        return $api_client->create_credit_note($order, $invoice_id);
    }

    private function handle_update_invoice($order, $api_client) {
        // If a previous attempt already credited the old invoice but failed while
        // creating the replacement, continue with creation instead of crediting again.
        $pending_source = sanitize_text_field((string)$order->get_meta('_patsacbr_lexware_update_source_invoice_id'));
        $current_invoice = sanitize_text_field((string)$order->get_meta('_patsacbr_lexware_invoice_id'));
        if ($pending_source && !$current_invoice) {
            $created = $this->handle_create_invoice($order, $api_client);
            if (!is_wp_error($created)) {
                $order->delete_meta_data('_patsacbr_lexware_update_source_invoice_id');
                $order->save();
            }
            return $created;
        }
        if (!$current_invoice) {
            return new WP_Error('no_invoice', esc_html__('Keine Rechnung für die Aktualisierung vorhanden.', 'patsch9-accounting-bridge'));
        }

        $void = $this->handle_void_invoice($order, $api_client);
        if (is_wp_error($void)) {
            return $void;
        }
        $credit_id = sanitize_text_field((string)$order->get_meta('_patsacbr_lexware_credit_note_id'));
        $history = $order->get_meta('_patsacbr_lexware_credit_note_history');
        $history = is_array($history) ? $history : array();
        if ($credit_id) {
            $history[] = array(
                'invoice_id'     => $current_invoice,
                'credit_note_id' => $credit_id,
                'created_at'     => current_time('mysql'),
            );
            $history = array_slice($history, -50);
            $order->update_meta_data('_patsacbr_lexware_credit_note_history', $history);
        }
        $order->update_meta_data('_patsacbr_lexware_update_source_invoice_id', $current_invoice);
        $order->delete_meta_data('_patsacbr_lexware_invoice_id');
        $order->delete_meta_data('_patsacbr_lexware_invoice_number');
        $order->delete_meta_data('_patsacbr_lexware_invoice_voided');
        $order->delete_meta_data('_patsacbr_lexware_credit_note_id');
        $order->delete_meta_data('_patsacbr_lexware_credit_note_for_invoice_id');
        $order->save();

        $created = $this->handle_create_invoice($order, $api_client);
        if (!is_wp_error($created)) {
            $order->delete_meta_data('_patsacbr_lexware_update_source_invoice_id');
            $order->save();
        }
        return $created;
    }

    private function send_invoice_email($order) {
        if (!function_exists('WC')) {
            return false;
        }
        $emails = WC()->mailer()->get_emails();
        if (isset($emails['PATSACBR_Invoice_Email'])) {
            $sent = $emails['PATSACBR_Invoice_Email']->trigger($order->get_id(), $order);
            if ($sent) {
                $order->add_order_note(esc_html__('Rechnung automatisch per E-Mail versendet', 'patsch9-accounting-bridge'));
                return true;
            }
            $order->add_order_note(esc_html__('Automatischer Rechnungsversand fehlgeschlagen. Rechnung wurde in Lexware erstellt; bitte E-Mail/Datei prüfen.', 'patsch9-accounting-bridge'));
        }
        return false;
    }

    private function mark_as_completed($item_id, $result_id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
        $wpdb->update(
            $wpdb->prefix . 'patsacbr_queue',
            array(
                'status'             => 'completed',
                'dedupe_key'         => null,
                'lexware_invoice_id' => sanitize_text_field((string) $result_id),
                'error_message'      => null,
                'locked_at'          => null,
                'next_attempt_at'    => null,
                'updated_at'         => current_time('mysql'),
            ),
            array('id' => absint($item_id)),
            array('%s','%s','%s','%s','%s','%s','%s'),
            array('%d')
        );
    }

    private function mark_as_failed($item, $error_message, $retryable, $ambiguous = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'patsacbr_queue';
        $max_attempts = max(1, min(10, absint(get_option('patsacbr_retry_attempts', 3))));
        $attempts = max(1, absint($item->attempts));
        $retry = !$ambiguous && $retryable && $attempts < $max_attempts;
        $manual_check = (bool) $ambiguous;
        $safe_error = wp_strip_all_tags(sanitize_text_field((string) $error_message));
        if (function_exists('mb_substr')) {
            $safe_error = mb_substr($safe_error, 0, 1000);
        } else {
            $safe_error = substr($safe_error, 0, 1000);
        }
        $next = null;
        if ($retry) {
            $delay = min(HOUR_IN_SECONDS, 30 * (2 ** max(0, $attempts - 1)));
            $next = wp_date('Y-m-d H:i:s', time() + $delay, wp_timezone());
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
        $wpdb->update(
            $table,
            array(
                'status'          => $manual_check ? 'manual_check' : ($retry ? 'pending' : 'failed'),
                'dedupe_key'      => ($manual_check || $retry) ? self::dedupe_key($item->order_id, $item->action) : null,
                'error_message'   => $safe_error,
                'locked_at'       => null,
                'next_attempt_at' => $next,
                'updated_at'      => current_time('mysql'),
            ),
            array('id' => absint($item->id)),
            array('%s','%s','%s','%s','%s','%s'),
            array('%d')
        );
    }

    public static function release_manual_check($item_id) {
        global $wpdb;
        $item_id = absint($item_id);
        if (!$item_id) {
            return false;
        }
        $table = $wpdb->prefix . 'patsacbr_queue';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
        $updated = $wpdb->update(
            $table,
            array(
                'status'          => 'failed',
                'dedupe_key'      => null,
                'locked_at'       => null,
                'next_attempt_at' => null,
                'updated_at'      => current_time('mysql'),
            ),
            array('id' => $item_id, 'status' => 'manual_check'),
            array('%s','%s','%s','%s','%s'),
            array('%d','%s')
        );
        return 1 === (int) $updated;
    }

    public static function get_queue_status() {
        global $wpdb;
        $table = $wpdb->prefix . 'patsacbr_queue';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional queue state must be read/written fresh for deduplication and atomic worker claiming; WordPress provides no CRUD API for this table.
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE status IN ('pending','processing','failed','manual_check') ORDER BY created_at DESC LIMIT 50",
                $table
            )
        );
    }

    public static function get_scheduler_status() {
        $status = array('type' => 'unknown', 'scheduled' => false, 'next_run' => null);
        if (function_exists('as_next_scheduled_action')) {
            $next = as_next_scheduled_action('patsacbr_process_queue', array(), self::GROUP);
            if ($next) {
                $status = array('type' => 'action_scheduler', 'scheduled' => true, 'next_run' => wp_date('Y-m-d H:i:s', $next));
            }
        }
        if (!$status['scheduled']) {
            $next = wp_next_scheduled('patsacbr_process_queue');
            if ($next) {
                $status = array('type' => 'wp_cron', 'scheduled' => true, 'next_run' => wp_date('Y-m-d H:i:s', $next));
            }
        }
        return $status;
    }
}
