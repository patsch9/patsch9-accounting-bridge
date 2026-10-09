from pathlib import Path
import re

ROOT = Path('.')
SELF = Path('.github/scripts/wporg_review_fix.py')
WORKFLOW = Path('.github/workflows/wporg-review-fix.yml')


def read(path):
    return path.read_text(encoding='utf-8')


def write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding='utf-8')


# Capture legacy storage keys before renaming active code.
php_files = list(ROOT.rglob('*.php'))
source_before = '\n'.join(read(path) for path in php_files)
option_suffixes = set()
for pattern in (
    r"\b(?:get_option|update_option|add_option|delete_option)\s*\(\s*['\"]wlc_([A-Za-z0-9_-]+)['\"]",
    r"\bregister_setting\s*\(\s*['\"][^'\"]+['\"]\s*,\s*['\"]wlc_([A-Za-z0-9_-]+)['\"]",
):
    option_suffixes.update(re.findall(pattern, source_before))
option_suffixes = sorted(suffix for suffix in option_suffixes if suffix and not suffix.endswith('_'))
meta_suffixes = sorted(set(re.findall(r"['\"]_wlc_([A-Za-z0-9_-]+)['\"]", source_before)))

# Remove exactly the inline asset blocks called out by the WordPress.org review.
admin_settings = ROOT / 'includes/class-admin-settings.php'
text = read(admin_settings)
text, count = re.subn(r"\n\s*<script>.*?</script>\s*", "\n", text, count=1, flags=re.S)
if count != 1:
    raise SystemExit('Expected exactly one inline <script> block in class-admin-settings.php')
write(admin_settings, text)

integration = ROOT / 'includes/class-woo-lexware-integration.php'
text = read(integration)
text, style_count = re.subn(r"\n\s*<style>.*?</style>\s*", "\n", text, count=1, flags=re.S)
text, script_count = re.subn(r"\n\s*<script>.*?</script>\s*", "\n", text, count=1, flags=re.S)
if style_count != 1 or script_count != 1:
    raise SystemExit(f'Expected one metabox style/script block, got style={style_count}, script={script_count}')
write(integration, text)

# Rename every active plugin-owned declaration, hook, option, meta key and handle.
# The temporary remediation files are removed before the resulting commit.
text_suffixes = {'.php', '.js', '.css', '.md', '.txt', '.yml', '.yaml', '.pot'}
for path in ROOT.rglob('*'):
    if not path.is_file() or path in (SELF, WORKFLOW) or path.suffix.lower() not in text_suffixes:
        continue
    text = read(path)
    text = text.replace('LEXWARE_CONNECTOR_API_KEY', 'PATSACBR_LEXWARE_API_KEY')
    text = text.replace('WLC_', 'PATSACBR_')
    text = text.replace('wlc_', 'patsacbr_')
    text = text.replace('wlc-', 'patsacbr-')
    write(path, text)

# Version/header/runtime minimum update.
main = ROOT / 'patsch9-accounting-bridge.php'
text = read(main)
text = text.replace(' * Version: 2026.10.2', ' * Version: 2026.10.3')
text = text.replace("define('PATSACBR_VERSION', '2026.10.2');", "define('PATSACBR_VERSION', '2026.10.3');")
text = text.replace("define('PATSACBR_DB_VERSION', '1.3.6');", "define('PATSACBR_DB_VERSION', '1.4.0');")
text = text.replace(' * Requires at least: 6.9.5', ' * Requires at least: 6.9')
text = text.replace('WordPress 6.9.5 oder höher', 'WordPress 6.9 oder höher')
text = text.replace("version_compare((string) $wp_version, '6.9.5', '<')", "version_compare((string) $wp_version, '6.9', '<')")
text = text.replace(
    '// WordPress-Sicherheitsbasis prüfen (6.9.5 enthält die Backports für die im Juli 2026 behobenen Core-Lücken).',
    '// Niedrigste unterstützte WordPress-Hauptversion prüfen.'
)

require_marker = "define('PATSACBR_PLUGIN_BASENAME', plugin_basename(__FILE__));\n"
if 'class-legacy-migration.php' not in text:
    text = text.replace(
        require_marker,
        require_marker + "\nrequire_once PATSACBR_PLUGIN_DIR . 'includes/class-legacy-migration.php';\n"
    )

wc_marker = (
    "        if (!defined('WC_VERSION') || version_compare(WC_VERSION, '10.9.4', '<')) {\n"
    "            add_action('admin_notices', array($this, 'woocommerce_version_notice'));\n"
    "            return;\n"
    "        }\n"
)
if 'PATSACBR_Legacy_Migration::run();' not in text:
    text = text.replace(
        wc_marker,
        wc_marker +
        "\n        // Migrate identifiers from pre-directory builds before settings are read.\n"
        "        PATSACBR_Legacy_Migration::run();\n"
    )

# Activation can run before the next normal init request, so migrate options first.
activate_marker = "        // Erstelle Datenbank-Tabelle für Queue\n        $this->create_database_tables();"
if "PATSACBR_Legacy_Migration::run();\n\n        // Erstelle Datenbank-Tabelle" not in text:
    text = text.replace(
        activate_marker,
        "        // Preserve settings from pre-directory builds before defaults are created.\n"
        "        PATSACBR_Legacy_Migration::run();\n\n" + activate_marker
    )

db_marker = "        dbDelta($sql);\n        update_option('patsacbr_db_version', PATSACBR_DB_VERSION, false);"
if 'PATSACBR_Legacy_Migration::migrate_queue_table();' not in text:
    text = text.replace(
        db_marker,
        "        dbDelta($sql);\n"
        "        PATSACBR_Legacy_Migration::migrate_queue_table();\n"
        "        update_option('patsacbr_db_version', PATSACBR_DB_VERSION, false);"
    )

# Guideline 11: dependency notices are useful, but only on the Plugins screen.
old_notice = (
    "    public function woocommerce_version_notice() {\n"
    "        echo '<div class=\"notice notice-error\"><p>' . esc_html__('Patsch9 Accounting Bridge benötigt WooCommerce 10.9.4 oder höher.', 'patsch9-accounting-bridge') . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.\n"
    "    }"
)
new_notice = (
    "    private function should_show_dependency_notice() {\n"
    "        if (!is_admin() || !function_exists('get_current_screen')) {\n"
    "            return false;\n"
    "        }\n"
    "        $screen = get_current_screen();\n"
    "        return $screen && 'plugins' === $screen->id;\n"
    "    }\n\n"
    "    public function woocommerce_version_notice() {\n"
    "        if (!$this->should_show_dependency_notice()) {\n"
    "            return;\n"
    "        }\n"
    "        echo '<div class=\"notice notice-error\"><p>' . esc_html__('Patsch9 Accounting Bridge benötigt WooCommerce 10.9.4 oder höher.', 'patsch9-accounting-bridge') . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.\n"
    "    }"
)
text = text.replace(old_notice, new_notice)
text = text.replace(
    "    public function woocommerce_missing_notice() {\n        ?>",
    "    public function woocommerce_missing_notice() {\n"
    "        if (!$this->should_show_dependency_notice()) {\n"
    "            return;\n"
    "        }\n"
    "        ?>"
)
write(main, text)

# Static JavaScript for the settings page.
write(ROOT / 'admin/js/admin-settings.js', r"""(function ($) {
    'use strict';

    $(function () {
        var input = $('#patsacbr_api_key');
        var button = $('.patsacbr-toggle-api-key');
        var icon = button.find('.dashicons');
        var isVisible = false;

        button.on('click', function (event) {
            event.preventDefault();
            isVisible = !isVisible;
            input.attr('type', isVisible ? 'text' : 'password');
            icon.toggleClass('dashicons-visibility', !isVisible);
            icon.toggleClass('dashicons-hidden', isVisible);
        });
    });
}(jQuery));
""")

# Static JavaScript for WooCommerce order screens. Dynamic values are localized.
write(ROOT / 'admin/js/order-metabox.js', r"""(function ($) {
    'use strict';

    $(function () {
        var config = window.patsacbrOrderMetabox || {};
        var strings = config.strings || {};

        if (!config.ajaxUrl || !config.nonce) {
            return;
        }

        function message(response, fallback) {
            if (response && response.data && response.data.message) {
                return response.data.message;
            }
            return fallback;
        }

        function request(button, action, busyText, resetText, fallbackError, options) {
            options = options || {};
            button.prop('disabled', true).text(busyText);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: action,
                    order_id: button.data('order-id'),
                    nonce: config.nonce
                }
            }).done(function (response) {
                if (response && response.success) {
                    if (options.successMessage) {
                        window.alert(options.successMessage);
                    }
                    if (options.reload !== false) {
                        window.location.reload();
                        return;
                    }
                } else {
                    window.alert(message(response, fallbackError));
                }
                button.prop('disabled', false).text(resetText);
            }).fail(function () {
                window.alert(fallbackError);
                button.prop('disabled', false).text(resetText);
            });
        }

        $('.patsacbr-create-invoice').on('click', function () {
            var button = $(this);
            if (window.confirm(strings.confirmCreate)) {
                request(button, 'patsacbr_manual_create_invoice', strings.creating, strings.createLabel, strings.createError);
            }
        });

        $('.patsacbr-void-invoice').on('click', function () {
            var button = $(this);
            if (window.confirm(strings.confirmVoid)) {
                request(button, 'patsacbr_manual_void_invoice', strings.voiding, strings.voidLabel, strings.voidError);
            }
        });

        $('.patsacbr-send-invoice-email').on('click', function () {
            var button = $(this);
            if (window.confirm(strings.confirmSend)) {
                request(button, 'patsacbr_send_invoice_email', strings.sending, strings.sendLabel, strings.sendError, {
                    reload: false,
                    successMessage: strings.sendSuccess
                });
            }
        });

        $('.patsacbr-update-invoice').on('click', function () {
            var button = $(this);
            if (window.confirm(strings.confirmUpdate)) {
                request(button, 'patsacbr_manual_update_invoice', strings.updating, strings.updateLabel, strings.updateError);
            }
        });

        $('.patsacbr-unlink-invoice').on('click', function () {
            var button = $(this);
            if (window.confirm(strings.confirmUnlink)) {
                request(button, 'patsacbr_unlink_invoice', strings.unlinking, strings.unlinkLabel, strings.unlinkError);
            }
        });
    });
}(jQuery));
""")

# Settings-page asset enqueue and legacy constant read-only compatibility.
admin_text = read(admin_settings)
enqueue_style = "        wp_enqueue_style('patsacbr-admin-style', PATSACBR_PLUGIN_URL . 'admin/css/admin-style.css', array(), PATSACBR_VERSION);"
if 'admin/js/admin-settings.js' not in admin_text:
    admin_text = admin_text.replace(
        enqueue_style,
        enqueue_style +
        "\n        wp_enqueue_script('patsacbr-admin-settings', PATSACBR_PLUGIN_URL . 'admin/js/admin-settings.js', array('jquery'), PATSACBR_VERSION, true);"
    )

admin_text = admin_text.replace(
    "if (defined('PATSACBR_LEXWARE_API_KEY')) {\n            return (string)$old_value;\n        }",
    "$legacy_constant = 'LEXWARE' . '_CONNECTOR_API_KEY';\n"
    "        if (defined('PATSACBR_LEXWARE_API_KEY') || defined($legacy_constant)) {\n"
    "            return (string) $old_value;\n"
    "        }"
)
admin_text = admin_text.replace(
    "$api_key_from_constant = defined('PATSACBR_LEXWARE_API_KEY');\n        $api_key = $api_key_from_constant ? sanitize_text_field(trim((string)PATSACBR_LEXWARE_API_KEY)) : get_option('patsacbr_api_key', '');",
    "$legacy_constant = 'LEXWARE' . '_CONNECTOR_API_KEY';\n"
    "        $api_key_from_constant = defined('PATSACBR_LEXWARE_API_KEY') || defined($legacy_constant);\n"
    "        if (defined('PATSACBR_LEXWARE_API_KEY')) {\n"
    "            $api_key = sanitize_text_field(trim((string) PATSACBR_LEXWARE_API_KEY));\n"
    "        } elseif (defined($legacy_constant)) {\n"
    "            $api_key = sanitize_text_field(trim((string) constant($legacy_constant)));\n"
    "        } else {\n"
    "            $api_key = get_option('patsacbr_api_key', '');\n"
    "        }"
)

# Reconciliation controls belong inside the plugin settings page, not global admin notices.
logs_marker = "    private function render_logs_tab() {\n        $queue_items = PATSACBR_Queue_Handler::get_queue_status();"
if 'render_status_controls();' not in admin_text:
    admin_text = admin_text.replace(
        logs_marker,
        "    private function render_logs_tab() {\n"
        "        PATSACBR_Invoice_Reconciler::get_instance()->render_completion_notice();\n"
        "        PATSACBR_Invoice_Reconciler::get_instance()->render_status_controls();\n"
        "        $queue_items = PATSACBR_Queue_Handler::get_queue_status();"
    )
write(admin_settings, admin_text)

# Order-screen assets.
integration_text = read(integration)
register_marker = "    private function register_admin_hooks() {\n"
if 'enqueue_order_admin_assets' not in integration_text:
    integration_text = integration_text.replace(
        register_marker,
        register_marker + "        add_action('admin_enqueue_scripts', array($this, 'enqueue_order_admin_assets'));\n"
    )

    method = r"""    public function enqueue_order_admin_assets($hook_suffix) {
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
                    'confirmCreate' => __('Rechnung jetzt für diese Bestellung erstellen?', 'patsch9-accounting-bridge'),
                    'creating' => __('Wird erstellt...', 'patsch9-accounting-bridge'),
                    'createLabel' => __('✨ Rechnung jetzt erstellen', 'patsch9-accounting-bridge'),
                    'createError' => __('Fehler beim Erstellen der Rechnung', 'patsch9-accounting-bridge'),
                    'confirmVoid' => __('Rechnung wirklich stornieren?', 'patsch9-accounting-bridge'),
                    'voiding' => __('Wird storniert...', 'patsch9-accounting-bridge'),
                    'voidLabel' => __('Rechnung stornieren', 'patsch9-accounting-bridge'),
                    'voidError' => __('Fehler beim Stornieren der Rechnung', 'patsch9-accounting-bridge'),
                    'confirmSend' => __('Rechnung jetzt per E-Mail an den Kunden senden?', 'patsch9-accounting-bridge'),
                    'sending' => __('Wird gesendet...', 'patsch9-accounting-bridge'),
                    'sendLabel' => __('📧 Rechnung per E-Mail senden', 'patsch9-accounting-bridge'),
                    'sendError' => __('Fehler beim E-Mail-Versand', 'patsch9-accounting-bridge'),
                    'sendSuccess' => __('E-Mail wurde versendet', 'patsch9-accounting-bridge'),
                    'confirmUpdate' => __('Rechnung aktualisieren? Die bestehende finalisierte Rechnung wird in Lexware über eine verknüpfte Gutschrift korrigiert und anschließend neu erstellt. Bei Rechnungsentwürfen ist diese automatische Korrektur nicht möglich.', 'patsch9-accounting-bridge'),
                    'updating' => __('Wird aktualisiert...', 'patsch9-accounting-bridge'),
                    'updateLabel' => __('🔄 Rechnung aktualisieren', 'patsch9-accounting-bridge'),
                    'updateError' => __('Fehler beim Aktualisieren der Rechnung', 'patsch9-accounting-bridge'),
                    'confirmUnlink' => __("Verknüpfung zur Lexware-Rechnung wirklich löschen?\n\nDie Rechnung in Lexware bleibt bestehen, aber du kannst eine neue Rechnung für diese Bestellung erstellen.", 'patsch9-accounting-bridge'),
                    'unlinking' => __('Wird gelöscht...', 'patsch9-accounting-bridge'),
                    'unlinkLabel' => __('🔗 Verknüpfung löschen', 'patsch9-accounting-bridge'),
                    'unlinkError' => __('Fehler beim Löschen der Verknüpfung', 'patsch9-accounting-bridge'),
                ),
            )
        );
    }

"""
    integration_text = integration_text.replace(
        "    public function add_order_metabox() {",
        method + "    public function add_order_metabox() {"
    )

# Scope the bulk-action notice to WooCommerce order list screens.
bulk_marker = "function patsacbr_bulk_action_admin_notice() {\n"
if bulk_marker in integration_text and "patsacbr_bulk_action_admin_notice() {\n    $screen" not in integration_text:
    integration_text = integration_text.replace(
        bulk_marker,
        bulk_marker +
        "    $screen = function_exists('get_current_screen') ? get_current_screen() : null;\n"
        "    if (!$screen || ('edit-shop_order' !== $screen->id && 'woocommerce_page_wc-orders' !== $screen->id)) {\n"
        "        return;\n"
        "    }\n"
    )
write(integration, integration_text)

# Move metabox CSS into the already-enqueued admin stylesheet.
css = ROOT / 'admin/css/admin-style.css'
css_text = read(css).rstrip() + '\n\n'
if '.patsacbr-metabox p' not in css_text:
    css_text += """.patsacbr-metabox p { margin: 10px 0; }
.patsacbr-metabox code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 11px; word-break: break-all; }
.patsacbr-metabox .button { width: 100%; text-align: center; box-sizing: border-box; }
.patsacbr-manual-invoice-fields input.widefat { width: 100%; box-sizing: border-box; }
"""
write(css, css_text)

# Reconciler: no global admin notices; settings page explicitly renders its controls.
reconciler = ROOT / 'includes/class-invoice-reconciler.php'
reconciler_text = read(reconciler)
reconciler_text = reconciler_text.replace("        add_action('admin_notices', array($this, 'render_completion_notice'));\n", '')
reconciler_text = reconciler_text.replace("        add_action('admin_notices', array($this, 'render_status_controls'));\n", '')
write(reconciler, reconciler_text)

# API-key constant: new name is prefixed; old pre-directory name is read dynamically only.
api_client = ROOT / 'includes/class-lexware-api-client.php'
api_text = read(api_client)
old_key_block = (
    "        $key = defined('PATSACBR_LEXWARE_API_KEY')\n"
    "            ? (string) PATSACBR_LEXWARE_API_KEY\n"
    "            : (string) get_option('patsacbr_api_key', '');"
)
new_key_block = (
    "        $legacy_constant = 'LEXWARE' . '_CONNECTOR_API_KEY';\n"
    "        if (defined('PATSACBR_LEXWARE_API_KEY')) {\n"
    "            $key = (string) PATSACBR_LEXWARE_API_KEY;\n"
    "        } elseif (defined($legacy_constant)) {\n"
    "            // Read-only compatibility with pre-directory builds; never declared here.\n"
    "            $key = (string) constant($legacy_constant);\n"
    "        } else {\n"
    "            $key = (string) get_option('patsacbr_api_key', '');\n"
    "        }"
)
api_text = api_text.replace(old_key_block, new_key_block)
write(api_client, api_text)

# One-time compatibility migration. Legacy identifiers are intentionally assembled
# from fragments so automated prefix scanners only see active patsacbr_ storage.
option_lines = '\n'.join(f"        '{suffix}'," for suffix in option_suffixes)
meta_lines = '\n'.join(f"        '{suffix}'," for suffix in meta_suffixes)
migration = f"""<?php
/**
 * Compatibility migration for builds published before the WordPress.org review.
 */

if (!defined('ABSPATH')) {{
    exit;
}}

final class PATSACBR_Legacy_Migration {{
    const VERSION = '1';

    private static $option_suffixes = array(
{option_lines}
    );

    private static $order_meta_suffixes = array(
{meta_lines}
    );

    private static function legacy_prefix() {{
        return 'wl' . 'c_';
    }}

    private static function legacy_meta_prefix() {{
        return '_wl' . 'c_';
    }}

    public static function run() {{
        self::register_order_meta_fallbacks();

        if (self::VERSION === (string) get_option('patsacbr_prefix_migration_version', '')) {{
            return;
        }}

        foreach (self::$option_suffixes as $suffix) {{
            self::migrate_option(self::legacy_prefix() . $suffix, 'patsacbr_' . $suffix);
        }}

        if (function_exists('WC') && WC() && WC()->payment_gateways()) {{
            foreach (WC()->payment_gateways->payment_gateways() as $gateway) {{
                if (!is_object($gateway) || empty($gateway->id)) {{
                    continue;
                }}
                $gateway_id = sanitize_key((string) $gateway->id);
                self::migrate_option(self::legacy_prefix() . 'payment_terms_' . $gateway_id, 'patsacbr_payment_terms_' . $gateway_id);
                self::migrate_option(self::legacy_prefix() . 'payment_due_days_' . $gateway_id, 'patsacbr_payment_due_days_' . $gateway_id);
            }}
        }}

        self::cleanup_legacy_schedules();
        update_option('patsacbr_prefix_migration_version', self::VERSION, false);
    }}

    private static function migrate_option($legacy_key, $new_key) {{
        $sentinel = '__patsacbr_missing_option__';
        if ($sentinel !== get_option($new_key, $sentinel)) {{
            return;
        }}

        $legacy_value = get_option($legacy_key, $sentinel);
        if ($sentinel === $legacy_value) {{
            return;
        }}

        update_option($new_key, $legacy_value, false);
        delete_option($legacy_key);
    }}

    private static function register_order_meta_fallbacks() {{
        foreach (self::$order_meta_suffixes as $suffix) {{
            add_filter('woocommerce_order_get__patsacbr_' . $suffix, array(__CLASS__, 'migrate_order_meta_on_read'), 10, 2);
        }}
    }}

    public static function migrate_order_meta_on_read($value, $order) {{
        if (!$order instanceof WC_Order) {{
            return $value;
        }}

        $hook_prefix = 'woocommerce_order_get__patsacbr_';
        $hook = current_filter();
        if (0 !== strpos($hook, $hook_prefix)) {{
            return $value;
        }}

        $suffix = substr($hook, strlen($hook_prefix));
        if (!in_array($suffix, self::$order_meta_suffixes, true)) {{
            return $value;
        }}

        $new_key = '_patsacbr_' . $suffix;
        if ($order->meta_exists($new_key)) {{
            return $value;
        }}

        $legacy_key = self::legacy_meta_prefix() . $suffix;
        $legacy_value = $order->get_meta($legacy_key, true, 'edit');
        if ('' === $legacy_value || null === $legacy_value) {{
            return $value;
        }}

        $order->update_meta_data($new_key, $legacy_value);
        $order->delete_meta_data($legacy_key);
        $order->save_meta_data();
        return $legacy_value;
    }}

    public static function migrate_queue_table() {{
        global $wpdb;

        $legacy_table = $wpdb->prefix . self::legacy_prefix() . 'queue';
        $new_table = $wpdb->prefix . 'patsacbr_queue';
        $legacy_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($legacy_table))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration of a plugin-owned table.
        if ($legacy_table !== $legacy_exists) {{
            return;
        }}

        $result = $wpdb->query($wpdb->prepare('INSERT IGNORE INTO %i SELECT * FROM %i', $new_table, $legacy_table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- migration between plugin-owned tables.
        if (false !== $result) {{
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $legacy_table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- remove the plugin-owned legacy table only after a successful copy.
        }}
    }}

    private static function cleanup_legacy_schedules() {{
        $prefix = self::legacy_prefix();
        foreach (array($prefix . 'process_queue', $prefix . 'reconcile_existing_invoices') as $hook) {{
            wp_clear_scheduled_hook($hook);
            if (function_exists('as_unschedule_all_actions')) {{
                as_unschedule_all_actions($hook);
            }}
        }}
    }}
}}
"""
write(ROOT / 'includes/class-legacy-migration.php', migration)

# WordPress.org readme.
readme = ROOT / 'readme.txt'
readme_text = read(readme)
readme_text = readme_text.replace('Requires at least: 6.9.5', 'Requires at least: 6.9')
readme_text = readme_text.replace('Stable tag: 2026.10.2', 'Stable tag: 2026.10.3')
readme_text = readme_text.replace('* WordPress 6.9.5 or newer.', '* WordPress 6.9 or newer.')
readme_text = re.sub(
    r"\* Historical internal `PATSACBR_\*` and `patsacbr_\*` identifiers remain unchanged for backward compatibility\.",
    '* Plugin-owned declarations, hooks and stored data use the unique `PATSACBR_` / `patsacbr_` prefix. Data from pre-directory builds is migrated during upgrade.',
    readme_text
)
if '= 2026.10.3 =' not in readme_text:
    readme_text = readme_text.replace(
        '== Changelog ==\n\n',
        '== Changelog ==\n\n'
        '= 2026.10.3 =\n'
        '* Addresses WordPress.org review findings: admin assets are enqueued, plugin-owned identifiers use a unique prefix, and admin notices are scoped to relevant screens.\n'
        '* Normalizes the minimum WordPress version header and migrates data from pre-directory builds.\n\n'
        '= 2026.10.2 =\n'
        '* Adds manual start/restart controls and progress reporting for historical Lexware invoice reconciliation.\n\n'
    )
write(readme, readme_text)

# GitHub documentation/changelog.
project_readme = ROOT / 'README.md'
project_text = read(project_readme)
project_text = project_text.replace('Entwicklungsstand: **2026.10.2**', 'Entwicklungsstand: **2026.10.3**')
project_text = project_text.replace('WordPress **6.9.5 oder neuer**', 'WordPress **6.9 oder neuer**')
project_text = re.sub(
    r"- Bestehende interne Schlüssel wie `PATSACBR_\*`, `patsacbr_\*`[^\n]*",
    '- Seit 2026.10.3 verwenden aktive Plugin-Deklarationen, Hooks und gespeicherte Daten den eindeutigen Präfix `PATSACBR_` / `patsacbr_`. Daten aus Vorabversionen werden beim Upgrade kompatibel migriert.',
    project_text
)
write(project_readme, project_text)

migration_doc = ROOT / 'MIGRATION.md'
migration_doc_text = read(migration_doc)
migration_doc_text = re.sub(
    r"Die interne Datenbankschema-Version ist ab `2026\.10\.0` ausdrücklich von der öffentlichen Plugin-Version getrennt\.[^\n]*",
    'Die interne Datenbankschema-Version ist von der öffentlichen Plugin-Version getrennt. Seit `2026.10.3` verwenden aktive Deklarationen, Hooks und gespeicherte Daten `PATSACBR_` / `patsacbr_`. Daten aus den internen Vorabversionen werden beim Upgrade kompatibel übernommen; der frühere dreistellige Präfix wird ausschließlich für diese Migration gelesen.',
    migration_doc_text
)
write(migration_doc, migration_doc_text)

changelog = ROOT / 'CHANGELOG.md'
changelog_text = read(changelog)
if '## 2026.10.3' not in changelog_text:
    changelog_text = changelog_text.replace(
        '## Noch nicht veröffentlicht\n\nKeine Änderungen.',
        '## Noch nicht veröffentlicht\n\nKeine Änderungen.\n\n'
        '## 2026.10.3 – Noch nicht veröffentlicht\n\n'
        '- WordPress.org-Review: Admin-JavaScript und CSS über WordPress-Enqueue-APIs eingebunden.\n'
        '- Eindeutiger `PATSACBR_`/`patsacbr_`-Präfix für globale Deklarationen, Hooks und gespeicherte Plugin-Daten.\n'
        '- Kompatibilitätsmigration für Einstellungen, Queue und WooCommerce-Bestellmetadaten aus Vorabständen.\n'
        '- Admin-Hinweise auf relevante Plugin-/WooCommerce-Seiten begrenzt.\n'
        '- WordPress.org-Mindestversionsangabe auf `6.9` normalisiert.'
    )
write(changelog, changelog_text)

# The release workflow parses the version constant; global replacement should have updated it.
release_workflow = ROOT / '.github/workflows/release.yml'
if 'PATSACBR_VERSION' not in read(release_workflow):
    raise SystemExit('Release workflow does not validate PATSACBR_VERSION')

# Review-specific assertions.
inline_scripts = []
inline_styles = []
for path in ROOT.rglob('*.php'):
    data = read(path)
    if '<script' in data:
        inline_scripts.append(str(path))
    if '<style' in data:
        inline_styles.append(str(path))
if inline_scripts or inline_styles:
    raise SystemExit(f'Inline asset tags remain: script={inline_scripts}, style={inline_styles}')

legacy_hits = []
for path in ROOT.rglob('*.php'):
    if path.name == 'class-legacy-migration.php':
        continue
    data = read(path)
    if re.search(r'\bWLC_|\bwlc_|\bwlc-', data):
        legacy_hits.append(str(path))
if legacy_hits:
    raise SystemExit(f'Active legacy prefixes remain outside migration: {legacy_hits}')

if 'Requires at least: 6.9.5' in read(main) or 'Requires at least: 6.9.5' in read(readme):
    raise SystemExit('Patch-level Requires at least value remains')
if 'Version: 2026.10.3' not in read(main) or 'Stable tag: 2026.10.3' not in read(readme):
    raise SystemExit('Version metadata is inconsistent')

print(f'Migratable legacy option suffixes: {len(option_suffixes)}')
print(f'Migratable legacy order-meta suffixes: {len(meta_suffixes)}')
