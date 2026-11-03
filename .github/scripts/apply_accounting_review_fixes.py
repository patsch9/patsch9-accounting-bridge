from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def replace_once(path, old, new):
    file_path = ROOT / path
    text = file_path.read_text(encoding="utf-8")
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"{path}: expected exactly one match, got {count}: {old[:120]!r}")
    file_path.write_text(text.replace(old, new, 1), encoding="utf-8")


def replace_all(path, old, new, expected):
    file_path = ROOT / path
    text = file_path.read_text(encoding="utf-8")
    count = text.count(old)
    if count != expected:
        raise RuntimeError(f"{path}: expected {expected} matches, got {count}: {old[:120]!r}")
    file_path.write_text(text.replace(old, new), encoding="utf-8")


# 1) Lexware API client: cross-request throttling, privacy redaction,
# correct WooCommerce date formatting, draft/manual-check handling and small DB cleanup.
replace_once(
    "includes/class-lexware-api-client.php",
    """    private function enforce_rate_limit() {\n        $now = microtime(true);\n\n        // Entferne alte Einträge außerhalb des Zeitfensters\n        $this->request_times = array_filter($this->request_times, function($time) use ($now) {\n            return ($now - $time) < self::RATE_LIMIT_WINDOW;\n        });\n\n        // Wenn wir das Limit erreicht haben, warte\n        if (count($this->request_times) >= self::RATE_LIMIT_REQUESTS) {\n            // Warte auf den ältesten Request\n            $oldest = min($this->request_times);\n            $wait_time = self::RATE_LIMIT_WINDOW - ($now - $oldest) + 0.01; // 10ms Puffer\n\n            if ($wait_time > 0) {\n                usleep($wait_time * 1000000); // Convert to microseconds\n                $this->request_times = array(); // Reset nach Warten\n            }\n        }\n\n        // Tracke diesen Request\n        $this->request_times[] = microtime(true);\n    }\n""",
    """    private function enforce_rate_limit() {\n        global $wpdb;\n\n        // Lexware applies one shared limit across endpoints. Serialize slot\n        // allocation across PHP workers so concurrent downloads, queue workers\n        // and reconciliation jobs cannot independently exceed the API limit.\n        $slot_interval = (self::RATE_LIMIT_WINDOW / self::RATE_LIMIT_REQUESTS) + 0.02;\n        $option_name = 'patsacbr_lexware_next_request_at';\n        $lock = 'patsacbr_lexware_rate_' . substr(hash('sha256', DB_NAME . '|' . $wpdb->prefix), 0, 40);\n        $acquired = '0';\n\n        for ($attempt = 0; $attempt < 3; $attempt++) {\n            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-request advisory lock serializes the shared remote API rate limit.\n            $acquired = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 2));\n            if ('1' === $acquired) {\n                break;\n            }\n            usleep(250000);\n        }\n\n        if ('1' !== $acquired) {\n            // Fail conservatively if the coordination lock itself is unavailable.\n            usleep((int) ceil(self::RATE_LIMIT_WINDOW * 1000000));\n            return;\n        }\n\n        $slot = microtime(true);\n        try {\n            // Avoid a request-local/persistent option cache hiding a slot reserved\n            // by another worker immediately before this lock was acquired.\n            wp_cache_delete($option_name, 'options');\n            $now = microtime(true);\n            $next = (float) get_option($option_name, 0);\n            if ($next < ($now - (self::RATE_LIMIT_WINDOW * 10))) {\n                $next = $now;\n            }\n            $slot = max($now, $next);\n            update_option($option_name, $slot + $slot_interval, false);\n        } finally {\n            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the matching advisory lock.\n            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));\n        }\n\n        $wait_time = $slot - microtime(true);\n        if ($wait_time > 0) {\n            usleep((int) ceil($wait_time * 1000000));\n        }\n    }\n""",
)

replace_once(
    "includes/class-lexware-api-client.php",
    """        $payment_method = $order->get_payment_method();\n        $specific_terms = get_option('patsacbr_payment_terms_' . $payment_method, '');\n        $terms = !empty($specific_terms)\n""",
    """        $payment_method = (string) $order->get_payment_method();\n        $specific_terms = '' !== $payment_method\n            ? get_option('patsacbr_payment_terms_' . $payment_method, '')\n            : '';\n        $terms = !empty($specific_terms)\n""",
)

replace_once(
    "includes/class-lexware-api-client.php",
    """        $payment_method = $order->get_payment_method();\n        $specific_days = get_option('patsacbr_payment_due_days_' . $payment_method, '');\n        if ($specific_days !== '' && $specific_days !== false) {\n""",
    """        $payment_method = (string) $order->get_payment_method();\n        $specific_days = '' !== $payment_method\n            ? get_option('patsacbr_payment_due_days_' . $payment_method, '')\n            : '';\n        if ($specific_days !== '' && $specific_days !== false) {\n""",
)

replace_once(
    "includes/class-lexware-api-client.php",
    """        // Formatiere Preis ohne HTML\n        $total_formatted = number_format_i18n($order->get_total(), 2) . ' ' . $order->get_currency();\n\n        $replace = array(\n            '[order_number]'     => $order->get_order_number(),\n            '[order_date]'       => date_i18n(get_option('date_format'), strtotime($order->get_date_created())),\n""",
    """        // Formatiere Preis ohne HTML\n        $total_formatted = number_format_i18n($order->get_total(), 2) . ' ' . $order->get_currency();\n        $created = $order->get_date_created();\n        $order_date_formatted = $created ? wc_format_datetime($created, get_option('date_format')) : '';\n\n        $replace = array(\n            '[order_number]'     => $order->get_order_number(),\n            '[order_date]'       => $order_date_formatted,\n""",
)

replace_once(
    "includes/class-lexware-api-client.php",
    """        $sensitive_keys = array('email', 'phone', 'street', 'zip', 'city', 'name', 'supplement', 'address', 'request_data', 'api_key', 'authorization', 'x-goog-api-key');\n""",
    """        $sensitive_keys = array(\n            'email', 'emailaddress', 'emailaddresses',\n            'phone', 'phonenumber', 'phonenumbers',\n            'firstname', 'lastname', 'name', 'supplement',\n            'street', 'zip', 'city', 'address', 'addresses',\n            'taxnumber', 'vatregistrationid',\n            'company', 'companyname', 'contactperson', 'contactpersons',\n            'title', 'introduction', 'remark',\n            'request_data', 'api_key', 'authorization', 'x-goog-api-key',\n        );\n""",
)

replace_once(
    "includes/class-lexware-api-client.php",
    """        if (get_option('patsacbr_email_on_error', 'yes') === 'yes') {\n            $admin_email = get_option('admin_email');\n            if (is_email($admin_email)) {\n                wp_mail(\n                    $admin_email,\n                    __('[Patsch9 Accounting Bridge] Error', 'patsch9-accounting-bridge'),\n                    sprintf(\n                        /* translators: 1: Error title, 2: Error message, 3: Local timestamp. */\n                        __('Error: %1$s\\n\\nMessage: %2$s\\n\\nTime: %3$s', 'patsch9-accounting-bridge'),\n                        $this->redact_log_string($title),\n                        $this->redact_log_string($message),\n                        current_time('mysql')\n                    )\n                );\n            }\n        }\n""",
    """        if (get_option('patsacbr_email_on_error', 'yes') === 'yes') {\n            $admin_email = get_option('admin_email');\n            $safe_title = $this->redact_log_string($title);\n            $safe_message = $this->redact_log_string($message);\n            $cooldown_key = 'patsacbr_error_mail_' . substr(hash('sha256', $safe_title . '|' . $safe_message), 0, 32);\n            if (is_email($admin_email) && false === get_transient($cooldown_key)) {\n                set_transient($cooldown_key, 1, 10 * MINUTE_IN_SECONDS);\n                wp_mail(\n                    $admin_email,\n                    __('[Patsch9 Accounting Bridge] Error', 'patsch9-accounting-bridge'),\n                    sprintf(\n                        /* translators: 1: Error title, 2: Error message, 3: Local timestamp. */\n                        __('Error: %1$s\\n\\nMessage: %2$s\\n\\nTime: %3$s', 'patsch9-accounting-bridge'),\n                        $safe_title,\n                        $safe_message,\n                        current_time('mysql')\n                    )\n                );\n            }\n        }\n""",
)

replace_once(
    "includes/class-lexware-api-client.php",
    """                array('retryable' => false)\n            );\n        }\n        // If Lexware already knows a credit note relation""",
    """                array('retryable' => false, 'manual_check' => true)\n            );\n        }\n        // If Lexware already knows a credit note relation""",
)

# 2) Customer invoice downloads: authenticated per-user abuse protection.
replace_once(
    "includes/class-customer-area.php",
    """        if (!$current_user_id || ((int) $order->get_user_id() !== $current_user_id && !current_user_can('manage_woocommerce'))) {\n            wp_die(esc_html__('Permission denied', 'patsch9-accounting-bridge'), '', array('response' => 403));\n        }\n\n        $lexware_invoice_id = $order->get_meta('_patsacbr_lexware_invoice_id');\n""",
    """        if (!$current_user_id || ((int) $order->get_user_id() !== $current_user_id && !current_user_can('manage_woocommerce'))) {\n            wp_die(esc_html__('Permission denied', 'patsch9-accounting-bridge'), '', array('response' => 403));\n        }\n        if (class_exists('PATSACBR_Security') && !PATSACBR_Security::check_rate_limit('customer_download_invoice', $current_user_id, 10, 60)) {\n            wp_die(esc_html__('Too many requests. Please wait.', 'patsch9-accounting-bridge'), '', array('response' => 429));\n        }\n\n        $lexware_invoice_id = $order->get_meta('_patsacbr_lexware_invoice_id');\n""",
)

# 3) Manual order metadata: consistent finance capability and same-request de-duplication.
replace_once(
    "includes/class-woo-lexware-integration.php",
    """    public function save_manual_invoice_data($order_id) {\n        // Sicherheitsprüfung\n        if (!isset($_POST['patsacbr_manual_invoice_nonce']) || !is_string($_POST['patsacbr_manual_invoice_nonce']) ||\n            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['patsacbr_manual_invoice_nonce'])), 'patsacbr_save_manual_invoice_' . $order_id)) {\n            return;\n        }\n\n        // Berechtigungsprüfung\n        if (!current_user_can('edit_shop_orders')) {\n            return;\n        }\n\n        // Read request data in the same scope as nonce verification. The locked\n""",
    """    public function save_manual_invoice_data($order_id) {\n        $order_id = absint($order_id);\n        if (!$order_id) {\n            return;\n        }\n\n        // Sicherheitsprüfung\n        if (!isset($_POST['patsacbr_manual_invoice_nonce']) || !is_string($_POST['patsacbr_manual_invoice_nonce']) ||\n            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['patsacbr_manual_invoice_nonce'])), 'patsacbr_save_manual_invoice_' . $order_id)) {\n            return;\n        }\n\n        // Manual financial links use the same capability as the other plugin\n        // administration actions. This remains storage-agnostic under HPOS.\n        if (!current_user_can('manage_woocommerce')) {\n            return;\n        }\n\n        // Legacy storage/sync mode can fire both registered save hooks in one\n        // request. Process each order only once to avoid duplicate notes/writes.\n        static $processed_orders = array();\n        if (isset($processed_orders[$order_id])) {\n            return;\n        }\n        $processed_orders[$order_id] = true;\n\n        // Read request data in the same scope as nonce verification. The locked\n""",
)

# 4) Queue scheduler fallback, retry semantics and explicit draft/manual-check note.
replace_once(
    "includes/class-queue-handler.php",
    """    private function __construct() {\n        add_action('patsacbr_process_queue', array($this, 'process_queue'));\n        add_action('action_scheduler_init', array($this, 'setup_scheduler'), 20);\n        add_action('patsacbr_cleanup_scheduler', array($this, 'cleanup_scheduler'));\n    }\n\n    public function setup_scheduler() {\n        if (!function_exists('as_schedule_recurring_action')) {\n            $this->setup_fallback_cron();\n            return;\n        }\n        if (!as_has_scheduled_action('patsacbr_process_queue', array(), self::GROUP)) {\n            as_schedule_recurring_action(time() + 10, 60, 'patsacbr_process_queue', array(), self::GROUP, true);\n        }\n    }\n\n    private function setup_fallback_cron() {\n        add_filter('cron_schedules', array($this, 'add_cron_interval'));\n        if (!wp_next_scheduled('patsacbr_process_queue')) {\n            wp_schedule_event(time() + 10, 'patsacbr_every_minute', 'patsacbr_process_queue');\n        }\n    }\n""",
    """    private function __construct() {\n        add_action('patsacbr_process_queue', array($this, 'process_queue'));\n        add_filter('cron_schedules', array($this, 'add_cron_interval'));\n        add_action('action_scheduler_init', array($this, 'setup_scheduler'), 20);\n        add_action('init', array($this, 'setup_fallback_cron_if_needed'), 25);\n        add_action('patsacbr_cleanup_scheduler', array($this, 'cleanup_scheduler'));\n    }\n\n    public function setup_scheduler() {\n        if (!function_exists('as_schedule_recurring_action')) {\n            $this->setup_fallback_cron();\n            return;\n        }\n        // Remove an old WP-Cron fallback after Action Scheduler becomes available.\n        wp_clear_scheduled_hook('patsacbr_process_queue');\n        if (!as_has_scheduled_action('patsacbr_process_queue', array(), self::GROUP)) {\n            as_schedule_recurring_action(time() + 10, 60, 'patsacbr_process_queue', array(), self::GROUP, true);\n        }\n    }\n\n    public function setup_fallback_cron_if_needed() {\n        if (!function_exists('as_schedule_recurring_action')) {\n            $this->setup_fallback_cron();\n        }\n    }\n\n    private function setup_fallback_cron() {\n        if (!wp_next_scheduled('patsacbr_process_queue')) {\n            wp_schedule_event(time() + 10, 'patsacbr_every_minute', 'patsacbr_process_queue');\n        }\n    }\n""",
)

replace_all(
    "includes/class-queue-handler.php",
    "max(1, min(10, absint(get_option('patsacbr_retry_attempts', 3))))",
    "1 + max(0, min(10, absint(get_option('patsacbr_retry_attempts', 3))))",
    3,
)

replace_once(
    "includes/class-queue-handler.php",
    """        if (is_wp_error($result)) {\n            $data = $result->get_error_data();\n            $retryable = is_array($data) && !empty($data['retryable']);\n            $ambiguous = is_array($data) && !empty($data['ambiguous_write']);\n            $manual_check = is_array($data) && !empty($data['manual_check']);\n            $this->mark_as_failed($item, $result->get_error_message(), $retryable, ($ambiguous || $manual_check));\n            return $result;\n        }\n""",
    """        if (is_wp_error($result)) {\n            $data = $result->get_error_data();\n            $retryable = is_array($data) && !empty($data['retryable']);\n            $ambiguous = is_array($data) && !empty($data['ambiguous_write']);\n            $manual_check = is_array($data) && !empty($data['manual_check']);\n            if ('draft_invoice_cannot_be_credited' === $result->get_error_code()) {\n                $order->add_order_note($result->get_error_message());\n            }\n            $this->mark_as_failed($item, $result->get_error_message(), $retryable, ($ambiguous || $manual_check));\n            return $result;\n        }\n""",
)

# 5) Historical reconciliation should not write/query options on ordinary frontend traffic.
replace_once(
    "includes/class-invoice-reconciler.php",
    """    public function maybe_schedule() {\n        if (self::VERSION === (string) get_option('patsacbr_invoice_reconciliation_version', '')) {\n""",
    """    public function maybe_schedule() {\n        if (!is_admin() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {\n            return;\n        }\n        if (self::VERSION === (string) get_option('patsacbr_invoice_reconciliation_version', '')) {\n""",
)

# 6) Admin settings: retry value really means retries, multiline payment terms, and empty-queue notice.
replace_once(
    "includes/class-admin-settings.php",
    """    public function sanitize_retry_attempts($value) {\n        return max(1, min(10, absint($value)));\n    }\n""",
    """    public function sanitize_retry_attempts($value) {\n        return max(0, min(10, absint($value)));\n    }\n""",
)

replace_once(
    "includes/class-admin-settings.php",
    """                register_setting('patsacbr_invoice_settings', 'patsacbr_payment_terms_' . $gateway_id, array(\n                    'type' => 'string',\n                    'sanitize_callback' => 'sanitize_text_field'\n                ));\n""",
    """                register_setting('patsacbr_invoice_settings', 'patsacbr_payment_terms_' . $gateway_id, array(\n                    'type' => 'string',\n                    'sanitize_callback' => 'sanitize_textarea_field'\n                ));\n""",
)

replace_once(
    "includes/class-admin-settings.php",
    """                            <input type=\"text\"\n                                   name=\"patsacbr_payment_terms_<?php echo esc_attr($gateway_id); ?>\"\n                                   value=\"<?php echo esc_attr($payment_terms); ?>\"\n                                   class=\"widefat\"\n                                   placeholder=\"<?php echo esc_attr($default_terms ?: __('Use default', 'patsch9-accounting-bridge')); ?>\">\n""",
    """                            <textarea\n                                   name=\"patsacbr_payment_terms_<?php echo esc_attr($gateway_id); ?>\"\n                                   rows=\"2\"\n                                   class=\"widefat\"\n                                   placeholder=\"<?php echo esc_attr($default_terms ?: __('Use default', 'patsch9-accounting-bridge')); ?>\"><?php echo esc_textarea($payment_terms); ?></textarea>\n""",
)

replace_once(
    "includes/class-admin-settings.php",
    """        $result = PATSACBR_Queue_Handler::process_next_item();\n        $status = is_wp_error($result) ? 'error' : 'processed';\n        wp_safe_redirect(add_query_arg(\n""",
    """        $result = PATSACBR_Queue_Handler::process_next_item();\n        if (is_wp_error($result)) {\n            $status = 'no_items' === $result->get_error_code() ? 'empty' : 'error';\n        } else {\n            $status = 'processed';\n        }\n        wp_safe_redirect(add_query_arg(\n""",
)

replace_once(
    "includes/class-admin-settings.php",
    """        if ('processed' === $queue_notice) {\n            echo '<div class=\"notice notice-success\"><p>' . esc_html__('Queue item processed successfully!', 'patsch9-accounting-bridge') . '</p></div>';\n        } elseif ('error' === $queue_notice) {\n""",
    """        if ('processed' === $queue_notice) {\n            echo '<div class=\"notice notice-success\"><p>' . esc_html__('Queue item processed successfully!', 'patsch9-accounting-bridge') . '</p></div>';\n        } elseif ('empty' === $queue_notice) {\n            echo '<div class=\"notice notice-info\"><p>' . esc_html__('No due items in the queue', 'patsch9-accounting-bridge') . '</p></div>';\n        } elseif ('error' === $queue_notice) {\n""",
)

# 7) Release artifact cleanup must use this repository's own artifact prefix.
replace_once(
    ".github/scripts/cleanup_release_artifacts.py",
    """        if artifact.get("expired") or not (name == "release-assets" or name.startswith("p9-rezeptwerk-")):\n""",
    """        if artifact.get("expired") or not (name == "release-assets" or name.startswith("patsch9-accounting-bridge-")):\n""",
)

# Sanity assertions for the security/logic changes before CI takes over.
checks = {
    "includes/class-lexware-api-client.php": [
        "'emailaddress', 'emailaddresses'",
        "wc_format_datetime($created, get_option('date_format'))",
        "patsacbr_lexware_next_request_at",
        "'manual_check' => true",
        "patsacbr_error_mail_",
    ],
    "includes/class-customer-area.php": ["customer_download_invoice"],
    "includes/class-woo-lexware-integration.php": ["static $processed_orders = array();", "current_user_can('manage_woocommerce')"],
    "includes/class-queue-handler.php": ["setup_fallback_cron_if_needed", "1 + max(0, min(10"],
    "includes/class-invoice-reconciler.php": ["!is_admin() && !wp_doing_cron()"],
    "includes/class-admin-settings.php": ["sanitize_textarea_field", "'empty' === $queue_notice"],
    ".github/scripts/cleanup_release_artifacts.py": ["patsch9-accounting-bridge-"],
}
for path, needles in checks.items():
    text = (ROOT / path).read_text(encoding="utf-8")
    for needle in needles:
        if needle not in text:
            raise RuntimeError(f"{path}: missing expected post-fix marker {needle!r}")

print("Accounting review fixes applied successfully.")
