<?php
/**
 * Admin Settings
 * MIT ZAHLUNGSMETHODEN-SPEZIFISCHEN EINSTELLUNGEN UND SICHEREM API-KEY
 */

if (!defined('ABSPATH')) {
    exit;
}

class PATSACBR_Admin_Settings {

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
        add_filter('pre_update_option_patsacbr_api_key', array($this, 'sanitize_api_key_on_save'), 10, 2);
        // WordPress Settings API defaults custom option pages to manage_options.
        // This plugin intentionally exposes its settings to WooCommerce managers,
        // so make the save capability match the menu/render capability as well.
        add_filter('option_page_capability_patsacbr_api_settings', array($this, 'settings_capability'));
        add_filter('option_page_capability_patsacbr_invoice_settings', array($this, 'settings_capability'));
        add_filter('option_page_capability_patsacbr_sync_settings', array($this, 'settings_capability'));
        add_action('admin_post_patsacbr_process_queue_now', array($this, 'handle_process_queue_now'));
        add_action('admin_post_patsacbr_clear_queue_now', array($this, 'handle_clear_queue_now'));
        add_action('admin_post_patsacbr_release_manual_check', array($this, 'handle_release_manual_check'));
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
            'patsacbr-settings',
            array($this, 'render_settings_page')
        );
    }

    public function sanitize_api_key_on_save($new_value, $old_value) {
        $legacy_constant = 'LEXWARE' . '_CONNECTOR_API_KEY';
        if (defined('PATSACBR_LEXWARE_API_KEY') || defined($legacy_constant)) {
            return (string) $old_value;
        }
        // Wenn Feld leer bleibt, alten Wert beibehalten (nicht klartext anzeigen)
        if ($new_value === null || trim((string)$new_value) === '') {
            return (string)$old_value;
        }
        $sanitized = class_exists('PATSACBR_Security') ? PATSACBR_Security::sanitize_api_key($new_value) : sanitize_text_field(trim((string)$new_value));
        if ($sanitized === '') {
            add_settings_error(
                'patsacbr_messages',
                'patsacbr_invalid_api_key',
                esc_html__('The Lexware API key has an invalid format. The previous key has been retained.', 'patsch9-accounting-bridge'),
                'error'
            );
            return (string)$old_value;
        }
        return $sanitized;
    }

    public function register_settings() {
        register_setting('patsacbr_api_settings', 'patsacbr_api_key', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('patsacbr_api_settings', 'patsacbr_order_statuses', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_order_statuses')
        ));
        register_setting('patsacbr_api_settings', 'patsacbr_retry_attempts', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_retry_attempts')
        ));
        register_setting('patsacbr_invoice_settings', 'patsacbr_invoice_title', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('patsacbr_invoice_settings', 'patsacbr_invoice_introduction', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('patsacbr_invoice_settings', 'patsacbr_payment_terms', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('patsacbr_invoice_settings', 'patsacbr_payment_due_days', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_due_days')
        ));
        register_setting('patsacbr_invoice_settings', 'patsacbr_closing_text', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('patsacbr_invoice_settings', 'patsacbr_finalize_immediately', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        $this->register_payment_method_settings();
        register_setting('patsacbr_sync_settings', 'patsacbr_auto_sync_contacts', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        register_setting('patsacbr_sync_settings', 'patsacbr_show_in_customer_area', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        register_setting('patsacbr_sync_settings', 'patsacbr_shipping_as_line_item', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        register_setting('patsacbr_sync_settings', 'patsacbr_enable_logging', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        register_setting('patsacbr_sync_settings', 'patsacbr_email_on_error', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        register_setting('patsacbr_sync_settings', 'patsacbr_auto_send_email', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_yes_no')
        ));
        register_setting('patsacbr_sync_settings', 'patsacbr_delete_data_on_uninstall', array(
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
                register_setting('patsacbr_invoice_settings', 'patsacbr_payment_terms_' . $gateway_id, array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ));
                register_setting('patsacbr_invoice_settings', 'patsacbr_payment_due_days_' . $gateway_id, array(
                    'type' => 'string',
                    'sanitize_callback' => array($this, 'sanitize_optional_due_days')
                ));
            }
        }
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'woocommerce_page_patsacbr-settings') {
            return;
        }
        wp_enqueue_style('patsacbr-admin-style', PATSACBR_PLUGIN_URL . 'admin/css/admin-style.css', array(), PATSACBR_VERSION);
        wp_enqueue_script('patsacbr-admin-settings', PATSACBR_PLUGIN_URL . 'admin/js/admin-settings.js', array('jquery'), PATSACBR_VERSION, true);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        // Nonce wird von WordPress Settings API automatisch geprüft
        if (isset($_GET['settings-updated']) && sanitize_text_field(wp_unslash($_GET['settings-updated'])) === 'true') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            add_settings_error(
                'patsacbr_messages',
                'patsacbr_message',
                esc_html__('Settings saved', 'patsch9-accounting-bridge'),
                'updated'
            );
        }
        settings_errors('patsacbr_messages');
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'api'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!in_array($active_tab, array('api', 'invoice', 'sync', 'logs'), true)) {
            $active_tab = 'api';
        }
        ?>
        <div class="wrap patsacbr-settings-wrap">
            <h1><?php esc_html_e('Patsch9 Accounting Bridge', 'patsch9-accounting-bridge'); ?></h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=patsacbr-settings&tab=api" class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('API configuration', 'patsch9-accounting-bridge'); ?>
                </a>
                <a href="?page=patsacbr-settings&tab=invoice" class="nav-tab <?php echo $active_tab === 'invoice' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Invoice settings', 'patsch9-accounting-bridge'); ?>
                </a>
                <a href="?page=patsacbr-settings&tab=sync" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Synchronization', 'patsch9-accounting-bridge'); ?>
                </a>
                <a href="?page=patsacbr-settings&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Logs & Queue', 'patsch9-accounting-bridge'); ?>
                </a>
            </h2>
            <?php if ($active_tab !== 'logs'): ?>
                <form method="post" action="options.php">
                    <?php
                    switch ($active_tab) {
                        case 'api':
                            settings_fields('patsacbr_api_settings');
                            $this->render_api_tab();
                            break;
                        case 'invoice':
                            settings_fields('patsacbr_invoice_settings');
                            $this->render_invoice_tab();
                            break;
                        case 'sync':
                            settings_fields('patsacbr_sync_settings');
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
        $legacy_constant = 'LEXWARE' . '_CONNECTOR_API_KEY';
        $api_key_from_constant = defined('PATSACBR_LEXWARE_API_KEY') || defined($legacy_constant);
        if (defined('PATSACBR_LEXWARE_API_KEY')) {
            $api_key = sanitize_text_field(trim((string) PATSACBR_LEXWARE_API_KEY));
        } elseif (defined($legacy_constant)) {
            $api_key = sanitize_text_field(trim((string) constant($legacy_constant)));
        } else {
            $api_key = get_option('patsacbr_api_key', '');
        }
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="patsacbr_api_key"><?php esc_html_e('Lexware API Key', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <?php if ($api_key_from_constant): ?>
                        <p><strong><?php esc_html_e('The API key is provided through wp-config.php.', 'patsch9-accounting-bridge'); ?></strong></p>
                        <p class="description"><?php esc_html_e('The key is therefore not stored in the WordPress database and cannot be changed here.', 'patsch9-accounting-bridge'); ?></p>
                    <?php else: ?>
                        <div style="position: relative; display: inline-block; width: 100%; max-width: 500px;">
                            <input type="password"
                                   id="patsacbr_api_key"
                                   name="patsacbr_api_key"
                                   value=""
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr($api_key ? esc_html__('Saved – leave blank to keep the current value', 'patsch9-accounting-bridge') : esc_html__('Enter API key', 'patsch9-accounting-bridge')); ?>"
                                   autocomplete="off"
                                   spellcheck="false"
                                   style="font-family: monospace; letter-spacing: 1px; padding-right: 45px; width: 100%;">
                            <button type="button"
                                    class="button button-secondary patsacbr-toggle-api-key"
                                    style="position: absolute; right: 5px; top: 1px; height: 28px; padding: 0 8px;"
                                    title="<?php esc_attr_e('Show/hide API key', 'patsch9-accounting-bridge'); ?>">
                                <span class="dashicons dashicons-visibility" style="line-height: 28px;"></span>
                            </button>
                        </div>
                        <p class="description" style="margin-top: 8px;">
                            <?php echo esc_html($api_key ? esc_html__('An API key is saved. Enter a new key to replace it, or leave blank to keep it.', 'patsch9-accounting-bridge') : esc_html__('Please save an API key.', 'patsch9-accounting-bridge')); ?>
                            <?php esc_html_e(' For improved security, set PATSACBR_LEXWARE_API_KEY in wp-config.php.', 'patsch9-accounting-bridge'); ?>
                        </p>
                    <?php endif; ?>
                    <p class="description" style="margin-top: 8px;">
                        <a href="https://app.lexware.de/settings/#/public-api" target="_blank" rel="noopener">
                            <?php esc_html_e('Create an API key in Lexware', 'patsch9-accounting-bridge'); ?>
                        </a>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Create invoices for order statuses', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <?php
                    $selected_statuses = get_option('patsacbr_order_statuses', array('wc-completed', 'wc-processing'));
                    if (!is_array($selected_statuses)) {
                        $selected_statuses = array();
                    }
                    $order_statuses = wc_get_order_statuses();
                    foreach ($order_statuses as $status => $label) {
                        $checked = in_array($status, $selected_statuses, true) ? 'checked' : '';
                        ?>
                        <label style="display: block; margin: 5px 0;">
                            <input type="checkbox" name="patsacbr_order_statuses[]"
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
                    <label for="patsacbr_retry_attempts"><?php esc_html_e('Retry attempts', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <input type="number" id="patsacbr_retry_attempts" name="patsacbr_retry_attempts"
                           value="<?php echo esc_attr(get_option('patsacbr_retry_attempts', '3')); ?>"
                           min="0" max="10" class="small-text">
                    <p class="description">
                        <?php esc_html_e('Number of automatic retry attempts after API errors', 'patsch9-accounting-bridge'); ?>
                    </p>
                </td>
            </tr>
        </table>
<?php
    }

    private function render_invoice_tab() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="patsacbr_invoice_title"><?php esc_html_e('Invoice title', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <input type="text" id="patsacbr_invoice_title" name="patsacbr_invoice_title"
                           value="<?php echo esc_attr(get_option('patsacbr_invoice_title', __('Invoice', 'patsch9-accounting-bridge'))); ?>"
                           class="regular-text">
                    <p class="description"><?php esc_html_e('Placeholders:', 'patsch9-accounting-bridge'); ?> [order_number], [order_date], [customer_name]</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="patsacbr_invoice_introduction"><?php esc_html_e('Introduction', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <textarea id="patsacbr_invoice_introduction" name="patsacbr_invoice_introduction"
                              rows="3" class="large-text"><?php echo esc_textarea(get_option('patsacbr_invoice_introduction', __('Thank you for your order [order_number] dated [order_date].', 'patsch9-accounting-bridge'))); ?></textarea>
                    <p class="description"><?php esc_html_e('Placeholders:', 'patsch9-accounting-bridge'); ?> [order_number], [order_date], [customer_name], [customer_company], [total], [payment_method]</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="patsacbr_payment_terms"><?php esc_html_e('Default payment terms', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <textarea id="patsacbr_payment_terms" name="patsacbr_payment_terms"
                              rows="3" class="large-text"><?php echo esc_textarea(get_option('patsacbr_payment_terms', __('Payable within 14 days without deduction.', 'patsch9-accounting-bridge'))); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="patsacbr_payment_due_days"><?php esc_html_e('Default payment due date (days)', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <input type="number" id="patsacbr_payment_due_days" name="patsacbr_payment_due_days"
                           value="<?php echo esc_attr(get_option('patsacbr_payment_due_days', '14')); ?>"
                           min="0" max="365" class="small-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="patsacbr_closing_text"><?php esc_html_e('Closing text', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <textarea id="patsacbr_closing_text" name="patsacbr_closing_text"
                              rows="3" class="large-text"><?php echo esc_textarea(get_option('patsacbr_closing_text', __('Thank you for your trust.', 'patsch9-accounting-bridge'))); ?></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="patsacbr_finalize_immediately"><?php esc_html_e('Finalize invoices immediately', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="patsacbr_finalize_immediately" name="patsacbr_finalize_immediately"
                               value="yes" <?php checked(get_option('patsacbr_finalize_immediately', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Yes, create invoices directly with the "open" status', 'patsch9-accounting-bridge'); ?>
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
        <h3>💳 <?php esc_html_e('Payment method settings', 'patsch9-accounting-bridge'); ?></h3>
        <p class="description">
            <?php esc_html_e('Configure individual payment terms for each payment method. Leave blank to use the default settings.', 'patsch9-accounting-bridge'); ?>
        </p>
        <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th style="width: 200px;"><?php esc_html_e('Payment method', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Payment terms', 'patsch9-accounting-bridge'); ?></th>
                    <th style="width: 120px;"><?php esc_html_e('Payment due date (days)', 'patsch9-accounting-bridge'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($active_gateways as $gateway): ?>
                    <?php
                    $gateway_id = $gateway->id;
                    $payment_terms = get_option('patsacbr_payment_terms_' . $gateway_id, '');
                    $payment_days = get_option('patsacbr_payment_due_days_' . $gateway_id, '');
                    $default_terms = get_option('patsacbr_payment_terms', '');
                    $default_days = get_option('patsacbr_payment_due_days', '14');
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($gateway->get_title()); ?></strong><br>
                            <code style="font-size: 11px; color: #666;"> <?php echo esc_html($gateway_id); ?></code>
                        </td>
                        <td>
                            <input type="text"
                                   name="patsacbr_payment_terms_<?php echo esc_attr($gateway_id); ?>"
                                   value="<?php echo esc_attr($payment_terms); ?>"
                                   class="widefat"
                                   placeholder="<?php echo esc_attr($default_terms ?: __('Use default', 'patsch9-accounting-bridge')); ?>">
                        </td>
                        <td>
                            <input type="number"
                                   name="patsacbr_payment_due_days_<?php echo esc_attr($gateway_id); ?>"
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
            💡 <strong><?php esc_html_e('Examples:', 'patsch9-accounting-bridge'); ?></strong> <?php esc_html_e('PayPal: "Already paid via PayPal" + 0 days | Invoice: "Payable within 14 days" + 14 days', 'patsch9-accounting-bridge'); ?>
        </p>
        <?php
    }

    private function render_sync_tab() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="patsacbr_auto_sync_contacts"><?php esc_html_e('Synchronize contacts automatically', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="patsacbr_auto_sync_contacts" name="patsacbr_auto_sync_contacts"
                               value="yes" <?php checked(get_option('patsacbr_auto_sync_contacts', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Yes, create/update customer data in Lexware automatically', 'patsch9-accounting-bridge'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="patsacbr_show_in_customer_area"><?php esc_html_e('Show invoices in the customer account', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="patsacbr_show_in_customer_area" name="patsacbr_show_in_customer_area"
                               value="yes" <?php checked(get_option('patsacbr_show_in_customer_area', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Yes, show invoice PDFs in the "My Account" area', 'patsch9-accounting-bridge'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="patsacbr_shipping_as_line_item"><?php esc_html_e('Shipping costs as a line item', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="patsacbr_shipping_as_line_item" name="patsacbr_shipping_as_line_item"
                               value="yes" <?php checked(get_option('patsacbr_shipping_as_line_item', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Yes, transfer shipping costs as a separate invoice line item', 'patsch9-accounting-bridge'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="patsacbr_enable_logging"><?php esc_html_e('Enable logging', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="patsacbr_enable_logging" name="patsacbr_enable_logging"
                               value="yes" <?php checked(get_option('patsacbr_enable_logging', 'no'), 'yes'); ?>>
                        <?php esc_html_e('Yes, log API requests and errors', 'patsch9-accounting-bridge'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="patsacbr_email_on_error"><?php esc_html_e('Email on errors', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="patsacbr_email_on_error" name="patsacbr_email_on_error"
                               value="yes" <?php checked(get_option('patsacbr_email_on_error', 'yes'), 'yes'); ?>>
                        <?php esc_html_e('Yes, notify the administrator of errors by email', 'patsch9-accounting-bridge'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="patsacbr_auto_send_email"><?php esc_html_e('Email invoices automatically', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="patsacbr_auto_send_email" name="patsacbr_auto_send_email"
                               value="yes" <?php checked(get_option('patsacbr_auto_send_email', 'no'), 'yes'); ?>>
                        <?php esc_html_e('Yes, email invoices to customers automatically after creation', 'patsch9-accounting-bridge'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('The email template can be customized under WooCommerce → Settings → Emails.', 'patsch9-accounting-bridge'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="patsacbr_delete_data_on_uninstall"><?php esc_html_e('Delete data on uninstall', 'patsch9-accounting-bridge'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="hidden" name="patsacbr_delete_data_on_uninstall" value="no">
                        <input type="checkbox" id="patsacbr_delete_data_on_uninstall" name="patsacbr_delete_data_on_uninstall"
                               value="yes" <?php checked(get_option('patsacbr_delete_data_on_uninstall', 'no'), 'yes'); ?>>
                        <?php esc_html_e('Yes, permanently remove plugin settings, queue data, and Lexware linking metadata when deleting the plugin.', 'patsch9-accounting-bridge'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Data is retained by default. This protects existing invoice and credit note links in case of accidental uninstallation or reinstallation.', 'patsch9-accounting-bridge'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function handle_process_queue_now() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'patsch9-accounting-bridge'), '', array('response' => 403));
        }
        check_admin_referer('patsacbr_process_queue_now');

        $result = PATSACBR_Queue_Handler::process_next_item();
        $status = is_wp_error($result) ? 'error' : 'processed';
        wp_safe_redirect(add_query_arg(
            array('page' => 'patsacbr-settings', 'tab' => 'logs', 'patsacbr_queue_notice' => $status),
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_clear_queue_now() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'patsch9-accounting-bridge'), '', array('response' => 403));
        }
        check_admin_referer('patsacbr_clear_queue_now');

        global $wpdb;
        $table_name = $wpdb->prefix . 'patsacbr_queue';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit nonce/capability-protected cleanup of the plugin-owned queue table must take effect immediately.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE status IN (%s, %s)",
                $table_name,
                'pending',
                'failed'
            )
        );

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'patsacbr-settings',
                'tab' => 'logs',
                'patsacbr_queue_notice' => 'cleared',
                'patsacbr_queue_deleted' => max(0, (int)$deleted),
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_release_manual_check() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'patsch9-accounting-bridge'), '', array('response' => 403));
        }
        $item_id = isset($_POST['item_id']) ? absint(wp_unslash($_POST['item_id'])) : 0;
        check_admin_referer('patsacbr_release_manual_check_' . $item_id);
        $released = $item_id && PATSACBR_Queue_Handler::release_manual_check($item_id);
        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'patsacbr-settings',
                'tab' => 'logs',
                'patsacbr_queue_notice' => $released ? 'released' : 'release_error',
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    private function render_logs_tab() {
        PATSACBR_Invoice_Reconciler::get_instance()->render_completion_notice();
        PATSACBR_Invoice_Reconciler::get_instance()->render_status_controls();
        $queue_items = PATSACBR_Queue_Handler::get_queue_status();
        $error_logs = get_option('patsacbr_error_logs', array());
        ?>
        <h2><?php esc_html_e('Queue status', 'patsch9-accounting-bridge'); ?></h2>
        <div style="display:flex;gap:8px;align-items:center;margin:1em 0;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <input type="hidden" name="action" value="patsacbr_process_queue_now">
                <?php wp_nonce_field('patsacbr_process_queue_now'); ?>
                <button type="submit" class="button button-primary"><?php esc_html_e('Process queue now', 'patsch9-accounting-bridge'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" data-patsacbr-confirm="<?php esc_attr_e('Really delete all pending and failed queue items?', 'patsch9-accounting-bridge'); ?>">
                <input type="hidden" name="action" value="patsacbr_clear_queue_now">
                <?php wp_nonce_field('patsacbr_clear_queue_now'); ?>
                <button type="submit" class="button button-secondary"><?php esc_html_e('Clear queue', 'patsch9-accounting-bridge'); ?></button>
            </form>
        </div>
        <?php
        $queue_notice = isset($_GET['patsacbr_queue_notice']) ? sanitize_key(wp_unslash($_GET['patsacbr_queue_notice'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect status.
        if ('processed' === $queue_notice) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Queue item processed successfully!', 'patsch9-accounting-bridge') . '</p></div>';
        } elseif ('error' === $queue_notice) {
            echo '<div class="notice notice-error"><p>' . esc_html__('The queue item could not be processed. See the error log for details.', 'patsch9-accounting-bridge') . '</p></div>';
        } elseif ('cleared' === $queue_notice) {
            $deleted = isset($_GET['patsacbr_queue_deleted']) ? absint(wp_unslash($_GET['patsacbr_queue_deleted'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect status.
            /* translators: %d: Number of deleted queue items */
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('%d queue items deleted!', 'patsch9-accounting-bridge'), $deleted)) . '</p></div>';
        } elseif ('released' === $queue_notice) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('The safety lock was released after manual verification. A new write operation is now possible.', 'patsch9-accounting-bridge') . '</p></div>';
        } elseif ('release_error' === $queue_notice) {
            echo '<div class="notice notice-error"><p>' . esc_html__('The safety lock could not be released.', 'patsch9-accounting-bridge') . '</p></div>';
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Order', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Action', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Status', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Attempts', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Created', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Error message', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Action', 'patsch9-accounting-bridge'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($queue_items)): ?>
                    <tr><td colspan="7"><?php esc_html_e('The queue is empty', 'patsch9-accounting-bridge'); ?></td></tr>
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
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" data-patsacbr-confirm="<?php esc_attr_e('Only release this lock after checking in Lexware whether the document has already been created. Continue?', 'patsch9-accounting-bridge'); ?>">
                                        <input type="hidden" name="action" value="patsacbr_release_manual_check">
                                        <input type="hidden" name="item_id" value="<?php echo esc_attr(absint($item->id)); ?>">
                                        <?php wp_nonce_field('patsacbr_release_manual_check_' . absint($item->id)); ?>
                                        <button type="submit" class="button button-small"><?php esc_html_e('Release after verification', 'patsch9-accounting-bridge'); ?></button>
                                    </form>
                                <?php else: ?>–<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <h2 style="margin-top: 40px;"> <?php esc_html_e('Error log (last 20)', 'patsch9-accounting-bridge'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Time', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Title', 'patsch9-accounting-bridge'); ?></th>
                    <th><?php esc_html_e('Message', 'patsch9-accounting-bridge'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($error_logs)): ?>
                    <tr><td colspan="3"><?php esc_html_e('No errors', 'patsch9-accounting-bridge'); ?></td></tr>
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
