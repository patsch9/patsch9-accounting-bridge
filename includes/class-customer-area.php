<?php
/**
 * Customer Area Integration
 * Zeigt Rechnungen im WooCommerce Kundenbereich an
 */

if (!defined('ABSPATH')) {
    exit;
}

class WLC_Customer_Area {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        if (get_option('wlc_show_in_customer_area', 'yes') !== 'yes') {
            return;
        }
        
        // Füge Download-Button in Order-Details hinzu
        add_action('woocommerce_order_details_after_order_table', array($this, 'add_invoice_download_button'), 10, 1);
        
        // Füge Spalte in "Meine Bestellungen" hinzu
        add_filter('woocommerce_my_account_my_orders_columns', array($this, 'add_orders_column'));
        add_action('woocommerce_my_account_my_orders_column_invoice', array($this, 'render_orders_column'));
        
        // Download-Handler
        add_action('init', array($this, 'handle_invoice_download'));
    }
    
    public function add_invoice_download_button($order) {
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        $invoice_voided = $order->get_meta('_wlc_lexware_invoice_voided');
        
        if (!$lexware_invoice_id || $invoice_voided === 'yes') {
            return;
        }
        
        $download_url = $this->get_download_url($order->get_id());
        
        ?>
        <section class="wlc-invoice-download" style="margin-top: 20px;">
            <h2><?php esc_html_e('Rechnung', 'patsch9-accounting-bridge'); ?></h2>
            <p>
                <a href="<?php echo esc_url($download_url); ?>" 
                   class="button" 
                   target="_blank">
                    <?php esc_html_e('Rechnung herunterladen', 'patsch9-accounting-bridge'); ?>
                </a>
            </p>
        </section>
        <?php
    }
    
    public function add_orders_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $name) {
            $new_columns[$key] = $name;
            
            if ($key === 'order-total') {
                $new_columns['invoice'] = esc_html__('Rechnung', 'patsch9-accounting-bridge');
            }
        }
        
        return $new_columns;
    }
    
    public function render_orders_column($order) {
        $lexware_invoice_id = $order->get_meta('_wlc_lexware_invoice_id');
        $invoice_voided = $order->get_meta('_wlc_lexware_invoice_voided');
        
        if ($lexware_invoice_id && $invoice_voided !== 'yes') {
            $download_url = $this->get_download_url($order->get_id());
            echo '<a href="' . esc_url($download_url) . '" target="_blank">' . esc_html__('Rechnung', 'patsch9-accounting-bridge') . '</a>';
        } else {
            echo '–';
        }
    }
    
    public function handle_invoice_download() {
        if (!isset($_GET['wlc_download_invoice']) || !isset($_GET['order_id']) || !isset($_GET['nonce'])) {
            return;
        }
        
        $order_id = absint(wp_unslash($_GET['order_id']));
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        
        if (!wp_verify_nonce($nonce, 'wlc_download_' . $order_id)) {
            wp_die(esc_html__('Ungültiger Sicherheitsschlüssel', 'patsch9-accounting-bridge'), '', array('response' => 403));
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_die(esc_html__('Bestellung nicht gefunden', 'patsch9-accounting-bridge'), '', array('response' => 404));
        }
        
        // Explicit ownership check; do not rely on an application-specific meta capability.
        $current_user_id = get_current_user_id();
        if (!$current_user_id || ((int) $order->get_user_id() !== $current_user_id && !current_user_can('manage_woocommerce'))) {
            wp_die(esc_html__('Keine Berechtigung', 'patsch9-accounting-bridge'), '', array('response' => 403));
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
        header('Content-Disposition: attachment; filename="' . sanitize_file_name('rechnung_' . $order->get_order_number() . '.' . $extension) . '"');
        header('Content-Length: ' . strlen($content));
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- authenticated invoice binary/XML data
        exit;
    }
    
    private function get_download_url($order_id) {
        return add_query_arg(array(
            'wlc_download_invoice' => '1',
            'order_id' => $order_id,
            'nonce' => wp_create_nonce('wlc_download_' . $order_id)
        ), home_url());
    }
}
