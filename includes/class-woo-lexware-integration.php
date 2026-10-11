<?php
/**
 * WooCommerce Integration
 * Handhabt alle WooCommerce Hooks und Events
 */

if (!defined('ABSPATH')) {
    exit;
}

class PATSACBR_WooCommerce_Integration {
    private static $instance = null;
    private $api_client;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api_client = new PATSACBR_API_Client();
        $this->register_order_hooks();
        $this->register_admin_hooks();
    }

    private function register_order_hooks() {
        $trigger_statuses = get_option('patsacbr_order_statuses', array('wc-completed', 'wc-processing'));
        foreach ($trigger_statuses as $status) {
            $clean_status = str_replace('wc-', '', $status);
            add_action('woocommerce_order_status_' . $clean_status, array($this, 'handle_order_completed'), 10, 2);
        }
        add_action('woocommerce_order_status_cancelled', array($this, 'handle_order_cancelled'), 10, 2);
        add_action('woocommerce_order_status_refunded', array($this, 'handle_order_refunded'), 10, 2);
        // ACHTUNG: Automatisches Update deaktiviert
        // add_action('woocommerce_saved_order_items', array($this, 'handle_order_items_changed'), 10, 2);
    }

    private function register_admin_hooks() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_order_admin_assets'));
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));
        add_action('save_post_shop_order', array($this, 'save_manual_invoice_data'), 10, 1);
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_manual_invoice_data'), 10, 1);
        add_filter('bulk_actions-edit-shop_order', array($this, 'add_bulk_action'));
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_action'), 10, 3);
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_order_column'), 10, 2);

        // HPOS order-list equivalents. WooCommerce uses a different screen ID
        // when custom order tables are authoritative.
        add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'add_bulk_action'));
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'handle_bulk_action'), 10, 3);
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_order_column'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_order_column_hpos'), 10, 2);
    }

    public function handle_order_completed($order_id, $order = null) {
        if (!$order) { $order = wc_get_order($order_id); }
        if (!$order) { return; }
        $lexware_invoice_id = $order->get_meta('_patsacbr_lexware_invoice_id');
        if ($lexware_invoice_id) {
            $order->add_order_note(esc_html__('Lexware invoice already exists', 'patsch9-accounting-bridge'));
            return;
        }
        PATSACBR_Queue_Handler::add_to_queue($order_id, 'create_invoice');
    }

    public function handle_order_cancelled($order_id, $order = null) {
        if (!$order) { $order = wc_get_order($order_id); }
        if (!$order) { return; }
        $lexware_invoice_id = $order->get_meta('_patsacbr_lexware_invoice_id');
        $already_voided = $order->get_meta('_patsacbr_lexware_invoice_voided');
        if (!$lexware_invoice_id || $already_voided === 'yes') { return; }
        PATSACBR_Queue_Handler::add_to_queue($order_id, 'void_invoice');
    }

    public function handle_order_refunded($order_id, $order = null) {
        $this->handle_order_cancelled($order_id, $order);
    }

    // keine automatische Aktualisierung mehr
    // public function handle_order_items_changed($order_id, $items) {...}

    public function enqueue_order_admin_assets($hook_suffix) {
        unset($hook_suffix);
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || ('shop_order' !== $screen->post_type && 'woocommerce_page_wc-orders' !== $screen->id)) {
            return;
        }

        wp_enqueue_style('patsacbr-order-admin', PATSACBR_PLUGIN_URL . 'admin/css/admin-style.css', array(), PATSACBR_VERSION);
        wp_enqueue_script('patsacbr-order-metabox', PATSACBR_PLUGIN_URL . 'admin/js/order-metabox.js', array('jquery'), PATSACBR_VERSION, true);
        wp_localize_script(
            'patsacbr-order-metabox',
            'patsacbrOrderMetabox',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('patsacbr_manual_action'),
                'strings' => array(
                    'confirmCreate' => __('Create an invoice for this order now?', 'patsch9-accounting-bridge'),
                    'creating' => __('Creating...', 'patsch9-accounting-bridge'),
                    'createLabel' => __('✨ Create invoice now', 'patsch9-accounting-bridge'),
                    'createError' => __('Error creating the invoice', 'patsch9-accounting-bridge'),
                    'confirmVoid' => __('Really cancel the invoice?', 'patsch9-accounting-bridge'),
                    'voiding' => __('Cancelling...', 'patsch9-accounting-bridge'),
                    'voidLabel' => __('Cancel invoice', 'patsch9-accounting-bridge'),
                    'voidError' => __('Error cancelling the invoice', 'patsch9-accounting-bridge'),
                    'confirmSend' => __('Email the invoice to the customer now?', 'patsch9-accounting-bridge'),
                    'sending' => __('Sending...', 'patsch9-accounting-bridge'),
                    'sendLabel' => __('📧 Email invoice', 'patsch9-accounting-bridge'),
                    'sendError' => __('Error sending the email', 'patsch9-accounting-bridge'),
                    'sendSuccess' => __('Email sent', 'patsch9-accounting-bridge'),
                    'confirmUpdate' => __('Update the invoice? The existing finalized invoice will be corrected in Lexware using a linked credit note and then recreated. This automatic correction is not available for invoice drafts.', 'patsch9-accounting-bridge'),
                    'updating' => __('Updating...', 'patsch9-accounting-bridge'),
                    'updateLabel' => __('🔄 Update invoice', 'patsch9-accounting-bridge'),
                    'updateError' => __('Error updating the invoice', 'patsch9-accounting-bridge'),
                    'confirmUnlink' => __('Really remove the link to the Lexware invoice?

The invoice will remain in Lexware, but you will be able to create a new invoice for this order.', 'patsch9-accounting-bridge'),
                    'unlinking' => __('Removing...', 'patsch9-accounting-bridge'),
                    'unlinkLabel' => __('🔗 Remove link', 'patsch9-accounting-bridge'),
                    'unlinkError' => __('Error removing the link', 'patsch9-accounting-bridge'),
                ),
            )
        );
    }

    public function add_order_metabox() {
        add_meta_box(
            'patsacbr_lexware_info',
            esc_html__('Lexware invoice', 'patsch9-accounting-bridge'),
            array($this, 'render_order_metabox'),
            'shop_order',
            'side',
            'default'
        );
        add_meta_box(
            'patsacbr_lexware_info',
            esc_html__('Lexware invoice', 'patsch9-accounting-bridge'),
            array($this, 'render_order_metabox'),
            'woocommerce_page_wc-orders',
            'side',
            'default'
        );
    }

    /**
     * Speichert manuell eingegebene Rechnungsdaten
     */
    public function save_manual_invoice_data($order_id) {
        // Sicherheitsprüfung
        if (!isset($_POST['patsacbr_manual_invoice_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['patsacbr_manual_invoice_nonce'])), 'patsacbr_save_manual_invoice_' . $order_id)) {
            return;
        }

        // Berechtigungsprüfung
        if (!current_user_can('edit_shop_orders')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Prüfe ob bereits eine Rechnung existiert
        $existing_invoice_id = $order->get_meta('_patsacbr_lexware_invoice_id');
        if ($existing_invoice_id) {
            return; // Keine Änderung wenn bereits eine Rechnung verknüpft ist
        }

        // Hole die manuellen Eingaben
        $manual_invoice_id = isset($_POST['patsacbr_manual_invoice_id']) ?
            sanitize_text_field(wp_unslash($_POST['patsacbr_manual_invoice_id'])) : '';
        $manual_invoice_number = isset($_POST['patsacbr_manual_invoice_number']) ?
            sanitize_text_field(wp_unslash($_POST['patsacbr_manual_invoice_number'])) : '';

        // Lexware resource IDs are UUIDs. Reject malformed manual links so a
        // typo cannot poison later download/correction operations.
        $valid_uuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $manual_invoice_id);

        // Speichere nur wenn beide Felder ausgefüllt und die ID plausibel ist.
        if (!empty($manual_invoice_id) && !empty($manual_invoice_number) && $valid_uuid) {
            $order->update_meta_data('_patsacbr_lexware_invoice_id', $manual_invoice_id);
            $order->update_meta_data('_patsacbr_lexware_invoice_number', $manual_invoice_number);
            $order->update_meta_data('_patsacbr_manual_invoice_entry', 'yes');
            $order->save();

            $order->add_order_note(
                sprintf(
                    /* translators: 1: Lexware invoice ID, 2: Invoice number */
                    esc_html__('Invoice data entered manually: ID %1$s, number %2$s', 'patsch9-accounting-bridge'),
                    $manual_invoice_id,
                    $manual_invoice_number
                )
            );
        } elseif (!empty($manual_invoice_id) || !empty($manual_invoice_number)) {
            $order->add_order_note(esc_html__('Manual Lexware link not saved: Please provide a valid Lexware UUID and a complete invoice number.', 'patsch9-accounting-bridge'));
        }
    }

    public function render_order_metabox($post_or_order) {
        if (is_a($post_or_order, 'WP_Post')) {
            $order = wc_get_order($post_or_order->ID);
            $order_id = $post_or_order->ID;
        } else {
            $order = $post_or_order;
            $order_id = is_a($order, 'WC_Order') ? $order->get_id() : 0;
        }
        if (!is_a($order, 'WC_Order') || !$order_id) {
            return;
        }

        $lexware_invoice_id = $order->get_meta('_patsacbr_lexware_invoice_id');
        $lexware_invoice_number = $order->get_meta('_patsacbr_lexware_invoice_number');
        $lexware_credit_note_id = $order->get_meta('_patsacbr_lexware_credit_note_id');
        $invoice_voided = $order->get_meta('_patsacbr_lexware_invoice_voided');
        $manual_entry = $order->get_meta('_patsacbr_manual_invoice_entry');
        $reconciled_entry = $order->get_meta('_patsacbr_lexware_invoice_reconciled');

        wp_nonce_field('patsacbr_save_manual_invoice_' . $order_id, 'patsacbr_manual_invoice_nonce');
        ?>
        <div class="patsacbr-metabox">
            <?php if ($lexware_invoice_id): ?>
                <p><strong><?php esc_html_e('Invoice number:', 'patsch9-accounting-bridge'); ?></strong><br><?php echo esc_html($lexware_invoice_number ?: '–'); ?></p>
                <p><strong><?php esc_html_e('Lexware ID:', 'patsch9-accounting-bridge'); ?></strong><br><code><?php echo esc_html($lexware_invoice_id); ?></code></p>
                <?php if ($manual_entry === 'yes'): ?>
                    <p><em style="font-size: 11px; color: #666;"><?php esc_html_e('(Entered manually)', 'patsch9-accounting-bridge'); ?></em></p>
                <?php elseif ($reconciled_entry === 'yes'): ?>
                    <p><em style="font-size: 11px; color: #666;"><?php esc_html_e('(Retrieved automatically from Lexware)', 'patsch9-accounting-bridge'); ?></em></p>
                <?php endif; ?>
                <p><strong><?php esc_html_e('Status:', 'patsch9-accounting-bridge'); ?></strong><br><?php
                if ($invoice_voided === 'yes') {
                    echo '<span style="color: red;">' . esc_html__('Cancelled', 'patsch9-accounting-bridge') . '</span>';
                } else {
                    echo '<span style="color: green;">' . esc_html__('Active', 'patsch9-accounting-bridge') . '</span>';
                }
                ?></p>
                <?php if ($lexware_credit_note_id): ?>
                    <p><strong><?php esc_html_e('Credit note ID:', 'patsch9-accounting-bridge'); ?></strong><br><code><?php echo esc_html($lexware_credit_note_id); ?></code></p>
                <?php endif; ?>

                <p><a href="https://app.lexware.de/permalink/invoices/view/<?php echo esc_attr(rawurlencode($lexware_invoice_id)); ?>" target="_blank" class="button button-secondary"><?php esc_html_e('Open in Lexware', 'patsch9-accounting-bridge'); ?></a></p>
                <p><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=patsacbr_download_invoice_pdf&order_id=' . $order_id), 'patsacbr_download_pdf_' . $order_id)); ?>" class="button button-primary" target="_blank"><?php esc_html_e('📄 Download invoice document', 'patsch9-accounting-bridge'); ?></a></p>

                <?php if ($invoice_voided !== 'yes'): ?>
                    <p><button type="button" class="button button-secondary patsacbr-void-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('Cancel invoice', 'patsch9-accounting-bridge'); ?></button></p>
                    <p><button type="button" class="button button-secondary patsacbr-send-invoice-email" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('📧 Email invoice', 'patsch9-accounting-bridge'); ?></button></p>
                    <p><button type="button" class="button button-secondary patsacbr-update-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('🔄 Update invoice', 'patsch9-accounting-bridge'); ?></button></p>
                <?php endif; ?>

                <p><button type="button" class="button button-secondary patsacbr-unlink-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('🔗 Remove link', 'patsch9-accounting-bridge'); ?></button></p>
                <p style="font-size: 11px; color: #666;">
                    <?php esc_html_e('Removes only the link to the invoice, not the invoice itself in Lexware.', 'patsch9-accounting-bridge'); ?>
                </p>

            <?php else: ?>
                <p><?php esc_html_e('No invoice created yet.', 'patsch9-accounting-bridge'); ?></p>

                <div class="patsacbr-manual-invoice-fields" style="margin: 15px 0; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                    <p style="margin-top: 0; font-weight: bold;"><?php esc_html_e('Manual entry:', 'patsch9-accounting-bridge'); ?></p>
                    <p style="margin-bottom: 8px;">
                        <label for="patsacbr_manual_invoice_id" style="display: block; margin-bottom: 4px; font-size: 12px;">
                            <?php esc_html_e('Lexware invoice ID:', 'patsch9-accounting-bridge'); ?>
                        </label>
                        <input type="text"
                               id="patsacbr_manual_invoice_id"
                               name="patsacbr_manual_invoice_id"
                               class="widefat"
                               placeholder="z.B. 12345678-1234-1234-1234-123456789012"
                               style="font-size: 12px;">
                    </p>
                    <p style="margin-bottom: 0;">
                        <label for="patsacbr_manual_invoice_number" style="display: block; margin-bottom: 4px; font-size: 12px;">
                            <?php esc_html_e('Invoice number:', 'patsch9-accounting-bridge'); ?>
                        </label>
                        <input type="text"
                               id="patsacbr_manual_invoice_number"
                               name="patsacbr_manual_invoice_number"
                               class="widefat"
                               placeholder="z.B. RE-2024-001"
                               style="font-size: 12px;">
                    </p>
                    <p style="font-size: 11px; color: #666; margin-bottom: 0;">
                        <?php esc_html_e('Complete both fields and save the order to create a manual link.', 'patsch9-accounting-bridge'); ?>
                    </p>
                </div>

                <p style="text-align: center; margin: 10px 0; color: #666; font-size: 11px;">
                    <?php esc_html_e('— or —', 'patsch9-accounting-bridge'); ?>
                </p>

                <p><button type="button" class="button button-primary patsacbr-create-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('✨ Create invoice now', 'patsch9-accounting-bridge'); ?></button></p>
            <?php endif; ?>
        </div>
<?php
    }

    public function add_bulk_action($actions) {
        $actions['patsacbr_create_invoices'] = esc_html__('Create Lexware invoices', 'patsch9-accounting-bridge');
        return $actions;
    }

    public function handle_bulk_action($redirect_to, $action, $post_ids) {
        if ($action !== 'patsacbr_create_invoices') {
            return $redirect_to;
        }
        if (!current_user_can('manage_woocommerce')) {
            return $redirect_to;
        }
        $created = 0;
        foreach ((array) $post_ids as $post_id) {
            $post_id = absint($post_id);
            if (!$post_id) { continue; }
            $order = wc_get_order($post_id);
            if (!$order) { continue; }
            if ($order->get_meta('_patsacbr_lexware_invoice_id')) { continue; }
            PATSACBR_Queue_Handler::add_to_queue($post_id, 'create_invoice');
            $created++;
        }
        $redirect_to = add_query_arg('patsacbr_invoices_created', $created, $redirect_to);
        return $redirect_to;
    }

    public function add_order_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'order_total') {
                $new_columns['patsacbr_lexware'] = esc_html__('Lexware', 'patsch9-accounting-bridge');
            }
        }
        return $new_columns;
    }

    public function render_order_column($column, $post_id) {
        if ($column !== 'patsacbr_lexware') {
            return;
        }
        $this->render_order_column_value(wc_get_order(absint($post_id)));
    }

    public function render_order_column_hpos($column, $order) {
        if ($column !== 'patsacbr_lexware') {
            return;
        }
        if (!is_a($order, 'WC_Order')) {
            $order = wc_get_order(absint($order));
        }
        $this->render_order_column_value($order);
    }

    private function render_order_column_value($order) {
        if (!is_a($order, 'WC_Order')) {
            echo '<span style="color: gray;">–</span>';
            return;
        }
        $lexware_invoice_id = $order->get_meta('_patsacbr_lexware_invoice_id');
        $invoice_voided = $order->get_meta('_patsacbr_lexware_invoice_voided');
        if ($lexware_invoice_id) {
            if ($invoice_voided === 'yes') {
                echo '<span style="color: red;">✗ ' . esc_html__('Cancelled', 'patsch9-accounting-bridge') . '</span>';
            } else {
                echo '<span style="color: green;">✓ ' . esc_html__('Created', 'patsch9-accounting-bridge') . '</span>';
            }
        } else {
            echo '<span style="color: gray;">– ' . esc_html__('None', 'patsch9-accounting-bridge') . '</span>';
        }
    }
}

add_action('wp_ajax_patsacbr_manual_create_invoice', 'patsacbr_ajax_manual_create_invoice');
function patsacbr_ajax_manual_create_invoice() {
    check_ajax_referer('patsacbr_manual_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Permission denied', 'patsch9-accounting-bridge')));
    }
    if (class_exists('PATSACBR_Security') && !PATSACBR_Security::check_rate_limit('manual_create_invoice', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Too many requests. Please wait.', 'patsch9-accounting-bridge')));
    }
    $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Order not found', 'patsch9-accounting-bridge')));
    }

    if ($order->get_meta('_patsacbr_lexware_invoice_id')) {
        wp_send_json_error(array('message' => esc_html__('A Lexware invoice is already linked to this order. Use "Update invoice" or cancel the existing invoice.', 'patsch9-accounting-bridge')));
    }
    PATSACBR_Queue_Handler::add_to_queue($order_id, 'create_invoice');
    $result = PATSACBR_Queue_Handler::process_order_action($order_id, 'create_invoice');

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array('message' => esc_html__('Invoice created', 'patsch9-accounting-bridge')));
}

add_action('wp_ajax_patsacbr_manual_void_invoice', 'patsacbr_ajax_manual_void_invoice');
function patsacbr_ajax_manual_void_invoice() {
    check_ajax_referer('patsacbr_manual_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Permission denied', 'patsch9-accounting-bridge')));
    }
    if (class_exists('PATSACBR_Security') && !PATSACBR_Security::check_rate_limit('manual_void_invoice', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Too many requests. Please wait.', 'patsch9-accounting-bridge')));
    }
    $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Order not found', 'patsch9-accounting-bridge')));
    }

    PATSACBR_Queue_Handler::add_to_queue($order_id, 'void_invoice');
    $result = PATSACBR_Queue_Handler::process_order_action($order_id, 'void_invoice');

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array('message' => esc_html__('Invoice cancelled', 'patsch9-accounting-bridge')));
}

add_action('wp_ajax_patsacbr_send_invoice_email', 'patsacbr_ajax_send_invoice_email');
function patsacbr_ajax_send_invoice_email() {
    check_ajax_referer('patsacbr_manual_action', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Permission denied', 'patsch9-accounting-bridge')));
    }
    if (class_exists('PATSACBR_Security') && !PATSACBR_Security::check_rate_limit('manual_send_invoice_email', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Too many requests. Please wait.', 'patsch9-accounting-bridge')));
    }

    $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
    $order = wc_get_order($order_id);

    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Order not found', 'patsch9-accounting-bridge')));
    }

    $invoice_id = $order->get_meta('_patsacbr_lexware_invoice_id');
    if (!$invoice_id) {
        wp_send_json_error(array('message' => esc_html__('No invoice available', 'patsch9-accounting-bridge')));
    }

    $mailer = WC()->mailer();
    $emails = $mailer->get_emails();

    if (isset($emails['PATSACBR_Invoice_Email'])) {
        $sent = $emails['PATSACBR_Invoice_Email']->trigger($order_id, $order);
        if ($sent) {
            $order->add_order_note(esc_html__('Invoice sent by email', 'patsch9-accounting-bridge'));
            wp_send_json_success(array('message' => esc_html__('Email sent', 'patsch9-accounting-bridge')));
        }
        wp_send_json_error(array('message' => esc_html__('The invoice email could not be sent. See the order notes for details.', 'patsch9-accounting-bridge')));
    } else {
        wp_send_json_error(array('message' => esc_html__('Email class not found', 'patsch9-accounting-bridge')));
    }
}

add_action('wp_ajax_patsacbr_manual_update_invoice', 'patsacbr_ajax_manual_update_invoice');
function patsacbr_ajax_manual_update_invoice() {
    check_ajax_referer('patsacbr_manual_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Permission denied', 'patsch9-accounting-bridge')));
    }
    if (class_exists('PATSACBR_Security') && !PATSACBR_Security::check_rate_limit('manual_update_invoice', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Too many requests. Please wait.', 'patsch9-accounting-bridge')));
    }
    $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Order not found', 'patsch9-accounting-bridge')));
    }
    $lexware_invoice_id = $order->get_meta('_patsacbr_lexware_invoice_id');
    $already_voided = $order->get_meta('_patsacbr_lexware_invoice_voided');
    if (!$lexware_invoice_id || $already_voided === 'yes') {
        wp_send_json_error(array('message' => esc_html__('No valid invoice available', 'patsch9-accounting-bridge')));
    }
    PATSACBR_Queue_Handler::add_to_queue($order_id, 'update_invoice');
    $result = PATSACBR_Queue_Handler::process_order_action($order_id, 'update_invoice');
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    wp_send_json_success(array('message' => esc_html__('Invoice updated', 'patsch9-accounting-bridge')));
}

add_action('wp_ajax_patsacbr_unlink_invoice', 'patsacbr_ajax_unlink_invoice');
function patsacbr_ajax_unlink_invoice() {
    check_ajax_referer('patsacbr_manual_action', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Permission denied', 'patsch9-accounting-bridge')));
    }
    if (class_exists('PATSACBR_Security') && !PATSACBR_Security::check_rate_limit('manual_unlink_invoice', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Too many requests. Please wait.', 'patsch9-accounting-bridge')));
    }

    $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
    $order = wc_get_order($order_id);

    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Order not found', 'patsch9-accounting-bridge')));
    }

    $order->delete_meta_data('_patsacbr_lexware_invoice_id');
    $order->delete_meta_data('_patsacbr_lexware_invoice_number');
    $order->delete_meta_data('_patsacbr_lexware_credit_note_id');
    $order->delete_meta_data('_patsacbr_lexware_invoice_voided');
    $order->delete_meta_data('_patsacbr_lexware_contact_id');
    $order->delete_meta_data('_patsacbr_manual_invoice_entry');
    $order->delete_meta_data('_patsacbr_lexware_credit_note_for_invoice_id');
    $order->delete_meta_data('_patsacbr_lexware_update_source_invoice_id');
    $order->delete_meta_data('_patsacbr_lexware_invoice_reconciled');
    $order->update_meta_data('_patsacbr_skip_auto_reconciliation', 'yes');
    $order->update_meta_data('_patsacbr_lexware_reconciliation_state', 'manually_unlinked');
    $order->save();

    $order->add_order_note(esc_html__('The link to the Lexware invoice was removed. Automatic historical relinking is disabled for this order.', 'patsch9-accounting-bridge'));

    wp_send_json_success(array('message' => esc_html__('Link removed', 'patsch9-accounting-bridge')));
}

add_action('wp_ajax_patsacbr_download_invoice_pdf', 'patsacbr_ajax_download_invoice_document');
function patsacbr_ajax_download_invoice_document() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Permission denied', 'patsch9-accounting-bridge'), '', array('response' => 403));
    }
    $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'patsacbr_download_pdf_' . $order_id)) {
        wp_die(esc_html__('Invalid security token', 'patsch9-accounting-bridge'), '', array('response' => 403));
    }
    if (class_exists('PATSACBR_Security') && !PATSACBR_Security::check_rate_limit('admin_download_invoice', get_current_user_id(), 20, 60)) {
        wp_die(esc_html__('Too many requests. Please wait.', 'patsch9-accounting-bridge'), '', array('response' => 429));
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_die(esc_html__('Order not found', 'patsch9-accounting-bridge'), '', array('response' => 404));
    }
    $lexware_invoice_id = $order->get_meta('_patsacbr_lexware_invoice_id');
    if (!$lexware_invoice_id) {
        wp_die(esc_html__('No invoice available', 'patsch9-accounting-bridge'), '', array('response' => 404));
    }
    $api_client = new PATSACBR_API_Client();
    $document = $api_client->download_invoice_document($lexware_invoice_id);
    if (is_wp_error($document)) {
        wp_die(esc_html($document->get_error_message()));
    }
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    $path = isset($document['path']) ? $document['path'] : '';
    if (!$path || !$wp_filesystem || !$wp_filesystem->exists($path)) {
        wp_die(esc_html__('Invoice document not found', 'patsch9-accounting-bridge'));
    }
    $content = $wp_filesystem->get_contents($path);
    $wp_filesystem->delete($path);
    if ($content === false) {
        wp_die(esc_html__('The invoice document could not be read', 'patsch9-accounting-bridge'));
    }
    $extension = in_array(($document['extension'] ?? ''), array('pdf', 'xml'), true) ? $document['extension'] : 'bin';
    $mime = in_array(($document['mime'] ?? ''), array('application/pdf', 'application/xml'), true) ? $document['mime'] : 'application/octet-stream';
    nocache_headers();
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . sanitize_file_name('rechnung_' . $order->get_order_number() . '.' . $extension) . '"');
    header('Content-Length: ' . strlen($content));
    echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- authenticated invoice binary/XML data
    exit;
}

add_action('admin_notices', 'patsacbr_bulk_action_admin_notice');
function patsacbr_bulk_action_admin_notice() {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || ('edit-shop_order' !== $screen->id && 'woocommerce_page_wc-orders' !== $screen->id)) {
        return;
    }
    // Nonce-Prüfung ist hier nicht nötig, da nur GET-Parameter gelesen werden
    // und diese vom WordPress Core nach erfolgreicher Bulk-Action gesetzt werden
    if (!empty($_GET['patsacbr_invoices_created'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only bulk-action redirect count.
        $count = absint(wp_unslash($_GET['patsacbr_invoices_created'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only bulk-action redirect count.
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(sprintf(
            /* translators: %d: Number of invoices added to queue */
                _n(
                    '%d invoice added to the queue.',
                    '%d invoices added to the queue.',
                    $count,
                    'patsch9-accounting-bridge'
                ),
                $count
            ))
        );
    }
}
