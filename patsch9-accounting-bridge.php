<?php
/**
 * Plugin Name: Patsch9 Accounting Bridge for WooCommerce
 * Plugin URI: https://github.com/patsch9/patsch9-accounting-bridge
 * Description: Automatische Rechnungserstellung in Lexware Office aus WooCommerce-Bestellungen mit vollständiger Synchronisation und Kundenbereichs-Integration
 * Version: 2026.10.3
 * Author: Patrick Schmidt
 * Author URI: https://github.com/patsch9
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: patsch9-accounting-bridge
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * WC requires at least: 10.9.4
 * WC tested up to: 10.9.4
 * Requires Plugins: woocommerce
 */
/*
 * Hinweis: Dies ist ein inoffizielles Plugin und steht in keiner Verbindung zur Haufe-Lexware GmbH & Co. KG. Lexware® ist eine eingetragene Marke der Haufe-Lexware GmbH & Co. KG.
 */

// Verhindere direkten Zugriff
if (!defined('ABSPATH')) {
    exit;
}

// Plugin-Konstanten definieren
define('PATSACBR_VERSION', '2026.10.3');
define('PATSACBR_DB_VERSION', '1.4.0');
define('PATSACBR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PATSACBR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PATSACBR_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once PATSACBR_PLUGIN_DIR . 'includes/class-legacy-migration.php';

/**
 * Hauptklasse des Plugins
 */
class PATSACBR_Connector {

    /**
     * Singleton-Instanz
     */
    private static $instance = null;

    /**
     * Singleton-Pattern
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktor
     */
    private function __construct() {
        // WooCommerce-Abhängigkeit prüfen
        add_action('plugins_loaded', array($this, 'init'));

        // Aktivierungs-Hook
        register_activation_hook(__FILE__, array($this, 'activate'));

        // Deaktivierungs-Hook
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // HPOS-Kompatibilität deklarieren
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }

    /**
     * Deklariere HPOS (High-Performance Order Storage) Kompatibilität
     * Behebt die WooCommerce Inkompatibilitäts-Warnung
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }

    /**
     * Plugin initialisieren
     */
    public function init() {
        // Prüfe ob WooCommerce aktiv ist
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        if (!defined('WC_VERSION') || version_compare(WC_VERSION, '10.9.4', '<')) {
            add_action('admin_notices', array($this, 'woocommerce_version_notice'));
            return;
        }

        // Migrate identifiers from pre-directory builds before settings are read.
        PATSACBR_Legacy_Migration::run();

        // Hinweis: load_plugin_textdomain() ist ab WP 4.6+ nicht mehr notwendig.
        // WordPress lädt Übersetzungen automatisch basierend auf Text Domain Header.
        // Für manuelle .mo/.po-Dateien im /languages Ordner kann der Aufruf optional bleiben,
        // wird aber von WordPress.org Plugin Check als discouraged markiert.

        // Datenbankschema bei Updates sicher migrieren.
        if (get_option('patsacbr_db_version') !== PATSACBR_DB_VERSION) {
            $this->create_database_tables();
            $this->cleanup_legacy_pdf_cache();
            // 1.3.1: print layouts do not control Lexware's invoice payment QR.
            // Remove the obsolete rental-only layout setting from previous builds.
            delete_option('patsacbr_rental_print_layout_id');
        }

        // Lade Plugin-Klassen
        $this->load_dependencies();

        // Registriere E-Mail-Klasse in WooCommerce
        add_filter('woocommerce_email_classes', array($this, 'register_invoice_email'));

        // Initialisiere Komponenten
        $this->init_components();
        add_action('admin_init', array($this, 'add_privacy_policy_content'));
    }

/**
 * Lade alle Abhängigkeiten
 */
private function load_dependencies() {
    require_once PATSACBR_PLUGIN_DIR . 'includes/class-lexware-api-client.php';
    require_once PATSACBR_PLUGIN_DIR . 'includes/class-invoice-reconciler.php';
    require_once PATSACBR_PLUGIN_DIR . 'includes/class-woo-lexware-integration.php';
    require_once PATSACBR_PLUGIN_DIR . 'includes/class-admin-settings.php';
    require_once PATSACBR_PLUGIN_DIR . 'includes/class-customer-area.php';
    require_once PATSACBR_PLUGIN_DIR . 'includes/class-queue-handler.php';
    // WICHTIG: class-invoice-email.php NICHT hier laden!
}

/**
 * Registriere E-Mail-Klasse in WooCommerce
 */
public function register_invoice_email($email_classes) {
    // Lade E-Mail-Klasse erst hier (lazy loading)
    if (!class_exists('PATSACBR_Invoice_Email')) {
        require_once PATSACBR_PLUGIN_DIR . 'includes/class-invoice-email.php';
    }
    $email_classes['PATSACBR_Invoice_Email'] = new PATSACBR_Invoice_Email();
    return $email_classes;
}

    /**
     * Initialisiere Komponenten
     */
    private function init_components() {
        // Admin-Settings
        if (is_admin()) {
            PATSACBR_Admin_Settings::get_instance();
        }

        // Historische Rechnungen im Hintergrund read-only zuordnen.
        PATSACBR_Invoice_Reconciler::get_instance();

        // WooCommerce-Integration
        PATSACBR_WooCommerce_Integration::get_instance();

        // Kundenbereich
        PATSACBR_Customer_Area::get_instance();

        // Queue-Handler
        PATSACBR_Queue_Handler::get_instance();
    }

    private function should_show_dependency_notice() {
        if (!is_admin() || !function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        return $screen && 'plugins' === $screen->id;
    }

    public function woocommerce_version_notice() {
        if (!$this->should_show_dependency_notice()) {
            return;
        }
        echo '<div class="notice notice-error"><p>' . esc_html__('Patsch9 Accounting Bridge benötigt WooCommerce 10.9.4 oder höher.', 'patsch9-accounting-bridge') . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
    }

    /**
     * Plugin-Aktivierung
     */
    public function activate() {
        // Prüfe Mindestanforderungen
        if (!$this->check_requirements()) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                esc_html__('Patsch9 Accounting Bridge erfordert WordPress 6.9 oder höher, WooCommerce 10.9.4 oder höher und PHP 8.2 oder höher.', 'patsch9-accounting-bridge'),
                esc_html__('Plugin-Aktivierung fehlgeschlagen', 'patsch9-accounting-bridge'),
                array('back_link' => true)
            );
        }

        // Preserve settings from pre-directory builds before defaults are created.
        PATSACBR_Legacy_Migration::run();

        // Erstelle Datenbank-Tabelle für Queue
        $this->create_database_tables();

        // Entferne ggf. alte öffentlich erreichbare PDF-Cache-Dateien früherer Versionen.
        $this->cleanup_legacy_pdf_cache();

        // Setze Standard-Einstellungen
        $this->set_default_options();

        // Orders existing before this feature becomes active form the immutable
        // historical reconciliation set. New orders are handled normally.
        if (!get_option('patsacbr_invoice_reconciliation_cutoff', 0)) {
            add_option('patsacbr_invoice_reconciliation_cutoff', time(), '', false);
        }

    }

    /**
     * Prüfe Systemanforderungen
     */
    private function check_requirements() {
        // PHP-Version prüfen
        if (version_compare(PHP_VERSION, '8.2', '<')) {
            return false;
        }

        // Niedrigste unterstützte WordPress-Hauptversion prüfen.
        global $wp_version;
        if (!isset($wp_version) || version_compare((string) $wp_version, '6.9', '<')) {
            return false;
        }

        // WooCommerce-Version prüfen
        if (!class_exists('WooCommerce')) {
            return false;
        }

        if (!defined('WC_VERSION') || version_compare(WC_VERSION, '10.9.4', '<')) {
            return false;
        }

        return true;
    }

    /**
     * Entfernt den veralteten persistenten PDF-Cache aus uploads/. Seit 1.1.0
     * werden Rechnungsdateien nur noch temporär für den jeweiligen Download
     * oder E-Mail-Versand abgelegt und sofort danach gelöscht.
     */
    private function cleanup_legacy_pdf_cache() {
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return;
        }
        $pdf_dir = trailingslashit($upload_dir['basedir']) . 'lexware-invoices';
        if (!is_dir($pdf_dir)) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;
        if (empty($wp_filesystem) && !WP_Filesystem()) {
            return;
        }
        $files = $wp_filesystem->dirlist($pdf_dir);
        if (is_array($files)) {
            foreach ($files as $file => $info) {
                if (!empty($info['type']) && 'f' === $info['type']) {
                    $wp_filesystem->delete(trailingslashit($pdf_dir) . wp_basename($file));
                }
            }
        }
        $wp_filesystem->rmdir($pdf_dir);
    }

    /**
     * Erstelle Datenbank-Tabellen mit verbesserter Sicherheit
     */
    private function create_database_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'patsacbr_queue';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            action varchar(50) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            dedupe_key varchar(191) DEFAULT NULL,
            lexware_invoice_id varchar(100) DEFAULT NULL,
            lexware_contact_id varchar(100) DEFAULT NULL,
            attempts int(11) UNSIGNED NOT NULL DEFAULT 0,
            error_message text DEFAULT NULL,
            locked_at datetime DEFAULT NULL,
            next_attempt_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            KEY order_id (order_id),
            KEY status_next (status,next_attempt_at),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        PATSACBR_Legacy_Migration::migrate_queue_table();
        update_option('patsacbr_db_version', PATSACBR_DB_VERSION, false);
    }

    /**
     * Setze Standard-Einstellungen
     */
    private function set_default_options() {
        $defaults = array(
            'patsacbr_api_key' => '',
            'patsacbr_order_statuses' => array('wc-completed', 'wc-processing'),
            'patsacbr_invoice_title' => 'Rechnung',
            'patsacbr_invoice_introduction' => 'Vielen Dank für Ihre Bestellung [order_number] vom [order_date].',
            'patsacbr_payment_terms' => 'Zahlbar innerhalb von 14 Tagen ohne Abzug.',
            'patsacbr_payment_due_days' => '14',
            'patsacbr_closing_text' => 'Vielen Dank für Ihr Vertrauen.',
            'patsacbr_finalize_immediately' => 'yes',
            'patsacbr_auto_sync_contacts' => 'yes',
            'patsacbr_show_in_customer_area' => 'yes',
            'patsacbr_enable_logging' => 'no',
            'patsacbr_email_on_error' => 'yes',
            'patsacbr_retry_attempts' => '3',
            'patsacbr_shipping_as_line_item' => 'yes',
            'patsacbr_auto_send_email' => 'no'
        );

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

	/**
	* Plugin-Deaktivierung
	*/
	public function deactivate() {
		// Cleanup Action Scheduler / WP Cron
		do_action('patsacbr_cleanup_scheduler');

		// Hinweis: Daten werden NICHT gelöscht bei Deaktivierung
		// Nur bei Deinstallation (siehe uninstall.php)
	}

    public function add_privacy_policy_content() {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        $content = '<p>' . esc_html__('Wenn die Lexware-Anbindung aktiviert und eine Rechnung erstellt wird, überträgt das Plugin zur Vertragserfüllung Rechnungs- und Kontaktdaten der jeweiligen WooCommerce-Bestellung an die Lexware Public API. Rechnungs-PDFs werden nicht dauerhaft im WordPress-Upload-Verzeichnis gespeichert, sondern nur temporär für einen angeforderten Download oder E-Mail-Versand verarbeitet.', 'patsch9-accounting-bridge') . '</p>';
        wp_add_privacy_policy_content(esc_html__('Patsch9 Accounting Bridge', 'patsch9-accounting-bridge'), wp_kses_post(wpautop($content)));
    }

    /**
     * WooCommerce-Fehler-Hinweis
     */
    public function woocommerce_missing_notice() {
        if (!$this->should_show_dependency_notice()) {
            return;
        }
        ?>
        <div class="error">
            <p>
                <strong><?php esc_html_e('Patsch9 Accounting Bridge', 'patsch9-accounting-bridge'); ?></strong>
                <?php esc_html_e('benötigt WooCommerce 10.9.4 oder höher. Bitte installieren und aktivieren Sie WooCommerce.', 'patsch9-accounting-bridge'); ?>
            </p>
        </div>
        <?php
    }
}

/**
 * Sicherheits-Helper-Funktionen
 */
class PATSACBR_Security {

    /**
     * Validiere und sanitize API-Key
     */
    public static function sanitize_api_key($key) {
        $key = is_scalar($key) ? trim((string)$key) : '';

        // Lexware documents this as an access token/API key, but does not guarantee
        // a UUID-only format. Accept RFC 6750 bearer-token characters while
        // rejecting whitespace/control characters and header injection.
        if ($key === '' || strlen($key) < 16 || strlen($key) > 512 ||
            !preg_match('/^[A-Za-z0-9\-._~+\/]+={0,2}$/', $key)) {
            return '';
        }

        return $key;
    }

    /**
     * Verhindere Path Traversal bei PDF-Downloads
     */
    public static function sanitize_file_path($path, $allowed_dir) {
        $real_path = realpath($path);
        $allowed_path = realpath($allowed_dir);

        if ($real_path === false || $allowed_path === false) {
            return false;
        }

        // Require a real directory boundary. A simple prefix comparison would
        // also accept a sibling such as /lexware-invoices-malicious.
        $allowed_prefix = rtrim($allowed_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (0 !== strpos($real_path, $allowed_prefix)) {
            return false;
        }

        return $real_path;
    }

    /**
     * Rate Limiting für manuelle Aktionen
     */
    public static function check_rate_limit($action, $user_id, $limit = 10, $period = 60) {
        $transient_key = 'patsacbr_rate_limit_' . $user_id . '_' . $action;
        $count = get_transient($transient_key);

        if ($count === false) {
            set_transient($transient_key, 1, $period);
            return true;
        }

        if ($count >= $limit) {
            return false;
        }

        set_transient($transient_key, $count + 1, $period);
        return true;
    }
}

// Plugin initialisieren
function patsacbr_connector() {
    return PATSACBR_Connector::get_instance();
}

// Starte Plugin
patsacbr_connector();
