<?php
/**
 * WooCommerce Integration
 * Handhabt alle WooCommerce Hooks und Events
 */

if (!defined('ABSPATH')) {
    exit;
}

class WLC_WooCommerce_Integration {
    private static $instance = null;
    private $api_client;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->api_client = new WLC_API_Client();
        $this->register_order_hooks();
        $this->register_admin_hooks();
    }
    
    private function register_order_hooks() {
        $trigger_statuses = get_option('wlc_order_statuses', array('wc-completed', 'wc-processing'));
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
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        if ($lexware_invoice_id) {
            $order->add_order_note(esc_html__('Lexware Rechnung existiert bereits', 'patsch9-accounting-bridge'));
            return;
        }
        WLC_Queue_Handler::add_to_queue($order_id, 'create_invoice');
    }
    
    public function handle_order_cancelled($order_id, $order = null) {
        if (!$order) { $order = wc_get_order($order_id); }
        if (!$order) { return; }
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        $already_voided = $order->get_meta('_wlc_lexware_invoice_voided');
        if (!$lexware_invoice_id || $already_voided === 'yes') { return; }
        WLC_Queue_Handler::add_to_queue($order_id, 'void_invoice');
    }
    
    public function handle_order_refunded($order_id, $order = null) {
        $this->handle_order_cancelled($order_id, $order);
    }
    
    // keine automatische Aktualisierung mehr
    // public function handle_order_items_changed($order_id, $items) {...}
    
    public function add_order_metabox() {
        add_meta_box(
            'wlc_lexware_info', 
            esc_html__('Lexware Rechnung', 'patsch9-accounting-bridge'), 
            array($this, 'render_order_metabox'), 
            'shop_order', 
            'side', 
            'default'
        );
        add_meta_box(
            'wlc_lexware_info', 
            esc_html__('Lexware Rechnung', 'patsch9-accounting-bridge'), 
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
        if (!isset($_POST['wlc_manual_invoice_nonce']) || 
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wlc_manual_invoice_nonce'])), 'wlc_save_manual_invoice_' . $order_id)) {
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
        $existing_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        if ($existing_invoice_id) {
            return; // Keine Änderung wenn bereits eine Rechnung verknüpft ist
        }
        
        // Hole die manuellen Eingaben
        $manual_invoice_id = isset($_POST['wlc_manual_invoice_id']) ? 
            sanitize_text_field(wp_unslash($_POST['wlc_manual_invoice_id'])) : '';
        $manual_invoice_number = isset($_POST['wlc_manual_invoice_number']) ? 
            sanitize_text_field(wp_unslash($_POST['wlc_manual_invoice_number'])) : '';
        
        // Lexware resource IDs are UUIDs. Reject malformed manual links so a
        // typo cannot poison later download/correction operations.
        $valid_uuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $manual_invoice_id);

        // Speichere nur wenn beide Felder ausgefüllt und die ID plausibel ist.
        if (!empty($manual_invoice_id) && !empty($manual_invoice_number) && $valid_uuid) {
            $order->update_meta_data('_wlc_lexware_invoice_id', $manual_invoice_id);
            $order->update_meta_data('_wlc_lexware_invoice_number', $manual_invoice_number);
            $order->update_meta_data('_wlc_manual_invoice_entry', 'yes');
            $order->save();
            
            $order->add_order_note(
                sprintf(
                    /* translators: 1: Lexware invoice ID, 2: Invoice number */
                    esc_html__('Rechnungsdaten manuell hinterlegt: ID %1$s, Nummer %2$s', 'patsch9-accounting-bridge'),
                    $manual_invoice_id,
                    $manual_invoice_number
                )
            );
        } elseif (!empty($manual_invoice_id) || !empty($manual_invoice_number)) {
            $order->add_order_note(esc_html__('Manuelle Lexware-Verknüpfung nicht gespeichert: Bitte eine gültige Lexware-UUID und Rechnungsnummer vollständig angeben.', 'patsch9-accounting-bridge'));
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
        
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        $lexware_invoice_number = $order->get_meta('_wlc_lexware_invoice_number');
        $lexware_credit_note_id = $order->get_meta('_wlc_lexware_credit_note_id');
        $invoice_voided = $order->get_meta('_wlc_lexware_invoice_voided');
        $manual_entry = $order->get_meta('_wlc_manual_invoice_entry');
        
        wp_nonce_field('wlc_save_manual_invoice_' . $order_id, 'wlc_manual_invoice_nonce');
        ?>
        <div class="wlc-metabox">
            <?php if ($lexware_invoice_id): ?>
                <p><strong><?php esc_html_e('Rechnungsnummer:', 'patsch9-accounting-bridge'); ?></strong><br><?php echo esc_html($lexware_invoice_number ?: '–'); ?></p>
                <p><strong><?php esc_html_e('Lexware ID:', 'patsch9-accounting-bridge'); ?></strong><br><code><?php echo esc_html($lexware_invoice_id); ?></code></p>
                <?php if ($manual_entry === 'yes'): ?>
                    <p><em style="font-size: 11px; color: #666;"><?php esc_html_e('(Manuell hinterlegt)', 'patsch9-accounting-bridge'); ?></em></p>
                <?php endif; ?>
                <p><strong><?php esc_html_e('Status:', 'patsch9-accounting-bridge'); ?></strong><br><?php 
                if ($invoice_voided === 'yes') { 
                    echo '<span style="color: red;">' . esc_html__('Storniert', 'patsch9-accounting-bridge') . '</span>'; 
                } else { 
                    echo '<span style="color: green;">' . esc_html__('Aktiv', 'patsch9-accounting-bridge') . '</span>'; 
                } 
                ?></p>
                <?php if ($lexware_credit_note_id): ?>
                    <p><strong><?php esc_html_e('Gutschrift ID:', 'patsch9-accounting-bridge'); ?></strong><br><code><?php echo esc_html($lexware_credit_note_id); ?></code></p>
                <?php endif; ?>
                
                <p><a href="https://app.lexware.de/permalink/invoices/view/<?php echo esc_attr(rawurlencode($lexware_invoice_id)); ?>" target="_blank" class="button button-secondary"><?php esc_html_e('In Lexware öffnen', 'patsch9-accounting-bridge'); ?></a></p>
                <p><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=wlc_download_invoice_pdf&order_id=' . $order_id), 'wlc_download_pdf_' . $order_id)); ?>" class="button button-primary" target="_blank"><?php esc_html_e('📄 Rechnungsdatei herunterladen', 'patsch9-accounting-bridge'); ?></a></p>
                
                <?php if ($invoice_voided !== 'yes'): ?>
                    <p><button type="button" class="button button-secondary wlc-void-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('Rechnung stornieren', 'patsch9-accounting-bridge'); ?></button></p>
                    <p><button type="button" class="button button-secondary wlc-send-invoice-email" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('📧 Rechnung per E-Mail senden', 'patsch9-accounting-bridge'); ?></button></p>
                    <p><button type="button" class="button button-secondary wlc-update-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('🔄 Rechnung aktualisieren', 'patsch9-accounting-bridge'); ?></button></p>
                <?php endif; ?>
                
                <p><button type="button" class="button button-secondary wlc-unlink-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('🔗 Verknüpfung löschen', 'patsch9-accounting-bridge'); ?></button></p>
                <p style="font-size: 11px; color: #666;">
                    <?php esc_html_e('Löscht nur die Verknüpfung zur Rechnung, nicht die Rechnung selbst in Lexware.', 'patsch9-accounting-bridge'); ?>
                </p>
                
            <?php else: ?>
                <p><?php esc_html_e('Noch keine Rechnung erstellt.', 'patsch9-accounting-bridge'); ?></p>
                
                <div class="wlc-manual-invoice-fields" style="margin: 15px 0; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                    <p style="margin-top: 0; font-weight: bold;"><?php esc_html_e('Manuelle Eingabe:', 'patsch9-accounting-bridge'); ?></p>
                    <p style="margin-bottom: 8px;">
                        <label for="wlc_manual_invoice_id" style="display: block; margin-bottom: 4px; font-size: 12px;">
                            <?php esc_html_e('Lexware Rechnungs-ID:', 'patsch9-accounting-bridge'); ?>
                        </label>
                        <input type="text" 
                               id="wlc_manual_invoice_id" 
                               name="wlc_manual_invoice_id" 
                               class="widefat" 
                               placeholder="z.B. 12345678-1234-1234-1234-123456789012"
                               style="font-size: 12px;">
                    </p>
                    <p style="margin-bottom: 0;">
                        <label for="wlc_manual_invoice_number" style="display: block; margin-bottom: 4px; font-size: 12px;">
                            <?php esc_html_e('Rechnungsnummer:', 'patsch9-accounting-bridge'); ?>
                        </label>
                        <input type="text" 
                               id="wlc_manual_invoice_number" 
                               name="wlc_manual_invoice_number" 
                               class="widefat" 
                               placeholder="z.B. RE-2024-001"
                               style="font-size: 12px;">
                    </p>
                    <p style="font-size: 11px; color: #666; margin-bottom: 0;">
                        <?php esc_html_e('Beide Felder ausfüllen und Bestellung speichern, um eine manuelle Verknüpfung zu erstellen.', 'patsch9-accounting-bridge'); ?>
                    </p>
                </div>
                
                <p style="text-align: center; margin: 10px 0; color: #666; font-size: 11px;">
                    <?php esc_html_e('— oder —', 'patsch9-accounting-bridge'); ?>
                </p>
                
                <p><button type="button" class="button button-primary wlc-create-invoice" data-order-id="<?php echo esc_attr($order_id); ?>"><?php esc_html_e('✨ Rechnung jetzt erstellen', 'patsch9-accounting-bridge'); ?></button></p>
            <?php endif; ?>
        </div>
        
        <style>
            .wlc-metabox p { margin: 10px 0; }
            .wlc-metabox code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 11px; word-break: break-all;}
            .wlc-metabox .button { width: 100%; text-align: center; box-sizing: border-box;}
            .wlc-manual-invoice-fields input.widefat { width: 100%; box-sizing: border-box; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            function rateLimited(action) {
                return true;
            }
            $('.wlc-create-invoice').on('click', function() {
                var orderId = $(this).data('order-id');
                var button = $(this);
                if (!confirm('<?php esc_attr_e('Rechnung jetzt für diese Bestellung erstellen?', 'patsch9-accounting-bridge'); ?>')) { 
                    return; 
                }
                if (!rateLimited('create_invoice')) { return; }
                button.prop('disabled', true).text('<?php esc_attr_e('Wird erstellt...', 'patsch9-accounting-bridge'); ?>');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wlc_manual_create_invoice',
                        order_id: orderId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wlc_manual_action')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php esc_attr_e('Fehler beim Erstellen der Rechnung', 'patsch9-accounting-bridge'); ?>');
                            button.prop('disabled', false).text('<?php esc_attr_e('✨ Rechnung jetzt erstellen', 'patsch9-accounting-bridge'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_attr_e('Fehler beim Erstellen der Rechnung', 'patsch9-accounting-bridge'); ?>');
                        button.prop('disabled', false).text('<?php esc_attr_e('✨ Rechnung jetzt erstellen', 'patsch9-accounting-bridge'); ?>');
                    }
                });
            });
            
            $('.wlc-void-invoice').on('click', function() {
                if (!confirm('<?php esc_attr_e('Rechnung wirklich stornieren?', 'patsch9-accounting-bridge'); ?>')) {
                    return;
                }
                var orderId = $(this).data('order-id');
                var button = $(this);
                if (!rateLimited('void_invoice')) { return; }
                button.prop('disabled', true).text('<?php esc_attr_e('Wird storniert...', 'patsch9-accounting-bridge'); ?>');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wlc_manual_void_invoice',
                        order_id: orderId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wlc_manual_action')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php esc_attr_e('Fehler beim Stornieren der Rechnung', 'patsch9-accounting-bridge'); ?>');
                            button.prop('disabled', false).text('<?php esc_attr_e('Rechnung stornieren', 'patsch9-accounting-bridge'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_attr_e('Fehler beim Stornieren der Rechnung', 'patsch9-accounting-bridge'); ?>');
                        button.prop('disabled', false).text('<?php esc_attr_e('Rechnung stornieren', 'patsch9-accounting-bridge'); ?>');
                    }
                });
            });
            
            $('.wlc-send-invoice-email').on('click', function() {
                var orderId = $(this).data('order-id');
                var button = $(this);
                
                if (!confirm('<?php esc_attr_e('Rechnung jetzt per E-Mail an den Kunden senden?', 'patsch9-accounting-bridge'); ?>')) {
                    return;
                }
                if (!rateLimited('send_invoice_email')) { return; }
                
                button.prop('disabled', true).text('<?php esc_attr_e('Wird gesendet...', 'patsch9-accounting-bridge'); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wlc_send_invoice_email',
                        order_id: orderId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wlc_manual_action')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('<?php esc_attr_e('E-Mail wurde versendet', 'patsch9-accounting-bridge'); ?>');
                        } else {
                            alert(response.data.message || '<?php esc_attr_e('Fehler beim E-Mail-Versand', 'patsch9-accounting-bridge'); ?>');
                        }
                        button.prop('disabled', false).text('<?php esc_attr_e('📧 Rechnung per E-Mail senden', 'patsch9-accounting-bridge'); ?>');
                    },
                    error: function() {
                        alert('<?php esc_attr_e('Fehler beim E-Mail-Versand', 'patsch9-accounting-bridge'); ?>');
                        button.prop('disabled', false).text('<?php esc_attr_e('📧 Rechnung per E-Mail senden', 'patsch9-accounting-bridge'); ?>');
                    }
                });
            });
            
            $('.wlc-update-invoice').on('click', function() {
                var orderId = $(this).data('order-id');
                var button = $(this);
                if (!confirm('<?php esc_attr_e('Rechnung aktualisieren? Die bestehende finalisierte Rechnung wird in Lexware über eine verknüpfte Gutschrift korrigiert und anschließend neu erstellt. Bei Rechnungsentwürfen ist diese automatische Korrektur nicht möglich.', 'patsch9-accounting-bridge'); ?>')) {
                    return;
                }
                if (!rateLimited('update_invoice')) { return; }
                button.prop('disabled', true).text('<?php esc_attr_e('Wird aktualisiert...', 'patsch9-accounting-bridge'); ?>');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wlc_manual_update_invoice',
                        order_id: orderId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wlc_manual_action')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php esc_attr_e('Fehler beim Aktualisieren der Rechnung', 'patsch9-accounting-bridge'); ?>');
                            button.prop('disabled', false).text('<?php esc_attr_e('🔄 Rechnung aktualisieren', 'patsch9-accounting-bridge'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_attr_e('Fehler beim Aktualisieren der Rechnung', 'patsch9-accounting-bridge'); ?>');
                        button.prop('disabled', false).text('<?php esc_attr_e('🔄 Rechnung aktualisieren', 'patsch9-accounting-bridge'); ?>');
                    }
                });
            });

            $('.wlc-unlink-invoice').on('click', function() {
                var orderId = $(this).data('order-id');
                var button = $(this);
                
                if (!confirm('<?php esc_attr_e('Verknüpfung zur Lexware-Rechnung wirklich löschen?\n\nDie Rechnung in Lexware bleibt bestehen, aber du kannst eine neue Rechnung für diese Bestellung erstellen.', 'patsch9-accounting-bridge'); ?>')) {
                    return;
                }
                if (!rateLimited('unlink_invoice')) { return; }
                
                button.prop('disabled', true).text('<?php esc_attr_e('Wird gelöscht...', 'patsch9-accounting-bridge'); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wlc_unlink_invoice',
                        order_id: orderId,
                        nonce: '<?php echo esc_js(wp_create_nonce('wlc_manual_action')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php esc_attr_e('Fehler beim Löschen der Verknüpfung', 'patsch9-accounting-bridge'); ?>');
                            button.prop('disabled', false).text('<?php esc_attr_e('🔗 Verknüpfung löschen', 'patsch9-accounting-bridge'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_attr_e('Fehler beim Löschen der Verknüpfung', 'patsch9-accounting-bridge'); ?>');
                        button.prop('disabled', false).text('<?php esc_attr_e('🔗 Verknüpfung löschen', 'patsch9-accounting-bridge'); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function add_bulk_action($actions) {
        $actions['wlc_create_invoices'] = esc_html__('Lexware Rechnungen erstellen', 'patsch9-accounting-bridge');
        return $actions;
    }
    
    public function handle_bulk_action($redirect_to, $action, $post_ids) {
        if ($action !== 'wlc_create_invoices') {
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
            if ($order->get_meta('_wlc_lexware_invoice_id')) { continue; }
            WLC_Queue_Handler::add_to_queue($post_id, 'create_invoice');
            $created++;
        }
        $redirect_to = add_query_arg('wlc_invoices_created', $created, $redirect_to);
        return $redirect_to;
    }
    
    public function add_order_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'order_total') {
                $new_columns['wlc_lexware'] = esc_html__('Lexware', 'patsch9-accounting-bridge');
            }
        }
        return $new_columns;
    }
    
    public function render_order_column($column, $post_id) {
        if ($column !== 'wlc_lexware') {
            return;
        }
        $this->render_order_column_value(wc_get_order(absint($post_id)));
    }

    public function render_order_column_hpos($column, $order) {
        if ($column !== 'wlc_lexware') {
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
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        $invoice_voided = $order->get_meta('_wlc_lexware_invoice_voided');
        if ($lexware_invoice_id) {
            if ($invoice_voided === 'yes') {
                echo '<span style="color: red;">✗ ' . esc_html__('Storniert', 'patsch9-accounting-bridge') . '</span>';
            } else {
                echo '<span style="color: green;">✓ ' . esc_html__('Erstellt', 'patsch9-accounting-bridge') . '</span>';
            }
        } else {
            echo '<span style="color: gray;">– ' . esc_html__('Keine', 'patsch9-accounting-bridge') . '</span>';
        }
    }
}

add_action('wp_ajax_wlc_manual_create_invoice', 'wlc_ajax_manual_create_invoice');
function wlc_ajax_manual_create_invoice() {
    check_ajax_referer('wlc_manual_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Keine Berechtigung', 'patsch9-accounting-bridge')));
    }
    if (class_exists('WLC_Security') && !WLC_Security::check_rate_limit('manual_create_invoice', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Zu viele Anfragen. Bitte warten.', 'patsch9-accounting-bridge')));
    }
    $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Bestellung nicht gefunden', 'patsch9-accounting-bridge')));
    }
    
    if ($order->get_meta('_wlc_lexware_invoice_id')) {
        wp_send_json_error(array('message' => esc_html__('Für diese Bestellung ist bereits eine Lexware-Rechnung verknüpft. Nutzen Sie „Rechnung aktualisieren“ oder stornieren Sie die bestehende Rechnung.', 'patsch9-accounting-bridge')));
    }
    WLC_Queue_Handler::add_to_queue($order_id, 'create_invoice');
    $result = WLC_Queue_Handler::process_order_action($order_id, 'create_invoice');
    
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    
    wp_send_json_success(array('message' => esc_html__('Rechnung wurde erstellt', 'patsch9-accounting-bridge')));
}

add_action('wp_ajax_wlc_manual_void_invoice', 'wlc_ajax_manual_void_invoice');
function wlc_ajax_manual_void_invoice() {
    check_ajax_referer('wlc_manual_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Keine Berechtigung', 'patsch9-accounting-bridge')));
    }
    if (class_exists('WLC_Security') && !WLC_Security::check_rate_limit('manual_void_invoice', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Zu viele Anfragen. Bitte warten.', 'patsch9-accounting-bridge')));
    }
    $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Bestellung nicht gefunden', 'patsch9-accounting-bridge')));
    }
    
    WLC_Queue_Handler::add_to_queue($order_id, 'void_invoice');
    $result = WLC_Queue_Handler::process_order_action($order_id, 'void_invoice');
    
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    
    wp_send_json_success(array('message' => esc_html__('Rechnung wurde storniert', 'patsch9-accounting-bridge')));
}

add_action('wp_ajax_wlc_send_invoice_email', 'wlc_ajax_send_invoice_email');
function wlc_ajax_send_invoice_email() {
    check_ajax_referer('wlc_manual_action', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Keine Berechtigung', 'patsch9-accounting-bridge')));
    }
    if (class_exists('WLC_Security') && !WLC_Security::check_rate_limit('manual_send_invoice_email', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Zu viele Anfragen. Bitte warten.', 'patsch9-accounting-bridge')));
    }
    
    $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Bestellung nicht gefunden', 'patsch9-accounting-bridge')));
    }
    
    $invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
    if (!$invoice_id) {
        wp_send_json_error(array('message' => esc_html__('Keine Rechnung vorhanden', 'patsch9-accounting-bridge')));
    }
    
    $mailer = WC()->mailer();
    $emails = $mailer->get_emails();
    
    if (isset($emails['WLC_Invoice_Email'])) {
        $sent = $emails['WLC_Invoice_Email']->trigger($order_id, $order);
        if ($sent) {
            $order->add_order_note(esc_html__('Rechnung per E-Mail versendet', 'patsch9-accounting-bridge'));
            wp_send_json_success(array('message' => esc_html__('E-Mail wurde versendet', 'patsch9-accounting-bridge')));
        }
        wp_send_json_error(array('message' => esc_html__('Die Rechnungs-E-Mail konnte nicht versendet werden. Details stehen in den Bestellnotizen.', 'patsch9-accounting-bridge')));
    } else {
        wp_send_json_error(array('message' => esc_html__('E-Mail-Klasse nicht gefunden', 'patsch9-accounting-bridge')));
    }
}

add_action('wp_ajax_wlc_manual_update_invoice', 'wlc_ajax_manual_update_invoice');
function wlc_ajax_manual_update_invoice() {
    check_ajax_referer('wlc_manual_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Keine Berechtigung', 'patsch9-accounting-bridge')));
    }
    if (class_exists('WLC_Security') && !WLC_Security::check_rate_limit('manual_update_invoice', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Zu viele Anfragen. Bitte warten.', 'patsch9-accounting-bridge')));
    }
    $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Bestellung nicht gefunden', 'patsch9-accounting-bridge')));
    }
    $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
    $already_voided = $order->get_meta('_wlc_lexware_invoice_voided');
    if (!$lexware_invoice_id || $already_voided === 'yes') {
        wp_send_json_error(array('message' => esc_html__('Keine gültige Rechnung vorhanden', 'patsch9-accounting-bridge')));
    }
    WLC_Queue_Handler::add_to_queue($order_id, 'update_invoice');
    $result = WLC_Queue_Handler::process_order_action($order_id, 'update_invoice');
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    wp_send_json_success(array('message' => esc_html__('Rechnung wurde aktualisiert', 'patsch9-accounting-bridge')));
}

add_action('wp_ajax_wlc_unlink_invoice', 'wlc_ajax_unlink_invoice');
function wlc_ajax_unlink_invoice() {
    check_ajax_referer('wlc_manual_action', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => esc_html__('Keine Berechtigung', 'patsch9-accounting-bridge')));
    }
    if (class_exists('WLC_Security') && !WLC_Security::check_rate_limit('manual_unlink_invoice', get_current_user_id(), 5, 60)) {
        wp_send_json_error(array('message' => esc_html__('Zu viele Anfragen. Bitte warten.', 'patsch9-accounting-bridge')));
    }
    
    $order_id = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Bestellung nicht gefunden', 'patsch9-accounting-bridge')));
    }
    
    $order->delete_meta_data('_wlc_lexware_invoice_id');
    $order->delete_meta_data('_wlc_lexware_invoice_number');
    $order->delete_meta_data('_wlc_lexware_credit_note_id');
    $order->delete_meta_data('_wlc_lexware_invoice_voided');
    $order->delete_meta_data('_wlc_lexware_contact_id');
    $order->delete_meta_data('_wlc_manual_invoice_entry');
    $order->delete_meta_data('_wlc_lexware_credit_note_for_invoice_id');
    $order->delete_meta_data('_wlc_lexware_update_source_invoice_id');
    $order->save();
    
    $order->add_order_note(esc_html__('Verknüpfung zur Lexware-Rechnung wurde entfernt.', 'patsch9-accounting-bridge'));
    
    wp_send_json_success(array('message' => esc_html__('Verknüpfung wurde gelöscht', 'patsch9-accounting-bridge')));
}

add_action('wp_ajax_wlc_download_invoice_pdf', 'wlc_ajax_download_invoice_document');
function wlc_ajax_download_invoice_document() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Keine Berechtigung', 'patsch9-accounting-bridge'), '', array('response' => 403));
    }
    $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'wlc_download_pdf_' . $order_id)) {
        wp_die(esc_html__('Ungültiger Sicherheitsschlüssel', 'patsch9-accounting-bridge'), '', array('response' => 403));
    }
    if (class_exists('WLC_Security') && !WLC_Security::check_rate_limit('admin_download_invoice', get_current_user_id(), 20, 60)) {
        wp_die(esc_html__('Zu viele Anfragen. Bitte warten.', 'patsch9-accounting-bridge'), '', array('response' => 429));
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_die(esc_html__('Bestellung nicht gefunden', 'patsch9-accounting-bridge'), '', array('response' => 404));
    }
    $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
    if (!$lexware_invoice_id) {
        wp_die(esc_html__('Keine Rechnung vorhanden', 'patsch9-accounting-bridge'), '', array('response' => 404));
    }
    $api_client = new WLC_API_Client();
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
        wp_die(esc_html__('Rechnungsdatei nicht gefunden', 'patsch9-accounting-bridge'));
    }
    $content = $wp_filesystem->get_contents($path);
    $wp_filesystem->delete($path);
    if ($content === false) {
        wp_die(esc_html__('Rechnungsdatei konnte nicht gelesen werden', 'patsch9-accounting-bridge'));
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

add_action('admin_notices', 'wlc_bulk_action_admin_notice');
function wlc_bulk_action_admin_notice() {
    // Nonce-Prüfung ist hier nicht nötig, da nur GET-Parameter gelesen werden
    // und diese vom WordPress Core nach erfolgreicher Bulk-Action gesetzt werden
    if (!empty($_GET['wlc_invoices_created'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only bulk-action redirect count.
        $count = absint(wp_unslash($_GET['wlc_invoices_created'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only bulk-action redirect count.
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(sprintf(
            /* translators: %d: Number of invoices added to queue */
                _n(
                    '%d Rechnung zur Queue hinzugefügt.',
                    '%d Rechnungen zur Queue hinzugefügt.',
                    $count,
                    'patsch9-accounting-bridge'
                ),
                $count
            ))
        );
    }
}
