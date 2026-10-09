<?php
/**
 * WooCommerce E-Mail für Lexware Rechnungen
 */

if (!defined('ABSPATH')) {
    exit;
}

// Prüfe ob WC_Email verfügbar ist
if (!class_exists('WC_Email')) {
    return;
}

class PATSACBR_Invoice_Email extends WC_Email {

    private $pdf_path = null;

    public function __construct() {
        $this->id = 'patsacbr_invoice';
        $this->customer_email = true;
        $this->title = __('Lexware Rechnung', 'patsch9-accounting-bridge');
        $this->description = __('E-Mail mit der Lexware-Rechnungsdatei als Anhang (PDF bzw. XRechnung-XML)', 'patsch9-accounting-bridge');
        $this->template_html = 'emails/customer-invoice.php';
        $this->template_plain = 'emails/plain/customer-invoice.php';
        $this->template_base = PATSACBR_PLUGIN_DIR . 'templates/';
        $this->placeholders = array(
            '{order_date}' => '',
            '{order_number}' => '',
        );

        parent::__construct();
    }

    public function get_default_subject() {
        return __('Ihre Rechnung für Bestellung {order_number}', 'patsch9-accounting-bridge');
    }

    public function get_default_heading() {
        return __('Rechnung für Ihre Bestellung', 'patsch9-accounting-bridge');
    }

    public function trigger($order_id, $order = null) {
        $this->setup_locale();

        if (!$order) {
            $order = wc_get_order($order_id);
        }

        if (!is_a($order, 'WC_Order')) {
            $this->restore_locale();
            return false;
        }

        $this->object = $order;
        $this->recipient = $order->get_billing_email();
        $this->placeholders['{order_date}'] = wc_format_datetime($order->get_date_created());
        $this->placeholders['{order_number}'] = $order->get_order_number();
        $this->pdf_path = null;

        $invoice_id = $order->get_meta('_patsacbr_lexware_invoice_id');
        if (!$invoice_id) {
            $this->restore_locale();
            return false;
        }

        $api_client = new PATSACBR_API_Client();
        $document = $api_client->download_invoice_document($invoice_id);
        if (is_wp_error($document) || empty($document['path']) || !file_exists($document['path'])) {
            $message = is_wp_error($document) ? $document->get_error_message() : __('Rechnungsdatei konnte nicht vorbereitet werden.', 'patsch9-accounting-bridge');
            $order->add_order_note(sprintf(
                /* translators: %s: error message */
                __('Rechnungs-E-Mail nicht versendet: %s', 'patsch9-accounting-bridge'),
                sanitize_text_field($message)
            ));
            $this->restore_locale();
            return false;
        }
        $this->pdf_path = $document['path'];

        try {
            if ($this->is_enabled() && $this->get_recipient()) {
                return (bool) $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
            }
            return false;
        } finally {
            if ($this->pdf_path && file_exists($this->pdf_path)) {
                wp_delete_file($this->pdf_path);
            }
            $this->pdf_path = null;
            $this->restore_locale();
        }
    }

    /**
     * Überschreibe get_attachments() um PDF dynamisch hinzuzufügen
     */
    public function get_attachments() {
        $attachments = parent::get_attachments();

        if (!is_array($attachments)) {
            $attachments = array();
        }

        // Füge PDF hinzu, falls vorhanden
        if ($this->pdf_path && file_exists($this->pdf_path)) {
            $attachments[] = $this->pdf_path;
        }

        return apply_filters('woocommerce_email_attachments', $attachments, $this->id, $this->object, $this);
    }

    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            array(
                'order' => $this->object,
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => false,
                'email' => $this,
            ),
            '',
            $this->template_base
        );
    }

    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            array(
                'order' => $this->object,
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => true,
                'email' => $this,
            ),
            '',
            $this->template_base
        );
    }
}
