<?php
/**
 * Admin Settings
 * MIT ZAHLUNGSMETHODEN-SPEZIFISCHEN EINSTELLUNGEN UND SICHEREM API-KEY
 */

if (!defined('ABSPATH')) {
    exit;
}

class WLC_Admin_Settings {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('pre_update_option_wlc_api_key', array($this, 'sanitize_api_key_on_save'), 10, 2);
        // WordPress Settings API defaults custom option pages to manage_options.
        // This plugin intentionally exposes its settings to WooCommerce managers,
        // so make the save capability match the menu/render capability as well.
        add_filter('option_page_capability_wlc_api_settings', array($this, 'settings_capability'));
        add_filter('option_page_capability_wlc_invoice_settings', array($this, 'settings_capability'));
        add_filter('option_page_capability_wlc_sync_settings', array($this, 'settings_capability'));
        add_action('admin_post_wlc_process_queue_now', array($this, 'handle_process_queue_now'));
        add_action('admin_post_wlc_clear_queue_now', array($this, 'handle_clear_queue_now'));
        add_action('admin_post_wlc_release_manual_check', array($this, 'handle_release_manual_check'));
    }

    public function settings_capability() {
        return 'manage_woocommerce';
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            esc_html__('Accounting Bridge', 'patsch9-accounting-bridge'),
            esc_html__('Accounting Bridge', 'patsch9-accounting-bridge'),
            'manage_woocommerce',
            'wlc-settings',
            array($this, 'render_settings_page')
        );
    }

    public function sanitize_api_key_on_save($new_value, $old_value) {
        if (defined('LEXWARE_CONNECTOR_API_KEY')) {
            return (string)$old_value;
        }
        // Wenn Feld leer bleibt, alten Wert beibehalten (nicht klartext anzeigen)
        if ($new_value === null || trim((string)$new_value) === '') {
            return (string)$old_value;
        }
        $sanitized = class_exists('WLC_Security') ? WLC_Security::sanitize_api_key($new_value) : sanitize_text_field(trim((string)$new_value));
        if ($sanitized === '') {
            add_settings_error(
                'wlc_messages',
                'wlc_invalid_api_key',
                esc_html__('Der Lexware API-Key hat ein ungültiges Format. Der bisherige Key wurde beibehalten.', 'patsch9-accounting-bridge'),
                'error'
            );
            return (string)$old_value;
        }
        return $sanitized;
    }

    public function register_settings() {
        register_setting('wlc_api_settings', 'wlc_api_key', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wlc_api_settings', 'wlc_order_statuses', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_order_statuses')
        ));
        register_setting('wlc_api_settings', 'wlc_retry_attempts', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_retry_attempts')
        ));
        register_setting('wlc_invoice_settings', 'wlc_invoice_title', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wlc_invoice_settings', 'wlc_invoice_introduction', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('wlc_invoice_settings', 'wlc_payment_terms', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('wlc_invoice_settings', 'wlc_payment_due_days', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_due_days')
        ));
        register_setting('wlc_invoice_settings', 'wlc_closing_text', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('wlc_invoice_settings', 'wlc_finalize_immediately', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        $this->register_payment_method_settings();
        register_setting('wlc_sync_settings', 'wlc_auto_sync_contacts', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        register_setting('wlc_sync_settings', 'wlc_show_in_customer_area', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        register_setting('wlc_sync_settings', 'wlc_shipping_as_line_item', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        register_setting('wlc_sync_settings', 'wlc_enable_logging', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        register_setting('wlc_sync_settings', 'wlc_email_on_error', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        register_setting('wlc_sync_settings', 'wlc_auto_send_email', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        register_setting('wlc_sync_settings', 'wlc_delete_data_on_uninstall', array(
            'type' => 'string',
            'default' => 'no',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
    }

    public function sanitize_order_statuses($value) {
        if (!is_array($value)) {
            return array();
        }
        $allowed = function_exists('wc_get_order_statuses') ? array_keys(wc_get_order_statuses()) : array();
        $statuses = array();
        foreach (array_slice($value, 0, 50) as $status) {
            $status = sanitize_key((string) $status);
            if (empty($allowed) || in_array($status, $allowed, true)) {
                $statuses[] = $status;
            }
        }
        return array_values(array_unique($statuses));
    }

    public function sanitize_yes_no($value) {
        return 'yes' === (string) $value ? 'yes' : 'no';
    }

    public function sanitize_retry_attempts($value) {
        return max(1, min(10, absint($value)));
    }

    public function sanitize_due_days($value) {
        return max(0, min(3650, absint($value)));
    }

    public function sanitize_optional_due_days($value) {
        if ('' === trim((string) $value)) {
            return '';
        }
        return (string) $this->sanitize_due_days($value);
    }

    private function register_payment_method_settings() {
        if (!function_exists('WC')) {
            return;
        }
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        foreach ($payment_gateways as $gateway) {
            if ($gateway->enabled === 'yes') {
                $gateway_id = $gateway->id;
                register_setting('wlc_invoice_settings', 'wlc_payment_terms_' . $gateway_id, array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ));
                register_setting('wlc_invoice_settings', 'wlc_payment_due_days_' . $gateway_id, array(
                    'type' => 'string',
                    'sanitize_callback' => array($this, 'sanitize_optional_due_days')
                ));
            }
        }
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'woocommerce_page_wlc-settings') {
            return;
        }
        wp_enqueue_style('wlc-admin-style', WLC_PLUGIN_URL . 'admin/css/admin-style.css', array(), WLC_VERSION);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        // Nonce wird von WordPress Settings API automatisch geprüft
        if (isset($_GET['settings-updated']) && sanitize_text_field(wp_unslash($_GET['settings-updated'])) === 'true') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            add_settings_error(
                'wlc_messages',
                'wlc_message',
                esc_html__('Einstellungen gespeichert', 'patsch9-accounting-bridge'),
                'updated'
            );
        }
        settings_errors('wlc_messages');
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'api'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!in_array($active_tab, array('api', 'invoice', 'sync', 'logs'), true)) {
            $active_tab = 'api';
        }
        ?>
        <div class="wrap wlc-settings-wrap">
            <h1><?php esc_html_e('Patsch9 Accounting Bridge', 'patsch9-accounting-bridge'); ?></h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=wlc-settings&tab=api" class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('API-Konfiguration', 'patsch9-accounting-bridge'); ?>
                </a>
                <a href="?page=wlc-settings&tab=invoice" class="nav-tab <?php echo $active_tab === 'invoice' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Rechnungseinstellungen', 'patsch9-accounting-bridge'); ?>
                </a>
                <a href="?page=wlc-settings&tab=sync" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Synchronisation', 'patsch9-accounting-bridge'); ?>
                </a>
                <a href="?page=wlc-settings&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Logs & Queue', 'patsch9-accounting-bridge'); ?>
                </a>
            </h2>
            <?php if ($active_tab !== 'logs'): ?>
                <form method="post" action="options.php">
                    <?php
                    switch ($active_tab) {
                        case 'api':
                            settings_fields('wlc_api_settings');
                            $this->render_api_tab();
                            break;
                        case 'invoice':
                            settings_fields('wlc_invoice_settings');
                            $this->render_invoice_tab();
                            break;
                        case 'sync':
                            settings_fields('wlc_sync_settings');
                            $this->render_sync_tab();
                            break;
                    }
                    submit_button();
                    ?>
                </form>
            <?php else: ?>
                <?php $this->render_logs_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_api_tab() {
        $api_key_from_constant = defined('LEXWARE_CONNECTOR_API_KEY');
        $api_key = $api_key_from_constant ? sanitize_text_field(trim((string)LEXWARE_CONNECTOR_API_KEY)) : get_option('wlc_api_key', '');
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wlc_api_key"><?php esc_html_e('Lexware API Key', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <?php if ($api_key_from_constant): ?>
                        <p><strong><?php esc_html_e('API-Key wird über wp-config.php bereitgestellt.', 'patsch9-accounting-bridge'); ?></strong></p>
                        <p class="description"><?php esc_html_e('Der Key wird dadurch nicht in der WordPress-Datenbank gespeichert und kann hier nicht geändert werden.', 'patsch9-accounting-bridge'); ?></p>
                    <?php else: ?>
                        <div style="position: relative; display: inline-block; width: 100%; max-width: 500px;">
                            <input type="password"
                                   id="wlc_api_key"
                                   name="wlc_api_key"
                                   value=""
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr($api_key ? esc_html__('Gespeichert – leer lassen, um nicht zu ändern', 'patsch9-accounting-bridge') : esc_html__('API-Schlüssel eingeben', 'patsch9-accounting-bridge')); ?>"
                                   autocomplete="off"
                                   spellcheck="false"
                                   style="font-family: monospace; letter-spacing: 1px; padding-right: 45px; width: 100%;">
                            <button type="button"
                                    class="button button-secondary wlc-toggle-api-key"
                                    style="position: absolute; right: 5px; top: 1px; height: 28px; padding: 0 8px;"
                                    title="<?php esc_attr_e('API-Key anzeigen/verbergen', 'patsch9-accounting-bridge'); ?>">
                                <span class="dashicons dashicons-visibility" style="line-height: 28px;"></span>
                            </button>
                        </div>
                        <p class="description" style="margin-top: 8px;">
                            <?php echo esc_html($api_key ? esc_html__('Ein API-Key ist gespeichert. Gib einen neuen ein, um ihn zu ersetzen – leer lassen, um nichts zu ändern.', 'patsch9-accounting-bridge') : esc_html__('Bitte API-Key speichern.', 'patsch9-accounting-bridge')); ?>
                            <?php esc_html_e(' Für höhere Sicherheit kann LEXWARE_CONNECTOR_API_KEY in wp-config.php gesetzt werden.', 'patsch9-accounting-bridge'); ?>
                        </p>
                    <?php endif; ?>
                    <p class="description" style="margin-top: 8px;">
                        <a href="https://app.lexware.de/settings/#/public-api" target="_blank" rel="noopener">
                            <?php esc_html_e('API Key in Lexware erstellen', 'patsch9-accounting-bridge'); ?>
                        </a>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Rechnungen erstellen bei Status', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <?php
                    $selected_statuses = get_option('wlc_order_statuses', array('wc-completed', 'wc-processing'));
                    if (!is_array($selected_statuses)) {
                        $selected_statuses = array();
                    }
                    $order_statuses = wc_get_order_statuses();
                    foreach ($order_statuses as $status => $label) {
                        $checked = in_array($status, $selected_statuses, true) ? 'checked' : '';
                        ?>
                        <label style="display: block; margin: 5px 0;">
                            <input type="checkbox" name="wlc_order_statuses[]"
                                   value="<?php echo esc_attr($status); ?>" <?php echo esc_attr($checked); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                        <?php
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_retry_attempts"><?php esc_html_e('Wiederholungsversuche', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <input type="number" id="wlc_retry_attempts" name="wlc_retry_attempts"
                           value="<?php echo esc_attr(get_option('wlc_retry_attempts', '3')); ?>"
                           min="0" max="10" class="small-text">
                    <p class="description">
                        <?php esc_html_e('Anzahl der automatischen Wiederholungsversuche bei API-Fehlern', 'patsch9-accounting-bridge'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <script>
        jQuery(document).ready(function($) {
            var input = $('#wlc_api_key');
            var button = $('.wlc-toggle-api-key');
            var icon = button.find('.dashicons');
            var isVisible = false;
            button.on('click', function(e) {
                e.preventDefault();
                isVisible = !isVisible;
                if (isVisible) {
                    input.attr('type', 'text');
                    icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                } else {
                    input.attr('type', 'password');
                    icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                }
            });
        });
        </script>
        <?php
    }

    private function render_invoice_tab() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wlc_invoice_title"><?php esc_html_e('Rechnungstitel', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <input type="text" id="wlc_invoice_title" name="wlc_invoice_title"
                           value="<?php echo esc_attr(get_option('wlc_invoice_title', 'Rechnung')); ?>"
                           class="regular-text">
                    <p class="description">Shortcodes: [order_number], [order_date], [customer_name]</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_invoice_introduction"><?php esc_html_e('Einleitungstext', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <textarea id="wlc_invoice_introduction" name="wlc_invoice_introduction"
                              rows="3" class="large-text"><?php echo esc_textarea(get_option('wlc_invoice_introduction', 'Vielen Dank für Ihre Bestellung [order_number] vom [order_date].')); ?></textarea>
                    <p class="description">Shortcodes: [order_number], [order_date], [customer_name], [customer_company], [total], [payment_method]</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_payment_terms"><?php esc_html_e('Standard Zahlungsbedingungen', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <textarea id="wlc_payment_terms" name="wlc_payment_terms"
                              rows="3" class="large-text"><?php echo esc_textarea(get_option('wlc_payment_terms', 'Zahlbar innerhalb von 14 Tagen ohne Abzug.')); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_payment_due_days"><?php esc_html_e('Standard Zahlungsziel (Tage)', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <input type="number" id="wlc_payment_due_days" name="wlc_payment_due_days"
                           value="<?php echo esc_attr(get_option('wlc_payment_due_days', '14')); ?>"
                           min="0" max="365" class="small-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_closing_text"><?php esc_html_e('Schlusstext', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <textarea id="wlc_closing_text" name="wlc_closing_text"
                              rows="3" class="large-text"><?php echo esc_textarea(get_option('wlc_closing_text', 'Vielen Dank für Ihr Vertrauen.')); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_finalize_immediately"><?php esc_html_e('Rechnungen sofort abschließen', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_finalize_immediately" name="wlc_finalize_immediately"
                               value="yes" <?php checked(get_option('wlc_finalize_immediately', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Rechnungen direkt im Status "open" erstellen', 'patsch9-accounting-bridge'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php $this->render_payment_method_settings(); ?>
        <?php
    }

    private function render_payment_method_settings() {
        if (!function_exists('WC')) {
            return;
        }
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        $active_gateways = array_filter($payment_gateways, function($gateway) {
            return $gateway->enabled === 'yes';
        });
        if (empty($active_gateways)) {
            return;
        }
        ?>
        <hr style="margin: 30px 0;">
        <h3>💳 <?php esc_html_e('Zahlungsmethoden-spezifische Einstellungen', 'patsch9-accounting-bridge'); ?></h3>
        <p class="description">
            <?php esc_html_e('Konfiguriere individuelle Zahlungsbedingungen für jede Zahlungsmethode. Leer = Standard-Einstellungen verwenden.', 'patsch9-accounting-bridge'); ?>
        </p>
        <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th style="width: 200px;"><?php esc_html_e('Zahlungsmethode', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Zahlungsbedingungen', 'patsch9-accounting-bridge'); ?></th>
                    <th style="width: 120px;"><?php esc_html_e('Zahlungsziel (Tage)', 'patsch9-accounting-bridge'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($active_gateways as $gateway): ?>
                    <?php
                    $gateway_id = $gateway->id;
                    $payment_terms = get_option('wlc_payment_terms_' . $gateway_id, '');
                    $payment_days = get_option('wlc_payment_due_days_' . $gateway_id, '');
                    $default_terms = get_option('wlc_payment_terms', '');
                    $default_days = get_option('wlc_payment_due_days', '14');
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($gateway->get_title()); ?></strong><br>
                            <code style="font-size: 11px; color: #666;"> <?php echo esc_html($gateway_id); ?></code>
                        </td>
                        <td>
                            <input type="text" 
                                   name="wlc_payment_terms_<?php echo esc_attr($gateway_id); ?>" 
                                   value="<?php echo esc_attr($payment_terms); ?>" 
                                   class="widefat"
                                   placeholder="<?php echo esc_attr($default_terms ?: 'Standard verwenden'); ?>">
                        </td>
                        <td>
                            <input type="number" 
                                   name="wlc_payment_due_days_<?php echo esc_attr($gateway_id); ?>" 
                                   value="<?php echo esc_attr($payment_days); ?>" 
                                   class="small-text"
                                   min="0" max="365"
                                   placeholder="<?php echo esc_attr($default_days); ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="margin-top: 10px;">
            💡 <strong>Beispiele:</strong> PayPal: "Bereits bezahlt per PayPal" + 0 Tage | Rechnung: "Zahlbar innerhalb von 14 Tagen" + 14 Tage
        </p>
        <?php
    }
    
    private function render_sync_tab() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wlc_auto_sync_contacts"><?php esc_html_e('Kontakte automatisch synchronisieren', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_auto_sync_contacts" name="wlc_auto_sync_contacts" 
                               value="yes" <?php checked(get_option('wlc_auto_sync_contacts', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Kundendaten automatisch in Lexware erstellen/aktualisieren', 'patsch9-accounting-bridge'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_show_in_customer_area"><?php esc_html_e('Rechnungen im Kundenbereich anzeigen', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_show_in_customer_area" name="wlc_show_in_customer_area" 
                               value="yes" <?php checked(get_option('wlc_show_in_customer_area', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Rechnungs-PDFs im "Mein Konto"-Bereich anzeigen', 'patsch9-accounting-bridge'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_shipping_as_line_item"><?php esc_html_e('Versandkosten als Position', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_shipping_as_line_item" name="wlc_shipping_as_line_item" 
                               value="yes" <?php checked(get_option('wlc_shipping_as_line_item', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Versandkosten als separate Rechnungsposition übertragen', 'patsch9-accounting-bridge'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_enable_logging"><?php esc_html_e('Logging aktivieren', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_enable_logging" name="wlc_enable_logging" 
                               value="yes" <?php checked(get_option('wlc_enable_logging', 'no'), 'yes'); ?>>
                        <?php esc_html_e('Ja, API-Aufrufe und Fehler protokollieren', 'patsch9-accounting-bridge'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_email_on_error"><?php esc_html_e('E-Mail bei Fehlern', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_email_on_error" name="wlc_email_on_error" 
                               value="yes" <?php checked(get_option('wlc_email_on_error', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Admin per E-Mail über Fehler benachrichtigen', 'patsch9-accounting-bridge'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_auto_send_email"><?php esc_html_e('Rechnung automatisch per E-Mail versenden', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="wlc_auto_send_email" name="wlc_auto_send_email" 
                               value="yes" <?php checked(get_option('wlc_auto_send_email', 'no'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Rechnung automatisch nach Erstellung per E-Mail an Kunden senden', 'patsch9-accounting-bridge'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Die E-Mail-Vorlage kann unter WooCommerce → Einstellungen → E-Mails angepasst werden.', 'patsch9-accounting-bridge'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wlc_delete_data_on_uninstall"><?php esc_html_e('Daten bei Deinstallation löschen', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="hidden" name="wlc_delete_data_on_uninstall" value="no">
                        <input type="checkbox" id="wlc_delete_data_on_uninstall" name="wlc_delete_data_on_uninstall"
                               value="yes" <?php checked(get_option('wlc_delete_data_on_uninstall', 'no'), 'yes'); ?>>
                        <?php esc_html_e('Ja, Plugin-Einstellungen, Queue-Daten und Lexware-Verknüpfungsmetadaten beim Löschen des Plugins dauerhaft entfernen.', 'patsch9-accounting-bridge'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Standardmäßig bleiben die Daten erhalten. Das schützt bestehende Rechnungs- und Gutschriftszuordnungen bei einer versehentlichen Deinstallation oder Neuinstallation.', 'patsch9-accounting-bridge'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function handle_process_queue_now() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Keine Berechtigung.', 'patsch9-accounting-bridge'), '', array('response' => 403));
        }
        check_admin_referer('wlc_process_queue_now');

        $result = WLC_Queue_Handler::process_next_item();
        $status = is_wp_error($result) ? 'error' : 'processed';
        wp_safe_redirect(add_query_arg(
            array('page' => 'wlc-settings', 'tab' => 'logs', 'wlc_queue_notice' => $status),
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_clear_queue_now() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Keine Berechtigung.', 'patsch9-accounting-bridge'), '', array('response' => 403));
        }
        check_admin_referer('wlc_clear_queue_now');

        global $wpdb;
        $table_name = $wpdb->prefix . 'wlc_queue';
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE status IN (%s, %s)",
                $table_name,
                'pending',
                'failed'
            )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'wlc-settings',
                'tab' => 'logs',
                'wlc_queue_notice' => 'cleared',
                'wlc_queue_deleted' => max(0, (int)$deleted),
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_release_manual_check() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Keine Berechtigung.', 'patsch9-accounting-bridge'), '', array('response' => 403));
        }
        $item_id = isset($_POST['item_id']) ? absint(wp_unslash($_POST['item_id'])) : 0;
        check_admin_referer('wlc_release_manual_check_' . $item_id);
        $released = $item_id && WLC_Queue_Handler::release_manual_check($item_id);
        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'wlc-settings',
                'tab' => 'logs',
                'wlc_queue_notice' => $released ? 'released' : 'release_error',
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    private function render_logs_tab() {
        $queue_items = WLC_Queue_Handler::get_queue_status();
        $error_logs = get_option('wlc_error_logs', array());
        ?>
        <h2><?php esc_html_e('Queue-Status', 'patsch9-accounting-bridge'); ?></h2>
        <div style="display:flex;gap:8px;align-items:center;margin:1em 0;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <input type="hidden" name="action" value="wlc_process_queue_now">
                <?php wp_nonce_field('wlc_process_queue_now'); ?>
                <button type="submit" class="button button-primary"><?php esc_html_e('Queue jetzt verarbeiten', 'patsch9-accounting-bridge'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e('Alle pending und failed Queue-Items wirklich löschen?', 'patsch9-accounting-bridge'); ?>');">
                <input type="hidden" name="action" value="wlc_clear_queue_now">
                <?php wp_nonce_field('wlc_clear_queue_now'); ?>
                <button type="submit" class="button button-secondary"><?php esc_html_e('Queue leeren', 'patsch9-accounting-bridge'); ?></button>
            </form>
        </div>
        <?php
        $queue_notice = isset($_GET['wlc_queue_notice']) ? sanitize_key(wp_unslash($_GET['wlc_queue_notice'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect status.
        if ('processed' === $queue_notice) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Queue-Item erfolgreich verarbeitet!', 'patsch9-accounting-bridge') . '</p></div>';
        } elseif ('error' === $queue_notice) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Queue-Item konnte nicht verarbeitet werden. Details stehen im Fehlerprotokoll.', 'patsch9-accounting-bridge') . '</p></div>';
        } elseif ('cleared' === $queue_notice) {
            $deleted = isset($_GET['wlc_queue_deleted']) ? absint(wp_unslash($_GET['wlc_queue_deleted'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect status.
            /* translators: %d: Number of deleted queue items */
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('%d Queue-Items gelöscht!', 'patsch9-accounting-bridge'), $deleted)) . '</p></div>';
        } elseif ('released' === $queue_notice) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Die Sicherheitssperre wurde nach manueller Prüfung freigegeben. Ein erneuter Schreibvorgang ist nun möglich.', 'patsch9-accounting-bridge') . '</p></div>';
        } elseif ('release_error' === $queue_notice) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Die Sicherheitssperre konnte nicht freigegeben werden.', 'patsch9-accounting-bridge') . '</p></div>';
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Bestellung', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Aktion', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Status', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Versuche', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Erstellt', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Fehlermeldung', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Aktion', 'patsch9-accounting-bridge'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($queue_items)): ?>
                    <tr><td colspan="7"><?php esc_html_e('Queue ist leer', 'patsch9-accounting-bridge'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($queue_items as $item): ?>
                        <tr>
                            <?php $queue_order = wc_get_order(absint($item->order_id)); ?>
                            <td><?php if ($queue_order): ?><a href="<?php echo esc_url($queue_order->get_edit_order_url()); ?>">#<?php echo esc_html($item->order_id); ?></a><?php else: ?>#<?php echo esc_html($item->order_id); ?><?php endif; ?></td>
                            <td><?php echo esc_html($item->action); ?></td>
                            <td><?php echo esc_html($item->status); ?></td>
                            <td><?php echo esc_html($item->attempts); ?></td>
                            <td><?php echo esc_html($item->created_at); ?></td>
                            <td><?php echo esc_html($item->error_message ?: '–'); ?></td>
                            <td>
                                <?php if ('manual_check' === $item->status): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e('Nur freigeben, nachdem Sie in Lexware geprüft haben, ob der Beleg bereits erstellt wurde. Fortfahren?', 'patsch9-accounting-bridge'); ?>');">
                                        <input type="hidden" name="action" value="wlc_release_manual_check">
                                        <input type="hidden" name="item_id" value="<?php echo esc_attr(absint($item->id)); ?>">
                                        <?php wp_nonce_field('wlc_release_manual_check_' . absint($item->id)); ?>
                                        <button type="submit" class="button button-small"><?php esc_html_e('Nach Prüfung freigeben', 'patsch9-accounting-bridge'); ?></button>
                                    </form>
                                <?php else: ?>–<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <h2 style="margin-top: 40px;"> <?php esc_html_e('Fehler-Log (letzte 20)', 'patsch9-accounting-bridge'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Zeit', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Titel', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Nachricht', 'patsch9-accounting-bridge'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($error_logs)): ?>
                    <tr><td colspan="3"><?php esc_html_e('Keine Fehler', 'patsch9-accounting-bridge'); ?></td></tr>
                <?php else: ?>
                    <?php foreach (array_slice($error_logs, 0, 20) as $error): ?>
                        <tr>
                            <td><?php echo esc_html($error['timestamp']); ?></td>
                            <td><?php echo esc_html($error['title']); ?></td>
                            <td><?php echo esc_html($error['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}
